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
define('CDAV_TASK_SYNC', 0);
define('CDAV_INTERV_SYNC', 0);
define('CDAV_SYNC_PAST', 90);
define('CDAV_SYNC_FUTURE', 180);
$languageRoot = sys_get_temp_dir().'/cdav-description-'.bin2hex(random_bytes(8));
if (!mkdir($languageRoot, 0700) || !symlink(dirname(__DIR__), $languageRoot.'/cdav')) throw new RuntimeException('Unable to prepare native module languages');
register_shutdown_function(static function () use ($languageRoot) {
	if (is_link($languageRoot.'/cdav')) unlink($languageRoot.'/cdav');
	if (is_dir($languageRoot)) rmdir($languageRoot);
});
$conf = (object) array('entity' => 1, 'global' => new stdClass(), 'modules' => array(), 'cache' => array(),
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
		$matched = $this->reference !== '' && strpos($sql, "request.reference = '".$this->reference."'") !== false
			&& strpos($sql, 'request.fk_actioncomm = 42') !== false && strpos($sql, 'segment.fk_actioncomm = 42') !== false;
		return (object) array('row' => $matched ? (object) array('reference' => $this->reference) : null);
	}
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Consume the native result cursor.
	 * @param object $result Cursor
	 * @return object|null
	 */
	public function fetch_object($result)
	{
		$row = $result->row;
		$result->row = null;
		return $row;
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
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
	/**
	 * Keep native exporter rights deterministic.
	 * @param string $module Native module
	 * @param string $group Native permission group
	 * @param string $right Native permission
	 * @return bool
	 */
	public function hasRight($module, $group, $right = '')
	{
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
$bookingSql = $lib->getSqlCalEvents(7, false, false, array(strtotime('2026-11-20'), strtotime('2026-11-22')));
descriptionAssert(strpos($bookingSql, 'sp.email') === false && strpos($bookingSql, 's.email') === false,
	'Anonymous booking SQL must not select newly displayed contact or company emails');
fwrite(STDOUT, "Localized plain calendar descriptions, verified reference footer, protected moves and free/busy privacy passed.\n");
