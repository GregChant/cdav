<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Exercise real Sabre REPORTs and production mappings with an isolated SQL boundary. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
$source = getenv('CDAV_READ_COST_SOURCE_ROOT') ?: dirname(__DIR__);
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
$calendarReadDiagnostics = array();
$calendarReadVendorRoot = realpath($root.'/includes/sabre').DIRECTORY_SEPARATOR;
$calendarReadStrictHandler = null;
$calendarReadStrictHandler = set_error_handler(static function ($severity, $message, $file, $line) use (&$calendarReadDiagnostics, $calendarReadVendorRoot, &$calendarReadStrictHandler) {
	// Record before delegating: a production fallback may catch the strict exception.
	if ((error_reporting() & $severity) && !($severity === E_DEPRECATED && str_starts_with($file, $calendarReadVendorRoot))) {
		$calendarReadDiagnostics[] = $message.' at '.$file.':'.$line;
	}
	return $calendarReadStrictHandler($severity, $message, $file, $line);
});
define('DOL_DOCUMENT_ROOT', $root);
define('DOL_URL_ROOT', '/dolibarr');
define('DOL_MAIN_URL_ROOT', 'https://calendar.example.test/dolibarr');
define('MAIN_DB_PREFIX', 'llx_');
define('CDAV_URI_KEY', 'read-cost-tests');
define('CDAV_SYNC_PAST', 3650);
define('CDAV_SYNC_FUTURE', 3650);
$conf = (object) array('entity' => 1, 'global' => (object) array(), 'modules' => array(),
	'file' => (object) array('dol_document_root' => array('main' => $root), 'dol_url_root' => array('main' => DOL_URL_ROOT),
		'dol_main_url_root' => DOL_MAIN_URL_ROOT, 'character_set_client' => 'UTF-8'),
	'agenda' => (object) array('dir_output' => ''));
require $root.'/includes/sabre/autoload.php';
require $root.'/core/lib/functions.lib.php';
require $source.'/class/CDavSyncStore.php';
require $source.'/class/CDavManagedAttachmentStore.php';
require $source.'/class/CalDAVDolibarr.php';
require $source.'/class/CDavCalDAVPlugin.php';
require $source.'/lib/cdav.lib.php';
date_default_timezone_set('Europe/Zurich');

/**
 * Keep protocol diagnostics inert; SQL is counted explicitly below.
 * @param string $message Diagnostic
 * @return void
 */
function debug_log($message)
{
}

/**
 * Assert observable behavior or an operation budget, never elapsed time.
 * @param bool $condition Expected invariant
 * @param string $message Failure detail
 * @return void
 */
function calendarReadAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/** Minimal native language API; formatting and escaping remain production code. */
class CalendarReadLanguages
{
	public $defaultlang = 'en_US';
	/**
	 * @param string $domain Translation domain
	 * @return void
	 */
	public function load($domain)
	{
	}
	/**
	 * @param array $domains Translation domains
	 * @return void
	 */
	public function loadLangs($domains)
	{
	}
	/**
	 * @param string $key Native translation key
	 * @return string Label
	 */
	public function transnoentitiesnoconv($key)
	{
		return $key;
	}
	/** @return string Native language preference */
	public function getDefaultLang()
	{
		return $this->defaultlang;
	}
}

/** The authenticated native user retains the production rights checks. */
class CalendarReadUser
{
	public $id = 7;
	public $canRead = true;
	/**
	 * Permit Agenda reads only; no project, intervention or native write permission.
	 * @param string $module Native module
	 * @param string $first Permission group
	 * @param string $second Permission operation
	 * @return bool Native right
	 */
	public function hasRight($module, $first, $second = '')
	{
		return $this->canRead && $module === 'agenda' && in_array($first, array('myactions', 'allactions'), true) && $second === 'read';
	}
}

/** A cursor boundary only: no backend, filter or serializer is replaced. */
class CalendarReadDatabase
{
	public $rows = array();
	public $failList = false;
	public $failMetadataFor = 0;
	public $counts = array('list' => 0, 'single' => 0, 'metadata' => 0, 'recurrence' => 0, 'links' => 0, 'user' => 0);
	/** @param int $count Synthetic native Agenda rows */
	public function __construct($count)
	{
		for ($id = 1; $id <= $count; $id++) {
			$date = $id === 1 ? '2026-11-01' : ($id === 3 ? '2026-12-20' : '2026-11-20');
			$this->rows[$id] = (object) array('id' => $id, 'elem_source' => 'ev', 'datec' => '2026-10-01 08:00:00',
				'lastupd' => '2026-11-18 09:45:00', 'label' => 'Appointment '.$id, 'note' => '<p>Native note '.$id.'</p>',
				'location' => 'Lausanne', 'address' => '', 'soc_address' => '', 'sourceuid' => 'load-'.$id.'@example.test',
				'uuidext' => $id === 4 ? 'calendar-client-4.ics' : '', 'uuidext_count' => 1, 'cdav_project_id' => 0,
				'percent' => $id === 2 ? 50 : -1, 'datep' => $date.' 09:00:00', 'datep2' => $date.' 10:00:00',
				'fulldayevent' => 0, 'priority' => 5, 'transparency' => 1);
		}
	}
	/**
	 * @param string $table Native table
	 * @return bool Optional metadata availability
	 */
	public function DDLInfoTable($table)
	{
		return $table === MAIN_DB_PREFIX.'cdav_scheduling';
	}
	/**
	 * @param string $value SQL literal
	 * @return string Escaped literal
	 */
	public function escape($value)
	{
		return addslashes($value);
	}
	/** @return string Native database prefix */
	public function prefix()
	{
		return MAIN_DB_PREFIX;
	}
	/**
	 * Respond to the SQL emitted by production; unexpected database work fails closed.
	 * @param string $sql Native SQL
	 * @return object|false Isolated result cursor or a failed native query
	 */
	public function query($sql)
	{
		$rows = array();
		if (str_contains($sql, 'FROM llx_user u')) {
			$this->counts['user']++;
			$rows[] = (object) array('rowid' => 7, 'login' => 'load', 'firstname' => 'Read', 'lastname' => 'Fixture', 'color' => '123456');
		} elseif (str_contains($sql, 'FROM llx_actioncomm AS a')) {
			$id = preg_match('/AND a\.id = ([0-9]+)/', $sql, $match) ? (int) $match[1] : 0;
			if (!$id && str_contains($sql, "'calendar-client-4.ics'")) $id = 4;
			$this->counts[$id > 0 ? 'single' : 'list']++;
			if (!$id && $this->failList) return false;
			$rows = $id > 0 ? (isset($this->rows[$id]) ? array($this->rows[$id]) : array()) : array_values($this->rows);
		} elseif (str_contains($sql, 'SELECT calendardata FROM llx_cdav_scheduling')) {
			$this->counts['metadata']++;
			preg_match('/fk_actioncomm = ([0-9]+)/', $sql, $match);
			$id = (int) $match[1];
			if ($id === $this->failMetadataFor) throw new RuntimeException('Isolated metadata read failure');
			$extra = $id === 1 ? "RRULE:FREQ=DAILY;COUNT=30\r\n" : ($id === 5 ? "X-MICROSOFT-CDO-BUSYSTATUS:TENTATIVE\r\n" : '');
			$exception = $id === 1 ? "BEGIN:VEVENT\r\nUID:load-1@example.test\r\nRECURRENCE-ID:20261120T080000Z\r\nDTSTART:20261120T120000Z\r\nDTEND:20261120T130000Z\r\nSUMMARY:Exception\r\nEND:VEVENT\r\n" : '';
			$rows[] = (object) array('calendardata' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:load-".$id."@example.test\r\nDTSTART:20261101T080000Z\r\nDTEND:20261101T090000Z\r\nSEQUENCE:3\r\nORGANIZER:mailto:owner@example.test\r\nATTENDEE;PARTSTAT=ACCEPTED:mailto:load@example.test\r\n".$extra."END:VEVENT\r\n".$exception."END:VCALENDAR\r\n");
		} elseif (str_contains($sql, 'SELECT recurid, recurrule, recurdateend FROM llx_actioncomm')) {
			$this->counts['recurrence']++;
			$rows[] = (object) array('recurid' => null, 'recurrule' => '', 'recurdateend' => null);
		} elseif (str_contains($sql, 'FROM llx_links')) {
			$this->counts['links']++;
		} else {
			throw new RuntimeException('Unexpected isolated SQL: '.$sql);
		}
		return (object) array('rows' => $rows, 'position' => 0);
	}
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * @param object $result Isolated cursor
	 * @return object|null Native row clone
	 */
	public function fetch_object($result)
	{
		return isset($result->rows[$result->position]) ? clone $result->rows[$result->position++] : null;
	}
	/**
	 * @param object $result Isolated cursor
	 * @return array|null Native associative row
	 */
	public function fetch_array($result)
	{
		$row = $this->fetch_object($result);
		return $row ? get_object_vars($row) : null;
	}
	/**
	 * @param object $result Isolated cursor
	 * @return int Native cursor size
	 */
	public function num_rows($result)
	{
		return count($result->rows);
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}

/** Count production serializer invocations without changing its inputs or outputs. */
class CalendarReadExporter extends CdavLib
{
	public $exports = 0;
	/**
	 * Pass every byte through the production serializer.
	 * @param int $calid Native calendar owner
	 * @param object $obj Native export row
	 * @param bool $bHeader Complete VCALENDAR
	 * @param bool $booking Strict anonymous export
	 * @return string Actual production representation
	 */
	public function toVCalendar($calid, $obj, $bHeader, $booking = false)
	{
		$this->exports++;
		return parent::toVCalendar($calid, $obj, $bHeader, $booking);
	}
}

/**
 * Construct a request-local production backend and a real Sabre calendar tree.
 * @param int $count Native Agenda size
 * @param bool $cache Whether this is an explicitly cacheable read request
 * @return array Fixture components
 */
function calendarReadFixture($count, $cache)
{
	global $db;
	$db = new CalendarReadDatabase($count);
	$user = new CalendarReadUser();
	$langs = new CalendarReadLanguages();
	$exporter = new CalendarReadExporter($user, $db, $langs);
	$backend = new Sabre\CalDAV\Backend\Dolibarr($user, $db, $langs, $exporter, $cache);
	$node = new Sabre\CalDAV\Calendar($backend, array('id' => 7, 'uri' => '7-cal-load', 'principaluri' => 'principals/load'));
	$server = new Sabre\DAV\Server(array($node));
	$server->setBaseUri('/');
	$server->addPlugin(new Dolibarr\CDav\CalDAVPlugin(1048576, false, $backend));
	return array($db, $exporter, $backend, $server, $langs);
}

/**
 * Execute XML REPORT through the actual native Sabre request/property pipeline.
 * @param object $server Real Sabre server
 * @return array<string,array> Resource URI to actual calendar data and ETag
 */
function calendarReadReport($server)
{
	$xml = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="20261120T000000Z" end="20261121T000000Z"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
	$request = new Sabre\HTTP\Request('REPORT', '/7-cal-load/', array('Depth' => '1', 'Content-Type' => 'application/xml'), $xml);
	$response = new Sabre\HTTP\Response();
	$server->httpRequest = $request;
	$server->httpResponse = $response;
	$server->invokeMethod($request, $response, false);
	calendarReadAssert($response->getStatus() === 207, 'Real Sabre calendar-query must return multistatus');
	$document = new DOMDocument();
	calendarReadAssert($document->loadXML($response->getBodyAsString()), 'REPORT must return valid XML');
	$xpath = new DOMXPath($document);
	$xpath->registerNamespace('d', 'DAV:');
	$xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
	$result = array();
	foreach ($xpath->query('/d:multistatus/d:response') as $resource) {
		$uri = basename(rawurldecode($xpath->evaluate('string(d:href)', $resource)));
		// XML parsers normalize CRLF; restore the RFC 5545 transport line endings.
		$result[$uri] = array('data' => str_replace("\n", "\r\n", $xpath->evaluate('string(d:propstat/d:prop/c:calendar-data)', $resource)),
			'etag' => $xpath->evaluate('string(d:propstat/d:prop/d:getetag)', $resource));
	}
	return $result;
}

$measurements = array();
$budgetFailures = array();
foreach (array(100, 1000) as $count) {
	list($database, $exporter, $backend, $server) = calendarReadFixture($count, true);
	$start = hrtime(true);
	$resources = calendarReadReport($server);
	$elapsed = (hrtime(true) - $start) / 1000000000;
	calendarReadAssert(count($resources) === $count - 2, 'Native Sabre filters must retain recurring matches and exclude VTODO/out-of-range events');
	foreach ($resources as $uri => $resource) {
		calendarReadAssert($resource['etag'] === '"'.md5($resource['data']).'"', 'REPORT ETag must match its exact exported resource bytes');
		calendarReadAssert(str_contains($resource['data'], 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:load@example.test'), 'Scheduling metadata must survive cached REPORT');
	}
	calendarReadAssert(isset($resources['calendar-client-4.ics']), 'Client resource URI aliases must survive collection warming');
	calendarReadAssert(str_contains($resources['5-ev-'.CDAV_URI_KEY]['data'], 'STATUS:TENTATIVE')
		&& str_contains($resources['5-ev-'.CDAV_URI_KEY]['data'], 'X-MICROSOFT-CDO-BUSYSTATUS:TENTATIVE'),
		'A legacy Outlook-only tentative state must survive native REPORT export before an optional field is populated');
	calendarReadAssert(substr_count($resources['1-ev-'.CDAV_URI_KEY]['data'], 'BEGIN:VEVENT') === 2, 'Recurrence exceptions must remain complete calendar resources');
	$record = $backend->getCalendarObject(7, 'calendar-client-4.ics');
	calendarReadAssert((int) $record['id'] === 4 && $record['_cdav_description']['native_note'] === '<p>Native note 4</p>', 'Warm object records must retain native identity and trusted description boundaries');
	$measurements[] = array('events' => $count, 'exports' => $exporter->exports, 'sql' => $database->counts,
		'php_diagnostics' => count($calendarReadDiagnostics), 'seconds' => round($elapsed, 6), 'peak_bytes' => memory_get_peak_usage(true));
	if ($exporter->exports > $count || $database->counts['single'] > 0) {
		$budgetFailures[] = $count.' rows: '.$exporter->exports.' exports, '.$database->counts['single'].' individual SQL refetches';
	}
	if ($database->counts['list'] !== 1 || array_sum($database->counts) > 4 * $count + 10) {
		$budgetFailures[] = $count.' rows: native SQL budget exceeded ('.array_sum($database->counts).' queries)';
	}
	if ($count === 100) {
		list(, $uncachedExporter, , $uncachedServer) = calendarReadFixture($count, false);
		calendarReadAssert(calendarReadReport($uncachedServer) === $resources, 'Read caching must preserve the complete uncached native REPORT response');
		calendarReadAssert($uncachedExporter->exports > $count, 'The control fixture must actually exercise the uncached legacy pipeline');
	}
}

if (getenv('CDAV_READ_COST_BENCHMARK') === '1') fwrite(STDOUT, json_encode($measurements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
calendarReadAssert(!$calendarReadDiagnostics, 'REPORT export must not hide PHP diagnostics: '.implode('; ', $calendarReadDiagnostics));
calendarReadAssert(!$budgetFailures, 'REPORT one-export-per-row budget exceeded: '.implode('; ', $budgetFailures));

list($database, , $backend) = calendarReadFixture(5, false);
$database->rows[5]->cdav_status = 'CONFIRMED';
$explicitState = $backend->getCalendarObject(7, '5-ev-'.CDAV_URI_KEY);
calendarReadAssert(str_contains($explicitState['calendardata'], 'STATUS:CONFIRMED')
	&& str_contains($explicitState['calendardata'], 'X-MICROSOFT-CDO-BUSYSTATUS:BUSY'),
	'An explicit native state must override an older Outlook-only tentative hint in both protocol representations');
foreach (array('NEEDS-ACTION' => 'TENTATIVE', 'TENTATIVE' => 'TENTATIVE', 'ACCEPTED' => 'BUSY') as $answer => $busy) {
	$database->rows[5]->calendar_answer_status = $answer;
	$personalState = $backend->getCalendarObject(7, '5-ev-'.CDAV_URI_KEY);
	calendarReadAssert(str_contains($personalState['calendardata'], 'STATUS:CONFIRMED')
		&& str_contains($personalState['calendardata'], 'X-MICROSOFT-CDO-BUSYSTATUS:'.$busy),
		'Outlook must retain the personal provisional display without changing the confirmed shared state: '.$answer);
}

// Collection discovery/CTag reconciliation must reuse the same request snapshot.
list($database, $exporter, $backend, $server) = calendarReadFixture(100, true);
$calendars = $backend->getCalendarsForUser('principals/load');
calendarReadAssert(count($calendars) === 1 && count(calendarReadReport($server)) === 98, 'Collection discovery must preserve the subsequent real REPORT');
calendarReadAssert($exporter->exports === 100 && $database->counts['list'] === 1, 'Discovery, CTag and REPORT must share one serialization and native enumeration per row');
$again = $backend->getCalendarsForUser('principals/load');
calendarReadAssert($again == $calendars && $exporter->exports === 100, 'Repeated collection properties must keep stable CTags without rerendering objects');

// The storage budget is deterministic and includes identity/description metadata.
$cache = new Dolibarr\CDav\CalendarReadCache(true);
$small = array('id' => 4, 'uri' => 'same.ics', 'calendardata' => 'native bytes', 'etag' => '"etag"',
	'_cdav_description' => array('native_note' => '<p>Exact native note</p>'));
calendarReadAssert($cache->putObject('actor-a', $small) && $cache->completeCollection('actor-a', array('same.ics')), 'A complete small snapshot must be reusable');
calendarReadAssert($cache->getObject('actor-b', 'same.ics') === null && $cache->getCollection('actor-b') === null, 'Calendar contexts must never share an identity or a listing');
$oversized = $small;
$oversized['calendardata'] = str_repeat('x', Dolibarr\CDav\CalendarReadCache::MAX_BYTES);
calendarReadAssert(!$cache->putObject('actor-a', $oversized) && $cache->getObject('actor-a', 'same.ics') === null,
	'A representation exceeding eight MiB must fall back to native reads and discard its stale predecessor');
calendarReadAssert($cache->getCollection('actor-a') === null, 'An incomplete snapshot must never manufacture missing resources');
unset($oversized);
$cache->forgetContext('actor-a');
calendarReadAssert($cache->putObject('actor-b', $small), 'Forgetting one context must release its storage');
$cache->disable();
calendarReadAssert(!$cache->isEnabled() && $cache->getObject('actor-b', 'same.ics') === null
	&& !$cache->putObject('actor-b', $small), 'Mutation invalidation must permanently disable reads for this request');
$duplicate = new Dolibarr\CDav\CalendarReadCache(true);
$duplicate->putObject('calendar', $small);
calendarReadAssert(!$duplicate->putObject('calendar', array_replace($small, array('id' => 99))) && !$duplicate->isEnabled(), 'Ambiguous URI identities must abandon cache reuse and retain native lookup behavior');

// Fill the shared cache with another context, then prove the real REPORT remains complete.
list($database, , $backend, $server) = calendarReadFixture(5, true);
$property = new ReflectionProperty($backend, 'calendarReadCache');
$cache = $property->getValue($backend);
for ($index = 0; $index < Dolibarr\CDav\CalendarReadCache::MAX_ENTRIES; $index++) {
	$record = array_replace($small, array('id' => $index + 10000, 'uri' => 'other-'.$index.'.ics'));
	calendarReadAssert($cache->putObject('other-calendar', $record), 'The configured entry budget must accept its exact capacity');
}
calendarReadAssert(!$cache->putObject('other-calendar', array_replace($small, array('uri' => 'overflow.ics'))), 'The cache must enforce its 2048-entry cap');
calendarReadAssert(count(calendarReadReport($server)) === 3 && $database->counts['single'] > 0, 'A full cache must use fresh native reads without losing filtered calendar resources');

// Freshness scope: the same backend cannot reuse another locale/entity/calendar/actor.
list($database, $exporter, $backend, , $langs) = calendarReadFixture(5, true);
$uri = 'calendar-client-4.ics';
$initial = $backend->getCalendarObject(7, $uri);
calendarReadAssert($backend->getCalendarObject(7, $uri) === $initial && $exporter->exports === 1, 'A positive read must be reused within its exact request context');
$langs->defaultlang = 'fr_FR';
$backend->getCalendarObject(7, $uri);
$conf->entity = 2;
$backend->getCalendarObject(7, $uri);
$backend->getCalendarObject(8, $uri);
$backend->user->id = 8;
$backend->getCalendarObject(8, $uri);
date_default_timezone_set('UTC');
$backend->getCalendarObject(8, $uri);
calendarReadAssert($exporter->exports === 6, 'Language, entity, calendar, authenticated user and timezone must isolate serialized representations');
$conf->entity = 1;
date_default_timezone_set('Europe/Zurich');
$database->rows[4]->label = 'Changed natively before lock';
$backend->expectCalendarVersion(7, $uri, $initial['etag']);
$fresh = $backend->getCalendarObject(7, $uri);
calendarReadAssert(str_contains($fresh['calendardata'], 'SUMMARY:Changed natively before lock') && $fresh['etag'] !== $initial['etag'], 'Conditional native writes must abandon cached preflight representations');
$database->rows[4]->label = 'Changed again inside write request';
calendarReadAssert(str_contains($backend->getCalendarObject(7, $uri)['calendardata'], 'SUMMARY:Changed again inside write request'), 'A write request must keep reading the native store after invalidation');

// Optional metadata errors and failed source SQL must not publish partial snapshots.
foreach (array('failMetadataFor' => 2, 'failList' => true) as $failure => $value) {
	list($database, , $backend) = calendarReadFixture(5, true);
	$backend->getCalendarObject(7, $uri);
	$database->{$failure} = $value;
	$thrown = false;
	try {
		$backend->getCalendarObjects(7);
	} catch (RuntimeException $error) {
		$thrown = true;
	}
	calendarReadAssert($thrown, 'Failed native enumeration must propagate before a partial collection can be published: '.$failure);
	$database->{$failure} = 0;
	$database->rows[4]->label = 'Native change after failed listing';
	calendarReadAssert(str_contains($backend->getCalendarObject(7, $uri)['calendardata'], 'SUMMARY:Native change after failed listing'), 'A failed enumeration must discard every warmed representation in its context');
	calendarReadAssert(count($backend->getCalendarObjects(7)) === 5, 'A repaired native source must return every resource after a failed listing');
}

list($database, , $backend) = calendarReadFixture(5, true);
$backend->getCalendarObjects(7);
calendarReadAssert($backend->getCalendarObject(7, '999-ev-'.CDAV_URI_KEY) === null, 'An unknown native resource must remain absent');
$database->rows[999] = clone $database->rows[4];
$database->rows[999]->id = 999;
$database->rows[999]->uuidext = '';
calendarReadAssert($backend->getCalendarObject(7, '999-ev-'.CDAV_URI_KEY) !== null, 'A completed snapshot must never negatively cache a subsequently created resource');
$backend->user->canRead = false;
calendarReadAssert($backend->getCalendarObject(7, $uri) === null && $backend->getCalendarObjects(7) === array(), 'Warmed resources must still pass current native Agenda rights');

// Legacy aliases must not bypass native URI routing merely because the list was warmed.
foreach (array('999-pt-', '4-pt-', '999-ev-') as $prefix) {
	$alias = $prefix.CDAV_URI_KEY;
	foreach (array(false, true) as $cacheReads) {
		foreach (array('collection', 'object') as $warming) {
			list($database, , $backend) = calendarReadFixture(5, $cacheReads);
			$database->rows[4]->uuidext = $alias;
			if ($warming === 'collection') $backend->getCalendarObjects(7);
			else calendarReadAssert((int) $backend->getCalendarObject(7, '4-ev-'.CDAV_URI_KEY)['id'] === 4, 'Direct native URI must still resolve its authoritative Agenda object');
			calendarReadAssert($backend->getCalendarObject(7, $alias) === null,
				'A warmed external alias must retain native id/source routing: '.$warming.' '.$alias);
		}
	}
}
list($database, $exporter, $backend) = calendarReadFixture(5, true);
$database->rows[4]->uuidext = '4-ev-'.CDAV_URI_KEY;
$backend->getCalendarObjects(7);
calendarReadAssert((int) $backend->getCalendarObject(7, $database->rows[4]->uuidext)['id'] === 4
	&& $exporter->exports === 5 && $database->counts['single'] === 0, 'An internal-looking alias with matching native id/source must remain cacheable');
calendarReadAssert(!$calendarReadDiagnostics, 'Production fallbacks must not hide PHP diagnostics: '.implode('; ', $calendarReadDiagnostics));
fwrite(STDOUT, "[PASS] Real Sabre REPORT preserves filters, recurrence, metadata and ETags with one production export per native row.\n");
