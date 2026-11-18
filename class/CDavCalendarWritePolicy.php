<?php

namespace Dolibarr\CDav;

use Sabre\DAV\Exception\BadRequest;
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
	 * Plan an authorized status change without adopting native business fields.
	 * The backend must verify ownership before applying any global status change.
	 *
	 * @param string $current Current authoritative appointment
	 * @param string $incoming Client replacement appointment
	 * @return array<string,mixed> Canonical dates, state and server-owned revision
	 */
	public static function planStatusChange($current, $incoming)
	{
		$before = VObject\Reader::read((string) $current);
		$after = null;
		$comparison = null;
		try {
			$after = VObject\Reader::read((string) $incoming);
			$old = self::item($before);
			$new = self::item($after);
			if ($old->name !== 'VEVENT' || $new->name !== 'VEVENT'
				|| (isset($old->ORGANIZER) && $old->select('ATTENDEE'))) {
				throw new Forbidden('Appointment status changes require an original event; invitations use their organizer policy.');
			}
			$oldStatus = self::eventStatus($old);
			$status = self::normalizeAppointmentStatus($old, $new);
			$statusChanged = $status !== $oldStatus;
			$comparison = clone $after;
			$test = self::item($comparison);
			// Compare every other protected field, including independent occupancy.
			self::normalizeCancelledSummary($old, $test);
			$old->STATUS = $oldStatus;
			$test->STATUS = $oldStatus;
			if ($status === 'CANCELLED') {
				$test->remove('TRANSP');
				foreach ($old->select('TRANSP') as $property) $test->add(clone $property);
				$test->remove('VALARM');
				foreach ($old->select('VALARM') as $alarm) $test->add(clone $alarm);
			}
			$dates = self::assertMoveOnly($before->serialize(), $comparison->serialize());
			$oldDates = self::assertMoveOnly($before->serialize(), $before->serialize());
			$dateChanged = $dates !== $oldDates;
			if (($status === 'CANCELLED' && $dateChanged)
				|| ($oldStatus === 'CANCELLED' && ($statusChanged || $dateChanged))) {
				throw new Forbidden('A cancelled appointment cannot be restored or moved through CalDAV.');
			}
			$sequenceProperties = $old->select('SEQUENCE');
			$sequenceValue = isset($old->SEQUENCE) ? (string) $old->SEQUENCE : '0';
			if (count($sequenceProperties) > 1 || !preg_match('/^[0-9]+$/D', $sequenceValue)
				|| (float) $sequenceValue > 2147483647) {
				throw new BadRequest('The appointment revision is invalid or ambiguous.');
			}
			$sequence = (int) $sequenceValue;
			$changed = $statusChanged || $dateChanged;
			if ($changed) {
				if ($sequence >= 2147483647) throw new Forbidden('The appointment revision cannot be advanced safely.');
				$sequence++;
				foreach (array('DTSTART', 'DTEND', 'DUE', 'DURATION') as $name) {
					$old->remove($name);
					foreach ($new->select($name) as $property) $old->add(clone $property);
				}
				$old->STATUS = $status;
				$old->SEQUENCE = $sequence;
				if ($status === 'CANCELLED') $old->TRANSP = 'TRANSPARENT';
			}
			return array('kind' => !$changed ? 'noop' : ($status === 'CANCELLED' ? 'cancel' : 'status'),
				'changed' => $changed, 'date_changed' => $dateChanged, 'status' => $status,
				'previous_status' => $oldStatus, 'dates' => $dates, 'sequence' => $sequence,
				'calendar_data' => $before->serialize());
		} finally {
			$before->destroy();
			if ($after !== null) $after->destroy();
			if ($comparison !== null) $comparison->destroy();
		}
	}

	/**
	 * Read one supported global event state, independent of attendee PARTSTAT.
	 *
	 * @param VObject\Component $event Event component
	 * @return string Effective supported event status
	 */
	public static function eventStatus($event)
	{
		$properties = $event->select('STATUS');
		$status = isset($event->STATUS) ? strtoupper(trim((string) $event->STATUS)) : 'CONFIRMED';
		if (count($properties) > 1 || !in_array($status, array('TENTATIVE', 'CONFIRMED', 'CANCELLED'), true)) {
			throw new BadRequest('The appointment status is unsupported or ambiguous.');
		}
		return $status;
	}

	/**
	 * Persist protocol changes without copying a generated CRM description into metadata.
	 *
	 * @param string $stored Trusted existing sidecar, empty for a native appointment
	 * @param array<string,mixed> $plan Validated canonical appointment state plan
	 * @return string|null Trusted protocol metadata, null for an unchanged echo
	 */
	public static function metadataAfterStatusChange($stored, array $plan)
	{
		if (empty($plan['changed'])) return null;
		$canonical = VObject\Reader::read((string) $plan['calendar_data']);
		$metadata = null;
		try {
			$source = self::item($canonical);
			if ((string) $stored !== '') {
				$metadata = VObject\Reader::read((string) $stored);
				$target = self::item($metadata);
				if ($target->name !== $source->name || (string) $target->UID !== (string) $source->UID) {
					throw new Forbidden('The appointment metadata cannot replace its original identity.');
				}
			} else {
				$metadata = new VObject\Component\VCalendar();
				$target = $metadata->add($source->name, array('UID' => (string) $source->UID));
			}
			foreach (array('DTSTART', 'DTEND', 'DUE', 'DURATION') as $name) {
				$target->remove($name);
				foreach ($source->select($name) as $property) $target->add(clone $property);
			}
			if ($plan['status'] !== $plan['previous_status']) {
				$target->STATUS = $plan['status'];
				$target->remove('X-MICROSOFT-CDO-BUSYSTATUS');
			}
			$target->SEQUENCE = (int) $plan['sequence'];
			if ($plan['status'] === 'CANCELLED') {
				$target->TRANSP = 'TRANSPARENT';
				$target->remove('VALARM');
			}
			return $metadata->serialize();
		} finally {
			$canonical->destroy();
			if ($metadata !== null) $metadata->destroy();
		}
	}

	/**
	 * Reconcile Outlook availability edits without overriding an explicit DAV edit.
	 * CalDav Synchronizer changes X-MICROSOFT-CDO-BUSYSTATUS for Provisional.
	 * A preserved client hint must not override a STATUS edit from another client.
	 *
	 * @param VObject\Component $current Authoritative event with exported display hints
	 * @param VObject\Component $incoming Client replacement event
	 * @return string Requested global appointment state
	 */
	public static function normalizeAppointmentStatus($current, $incoming)
	{
		self::eventStatus($current);
		$status = self::eventStatus($incoming);
		$previousBusy = self::outlookBusyStatus($current);
		$busy = self::outlookBusyStatus($incoming);
		if ($status !== 'CANCELLED' && (!isset($incoming->STATUS) || $busy !== $previousBusy)) {
			if ($busy === 'TENTATIVE') $status = 'TENTATIVE';
			if ($busy === 'BUSY') $status = 'CONFIRMED';
		}
		return $status;
	}

	/**
	 * Ignore CalDav Synchronizer's exact subject prefix on an explicit cancellation.
	 * This must be applied only to a comparison clone, never to trusted native data.
	 *
	 * @param VObject\Component $current Authoritative appointment
	 * @param VObject\Component $incoming Client comparison clone
	 * @return void
	 */
	public static function normalizeCancelledSummary($current, $incoming)
	{
		self::eventStatus($current);
		if (self::eventStatus($incoming) !== 'CANCELLED' || count($incoming->select('STATUS')) !== 1
			|| count($current->select('SUMMARY')) !== 1 || count($incoming->select('SUMMARY')) !== 1) return;
		if ((string) $incoming->SUMMARY === 'Cancelled: '.(string) $current->SUMMARY) {
			$incoming->remove('SUMMARY');
			$incoming->add(clone $current->SUMMARY);
		}
	}

	/**
	 * Read an optional Outlook availability hint without accepting ambiguous data.
	 *
	 * @param VObject\Component $event Event component
	 * @return string|null Normalized client hint, null when absent
	 */
	public static function outlookBusyStatus($event)
	{
		$name = 'X-MICROSOFT-CDO-BUSYSTATUS';
		if (count($event->select($name)) > 1) {
			throw new BadRequest('The Outlook availability hint is ambiguous.');
		}
		return isset($event->{$name}) ? strtoupper(trim((string) $event->{$name})) : null;
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
