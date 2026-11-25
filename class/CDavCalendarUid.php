<?php

namespace Dolibarr\CDav;

use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component;

/** Validate iCalendar identities and provision their native mapping column. */
class CalendarUid
{
	public const MAX_BYTES = 1024;

	/**
	 * Read a single non-empty UID after iCalendar unfolding.
	 * @param Component $component Event or task
	 * @return string Calendar UID
	 */
	public static function read(Component $component): string
	{
		$uid = trim((string) $component->UID);
		if (count($component->select('UID')) !== 1 || $uid === '') {
			throw new BadRequest('The calendar UID is missing or ambiguous.');
		}
		if (strlen($uid) > self::MAX_BYTES) {
			throw new BadRequest('The calendar UID exceeds '.self::MAX_BYTES.' bytes.');
		}
		return $uid;
	}

	/**
	 * Check the capacity of the installed mapping schema, including legacy installs.
	 * @param \DoliDB $db Native database connection
	 * @return int Column capacity in characters, or zero if unavailable
	 */
	public static function storageCapacity($db): int
	{
		$result = $db->DDLDescTable(MAIN_DB_PREFIX.'actioncomm_cdav', 'sourceuid');
		$column = $result ? $db->fetch_object($result) : null;
		return preg_match('/^varchar\((\d+)\)$/i', (string) ($column->Type ?? ''), $matches) ? (int) $matches[1] : 0;
	}

	/**
	 * Widen the legacy column while retaining existing mappings.
	 * @param \DoliDB $db Native database connection
	 * @return bool Whether the schema can store every supported UID length
	 */
	public static function migrate($db): bool
	{
		$capacity = self::storageCapacity($db);
		if ($capacity >= self::MAX_BYTES) {
			return true;
		}
		return $capacity > 0 && $db->DDLUpdateField(MAIN_DB_PREFIX.'actioncomm_cdav', 'sourceuid', array(
			'type' => 'varchar', 'value' => (string) self::MAX_BYTES, 'null' => 'NOT NULL',
		)) > 0;
	}
}
