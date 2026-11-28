<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Characterize native calendar dates, task progress and commercial fallbacks. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
define('DOL_URL_ROOT', '/dolibarr');
define('DOL_MAIN_URL_ROOT', 'https://calendar.example.test/dolibarr');
define('CDAV_URI_KEY', 'factorization-tests');
$conf = (object) array('file' => (object) array('dol_document_root' => array($root), 'dol_url_root' => array(DOL_URL_ROOT)));
require $root.'/includes/sabre/autoload.php';
require $root.'/core/lib/functions.lib.php';
require dirname(__DIR__).'/class/CalDAVDolibarr.php';
require dirname(__DIR__).'/class/actions_cdav.class.php';
require dirname(__DIR__).'/lib/cdav.lib.php';
date_default_timezone_set('Europe/Zurich');

/**
 * Fail on a changed business invariant.
 * @param bool $condition Expected invariant
 * @param string $message Failure detail
 * @return void
 */
function calendarFactorizationAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Exercise pure mapping without a Dolibarr database or HTTP endpoint.
 * @param object $object Production mapper
 * @param string $name Mapping method
 * @param array $arguments Native fixture values, with references where required
 * @return mixed Mapping result
 */
function calendarFactorizationInvoke($object, $name, array $arguments)
{
	$method = new ReflectionMethod($object, $name);
	return $method->invokeArgs($object, $arguments);
}

$lib = (new ReflectionClass(CdavLib::class))->newInstanceWithoutConstructor();
$row = (object) array('id' => 42, 'datec' => '2026-11-18 08:00:00', 'lastupd' => '2026-11-18 09:45:00',
	'location' => '', 'address' => '', 'soc_address' => '', 'label' => 'Native task', 'sourceuid' => 'outlook-stable-uid',
	'percent' => -1, 'datep' => '2026-11-20 09:00:00', 'datep2' => '2026-11-20 10:00:00', 'fulldayevent' => 0,
	'priority' => 5, 'transparency' => 1, 'dateo' => '2026-11-20 09:00:00', 'datee' => '2026-11-20 10:00:00',
	'fk_projet' => 8, 'proj_title' => 'Native project', 'progress' => 35,
	'fi_ref' => 'FI-009', 'fi_description' => 'Service intervention', 'fi_id' => 9, 'soc_nom' => '',
	'det_date' => '2026-11-20 09:00:00', 'det_duree' => 1800);

foreach (array('ev' => 'VEVENT', 'pe' => 'VEVENT', 'pt' => 'VTODO', 'fi' => 'VEVENT') as $source => $type) {
	$selected = clone $row;
	$selected->elem_source = $source;
	// No company zip/town/country properties exist: an empty address must short-circuit.
	$fragment = $lib->toVCalendar(7, $selected, false, true);
	$prefix = calendarFactorizationInvoke($lib, 'calendarComponentHeader', array($selected, $type, false));
	$envelope = calendarFactorizationInvoke($lib, 'calendarComponentHeader', array($selected, $type, true));
	calendarFactorizationAssert(str_starts_with($fragment, $prefix) && !str_contains($fragment, 'BEGIN:VCALENDAR'), 'Component-only export must retain its original envelope choice: '.$source);
	$calendar = Sabre\VObject\Reader::read($envelope.substr($fragment, strlen($prefix))."END:VCALENDAR\n");
	try {
		$component = $calendar->{$type};
		calendarFactorizationAssert((string) $calendar->PRODID === '-//Dolibarr CDav//FR'
			&& (string) $component->CREATED === '20261118T070000Z'
			&& (string) $component->{'LAST-MODIFIED'} === '20261118T084500Z'
			&& (string) $component->DTSTAMP === '20261118T084500Z', 'Native creation/modification timestamps must remain identical in UTC: '.$source);
		calendarFactorizationAssert($component->DTSTART->getDateTime()->getTimestamp() === strtotime('2026-11-20 09:00:00'), 'Every source must retain its local start and timezone: '.$source);
		$endpoint = $type === 'VTODO' ? $component->DUE : $component->DTEND;
		calendarFactorizationAssert($endpoint->getDateTime()->getTimestamp() - $component->DTSTART->getDateTime()->getTimestamp()
			=== ($source === 'fi' ? 1800 : 3600), 'Intervention seconds and project/Agenda endpoints must remain distinct: '.$source);
		calendarFactorizationAssert((string) $component->LOCATION === '' && (string) $component->DESCRIPTION === '', 'Anonymous export must not read absent postal fields or reveal description context');
		$uid = $source === 'ev' ? 'outlook-stable-uid' : '42-'.$source.'-'.CDAV_URI_KEY;
		calendarFactorizationAssert((string) $component->UID === $uid, 'Refactoring must not replace source-specific identities: '.$source);
		if ($source === 'pt') calendarFactorizationAssert((string) $component->{'PERCENT-COMPLETE'} === '35' && isset($component->DUE), 'VTODO must export progress with DUE');
		if ($source === 'pe') calendarFactorizationAssert(!isset($component->{'PERCENT-COMPLETE'}) && isset($component->DTEND), 'A task exported as VEVENT must not introduce VTODO progress properties');
	} finally {
		$calendar->destroy();
	}
}

$address = calendarFactorizationInvoke($lib, 'calendarAddress', array("Route 1\nBâtiment B", '1000', 'Lausanne', 'Suisse'));
calendarFactorizationAssert($address === 'Route 1 Bâtiment B, 1000 Lausanne, Suisse', 'Native postal formatting must preserve country and normalize street line breaks');
$backend = (new ReflectionClass(Sabre\CalDAV\Backend\Dolibarr::class))->newInstanceWithoutConstructor();
foreach (array('pe' => 'VEVENT', 'pt' => 'VTODO') as $source => $type) {
	$due = $type === 'VTODO' ? 'DUE' : 'DTEND';
	$task = (object) array('label' => 'Native task', 'description' => '<p>Keep the formatting</p>', 'progress' => 35,
		'priority' => 2, 'date_start' => strtotime('2026-11-20 09:00'), 'date_end' => null, 'fk_project' => 8, 'contacts' => array(19));
	$selected = clone $row;
	$selected->elem_source = $source;
	$selected->datee = null;
	$selected->priority = null;
	$fragment = $lib->toVCalendar(7, $selected, false, true);
	calendarFactorizationAssert(!str_contains($fragment, $due.';'), 'A project task without a native deadline must omit the calendar endpoint.');
	calendarFactorizationAssert(str_contains($fragment, "\nPRIORITY:0\n"), 'A native NULL priority must export as unspecified in both project task projections.');
	$calendar = new Sabre\VObject\Component\VCalendar();
	$item = $calendar->add($type, array('UID' => '42-'.$source.'-'.CDAV_URI_KEY, 'DTSTART' => '20261120T080000Z', 'SUMMARY' => 'Native task'));
	if ($type === 'VTODO') {
		$item->STATUS = 'IN-PROCESS';
		$item->{'PERCENT-COMPLETE'} = 35;
	}
	$before = $calendar->serialize();
	$write = static function (array $data = array()) use ($backend, $task, $source, $before, $calendar) {
		return calendarFactorizationInvoke($backend, '_applyProjectTaskData', array($task, $data + array('elem_source' => $source), $before, $calendar->serialize()));
	};
	$item->remove('SUMMARY');
	if ($type === 'VTODO') $item->remove('PERCENT-COMPLETE');
	else $item->DTEND = '20261120T080000Z'; // Historical PE echo repeated DTSTART when the deadline was absent.
	$task->priority = null;
	$legacy = str_replace("END:".$type, "PRIORITY:\r\nEND:".$type, $calendar->serialize());
	calendarFactorizationAssert(!calendarFactorizationInvoke($backend, '_applyProjectTaskData', array($task, array('elem_source' => $source), $before, $legacy))
		&& $task->priority === null, 'A legacy echo containing an empty priority must leave the unspecified native priority intact.');
	$task->priority = 2;
	calendarFactorizationAssert(!$write() && $task->date_end === null && $task->progress === 35 && $task->priority === 2
		&& $task->description === '<p>Keep the formatting</p>', 'A sparse project-task replay must preserve native fields and avoid Task::update.');
	$item->SUMMARY = 'Updated task';
	$item->DESCRIPTION = 'Updated native note';
	$item->PRIORITY = 0;
	$item->{$due} = '20261120T093000Z';
	if ($type === 'VTODO') $item->{'PERCENT-COMPLETE'} = 80;
	calendarFactorizationAssert($write(array('label' => 'Updated task', 'note' => 'Updated native note'))
		&& $task->date_end === strtotime('2026-11-20 10:30') && $task->label === 'Updated task' && $task->priority === 0
		&& $task->description === 'Updated native note' && $task->progress === ($source === 'pt' ? 80 : 35)
		&& $task->fk_project === 8 && $task->contacts === array(19), 'Project task edits must apply only to editable native fields.');
	$item->remove('SUMMARY');
	$item->remove($due);
	$item->remove('PERCENT-COMPLETE');
	$item->remove('STATUS');
	$item->DESCRIPTION = '';
	calendarFactorizationAssert($write(array('note' => '')) && $task->description === '' && $task->date_end === strtotime('2026-11-20 10:30'),
		'An explicit note clear must retain an omitted deadline.');
	$item->remove('DESCRIPTION');
	$item->remove('DTSTART');
	$item->add('DTSTART', '20261120', array('VALUE' => 'DATE'));
	$item->add($due, '20261121', array('VALUE' => 'DATE'));
	$write();
	calendarFactorizationAssert($task->date_end === strtotime('2026-11-21') - (int) ($source === 'pe'),
		'A VEVENT ends before DTEND while the VTODO remains due on its specified day.');
	$calendar->destroy();
}

$task = (object) array('date_start' => strtotime('2026-11-20 09:00'), 'date_end' => strtotime('2026-11-20 16:00'), 'priority' => 2);
$calendar = new Sabre\VObject\Component\VCalendar();
$item = $calendar->add('VTODO', array('UID' => '42-pt-'.CDAV_URI_KEY, 'DTSTART' => '20261120T080000Z', 'DUE' => '20261120T150000Z', 'PRIORITY' => 2));
$current = $calendar->serialize();
$apply = static function () use ($backend, $task, $current, $calendar) {
	return calendarFactorizationInvoke($backend, '_applyProjectTaskData', array($task, array('elem_source' => 'pt'), $current, $calendar->serialize()));
};
$item->DTSTART = '20261120T000000';
$item->DUE = '20261120T235959';
$item->PRIORITY = 1;
$outlook = new ReflectionProperty($backend, 'outlookSynchronizer');
$outlook->setValue($backend, true);
calendarFactorizationAssert(!$apply() && $task->priority === 2 && $task->date_start === strtotime('2026-11-20 09:00')
	&& $task->date_end === strtotime('2026-11-20 16:00'), 'The Outlook day-level VTODO replay must retain precise native project task times and priority.');
$outlook->setValue($backend, false);
calendarFactorizationAssert($apply() && $task->priority === 1 && $task->date_start === strtotime('2026-11-20')
	&& $task->date_end === strtotime('2026-11-20 23:59:59'), 'Standard project VTODO updates must apply literal priority and local times.');
$item->remove('DUE');
$item->DURATION = 'PT90M';
calendarFactorizationAssert($apply() && $task->date_start === strtotime('2026-11-20') && $task->date_end === strtotime('2026-11-20 01:30'),
	'A relative project-task duration must change only its deadline.');
$item->PRIORITY = 10;
try {
	$apply();
	throw new RuntimeException('An out-of-range project task priority was applied.');
} catch (Sabre\DAV\Exception\BadRequest $expected) {
	calendarFactorizationAssert($task->priority === 1, 'An invalid priority must leave native fields intact.');
}
$calendar->destroy();

$hook = new ActionsCDav();
$labels = $descriptions = $durations = array();
$cases = array(
	array(array('label' => 'Line label', 'prod_label' => 'Product label', 'description' => "Line details\nSecond line", 'prod_description' => 'Product details', 'cdav_duration' => '2h', 'prod_duration' => '1h'), array('Line label', "Line details\nSecond line", '2h')),
	array(array('prod_label' => 'Product fallback', 'prod_description' => 'Product details', 'prod_duration' => '45i'), array('Product fallback', 'Product details', '45i')),
	array(array('fk_product' => null, 'description' => "Description title\nFull detail", 'cdav_duration' => '1d'), array('Description title', "Description title\nFull detail", '1d')),
	array(array(), array('Task', '', '')),
	array(array('fk_product' => '790', 'label' => 'Catalog service'), array('Catalog service', '', ''), 789),
);
foreach ($cases as $index => $case) {
	calendarFactorizationInvoke($hook, 'appendCommercialTask', array((object) $case[0], $case[2] ?? 0, &$labels, &$descriptions, &$durations));
	calendarFactorizationAssert(array($labels[$index], $descriptions[$index], $durations[$index]) === $case[1], 'Order/proposal service tasks must retain their original label, description and duration priorities');
}
calendarFactorizationAssert(count($labels) === count($cases) && count($descriptions) === count($labels) && count($durations) === count($labels), 'Commercial task values must remain aligned in document order');
calendarFactorizationInvoke($hook, 'appendCommercialTask', array((object) array('fk_product' => '789'), 789, &$labels, &$descriptions, &$durations));
calendarFactorizationAssert(count($labels) === count($cases) && count($descriptions) === count($cases) && count($durations) === count($cases), 'The configured WEEE product should be excluded from task generation');
fwrite(STDOUT, "[PASS] Calendar factorization preserves native dates, event/todo semantics, identities and commercial task priorities.\n");
