<?php

/**
 * Define Common function to access calendar items
 * And format it in vCalendar
 * */


class CdavLib
{
	/** Change this value whenever the generated DAV representation changes. */
	private const CALENDAR_SERIALIZATION_VERSION = '2026-08-html-text-v1';

	private $db;

	private $user;

	private $langs;

	/** @var bool|null Whether the optional iCalendar metadata table exists. */
	private $hasSchedulingTable = null;

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
		$tableName = MAIN_DB_PREFIX.'cdav_scheduling';
		$result = $this->db->query("SELECT COUNT(*) AS nb FROM information_schema.tables
			WHERE table_schema = DATABASE() AND table_name = '".$this->db->escape($tableName)."'");
		$row = $result ? $this->db->fetch_object($result) : null;
		$this->hasSchedulingTable = $row && (int) $row->nb > 0;
		return $this->hasSchedulingTable;
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
				'RECURRENCE-ID', 'SEQUENCE',
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
	 * Base sql request for calendar events
	 *
	 * @param int calendar user id
	 * @param int actioncomm object id
	 * @return string
	 */
	public function getSqlCalEvents($calid, $oid=false, $ouri=false)
	{
		// TODO : replace GROUP_CONCAT by
		$sql = 'SELECT
					"ev" elem_source,
					a.tms AS lastupd,
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
			if ($this->schedulingTableAvailable()) {
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
	 * Build a collection tag that changes on additions, updates, removals and
	 * assignment changes.  We deliberately do not advertise DAV sync tokens:
	 * Dolibarr has no tombstone log from which deleted object names can be
	 * reconstructed reliably.
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
				$tokens[] = $source.':'.((int) $obj->id).':'.((string) $obj->lastupd);
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
				$caldata.="STATUS:NEEDS-ACTION\n";
			elseif($obj->percent==100)
				$caldata.="STATUS:COMPLETED\n";
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
				$caldata.="STATUS:NEEDS-ACTION\n";
			elseif($obj->progress==100)
				$caldata.="STATUS:COMPLETED\n";
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
