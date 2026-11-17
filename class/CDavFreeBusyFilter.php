<?php

namespace Dolibarr\CDav;

use Sabre\VObject;

/** Build availability-only ICS components without carrying private metadata. */
class FreeBusyFilter
{
	private const TIME_PROPERTIES = array(
		'DTSTAMP', 'DTSTART', 'DTEND', 'DUE', 'DURATION',
		'RRULE', 'EXRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID', 'TRANSP', 'STATUS',
		'SEQUENCE', 'X-MICROSOFT-CDO-BUSYSTATUS',
	);

	/** Preserve recurrence identity while making its original UID unrecognizable. */
	public static function calendarComponent($source, $busyLabel, $scope, $secret)
	{
		if (!in_array($source->name, array('VEVENT', 'VTODO'), true)) {
			throw new \InvalidArgumentException('Availability requires an event or task');
		}
		$sourceUid = isset($source->UID) ? (string) $source->UID : $source->serialize();
		$component = self::propertiesOnly($source, self::TIME_PROPERTIES);
		$component->UID = hash_hmac('sha256', 'cdav-freebusy|'.(string) $scope.'|'.$sourceUid, (string) $secret).'@cdav-freebusy.invalid';
		$component->SUMMARY = (string) $busyLabel;
		return $component;
	}

	/** Keep the timezone transitions needed to interpret recurring local times. */
	public static function timezoneComponent($source)
	{
		if ($source->name !== 'VTIMEZONE') {
			throw new \InvalidArgumentException('A timezone component is required');
		}
		$timezone = self::propertiesOnly($source, array('TZID'));
		foreach ($source->getComponents() as $transition) {
			if (in_array($transition->name, array('STANDARD', 'DAYLIGHT'), true)) {
				$timezone->add(self::propertiesOnly($transition, array('DTSTART', 'TZOFFSETFROM', 'TZOFFSETTO', 'RRULE', 'RDATE')));
			}
		}
		return $timezone;
	}

	/** Whitelist properties and parameters; remove every nested component. */
	private static function propertiesOnly($source, array $allowedProperties)
	{
		$component = clone $source;
		foreach ($component->children() as $property) {
			if (!$property instanceof VObject\Property || !in_array($property->name, $allowedProperties, true)) {
				$component->remove($property);
				continue;
			}
			$property->group = null;
			$allowedParameters = array();
			if (in_array($property->name, array('DTSTART', 'DTEND', 'DUE', 'RDATE', 'EXDATE', 'RECURRENCE-ID'), true)) {
				$allowedParameters = array('VALUE', 'TZID');
			}
			if ($property->name === 'RECURRENCE-ID') $allowedParameters[] = 'RANGE';
			foreach (array_keys($property->parameters) as $parameter) {
				if (!in_array(strtoupper($parameter), $allowedParameters, true)) unset($property[$parameter]);
			}
			if (isset($property['VALUE']) && !in_array(strtoupper((string) $property['VALUE']), array('DATE', 'DATE-TIME', 'PERIOD'), true)) {
				unset($property['VALUE']);
			}
			if (isset($property['RANGE']) && strtoupper((string) $property['RANGE']) !== 'THISANDFUTURE') unset($property['RANGE']);
			if ($property->name === 'STATUS') {
				$statuses = $source->name === 'VTODO'
					? array('NEEDS-ACTION', 'COMPLETED', 'IN-PROCESS', 'CANCELLED')
					: array('TENTATIVE', 'CONFIRMED', 'CANCELLED');
				if (!in_array(strtoupper((string) $property), $statuses, true)) $component->remove($property);
			}
			if ($property->name === 'TRANSP' && !in_array(strtoupper((string) $property), array('OPAQUE', 'TRANSPARENT'), true)) {
				$component->remove($property);
			}
			if ($property->name === 'X-MICROSOFT-CDO-BUSYSTATUS'
				&& !in_array(strtoupper((string) $property), array('FREE', 'TENTATIVE', 'BUSY', 'OOF', 'WORKINGELSEWHERE'), true)) {
				$component->remove($property);
			}
			if ($property->name === 'SEQUENCE' && !ctype_digit((string) $property)) $component->remove($property);
		}
		return $component;
	}
}
