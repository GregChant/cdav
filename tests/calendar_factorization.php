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
$data = array('label' => 'Updated task', 'start' => 1000, 'end' => 2000, 'fullday' => 0,
	'priority' => 3, 'note' => '<p>Exact native HTML note</p>', 'percent' => 80);
foreach (array('pe', 'pt') as $source) {
	$task = (object) array('progress' => 35, 'fk_project' => 8, 'contacts' => array(19));
	calendarFactorizationInvoke($backend, '_applyProjectTaskData', array($task, $data + array('elem_source' => $source)));
	calendarFactorizationAssert($task->progress === ($source === 'pt' ? 80 : 35), 'Only VTODO may update native task progress');
	calendarFactorizationAssert($task->date_start === 1000 && $task->date_end === 2000 && $task->description === $data['note']
		&& $task->fk_project === 8 && $task->contacts === array(19), 'Common Task mapping must preserve dates, rich notes and native project/contact links');
	calendarFactorizationInvoke($backend, '_applyProjectTaskData', array($task, array_replace($data, array('elem_source' => $source, 'fullday' => 1))));
	calendarFactorizationAssert($task->date_end === 1999, 'All-day exclusive DAV ends must remain native inclusive task ends');
	calendarFactorizationInvoke($backend, '_applyProjectTaskData', array($task, array_replace($data, array('elem_source' => $source, 'end' => 500))));
	calendarFactorizationAssert($task->date_end === $task->date_start, 'Task mapping must never persist an end before its start');
}

$hook = new ActionsCDav();
$labels = $descriptions = $durations = array();
$cases = array(
	array(array('label' => 'Line label', 'prod_label' => 'Product label', 'description' => "Line details\nSecond line", 'prod_description' => 'Product details', 'cdav_duration' => '2h', 'prod_duration' => '1h'), array('Line label', "Line details\nSecond line", '2h')),
	array(array('prod_label' => 'Product fallback', 'prod_description' => 'Product details', 'prod_duration' => '45i'), array('Product fallback', 'Product details', '45i')),
	array(array('description' => "Description title\nFull detail", 'cdav_duration' => '1d'), array('Description title', "Description title\nFull detail", '1d')),
	array(array(), array('Task', '', '')),
);
foreach ($cases as $index => $case) {
	calendarFactorizationInvoke($hook, 'appendCommercialTask', array((object) $case[0], &$labels, &$descriptions, &$durations));
	calendarFactorizationAssert(array($labels[$index], $descriptions[$index], $durations[$index]) === $case[1], 'Order/proposal service tasks must retain their original label, description and duration priorities');
}
calendarFactorizationAssert(count($labels) === count($cases) && count($descriptions) === count($labels) && count($durations) === count($labels), 'Commercial task values must remain aligned in document order');
fwrite(STDOUT, "[PASS] Calendar factorization preserves native dates, event/todo semantics, identities and commercial task priorities.\n");
