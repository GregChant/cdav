<?php

namespace Dolibarr\CDav;

use Sabre\DAV\Exception\Forbidden;
use Sabre\VObject;

/** Compare calendar semantics before permitting a move of a protected Agenda item. */
class CalendarWritePolicy
{
	/**
	 * Reconcile user browser alarms without adopting or replacing native reminders.
	 *
	 * @param array<array<string,mixed>> $existing Native rows with optional DAV provenance
	 * @param array<array<string,mixed>> $requested Parsed supported DISPLAY alarms
	 * @param int $calendarId Reminder recipient whose calendar is being written
	 * @return array<string,array> Native IDs to preserve/delete and genuinely new alarms
	 */
	public static function planReminders(array $existing, array $requested, $calendarId)
	{
		$create = array();
		foreach ($requested as $reminder) {
			$key = self::reminderKey((int) $reminder['value'], (string) $reminder['unit']);
			if ($key === null) {
				throw new Forbidden('This calendar alarm cannot be represented by a native Dolibarr reminder.');
			}
			$create[$key] = $reminder;
		}
		$requestedKeys = array_fill_keys(array_keys($create), true);
		$rows = array();
		$pendingKeys = array();
		foreach ($existing as $row) {
			if ((int) $row['fk_user'] !== (int) $calendarId || (string) $row['typeremind'] !== 'browser'
				|| !empty($row['fk_soc']) || !empty($row['fk_contact'])) {
				continue;
			}
			$key = self::reminderKey((int) $row['offsetvalue'], (string) $row['offsetunit']);
			if ($key === null) {
				continue;
			}
			$row['key'] = $key;
			$rows[] = $row;
			if ((int) $row['status'] === 0) {
				$pendingKeys[$key] = true;
			}
		}
		$delete = array();
		$preserve = array();
		foreach ($rows as $row) {
			$id = (int) $row['rowid'];
			$owned = $id > 0 && (int) ($row['cdav_reminder_id'] ?? 0) === $id;
			$requestedHere = isset($requestedKeys[$row['key']]);
			if ((int) $row['status'] === 0 && !$requestedHere) {
				if (!$owned) {
					throw new Forbidden('A reminder created in Dolibarr must be changed or removed in Dolibarr.');
				}
				$delete[] = $id;
				continue;
			}
			if ($requestedHere) {
				if ((int) $row['status'] !== 0 && !isset($pendingKeys[$row['key']])) {
					throw new Forbidden('A processed Dolibarr reminder cannot be rearmed by a calendar client.');
				}
				unset($create[$row['key']]);
			}
			$preserve[] = $id;
		}
		return array('delete' => $delete, 'preserve' => $preserve, 'create' => array_values($create));
	}

	/**
	 * Calendar days remain distinct from fixed elapsed hours across DST changes.
	 *
	 * @param int $value Positive native offset
	 * @param string $unit Native offset unit
	 * @return string|null Equivalent supported alarm key
	 */
	private static function reminderKey($value, $unit)
	{
		if ($value <= 0) return null;
		if ($unit === 'w' || $unit === 'd') {
			$days = $value * ($unit === 'w' ? 7 : 1);
			return $days <= 3650 ? 'days:'.$days : null;
		}
		if ($unit === 'h' || $unit === 'i') {
			$seconds = $value * ($unit === 'h' ? 3600 : 60);
			return $seconds <= 10 * 365 * 86400 ? 'seconds:'.$seconds : null;
		}
		return null;
	}

	/**
	 * Recover editable notes without importing the generated customer/project context.
	 *
	 * @param array<string,mixed> $parts Authoritative description envelope from the exporter
	 * @param string $description Incoming decoded iCalendar DESCRIPTION
	 * @param string|null $replacementPrefix Trusted prefix reflecting authorized subject/address edits
	 * @return string Original native rich text for echoes, otherwise the edited note only
	 */
	public static function nativeNoteForReplacement(array $parts, $description, $replacementPrefix = null)
	{
		$description = str_replace(array("\r\n", "\r"), "\n", (string) $description);
		$text = (string) $parts['text'];
		$native = (string) $parts['native_note'];
		if ($description === $text) {
			return $native;
		}
		if (empty($parts['notes_editable'])) {
			throw new Forbidden('The structured booking information must be edited in Dolibarr; its calendar description is read-only.');
		}
		$prefix = (string) $parts['note_prefix'];
		$suffix = (string) $parts['note_suffix'];
		$noteLength = strlen($text) - strlen($prefix) - strlen($suffix);
		$currentNote = $noteLength >= 0 ? substr($text, strlen($prefix), $noteLength) : '';
		if ($description === $currentNote || $description === $native) {
			return $native;
		}
		$prefixes = array($prefix);
		if ($replacementPrefix !== null) {
			$prefixes[] = (string) $replacementPrefix;
		}
		foreach (array_unique($prefixes) as $candidatePrefix) {
			if (strlen($description) >= strlen($candidatePrefix) + strlen($suffix)
				&& str_starts_with($description, $candidatePrefix) && str_ends_with($description, $suffix)) {
				$note = substr($description, strlen($candidatePrefix), strlen($description) - strlen($candidatePrefix) - strlen($suffix));
				return $note === $currentNote ? $native : $note;
			}
		}
		$heading = explode("\n", $prefix, 2)[0];
		if (($heading !== '' && str_starts_with($description, $heading."\n"))
			|| ($suffix !== '' && str_ends_with($description, $suffix))) {
			throw new Forbidden('The generated appointment, customer and project context must remain unchanged; edit only its Notes section or use Dolibarr.');
		}
		// Clients may PUT their original plain notes after CREATE without a GET.
		// Those notes cannot assign a customer/project; native links remain authoritative.
		return $description;
	}

	/**
	 * Permit date changes without trusting a client's copy of native business data.
	 *
	 * @param string $current Current representation exported by Dolibarr
	 * @param string $incoming Client replacement representation
	 * @return array<string,int> Validated native dates
	 */
	public static function assertMoveOnly($current, $incoming)
	{
		$before = VObject\Reader::read((string) $current);
		$after = VObject\Reader::read((string) $incoming);
		try {
			$old = self::item($before);
			$new = self::item($after);
			if (self::projection($old) !== self::projection($new)
				|| self::calendarMethod($before) !== self::calendarMethod($after)) {
				throw new Forbidden('This Dolibarr appointment is protected: only moving its dates or deleting it is allowed. Edit its other information in Dolibarr.');
			}
			$oldDates = self::dates($old);
			$newDates = self::dates($new);
			if ($oldDates['fullday'] !== $newDates['fullday'] || $oldDates['duration'] !== $newDates['duration']) {
				throw new Forbidden('Moving a protected Dolibarr appointment must preserve its duration and all-day status.');
			}
			unset($newDates['duration']);
			return $newDates;
		} finally {
			$before->destroy();
			$after->destroy();
		}
	}

	/**
	 * Advance the server-owned iTIP revision while retaining only original metadata.
	 *
	 * @param string $stored Original metadata, empty when no sidecar exists
	 * @param string $current Current authoritative export
	 * @param array<string,int> $newDates Validated replacement dates
	 * @return string|null Updated metadata, or null when the dates did not change
	 */
	public static function metadataAfterMove($stored, $current, array $newDates)
	{
		$calendar = VObject\Reader::read((string) $current);
		$metadata = null;
		try {
			$item = self::item($calendar);
			$oldDates = self::dates($item);
			if ($oldDates['start'] === $newDates['start'] && $oldDates['end'] === $newDates['end']) {
				return null;
			}
			$sequence = isset($item->SEQUENCE) ? (int) (string) $item->SEQUENCE : 0;
			if ($sequence < 0 || $sequence >= 2147483647) {
				throw new Forbidden('The protected appointment sequence cannot be advanced safely.');
			}
			if ((string) $stored !== '') {
				$metadata = VObject\Reader::read((string) $stored);
				$target = self::item($metadata);
			} else {
				$metadata = new VObject\Component\VCalendar();
				$target = $metadata->add($item->name, array('UID' => (string) $item->UID));
				foreach (array('DTSTART', 'DTEND', 'DUE', 'DURATION') as $name) {
					foreach ($item->select($name) as $property) {
						$target->add(clone $property);
					}
				}
			}
			$target->remove('SEQUENCE');
			$target->add('SEQUENCE', $sequence + 1);
			return $metadata->serialize();
		} finally {
			$calendar->destroy();
			if ($metadata !== null) {
				$metadata->destroy();
			}
		}
	}

	/**
	 * Require one original appointment, never a replacement series or exception.
	 *
	 * @param VObject\Component $calendar Calendar representation
	 * @return VObject\Component The single Agenda item
	 */
	private static function item($calendar)
	{
		$items = array();
		foreach ($calendar->getComponents() as $component) {
			if (in_array($component->name, array('VEVENT', 'VTODO'), true)) {
				$items[] = $component;
			} elseif ($component->name !== 'VTIMEZONE') {
				throw new Forbidden('A protected Dolibarr appointment cannot change its calendar components.');
			}
		}
		if (count($items) !== 1) {
			throw new Forbidden('A protected Dolibarr appointment cannot be replaced by multiple occurrences.');
		}
		return $items[0];
	}

	/**
	 * METHOD affects scheduling, unlike harmless calendar producer information.
	 *
	 * @param VObject\Component $calendar Calendar representation
	 * @return string Normalized scheduling method
	 */
	private static function calendarMethod($calendar)
	{
		return isset($calendar->METHOD) ? strtoupper(trim((string) $calendar->METHOD)) : '';
	}

	/**
	 * Stable projection independent of folding, property order and parameter order.
	 *
	 * @param VObject\Component $component Calendar item or nested alarm
	 * @param bool $nested Whether dates belong to a protected nested component
	 * @return string Stable protected-property representation
	 */
	private static function projection($component, $nested = false)
	{
		$properties = array();
		foreach ($component->children() as $child) {
			if ($child instanceof VObject\Component) {
				$properties[] = self::projection($child, true);
				continue;
			}
			$name = strtoupper($child->name);
			if (!$nested && in_array($name, array('DTSTART', 'DTEND', 'DUE', 'DURATION', 'DTSTAMP', 'CREATED', 'LAST-MODIFIED', 'SEQUENCE'), true)) {
				continue;
			}
			// Client display hints are never persisted for protected appointments.
			if (strpos($name, 'X-') === 0) {
				continue;
			}
			$parameters = array();
			foreach ($child->parameters() as $parameter) {
				$parameterName = strtoupper($parameter->name);
				if ($parameterName === 'SCHEDULE-STATUS' || $parameterName === 'VALUE') {
					continue;
				}
				$values = $parameter->getParts();
				if (in_array($parameterName, array('ROLE', 'PARTSTAT', 'CUTYPE', 'RSVP', 'ENCODING'), true)) {
					$values = array_map('strtoupper', $values);
				}
				sort($values);
				$parameters[$parameterName] = $values;
			}
			ksort($parameters);
			$value = $child->getParts();
			if (in_array($name, array('PRIORITY', 'PERCENT-COMPLETE'), true)) {
				$value = array((string) ((int) $child->getValue()));
			} elseif (in_array($name, array('TRANSP', 'STATUS', 'CLASS'), true)) {
				$value = array(strtoupper(trim((string) $child)));
			} elseif (in_array($name, array('ATTENDEE', 'ORGANIZER'), true)) {
				$value = array(strtolower(trim((string) $child)));
			} elseif ($name === 'RRULE') {
				$value = explode(';', strtoupper((string) $child));
				sort($value);
			}
			$properties[] = json_encode(array($name, $child->getValueType(), $parameters, $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		sort($properties);
		return $component->name.':'.json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Read explicit endpoints without silently repairing invalid durations.
	 *
	 * @param VObject\Component $component Calendar item
	 * @return array<string,int> Validated endpoints and duration
	 */
	private static function dates($component)
	{
		foreach (array('DTSTART', 'DTEND', 'DUE', 'DURATION') as $propertyName) {
			if (count($component->select($propertyName)) > 1) {
				throw new Forbidden('A protected appointment must have unambiguous endpoints.');
			}
		}
		if (isset($component->DURATION) && (isset($component->DTEND) || isset($component->DUE))) {
			throw new Forbidden('A protected appointment cannot combine an endpoint and a duration.');
		}
		$startProperty = isset($component->DTSTART) ? $component->DTSTART : ($component->DUE ?? null);
		if ($startProperty === null) {
			throw new Forbidden('Moving a protected Dolibarr appointment requires an explicit date.');
		}
		$start = $startProperty->getDateTime();
		$endProperty = isset($component->DTEND) ? $component->DTEND : ($component->DUE ?? null);
		if ($endProperty !== null) {
			$end = $endProperty->getDateTime();
			if ($endProperty->hasTime() !== $startProperty->hasTime()) {
				throw new Forbidden('The appointment endpoints must use the same date type.');
			}
		} else {
			$end = clone $start;
			$end = $end->add(isset($component->DURATION)
				? VObject\DateTimeParser::parse((string) $component->DURATION)
				: new \DateInterval($startProperty->hasTime() ? 'PT1H' : 'P1D'));
		}
		if ($end < $start) {
			throw new Forbidden('The appointment end cannot precede its start.');
		}
		$fullday = !$startProperty->hasTime();
		$duration = $fullday
			? (int) (new \DateTimeImmutable($start->format('Y-m-d'), new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($end->format('Y-m-d'), new \DateTimeZone('UTC')))->days
			: $end->getTimestamp() - $start->getTimestamp();
		return array('start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'fullday' => (int) $fullday, 'duration' => $duration);
	}
}
