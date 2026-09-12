<?php

namespace Dolibarr\CDav;

use Sabre\VObject;

/** Preserve only vCard values that cannot be represented by native objects. */
class VCardStore
{
	private const OBJECT_TYPES = array('ct', 'th', 'mb');
	private const ALWAYS_NATIVE = array(
		'VERSION', 'PRODID', 'UID', 'FN', 'N', 'ORG', 'TITLE', 'NICKNAME',
		'CATEGORIES', 'CLASS', 'NOTE', 'PHOTO', 'REV',
	);

	/** @var object */
	private $db;
	/** @var int */
	private $entity;
	/** @var bool|null */
	private $available = null;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = max(1, (int) $entity);
	}

	public function isAvailable()
	{
		if ($this->available === null) {
			$this->available = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_vcard');
		}
		return $this->available;
	}

	public function save($objectType, $objectId, $cardData)
	{
		$objectType = $this->validateObjectType($objectType);
		if (!$this->isAvailable()) {
			return;
		}
		$source = VObject\Reader::read((string) $cardData, VObject\Reader::OPTION_FORGIVING);
		$sidecar = VObject\Reader::read("BEGIN:VCARD\r\nVERSION:3.0\r\nEND:VCARD\r\n");
		$usedSlots = array();
		$pendingLabels = array();
		$preservedGroups = array();

		foreach ($source->children() as $property) {
			if (!$property instanceof VObject\Property) {
				continue;
			}
			$name = strtoupper((string) $property->name);
			$group = strtolower((string) ($property->group ?? ''));
			if ($name === 'X-ABLABEL') {
				$pendingLabels[] = clone $property;
				continue;
			}
			if ($this->isPreservedProperty($property, $objectType, $usedSlots)) {
				$sidecar->add(clone $property);
				if ($group !== '') {
					$preservedGroups[$group] = true;
				}
			}
		}
		foreach ($pendingLabels as $property) {
			$group = strtolower((string) ($property->group ?? ''));
			if ($group !== '' && isset($preservedGroups[$group])) {
				$sidecar->add($property);
			}
		}

		$children = array_filter($sidecar->children(), static function ($child) {
			return $child instanceof VObject\Property && strtoupper((string) $child->name) !== 'VERSION';
		});
		if (!$children) {
			$this->delete($objectType, $objectId);
			return;
		}
		$serialized = $sidecar->serialize();
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_vcard (entity, object_type, fk_object, carddata, datahash) VALUES ('
			.$this->entity.', \''.$this->db->escape($objectType).'\', '.((int) $objectId).', \''
			.$this->db->escape($serialized).'\', \''.hash('sha256', $serialized).'\')'
			.' ON DUPLICATE KEY UPDATE carddata = VALUES(carddata), datahash = VALUES(datahash)';
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to preserve lossless vCard metadata');
		}
	}

	public function merge($objectType, $objectId, $nativeCardData)
	{
		$objectType = $this->validateObjectType($objectType);
		if (!$this->isAvailable()) {
			return $nativeCardData;
		}
		$sql = 'SELECT carddata FROM '.MAIN_DB_PREFIX.'cdav_vcard WHERE entity = '.$this->entity
			.' AND object_type = \''.$this->db->escape($objectType).'\' AND fk_object = '.((int) $objectId);
		$result = $this->db->query($sql);
		$row = $result ? $this->db->fetch_object($result) : null;
		if (!$row || (string) $row->carddata === '') {
			return $nativeCardData;
		}

		try {
			$native = VObject\Reader::read((string) $nativeCardData, VObject\Reader::OPTION_FORGIVING);
			$sidecar = VObject\Reader::read((string) $row->carddata, VObject\Reader::OPTION_FORGIVING);
			$seen = array();
			foreach ($native->children() as $property) {
				if ($property instanceof VObject\Property) {
					$seen[hash('sha256', $property->serialize())] = true;
				}
			}
			foreach ($sidecar->children() as $property) {
				if (!$property instanceof VObject\Property || strtoupper((string) $property->name) === 'VERSION') {
					continue;
				}
				$hash = hash('sha256', $property->serialize());
				if (!isset($seen[$hash])) {
					$native->add(clone $property);
					$seen[$hash] = true;
				}
			}
			return $native->serialize();
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.': '.$e->getMessage(), LOG_ERR);
			return $nativeCardData;
		}
	}

	public function delete($objectType, $objectId)
	{
		$objectType = $this->validateObjectType($objectType);
		if (!$this->isAvailable()) {
			return;
		}
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'cdav_vcard WHERE entity = '.$this->entity
			.' AND object_type = \''.$this->db->escape($objectType).'\' AND fk_object = '.((int) $objectId);
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to remove lossless vCard metadata');
		}
	}

	private function validateObjectType($objectType)
	{
		$objectType = (string) $objectType;
		if (!in_array($objectType, self::OBJECT_TYPES, true)) {
			throw new \InvalidArgumentException('Invalid CardDAV object type');
		}
		return $objectType;
	}

	/**
	 * Return true when a property has no faithful native slot.
	 *
	 * @param array<string,bool> $usedSlots
	 */
	private function isPreservedProperty($property, $objectType, array &$usedSlots)
	{
		$name = strtoupper((string) $property->name);
		if ($name === 'BDAY') {
			// Native contacts and members expose a birthday; companies do not.
			return $objectType === 'th';
		}
		if (in_array($name, self::ALWAYS_NATIVE, true)) {
			return false;
		}
		if ($name === 'EMAIL' || $name === 'URL' || $name === 'ADR' || $name === 'TEL') {
			$slot = $name === 'TEL' ? $this->telephoneSlot($property) : $name;
			if ($name === 'ADR') {
				$types = $this->propertyTypes($property);
				$slot .= isset($types['HOME']) ? ':HOME' : (isset($types['WORK']) ? ':WORK' : ':DEFAULT');
				if ($objectType !== 'ct') {
					$slot = 'ADR';
				}
			}
			if (!isset($usedSlots[$slot])) {
				$usedSlots[$slot] = true;
				return false;
			}
			return true;
		}
		if ($name === 'IMPP' || $name === 'X-SOCIALPROFILE' || str_starts_with($name, 'X-')) {
			return !$this->isNativeSocialProperty($property);
		}
		return true;
	}

	private function telephoneSlot($property)
	{
		$types = $this->propertyTypes($property);
		if (isset($types['FAX'])) return 'TEL:FAX:'.(isset($types['HOME']) ? 'HOME' : 'WORK');
		if (isset($types['CELL']) || isset($types['MOBILE'])) return 'TEL:CELL';
		if (isset($types['HOME'])) return 'TEL:HOME';
		return 'TEL:WORK';
	}

	/** @return array<string,bool> */
	private function propertyTypes($property)
	{
		$types = array();
		foreach (isset($property['TYPE']) ? $property['TYPE'] : array() as $type) {
			foreach (explode(',', (string) $type) as $part) {
				$part = strtoupper(trim($part));
				if ($part !== '') $types[$part] = true;
			}
		}
		return $types;
	}

	private function isNativeSocialProperty($property)
	{
		$name = strtoupper((string) $property->name);
		$known = array('JABBER', 'XMPP', 'SKYPE', 'WHATSAPP', 'SNAPCHAT', 'LINKEDIN', 'INSTAGRAM', 'FACEBOOK', 'TWITTER', 'X');
		if (function_exists('getArrayOfSocialNetworks')) {
			$known = array_merge($known, array_map('strtoupper', array_keys(getArrayOfSocialNetworks())));
		}
		$known = array_values(array_unique($known));
		if (str_starts_with($name, 'X-') && $name !== 'X-SOCIALPROFILE') {
			return in_array(str_replace(array('X-', '-USERNAME'), '', $name), $known, true);
		}
		$service = strtoupper(trim((string) ($property['X-SERVICE-TYPE'] ?? $property['TYPE'] ?? '')));
		if ($service === '') {
			$service = strtoupper((string) parse_url((string) $property, PHP_URL_SCHEME));
		}
		return in_array($service === 'XMPP' ? 'JABBER' : $service, $known, true);
	}
}
