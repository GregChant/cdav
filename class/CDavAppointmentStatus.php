<?php

namespace Dolibarr\CDav;

/** Keep the appointment state separate from native progress and personal availability. */
class AppointmentStatus
{
	/**
	 * Validate the server-owned optional field without accepting arbitrary iCalendar text.
	 *
	 * @param mixed $value Native optional field value
	 * @return string Supported global state, confirmed for legacy empty fields
	 */
	public static function normalize($value): string
	{
		if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('Invalid appointment state.');
		$status = strtoupper(trim((string) $value));
		if ($status === '') return 'CONFIRMED';
		if (!in_array($status, array('TENTATIVE', 'CONFIRMED', 'CANCELLED'), true)) {
			throw new \InvalidArgumentException('Unsupported appointment state.');
		}
		return $status;
	}

	/**
	 * Read legacy scheduling metadata without interpreting a participant response as global state.
	 *
	 * @param string $calendarData Stored iCalendar resource
	 * @return string|null Global state, or null for a resource without an appointment
	 */
	public static function statusFromCalendar($calendarData): ?string
	{
		if ((string) $calendarData === '') return null;
		require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
		require_once __DIR__.'/CDavCalendarWritePolicy.php';
		$calendar = \Sabre\VObject\Reader::read((string) $calendarData);
		try {
			foreach ($calendar->select('VEVENT') as $event) {
				if (!isset($event->{'RECURRENCE-ID'})) return self::componentStatus($event);
			}
			return null;
		} finally {
			$calendar->destroy();
		}
	}

	/**
	 * Prefer explicit RFC state and accept Outlook's busy state when RFC state is absent.
	 *
	 * @param \Sabre\VObject\Component $event Appointment component
	 * @return string Validated global state
	 */
	public static function componentStatus($event): string
	{
		require_once __DIR__.'/CDavCalendarWritePolicy.php';
		$status = CalendarWritePolicy::eventStatus($event);
		$busy = CalendarWritePolicy::outlookBusyStatus($event);
		return !isset($event->STATUS) && $busy === 'TENTATIVE' ? 'TENTATIVE' : $status;
	}

	/**
	 * Install a native select field; an empty SQL default preserves older DAV metadata.
	 *
	 * @param \DoliDB $db Native connection
	 * @return int Positive on success, negative on failure
	 */
	public static function migrate($db): int
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$fields = new \ExtraFields($db);
		$permissions = '$user->hasRight("agenda", "allactions", "write") || ($user->hasRight("agenda", "myactions", "write") && ($object->id == 0 || $object->userownerid == $user->id))';
		return $fields->addExtraField('cdav_status', 'CDavAppointmentStatus', 'select', 100, '', 'actioncomm', 0, 0, '',
			array('options' => array('CONFIRMED' => 'CDavAppointmentConfirmed', 'TENTATIVE' => 'CDavAppointmentTentative', 'CANCELLED' => 'CDavAppointmentCancelled')),
			1, $permissions, '1', 'CDavAppointmentStatusHelp', '', '', 'cdav@cdav', '1', 0, 0, array(), '', 1);
	}

	/**
	 * Supply the native widget with the effective legacy state without rewriting existing rows.
	 *
	 * @param \DoliDB $db Native connection
	 * @param \ActionComm $event Agenda object
	 * @return string Authoritative native state or legacy metadata/default
	 */
	public static function nativeStatus($db, $event): string
	{
		$value = $event->array_options['options_cdav_status'] ?? '';
		if ($value !== null && $value !== '') return self::normalize($value);
		if (empty($event->id)) return 'CONFIRMED';
		$result = $db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.((int) $event->id));
		if (!$result) throw new \RuntimeException('Unable to read the appointment state.');
		$row = $db->fetch_object($result);
		return $row ? (self::statusFromCalendar((string) $row->calendardata) ?? 'CONFIRMED') : 'CONFIRMED';
	}

	/**
	 * Synchronize a native write inside its existing transaction, never trusting personal PARTSTAT.
	 *
	 * @param \DoliDB $db Native connection
	 * @param \ActionComm $event Persisted Agenda object
	 * @param \User $user Authenticated native actor
	 * @param string $action Native trigger code
	 * @return void
	 */
	public static function synchronizeNative($db, $event, $user, $action): void
	{
		if (!empty($event->context['cdav_status_managed'])) return;
		$percentage = (int) $event->percentage;
		if ($percentage >= 0 && ($action !== 'ACTION_MODIFY' || $percentage >= 100)) return;
		$result = $db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.((int) $event->id).' FOR UPDATE');
		if (!$result) throw new \RuntimeException('Unable to lock the appointment state.');
		$row = $db->fetch_object($result);
		$stored = $row ? (string) $row->calendardata : '';
		if ($percentage >= 0) {
			$metadata = self::metadataForReopenedTask($stored);
			if ($metadata !== null && !$db->query('UPDATE '.MAIN_DB_PREFIX."cdav_scheduling SET calendardata = '".$db->escape($metadata)."' WHERE fk_actioncomm = ".((int) $event->id))) {
				throw new \RuntimeException('Unable to clear the previous completion date of the reopened Agenda task.');
			}
			return;
		}
		$previous = self::statusFromCalendar($stored) ?? 'CONFIRMED';
		$value = $event->array_options['options_cdav_status'] ?? '';
		$status = $value === null || $value === '' ? $previous : self::normalize($value);
		if ($status !== $previous) {
			$owner = !empty($event->oldcopy->userownerid) ? (int) $event->oldcopy->userownerid : (int) $event->userownerid;
			if (!$user->hasRight('agenda', 'allactions', 'write')
				&& ($owner !== (int) $user->id || !$user->hasRight('agenda', 'myactions', 'write'))) {
				throw new \RuntimeException('Only the appointment owner may change its global state.');
			}
			if ($previous === 'CANCELLED') throw new \RuntimeException('A cancelled appointment cannot be restored; create a new appointment.');
			self::preserveNativeParticipants($db, $event, $user);
		}
		if ($value !== $status) {
			$event->array_options['options_cdav_status'] = $status;
			if ($event->updateExtraField('cdav_status', '', $user) < 0) throw new \RuntimeException('Unable to save the appointment state.');
		}
		if ($status === 'CANCELLED') {
			self::clearOccupancy($event);
			// CommonObject's setter avoids rewriting native business links and participant answers.
			if ($event->setValueFrom('transparency', 0, '', null, 'int', 'id', $user) <= 0) {
				throw new \RuntimeException('Unable to free the cancelled appointment.');
			}
			// No native bulk resource setter exists in Agenda 23/24.
			if (!$db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET transparency = 0 WHERE fk_actioncomm = '.((int) $event->id))) {
				throw new \RuntimeException('Unable to free the cancelled participants.');
			}
			self::cancelPendingReminders($db, (int) $event->id, $user);
		}
		if ($status === $previous) return;
		self::storeNativeState($db, $event, $stored, $status);
	}

	/**
	 * Discard the former completion of a task reopened in Agenda, keeping its exceptions.
	 * @param string $stored Existing protocol metadata
	 * @return string|null Updated metadata, or null when the native write requires no protocol change
	 */
	public static function metadataForReopenedTask(string $stored): ?string
	{
		if ($stored === '') return null;
		require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
		try {
			$calendar = \Sabre\VObject\Reader::read($stored);
		} catch (\Sabre\VObject\ParseException $error) {
			// A legacy unreadable sidecar has no exported completion. Leave it intact for repair.
			return null;
		}
		try {
			$changed = false;
			foreach ($calendar->select('VTODO') as $task) {
				if (isset($task->{'RECURRENCE-ID'})) continue;
				$completedStatus = strtoupper((string) ($task->STATUS ?? '')) === 'COMPLETED';
				if (!isset($task->COMPLETED) && !$completedStatus) continue;
				$task->remove('COMPLETED');
				if ($completedStatus) $task->remove('STATUS');
				if ((string) ($task->{'PERCENT-COMPLETE'} ?? '') === '100') $task->remove('PERCENT-COMPLETE');
				$changed = true;
			}
			return $changed ? $calendar->serialize() : null;
		} finally {
			$calendar->destroy();
		}
	}

	/**
	 * Bridge native Agenda's response cast and reset of hidden participant metadata.
	 * The standard Agenda editor has no participant response control. Its full update
	 * must retain other participants' availability while accepting the actor's checkbox.
	 *
	 * @param \DoliDB $db Native connection
	 * @param \ActionComm $event Modified native appointment with its original snapshot
	 * @param \User $user Actor whose availability is explicitly editable in the form
	 * @return void
	 */
	private static function preserveNativeParticipants($db, $event, $user): void
	{
		if (empty($event->oldcopy)) return;
		foreach (array('user' => 'userassigned', 'socpeople' => 'socpeopleassigned') as $type => $field) {
			foreach (($event->oldcopy->{$field} ?? array()) as $assignment) {
				if (!is_array($assignment) || empty($assignment['id'])) continue;
				$id = (int) $assignment['id'];
				if (!isset($event->{$field}[$id])) continue;
				$status = $assignment['answer_status'] ?? null;
				$contact = $type === 'socpeople';
				$changes = array('answer_status = '.($status !== null ? '\''.$db->escape((string) $status).'\'' : 'NULL'));
				$event->{$field}[$id]['answer_status'] = $status;
				foreach (array('mandatory', 'transparency') as $property) {
					if ($property === 'transparency' && !$contact && $id === (int) $user->id) continue;
					$event->{$field}[$id][$property] = (int) ($assignment[$property] ?? 0);
					$changes[] = $property.' = '.$event->{$field}[$id][$property];
				}
				if (!$db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET '.implode(', ', $changes).' WHERE fk_actioncomm = '.((int) $event->id).' AND element_type = \''.$type.'\' AND fk_element = '.$id)) {
					throw new \RuntimeException('Unable to preserve the participant response.');
				}
			}
		}
	}

	/**
	 * Free every native participant without changing identities, links or responses.
	 *
	 * @param \ActionComm $event Mutable native appointment
	 * @return void
	 */
	public static function clearOccupancy($event): void
	{
		$event->transparency = 0;
		foreach (array('userassigned', 'socpeopleassigned') as $field) {
			foreach ($event->{$field} as &$assignment) {
				if (is_array($assignment)) $assignment['transparency'] = 0;
			}
			unset($assignment);
		}
	}

	/**
	 * Remove pending reminders through their native API, preserving processed history.
	 *
	 * @param \DoliDB $db Native connection
	 * @param int $eventId Cancelled Agenda item
	 * @param \User $user Authenticated actor
	 * @return void
	 */
	public static function cancelPendingReminders($db, $eventId, $user): void
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
		$result = $db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE fk_actioncomm = '.((int) $eventId).' AND status = 0 FOR UPDATE');
		if (!$result) throw new \RuntimeException('Unable to inspect reminders of the cancelled meeting.');
		$hasMapping = (bool) $db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_reminder');
		while ($row = $db->fetch_object($result)) {
			$reminder = new \ActionCommReminder($db);
			if ($reminder->fetch((int) $row->rowid) <= 0 || $reminder->delete($user) <= 0) {
				throw new \RuntimeException('Unable to remove a pending reminder of the cancelled meeting.');
			}
			if ($hasMapping
				&& !$db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_reminder WHERE fk_actioncomm = '.((int) $eventId).' AND fk_reminder = '.((int) $row->rowid))) {
				throw new \RuntimeException('Unable to remove cancelled reminder provenance.');
			}
		}
	}

	/**
	 * Preserve server-owned identity and scheduling metadata while advancing a native state change.
	 *
	 * @param \DoliDB $db Native connection
	 * @param \ActionComm $event Native Agenda item
	 * @param string $stored Original sidecar data
	 * @param string $status Validated global state
	 * @return void
	 */
	private static function storeNativeState($db, $event, $stored, $status): void
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
		$calendar = $stored !== '' ? \Sabre\VObject\Reader::read($stored) : new \Sabre\VObject\Component\VCalendar();
		try {
			$events = $calendar->select('VEVENT');
			if (!$events) {
				$result = $db->query('SELECT sourceuid FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.((int) $event->id));
				if (!$result) throw new \RuntimeException('Unable to read the appointment identity.');
				$row = $db->fetch_object($result);
				$uid = $row && !empty($row->sourceuid) ? (string) $row->sourceuid : ((int) $event->id).'-ev-'.getDolGlobalString('CDAV_URI_KEY');
				$events = array($calendar->add('VEVENT', array('UID' => $uid)));
			}
			foreach ($events as $item) {
				$sequence = isset($item->SEQUENCE) ? (int) (string) $item->SEQUENCE : 0;
				if ($sequence < 0 || $sequence >= 2147483647) throw new \RuntimeException('The appointment revision cannot be advanced safely.');
				$item->STATUS = $status;
				$item->SEQUENCE = $sequence + 1;
				if ($status === 'CANCELLED') {
					$item->TRANSP = 'TRANSPARENT';
					$item->remove('VALARM');
				}
				$item->remove('X-MICROSOFT-CDO-BUSYSTATUS');
			}
			$data = $calendar->serialize();
			if (!$db->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_scheduling (fk_actioncomm, calendardata) VALUES ('.((int) $event->id).', \''.$db->escape($data).'\') ON DUPLICATE KEY UPDATE calendardata = VALUES(calendardata)')) {
				throw new \RuntimeException('Unable to save the appointment scheduling state.');
			}
		} finally {
			$calendar->destroy();
		}
	}
}
