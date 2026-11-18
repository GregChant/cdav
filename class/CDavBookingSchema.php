<?php

namespace Dolibarr\CDav;

/** Idempotent native schema upgrade; existing profiles and publication keys survive. */
class BookingSchema
{
	/**
	 * Extend old booking tables before enabling multiple named calendars.
	 *
	 * @param \DoliDB $db Native database connection
	 * @return int Positive on success, negative on failure
	 */
	public static function migrate($db): int
	{
		$tables = array(
			'cdav_booking_profile' => array(
				'label' => array('type' => 'varchar', 'value' => 120, 'null' => 'NULL', 'default' => 'null'),
				'fk_soc' => array('type' => 'int', 'null' => 'NULL'),
				'fk_project' => array('type' => 'int', 'null' => 'NULL'),
			),
			'cdav_booking_request' => array(
				'mail_customer' => array('type' => 'tinyint', 'null' => 'NOT NULL', 'default' => '0'),
				'mail_owner' => array('type' => 'tinyint', 'null' => 'NOT NULL', 'default' => '0'),
			),
		);
		foreach ($tables as $table => $fields) {
			$description = $db->DDLDescTable(MAIN_DB_PREFIX.$table);
			if (!$description) return -1;
			$existing = array();
			while ($column = $db->fetch_object($description)) $existing[(string) $column->Field] = true;
			foreach ($fields as $name => $definition) {
				if (!isset($existing[$name]) && $db->DDLAddField(MAIN_DB_PREFIX.$table, $name, $definition) <= 0) return -1;
			}
		}
		// The module's existing SQL already targets MySQL/MariaDB. Preserve a
		// covering owner index before removing its obsolete uniqueness constraint.
		$table = MAIN_DB_PREFIX.'cdav_booking_profile';
		$rows = $db->query('SHOW INDEX FROM '.$table);
		if (!$rows) return -1;
		$indexes = array();
		while ($index = $db->fetch_object($rows)) $indexes[(string) $index->Key_name] = true;
		if (!isset($indexes['idx_cdav_booking_owner'])
			&& !$db->query('CREATE INDEX idx_cdav_booking_owner ON '.$table.' (entity, fk_user)')) return -1;
		if (isset($indexes['uk_cdav_booking_owner'])
			&& !$db->query('ALTER TABLE '.$table.' DROP INDEX uk_cdav_booking_owner')) return -1;
		return 1;
	}
}
