<?php

namespace Dolibarr\CDav;

/** Server-owned provenance for Agenda writes; a mapping alone never proves origin. */
class BusinessPolicy
{
	/** @var \WeakMap|null Schema capabilities are scoped to the native connection lifetime. */
	private static ?\WeakMap $originColumns = null;

	/**
	 * Whether native and legacy appointments permit only protected date moves.
	 *
	 * @return bool Protection is enabled unless an administrator explicitly disables it
	 */
	public static function protectNativeEvents(): bool
	{
		return (bool) getDolGlobalInt('CDAV_PROTECT_NATIVE_EVENTS', 1);
	}

	/**
	 * Only an explicit marker saved during DAV creation permits ordinary DAV editing.
	 * Native, publicly booked and older ambiguous events remain protected. A missing
	 * upgrade column also fails closed until the module is reactivated.
	 *
	 * @param \DoliDB $db Native database connection
	 * @param int $eventId Native ActionComm identifier
	 * @return bool True only for a positively identified DAV-created appointment
	 */
	public static function isDavCreated($db, int $eventId): bool
	{
		if ($eventId <= 0 || !self::hasOriginColumn($db)) return false;
		$result = $db->query('SELECT dav_origin FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$eventId);
		if (!$result) throw new \RuntimeException('Unable to verify the appointment creation origin');
		$row = $db->fetch_object($result);
		return $row && (int) $row->dav_origin === 1;
	}

	/**
	 * Upgrade provenance without reclassifying existing events or changing identities.
	 *
	 * @param \DoliDB $db Native database connection
	 * @return int Positive on success, negative on failure
	 */
	public static function migrate($db): int
	{
		try {
			if (self::readOriginColumn($db)) {
				self::cacheOriginColumn($db, true);
				return 1;
			}
			$definition = array('type' => 'tinyint', 'null' => 'NOT NULL', 'default' => '0');
			if ($db->DDLAddField(MAIN_DB_PREFIX.'actioncomm_cdav', 'dav_origin', $definition) <= 0) return -1;
			self::cacheOriginColumn($db, true);
			return 1;
		} catch (\Throwable $error) {
			return -1;
		}
	}

	/**
	 * Resolve schema capability once per live connection.
	 *
	 * @param \DoliDB $db Native database connection
	 * @return bool Whether the immutable creation marker can be read
	 */
	private static function hasOriginColumn($db): bool
	{
		if (self::$originColumns === null) self::$originColumns = new \WeakMap();
		if (!isset(self::$originColumns[$db])) self::cacheOriginColumn($db, self::readOriginColumn($db));
		return self::$originColumns[$db];
	}

	/**
	 * Inspect the native schema without probing a nonexistent column on each PUT.
	 *
	 * @param \DoliDB $db Native database connection
	 * @return bool Whether the origin column exists
	 */
	private static function readOriginColumn($db): bool
	{
		$result = $db->DDLDescTable(MAIN_DB_PREFIX.'actioncomm_cdav');
		if (!$result) throw new \RuntimeException('Unable to inspect the appointment protection schema');
		while ($column = $db->fetch_object($result)) {
			if ((string) $column->Field === 'dav_origin') return true;
		}
		return false;
	}

	/**
	 * Refresh the connection capability after an in-process module upgrade.
	 *
	 * @param \DoliDB $db Native database connection
	 * @param bool $available Whether the origin column exists
	 * @return void
	 */
	private static function cacheOriginColumn($db, bool $available): void
	{
		if (self::$originColumns === null) self::$originColumns = new \WeakMap();
		self::$originColumns[$db] = $available;
	}
}
