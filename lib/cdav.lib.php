<?php

/**
 * Define Common function to access calendar items
 * And format it in vCalendar
 * */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';


class CdavLib
{
	/** Change this value whenever the generated DAV representation changes. */
	private const CALENDAR_SERIALIZATION_VERSION = '2026-09-native-sync-v3';

	private $db;

	private $user;

	private $langs;

	/** @var bool|null Whether the optional iCalendar metadata table exists. */
	private $hasSchedulingTable = null;

	/** @var bool|null Whether CalDAV recurrence projection metadata exists. */
	private $hasRecurrenceTable = null;

	function __construct($user, $db, $langs)
	{
		$this->user 	= $user;
		$this->db 		= $db;
		$this->langs 	= $langs;
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

	private function addDescriptionPart(array &$parts, $prefix, $value, $repeatPrefix = false)
	{
		$value = $this->cleanDolibarrText($value);
		if ($value === '') {
			return;
		}
		if ($repeatPrefix) {
			$value = str_replace("\n", "\n".$prefix, $value);
		}
		$parts[] = $prefix.$value;
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

	/** Make native ActionComm recurrence edits visible to DAV clients. */
	private function applyNativeRecurrence($eventId, $calendarData)
	{
		$result = $this->db->query('SELECT recurid, recurrule, recurdateend FROM '.MAIN_DB_PREFIX.'actioncomm'
			.' WHERE id = '.((int) $eventId).' AND entity IN ('.getEntity('agenda').')');
		$row = $result ? $this->db->fetch_object($result) : null;
		$projectionOwned = false;
		if ($this->recurrenceTableAvailable()) {
			$tracking = $this->db->query('SELECT fk_actioncomm FROM '.MAIN_DB_PREFIX.'cdav_recurrence'
				.' WHERE fk_actioncomm = '.((int) $eventId));
			$projectionOwned = $tracking && (bool) $this->db->fetch_object($tracking);
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
			if ($rule === '' || $until <= 0) return $calendar->serialize();
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
				$component->add('RRULE', $rfcRule.';UNTIL='.gmdate('Ymd\THis\Z', $until));
			}
			return $calendar->serialize();
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.': unable to export native recurrence: '.$e->getMessage(), LOG_ERR);
			return $calendarData;
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
		}
	}

	/**
	 * Reapply RFC properties that have no native ActionComm field.  Dolibarr
	 * remains authoritative for the title, dates, notes and free/busy value.
	 */
	private function mergeCalendarMetadata($eventId, $calendarData)
	{
		if (!$this->schedulingTableAvailable()) {
			return $calendarData;
		}
		$result = $this->db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling
			WHERE fk_actioncomm = '.((int) $eventId));
		if (!$result || !($row = $this->db->fetch_object($result)) || empty($row->calendardata)) {
			return $calendarData;
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
			if (function_exists('debug_log')) {
				debug_log('Unable to restore CalDAV metadata for event '.$eventId.': '.$e->getMessage());
			}
			return $calendarData;
		}
	}

	/**
	 * Add native Dolibarr appointment documents to the iCalendar master item.
	 *
	 * External links use the Link API. Physical files are exposed through
	 * Dolibarr document.php so its native access checks still run; no storage
	 * path is ever disclosed. Existing ATTACH values are kept and deduplicated.
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
			if ($agendaOutput !== '' && str_starts_with(strtolower($documentBase), 'https://')) {
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
					$url = $documentBase.'?modulepart=actions&attachment=1&file='
						.urlencode($eventId.'/'.$filename).'&entity='.((int) $conf->entity);
					if (isset($seen[$url])) {
						continue;
					}
					$component->add('ATTACH', $url, array(
						'VALUE' => 'URI',
						'FILENAME' => $filename,
						'FMTTYPE' => dol_mimetype($filename),
					));
					$seen[$url] = true;
				}
			}

			return $calendar->serialize();
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.': unable to export appointment attachments: '.$e->getMessage(), LOG_ERR);
			return $calendarData;
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
				$tokens[] = 'file:'.basename((string) $file['name']).':'.((int) ($file['size'] ?? 0)).':'.((int) ($file['date'] ?? 0));
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
	 * @param int calendar user id
	 * @param int actioncomm object id
	 * @return string
	 */
	public function getSqlCalEvents($calid, $oid=false, $ouri=false)
	{
		// TODO : replace GROUP_CONCAT by
		$hasSchedulingMetadata = $this->schedulingTableAvailable();
		$lastUpdatedSql = $hasSchedulingMetadata ? 'GREATEST(a.tms, COALESCE(cds.tms, a.tms))' : 'a.tms';
		$sql = 'SELECT
					"ev" elem_source,
					'.$lastUpdatedSql.' AS lastupd,
					a.*,
					sp.firstname,
					sp.lastname,
					sp.address,
					sp.zip,
					sp.town,
					co.label country_label,
					sp.phone,
					sp.phone_perso,
					sp.phone_mobile,
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
					p.ref proj_ref,
					p.title proj_title,
					p.description proj_desc,
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
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = a.fk_project)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = sp.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				WHERE 	a.code IN (SELECT cac.code FROM '.MAIN_DB_PREFIX.'c_actioncomm cac WHERE cac.type<>\'systemauto\')
						AND a.entity IN ('.getEntity('agenda').')';
		if($oid!==false) {
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
				$tokens[] = $source.':'.((int) $obj->id).':'.((string) $obj->lastupd).$attachmentTag;
			}
		}

		sort($tokens, SORT_STRING);
		return sha1(CDAV_URI_KEY.'|'.self::CALENDAR_SERIALIZATION_VERSION.'|'.implode('|', $tokens));
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

		if(!isModEnabled('project') || getDolGlobalInt('PROJECT_HIDE_TASKS'))
			return false;

		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==1 && $elem_source=='pt'))
			return false;

		if(intval(CDAV_TASK_SYNC)==0 || (intval(CDAV_TASK_SYNC)==2 && $elem_source=='pe'))
			return false;

		// TODO : replace GROUP_CONCAT by
		$sql = 'SELECT
					"'.$elem_source.'" elem_source,
					pt.rowid AS id,
					pt.tms AS lastupd,
					pt.*,
					p.ref proj_ref,
					p.title proj_title,
					p.description proj_desc,
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
					(SELECT GROUP_CONCAT(u.login) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="project_task" AND gtc.source="internal")
						LEFT OUTER JOIN '.MAIN_DB_PREFIX.'user AS u ON (u.rowid=gec.fk_socpeople)
						WHERE gec.element_id=pt.rowid AND gtc.element="project_task" AND u.login IS NOT NULL) AS other_users,
					(SELECT GROUP_CONCAT(sp.firstname, " ", sp.lastname) FROM '.MAIN_DB_PREFIX.'element_contact gec
						LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=gec.fk_c_type_contact AND gtc.element="project_task" AND gtc.source="external")
						LEFT JOIN '.MAIN_DB_PREFIX.'socpeople AS sp ON (sp.rowid=gec.fk_socpeople)
						WHERE gec.element_id=pt.rowid AND gtc.element="project_task" AND sp.lastname IS NOT NULL) AS other_contacts
				FROM '.MAIN_DB_PREFIX.'projet_task AS pt
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = pt.fk_projet)
				LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = p.fk_soc)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=pt.rowid)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON (tc.rowid=ec.fk_c_type_contact AND tc.element="project_task" AND tc.source="internal")
				WHERE tc.element="project_task" AND tc.source="internal" AND ec.fk_socpeople='.intval($calid).'
				AND pt.entity IN ('.getEntity('project').')';
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

		if(!isModEnabled('ficheinter'))
			return false;

		if(intval(CDAV_INTERV_SYNC)==0)
			return false;

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
					s.nom AS soc_nom,
					s.address soc_address,
					s.zip soc_zip,
					s.town soc_town,
					cos.label soc_country_label,
					s.phone soc_phone,
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
				LEFT JOIN '.MAIN_DB_PREFIX.'projet AS p ON (p.rowid = fi.fk_projet)
				LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON (s.rowid = fi.fk_soc)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON (ec.element_id=fi.rowid)
				LEFT JOIN '.MAIN_DB_PREFIX.'c_type_contact as gtc ON (gtc.rowid=ec.fk_c_type_contact AND gtc.element="fichinter" AND gtc.source="internal")
				WHERE gtc.element="fichinter" AND gtc.source="internal" AND ec.fk_socpeople='.intval($calid).'
				AND fid.date IS NOT NULL
				AND fi.entity IN ('.getEntity('intervention').')';
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
	 * Convert calendar row to VCalendar string
	 *
	 * @param row object
	 * @return string
	 */
	public function toVCalendar($calid, $obj, $bHeader)
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
				$location = trim(str_replace(array("\r","\t","\n"),' ', $obj->address));
				$location = trim($location.', '.$obj->zip);
				$location = trim($location.' '.$obj->town);
				$location = trim($location.', '.$obj->country_label);
			}

			// contact address
			if(empty($location) && !empty($obj->soc_address))
			{
				$location = trim(str_replace(array("\r","\t","\n"),' ', $obj->soc_address));
				$location = trim($location.', '.$obj->soc_zip);
				$location = trim($location.' '.$obj->soc_town);
				$location = trim($location.', '.$obj->soc_country_label);
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

			$caldata ="";
			if($bHeader)
			{
				$caldata ="BEGIN:VCALENDAR\n";
				$caldata.="VERSION:2.0\n";
				$caldata.="PRODID:-//Dolibarr CDav//FR\n";
			}
			$caldata.="BEGIN:".$type."\n";
			$caldata.="CREATED:".gmdate('Ymd\THis', strtotime($obj->datec))."Z\n";
			$caldata.="LAST-MODIFIED:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
			$caldata.="DTSTAMP:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
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

			$descriptionParts = array();
			if (!empty($obj->proj_ref))
				$this->addDescriptionPart($descriptionParts, '💼📋 ', '['.$obj->proj_ref.'] '.$obj->proj_title);
			if (!empty($obj->proj_desc))
				$this->addDescriptionPart($descriptionParts, '💼⚠️ ', $obj->proj_desc, true);
			if (!empty($obj->soc_town))
				$this->addDescriptionPart($descriptionParts, '💼🏁 ', $obj->soc_town);
			if (!empty($obj->soc_nom))
				$this->addDescriptionPart($descriptionParts, '💼🏢 ', $obj->soc_nom);
			if (!empty($obj->soc_phone))
				$this->addDescriptionPart($descriptionParts, '💼☎️ ', $obj->soc_phone);
			if (!empty($obj->firstname) || !empty($obj->lastname))
				$this->addDescriptionPart($descriptionParts, '💼👨 ', trim($obj->firstname.' '.$obj->lastname));
			if (!empty($obj->phone) || !empty($obj->phone_perso) || !empty($obj->phone_mobile))
				$this->addDescriptionPart($descriptionParts, '💼📞 ', trim($obj->phone.' '.$obj->phone_perso.' '.$obj->phone_mobile));
	// removed because unable to swap from one calendar to an other with extrenal client
	//		if(strpos($obj->other_users,',')) // several
	//			$this->addDescriptionPart($descriptionParts, '💼USR: ', $obj->other_users);
			$note = $type == 'VEVENT' ? $this->cleanDolibarrText($obj->note) : $this->formatChecklistText($obj->note);
			if ($note !== '')
				$descriptionParts[] = $note;
			$caldata.="DESCRIPTION:".$this->escapeICalendarText(implode("\n", $descriptionParts))."\n";

			$caldata.="END:".$type."\n";
			if($bHeader) {
				$caldata.="END:VCALENDAR\n";
					$caldata = $this->mergeCalendarMetadata((int) $obj->id, $caldata);
					$caldata = $this->applyNativeRecurrence((int) $obj->id, $caldata);
					$caldata = $this->addNativeReminders((int) $obj->id, (int) $calid, $caldata);
					$caldata = $this->addActionCommAttachments((int) $obj->id, $caldata);
			}
		}
	   elseif(substr($obj->elem_source,0,1)=='p')		// Project Task  pe/pt
	   {
			if($obj->elem_source=='pe')
				$type='VEVENT';
			else 	// 'pt'
				$type='VTODO';

			$location='';

			// soc address
			if(!empty($obj->soc_address))
			{
				$location = trim(str_replace(array("\r","\t","\n"),' ', $obj->soc_address));
				$location = trim($location.', '.$obj->soc_zip);
				$location = trim($location.' '.$obj->soc_town);
				$location = trim($location.', '.$obj->soc_country_label);
			}

			$timezone = date_default_timezone_get();

			$caldata ="";
			if($bHeader)
			{
				$caldata ="BEGIN:VCALENDAR\n";
				$caldata.="VERSION:2.0\n";
				$caldata.="PRODID:-//Dolibarr CDav//FR\n";
			}
			$caldata.="BEGIN:".$type."\n";
			$caldata.="CREATED:".gmdate('Ymd\THis', strtotime($obj->datec))."Z\n";
			$caldata.="LAST-MODIFIED:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
			$caldata.="DTSTAMP:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
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

			$descriptionParts = array();
			if(!empty($obj->proj_desc))
				$this->addDescriptionPart($descriptionParts, '💼⚠️ ', $obj->proj_desc, true);
			if(!empty($obj->soc_town))
				$this->addDescriptionPart($descriptionParts, '💼🏁 ', $obj->soc_town);
			if(!empty($obj->soc_nom))
				$this->addDescriptionPart($descriptionParts, '💼🏢 ', $obj->soc_nom);
			if(!empty($obj->soc_phone))
				$this->addDescriptionPart($descriptionParts, '💼☎️ ', $obj->soc_phone);
			if(!empty($obj->other_contacts))
				$this->addDescriptionPart($descriptionParts, '💼👨 ', $obj->other_contacts);
			if(!empty($obj->proj_ref))
				$this->addDescriptionPart($descriptionParts, '💼📋 ', '['.$obj->proj_ref.'/'.$obj->ref.'] '.$obj->proj_title);
			if(!empty($obj->note_public))
				$this->addDescriptionPart($descriptionParts, '💼📝 ', $obj->note_public, true);
	//removed because unable to swap from one calendar to an other with external client
	//		if(!empty($obj->note_private))
	//			$this->addDescriptionPart($descriptionParts, '💼🔒 ', $obj->note_private, true);
			$description = $this->formatChecklistText($obj->description);
			if ($description !== '')
				$descriptionParts[] = $description;
	// removed because unable to swap from one calendar to an other with external client
	//		if(strpos($obj->other_users,',')) // several
	//			$this->addDescriptionPart($descriptionParts, '💼USR: ', $obj->other_users);
			$caldata.="DESCRIPTION:".$this->escapeICalendarText(implode("\n", $descriptionParts))."\n";

			$caldata.="END:".$type."\n";
			if($bHeader)
				$caldata.="END:VCALENDAR\n";
		}
	   elseif($obj->elem_source=='fi')		// Intervention card line (fichinterdet)
	   {
			$type='VEVENT';

			$location='';

			// soc address
			if(!empty($obj->soc_address))
			{
				$location = trim(str_replace(array("\r","\t","\n"),' ', $obj->soc_address));
				$location = trim($location.', '.$obj->soc_zip);
				$location = trim($location.' '.$obj->soc_town);
				$location = trim($location.', '.$obj->soc_country_label);
			}

			$timezone = date_default_timezone_get();

			$caldata ="";
			if($bHeader)
			{
				$caldata ="BEGIN:VCALENDAR\n";
				$caldata.="VERSION:2.0\n";
				$caldata.="PRODID:-//Dolibarr CDav//FR\n";
			}
			$caldata.="BEGIN:".$type."\n";
			$caldata.="CREATED:".gmdate('Ymd\THis', strtotime($obj->datec))."Z\n";
			$caldata.="LAST-MODIFIED:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
			$caldata.="DTSTAMP:".gmdate('Ymd\THis', strtotime($obj->lastupd))."Z\n";
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

			$descriptionParts = array();
			if(!empty($obj->proj_ref))
				$this->addDescriptionPart($descriptionParts, '💼📋 ', '['.$obj->proj_ref.'] '.$obj->proj_title);
			if(!empty($obj->proj_desc))
				$this->addDescriptionPart($descriptionParts, '💼⚠️ ', $obj->proj_desc, true);
			if(!empty($obj->soc_nom))
				$this->addDescriptionPart($descriptionParts, '💼🏢 ', $obj->soc_nom);
			if(!empty($obj->soc_town))
				$this->addDescriptionPart($descriptionParts, '💼🏁 ', $obj->soc_town);
			if(!empty($obj->soc_phone))
				$this->addDescriptionPart($descriptionParts, '💼☎️ ', $obj->soc_phone);
			if(!empty($obj->other_contacts))
				$this->addDescriptionPart($descriptionParts, '💼👨 ', $obj->other_contacts);
			if(!empty($obj->fi_note_public))
				$this->addDescriptionPart($descriptionParts, '💼📝 ', $obj->fi_note_public, true);
			if(!empty($obj->det_description))
				$descriptionParts[] = $this->cleanDolibarrText($obj->det_description);
			$caldata.="DESCRIPTION:".$this->escapeICalendarText(implode("\n", $descriptionParts))."\n";

			$caldata.="END:".$type."\n";
			if($bHeader)
				$caldata.="END:VCALENDAR\n";
		}

		if ($bHeader && $caldata !== '') {
			try {
				// Let Sabre normalize CRLF, escape properties and fold long lines.
				$caldata = \Sabre\VObject\Reader::read($caldata)->serialize();
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__.': invalid generated calendar: '.$e->getMessage(), LOG_ERR);
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

	public function getFullCalendarObjects($calendarId, $bCalendarData)
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

			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					// Use the exact same representation as getCalendarObject so ETags
					// stay stable between collection listings and individual GETs.
					$calendardata = $this->toVCalendar($calid, $obj, true);

					if($bCalendarData)
					{
						$calevents[] = [
							'calendardata' => $calendardata,
							'uri' => $this->getCalendarObjectUri($obj, $elem_source),
							'lastmodified' => strtotime($obj->lastupd),
							'etag' => '"'.md5($calendardata).'"',
							'calendarid'   => $calendarId,
							'size' => strlen($calendardata),
							'component' => strpos($calendardata, 'BEGIN:VEVENT') !== false ? 'vevent' : 'vtodo',
						];
					}
					else
					{
						$calevents[] = [
							// 'calendardata' => $calendardata,  not necessary because etag+size are present
							'uri' => $this->getCalendarObjectUri($obj, $elem_source),
							'lastmodified' => strtotime($obj->lastupd),
							'etag' => '"'.md5($calendardata).'"',
							'calendarid'   => $calendarId,
							'size' => strlen($calendardata),
							'component' => strpos($calendardata, 'BEGIN:VEVENT') !== false ? 'vevent' : 'vtodo',
						];
					}
				}
			}
		}
		return $calevents;
	}

}
