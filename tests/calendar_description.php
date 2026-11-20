<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Plain iCalendar business descriptions, verified booking references and privacy. */
date_default_timezone_set('Europe/Zurich');
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
define('DOL_URL_ROOT', '/dolibarr');
define('DOL_MAIN_URL_ROOT', 'https://calendar.example.test/dolibarr');
define('MAIN_DB_PREFIX', 'llx_');
define('CDAV_URI_KEY', 'description-tests');
define('CDAV_TASK_SYNC', 3);
define('CDAV_INTERV_SYNC', 1);
define('CDAV_SYNC_PAST', 90);
define('CDAV_SYNC_FUTURE', 180);
$languageRoot = sys_get_temp_dir().'/cdav-description-'.bin2hex(random_bytes(8));
if (!mkdir($languageRoot, 0700) || !symlink(dirname(__DIR__), $languageRoot.'/cdav')) throw new RuntimeException('Unable to prepare native module languages');
register_shutdown_function(static function () use ($languageRoot) {
	if (is_link($languageRoot.'/cdav')) unlink($languageRoot.'/cdav');
	if (is_dir($languageRoot)) rmdir($languageRoot);
});
$conf = (object) array('entity' => 1, 'global' => new stdClass(), 'modules' => array('project' => true), 'cache' => array(),
	'file' => (object) array('dol_document_root' => array($root, $languageRoot), 'dol_url_root' => array('', '/custom'),
		'dol_main_url_root' => DOL_MAIN_URL_ROOT, 'character_set_client' => 'UTF-8'));
require $root.'/includes/sabre/autoload.php';
require $root.'/core/lib/functions.lib.php';
require $root.'/core/class/translate.class.php';
require dirname(__DIR__).'/lib/cdav.lib.php';
require dirname(__DIR__).'/class/CDavCalendarWritePolicy.php';

/** Minimal receipt read boundary; all real booking fields remain in native notes. */
class DescriptionDatabase
{
	public $reference = '';
	public $available = true;
	public $queries = array();
	public $interventionCandidates = array();
	public $interventionAllowed = array();
	public $tagRow = null;
	/**
	 * Expose only the optional booking receipt tables.
	 * @param string $table Requested table
	 * @return bool
	 */
	public function DDLInfoTable($table)
	{
		return $this->available && in_array($table, array('llx_cdav_booking_request', 'llx_cdav_booking_request_event'), true);
	}
	/**
	 * Escape deterministic test literals.
	 * @param string $value Literal
	 * @return string
	 */
	public function escape($value)
	{
		return addslashes($value);
	}
	/**
	 * Mirror the native regex predicate for the booking query shape.
	 * @param string $subject SQL expression to match
	 * @param string $pattern Native Agenda series ID format
	 * @param int $sqlstring Whether the subject is an SQL expression
	 * @return string Native SQL predicate
	 */
	public function regexpsql($subject, $pattern, $sqlstring = 0)
	{
		return '('.$subject." REGEXP '".$this->escape($pattern)."')";
	}
	/**
	 * Format a timestamp through the native database boundary.
	 * @param int $timestamp Native timestamp
	 * @return string
	 */
	public function idate($timestamp)
	{
		return date('Y-m-d H:i:s', $timestamp);
	}
	/**
	 * Return a reference only when its native event association is verified.
	 * @param string $sql Read query
	 * @return object
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;
		if (str_starts_with($sql, 'SELECT DISTINCT fi.rowid FROM llx_fichinter fi')) {
			return (object) array('rows' => array_map(static fn($id) => (object) array('rowid' => $id), $this->interventionCandidates));
		}
		if ($sql === 'SELECT description_acl_tag_fixture') return (object) array('rows' => $this->tagRow ? array(clone $this->tagRow) : array());
		$matched = $this->reference !== '' && strpos($sql, "request.reference = '".$this->reference."'") !== false
			&& strpos($sql, 'request.fk_actioncomm = 42') !== false && strpos($sql, 'segment.fk_actioncomm = 42') !== false;
		return (object) array('rows' => $matched ? array((object) array('reference' => $this->reference)) : array());
	}
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Consume the native result cursor.
	 * @param object $result Cursor
	 * @return object|null
	 */
	public function fetch_object($result)
	{
		return array_shift($result->rows);
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}

/** Native project API double; production uses Project::fetch/restrictedProjectArea. */
class Project
{
	public static $records = array(4 => array('entity' => 1, 'allowed' => true));
	public static $fetches = 0;
	public static $checks = 0;
	public static $scopes = 0;
	public $id = 0;
	public $entity = 0;
	/**
	 * Match the native constructor without loading a business database.
	 * @param object $db Native connection
	 */
	public function __construct($db)
	{
	}
	/**
	 * Fetch only an explicitly configured native project fixture.
	 * @param int $id Native project ID
	 * @return int Native fetch result
	 */
	public function fetch($id)
	{
		self::$fetches++;
		if (!isset(self::$records[$id])) return 0;
		$this->id = $id;
		$this->entity = self::$records[$id]['entity'];
		return 1;
	}
	/**
	 * Apply the fixture's native object access decision.
	 * @param object $user Authenticated user
	 * @param string $mode Requested access
	 * @return int Native permission result
	 */
	public function restrictedProjectArea($user, $mode = 'read')
	{
		self::$checks++;
		return $user->projectAll || self::$records[$this->id]['allowed'] ? 1 : 0;
	}
	/**
	 * Match the native project-list scope API.
	 * @param object $user Authenticated user
	 * @param int $mode Native scope mode
	 * @param int $list String-list flag
	 * @param int $socid Optional company
	 * @return string Native ID list
	 */
	public function getProjectsAuthorizedForUser($user, $mode = 0, $list = 0, $socid = 0)
	{
		self::$scopes++;
		$ids = array();
		foreach (self::$records as $id => $record) if ($record['entity'] === 1 && ($mode === 2 || $record['allowed'])) $ids[] = $id;
		return $ids ? implode(',', $ids) : '0';
	}
}

/**
 * Model native intervention object access independently of a calendar assignment.
 * @param object $user Authenticated user
 * @param array $features Native features
 * @param mixed $object Native object ID
 * @param string $table Native table
 * @param string $feature2 Secondary native feature
 * @param string $key Native company field
 * @return bool
 */
function checkUserAccessToObject($user, array $features, $object = 0, $table = '', $feature2 = '', $key = '')
{
	global $db;
	$GLOBALS['description_intervention_checks'][] = (int) $object;
	return $features === array('ficheinter') && $table === 'fichinter' && $key === 'fk_soc'
		&& in_array((int) $object, $db->interventionAllowed, true);
}

/** Collection-tag fixture without filesystem attachments or unrelated module lists. */
class DescriptionTagLibrary extends CdavLib
{
	/**
	 * Skip Agenda filesystem attachment work in this focused token regression.
	 * @param int $calid Native owner
	 * @param mixed $oid Native ID
	 * @param mixed $ouri Resource URI
	 * @param array|null $bookingRange Anonymous range
	 * @return false
	 */
	public function getSqlCalEvents($calid, $oid = false, $ouri = false, ?array $bookingRange = null)
	{
		return false;
	}
	/**
	 * Supply one unchanged project-linked native row.
	 * @param int $calid Native owner
	 * @param mixed $oid Native ID
	 * @param string $elem_source Native source
	 * @return string|false
	 */
	public function getSqlProjectTasks($calid, $oid = false, $elem_source = 'pt')
	{
		return $elem_source === 'pe' ? 'SELECT description_acl_tag_fixture' : false;
	}
	/**
	 * Exclude unrelated intervention fixtures.
	 * @param int $calid Native owner
	 * @param mixed $oid Native line ID
	 * @return false
	 */
	public function getSqlIntervEvents($calid, $oid = false)
	{
		return false;
	}
}

/**
 * Assert useful rendering behavior rather than its implementation.
 * @param bool $condition Expected behavior
 * @param string $message Failure detail
 * @return void
 */
function descriptionAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Parse the actual export fragment with the bundled native Sabre library.
 * @param CdavLib $lib Native exporter
 * @param object $row Native export row
 * @return Sabre\VObject\Component\VCalendar Parsed calendar
 */
function descriptionCalendar($lib, $row)
{
	return Sabre\VObject\Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n".$lib->toVCalendar(7, $row, false)."END:VCALENDAR\r\n");
}

$db = new DescriptionDatabase();
$user = new class {
	public $id = 7;
	public $projectRead = true;
	public $projectAll = false;
	public $interventionRead = true;
	/**
	 * Keep native exporter rights deterministic.
	 * @param string $module Native module
	 * @param string $group Native permission group
	 * @param string $right Native permission
	 * @return bool
	 */
	public function hasRight($module, $group, $right = '')
	{
		if ($module === 'projet' && $group === 'lire') return $this->projectRead;
		if ($module === 'projet' && $group === 'all' && $right === 'lire') return $this->projectAll;
		if ($module === 'ficheinter' && $group === 'lire') return $this->interventionRead;
		return true;
	}
};
$reference = str_repeat('a', 32);
$rawNote = 'CDav booking '.$reference."\nÉmile Client\nclient@example.test\n+41 79 111 22 33\nObjet du rendez-vous: Entretien chaudière\nAdresse du rendez-vous:\nRoute du Rhône 8\n1860 Aigle";
$collectionTags = array();
$event = (object) array('elem_source' => 'ev', 'id' => 42, 'label' => 'Entretien chaudière', 'location' => "Route du Rhône 8\n1860 Aigle",
	'note' => $rawNote, 'datec' => '2026-11-18 08:00:00', 'lastupd' => '2026-11-18 08:00:00',
	'datep' => '2026-11-20 09:00:00', 'datep2' => '2026-11-20 10:00:00', 'percent' => -1, 'sourceuid' => '', 'fulldayevent' => 0,
	'priority' => 5, 'transparency' => 1, 'firstname' => 'Camille', 'lastname' => 'Contact',
	'phone' => '+41 62 555 01 01', 'phone_perso' => '', 'phone_mobile' => '+41 79 555 01 01', 'contact_email' => 'camille@example.test',
	'address' => '', 'zip' => '', 'town' => '', 'country_label' => '',
	'soc_nom' => 'Client & Associés SA', 'soc_address' => 'Industriestrasse 12', 'soc_zip' => '4600', 'soc_town' => 'Olten',
	'soc_country_label' => 'Suisse', 'soc_phone' => '+41 62 555 00 00', 'soc_email' => 'societe@example.test',
	'proj_ref' => 'PRJ-0042', 'proj_title' => 'Installation chauffage',
	'fk_project' => 4, 'cdav_project_id' => 4,
	'proj_desc' => '<p>Première consigne</p><p>Deuxième paragraphe<br>Suite de la consigne</p>',
	'recurid' => '', 'recurrule' => '', 'recurdateend' => null);

foreach (array('fr_FR' => array('Rendez-vous', 'Client et contact', 'Projet', 'Référence de réservation'),
	'en_US' => array('Appointment', 'Client and contact', 'Project', 'Booking reference')) as $language => $labels) {
	$langs = new Translate('', $conf);
	$langs->setDefaultLang($language);
	$lib = new CdavLib($user, $db, $langs);
	$collectionTags[$language] = $lib->getCalendarCollectionTag(7);
	$db->reference = $reference;
	$db->queries = array();
	$calendar = descriptionCalendar($lib, $event);
	$text = (string) $calendar->VEVENT->DESCRIPTION;
	$appointment = strpos($text, $labels[0]."\n");
	$client = strpos($text, $labels[1]."\n");
	$project = strpos($text, $labels[2]."\n");
	descriptionAssert($appointment === 0 && $client > $appointment && $project > $client, 'Appointment, client/contact and project blocks must be localized and ordered');
	descriptionAssert(strpos($text, ($language === 'fr_FR' ? 'Objet du rendez-vous' : 'Appointment subject').' : '.$event->label) !== false,
		'The appointment subject label must be translated without depending on unrelated native mail language files');
	descriptionAssert(str_ends_with($text, $labels[3].' : '.$reference) && strpos($text, 'CDav booking') === false,
		'A verified booking reference must appear as a readable footer');
	foreach (array('Émile Client', 'client@example.test', '+41 79 111 22 33', '1860 Aigle', '4600 Olten', 'Camille Contact',
		'camille@example.test', 'societe@example.test', '[PRJ-0042] Installation chauffage', 'Première consigne', 'Deuxième paragraphe', 'Suite de la consigne') as $value) {
		descriptionAssert(strpos($text, $value) !== false, 'Description lost native business information: '.$value);
	}
	descriptionAssert(strpos($text, '💼') === false && strpos($text, '⚠') === false && strpos($text, '<p>') === false
		&& strpos($text, "\n\n".$labels[2]) !== false, 'Project paragraphs must remain plain text without repeated decorations');
	descriptionAssert((string) $calendar->VEVENT->SUMMARY === $event->label
		&& strpos((string) $calendar->VEVENT->LOCATION, 'Aigle') !== false
		&& strpos((string) $calendar->VEVENT->LOCATION, 'Olten') === false, 'Client postal town must not replace the appointment address');
	descriptionAssert($event->note === $rawNote, 'Rendering must never alter stored native notes');
	descriptionAssert($event->_cdav_description['native_note'] === $rawNote && !$event->_cdav_description['notes_editable'],
		'An internally verified booking must retain its exact SQL notes and disallow editing its rewritten description');
	descriptionAssert(count($db->queries) === 1, 'Only an exact booking marker should require one receipt lookup');

	$before = $calendar->serialize();
	$calendar->VEVENT->DTSTART->setDateTime(new DateTimeImmutable('2026-11-21 09:00:00', new DateTimeZone('Europe/Zurich')));
	$calendar->VEVENT->DTEND->setDateTime(new DateTimeImmutable('2026-11-21 10:00:00', new DateTimeZone('Europe/Zurich')));
	descriptionAssert(Dolibarr\CDav\CalendarWritePolicy::assertMoveOnly($before, $calendar->serialize())['end']
		- Dolibarr\CDav\CalendarWritePolicy::assertMoveOnly($before, $calendar->serialize())['start'] === 3600,
		'A protected move must accept an unchanged enriched description');

	$db->queries = array();
	$private = $lib->toVCalendar(7, $event, true, true);
	foreach (array('Émile', 'Aigle', 'Olten', 'example.test', $reference, 'PRJ-0042', 'consigne') as $secret) {
		descriptionAssert(strpos($private, $secret) === false, 'Free/busy export exposed description information: '.$secret);
	}
	descriptionAssert($db->queries === array(), 'Anonymous time-only export must not query private booking receipts');

	$ordinary = clone $event;
	$ordinary->note = "Paragraphe personnel\n\nPréserver mes lignes\nCDav booking ".$reference."\nNe pas déplacer cette ligne. ✅";
	$db->queries = array();
	$ordinaryText = (string) descriptionCalendar($lib, $ordinary)->VEVENT->DESCRIPTION;
	descriptionAssert(strpos($ordinaryText, $ordinary->note) !== false && $db->queries === array(),
		'An arbitrary marker inside user notes must stay intact without a receipt query');
	descriptionAssert($ordinary->_cdav_description['notes_editable']
		&& $ordinary->_cdav_description['text'] === $ordinary->_cdav_description['note_prefix'].$ordinary->note.$ordinary->_cdav_description['note_suffix'],
		'Ordinary notes must have a trusted exact server envelope around their editable content');
	$db->reference = '';
	$unverified = (string) descriptionCalendar($lib, $event)->VEVENT->DESCRIPTION;
	descriptionAssert(strpos($unverified, 'CDav booking '.$reference) !== false && strpos($unverified, $labels[3].' : ') === false,
		'Expired or absent receipts must preserve the full original booking note');
	$empty = clone $ordinary;
	$empty->note = '';
	$emptyText = (string) descriptionCalendar($lib, $empty)->VEVENT->DESCRIPTION;
	descriptionAssert($emptyText === $empty->_cdav_description['note_prefix'].$empty->_cdav_description['note_suffix']
		&& strpos($emptyText, "\n\n\n") === false, 'Empty notes must retain an editable boundary without repeated blank decorations');
	$rich = clone $ordinary;
	$rich->note = '<p>My <strong>native</strong> note</p><p>Second paragraph</p>';
	descriptionCalendar($lib, $rich);
	descriptionAssert($rich->_cdav_description['native_note'] === $rich->note && $rich->_cdav_description['notes_editable'],
		'A plain exported description must retain the original rich SQL note for a harmless echo');
	$trustedParts = $rich->_cdav_description;
	descriptionAssert($lib->getCalendarDescriptionPrefixForFields($trustedParts, $rich->label, $rich->location) === $trustedParts['note_prefix'],
		'The trusted prefix descriptor must reproduce the original generated fields exactly');
	$newPrefix = $lib->getCalendarDescriptionPrefixForFields($trustedParts, 'Nouveau sujet', "Nouvelle adresse\n1000 Lausanne");
	descriptionAssert(strpos($newPrefix, 'Nouveau sujet') !== false && strpos($newPrefix, '1000 Lausanne') !== false
		&& $trustedParts['note_suffix'] === $rich->_cdav_description['note_suffix'] && $trustedParts['native_note'] === $rich->note,
		'Updating explicit appointment fields must not rewrite CRM context or the native notes');
	descriptionAssert(Dolibarr\CDav\CalendarWritePolicy::nativeNoteForReplacement($trustedParts,
		$newPrefix.'My native note'."\nSecond paragraph".$trustedParts['note_suffix'], $newPrefix) === $rich->note,
		'A client that echoes its permitted subject/location edits in the generated prefix must retain the exact native HTML notes');
	$blankPrefix = $lib->getCalendarDescriptionPrefixForFields($trustedParts, '', '');
	descriptionAssert(strpos($blankPrefix, $language === 'fr_FR' ? 'Objet du rendez-vous' : 'Appointment subject') === false,
		'Explicitly clearing an editable field must remove only its generated label/value');
}
descriptionAssert($collectionTags['fr_FR'] !== $collectionTags['en_US'], 'Changing the native user language must invalidate the calendar collection tag');

$task = clone $event;
$task->elem_source = 'pt';
$task->ref = 'TASK-01';
$task->dateo = $task->datep;
$task->datee = $task->datep2;
$task->fk_projet = 4;
$task->progress = 0;
$task->note_public = 'Note publique de la tâche';
$task->description = "- Première étape\n- [x] Étape terminée";
$taskText = (string) descriptionCalendar($lib, $task)->VTODO->DESCRIPTION;
descriptionAssert(strpos($taskText, '[ ] Première étape') !== false && strpos($taskText, '[x] Étape terminée') !== false
	&& strpos($taskText, '[PRJ-0042/TASK-01]') !== false, 'Project-task descriptions must retain checklists and task references');
descriptionAssert($task->_cdav_description['native_note'] === $task->description
	&& strpos($task->_cdav_description['note_prefix'], $task->note_public) !== false,
	'Public task notes must remain outside the editable task-description region');
$task->label = 'Shared token';
$task->note_public = "Parent information: Shared token\nParent address: Stable address";
descriptionCalendar($lib, $task);
$taskPrefix = $lib->getCalendarDescriptionPrefixForFields($task->_cdav_description, 'Changed task title', 'Forged CRM address');
descriptionAssert(strpos($taskPrefix, 'Changed task title') !== false
	&& strpos($taskPrefix, $task->note_public) !== false && strpos($taskPrefix, 'Forged CRM address') === false,
	'Task title edits must not replace identical text in protected parent notes or authorize a derived CRM location');
$intervention = clone $event;
$intervention->elem_source = 'fi';
$intervention->fi_id = 9;
$intervention->fi_ref = 'FI-009';
$intervention->fi_description = 'Intervention chauffage';
$intervention->fi_note_public = 'Note publique intervention';
$intervention->det_description = 'Détail de la ligne intervention';
$intervention->det_date = $intervention->datep;
$intervention->det_duree = 3600;
$interventionText = (string) descriptionCalendar($lib, $intervention)->VEVENT->DESCRIPTION;
descriptionAssert(strpos($interventionText, 'Note publique intervention') !== false
	&& strpos($interventionText, 'Détail de la ligne intervention') !== false && strpos($interventionText, '💼') === false,
	'Interventions must preserve both native notes and line descriptions');
descriptionAssert($intervention->_cdav_description['native_note'] === $intervention->det_description
	&& strpos($intervention->_cdav_description['note_prefix'], $intervention->fi_note_public) !== false,
	'Public intervention notes must remain outside the editable line-description region');
descriptionAssert($lib->getCalendarDescriptionPrefixForFields($intervention->_cdav_description, 'Forged parent title', 'Forged CRM address')
	=== $intervention->_cdav_description['note_prefix'], 'Intervention parent titles and derived locations must remain immutable');

$nativeSeries = clone $event;
$nativeSeries->recurid = '20261118074500';
$nativeSeries->recurrule = 'FREQ=WEEKLY_BYDAY5';
$nativeSeries->recurdateend = '2026-12-18 23:59:59';
$seriesCalendar = Sabre\VObject\Reader::read($lib->toVCalendar(7, $nativeSeries, true, true));
descriptionAssert(!isset($seriesCalendar->VEVENT->RRULE), 'A row materialized by the native Agenda recurrence form must occupy only its stored dates');
$seriesCalendar->destroy();
$nativeSeries->recurid = '';
$seriesCalendar = Sabre\VObject\Reader::read($lib->toVCalendar(7, $nativeSeries, true, true));
descriptionAssert(str_starts_with((string) $seriesCalendar->VEVENT->RRULE, 'FREQ=WEEKLY;BYDAY=FR'), 'A standalone declarative native recurrence must still expand');
$seriesCalendar->destroy();

$bookingSql = $lib->getSqlCalEvents(7, false, false, array(strtotime('2026-11-20'), strtotime('2026-11-22')));
descriptionAssert(strpos($bookingSql, 'sp.email') === false && strpos($bookingSql, 's.email') === false,
	'Anonymous booking SQL must not select newly displayed contact or company emails');
descriptionAssert(str_contains($bookingSql, "NOT (COALESCE(a.recurid,'') REGEXP '^[0-9]{14}$')"),
	'Materialized native recurrence rows without iCalendar metadata must match the actual booking date window');

// Project links may be visible on Agenda while project contents remain private.
$aclRow = clone $event;
$aclRow->note = 'Native event note';
$aclRow->proj_desc = 'PROJECT-DESCRIPTION-SECRET';
$user->projectRead = false;
$beforeFetches = Project::$fetches;
$noProjectRead = new CdavLib($user, $db, $langs);
$denied = $noProjectRead->getCalendarDescriptionParts($aclRow)['text'];
descriptionAssert(strpos($denied, $aclRow->proj_desc) === false && strpos($denied, '[PRJ-0042]') !== false
	&& Project::$fetches === $beforeFetches, 'Agenda-only access may retain its project link but must not read project contents');
descriptionAssert($noProjectRead->getSqlProjectTasks(7) === false, 'Agenda-only users must not list or fetch project-task details');

$user->projectRead = true;
Project::$records[4]['allowed'] = false;
$privateProject = new CdavLib($user, $db, $langs);
$beforeFetches = Project::$fetches;
$beforeChecks = Project::$checks;
descriptionAssert(strpos($privateProject->getCalendarDescriptionParts($aclRow)['text'], $aclRow->proj_desc) === false,
	'A private project must stay masked when the reader is not a project member');
$privateProject->getCalendarDescriptionParts($aclRow);
descriptionAssert(Project::$fetches === $beforeFetches + 1 && Project::$checks === $beforeChecks + 1,
	'Native project fetch and authorization must run once per project in one export request');
descriptionAssert($privateProject->getSqlProjectTasks(7) === false, 'A calendar assignment must not bypass private-project membership for tasks');

Project::$records[4]['allowed'] = true;
$memberProject = new CdavLib($user, $db, $langs);
descriptionAssert(strpos($memberProject->getCalendarDescriptionParts($aclRow)['text'], $aclRow->proj_desc) !== false,
	'A native project member with read permission must retain the project description');
$beforeScopes = Project::$scopes;
$taskSql = $memberProject->getSqlProjectTasks(7);
$memberProject->getSqlProjectTasks(7, 15, 'pe');
descriptionAssert(strpos($taskSql, 'p.rowid IN (4)') !== false && Project::$scopes === $beforeScopes + 1,
	'Task list and direct reads must use one cached native project scope');
Project::$records[4]['allowed'] = false;
$user->projectAll = true;
$allProject = new CdavLib($user, $db, $langs);
descriptionAssert($allProject->canReadProjectDescription($aclRow), 'The native all-project read permission must authorize a private project');
$user->projectAll = false;
Project::$records[4] = array('entity' => 2, 'allowed' => true);
$crossEntity = new CdavLib($user, $db, $langs);
descriptionAssert(!$crossEntity->canReadProjectDescription($aclRow), 'A linked project outside shared entities must remain masked');
unset(Project::$records[4]);
descriptionAssert(!(new CdavLib($user, $db, $langs))->canReadProjectDescription($aclRow), 'A failed native project fetch must fail closed');
Project::$records[4] = array('entity' => 1, 'allowed' => true);
$legacyAlias = clone $aclRow;
unset($legacyAlias->cdav_project_id, $legacyAlias->fk_project);
$legacyAlias->fk_projet = 4;
descriptionAssert((new CdavLib($user, $db, $langs))->canReadProjectDescription($legacyAlias), 'Native fk_projet aliases must receive the same project authorization');
$maskedJoin = clone $aclRow;
$maskedJoin->cdav_project_id = null;
descriptionAssert(!(new CdavLib($user, $db, $langs))->canReadProjectDescription($maskedJoin), 'A masked entity join must not fall back to its forbidden native foreign key');
$conf->modules['project'] = false;
descriptionAssert(!(new CdavLib($user, $db, $langs))->canReadProjectDescription($aclRow), 'A disabled project module must not export project descriptions');
$conf->modules['project'] = true;
$scopeSql = $memberProject->getSqlCalEvents(7, 42);
descriptionAssert(strpos($scopeSql, 'p.rowid = a.fk_project AND p.entity IN (1)') !== false,
	'Agenda project joins must respect current native shared-project entities');

// Rights/membership changes invalidate descriptions even without an Agenda edit.
$db->tagRow = $aclRow;
Project::$records[4]['allowed'] = false;
$deniedTag = (new DescriptionTagLibrary($user, $db, $langs))->getCalendarCollectionTag(7);
Project::$records[4]['allowed'] = true;
$allowedTag = (new DescriptionTagLibrary($user, $db, $langs))->getCalendarCollectionTag(7);
Project::$records[4]['allowed'] = false;
$revokedTag = (new DescriptionTagLibrary($user, $db, $langs))->getCalendarCollectionTag(7);
descriptionAssert($deniedTag !== $allowedTag && $revokedTag === $deniedTag,
	'Granting or revoking project membership must change the collection tag without changing the Agenda row');

// Native intervention rights and parent-company scope are separate from Agenda.
$conf->modules['ficheinter'] = true;
$user->interventionRead = false;
$db->queries = array();
descriptionAssert((new CdavLib($user, $db, $langs))->getSqlIntervEvents(7) === false && $db->queries === array(),
	'Agenda-only readers must not query assigned intervention details');
$user->interventionRead = true;
$db->interventionCandidates = array(9, 10);
$db->interventionAllowed = array(9);
$GLOBALS['description_intervention_checks'] = array();
$interventionScope = new CdavLib($user, $db, $langs);
$interventionSql = $interventionScope->getSqlIntervEvents(7);
$interventionScope->getSqlIntervEvents(7, 15);
descriptionAssert(strpos($interventionSql, 'fi.rowid IN (9)') !== false
	&& $GLOBALS['description_intervention_checks'] === array(9, 10), 'Intervention list/direct reads must reuse cached native object access for allowed parent documents');
descriptionAssert(strpos($interventionSql, 'p.rowid = fi.fk_projet AND p.entity IN (1)') !== false,
	'Intervention project links must respect shared-project entities');
fwrite(STDOUT, "Localized plain calendar descriptions, verified reference footer, protected moves and free/busy privacy passed.\n");
