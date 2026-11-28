<?php

namespace Dolibarr\CDav;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\VObject;

/** Validate calendar edits while preserving the authoritative native Agenda relationships. */
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
	 * Map one relative display alarm to a Dolibarr browser reminder.
	 *
	 * @param VObject\Component $alarm Alarm attached to its calendar component
	 * @param int $start Event start timestamp
	 * @param bool $strict Accept only properties represented by the personal native reminder
	 * @return array|null Native offset, or null to retain the alarm as protected protocol data
	 */
	public static function nativeReminder($alarm, int $start, bool $strict = false): ?array
	{
		if ($alarm->name !== 'VALARM' || !isset($alarm->TRIGGER)
			|| strtoupper((string) ($alarm->ACTION ?? '')) !== 'DISPLAY'
			|| isset($alarm->REPEAT) || isset($alarm->DURATION)
			|| strtoupper((string) ($alarm->TRIGGER['VALUE'] ?? 'DURATION')) !== 'DURATION'
			|| strtoupper((string) ($alarm->TRIGGER['RELATED'] ?? 'START')) !== 'START'
			|| !str_starts_with((string) $alarm->TRIGGER, '-')) return null;
		if ($strict) {
			foreach ($alarm->children() as $property) {
				if (!in_array($property->name, array('ACTION', 'DESCRIPTION', 'TRIGGER'), true)
					|| count($alarm->select($property->name)) !== 1) return null;
				foreach ($property->parameters() as $parameter) {
					if ($parameter->name !== 'VALUE' && !($property->name === 'TRIGGER' && $parameter->name === 'RELATED')) return null;
				}
			}
		}
		try {
			$interval = VObject\DateTimeParser::parseDuration((string) $alarm->TRIGGER);
			if ($interval->d > 0 && !$interval->h && !$interval->i && !$interval->s) {
				if ($interval->d > 3650) return null;
				$unit = $interval->d % 7 === 0 ? 'w' : 'd';
				$value = $unit === 'w' ? (int) ($interval->d / 7) : $interval->d;
			} else {
				if ($strict && $interval->d) return null; // Mixed day/hour offsets can cross a DST transition.
				$seconds = $interval->d ? $start - $alarm->getEffectiveTriggerTime()->getTimestamp()
					: $interval->h * 3600 + $interval->i * 60 + $interval->s;
				if ($seconds < 60 || $seconds % 60 !== 0 || $seconds > 10 * 365 * 86400) return null;
				$unit = $seconds % 3600 === 0 ? 'h' : 'i';
				$value = intdiv($seconds, $unit === 'h' ? 3600 : 60);
			}
			return array('start' => $start, 'value' => $value, 'unit' => $unit);
		} catch (\Throwable $error) {
			return null;
		}
	}

	/**
	 * Separate a personal reminder request from the shared meeting comparison.
	 *
	 * @param string $current Current export for the same authenticated calendar owner
	 * @param string $incoming Incoming replacement representation
	 * @return array Shared comparison data and optional requested native offsets
	 */
	public static function personalReminderChange(string $current, string $incoming): array
	{
		if (stripos($current, 'VALARM') === false && stripos($incoming, 'VALARM') === false) return array($incoming, null);
		$before = VObject\Reader::read($current);
		$after = null;
		try {
			$after = VObject\Reader::read($incoming);
			if (count($before->select('VEVENT')) !== 1 || count($after->select('VEVENT')) !== 1
				|| isset($before->VEVENT->{'RECURRENCE-ID'}) || isset($after->VEVENT->{'RECURRENCE-ID'})
				|| self::eventStatus($before->VEVENT) === 'CANCELLED' || self::eventStatus($after->VEVENT) === 'CANCELLED') {
				return array($incoming, null);
			}
			$oldAlarms = array();
			$oldKeys = array();
			$requested = array();
			$oldStart = self::dates($before->VEVENT)['start'];
			$newStart = self::dates($after->VEVENT)['start'];
			foreach ($before->VEVENT->select('VALARM') as $alarm) {
				$reminder = self::nativeReminder($alarm, $oldStart, true);
				if ($reminder === null) continue;
				$oldAlarms[] = $alarm;
				$oldKeys[$reminder['value'].$reminder['unit']] = true;
			}
			foreach ($after->VEVENT->select('VALARM') as $alarm) {
				$reminder = self::nativeReminder($alarm, $newStart, true);
				if ($reminder === null) continue;
				$requested[$reminder['value'].$reminder['unit']] = $reminder;
				$after->VEVENT->remove($alarm);
			}
			if (!$oldAlarms && !$requested) return array($incoming, null);
			if (count($requested) > 10) throw new Forbidden('At most ten personal browser reminders can be synchronized.');
			foreach ($oldAlarms as $alarm) $after->VEVENT->add(clone $alarm);
			$changed = array_diff_key($requested, $oldKeys) || array_diff_key($oldKeys, $requested);
			return array($after->serialize(), $changed ? array_values($requested) : null);
		} finally {
			$before->destroy();
			if ($after !== null) $after->destroy();
		}
	}

	/**
	 * Remove the optional generated footer from an appointment description.
	 * @param string $description Original decoded DESCRIPTION
	 * @param string $url Reserved documents URL for this exact appointment
	 * @return string Description without its generated footer
	 */
	private static function withoutDocumentsFooter(string $description, string $url): string
	{
		if ($url === '') return $description;
		$text = rtrim(str_replace(array("\r\n", "\r"), "\n", $description), "\n");
		$suffix = ' : '.$url;
		if (!str_ends_with($text, $suffix)) return $description;
		$separator = strrpos($text, "\n\n");
		$labelStart = $separator === false ? 0 : $separator + 2;
		$labelLength = strlen($text) - strlen($suffix) - $labelStart;
		if ($labelLength <= 0 || str_contains(substr($text, $labelStart, $labelLength), "\n")) return $description;
		return $separator === false ? '' : substr($text, 0, $separator);
	}

	/**
	 * Keep the server-generated documents footer outside saved data and write comparisons.
	 * @param string $data Complete iCalendar data
	 * @param string $url Reserved documents URL for this exact appointment
	 * @return string Calendar with only matching generated DESCRIPTION footers removed
	 */
	public static function calendarWithoutDocumentsLink(string $data, string $url): string
	{
		// Long URLs and their fragment may be split across iCalendar folded lines.
		if ($url === '' || !str_contains($data, '#')
			|| !str_contains((string) preg_replace('/\r?\n[ \t]/', '', $data), '#cdav-documents')) return $data;
		$calendar = VObject\Reader::read($data);
		$changed = false;
		try {
			foreach ($calendar->getComponents() as $component) {
				if ($component->name !== 'VEVENT') continue;
				foreach ($component->select('DESCRIPTION') as $property) {
					$original = (string) $property;
					$description = self::withoutDocumentsFooter($original, $url);
					if ($description !== $original) {
						$property->setValue($description);
						$changed = true;
					}
				}
			}
			return $changed ? $calendar->serialize() : $data;
		} finally {
			$calendar->destroy();
		}
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
		$description = self::withoutDocumentsFooter($description, (string) ($parts['documents_url'] ?? ''));
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
			if ($currentNote !== '' && str_starts_with($suffix, "\n\n")
				&& $description === $candidatePrefix.substr($suffix, 2)) {
				return '';
			}
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
	 * @param bool $allowResize Allow new duration and date type on an editable standalone occurrence
	 * @return array<string,int> Validated native dates
	 */
	public static function assertMoveOnly($current, $incoming, bool $allowResize = false)
	{
		$before = VObject\Reader::read((string) $current);
		$after = VObject\Reader::read((string) $incoming);
		try {
			$old = self::item($before);
			$new = self::item($after);
			$stored = self::projection($old);
			$submitted = self::projection($new);
			$changed = array();
			foreach (array_keys($stored + $submitted) as $field) {
				if (($stored[$field] ?? null) === ($submitted[$field] ?? null)) continue;
				// Only report standard field names. A DAV error can appear in a shared client log.
				$changed[] = in_array($field, array('UID', 'SUMMARY', 'LOCATION', 'DESCRIPTION', 'CLASS', 'STATUS', 'TRANSP',
					'PRIORITY', 'PERCENT-COMPLETE', 'COMPLETED', 'URL', 'CATEGORIES', 'ORGANIZER', 'ATTENDEE', 'VALARM',
					'ATTACH', 'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID', 'RELATED-TO'), true) ? $field : 'other property';
			}
			if ($old->name !== $new->name) $changed[] = 'component type';
			if (self::calendarMethod($before) !== self::calendarMethod($after)) $changed[] = 'METHOD';
			if ($changed) {
				throw new Forbidden('This update changes protected Dolibarr fields: '.implode(', ', array_unique($changed)).'.');
			}
			$oldDates = self::dates($old);
			$newDates = self::dates($new);
			$canResize = $allowResize
				&& !isset($old->RRULE) && !isset($old->RDATE) && !isset($old->{'RECURRENCE-ID'});
			if (!$canResize && ($oldDates['fullday'] !== $newDates['fullday'] || $oldDates['duration'] !== $newDates['duration'])) {
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
	 * Extract editable native values and leave other properties for strict comparison.
	 *
	 * @param VObject\Component $current Authoritative event
	 * @param VObject\Component $comparison Incoming event in the comparison clone
	 * @param array $descriptionContext Server-composed note boundaries and prefix formatter
	 * @return array Changed native fields, without client-supplied CRM context
	 */
	public static function appointmentFieldChanges($current, $comparison, array $descriptionContext = array()): array
	{
		$changes = array();
		foreach (array('SUMMARY', 'LOCATION', 'PRIORITY', 'TRANSP', 'DESCRIPTION') as $name) {
			if (count($current->select($name)) > 1 || count($comparison->select($name)) > 1) {
				throw new BadRequest('An appointment field is ambiguous: '.$name);
			}
			$old = (string) ($current->{$name} ?? '');
			$value = (string) ($comparison->{$name} ?? '');
			if ($name === 'TRANSP') $value = self::requestedTransparency($current, $comparison);
			if ($old === $value && isset($current->{$name}) === isset($comparison->{$name})) continue;
			if ($name === 'SUMMARY' || $name === 'LOCATION') {
				if ($name === 'LOCATION') $value = str_replace(array("\r\n", "\r"), "\n", $value);
				$value = trim($value);
				$singleLine = $name === 'LOCATION' ? str_replace("\n", ' ', $value) : $value;
				if (($name === 'SUMMARY' && $value === '') || $value === '0'
					|| mb_strlen($value, 'UTF-8') > ($name === 'SUMMARY' ? 255 : 128)
					|| preg_match('/[\x00-\x1F\x7F]|<\/?[a-z][^>]*>/i', $singleLine)) {
					throw new BadRequest('The appointment title or location is invalid for Dolibarr.');
				}
				$key = $name === 'SUMMARY' ? 'label' : 'location';
				$native = $descriptionContext['note_prefix_fields']['original_location'] ?? $old;
				if ($value !== ($key === 'location' ? $native : $old)) $changes[$key] = $value;
			} elseif ($name === 'PRIORITY') {
				if ($value === '' && !isset($comparison->PRIORITY)) $value = '0';
				if (!preg_match('/^[0-9]$/D', $value)) throw new BadRequest('Priority must be between 0 and 9.');
				if ((int) $old !== (int) $value) $changes['priority'] = (int) $value;
			} elseif ($name === 'TRANSP') {
				if ($value !== strtoupper($old === '' ? 'OPAQUE' : $old)) $changes['transparency'] = (int) ($value === 'OPAQUE');
			} elseif ($descriptionContext) {
				$prefix = $descriptionContext['note_prefix'];
				if (isset($descriptionContext['prefix_for_fields'])) {
					$original = $descriptionContext['note_prefix_fields'];
					$prefix = $descriptionContext['prefix_for_fields']($descriptionContext, $changes['label'] ?? $original['original_subject'],
						$changes['location'] ?? $original['original_location']);
				}
				// Repeating a new subject/address in the generated heading leaves the original rich notes intact.
				if ($value !== $prefix.substr($descriptionContext['text'], strlen($descriptionContext['note_prefix']))) {
					$note = self::nativeNoteForReplacement($descriptionContext, $value, $prefix);
					if ($note !== $descriptionContext['native_note']) {
						// iCalendar DESCRIPTION is plain text. The native notes editor also accepts HTML.
						$changes['note'] = htmlspecialchars($note, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
					}
				}
			} else {
				continue; // Without authoritative note boundaries DESCRIPTION stays protected.
			}
			// Preserve parameters on the comparison clone so a simultaneous metadata change is still checked.
			if (isset($current->{$name}, $comparison->{$name})) $comparison->{$name}->setValue($old);
			elseif (isset($current->{$name})) $comparison->add(clone $current->{$name});
			else $comparison->remove($name);
		}
		return $changes;
	}

	/**
	 * Plan an authorized standalone update without adopting native relationships.
	 * The backend must verify ownership before applying any global status change.
	 *
	 * @param string $current Current authoritative appointment
	 * @param string $incoming Client replacement appointment
	 * @param array $descriptionContext Trusted description boundaries for native note edits
	 * @return array<string,mixed> Canonical dates, state and server-owned revision
	 */
	public static function planStatusChange($current, $incoming, array $descriptionContext = array())
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
			$fields = ($oldStatus === 'CANCELLED' || $status === 'CANCELLED') ? array()
				: self::appointmentFieldChanges($old, $test, $descriptionContext);
			$old->STATUS = $oldStatus;
			$test->STATUS = $oldStatus;
			if ($status === 'CANCELLED') {
				$test->remove('TRANSP');
				foreach ($old->select('TRANSP') as $property) $test->add(clone $property);
				$test->remove('VALARM');
				foreach ($old->select('VALARM') as $alarm) $test->add(clone $alarm);
			}
			$dates = self::assertMoveOnly($before->serialize(), $comparison->serialize(), $status !== 'CANCELLED');
			$oldDates = self::dates($old);
			$resized = $dates['fullday'] !== $oldDates['fullday'] || self::dates($new)['duration'] !== $oldDates['duration'];
			unset($oldDates['duration']);
			$dateChanged = $dates !== $oldDates;
			if (($status === 'CANCELLED' && $dateChanged)
				|| ($oldStatus === 'CANCELLED' && ($statusChanged || $dateChanged))) {
				throw new Forbidden('A cancelled appointment cannot be restored or moved through CalDAV.');
			}
			$changed = $statusChanged || $dateChanged || (bool) $fields;
			$sequence = self::nextSequence($old, $changed);
			if ($changed) {
				foreach (array('DTSTART', 'DTEND', 'DUE', 'DURATION') as $name) {
					$old->remove($name);
					foreach ($new->select($name) as $property) $old->add(clone $property);
				}
				$old->STATUS = $status;
				$old->SEQUENCE = $sequence;
				if ($status === 'CANCELLED') $old->TRANSP = 'TRANSPARENT';
			}
			return array('kind' => !$changed ? 'noop' : ($status === 'CANCELLED' ? 'cancel' : 'status'),
				'changed' => $changed, 'date_changed' => $dateChanged, 'resized' => $resized, 'status' => $status, 'fields' => $fields,
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
			if ($source->name === 'VTODO') {
				// Native progress supplies STATUS after both DAV and Dolibarr edits.
				if ((string) $source->STATUS !== 'CANCELLED') $target->remove('STATUS');
				$target->remove('COMPLETED');
				if (isset($source->COMPLETED)) $target->add(clone $source->COMPLETED);
			} elseif ($plan['status'] !== $plan['previous_status']) {
				$target->STATUS = $plan['status'];
				$target->remove('X-MICROSOFT-CDO-BUSYSTATUS');
			}
			$target->SEQUENCE = (int) $plan['sequence'];
			if ($source->name === 'VEVENT' && $plan['status'] === 'CANCELLED') {
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
	 * Prefer an explicit RFC availability edit, then a changed Outlook Show As hint.
	 *
	 * @param VObject\Component $current Server export for the affected calendar
	 * @param VObject\Component $incoming Client replacement event
	 * @return string Effective personal RFC transparency
	 */
	public static function requestedTransparency($current, $incoming): string
	{
		if (count($current->select('TRANSP')) > 1 || count($incoming->select('TRANSP')) > 1) throw new BadRequest('Ambiguous appointment transparency.');
		$old = strtoupper(trim((string) ($current->TRANSP ?? 'OPAQUE')));
		$value = strtoupper(trim((string) ($incoming->TRANSP ?? $old)));
		if (!in_array($value, array('OPAQUE', 'TRANSPARENT'), true)) throw new BadRequest('Unsupported appointment transparency.');
		$busy = self::outlookBusyStatus($incoming);
		if ($value === $old && $busy !== self::outlookBusyStatus($current)) {
			if ($busy === 'FREE') return 'TRANSPARENT';
			if ($busy === 'BUSY' || $busy === 'TENTATIVE') return 'OPAQUE';
		}
		return $value;
	}

	/**
	 * Reconcile the native Agenda percentage with the client's VTODO status.
	 * @param VObject\Component $old Current task
	 * @param VObject\Component $new Incoming task
	 * @return int Native percentage
	 */
	private static function taskProgress($old, $new): int
	{
		$prior = (int) (string) $old->{'PERCENT-COMPLETE'};
		$percent = isset($new->{'PERCENT-COMPLETE'}) ? (int) (string) $new->{'PERCENT-COMPLETE'} : $prior;
		$previous = strtoupper((string) $old->STATUS);
		$status = strtoupper((string) ($new->STATUS ?? $previous));
		if ($prior < 0 || $prior > 100 || $percent < 0 || $percent > 100) throw new BadRequest('Task progress must be between 0 and 100.');
		if ($status === 'CANCELLED' && $status === $previous && $percent === $prior) return $prior;
		if (!in_array($status, array('NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED'), true)
			|| $previous === 'CANCELLED') throw new Forbidden('The requested task status cannot be represented by native Agenda progress.');
		if (isset($new->STATUS) && !isset($new->{'PERCENT-COMPLETE'})) {
			if ($status === 'COMPLETED') return 100;
			if ($status === 'NEEDS-ACTION') return 0;
			return $prior > 0 && $prior < 100 ? $prior : 1;
		}
		if (isset($new->STATUS, $new->{'PERCENT-COMPLETE'})
			&& (($status === 'COMPLETED') !== ($percent === 100) || ($status === 'NEEDS-ACTION') !== ($percent === 0))) {
			throw new BadRequest('The task status and percentage disagree.');
		}
		return $percent;
	}

	/**
	 * Sabre's INTEGER reader casts even malformed input such as "40%" to 40.
	 * @param string $data Original text/calendar body before Sabre parses integers
	 * @return void
	 */
	public static function validateTaskPercent(string $data): void
	{
		$unfolded = preg_replace('/\r?\n[ \t]/', '', $data);
		if (!preg_match_all('/^(?:[\w-]+\.)?PERCENT-COMPLETE(?:;(?:"[^"\r\n]*"|[^":\r\n])*)?:([^\r\n]*)/im', $unfolded, $matches)) return;
		foreach ($matches[1] as $value) {
			if (!preg_match('/^\+?0*(?:\d{1,2}|100)$/D', $value)) throw new BadRequest('Task progress must be an integer between 0 and 100.');
		}
	}

	/**
	 * Outlook tasks expose only the local day and send midnight / 23:59:59.
	 * Keep Dolibarr's clock times when applying the days from that representation.
	 * @param VObject\Component $old Authoritative task
	 * @param VObject\Component $new Comparison task
	 * @return void
	 */
	private static function normalizeTaskDays($old, $new): void
	{
		if (isset($new->DURATION)) return;
		$zone = new \DateTimeZone(date_default_timezone_get());
		$start = $new->DTSTART->getDateTime($zone);
		if ($start->format('His') !== '000000') return;
		if (isset($new->DUE) && $new->DUE->getDateTime($zone)->format('His') !== '235959') return;
		foreach (array('DTSTART', 'DUE') as $name) {
			if (!isset($new->{$name})) continue;
			$day = $new->{$name}->getDateTime($zone);
			if (!isset($old->{$name})) {
				if (!$old->DTSTART->hasTime()) {
					$new->remove($name);
					$new->add($name, $day->format('Ymd'), array('VALUE' => 'DATE'));
				}
				continue;
			}
			$original = clone $old->{$name};
			$time = $original->getDateTime($zone);
			$original->setDateTime($time->setDate((int) $day->format('Y'), (int) $day->format('m'), (int) $day->format('d')));
			$new->remove($name);
			$new->add($original);
		}
	}

	/**
	 * Plan a write to an Agenda ActionComm exported as VTODO.
	 * @param string $current Calendar generated from the native Agenda record
	 * @param string $incoming Replacement VTODO
	 * @param array $descriptionContext Trusted boundaries of the editable note
	 * @return array Native changes and canonical scheduling metadata
	 */
	public static function planTaskChange(string $current, string $incoming, array $descriptionContext = array()): array
	{
		self::validateTaskPercent($incoming);
		$before = VObject\Reader::read($current);
		$after = null;
		try {
			$after = VObject\Reader::read($incoming);
			$old = self::item($before);
			$new = self::item($after);
			if ($old->name !== 'VTODO' || $new->name !== 'VTODO') throw new Forbidden('A native Agenda task must remain a VTODO.');
			if (!isset($new->DTSTART) || isset($new->DTEND)) throw new BadRequest('Use DTSTART and an optional DUE or DURATION for an Agenda task.');
			foreach (array('DTSTART', 'DUE', 'DURATION', 'STATUS', 'PERCENT-COMPLETE', 'COMPLETED') as $name) {
				if (count($new->select($name)) > 1) throw new BadRequest('The task field is ambiguous: '.$name);
			}
			// Outlook rebuilds a task from scratch and does not map the Agenda URL, task location or occupancy.
			$omitsUrl = isset($old->URL) && !isset($new->URL);
			foreach (array('URL', 'CLASS', 'TRANSP', 'LOCATION', 'DESCRIPTION', 'PRIORITY') as $name) {
				if (!isset($new->{$name}) && isset($old->{$name})) $new->add(clone $old->{$name});
			}
			if (isset($old->DESCRIPTION, $new->DESCRIPTION)
				&& rtrim((string) $new->DESCRIPTION, "\r\n") === rtrim((string) $old->DESCRIPTION, "\r\n")) {
				$new->DESCRIPTION->setValue((string) $old->DESCRIPTION);
			}
			$oldDates = self::dates($old);
			if ($omitsUrl) self::normalizeTaskDays($old, $new);
			$fields = self::appointmentFieldChanges($old, $new, $descriptionContext);
			if ($omitsUrl && isset($fields['priority'])) {
				$prior = (int) (string) $old->PRIORITY;
				$outlookPriority = $prior === 0 || $prior === 5 ? 5 : ($prior < 5 ? 1 : 9);
				if ($fields['priority'] === $outlookPriority) unset($fields['priority']);
			}
			$prior = (int) (string) $old->{'PERCENT-COMPLETE'};
			$percent = self::taskProgress($old, $new);
			if ($percent !== $prior) $fields['percentage'] = $percent;
			$completed = isset($new->COMPLETED) ? (string) $new->COMPLETED : null;
			if ($percent < 100 && $prior === 100 && $completed === (string) ($old->COMPLETED ?? '')) $completed = null;
			if ($completed !== null) {
				$stamp = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $completed, new \DateTimeZone('UTC'));
				if (!$stamp || $stamp->format('Ymd\THis\Z') !== $completed || $percent !== 100) {
					throw new BadRequest('COMPLETED requires a completed task and a UTC completion date.');
				}
			}
			foreach (array('STATUS', 'PERCENT-COMPLETE', 'COMPLETED') as $name) {
				$new->remove($name);
				if (isset($old->{$name})) $new->add(clone $old->{$name});
			}
			$dates = self::assertMoveOnly($before->serialize(), $after->serialize(), true);
			$hadDue = isset($old->DUE) || isset($old->DURATION);
			$hasDue = isset($new->DUE) || isset($new->DURATION);
			$resized = $hadDue !== $hasDue || $dates['fullday'] !== $oldDates['fullday'] || self::dates($new)['duration'] !== $oldDates['duration'];
			unset($oldDates['duration']);
			$dateChanged = $hadDue !== $hasDue || $dates !== $oldDates;
			$changed = (bool) $fields || $dateChanged;
			if ((string) $old->STATUS === 'CANCELLED' && $changed) throw new Forbidden('A cancelled Agenda task cannot be edited through CalDAV.');
			$sequence = self::nextSequence($old, $changed);
			if ($changed) {
				foreach (array('DTSTART', 'DUE', 'DURATION') as $name) {
					$old->remove($name);
					if (isset($new->{$name})) $old->add(clone $new->{$name});
				}
				$old->SEQUENCE = $sequence;
				if ($percent !== $prior) {
					$old->{'PERCENT-COMPLETE'} = $percent;
					$old->STATUS = $percent === 100 ? 'COMPLETED' : ($percent === 0 ? 'NEEDS-ACTION' : 'IN-PROCESS');
					$old->remove('COMPLETED');
					if ($completed !== null) $old->add('COMPLETED', $completed);
				}
			}
			$dates['task_without_due'] = !$hasDue;
			return array('changed' => $changed, 'date_changed' => $dateChanged, 'resized' => $resized, 'fields' => $fields,
				'dates' => $dates, 'sequence' => $sequence, 'calendar_data' => $before->serialize());
		} catch (VObject\InvalidDataException $error) {
			throw new BadRequest('The task contains an invalid iCalendar date or duration.');
		} finally {
			$before->destroy();
			if ($after !== null) $after->destroy();
		}
	}

	/**
	 * Advance the original server revision only for a material change.
	 * @param VObject\Component $item Original Agenda component
	 * @param bool $changed Whether native data or state changed
	 * @return int Canonical server revision
	 */
	private static function nextSequence($item, bool $changed): int
	{
		$value = (string) ($item->SEQUENCE ?? '0');
		if (count($item->select('SEQUENCE')) > 1 || !preg_match('/^[0-9]+$/D', $value) || (float) $value > 2147483647) {
			throw new BadRequest('The calendar revision is invalid or ambiguous.');
		}
		if ($changed && (int) $value >= 2147483647) throw new Forbidden('The calendar revision cannot be advanced safely.');
		return (int) $value + (int) $changed;
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
	 * @return array Stable property signatures grouped by field or subcomponent
	 */
	private static function projection($component, $nested = false)
	{
		$properties = array();
		foreach ($component->children() as $child) {
			if ($child instanceof VObject\Component) {
				$properties[$child->name][] = json_encode(self::projection($child, true));
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
			$properties[$name][] = json_encode(array($child->getValueType(), $parameters, $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		foreach ($properties as &$values) sort($values, SORT_STRING);
		unset($values);
		ksort($properties, SORT_STRING);
		return $properties;
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
		$zone = new \DateTimeZone(date_default_timezone_get());
		$start = $startProperty->getDateTime($zone);
		$endProperty = isset($component->DTEND) ? $component->DTEND : ($component->DUE ?? null);
		if ($endProperty !== null) {
			$end = $endProperty->getDateTime($zone);
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
		if ($fullday && (($component->name === 'VEVENT' && $start == $end) || $end->format('His') !== '000000')) {
			throw new Forbidden('An all-day appointment must span whole calendar days.');
		}
		$duration = $fullday
			? (int) (new \DateTimeImmutable($start->format('Y-m-d'), new \DateTimeZone('UTC')))->diff(new \DateTimeImmutable($end->format('Y-m-d'), new \DateTimeZone('UTC')))->days
			: $end->getTimestamp() - $start->getTimestamp();
		return array('start' => $start->getTimestamp(), 'end' => $end->getTimestamp(), 'fullday' => (int) $fullday, 'duration' => $duration);
	}
}
