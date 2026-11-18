<?php

/**
 * Define Common function to access calendar items
 * And format it in vCalendar
 * */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';


class CdavLib
{
	/** Change this value whenever the generated DAV representation changes. */
	private const CALENDAR_SERIALIZATION_VERSION = '2026-11-readable-business-description-v6';

	private $db;

	private $user;

	private $langs;

	/** Use DAV Basic authentication for document downloads from DAV clients. */
	private $davDocumentUrls;

	/** @var bool|null Whether the optional iCalendar metadata table exists. */
	private $hasSchedulingTable = null;

	/** @var bool|null Whether CalDAV recurrence projection metadata exists. */
	private $hasRecurrenceTable = null;

	/** @var bool|null Whether booking references can be verified against native event receipts. */
	private $hasBookingReferenceTables = null;

	/** @var array<int,bool> Native project authorization, cached only for this exporter/request. */
	private $projectDescriptionAccess = array();

	/** @var array<int>|null Native project IDs available to the authenticated user. */
	private $readableProjectIds = null;

	/** @var array<int,array<int>> Readable assigned interventions by calendar owner. */
	private $readableInterventionIds = array();

	/** @var array<int,bool> Native intervention object access decisions. */
	private $interventionReadAccess = array();

	function __construct($user, $db, $langs, $davDocumentUrls = false)
	{
		$this->user 	= $user;
		$this->db 		= $db;
		$this->langs 	= $langs;
		$this->langs->loadLangs(array('main', 'companies', 'cdav@cdav'));
		$this->davDocumentUrls = (bool) $davDocumentUrls;
	}

	/**
	 * Check a permission through Dolibarr's native User API.
	 *
	 * Directly walking the rights stdClass used to emit several warnings per
	 * event when a DAV/ICS caller supplied an incomplete user object.
	 */
	private function hasRight($module, $level1, $level2 = '')
	{
		if (!is_object($this->user) || !method_exists($this->user, 'hasRight')) {
			return false;
		}
		return (bool) $this->user->hasRight($module, $level1, $level2);
	}

	/** Normalize nullable SQL fields before passing them to PHP string APIs. */
	private function normalizeDatabaseRow($row)
	{
		foreach (get_object_vars($row) as $key => $value) {
			if ($value === null) {
				$row->{$key} = '';
			}
		}
		return $row;
	}

	/**
	 * Convert Dolibarr rich text to readable plain text while preserving lines.
	 * dol_string_nohtmltag() is the canonical Dolibarr HTML/entity cleaner.
	 */
	private function cleanDolibarrText($value)
	{
		$value = (string) $value;
		if ($value === '') {
			return '';
		}

		// Preserve the visual structure of WYSIWYG block elements before the
		// native cleaner removes their tags. It already converts <br> itself.
		$value = preg_replace('/<\s*li\b[^>]*>/i', '- ', $value);
		$value = preg_replace('/<\s*\/\s*(?:p|div|li|ul|ol|h[1-6]|blockquote|tr)\s*>/i', "\n", $value);
		$value = dol_string_nohtmltag($value, 0, 'UTF-8');
		$value = str_replace("\xC2\xA0", ' ', $value);
		$value = str_replace(array("\r\n", "\r"), "\n", $value);
		$value = preg_replace('/[ \t]+\n/', "\n", $value);
		$value = preg_replace('/\n{3,}/', "\n\n", $value);
		return trim($value);
	}

	/** RFC 5545 TEXT escaping. */
	private function escapeICalendarText($value)
	{
		return strtr((string) $value, array(
			'\\' => '\\\\',
			';' => '\\;',
			',' => '\\,',
			"\r\n" => '\\n',
			"\r" => '\\n',
			"\n" => '\\n',
		));
	}

	private function cleanAndEscapeICalendarText($value)
	{
		return $this->escapeICalendarText($this->cleanDolibarrText($value));
	}

	private function formatChecklistText($value)
	{
		$value = $this->cleanDolibarrText($value);
		return ltrim(strtr("\n".$value, array(
			"\n- [" => "\n[",
			"\n- " => "\n[ ] ",
			"[x] [ ]" => "[x]",
			"[ ] [x]" => "[x]",
			"[x] [x]" => "[x]",
			"[ ] [ ]" => "[ ]",
		)), "\n");
	}

	/**
	 * Add one labeled native value without decorating every line of a paragraph.
	 * @param array $lines Current section
	 * @param string $key Native translation key
	 * @param string $value Native value
	 * @return void
	 */
	private function addDescriptionValue(array &$lines, $key, $value)
	{
		$value = $this->cleanDolibarrText($value);
		if ($value !== '') $lines[] = $this->langs->transnoentitiesnoconv($key).' : '.$value;
	}

	/**
	 * Confirm the exact technical header before displaying it as a booking footer.
	 * The receipt is protocol state; arbitrary user notes never authorize stripping
	 * a similar-looking line. Missing or expired receipts preserve the original note.
	 * @param object $event Native export row
	 * @param string $note Native plain-text note
	 * @return string Verified reference, otherwise empty
	 */
	private function descriptionBookingReference($event, $note)
	{
		if (($event->elem_source ?? '') !== 'ev' || empty($event->id)
			|| !preg_match('/\ACDav booking ([a-f0-9]{32})(?:\n|\z)/D', $note, $match)) return '';
		if ($this->hasBookingReferenceTables === null) {
			$this->hasBookingReferenceTables = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_booking_request')
				&& (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_booking_request_event');
		}
		if (!$this->hasBookingReferenceTables) return '';
		$result = $this->db->query('SELECT request.reference FROM '.MAIN_DB_PREFIX.'cdav_booking_request request LEFT JOIN '.MAIN_DB_PREFIX.'cdav_booking_request_event segment ON segment.request_hash = request.request_hash WHERE request.reference = \''.$this->db->escape($match[1]).'\' AND (request.fk_actioncomm = '.((int) $event->id)
			.' OR segment.fk_actioncomm = '.((int) $event->id).') LIMIT 1');
		$row = $result ? $this->db->fetch_object($result) : null;
		return $row && hash_equals($match[1], (string) $row->reference) ? $match[1] : '';
	}

	/**
	 * Read a native postal address as one clean multiline value.
	 * @param object $row Native export row
	 * @param string $prefix Field prefix
	 * @return string Postal address
	 */
	private function descriptionAddress($row, $prefix = '')
	{
		$street = $this->cleanDolibarrText($row->{$prefix.'address'} ?? '');
		$town = trim(($row->{$prefix.'zip'} ?? '').' '.($row->{$prefix.'town'} ?? ''));
		$country = $this->cleanDolibarrText($row->{$prefix.'country_label'} ?? '');
		return implode("\n", array_filter(array($street, $town, $country), static fn($value) => $value !== ''));
	}

	/**
	 * Agenda visibility reveals a project link, never permission to read its contents.
	 * @param object $row Native Agenda/task/intervention row
	 * @return bool Whether the native project description may be exported
	 */
	public function canReadProjectDescription($row)
	{
		if (!isModEnabled('project') || !$this->hasRight('projet', 'lire')) return false;
		$id = property_exists($row, 'cdav_project_id') ? (int) $row->cdav_project_id
			: (int) ($row->fk_project ?? $row->fk_projet ?? 0);
		if ($id <= 0) return false;
		if (!array_key_exists($id, $this->projectDescriptionAccess)) {
			$this->projectDescriptionAccess[$id] = false;
			try {
				if (!class_exists('Project')) require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
				$project = new \Project($this->db);
				$this->projectDescriptionAccess[$id] = $project->fetch($id) > 0
					&& in_array((int) $project->entity, array_map('intval', explode(',', getEntity('project'))), true)
					&& $project->restrictedProjectArea($this->user, 'read') > 0;
			} catch (\Throwable $error) {
				$this->projectDescriptionAccess[$id] = false;
			}
		}
		return $this->projectDescriptionAccess[$id];
	}

	/**
	 * Reuse the project-card scope for listing tasks, including private-project roles.
	 * @return array<int> Native readable project IDs
	 */
	private function nativeReadableProjectIds()
	{
		if ($this->readableProjectIds !== null) return $this->readableProjectIds;
		$this->readableProjectIds = array();
		try {
			if (!class_exists('Project')) require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
			$project = new \Project($this->db);
			$ids = $project->getProjectsAuthorizedForUser($this->user, $this->hasRight('projet', 'all', 'lire') ? 2 : 0, 1, 0);
			if (is_string($ids) && preg_match('/^[0-9]+(?:,[0-9]+)*$/D', $ids)) {
				$this->readableProjectIds = array_values(array_filter(array_unique(array_map('intval', explode(',', $ids))), static fn($id) => $id > 0));
			}
		} catch (\Throwable $error) {
			$this->readableProjectIds = array();
		}
		return $this->readableProjectIds;
	}

	/**
	 * Reuse native intervention entity/commercial checks before selecting line details.
	 * @param int $calendarId Native calendar owner
	 * @return array<int> Assigned parent documents readable by the authenticated user
	 */
	private function nativeReadableInterventionIds($calendarId)
	{
		$calendarId = (int) $calendarId;
		if (isset($this->readableInterventionIds[$calendarId])) return $this->readableInterventionIds[$calendarId];
		$this->readableInterventionIds[$calendarId] = array();
		if (!function_exists('checkUserAccessToObject')) require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		$result = $this->db->query('SELECT DISTINCT fi.rowid FROM '.MAIN_DB_PREFIX.'fichinter fi INNER JOIN '.MAIN_DB_PREFIX.'element_contact ec ON ec.element_id = fi.rowid INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact tc ON tc.rowid = ec.fk_c_type_contact WHERE tc.element = \'fichinter\' AND tc.source = \'internal\' AND ec.fk_socpeople = '.$calendarId.' AND fi.entity IN ('.getEntity('intervention').')');
		if (!$result) return array();
		$ids = array();
		while ($row = $this->db->fetch_object($result)) $ids[] = (int) $row->rowid;
		foreach ($ids as $id) {
			if (!array_key_exists($id, $this->interventionReadAccess)) {
				try {
					$this->interventionReadAccess[$id] = (bool) checkUserAccessToObject($this->user, array('ficheinter'), $id, 'fichinter', '', 'fk_soc');
				} catch (\Throwable $error) {
					$this->interventionReadAccess[$id] = false;
				}
			}
			if ($this->interventionReadAccess[$id]) $this->readableInterventionIds[$calendarId][] = $id;
		}
		return $this->readableInterventionIds[$calendarId];
	}

	/**
	 * Build ordinary iCalendar TEXT sections and a trusted editable-note envelope.
	 * @param object $row Native event, task or intervention export row
	 * @param string $subject Already selected native subject
	 * @param string $location Already selected native location
	 * @param string $note Native notes, cleaned or checklist-formatted by the caller
	 * @param string $nativeNote Original SQL note, retained on a harmless client echo
	 * @param string $publicNote Public parent-document note, outside the editable region
	 * @return array<string,mixed> Text and trusted note boundaries
	 */
	private function calendarDescriptionParts($row, $subject, $location, $note, $nativeNote, $publicNote = '')
	{
		$reference = $this->descriptionBookingReference($row, $note);
		$visitor = array();
		if ($reference !== '') {
			$header = 'CDav booking '.$reference;
			$note = (string) substr($note, strlen($header));
			if (str_starts_with($note, "\n")) $note = substr($note, 1);
			$bookingLines = explode("\n", $note, 4);
			if (count($bookingLines) === 4 && filter_var($bookingLines[1], FILTER_VALIDATE_EMAIL)) {
				$this->addDescriptionValue($visitor, 'Name', $bookingLines[0]);
				$this->addDescriptionValue($visitor, 'Email', $bookingLines[1]);
				$this->addDescriptionValue($visitor, 'Phone', $bookingLines[2]);
				$note = $bookingLines[3];
				$generatedDetails = $this->langs->transnoentitiesnoconv('CDavBookingSubject').': '.$this->cleanDolibarrText($subject)
					."\n".$this->langs->transnoentitiesnoconv('CDavBookingClientAddress').":\n".$this->cleanDolibarrText($location);
				if ($note === $generatedDetails) $note = '';
			}
		}
		$sections = array();
		$publicNote = $this->cleanDolibarrText($publicNote);

		$client = $visitor;
		$this->addDescriptionValue($client, 'ThirdParty', $row->soc_nom ?? '');
		$this->addDescriptionValue($client, 'Contact', trim(($row->firstname ?? '').' '.($row->lastname ?? '')));
		$this->addDescriptionValue($client, 'Contacts', $row->other_contacts ?? '');
		$companyAddress = $this->descriptionAddress($row, 'soc_');
		$contactAddress = $this->descriptionAddress($row);
		$this->addDescriptionValue($client, 'Address', $companyAddress);
		if ($contactAddress !== $companyAddress) $this->addDescriptionValue($client, 'Address', $contactAddress);
		$this->addDescriptionValue($client, 'Phone', $row->soc_phone ?? '');
		$this->addDescriptionValue($client, 'PhonePro', $row->phone ?? '');
		$this->addDescriptionValue($client, 'PhonePerso', $row->phone_perso ?? '');
		$this->addDescriptionValue($client, 'PhoneMobile', $row->phone_mobile ?? '');
		$this->addDescriptionValue($client, 'Email', $row->soc_email ?? '');
		$this->addDescriptionValue($client, 'Email', $row->contact_email ?? '');
		if ($client) $sections[] = $this->langs->transnoentitiesnoconv('CDavCalendarClientContact')."\n".implode("\n", $client);

		$project = array();
		$projectRef = $this->cleanDolibarrText($row->proj_ref ?? '');
		if (str_starts_with((string) ($row->elem_source ?? ''), 'p') && !empty($row->ref)) $projectRef .= '/'.$row->ref;
		$projectTitle = trim(($projectRef !== '' ? '['.$projectRef.'] ' : '').$this->cleanDolibarrText($row->proj_title ?? ''));
		if ($projectTitle !== '') $project[] = $projectTitle;
		$projectNote = $this->canReadProjectDescription($row) ? $this->cleanDolibarrText($row->proj_desc ?? '') : '';
		if ($projectNote !== '') $project[] = $projectNote;
		if ($project) $sections[] = $this->langs->transnoentitiesnoconv('CDavCalendarProject')."\n".implode("\n\n", $project);
		if ($reference !== '') $sections[] = $this->langs->transnoentitiesnoconv('CDavCalendarBookingReference').' : '.$reference;
		$contextText = implode("\n\n", $sections);
		$prefixFields = array('heading' => $this->langs->transnoentitiesnoconv('CDavCalendarAppointment'),
			'subject_label' => $this->langs->transnoentitiesnoconv('CDavBookingSubject'),
			'location_label' => $this->langs->transnoentitiesnoconv('CDavBookingClientAddress'),
			'original_subject' => $this->cleanDolibarrText($subject), 'original_location' => $this->cleanDolibarrText($location),
			'subject_editable' => ($row->elem_source ?? 'ev') !== 'fi', 'location_editable' => ($row->elem_source ?? 'ev') === 'ev',
			'public_note' => $publicNote, 'note_present' => $note !== '', 'context_present' => $contextText !== '');
		$prefix = $this->getCalendarDescriptionPrefixForFields(array('note_prefix_fields' => $prefixFields), $subject, $location);
		$suffix = ($note !== '' && $contextText !== '' ? "\n\n" : '').$contextText;
		return array('text' => $prefix.$note.$suffix, 'note_prefix' => $prefix, 'note_suffix' => $suffix,
			'native_note' => (string) $nativeNote, 'notes_editable' => $reference === '', 'note_prefix_fields' => $prefixFields);
	}

	/**
	 * Rebuild generated appointment fields when a client also updates their iCal fields.
	 * Trusted structure preserves parent-document notes even when they contain the old
	 * subject or address. CRM context and the editable note are never searched/replaced.
	 * @param array<string,mixed> $parts Server-composed description metadata
	 * @param string $subject New native appointment subject allowed by the caller
	 * @param string $location New native appointment location allowed by the caller
	 * @return string Trusted generated prefix for those explicit values
	 */
	public function getCalendarDescriptionPrefixForFields(array $parts, $subject, $location)
	{
		if (!isset($parts['note_prefix_fields'])) return (string) ($parts['note_prefix'] ?? '');
		$fields = $parts['note_prefix_fields'];
		$lines = array();
		$subject = !empty($fields['subject_editable']) ? $this->cleanDolibarrText($subject) : $fields['original_subject'];
		$location = !empty($fields['location_editable']) ? $this->cleanDolibarrText($location) : $fields['original_location'];
		if ($subject !== '') $lines[] = $fields['subject_label'].' : '.$subject;
		if ($location !== '') $lines[] = $fields['location_label'].' : '.$location;
		if ($fields['public_note'] !== '') $lines[] = ($lines ? "\n" : '').$fields['public_note'];
		$appointment = $lines || $fields['note_present'] ? $fields['heading']."\n".implode("\n", $lines) : '';
		return $appointment.($appointment !== '' && ($fields['note_present'] || $fields['context_present']) ? "\n\n" : '');
	}

	/**
	 * Provide the same server composition to the exporter and its guarded DAV import.
	 * @param object $row Native database export row
	 * @return array<string,mixed> Human-readable text and exact native note envelope
	 */
	public function getCalendarDescriptionParts($row)
	{
		$source = (string) ($row->elem_source ?? 'ev');
		if (str_starts_with($source, 'p')) {
			$nativeNote = (string) ($row->description ?? '');
			return $this->calendarDescriptionParts($row, $row->label ?? '', '', $this->formatChecklistText($nativeNote),
				$nativeNote, $row->note_public ?? '');
		}
		if ($source === 'fi') {
			$nativeNote = (string) ($row->det_description ?? '');
			$subject = trim((string) ($row->fi_description ?? ''));
			if ($subject === '') $subject = trim((string) ($row->soc_nom ?? ''));
			$subject = '['.trim((string) ($row->fi_ref ?? '')).'] '.$subject;
			return $this->calendarDescriptionParts($row, $subject, '', $this->cleanDolibarrText($nativeNote),
				$nativeNote, $row->fi_note_public ?? '');
		}
		$nativeNote = (string) ($row->note ?? '');
		$note = (int) ($row->percent ?? -1) === -1 ? $this->cleanDolibarrText($nativeNote) : $this->formatChecklistText($nativeNote);
		return $this->calendarDescriptionParts($row, $row->label ?? '', $row->location ?? '', $note, $nativeNote);
	}

	private function schedulingTableAvailable()
	{
		if ($this->hasSchedulingTable !== null) {
			return $this->hasSchedulingTable;
		}
		$this->hasSchedulingTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_scheduling');
		return $this->hasSchedulingTable;
	}

	private function recurrenceTableAvailable()
	{
		if ($this->hasRecurrenceTable === null) {
			$this->hasRecurrenceTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_recurrence');
		}
		return $this->hasRecurrenceTable;
	}

	/**
	 * Make native ActionComm recurrence edits visible to DAV clients.
	 *
	 * @param int $eventId Native event identifier
	 * @param string $calendarData Generated calendar data
	 * @param object|null $bookingRow Preloaded native booking row, enabling strict errors
	 * @return string Calendar with authoritative native recurrence fields
	 */
	private function applyNativeRecurrence($eventId, $calendarData, $bookingRow = null)
	{
		if ($bookingRow !== null) {
			$row = $bookingRow;
			$projectionOwned = !empty($row->booking_recurrence_projected);
		} else {
			$result = $this->db->query('SELECT recurid, recurrule, recurdateend FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $eventId).' AND entity IN ('.getEntity('agenda').')');
			$row = $result ? $this->db->fetch_object($result) : null;
			$projectionOwned = false;
			if ($this->recurrenceTableAvailable()) {
				// NULL hashes belong to sidecar-only RFC rules, not native projections.
				$tracking = $this->db->query('SELECT rfc_rrule_hash FROM '.MAIN_DB_PREFIX.'cdav_recurrence WHERE fk_actioncomm = '.((int) $eventId));
				$projection = $tracking ? $this->db->fetch_object($tracking) : null;
				$projectionOwned = $projection && (string) ($projection->rfc_rrule_hash ?? '') !== '';
			}
		}
		if (!$row || (!$projectionOwned && empty($row->recurrule))) {
			return $calendarData;
		}
		try {
			$calendar = \Sabre\VObject\Reader::read($calendarData);
			$component = null;
			foreach ($calendar->getComponents() as $candidate) {
				if (in_array($candidate->name, array('VEVENT', 'VTODO'), true)) {
					$component = $candidate;
					break;
				}
			}
			if ($component === null) return $calendarData;
			if ($projectionOwned) {
				$component->remove('RRULE');
			}
			$rule = (string) ($row->recurrule ?? '');
			$until = empty($row->recurdateend) ? 0 : strtotime((string) $row->recurdateend);
			if ($rule === '' || ($until <= 0 && $bookingRow === null)) return $calendar->serialize();
			$rfcRule = '';
			if ($rule === 'FREQ=DAILY') {
				$rfcRule = 'FREQ=DAILY';
			} elseif (preg_match('/^FREQ=WEEKLY_BYDAY([0-6])$/', $rule, $matches)) {
				$days = array('SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA');
				$rfcRule = 'FREQ=WEEKLY;BYDAY='.$days[(int) $matches[1]];
			} elseif (preg_match('/^FREQ=MONTHLY_BYMONTHDAY(\d{1,2})$/', $rule, $matches)) {
				$rfcRule = 'FREQ=MONTHLY;BYMONTHDAY='.((int) $matches[1]);
			} elseif (preg_match('/^FREQ=YEARLY_BYYEARMONTHDAY(\d{3,4})$/', $rule, $matches)) {
				$value = str_pad($matches[1], 4, '0', STR_PAD_LEFT);
				$rfcRule = 'FREQ=YEARLY;BYMONTH='.((int) substr($value, 0, 2)).';BYMONTHDAY='.((int) substr($value, 2, 2));
			}
			if ($rfcRule !== '') {
				if (!$projectionOwned) $component->remove('RRULE');
				$component->add('RRULE', $rfcRule.($until > 0 ? ';UNTIL='.gmdate('Ymd\THis\Z', $until) : ''));
			} elseif ($bookingRow !== null) {
				throw new \RuntimeException('Unsupported native booking recurrence');
			}
			return $calendar->serialize();
		} catch (\Throwable $e) {
			if ($bookingRow !== null) throw $e;
			dol_syslog(__METHOD__.': unable to export native recurrence: '.$e->getMessage(), LOG_ERR);
			return $calendarData;
		} finally {
			if (isset($calendar)) $calendar->destroy();
		}
	}

	/** Make native browser reminders authoritative for the representable subset. */
	private function addNativeReminders($eventId, $calendarId, $calendarData)
	{
		if (!getDolGlobalInt('CDAV_NATIVE_REMINDERS') || !getDolGlobalString('AGENDA_REMINDER_BROWSER')) {
			return $calendarData;
		}
		$sql = 'SELECT offsetvalue, offsetunit FROM '.MAIN_DB_PREFIX.'actioncomm_reminder'
			.' WHERE fk_actioncomm = '.((int) $eventId).' AND fk_user = '.((int) $calendarId)
			." AND typeremind = 'browser' AND status = 0 ORDER BY dateremind, rowid";
		$result = $this->db->query($sql);
		if (!$result) return $calendarData;
		$reminders = array();
		while ($row = $this->db->fetch_object($result)) {
			$unit = (string) $row->offsetunit;
			$value = (int) $row->offsetvalue;
			if ($value > 0 && isset(array('w' => 1, 'd' => 1, 'h' => 1, 'i' => 1)[$unit])) {
				$reminders[$value.$unit] = array($value, $unit);
			}
			if (count($reminders) >= 10) break;
		}
		try {
			$calendar = \Sabre\VObject\Reader::read($calendarData);
			$component = null;
			foreach ($calendar->getComponents() as $candidate) {
				if (in_array($candidate->name, array('VEVENT', 'VTODO'), true)) {
					$component = $candidate;
					break;
				}
			}
			if ($component === null) return $calendarData;
			foreach ($component->getComponents() as $alarm) {
				if ($alarm->name === 'VALARM' && isset($alarm->TRIGGER)
					&& strtoupper((string) ($alarm->ACTION ?? '')) === 'DISPLAY'
					&& strtoupper((string) ($alarm->TRIGGER['RELATED'] ?? 'START')) === 'START'
					&& strtoupper((string) ($alarm->TRIGGER['VALUE'] ?? 'DURATION')) !== 'DATE-TIME'
					&& substr((string) $alarm->TRIGGER, 0, 1) === '-' && !isset($alarm->REPEAT) && !isset($alarm->DURATION)) {
					$component->remove($alarm);
				}
			}
			foreach ($reminders as [$value, $unit]) {
				$duration = $unit === 'w' ? '-P'.$value.'W'
					: ($unit === 'd' ? '-P'.$value.'D' : '-PT'.$value.($unit === 'h' ? 'H' : 'M'));
				$alarm = $calendar->createComponent('VALARM', array(
					'ACTION' => 'DISPLAY',
					'DESCRIPTION' => isset($component->SUMMARY) ? (string) $component->SUMMARY : 'Dolibarr reminder',
					'TRIGGER' => $duration,
				));
				$component->add($alarm);
			}
			return $calendar->serialize();
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.': unable to export native reminders: '.$e->getMessage(), LOG_ERR);
			return $calendarData;
		} finally {
			if (isset($calendar)) $calendar->destroy();
		}
	}

	/**
	 * Reapply RFC properties that have no native ActionComm field.  Dolibarr
	 * remains authoritative for the title, dates, notes and free/busy value.
	 *
	 * @param int $eventId Native event identifier
	 * @param string $calendarData Generated calendar data
	 * @param object|null $bookingRow Preloaded native booking row, enabling strict errors
	 * @return string Calendar with preserved iCalendar metadata
	 */
	private function mergeCalendarMetadata($eventId, $calendarData, $bookingRow = null)
	{
		if (!$this->schedulingTableAvailable()) {
			return $calendarData;
		}
		if ($bookingRow !== null) {
			if (empty($bookingRow->booking_calendardata)) return $calendarData;
			$row = (object) array('calendardata' => $bookingRow->booking_calendardata);
		} else {
			$result = $this->db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling
				WHERE fk_actioncomm = '.((int) $eventId));
			if (!$result || !($row = $this->db->fetch_object($result)) || empty($row->calendardata)) {
				return $calendarData;
			}
		}

		try {
			$generated = \Sabre\VObject\Reader::read($calendarData);
			$stored = \Sabre\VObject\Reader::read($row->calendardata);
			$target = null;
			$source = null;
			foreach ($generated->getComponents() as $component) {
				if (in_array($component->name, array('VEVENT', 'VTODO'), true)) {
					$target = $component;
					break;
				}
			}
			foreach ($stored->getComponents() as $component) {
				if ($target !== null && $component->name === $target->name) {
					$source = $component;
					break;
				}
			}
			if ($target === null || $source === null) {
				if ($bookingRow !== null) throw new \RuntimeException('Invalid booking calendar metadata');
				return $calendarData;
			}

			$propertyNames = array(
				'ORGANIZER', 'ATTENDEE', 'RRULE', 'RDATE', 'EXDATE',
				'RECURRENCE-ID', 'SEQUENCE', 'ATTACH',
			);
			foreach ($propertyNames as $propertyName) {
				$target->remove($propertyName);
				foreach ($source->select($propertyName) as $property) {
					$target->add(clone $property);
				}
			}
			if (isset($source->STATUS)) {
				$target->remove('STATUS');
				foreach ($source->select('STATUS') as $property) {
					$target->add(clone $property);
				}
			}
			$target->remove('VALARM');
			foreach ($source->getComponents() as $component) {
				if ($component->name === 'VALARM') {
					$target->add(clone $component);
				}
			}

			$seenMaster = false;
			foreach ($stored->getComponents() as $component) {
				if ($component->name === 'VTIMEZONE') {
					$generated->add(clone $component);
					continue;
				}
				if ($component->name === $target->name) {
					if (!$seenMaster) {
						$seenMaster = true;
						continue;
					}
					// RECURRENCE-ID exceptions are independent components and must
					// survive the Dolibarr round-trip unchanged.
					$generated->add(clone $component);
				}
			}

			return $generated->serialize();
		} catch (\Throwable $e) {
			if ($bookingRow !== null) throw $e;
			if (function_exists('debug_log')) {
				debug_log('Unable to restore CalDAV metadata for event '.$eventId.': '.$e->getMessage());
			}
			return $calendarData;
		} finally {
			if (isset($generated)) $generated->destroy();
			if (isset($stored)) $stored->destroy();
		}
	}

	/**
	 * Add native Dolibarr appointment documents to the iCalendar master item.
	 *
	 * External links use the Link API. Physical files use the protected DAV
	 * download endpoint for CalDAV, or native document.php for ICS consumers.
	 * Existing ATTACH values are kept and deduplicated.
	 */
	private function addActionCommAttachments($eventId, $calendarData)
	{
		global $conf;

		try {
			$calendar = \Sabre\VObject\Reader::read($calendarData);
			$component = null;
			foreach ($calendar->getComponents() as $candidate) {
				if (in_array($candidate->name, array('VEVENT', 'VTODO'), true)) {
					$component = $candidate;
					break;
				}
			}
			if ($component === null) {
				return $calendarData;
			}

			$seen = array();
			foreach ($component->select('ATTACH') as $attachment) {
				$nativeDavPrefix = rtrim(dol_buildpath('/cdav/server.php/agenda-documents/'.rawurlencode((string) $eventId), 3), '/').'/';
				if (!isset($attachment['MANAGED-ID']) && str_starts_with(trim((string) $attachment), $nativeDavPrefix)) {
					// Native documents are reconstructed below. A client may echo this
					// URI back into stored metadata before the native file is deleted.
					$component->remove($attachment);
					continue;
				}
				if (isset($attachment['MANAGED-ID']) && preg_match('/^[a-f0-9]{64}$/D', (string) $attachment['MANAGED-ID'])
					&& $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_managed_attachment')) {
					$result = $this->db->query('SELECT filename FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment'
						." WHERE managed_id = '".$this->db->escape((string) $attachment['MANAGED-ID'])."'"
						.' AND entity = '.((int) $conf->entity).' AND fk_actioncomm = '.((int) $eventId));
					$managed = $result ? $this->db->fetch_object($result) : null;
					$root = $conf->agenda->multidir_output[(int) $conf->entity] ?? ($conf->agenda->dir_output ?? '');
					$managedPath = $managed && basename($managed->filename) === $managed->filename
						? $root.'/'.dol_sanitizeFileName((string) $eventId).'/'.$managed->filename : '';
					if ($managedPath === '' || !is_file($managedPath) || is_link($managedPath) || is_link(dirname($managedPath))) {
						$component->remove($attachment);
						continue;
					}
					$attachment['SIZE'] = (string) filesize($managedPath);
					$attachment['X-CDAV-CONTENT-HASH'] = hash_file('sha256', $managedPath);
				}
				if ($this->davDocumentUrls && !isset($attachment['MANAGED-ID'])) {
					$urlParts = parse_url(trim((string) $attachment));
					$nativeParts = parse_url(dol_buildpath('document.php', 3));
					if (is_array($urlParts) && is_array($nativeParts)
						&& ($urlParts['host'] ?? '') === ($nativeParts['host'] ?? '')
						&& ($urlParts['path'] ?? '') === ($nativeParts['path'] ?? '')) {
						parse_str($urlParts['query'] ?? '', $query);
						if (($query['modulepart'] ?? '') === 'actions' && is_string($query['file'] ?? null)
							&& str_starts_with($query['file'], $eventId.'/')) $component->remove($attachment);
					}
				}
				$seen[trim((string) $attachment)] = true;
			}

			require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
			$links = array();
			$linkReader = new \Link($this->db);
			if ($linkReader->fetchAll($links, 'action', (int) $eventId) < 0) {
				throw new \RuntimeException('Unable to read native appointment links');
			}
			foreach ($links as $link) {
				$url = trim((string) $link->url);
				$parts = parse_url($url);
				if ($url === '' || isset($seen[$url]) || !is_array($parts)
					|| strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
					|| empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
					continue;
				}
				$params = array('VALUE' => 'URI');
				$label = trim((string) $link->label);
				if ($label !== '') {
					$params['FILENAME'] = $label;
				}
				$path = (string) ($parts['path'] ?? '');
				if ($path !== '') {
					$params['FMTTYPE'] = dol_mimetype($path);
				}
				$component->add('ATTACH', $url, $params);
				$seen[$url] = true;
			}

			$agendaOutput = !empty($conf->agenda->multidir_output[(int) $conf->entity])
				? $conf->agenda->multidir_output[(int) $conf->entity]
				: ($conf->agenda->dir_output ?? '');
			$documentBase = dol_buildpath('document.php', 3);
			if ($agendaOutput !== '' && ($this->davDocumentUrls || str_starts_with(strtolower($documentBase), 'https://'))) {
				$directory = $agendaOutput.'/'.dol_sanitizeFileName((string) $eventId);
				$managedFiles = array();
				if ($this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_managed_attachment')) {
					$managedResult = $this->db->query('SELECT filename FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment'
						.' WHERE entity = '.((int) $conf->entity).' AND fk_actioncomm = '.((int) $eventId));
					if ($managedResult) while ($managed = $this->db->fetch_object($managedResult)) $managedFiles[(string) $managed->filename] = true;
				}
				foreach (dol_dir_list($directory, 'files', 0, '', '(\.meta|_preview.*\.png)$') as $file) {
					$filename = basename((string) $file['name']);
					if (isset($managedFiles[$filename])) continue;
					if ($filename === '' || $filename[0] === '.' || is_link($directory) || is_link($directory.'/'.$filename)) continue;
					$url = $this->davDocumentUrls
						? dol_buildpath('/cdav/server.php/agenda-documents/'.rawurlencode((string) $eventId).'/'.rawurlencode($filename), 3)
						: $documentBase.'?modulepart=actions&attachment=1&file='
							.urlencode($eventId.'/'.$filename).'&entity='.((int) $conf->entity);
					if (isset($seen[$url])) {
						continue;
					}
					$component->add('ATTACH', $url, array(
						'VALUE' => 'URI',
						'FILENAME' => $filename,
						'FMTTYPE' => dol_mimetype($filename),
						'SIZE' => (string) filesize($directory.'/'.$filename),
						'X-CDAV-CONTENT-HASH' => hash_file('sha256', $directory.'/'.$filename),
					));
					$seen[$url] = true;
				}
			}

			return $calendar->serialize();
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.': unable to export appointment attachments: '.$e->getMessage(), LOG_ERR);
			return $calendarData;
		} finally {
			if (isset($calendar)) $calendar->destroy();
		}
	}

	/** Return a stable digest of native links/files for collection change tags. */
	private function getActionCommAttachmentTag($eventId)
	{
		global $conf;

		$tokens = array();
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
		$links = array();
		$linkReader = new \Link($this->db);
		if ($linkReader->fetchAll($links, 'action', (int) $eventId) >= 0) {
			foreach ($links as $link) {
				$tokens[] = 'link:'.((int) $link->id).':'.((string) $link->url).':'.((string) $link->label);
			}
		}
		$agendaOutput = !empty($conf->agenda->multidir_output[(int) $conf->entity])
			? $conf->agenda->multidir_output[(int) $conf->entity]
			: ($conf->agenda->dir_output ?? '');
		if ($agendaOutput !== '') {
			$directory = $agendaOutput.'/'.dol_sanitizeFileName((string) $eventId);
			foreach (dol_dir_list($directory, 'files', 0, '', '(\.meta|_preview.*\.png)$') as $file) {
				$path = $directory.'/'.basename((string) $file['name']);
				if (!is_file($path) || is_link($path) || is_link($directory)) continue;
				$tokens[] = 'file:'.basename((string) $file['name']).':'.hash_file('sha256', $path);
			}
		}
		if ($this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_reminder')) {
			$result = $this->db->query('SELECT r.rowid, r.dateremind, r.typeremind, r.offsetvalue, r.offsetunit, r.status'
				.' FROM '.MAIN_DB_PREFIX.'cdav_reminder cr'
				.' INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_reminder r ON r.rowid = cr.fk_reminder'
				.' WHERE cr.fk_actioncomm = '.((int) $eventId));
			if ($result) {
				while ($reminder = $this->db->fetch_object($result)) {
					$tokens[] = 'reminder:'.implode(':', array(
						(int) $reminder->rowid,
						(string) $reminder->dateremind,
						(string) $reminder->typeremind,
						(int) $reminder->offsetvalue,
						(string) $reminder->offsetunit,
						(int) $reminder->status,
					));
				}
			}
		}
		sort($tokens, SORT_STRING);
		return sha1(implode('|', $tokens));
	}

	/**
	 * Base sql request for calendar events
	 *
	 * @param int $calid Calendar user id
	 * @param int|false $oid Optional ActionComm id
	 * @param string|false $ouri Optional external resource name
	 * @param array|null $bookingRange Explicit timestamp range for strict booking reads
	 * @return string Native calendar selection SQL
	 */
	public function getSqlCalEvents($calid, $oid = false, $ouri = false, ?array $bookingRange = null)
	{
		// TODO : replace GROUP_CONCAT by
		$hasSchedulingMetadata = $this->schedulingTableAvailable();
		$lastUpdatedSql = $hasSchedulingMetadata ? 'GREATEST(a.tms, COALESCE(cds.tms, a.tms))' : 'a.tms';
		$bookingFields = '';
		if ($bookingRange !== null) {
			if (count($bookingRange) !== 2 || !is_int($bookingRange[0]) || !is_int($bookingRange[1])
				|| $bookingRange[0] <= 0 || $bookingRange[1] <= $bookingRange[0] || $bookingRange[1] - $bookingRange[0] > 93 * 86400) {
				throw new \InvalidArgumentException('Invalid booking calendar range');
			}
			$bookingFields = ($hasSchedulingMetadata ? 'cds.calendardata' : 'NULL').' AS booking_calendardata, '
				.($this->recurrenceTableAvailable() ? '(SELECT 1 FROM '.MAIN_DB_PREFIX.'cdav_recurrence bcr WHERE bcr.fk_actioncomm = a.id AND COALESCE(bcr.rfc_rrule_hash,\'\') <> \'\')' : 'NULL')
				.' AS booking_recurrence_projected, ';
		}
		$sql = 'SELECT
					"ev" elem_source,
					'.$lastUpdatedSql.' AS lastupd,
						a.*, '.$bookingFields.'
					sp.firstname,
					sp.lastname,
					sp.address,
					sp.zip,
					sp.town,
					co.label country_label,
					sp.phone,
					sp.phone_perso,
					sp.phone_mobile,
					sp.email AS contact_email,
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
					s.email soc_email,
					p.ref proj_ref,
					p.title proj_title,
					p.description proj_desc,
					p.rowid AS cdav_project_id,
					ac.sourceuid,
					ac.uuidext,
					(SELECT COUNT(*)
						FROM '.MAIN_DB_PREFIX.'actioncomm_cdav acdup
						INNER JOIN '.MAIN_DB_PREFIX.'actioncomm adup ON adup.id = acdup.fk_object
						WHERE acdup.uuidext = ac.uuidext) AS uuidext_count,
					arcal.transparency AS calendar_transparency,
					arcal.answer_status AS calendar_answer_status,
					(SELECT GROUP_CONCAT(u.login) FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar
						LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user AS u ON (u.rowid=fk_element)
						WHERE ar.element_type=\'user\' AND fk_actioncomm=a.id) AS other_users
				FROM '.MAIN_DB_PREFIX.'actioncomm AS a';
		if ($bookingRange !== null) {
			// Reuse the native joins and authorization predicates below, but never
			// buffer unrelated notes, project descriptions or contact/company data.
			$sql = 'SELECT "ev" AS elem_source, '.$lastUpdatedSql.' AS lastupd, a.id, a.entity, a.datec, a.datep, a.datep2, a.percent, a.fulldayevent, a.recurid, a.recurrule, a.recurdateend, a.transparency, ';
			$sql .= $bookingFields.'ac.sourceuid, arcal.transparency AS calendar_transparency, ';
			$sql .= 'NULL AS uuidext, 0 AS uuidext_count, NULL AS calendar_answer_status, ';
			$sql .= 'NULL AS label, NULL AS location, NULL AS note, 0 AS priority, ';
			$sql .= 'NULL AS firstname, NULL AS lastname, NULL AS address, NULL AS zip, NULL AS town, ';
			$sql .= 'NULL AS country_label, NULL AS phone, NULL AS phone_perso, NULL AS phone_mobile, ';
			$sql .= 'NULL AS soc_nom, NULL AS soc_address, NULL AS soc_zip, NULL AS soc_town, ';
			$sql .= 'NULL AS soc_country_label, NULL AS soc_phone, NULL AS proj_ref, NULL AS proj_title, ';
			$sql .= 'NULL AS proj_desc, NULL AS other_users FROM '.MAIN_DB_PREFIX.'actioncomm AS a';
		}
		if (!$this->hasRight('societe', 'client', 'voir'))
		{
			$sql.=' LEFT OUTER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux AS sc ON (a.fk_soc = sc.fk_soc AND sc.fk_user='.$this->user->id.')
					LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = sc.fk_soc)
					LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.fk_soc = sc.fk_soc AND sp.rowid = a.fk_contact)
					LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav AS ac ON (a.id = ac.fk_object)';
		}
		else
		{
			$sql.=' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = a.fk_soc)
					LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid = a.fk_contact)
					LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav AS ac ON (a.id = ac.fk_object)';
		}
		if ($hasSchedulingMetadata) {
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'cdav_scheduling AS cds ON cds.fk_actioncomm = a.id';
		}

		$sql.=' INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_resources AS arcal
					ON (arcal.fk_actioncomm = a.id AND arcal.element_type = \'user\' AND arcal.fk_element = '.intval($calid).')
					LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = a.fk_project AND p.entity IN ('.getEntity('project').'))
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = sp.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				WHERE 	a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>\'systemauto\')
						AND a.entity IN ('.getEntity('agenda').')';
		if ($bookingRange !== null) {
			$range = '(COALESCE(a.datep2,a.datep) >= \''.$this->db->idate($bookingRange[0]).'\' AND a.datep < \''.$this->db->idate($bookingRange[1]).'\')';
			$range = '('.$range.' OR COALESCE(a.recurrule,\'\') <> \'\'';
			$occupation = 'arcal.transparency > 0';
			if ($hasSchedulingMetadata) {
				$range .= ' OR UPPER(cds.calendardata) LIKE \'%RRULE:%\' OR UPPER(cds.calendardata) LIKE \'%RDATE%\' OR UPPER(cds.calendardata) LIKE \'%RECURRENCE-ID%\'';
				// A transparent master can contain an opaque exception, and an
				// exception may move into the range from a master outside it.
				$occupation .= ' OR UPPER(cds.calendardata) LIKE \'%RECURRENCE-ID%\'';
			}
			$sql .= ' AND '.$range.') AND a.percent = -1 AND ('.$occupation.')';
		} elseif ($oid !== false) {
			if($ouri===false)
			{
				$sql.=' AND a.id = '.intval($oid);
			}
			else
			{
				$sql.=' AND (a.id = '.intval($oid).' OR ac.uuidext = \''.$this->db->escape($ouri).'\' OR ac.sourceuid = \''.$this->db->escape($ouri).'\')';
			}
		}
		else
		{
			$range = '(COALESCE(a.datep2,a.datep)>="'.date('Y-m-d 00:00:00',time()-86400*CDAV_SYNC_PAST).'"
					AND a.datep<="'.date('Y-m-d 23:59:59',time()+86400*CDAV_SYNC_FUTURE).'")';
			if ($hasSchedulingMetadata) {
				$range = '('.$range.' OR EXISTS (
					SELECT 1 FROM '.MAIN_DB_PREFIX.'cdav_scheduling cds
					WHERE cds.fk_actioncomm = a.id
					AND (cds.calendardata LIKE \'%RRULE:%\' OR cds.calendardata LIKE \'%RDATE:%\')
				))';
			}
			$sql .= ' AND '.$range;
		}

		return $sql;

	}

	/**
	 * Booking reads the same native/calendar metadata as DAV, with a bounded
	 * explicit range and no document/reminder I/O or tolerant parser fallback.
	 *
	 * @param int $calendarId Owner user id
	 * @param int $start Requested start timestamp
	 * @param int $end Requested exclusive end timestamp
	 * @return string[] Native iCalendar resources for availability calculation
	 */
	public function getBookingCalendarData($calendarId, $start, $end)
	{
		if (!$this->hasRight('agenda', 'myactions', 'read')
			|| ((int) $calendarId !== (int) $this->user->id && !$this->hasRight('agenda', 'allactions', 'read'))) {
			throw new \RuntimeException('Booking calendar is not readable');
		}
		$sql = $this->getSqlCalEvents((int) $calendarId, false, false, array((int) $start, (int) $end));
		// Bound every variable payload before mysqli buffers the selected rows.
		// All remaining projected columns are fixed-size dates/numbers or NULL.
		// The native joins are shared with DAV, but descriptive columns are omitted.
		// This read happens after the booking service acquires the owner lock.
		$inspectionSql = 'SELECT COUNT(*) AS nb, COALESCE(SUM(OCTET_LENGTH(COALESCE(booking_calendardata,\'\')) + OCTET_LENGTH(COALESCE(sourceuid,\'\'))';
		$inspectionSql .= ' + OCTET_LENGTH(COALESCE(recurid,\'\')) + OCTET_LENGTH(COALESCE(recurrule,\'\'))),0) AS bytes FROM ('.$sql.') booking_scope';
		$inspection = $this->db->query($inspectionSql);
		$size = $inspection ? $this->db->fetch_object($inspection) : null;
		if (!$size) throw new \RuntimeException('Unable to inspect booking calendar size');
		if ((int) $size->nb > 5000 || (int) $size->bytes > 8388608) throw new \RuntimeException('Booking calendar size limit exceeded');
		$result = $this->db->query($sql.$this->db->plimit(5001));
		if (!$result) throw new \RuntimeException('Unable to read booking calendar');
		$calendars = array();
		while ($row = $this->db->fetch_object($result)) {
			if (count($calendars) >= 5000) throw new \RuntimeException('Booking calendar event limit exceeded');
			$eventStart = empty($row->datep) ? false : strtotime((string) $row->datep);
			$eventEnd = empty($row->datep2) ? false : strtotime((string) $row->datep2);
			if ($eventStart === false || (empty($row->fulldayevent) && ($eventEnd === false || $eventEnd <= $eventStart))) {
				throw new \RuntimeException('Occupied booking event has invalid dates');
			}
			$calendars[] = $this->toVCalendar((int) $calendarId, $row, true, true);
		}
		return $calendars;
	}

	/**
	 * Build a collection tag that changes on additions, updates, removals,
	 * assignment changes, native files/links and DAV-owned reminders.  The sync
	 * store reconciles this native state into its own bounded tombstone journal.
	 *
	 * @param int $calendarId Calendar owner user id
	 * @return string
	 */
	public function getCalendarCollectionTag($calendarId)
	{
		$tokens = array();
		$queries = array(
			'ev' => $this->getSqlCalEvents($calendarId),
			'pe' => $this->getSqlProjectTasks($calendarId, false, 'pe'),
			'pt' => $this->getSqlProjectTasks($calendarId, false, 'pt'),
			'fi' => $this->getSqlIntervEvents($calendarId),
		);

		foreach ($queries as $source => $sql) {
			if (empty($sql)) {
				continue;
			}
			$result = $this->db->query($sql);
			if (!$result) {
				continue;
			}
			while ($obj = $this->db->fetch_object($result)) {
				$attachmentTag = $source === 'ev' ? ':'.$this->getActionCommAttachmentTag((int) $obj->id) : '';
				$tokens[] = $source.':'.((int) $obj->id).':'.((string) $obj->lastupd).$attachmentTag.':project-read:'.(int) $this->canReadProjectDescription($obj);
			}
		}

		sort($tokens, SORT_STRING);
		return sha1(CDAV_URI_KEY.'|'.self::CALENDAR_SERIALIZATION_VERSION.'|'.$this->langs->getDefaultLang().'|'.implode('|', $tokens));
	}
	/**
	 * Base sql request for project tasks
	 *
	 * @param int calendar user id
	 * @param int task object id
	 * @param string elem_source 'pt'=Project TODO  'pe'=Project EVENT
	 * @return string
	 */
	public function getSqlProjectTasks($calid, $oid = false, $elem_source = 'pt')
	{
		global $conf;

		if (!isModEnabled('project') || !$this->hasRight('projet', 'lire') || getDolGlobalInt('PROJECT_HIDE_TASKS')) {
			return false;
		}

		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==1 && $elem_source=='pt'))
			return false;

		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==2 && $elem_source=='pe'))
			return false;
		$projectIds = $this->nativeReadableProjectIds();
		if (!$projectIds) return false;

		// TODO : replace GROUP_CONCAT by
		$sql = 'SELECT
					"'.$elem_source.'" elem_source,
					pt.rowid AS id,
					pt.tms AS lastupd,
					pt.*,
					p.ref proj_ref,
					p.title proj_title,
					p.description proj_desc,
					p.rowid AS cdav_project_id,
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
					s.email soc_email,
					(SELECT GROUP_CONCAT(u.login) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="project_task" AND gtc.source="internal")
						LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user AS u ON (u.rowid=gec.fk_socpeople)
						WHERE gec.element_id=pt.rowid AND gtc.element="project_task" AND u.login IS NOT NULL) AS other_users,
					(SELECT GROUP_CONCAT(sp.firstname, " ", sp.lastname) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="project_task" AND gtc.source="external")
						LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid=gec.fk_socpeople)
						WHERE gec.element_id=pt.rowid AND gtc.element="project_task" AND sp.lastname IS NOT NULL) AS other_contacts
				FROM '.MAIN_DB_PREFIX.'projet_task AS pt
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = pt.fk_projet AND p.entity IN ('.getEntity('project').'))
				LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = p.fk_soc)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=pt.rowid)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON (tc.rowid=ec.fk_c_type_contact AND tc.element="project_task" AND tc.source="internal")
				WHERE tc.element="project_task" AND tc.source="internal" AND ec.fk_socpeople='.intval($calid).'
				AND pt.entity IN ('.getEntity('project').') AND p.rowid IN ('.implode(',', $projectIds).')';
		if($oid!==false)
		{
			$sql.=' AND pt.rowid = '.intval($oid);
		}
		else
		{
			$sql.='	AND COALESCE(pt.datee,pt.dateo)>="'.date('Y-m-d 00:00:00',time()-86400*CDAV_SYNC_PAST).'"
					AND pt.dateo<="'.date('Y-m-d 23:59:59',time()+86400*CDAV_SYNC_FUTURE).'"';
		}
		return $sql;

	}

	/**
	 * Base sql request for intervention cards (fichinter)
	 *
	 * @param int calendar user id
	 * @param int fichinter object id
	 * @return string
	 */
	public function getSqlIntervEvents($calid, $oid=false)
	{
		global $conf;

		if (!isModEnabled('intervention') || !$this->hasRight('ficheinter', 'lire')) {
			return false;
		}

		if(intval(CDAV_INTERV_SYNC)==0)
			return false;
		$interventionIds = $this->nativeReadableInterventionIds($calid);
		if (!$interventionIds) return false;

		$sql = 'SELECT
					"fi" elem_source,
					fid.rowid AS id,
					fi.rowid AS fi_id,
					fi.ref AS fi_ref,
					fi.description AS fi_description,
					fi.note_public AS fi_note_public,
					fi.datec AS datec,
					fi.tms AS lastupd,
					fid.date AS det_date,
					fid.duree AS det_duree,
					fid.description AS det_description,
					p.ref proj_ref,
					p.title proj_title,
					p.description proj_desc,
					p.rowid AS cdav_project_id,
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
					s.email soc_email,
					(SELECT GROUP_CONCAT(u.login) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="fichinter" AND gtc.source="internal")
						LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user AS u ON (u.rowid=gec.fk_socpeople)
						WHERE gec.element_id=fi.rowid AND gtc.element="fichinter" AND u.login IS NOT NULL) AS other_users,
					(SELECT GROUP_CONCAT(sp.firstname, " ", sp.lastname) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="fichinter" AND gtc.source="external")
						LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid=gec.fk_socpeople)
						WHERE gec.element_id=fi.rowid AND gtc.element="fichinter" AND sp.lastname IS NOT NULL) AS other_contacts
				FROM '.MAIN_DB_PREFIX.'fichinter AS fi
				INNER JOIN '.MAIN_DB_PREFIX.'fichinterdet AS fid ON fid.fk_fichinter = fi.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = fi.fk_projet AND p.entity IN ('.getEntity('project').'))
				LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = fi.fk_soc)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=fi.rowid)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=ec.fk_c_type_contact AND gtc.element="fichinter" AND gtc.source="internal")
				WHERE gtc.element="fichinter" AND gtc.source="internal" AND ec.fk_socpeople='.intval($calid).'
				AND fid.date IS NOT NULL
				AND fi.entity IN ('.getEntity('intervention').') AND fi.rowid IN ('.implode(',', $interventionIds).')';
		if($oid!==false)
		{
			$sql.=' AND fid.rowid = '.intval($oid);
		}
		else
		{
			$sql.='	AND fid.date>="'.date('Y-m-d 00:00:00',time()-86400*CDAV_SYNC_PAST).'"
					AND fid.date<="'.date('Y-m-d 23:59:59',time()+86400*CDAV_SYNC_FUTURE).'"';
		}
		return $sql;

	}

	/**
	 * Format a native postal address as a single calendar location.
	 *
	 * @param string $address Street lines
	 * @param string $zip Postal code
	 * @param string $town Town
	 * @param string $country Country label
	 * @return string Calendar location, empty when no street is present
	 */
	private function calendarAddress($address, $zip, $town, $country)
	{
		if (empty($address)) return '';
		$location = trim(str_replace(array("\r", "\t", "\n"), ' ', $address));
		$location = trim($location.', '.$zip);
		$location = trim($location.' '.$town);
		return trim($location.', '.$country);
	}

	/**
	 * Build the identical native timestamps before source-specific UID and fields.
	 * Sabre still performs final escaping, folding and complete resource serialization.
	 *
	 * @param object $row Selected native calendar row
	 * @param string $type VEVENT or VTODO
	 * @param bool $withHeader Whether to include the VCALENDAR envelope
	 * @return string Calendar/component prefix
	 */
	private function calendarComponentHeader($row, $type, $withHeader)
	{
		$header = $withHeader ? "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Dolibarr CDav//FR\n" : '';
		$header .= 'BEGIN:'.$type."\n";
		$header .= 'CREATED:'.gmdate('Ymd\THis', strtotime($row->datec))."Z\n";
		$modified = gmdate('Ymd\THis', strtotime($row->lastupd))."Z\n";
		return $header.'LAST-MODIFIED:'.$modified.'DTSTAMP:'.$modified;
	}

	/**
	 * Export a native calendar row using the existing DAV recurrence metadata.
	 *
	 * @param int $calid Calendar owner identifier
	 * @param object $obj Selected native event/task/intervention row
	 * @param bool $bHeader Whether to generate a complete VCALENDAR resource
	 * @param bool $booking Whether to require strict anonymous time-only export
	 * @return string Serialized iCalendar data
	 */
	public function toVCalendar($calid, $obj, $bHeader, $booking = false)
	{
	   $obj = $this->normalizeDatabaseRow($obj);
	   if($obj->elem_source=='ev')		// Calendar Event
	   {
			$categ = [];
			/*if($obj->soc_client)
			{
				$nick[] = $obj->soc_code_client;
				$categ[] = $this->langs->transnoentitiesnoconv('Customer');
			}*/

			$location=trim(str_replace(array("\r","\t","\n"),' ',$obj->location));

			// contact address
			if(empty($location) && !empty($obj->address))
			{
				$location = $this->calendarAddress($obj->address, $obj->zip, $obj->town, $obj->country_label);
			}

			// contact address
			if(empty($location) && !empty($obj->soc_address))
			{
				$location = $this->calendarAddress($obj->soc_address, $obj->soc_zip, $obj->soc_town, $obj->soc_country_label);
			}

			$address=explode("\n",$obj->address,2);
			foreach($address as $kAddr => $vAddr)
			{
				$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
			}
			$address[]='';
			$address[]='';

			if($obj->percent==-1 && trim($obj->datep)!='')
				$type='VEVENT';
			else
				$type='VTODO';

			$timezone = date_default_timezone_get();

			$caldata = $this->calendarComponentHeader($obj, $type, $bHeader);
			if($obj->sourceuid=='')
				$caldata.="UID:".$obj->id.'-ev-'./*$calid.'-cal-'.*/ CDAV_URI_KEY."\n";
			else
				$caldata.="UID:".$this->escapeICalendarText($obj->sourceuid)."\n";
			$caldata.="SUMMARY:".$this->cleanAndEscapeICalendarText($obj->label)."\n";
			$caldata.="URL:".dol_buildpath("/comm/action/card.php?id=".$obj->id, 2)."\n";
			$caldata.="LOCATION:".$this->cleanAndEscapeICalendarText($location)."\n";
			$caldata.="PRIORITY:".$obj->priority."\n";
			if($obj->fulldayevent)
			{
				$caldata.="DTSTART;VALUE=DATE:".date('Ymd', strtotime($obj->datep))."\n";
				if($type=='VEVENT')
				{
					if(trim($obj->datep2)>trim($obj->datep))
						$caldata.="DTEND;VALUE=DATE:".date('Ymd', strtotime($obj->datep2)+1)."\n";
					else
						$caldata.="DTEND;VALUE=DATE:".date('Ymd', strtotime($obj->datep)+(25*3600))."\n";
				}
				elseif(trim($obj->datep2)!='')
					$caldata.="DUE;VALUE=DATE:".date('Ymd', strtotime($obj->datep2)+1)."\n";
			}
			else
			{
				$caldata.="DTSTART;TZID=".$timezone.":".strtr($obj->datep,array(" "=>"T", ":"=>"", "-"=>""))."\n";
				if($type=='VEVENT')
				{
					if(trim($obj->datep2)>trim($obj->datep))
						$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>""))."\n";
					else
						$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datep,array(" "=>"T", ":"=>"", "-"=>""))."\n";
				}
				elseif(trim($obj->datep2)!='')
					$caldata.="DUE;TZID=".$timezone.":".strtr($obj->datep2,array(" "=>"T", ":"=>"", "-"=>""))."\n";
			}
			$caldata.="CLASS:PUBLIC\n";
				$calendarTransparency = isset($obj->calendar_transparency) ? (int) $obj->calendar_transparency : (int) $obj->transparency;
				if($calendarTransparency > 0)
					$caldata.="TRANSP:OPAQUE\n";
				else
					$caldata.="TRANSP:TRANSPARENT\n";

			if($type=='VEVENT')
				$caldata.="STATUS:CONFIRMED\n";
			elseif($obj->percent==0)
			{
				$caldata.="STATUS:NEEDS-ACTION\n";
				$caldata.="PERCENT-COMPLETE:0\n";
			}
			elseif($obj->percent==100)
			{
				$caldata.="STATUS:COMPLETED\n";
				$caldata.="PERCENT-COMPLETE:100\n";
			}
			else
			{
				$caldata.="STATUS:IN-PROCESS\n";
				$caldata.="PERCENT-COMPLETE:".$obj->percent."\n";
			}

			$description = '';
			if (!$booking) {
				$obj->_cdav_description = $this->getCalendarDescriptionParts($obj);
				$description = $obj->_cdav_description['text'];
			}
			$caldata.="DESCRIPTION:".$this->escapeICalendarText($description)."\n";

			$caldata.="END:".$type."\n";
			if($bHeader) {
				$caldata.="END:VCALENDAR\n";
				$caldata = $this->mergeCalendarMetadata((int) $obj->id, $caldata, $booking ? $obj : null);
				$caldata = $this->applyNativeRecurrence((int) $obj->id, $caldata, $booking ? $obj : null);
				if (!$booking) {
					$caldata = $this->addNativeReminders((int) $obj->id, (int) $calid, $caldata);
					$caldata = $this->addActionCommAttachments((int) $obj->id, $caldata);
				}
			}
		}
	   elseif(substr($obj->elem_source,0,1)=='p')		// Project Task  pe/pt
	   {
			if($obj->elem_source=='pe')
				$type='VEVENT';
			else 	// 'pt'
				$type='VTODO';

			$location = empty($obj->soc_address) ? '' : $this->calendarAddress($obj->soc_address, $obj->soc_zip, $obj->soc_town, $obj->soc_country_label);

			$timezone = date_default_timezone_get();

			$caldata = $this->calendarComponentHeader($obj, $type, $bHeader);
			$caldata.="UID:".$obj->id.'-'.$obj->elem_source.'-'./*$calid.'-cal-'.*/ CDAV_URI_KEY."\n";
			$summary = '['.$this->cleanDolibarrText($obj->proj_title).'] '.$this->cleanDolibarrText($obj->label);
			$caldata.="SUMMARY:".$this->escapeICalendarText(trim($summary))."\n";
			$caldata.="URL:".dol_buildpath("/projet/tasks/task.php?id=".$obj->id."&withproject=".$obj->fk_projet,2)."\n";
			$caldata.="LOCATION:".$this->cleanAndEscapeICalendarText($location)."\n";
			$caldata.="PRIORITY:".$obj->priority."\n";

			$caldata.="DTSTART;TZID=".$timezone.":".strtr($obj->dateo,array(" "=>"T", ":"=>"", "-"=>""))."\n";
			if($type=='VEVENT')
			{
				if(trim($obj->datee)>trim($obj->dateo))
					$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->datee,array(" "=>"T", ":"=>"", "-"=>""))."\n";
				else
					$caldata.="DTEND;TZID=".$timezone.":".strtr($obj->dateo,array(" "=>"T", ":"=>"", "-"=>""))."\n";
			}
			elseif(trim($obj->datee)!='')
				$caldata.="DUE;TZID=".$timezone.":".strtr($obj->datee,array(" "=>"T", ":"=>"", "-"=>""))."\n";

			$caldata.="CLASS:PUBLIC\n";
			$caldata.="TRANSP:OPAQUE\n";

			if($type=='VEVENT')
				$caldata.="STATUS:CONFIRMED\n";
			elseif($obj->progress==0)
			{
				$caldata.="STATUS:NEEDS-ACTION\n";
				$caldata.="PERCENT-COMPLETE:0\n";
			}
			elseif($obj->progress==100)
			{
				$caldata.="STATUS:COMPLETED\n";
				$caldata.="PERCENT-COMPLETE:100\n";
			}
			else
			{
				$caldata.="STATUS:IN-PROCESS\n";
				$caldata.="PERCENT-COMPLETE:".$obj->progress."\n";
			}

			$description = '';
			if (!$booking) {
				$obj->_cdav_description = $this->getCalendarDescriptionParts($obj);
				$description = $obj->_cdav_description['text'];
			}
			$caldata.="DESCRIPTION:".$this->escapeICalendarText($description)."\n";

			$caldata.="END:".$type."\n";
			if($bHeader)
				$caldata.="END:VCALENDAR\n";
		}
	   elseif($obj->elem_source=='fi')		// Intervention card line (fichinterdet)
	   {
			$type='VEVENT';

			$location = empty($obj->soc_address) ? '' : $this->calendarAddress($obj->soc_address, $obj->soc_zip, $obj->soc_town, $obj->soc_country_label);

			$timezone = date_default_timezone_get();

			$caldata = $this->calendarComponentHeader($obj, $type, $bHeader);
			$caldata.="UID:".$obj->id.'-fi-'.CDAV_URI_KEY."\n";
			$summary = trim($obj->fi_description);
			if($summary=='')
				$summary = trim($obj->soc_nom);
			$summary = '['.trim($obj->fi_ref).'] '.$summary;
			$caldata.="SUMMARY:".$this->cleanAndEscapeICalendarText($summary)."\n";
			$caldata.="URL:".dol_buildpath("/fichinter/card.php?id=".$obj->fi_id, 2)."\n";
			$caldata.="LOCATION:".$this->cleanAndEscapeICalendarText($location)."\n";

			// fichinterdet.date is DATETIME, fichinterdet.duree is in seconds
			$startTs = strtotime($obj->det_date);
			$endTs = $startTs + intval($obj->det_duree);
			if($endTs <= $startTs)
				$endTs = $startTs + 3600;
			$caldata.="DTSTART;TZID=".$timezone.":".date('Ymd\THis', $startTs)."\n";
			$caldata.="DTEND;TZID=".$timezone.":".date('Ymd\THis', $endTs)."\n";

			$caldata.="CLASS:PUBLIC\n";
			$caldata.="TRANSP:OPAQUE\n";
			$caldata.="STATUS:CONFIRMED\n";

			$description = '';
			if (!$booking) {
				$obj->_cdav_description = $this->getCalendarDescriptionParts($obj);
				$description = $obj->_cdav_description['text'];
			}
			$caldata.="DESCRIPTION:".$this->escapeICalendarText($description)."\n";

			$caldata.="END:".$type."\n";
			if($bHeader)
				$caldata.="END:VCALENDAR\n";
		}

		if ($bHeader && $caldata !== '') {
			try {
				// Let Sabre normalize CRLF, escape properties and fold long lines.
				$calendar = \Sabre\VObject\Reader::read($caldata);
				if ($booking) {
					require_once dirname(__DIR__).'/class/CDavFreeBusyFilter.php';
					$times = new \Sabre\VObject\Component\VCalendar();
					foreach ($calendar->getComponents() as $component) {
						if ($component->name === 'VTIMEZONE') {
							$times->add(\Dolibarr\CDav\FreeBusyFilter::timezoneComponent($component));
						} elseif ($component->name === 'VEVENT') {
							$times->add(\Dolibarr\CDav\FreeBusyFilter::calendarComponent($component, 'Busy', (string) $calid, CDAV_URI_KEY));
						}
					}
					$caldata = $times->serialize();
				} else {
					$caldata = $calendar->serialize();
				}
			} catch (\Throwable $e) {
				if ($booking) throw $e;
				dol_syslog(__METHOD__.': invalid generated calendar: '.$e->getMessage(), LOG_ERR);
			} finally {
				if (isset($times)) $times->destroy();
				if (isset($calendar)) $calendar->destroy();
			}
		}

		return $caldata;
	}

	/**
	 * Return the stable DAV resource name for a calendar row.
	 *
	 * Client-created resources keep their original URI.  Old recurring rows
	 * may share the same external URI; those must retain their unique internal
	 * URI to avoid duplicate WebDAV collection members.
	 *
	 * @param object $obj Calendar database row
	 * @param string $source Element source (ev, pe, pt or fi)
	 * @return string
	 */
	public function getCalendarObjectUri($obj, $source)
	{
		if ($source === 'ev' && !empty($obj->uuidext) && (int) ($obj->uuidext_count ?? 1) === 1) {
			return (string) $obj->uuidext;
		}
		return ((int) $obj->id).'-'.$source.'-'.CDAV_URI_KEY;
	}

	/**
	 * Export the visible native collection with an optional per-object observer.
	 * The observer receives the full GET representation without retaining a second list.
	 *
	 * @param int $calendarId Native calendar owner
	 * @param bool $bCalendarData Whether the returned list includes calendar bodies
	 * @param callable|null $onObject Optional observer receiving one full native record and its native source
	 * @return array Native collection records in their historical representation
	 */
	public function getFullCalendarObjects($calendarId, $bCalendarData, ?callable $onObject = null)
	{
		if(function_exists("debug_log"))
			debug_log("getCalendarObjects( $calendarId , $bCalendarData )");

		$calid = intval($calendarId);
		$calevents = [] ;
		$rSql = [] ;

		if (!$this->hasRight('agenda', 'myactions', 'read'))
			return $calevents;

		if ($calid != $this->user->id && !$this->hasRight('agenda', 'allactions', 'read'))
			return $calevents;

		$rSql['ev'] = $this->getSqlCalEvents($calid);
		$rSql['pe'] = $this->getSqlProjectTasks($calid, false, 'pe');
		$rSql['pt'] = $this->getSqlProjectTasks($calid, false, 'pt');
		$rSql['fi'] = $this->getSqlIntervEvents($calid);

		foreach($rSql as $elem_source => $sql)
		{
			if($sql=='')
				continue;
			$result = $this->db->query($sql);
			if (!$result) throw new \RuntimeException('Unable to read the native calendar collection.');

			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					// Use the exact same representation as getCalendarObject so ETags
					// stay stable between collection listings and individual GETs.
					$calendardata = $this->toVCalendar($calid, $obj, true);

					$record = array(
						'uri' => $this->getCalendarObjectUri($obj, $elem_source),
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($calendardata).'"',
						'calendarid' => $calendarId,
						'size' => strlen($calendardata),
						'component' => strpos($calendardata, 'BEGIN:VEVENT') !== false ? 'vevent' : 'vtodo',
					);
					if ($onObject !== null) {
						$fullRecord = array('id' => $obj->id, 'calendardata' => $calendardata) + $record;
						if (isset($obj->_cdav_description) && is_array($obj->_cdav_description)) $fullRecord['_cdav_description'] = $obj->_cdav_description;
						$onObject($fullRecord, $elem_source);
					}
					$calevents[] = $bCalendarData ? array('calendardata' => $calendardata) + $record : $record;
				}
			}
		}
		return $calevents;
	}

}
