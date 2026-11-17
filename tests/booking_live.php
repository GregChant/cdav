<?php

/**
 * Opt-in HTTP/native-DB booking regressions. Use an exclusively isolated instance.
 *
 * Requires CDAV_LIVE_TEST=1, DOLIBARR_ROOT and CDAV_BOOKING_LIVE_URL. B06 also
 * requires CDAV_BOOKING_TEST_FIXTURES=1: a temporary native trigger is installed
 * in that test instance and removed in finally. Never target a production tree.
 */
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
if (getenv('CDAV_LIVE_TEST') !== '1') {
	fwrite(STDERR, "Refusing to run: CDAV_LIVE_TEST=1 is required.\n");
	exit(2);
}
$root = rtrim((string) getenv('DOLIBARR_ROOT'), '/');
$bookingUrl = rtrim((string) getenv('CDAV_BOOKING_LIVE_URL'), '/');
if (!is_file($root.'/main.inc.php') || !preg_match('#^https?://#', $bookingUrl)
	|| getenv('CDAV_BOOKING_TEST_FIXTURES') !== '1') {
	fwrite(STDERR, "Set DOLIBARR_ROOT, CDAV_BOOKING_LIVE_URL and CDAV_BOOKING_TEST_FIXTURES=1 for an isolated test tree.\n");
	exit(2);
}
date_default_timezone_set('Europe/Zurich');
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOCSRFCHECK', 1); // CLI fixture setup only; HTTP requests use native CSRF.
$parsedUrl = parse_url($bookingUrl);
$_SERVER['SERVER_NAME'] = (string) $parsedUrl['host'];
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'].(isset($parsedUrl['port']) ? ':'.$parsedUrl['port'] : '');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir($root);
require $root.'/main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
require_once dirname(__DIR__).'/class/CDavBookingService.php';
require_once dirname(__DIR__).'/class/CDavSyncStore.php';

use Dolibarr\CDav\BookingException;
use Dolibarr\CDav\BookingService;

/**
 * Stop a scenario when its expected condition is not satisfied.
 *
 * @param mixed $condition Expected truthy result
 * @param string $message Explanation of the failed requirement
 * @return void
 */
function bookingAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Record the successful completion of a live-test stage.
 *
 * @param string $message Validated behavior
 * @return void
 */
function bookingOk($message)
{
	fwrite(STDOUT, '[OK] '.$message.PHP_EOL);
}

/**
 * Execute fixture or inspection SQL using the native connection.
 *
 * @param string $sql SQL statement for the isolated test database
 * @return mixed Native database result
 */
function bookingQuery($sql)
{
	global $db;
	$result = $db->query($sql);
	if (!$result) throw new RuntimeException('Booking test SQL failed: '.$db->lasterror());
	return $result;
}

/**
 * Read one row from the isolated test database.
 *
 * @param string $sql Read-only inspection query
 * @return object|false|null First row, or the driver's empty result
 */
function bookingRow($sql)
{
	global $db;
	return $db->fetch_object(bookingQuery($sql));
}

/**
 * Count native Agenda assignments belonging to a fixture owner.
 *
 * @param int $ownerId Fixture owner identifier
 * @return int Assigned event count
 */
function bookingCountEvents($ownerId)
{
	return (int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources'
		." WHERE element_type = 'user' AND fk_element = ".((int) $ownerId))->nb;
}

/**
 * Check a response status and include useful diagnostics on failure.
 *
 * @param array $response HTTP response returned by bookingHttp()
 * @param int[] $allowed Accepted HTTP statuses
 * @param string $message Scenario being checked
 * @return void
 */
function bookingStatus(array $response, array $allowed, $message)
{
	bookingAssert(in_array($response['status'], $allowed, true),
		$message.': HTTP '.$response['status'].'; '.substr(strip_tags($response['body']), 0, 240));
}

/**
 * Prepare a native HTTP request with an independent client cookie jar.
 *
 * @param string $url Target test URL
 * @param string $method HTTP method
 * @param string|null $body Request body, or null for no body
 * @param string $cookie Cookie-jar filename, or an empty string
 * @param string[] $headers Request headers
 * @param array|null $auth Optional Basic authentication login and password
 * @param array $responseHeaders Response headers collected by reference
 * @return CurlHandle Configured cURL handle
 */
function bookingHandle($url, $method, $body, $cookie, array $headers, $auth, &$responseHeaders)
{
	$handle = curl_init($url);
	$options = array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers,
		CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders) {
			$pair = explode(':', $line, 2);
			if (count($pair) === 2) $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
			return strlen($line);
		});
	if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
	if ($cookie !== '') {
		$options[CURLOPT_COOKIEFILE] = $cookie;
		$options[CURLOPT_COOKIEJAR] = $cookie;
	}
	if ($auth !== null) {
		$options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
		$options[CURLOPT_USERPWD] = $auth[0].':'.$auth[1];
	}
	curl_setopt_array($handle, $options);
	return $handle;
}

/**
 * Execute one real HTTP request against the isolated instance.
 *
 * @param string $url Target test URL
 * @param string $method HTTP method
 * @param string|null $body Request body
 * @param string $cookie Cookie-jar filename, or an empty string
 * @param string[] $headers Request headers
 * @param array|null $auth Optional Basic authentication login and password
 * @return array Response status, body, headers and elapsed seconds
 */
function bookingHttp($url, $method = 'GET', $body = null, $cookie = '', array $headers = array(), $auth = null)
{
	$responseHeaders = array();
	$handle = bookingHandle($url, $method, $body, $cookie, $headers, $auth, $responseHeaders);
	$responseBody = curl_exec($handle);
	if ($responseBody === false) throw new RuntimeException('Booking HTTP transport failed: '.curl_error($handle));
	$result = array('status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $responseBody,
		'headers' => $responseHeaders, 'seconds' => curl_getinfo($handle, CURLINFO_TOTAL_TIME));
	unset($handle); // curl_close() is a no-op on supported PHP and deprecated in 8.5.
	return $result;
}

/**
 * Decode a public JSON response and reject a malformed response shape.
 *
 * @param array $response HTTP response returned by bookingHttp()
 * @return array Decoded response data
 */
function bookingJson(array $response)
{
	$data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
	bookingAssert(is_array($data), 'A JSON booking response is required');
	return $data;
}

/**
 * Fetch fresh availability and session/form tokens as a new client.
 *
 * @param string $key Publication key of the fixture profile
 * @param string $date Selected date in the profile timezone
 * @return array Profile key, date, private cookie jar and public form data
 */
function bookingForm($key, $date)
{
	global $bookingUrl, $cookies;
	$cookie = tempnam(sys_get_temp_dir(), 'cdav-booking-cookie-');
	$cookies[] = $cookie;
	$url = $bookingUrl.'?'.http_build_query(array('key' => $key, 'date' => $date, 'format' => 'json'));
	$response = bookingHttp($url, 'GET', null, $cookie);
	bookingStatus($response, array(200), 'Public slot form');
	$data = bookingJson($response);
	bookingAssert(isset($data['slots'], $data['request'], $data['token']), 'Public form must return slots and request/CSRF tokens');
	return array('key' => $key, 'date' => $date, 'cookie' => $cookie, 'data' => $data);
}

/**
 * Build a form submission, allowing deliberate invalid-input overrides.
 *
 * @param array $form Public form and client state returned by bookingForm()
 * @param int $start Selected Unix timestamp
 * @param array $changes Fields overridden by a scenario
 * @return array Submitted form fields
 */
function bookingFields(array $form, $start, array $changes = array())
{
	global $prefix;
	return array_replace(array('key' => $form['key'], 'date' => $form['date'], 'format' => 'json',
		'token' => $form['data']['token'], 'request' => $form['data']['request'], 'start' => (string) $start,
		'name' => $prefix.' Customer', 'email' => 'booking@example.test', 'phone' => '', 'website' => ''), $changes);
}

/**
 * Submit the form with its own native session and same-origin headers.
 *
 * @param array $form Public form and client state returned by bookingForm()
 * @param int $start Selected Unix timestamp
 * @param array $changes Fields overridden by a scenario
 * @return array HTTP response
 */
function bookingPost(array $form, $start, array $changes = array())
{
	global $bookingUrl;
	$origin = parse_url($bookingUrl, PHP_URL_SCHEME).'://'.parse_url($bookingUrl, PHP_URL_HOST)
		.(parse_url($bookingUrl, PHP_URL_PORT) ? ':'.parse_url($bookingUrl, PHP_URL_PORT) : '');
	return bookingHttp($bookingUrl, 'POST', http_build_query(bookingFields($form, $start, $changes)), $form['cookie'],
		array('Content-Type: application/x-www-form-urlencoded', 'Origin: '.$origin, 'Referer: '.$bookingUrl));
}

/**
 * Hold the native owner lock until independent HTTP workers wait on it.
 *
 * @param array $forms Independent public forms and cookie jars
 * @param int[] $starts Selected timestamps, indexed like the forms
 * @param int $ownerId Owner whose native row provides the transaction barrier
 * @param callable|null $beforeRelease Optional mutation before releasing the barrier
 * @return array HTTP responses in the same order as the submitted forms
 */
function bookingConcurrent(array $forms, array $starts, $ownerId, $beforeRelease = null)
{
	global $db, $bookingUrl, $dolibarr_main_db_name;
	$multi = curl_multi_init();
	$handles = $headers = array();
	$origin = parse_url($bookingUrl, PHP_URL_SCHEME).'://'.parse_url($bookingUrl, PHP_URL_HOST)
		.(parse_url($bookingUrl, PHP_URL_PORT) ? ':'.parse_url($bookingUrl, PHP_URL_PORT) : '');
	bookingAssert($db->begin(), 'Cannot hold the concurrency barrier');
	bookingQuery('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $ownerId).' FOR UPDATE');
	try {
		foreach ($forms as $i => $form) {
			$headers[$i] = array();
			$handles[$i] = bookingHandle($bookingUrl, 'POST', http_build_query(bookingFields($form, $starts[$i])),
				$form['cookie'], array('Content-Type: application/x-www-form-urlencoded', 'Origin: '.$origin,
					'Referer: '.$bookingUrl), null, $headers[$i]);
			curl_multi_add_handle($multi, $handles[$i]);
		}
		$deadline = microtime(true) + 5;
		$waiting = 0;
		do {
			bookingAssert(curl_multi_exec($multi, $running) === CURLM_OK, 'Concurrency transport failed');
			$waiting = 0;
			$list = bookingQuery('SHOW FULL PROCESSLIST');
			while ($row = $db->fetch_object($list)) {
				if (($row->db ?? '') === $dolibarr_main_db_name && strpos((string) ($row->Info ?? ''), 'FOR UPDATE') !== false
					&& strpos((string) $row->Info, MAIN_DB_PREFIX.'user') !== false) $waiting++;
			}
			if ($waiting >= count($forms)) break;
			curl_multi_select($multi, 0.02);
		} while (microtime(true) < $deadline && $running);
		$barrierReached = $waiting >= count($forms);
		if ($beforeRelease !== null) $beforeRelease();
		bookingAssert($db->commit(), 'Cannot release the concurrency barrier');
		do {
			bookingAssert(curl_multi_exec($multi, $running) === CURLM_OK, 'Concurrency transport failed after barrier');
			if ($running) curl_multi_select($multi, 0.05);
		} while ($running);
		$results = array();
		foreach ($handles as $i => $handle) {
			bookingAssert(curl_errno($handle) === 0, 'Concurrent booking request failed');
			$results[] = array('status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
				'body' => curl_multi_getcontent($handle), 'headers' => $headers[$i]);
			curl_multi_remove_handle($multi, $handle);
		}
		bookingAssert($barrierReached, 'At least two independent HTTP workers must reach the DB lock barrier; waiting='
			.$waiting.'; statuses='.json_encode(array_column($results, 'status')));
		return $results;
	} catch (Throwable $e) {
		$db->rollback();
		throw $e;
	} finally {
		unset($handles, $multi);
	}
}

/**
 * Create a tracked Agenda fixture through the native ActionComm API.
 *
 * @param User $owner Native fixture owner and event creator
 * @param int $start Event start timestamp
 * @param int $end Event end timestamp
 * @param string $label Fixture event title
 * @param bool $busy Whether the user's assignment occupies time
 * @param array $recurrence Optional native recurrence rule and end timestamp
 * @return ActionComm Created native event, registered for cleanup
 */
function bookingNativeEvent($owner, $start, $end, $label, $busy = true, array $recurrence = array())
{
	global $db, $events;
	$event = new ActionComm($db);
	$event->type_code = 'AC_RDV';
	$event->label = $label;
	$event->note_private = 'CDav isolated booking QA fixture';
	$event->datep = $start;
	$event->datef = $end;
	$event->percentage = -1;
	$event->priority = 0;
	$event->userownerid = $owner->id;
	$event->transparency = (int) $busy;
	$event->userassigned = array($owner->id => array('id' => $owner->id, 'transparency' => (int) $busy));
	if ($recurrence) {
		$event->recurrule = $recurrence['rule'];
		$event->recurdateend = $recurrence['until'];
	}
	bookingAssert($event->create($owner) > 0, 'Native fixture creation failed: '.$event->error);
	$events[] = $event->id;
	return $event;
}

/**
 * Extract the RFC 6578 token from a successful DAV sync response.
 *
 * @param array $response DAV multistatus response
 * @return string Decoded sync token
 */
function bookingSyncToken(array $response)
{
	bookingStatus($response, array(207), 'RFC 6578 collection sync');
	bookingAssert((bool) preg_match('/<(?:[A-Za-z0-9]+:)?sync-token[^>]*>([^<]+)</', $response['body'], $matches), 'Sync token missing');
	return html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$entity = (int) $conf->entity;
$prefix = 'CDAVBOOK-'.bin2hex(random_bytes(6));
$events = $users = $cookies = $constantSnapshots = array();
$rateSnapshot = array();
$triggerPath = $triggerMarker = '';
$admin = null;
$failure = null;
$initialAgendaId = null;
$initialAutoCount = null;
$started = microtime(true);
$proof = array('dolibarr' => DOL_VERSION, 'php' => PHP_VERSION, 'database' => $dolibarr_main_db_name,
	'url' => preg_replace('/\?.*$/', '', $bookingUrl), 'scenarios' => array());

try {
	bookingAssert(strpos(strtolower($dolibarr_main_db_name), 'test') !== false, 'Refusing a database not explicitly named test');
	$initialAgendaId = (int) bookingRow('SELECT COALESCE(MAX(id),0) AS id FROM '.MAIN_DB_PREFIX.'actioncomm')->id;
	$initialAutoCount = (int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_action IN (SELECT id FROM '
		.MAIN_DB_PREFIX.'c_actioncomm WHERE type = \'systemauto\')')->nb;
	bookingAssert(isModEnabled('agenda') && isModEnabled('cdav'), 'Enable native Agenda and CDav first');
	foreach (array('cdav_booking_profile', 'cdav_booking_request', 'cdav_booking_rate') as $table) {
		bookingAssert((bool) $db->DDLInfoTable(MAIN_DB_PREFIX.$table), 'Missing booking schema: '.$table);
	}
	$adminRow = bookingRow('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1');
	bookingAssert($adminRow, 'A native test administrator is required');
	$admin = new User($db);
	bookingAssert($admin->fetch((int) $adminRow->rowid) > 0 && $admin->loadRights() >= 0, 'Cannot load setup administrator');
	$GLOBALS['user'] = $admin;
	foreach (array('CDAV_BOOKING_ENABLED' => '1', 'CDAV_BOOKING_ALLOW_LOCAL_HTTP' => '1',
		'CDAV_TRUSTED_PROXY_IPS' => '', 'MAIN_SECURITY_CSRF_WITH_TOKEN' => '1') as $name => $value) {
		$constantSnapshots[$name] = bookingRow('SELECT * FROM '.MAIN_DB_PREFIX.'const WHERE name = \''.$db->escape($name).'\' AND entity = '.$entity);
		bookingAssert(dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $entity) > 0, 'Cannot configure booking test option');
	}
	$rates = bookingQuery('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	while ($row = $db->fetch_object($rates)) $rateSnapshot[] = $row;
	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$writeRight = bookingRow("SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'agenda' AND perms = 'myactions' AND subperms = 'create' LIMIT 1");
	bookingAssert($writeRight, 'Native Agenda create permission missing');
	$owners = array();
	foreach (array('owner', 'other') as $role) {
		$owner = new User($db);
		$owner->entity = $entity;
		$owner->login = strtolower(str_replace('-', '', $prefix)).$role;
		$owner->lastname = $prefix.' '.$role;
		$owner->firstname = 'Booking';
		$owner->email = $role.'@example.test';
		$owner->admin = 0;
		$owner->employee = 1;
		$owner->statut = $owner->status = 1;
		$owner->lang = 'fr_FR';
		bookingAssert($owner->create($admin) > 0, 'Cannot create non-admin booking owner');
		$users[] = $owner->id;
		foreach (array('read', 'create', 'delete') as $permission) {
			$right = bookingRow("SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = 'agenda' AND perms = 'myactions' AND subperms = '".$permission."' LIMIT 1");
			bookingAssert($right && $owner->addrights((int) $right->id, '', '', $entity) > 0, 'Cannot grant own-agenda permission');
		}
		$owner->loadRights('', 1);
		bookingAssert($owner->hasRight('agenda', 'myactions', 'write') && !$owner->hasRight('agenda', 'allactions', 'read'), 'Owner rights must remain limited');
		$owners[$role] = $owner;
	}
	$owner = $owners['owner'];
	$password = 'Booking!'.bin2hex(random_bytes(12)).'7aA';
	bookingAssert($owner->setPassword($admin, $password, 0, 0, 1) !== -1, 'Cannot configure DAV test password');
	$config = array('timezone' => 'Europe/Zurich', 'duration' => 30, 'step' => 30, 'buffer_before' => 0,
		'buffer_after' => 0, 'notice' => 0, 'horizon' => 60, 'weekly' => array_fill(1, 7, array(array('09:00', '17:00'))));
	$service = new BookingService($db, $entity);
	$profile = $service->saveProfile($owner->id, $config, true);
	$key = $service->rotateToken($owner->id);
	$otherProfile = $service->saveProfile($owners['other']->id, $config, true);
	$otherKey = $service->rotateToken($owners['other']->id);
	$zone = new DateTimeZone('Europe/Zurich');
	$day = (new DateTimeImmutable('now', $zone))->modify('+2 days')->setTime(0, 0);
	$date = $day->format('Y-m-d');
	$at = static function ($days, $hour, $minute = 0) use ($day) {
		return $day->modify('+'.$days.' days')->setTime($hour, $minute)->getTimestamp(); };
	$sentinel = $prefix.'-PRIVATE-SENTINEL';
	$privateEvent = bookingNativeEvent($owner, $at(0, 14), $at(0, 15), $sentinel);
	$privateEvent->note_private = $sentinel.' notes';
	$privateEvent->location = $sentinel.' location';
	bookingAssert($privateEvent->update($admin) >= 0, 'Cannot set private native event data');
	$fixtureRow = bookingRow('SELECT a.datep,a.datep2,a.percent,a.entity,a.code,ar.transparency FROM '.MAIN_DB_PREFIX.'actioncomm a INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_resources ar ON ar.fk_actioncomm = a.id WHERE a.id = '.((int) $privateEvent->id));
	bookingAssert($fixtureRow && (int) $fixtureRow->percent === -1 && (int) $fixtureRow->transparency === 1
		&& $db->jdate($fixtureRow->datep) === $at(0, 14), 'Native busy fixture is inconsistent: '.json_encode($fixtureRow));
	$form = bookingForm($key, $date);
	foreach ($form['data']['slots'] as $slot) bookingAssert($slot['end'] <= $at(0, 14) || $slot['start'] >= $at(0, 15),
		'A manual Dolibarr event must block every overlap: expected busy '.gmdate('c', $at(0, 14)).'–'.gmdate('c', $at(0, 15))
		.'; offered '.gmdate('c', $slot['start']).'–'.gmdate('c', $slot['end']).'; fixture '.json_encode($fixtureRow));
	bookingAssert(strpos(json_encode($form['data']), $sentinel) === false, 'Private data leaked in slot JSON');
	$html = bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $key, 'date' => $date)));
	bookingStatus($html, array(200), 'Public HTML form');
	bookingAssert(strpos($html['body'], $sentinel) === false, 'Private data leaked in public HTML');
	foreach (array('cache-control' => 'no-store', 'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff') as $header => $value) {
		bookingAssert(strpos(strtolower($html['headers'][$header] ?? ''), $value) !== false, 'Missing public privacy header '.$header);
	}
	bookingOk('manual native occupation and public HTML/JSON privacy (A01, S04, S06)');
	$proof['scenarios'][] = 'A01/S04/S06';

	$transportUrl = $bookingUrl.'?'.http_build_query(array('key' => $key, 'date' => $date, 'format' => 'json'));
	dolibarr_set_const($db, 'CDAV_BOOKING_ALLOW_LOCAL_HTTP', '0', 'chaine', 0, '', $entity);
	bookingStatus(bookingHttp($transportUrl), array(403), 'Loopback HTTP requires an explicit test flag');
	bookingStatus(bookingHttp($transportUrl, 'GET', null, '', array('X-Forwarded-Proto: https')), array(403), 'Untrusted HTTPS proxy header');
	dolibarr_set_const($db, 'CDAV_TRUSTED_PROXY_IPS', '127.0.0.1', 'chaine', 0, '', $entity);
	bookingStatus(bookingHttp($transportUrl, 'GET', null, '', array('X-Forwarded-Proto: https')), array(200), 'Explicit trusted proxy HTTPS');
	bookingStatus(bookingHttp($transportUrl, 'GET', null, '', array('X-Forwarded-Proto: http')), array(403), 'Trusted proxy still requires HTTPS');
	dolibarr_set_const($db, 'CDAV_TRUSTED_PROXY_IPS', '', 'chaine', 0, '', $entity);
	dolibarr_set_const($db, 'CDAV_BOOKING_ALLOW_LOCAL_HTTP', '1', 'chaine', 0, '', $entity);
	bookingOk('explicit local HTTP opt-in and trusted/untrusted HTTPS proxy headers (S06)');

	$davBase = preg_replace('#public/booking\.php$#', 'server.php/', $bookingUrl);
	$calendarUrl = $davBase.'calendars/'.rawurlencode($owner->login).'/'.rawurlencode($owner->id.'-cal-'.$owner->login).'/';
	$syncBody = '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>';
	$auth = array($owner->login, $password);
	$initialSync = bookingHttp($calendarUrl, 'REPORT', $syncBody, '', array('Depth: 0', 'Content-Type: application/xml'), $auth);
	$initialToken = bookingSyncToken($initialSync);
	$start = $form['data']['slots'][0]['start'];
	$before = bookingCountEvents($owner->id);
	$created = bookingPost($form, $start);
	bookingStatus($created, array(201), 'First public booking');
	$receipt = bookingJson($created);
	bookingAssert(isset($receipt['reference']) && !isset($receipt['id']) && !isset($receipt['owner_id']), 'Receipt must expose no internal IDs');
	$native = bookingRow('SELECT r.fk_actioncomm, a.datep, a.datep2, ar.transparency FROM '.MAIN_DB_PREFIX.'cdav_booking_request r INNER JOIN '
		.MAIN_DB_PREFIX.'actioncomm a ON a.id = r.fk_actioncomm INNER JOIN '
		.MAIN_DB_PREFIX."actioncomm_resources ar ON ar.fk_actioncomm = a.id AND ar.element_type = 'user' WHERE r.reference = '"
		.$db->escape($receipt['reference']).'\' AND ar.fk_element = '.$owner->id);
	bookingAssert($native && (int) $native->transparency === 1 && $db->jdate($native->datep) === $start
		&& $db->jdate($native->datep2) === $start + 1800 && bookingCountEvents($owner->id) === $before + 1,
		'Public booking must create one native occupied event with correct assignment and dates');
	$events[] = $native->fk_actioncomm;
	$deltaBody = str_replace('<d:sync-token/>', '<d:sync-token>'.htmlspecialchars($initialToken, ENT_XML1).'</d:sync-token>', $syncBody);
	$delta = bookingHttp($calendarUrl, 'REPORT', $deltaBody, '', array('Depth: 0', 'Content-Type: application/xml'), $auth);
	bookingSyncToken($delta);
	bookingAssert(strpos($delta['body'], $native->fk_actioncomm.'-ev-') !== false, 'Booking missing from RFC 6578 delta');
	$calendarRead = bookingHttp($calendarUrl, 'REPORT', '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter></c:calendar-query>', '', array('Depth: 1', 'Content-Type: application/xml'), $auth);
	bookingStatus($calendarRead, array(207), 'CalDAV read-back');
	bookingAssert(strpos($calendarRead['body'], $prefix) !== false, 'Native booking missing from CalDAV calendar data');
	bookingOk('native ActionComm assignment, dates, CalDAV read-back and incremental sync (B01)');
	$proof['scenarios'][] = 'B01';

	$retry = bookingPost($form, $start);
	bookingStatus($retry, array(200), 'Idempotent replay');
	bookingAssert(bookingJson($retry)['reference'] === $receipt['reference'] && bookingCountEvents($owner->id) === $before + 1, 'Replay must keep the same receipt and event');
	bookingStatus(bookingPost($form, $start, array('name' => $prefix.' changed')), array(409), 'Idempotency key with different content');
	$expired = $service->requestTicket($service->publicProfile($key), time() - BookingService::REQUEST_TTL - 1);
	bookingStatus(bookingPost($form, $start, array('request' => $expired)), array(409), 'Expired signed request');
	bookingAssert(bookingCountEvents($owner->id) === $before + 1, 'Rejected replays must not create events');
	bookingOk('replay, divergent body and signed expiry (B05)');
	$proof['scenarios'][] = 'B05';

	$stale = bookingForm($key, $day->modify('+1 day')->format('Y-m-d'));
	$staleStart = $stale['data']['slots'][0]['start'];
	$newBusy = bookingNativeEvent($owner, $staleStart + 600, $staleStart + 2400, $prefix.' late manual occupation');
	bookingStatus(bookingPost($stale, $staleStart), array(409), 'Stale public form after native agenda write');
	bookingAssert($newBusy->delete($admin) > 0, 'Cannot remove native manual fixture');
	$reopened = bookingForm($key, $stale['date']);
	bookingAssert(in_array($staleStart, array_column($reopened['data']['slots'], 'start'), true), 'Native deletion must reopen the slot');
	bookingOk('stale availability rejected and native deletion reopens slots (B02)');
	$proof['scenarios'][] = 'B02';

	// A DAV write committed before confirmation blocks a stale public form. DAV
	// itself still permits overlaps after a public reservation: document the boundary.
	$davDate = $day->modify('+7 days')->format('Y-m-d');
	$davForm = bookingForm($key, $davDate);
	$davStart = $davForm['data']['slots'][0]['start'];
	$ics = static function ($uid, $start, $end) {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:".$uid."\r\nDTSTAMP:".gmdate('Ymd\THis\Z')
			."\r\nDTSTART:".gmdate('Ymd\THis\Z', $start)."\r\nDTEND:".gmdate('Ymd\THis\Z', $end)
			."\r\nSUMMARY:Booking DAV conflict fixture\r\nTRANSP:OPAQUE\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	};
	bookingStatus(bookingHttp($calendarUrl.rawurlencode($prefix.'-dav-before.ics'), 'PUT',
		$ics($prefix.'-dav-before', $davStart, $davStart + 1800), '', array('Content-Type: text/calendar', 'If-None-Match: *'), $auth), array(201), 'DAV occupation before public confirmation');
	bookingStatus(bookingPost($davForm, $davStart), array(409), 'Stale form after committed DAV write');
	$davAfterForm = bookingForm($key, $day->modify('+8 days')->format('Y-m-d'));
	$davAfterStart = $davAfterForm['data']['slots'][0]['start'];
	bookingStatus(bookingPost($davAfterForm, $davAfterStart), array(201), 'Public booking before DAV overlap');
	bookingStatus(bookingHttp($calendarUrl.rawurlencode($prefix.'-dav-after.ics'), 'PUT',
		$ics($prefix.'-dav-after', $davAfterStart, $davAfterStart + 1800), '', array('Content-Type: text/calendar', 'If-None-Match: *'), $auth), array(201), 'DAV intentionally permits a later overlap');
	bookingOk('DAV/public writes in both orders and explicit overlap-policy boundary (B02, B04)');

	// Per-occurrence occupancy must survive a transparent native series master.
	$seriesCard = static function (
		$uid,
		DateTimeImmutable $master,
		$rule,
		$masterTransparency,
		DateTimeImmutable $recurrenceId,
		DateTimeImmutable $exception,
		$exceptionTransparency
) {
		$stamp = gmdate('Ymd\THis\Z');
		$dates = static function (DateTimeImmutable $start) {
			return 'DTSTART;TZID=Europe/Zurich:'.$start->format('Ymd\THis')."\r\n"
				.'DTEND;TZID=Europe/Zurich:'.$start->modify('+1 hour')->format('Ymd\THis')."\r\n";
		};
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:".$uid."\r\nDTSTAMP:".$stamp."\r\n"
			.$dates($master).'RRULE:'.$rule."\r\nTRANSP:".$masterTransparency."\r\nSUMMARY:Recurrence master\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:"
			.$uid."\r\nDTSTAMP:".$stamp."\r\nRECURRENCE-ID;TZID=Europe/Zurich:"
			.$recurrenceId->format('Ymd\THis')."\r\n".$dates($exception).'TRANSP:'.$exceptionTransparency
			."\r\nSUMMARY:Recurrence exception\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	};
	$busyException = $day->modify('+20 days')->setTime(14, 0);
	$oldMaster = $day->modify('-70 days')->setTime(14, 0);
	bookingStatus(bookingHttp($calendarUrl.rawurlencode($prefix.'-free-master.ics'), 'PUT',
		$seriesCard($prefix.'-free-master', $oldMaster, 'FREQ=DAILY;COUNT=120', 'TRANSPARENT', $busyException, $busyException, 'OPAQUE'),
		'', array('Content-Type: text/calendar', 'If-None-Match: *'), $auth), array(201), 'Transparent master with occupied exception');
	$seriesRead = bookingHttp($calendarUrl.rawurlencode($prefix.'-free-master.ics'), 'GET', null, '', array(), $auth);
	bookingStatus($seriesRead, array(200), 'Advanced recurrence CalDAV round trip');
	$roundTripCalendar = Sabre\VObject\Reader::read($seriesRead['body']);
	bookingAssert(isset($roundTripCalendar->VEVENT->RRULE)
		&& strpos((string) $roundTripCalendar->VEVENT->RRULE, 'COUNT=120') !== false,
		'Advanced COUNT recurrence must survive CalDAV export without a native projection');
	$roundTripCalendar->destroy();
	$exceptionForm = bookingForm($key, $busyException->format('Y-m-d'));
	bookingAssert(in_array($busyException->setTime(9, 0)->getTimestamp(), array_column($exceptionForm['data']['slots'], 'start'), true), 'An occupied exception must leave unrelated morning slots available');
	foreach ($exceptionForm['data']['slots'] as $slot) bookingAssert($slot['end'] <= $busyException->getTimestamp()
		|| $slot['start'] >= $busyException->modify('+1 hour')->getTimestamp(), 'An occupied exception of a free master must block slots');
	$ordinaryFree = bookingForm($key, $busyException->modify('-1 day')->format('Y-m-d'));
	bookingAssert(in_array($busyException->modify('-1 day')->getTimestamp(), array_column($ordinaryFree['data']['slots'], 'start'), true), 'A free master must remain free outside its occupied exception');
	$freeException = $day->modify('+21 days')->setTime(12, 0);
	bookingStatus(bookingHttp($calendarUrl.rawurlencode($prefix.'-busy-master.ics'), 'PUT',
		$seriesCard($prefix.'-busy-master', $day->modify('-70 days')->setTime(12, 0), 'FREQ=DAILY;COUNT=120', 'OPAQUE', $freeException, $freeException, 'TRANSPARENT'),
		'', array('Content-Type: text/calendar', 'If-None-Match: *'), $auth), array(201), 'Occupied master with transparent exception');
	$seriesRead = bookingHttp($calendarUrl.rawurlencode($prefix.'-busy-master.ics'), 'GET', null, '', array(), $auth);
	bookingStatus($seriesRead, array(200), 'Occupied advanced recurrence CalDAV round trip');
	$roundTripCalendar = Sabre\VObject\Reader::read($seriesRead['body']);
	bookingAssert(isset($roundTripCalendar->VEVENT->RRULE)
		&& strpos((string) $roundTripCalendar->VEVENT->RRULE, 'COUNT=120') !== false,
		'Occupied master COUNT must survive CalDAV export');
	$roundTripCalendar->destroy();
	$exceptionForm = bookingForm($key, $freeException->format('Y-m-d'));
	$availableStarts = array_column($exceptionForm['data']['slots'], 'start');
	bookingAssert(in_array($freeException->getTimestamp(), $availableStarts, true)
		&& in_array($freeException->modify('+30 minutes')->getTimestamp(), $availableStarts, true), 'A transparent exception must reopen its master occupation');
	$ordinaryBusy = bookingForm($key, $freeException->modify('+2 days')->format('Y-m-d'));
	if (in_array($freeException->modify('+2 days')->getTimestamp(), array_column($ordinaryBusy['data']['slots'], 'start'), true)) {
		$diagnostic = bookingHttp($calendarUrl.rawurlencode($prefix.'-busy-master.ics'), 'GET', null, '', array(), $auth);
		$nativeMaster = bookingRow('SELECT a.datep,a.datep2,a.percent,a.transparency,ar.transparency AS assigned_transparency FROM '
			.MAIN_DB_PREFIX.'actioncomm a INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav ac ON ac.fk_object = a.id INNER JOIN '
			.MAIN_DB_PREFIX."actioncomm_resources ar ON ar.fk_actioncomm = a.id AND ar.element_type='user' WHERE ac.uuidext = '"
			.$db->escape($prefix.'-busy-master.ics').'\'');
		throw new RuntimeException('An occupied master must still block its ordinary occurrences; native='
			.json_encode($nativeMaster).'; exported='.substr($diagnostic['body'], 0, 3000));
	}
	$movedException = $day->modify('+22 days')->setTime(16, 0);
	$endedMaster = $day->modify('-70 days')->setTime(16, 0);
	bookingStatus(bookingHttp($calendarUrl.rawurlencode($prefix.'-moved-old-series.ics'), 'PUT',
		$seriesCard($prefix.'-moved-old-series', $endedMaster, 'FREQ=DAILY;COUNT=2', 'OPAQUE', $endedMaster->modify('+1 day'), $movedException, 'OPAQUE'),
		'', array('Content-Type: text/calendar', 'If-None-Match: *'), $auth), array(201), 'Old ended master with moved future exception');
	$exceptionForm = bookingForm($key, $movedException->format('Y-m-d'));
	bookingAssert(in_array($movedException->setTime(9, 0)->getTimestamp(), array_column($exceptionForm['data']['slots'], 'start'), true), 'A moved exception must leave unrelated slots available');
	foreach ($exceptionForm['data']['slots'] as $slot) bookingAssert($slot['end'] <= $movedException->getTimestamp()
		|| $slot['start'] >= $movedException->modify('+1 hour')->getTimestamp(), 'A moved exception must block even when its master ended outside the sync window');
	bookingOk('CalDAV free/busy exceptions and moved occurrences of old masters (A04, A07)');
	$proof['scenarios'][] = 'A04/A07';

	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$concurrentDate = $day->modify('+2 days')->format('Y-m-d');
	$left = bookingForm($key, $concurrentDate);
	$right = bookingForm($key, $concurrentDate);
	$concurrentStart = $left['data']['slots'][0]['start'];
	$before = bookingCountEvents($owner->id);
	$race = bookingConcurrent(array($left, $right), array($concurrentStart, $concurrentStart), $owner->id);
	$statuses = array_column($race, 'status');
	sort($statuses);
	bookingAssert($statuses === array(201, 409) && bookingCountEvents($owner->id) === $before + 1, 'Exactly one simultaneous public request must win');
	$longConfig = array_replace($config, array('duration' => 60));
	$service->saveProfile($owner->id, $longConfig, true);
	$overlapDate = $day->modify('+3 days')->format('Y-m-d');
	$left = bookingForm($key, $overlapDate);
	$right = bookingForm($key, $overlapDate);
	$before = bookingCountEvents($owner->id);
	$race = bookingConcurrent(array($left, $right), array($left['data']['slots'][0]['start'], $right['data']['slots'][1]['start']), $owner->id);
	$statuses = array_column($race, 'status');
	sort($statuses);
	bookingAssert($statuses === array(201, 409) && bookingCountEvents($owner->id) === $before + 1, 'Different starts that overlap must still have one winner');
	$service->saveProfile($owner->id, $config, true);
	$distinctDate = $day->modify('+4 days')->format('Y-m-d');
	$left = bookingForm($key, $distinctDate);
	$right = bookingForm($key, $distinctDate);
	$race = bookingConcurrent(array($left, $right), array($left['data']['slots'][0]['start'], $right['data']['slots'][2]['start']), $owner->id);
	bookingAssert(array_column($race, 'status') === array(201, 201), 'Independent slots must both remain reservable');
	$other = bookingForm($otherKey, $concurrentDate);
	bookingStatus(bookingPost($other, $concurrentStart), array(201), 'A different owner remains independent');
	$replayDate = $day->modify('+9 days')->format('Y-m-d');
	$left = bookingForm($key, $replayDate);
	$right = bookingForm($key, $replayDate);
	$right['data']['request'] = $left['data']['request'];
	$before = bookingCountEvents($owner->id);
	$race = bookingConcurrent(array($left, $right), array($left['data']['slots'][0]['start'], $right['data']['slots'][0]['start']), $owner->id);
	$statuses = array_column($race, 'status');
	sort($statuses);
	bookingAssert($statuses === array(200, 201) && bookingCountEvents($owner->id) === $before + 1
		&& bookingJson($race[0])['reference'] === bookingJson($race[1])['reference'], 'Simultaneous retries must share one event and receipt');
	bookingOk('real HTTP/DB barrier: same slot, partial overlap, distinct slots and owners (B03, B04)');
	$proof['scenarios'][] = 'B03/B04';
	$guarded = bookingForm($key, $day->modify('+10 days')->format('Y-m-d'));
	$before = bookingCountEvents($owner->id);
	$race = bookingConcurrent(array($guarded), array($guarded['data']['slots'][0]['start']), $owner->id,
		static function () use ($db, $entity) {
			bookingAssert(dolibarr_set_const($db, 'CDAV_BOOKING_ENABLED', '0', 'chaine', 0, '', $entity) > 0,
				'Cannot disable booking while its confirmation waits on the owner lock');
		});
	bookingStatus($race[0], array(404), 'Feature disabled while request waits on native owner lock');
	bookingAssert(bookingCountEvents($owner->id) === $before, 'Feature disabled before final control must prevent the write');
	dolibarr_set_const($db, 'CDAV_BOOKING_ENABLED', '1', 'chaine', 0, '', $entity);
	bookingOk('concurrent replay and global revocation between initial and final checks (B05, B06)');

	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	foreach (array(array(0, 15), array(15, 0)) as $index => $buffers) {
		$bufferConfig = array_replace($config, array('step' => 15, 'buffer_before' => $buffers[0], 'buffer_after' => $buffers[1]));
		$service->saveProfile($owner->id, $bufferConfig, true);
		$earlyDate = $day->modify('+'.(11 + $index * 2).' days')->format('Y-m-d');
		$early = bookingForm($key, $earlyDate);
		$firstStart = $early['data']['slots'][0]['start'];
		bookingStatus(bookingPost($early, $firstStart), array(201), 'First booking with asymmetric buffer');
		$after = bookingForm($key, $earlyDate);
		bookingAssert(!in_array($firstStart + 1800, array_column($after['data']['slots'], 'start'), true)
			&& $after['data']['slots'][0]['start'] >= $firstStart + 2700,
			'Asymmetric margins must leave at least 15 minutes after an earlier booking');
		$lateDate = $day->modify('+'.(12 + $index * 2).' days')->format('Y-m-d');
		$late = bookingForm($key, $lateDate);
		$opening = $late['data']['slots'][0]['start'];
		$laterStart = $opening + 3600;
		bookingStatus(bookingPost($late, $laterStart), array(201), 'Later booking created first with asymmetric buffer');
		$after = bookingForm($key, $lateDate);
		$availableStarts = array_column($after['data']['slots'], 'start');
		bookingAssert(!in_array($laterStart - 1800, $availableStarts, true)
			&& !in_array($laterStart + 1800, $availableStarts, true)
			&& in_array($laterStart - 2700, $availableStarts, true)
			&& in_array($laterStart + 2700, $availableStarts, true),
			'Asymmetric margins must work in both creation orders and permit the exact 15-minute gap');
	}
	$service->saveProfile($owner->id, $config, true);
	bookingOk('before-only and after-only margins in both creation orders (A02)');
	$proof['scenarios'][] = 'A02';

	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$badDate = $day->modify('+5 days')->format('Y-m-d');
	$bad = bookingForm($key, $badDate);
	$badStart = $bad['data']['slots'][0]['start'];
	$before = bookingCountEvents($owner->id);
	bookingStatus(bookingPost($bad, $badStart, array('owner_id' => $owners['other']->id)), array(422), 'Forged owner');
	bookingStatus(bookingPost($bad, $badStart, array('entity' => $entity + 1000)), array(422), 'Forged entity');
	bookingStatus(bookingPost($bad, $badStart, array('duration' => '1')), array(422), 'Forged duration');
	bookingStatus(bookingPost($bad, $badStart, array('name' => '<script>alert(1)</script>')), array(403, 422), 'Native or module rejection of hostile HTML');
	bookingStatus(bookingPost($bad, $badStart, array('name' => str_repeat('x', 121))), array(422), 'Oversized name');
	bookingStatus(bookingPost($bad, $badStart, array('email' => 'bad-email')), array(422), 'Invalid email');
	bookingStatus(bookingPost($bad, $badStart, array('website' => 'https://spam.example.test')), array(422), 'Honeypot submission');
	$csrfFailure = bookingPost($bad, $badStart, array('token' => 'forged'));
	bookingStatus($csrfFailure, array(403), 'Native CSRF mismatch');
	bookingAssert(isset(bookingJson($csrfFailure)['error']), 'Native CSRF stripping must preserve a safe JSON error response');
	bookingStatus(bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $key, 'format' => 'json')), 'PUT', ''), array(403, 405), 'Unsupported method');
	bookingStatus(bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $key, 'date' => '2099-01-01', 'format' => 'json'))), array(422), 'Unbounded booking horizon');
	bookingStatus(bookingHttp($bookingUrl, 'POST', str_repeat('x', 8200), '', array('Content-Type: application/x-www-form-urlencoded')), array(413), 'Oversized request');
	bookingAssert(bookingCountEvents($owner->id) === $before, 'Rejected input or GET must not create a business event');
	$crossEntity = new BookingService($db, $entity + 1000);
	try { $crossEntity->publicProfile($key); throw new RuntimeException('Cross-entity publication key accepted'); } catch (BookingException $e) { bookingAssert($e->getCode() === 404, 'Cross-entity key must be hidden'); }
	bookingOk('server-owned profile, native CSRF, malicious inputs and resource limits (S02, S03, S05, S06)');
	$proof['scenarios'][] = 'S02/S03/S05/S06';

	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$disabledForm = bookingForm($key, $badDate);
	bookingStatus(bookingHttp($bookingUrl.'?format=json'), array(404), 'Absent publication key');
	bookingStatus(bookingHttp($bookingUrl.'?key=invalid&format=json'), array(404), 'Invalid publication key');
	bookingAssert(dolibarr_set_const($db, 'CDAV_BOOKING_ENABLED', '0', 'chaine', 0, '', $entity) > 0, 'Cannot disable public booking');
	bookingStatus(bookingPost($disabledForm, $badStart), array(404), 'Booking disabled after form display');
	dolibarr_set_const($db, 'CDAV_BOOKING_ENABLED', '1', 'chaine', 0, '', $entity);
	$owner->setstatus(0);
	bookingStatus(bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $key, 'date' => $badDate, 'format' => 'json'))), array(404), 'Inactive native owner');
	$owner->setstatus(1);
	bookingAssert($owner->delrights((int) $writeRight->id, '', '', $entity) > 0, 'Cannot revoke native owner right');
	bookingStatus(bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $key, 'date' => $badDate, 'format' => 'json'))), array(404), 'Native Agenda write right revoked');
	$owner->addrights((int) $writeRight->id, '', '', $entity);
	$rotated = $service->rotateToken($owner->id);
	bookingStatus(bookingPost($disabledForm, $badStart), array(404), 'Rotated publication key');
	$key = $rotated;
	$service->disableProfile($owners['other']->id);
	bookingStatus(bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $otherKey, 'format' => 'json'))), array(404), 'Revoked second profile');
	bookingOk('feature, native rights, inactive owner, rotation and revocation (S01, B06)');
	$proof['scenarios'][] = 'S01/B06';

	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$triggerClass = 'InterfaceCdavBookingQa'.substr(hash('sha256', $prefix), 0, 12);
	$triggerPath = DOL_DOCUMENT_ROOT.'/core/triggers/interface_99_modCDav_'.substr($triggerClass, 9).'.class.php';
	$triggerMarker = tempnam(sys_get_temp_dir(), 'cdav-booking-trigger-');
	bookingAssert(!file_exists($triggerPath) && is_writable(dirname($triggerPath)), 'A writable isolated native trigger directory is required');
	$rollbackName = $prefix.' ROLLBACK';
	$fixture = '<?php require_once DOL_DOCUMENT_ROOT."/core/triggers/dolibarrtriggers.class.php";class '
		.$triggerClass.' extends DolibarrTriggers {public function runTrigger($action,$object,$user,$langs,$conf) {if($action === "ACTION_CREATE" && ($object->label ?? "") === '
		.var_export('RDV — '.$rollbackName, true).') {file_put_contents('.var_export($triggerMarker, true)
		.', "native rollback trigger reached");$this->error="Deliberate isolated booking test rollback";return -1;}return 0;}}';
	bookingAssert(file_put_contents($triggerPath, $fixture) !== false, 'Cannot install temporary native fault fixture');
	$faultForm = bookingForm($key, $badDate);
	$before = bookingCountEvents($owner->id);
	$requestBefore = (int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request WHERE fk_profile = '.$profile['id'])->nb;
	bookingStatus(bookingPost($faultForm, $badStart, array('name' => $rollbackName)), array(503), 'Native trigger rejection');
	bookingAssert(strpos((string) file_get_contents($triggerMarker), 'native rollback trigger reached') !== false, 'Fault fixture did not run');
	bookingAssert(bookingCountEvents($owner->id) === $before && (int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request WHERE fk_profile = '.$profile['id'])->nb === $requestBefore,
		'Native trigger failure must roll back event, resources and receipt');
	unlink($triggerPath);
	$triggerPath = '';
	bookingStatus(bookingPost($faultForm, $badStart, array('name' => $rollbackName)), array(201), 'A rolled-back request may be retried');
	bookingOk('real native trigger failure rolls back event/resources/receipt (B06)');

	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$limited = bookingForm($key, $day->modify('+6 days')->format('Y-m-d'));
	$statuses = array();
	for ($i = 0; $i < 21; $i++) $statuses[] = bookingPost($limited, $limited['data']['slots'][0]['start'], array('request' => 'malformed'))['status'];
	bookingAssert($statuses[0] === 409 && $statuses[20] === 429, 'The public write limit must work across requests');
	bookingOk('public multi-request rate limiting (S05)');

	bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$profileBefore = $service->profileForOwner($owner->id);
	for ($i = 0; $i < 2; $i++) {
		$off = unActivateModule('modCDav', 0);
		bookingAssert(empty($off['errors']), 'Native module disable failed');
		bookingStatus(bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $key, 'format' => 'json'))), array(404), 'Module disabled');
		$on = activateModule('modCDav', 1, 1);
		bookingAssert(empty($on['errors']), 'Native module reactivation failed');
	}
	bookingAssert($service->profileForOwner($owner->id) === $profileBefore, 'Module reactivation changed the profile');
	bookingForm($key, $badDate);
	bookingStatus(bookingHttp($bookingUrl.'?'.http_build_query(array('key' => $otherKey, 'format' => 'json'))), array(404), 'Reactivation must not revive revoked keys');
	bookingOk('native disable/enable cycle replay and revoked-key retention (R02)');
	$proof['scenarios'][] = 'R02';

	if (getenv('CDAV_BOOKING_BENCHMARK') === '1') {
		bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
		$fixtureStart = microtime(true);
		$benchOrigin = $day->modify('-2 days');
		for ($i = 0; $i < 1000; $i++) {
			$eventStart = $benchOrigin->modify('+'.($i % 60).' days')->setTime(18, intdiv($i % 120, 2) % 60)->getTimestamp();
			bookingNativeEvent($owner, $eventStart, $eventStart + 600, $prefix.' BENCH event '.$i);
		}
		for ($i = 0; $i < 100; $i++) {
			$seriesStart = $benchOrigin->modify('-60 days')->setTime(20, $i % 60)->getTimestamp();
			bookingNativeEvent($owner, $seriesStart, $seriesStart + 600, $prefix.' BENCH series '.$i, true,
				array('rule' => 'FREQ=DAILY', 'until' => $benchOrigin->modify('+60 days')->getTimestamp()));
		}
		$singleCount = (int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE label LIKE \'%'
			.$db->escape($prefix.' BENCH event ').'%\'')->nb;
		$seriesCount = (int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE label LIKE \'%'
			.$db->escape($prefix.' BENCH series ').'%\' AND COALESCE(recurrule,\'\') <> \'\'')->nb;
		bookingAssert($singleCount === 1000 && $seriesCount === 100, 'Benchmark fixtures must persist 1000 native events and 100 native recurrence masters');
		$fixtureSeconds = microtime(true) - $fixtureStart;
		$benchDate = $day->modify('+30 days')->format('Y-m-d');
		$benchUrl = $bookingUrl.'?'.http_build_query(array('key' => $key, 'date' => $benchDate, 'format' => 'json'));
		$latencies = array();
		for ($i = 0; $i < 21; $i++) {
			$response = bookingHttp($benchUrl);
			bookingStatus($response, array(200), 'Native-data HTTP benchmark');
			if ($i > 0) $latencies[] = $response['seconds']; // One warm-up; 20 measured requests.
		}
		sort($latencies);
		$profile = $service->publicProfile($key);
		$benchDay = new DateTimeImmutable($benchDate, $zone);
		if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
		$memoryBefore = memory_get_usage(true);
		$queriesBefore = (int) bookingRow("SHOW SESSION STATUS LIKE 'Questions'")->Value;
		$serviceStart = microtime(true);
		$service->slots($profile, $benchDay->getTimestamp(), $benchDay->modify('+1 day')->getTimestamp());
		$serviceSeconds = microtime(true) - $serviceStart;
		$queriesAfter = (int) bookingRow("SHOW SESSION STATUS LIKE 'Questions'")->Value;
		$proof['benchmark'] = array('native_events' => 1000, 'native_recurring_series' => 100,
			'profile_horizon_days' => 60, 'http_query_days' => 1, 'http_concurrency' => 1,
			'http_samples' => count($latencies), 'http_p95_seconds' => $latencies[(int) ceil(count($latencies) * .95) - 1],
			'http_max_seconds' => max($latencies), 'fixture_creation_seconds' => round($fixtureSeconds, 3),
			'cli_service_seconds' => round($serviceSeconds, 6), 'cli_service_sql_queries' => $queriesAfter - $queriesBefore - 1,
			'cli_service_peak_bytes' => memory_get_peak_usage(true), 'cli_service_peak_delta_bytes' => memory_get_peak_usage(true) - $memoryBefore);
		bookingAssert($proof['benchmark']['http_p95_seconds'] <= 1.0, 'Public availability p95 exceeds the 1 s reference budget');
		bookingOk('native DB + HTTP benchmark: 1000 events, 100 old recurring series, horizon 60 days (R03)');
		$proof['scenarios'][] = 'R03';
	}
} catch (Throwable $e) {
	$failure = $e;
} finally {
	try {
		if ($triggerPath !== '' && is_file($triggerPath)) unlink($triggerPath);
		if ($triggerMarker !== '' && is_file($triggerMarker)) unlink($triggerMarker);
		if ($admin) {
			$GLOBALS['user'] = $admin;
			if ($db->transaction_opened) $db->rollback();
			if ($users) {
				$ids = implode(',', array_map('intval', $users));
				$allEvents = bookingQuery('SELECT DISTINCT a.id FROM '.MAIN_DB_PREFIX.'actioncomm a LEFT JOIN '.MAIN_DB_PREFIX
					.'actioncomm_resources ar ON ar.fk_actioncomm = a.id WHERE a.fk_user_author IN ('.$ids.') OR (ar.element_type = \'user\' AND ar.fk_element IN ('.$ids.'))');
				while ($row = $db->fetch_object($allEvents)) $events[] = (int) $row->id;
			}
			foreach (array_unique(array_map('intval', $events)) as $id) {
				$event = new ActionComm($db);
				if ($event->fetch($id) > 0) bookingAssert($event->delete($admin) > 0, 'Native event cleanup failed');
				bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$id);
			}
			foreach ($users as $id) {
				$cleanup = new User($db);
				if ($cleanup->fetch($id) > 0) bookingAssert($cleanup->delete($admin) > 0, 'Native user cleanup failed');
			}
			(new \Dolibarr\CDav\SyncStore($db, $entity))->pruneOrphanCollections();
			foreach ($constantSnapshots as $name => $snapshot) {
				if ($snapshot) dolibarr_set_const($db, $name, $snapshot->value, $snapshot->type, $snapshot->visible, $snapshot->note, $entity);
				else dolibarr_del_const($db, $name, $entity);
			}
			bookingQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
			foreach ($rateSnapshot as $row) bookingQuery('INSERT INTO '.MAIN_DB_PREFIX.'cdav_booking_rate (bucket_hash,hits,expires_at) VALUES (\''.$db->escape($row->bucket_hash).'\','.((int) $row->hits).','.((int) $row->expires_at).')');
			if ($users) {
				$ids = implode(',', array_map('intval', $users));
				bookingAssert((int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_profile WHERE fk_user IN ('.$ids.')')->nb === 0, 'Booking profiles remain after native user cleanup');
				bookingAssert((int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE element_type = \'user\' AND fk_element IN ('.$ids.')')->nb === 0, 'Agenda resources remain after cleanup');
			}
			bookingAssert((int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request r LEFT JOIN '.MAIN_DB_PREFIX.'cdav_booking_profile p ON p.rowid = r.fk_profile WHERE p.rowid IS NULL')->nb === 0, 'Orphan booking receipts remain');
			bookingAssert((int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_sync_collection c LEFT JOIN '.MAIN_DB_PREFIX
				.'user u ON u.rowid = c.collection_id WHERE c.entity = '.$entity.' AND c.scope = \'cal\' AND u.rowid IS NULL')->nb === 0, 'Orphan CalDAV sync collections remain');
			// Native fixture creation/deletion can log under the setup administrator.
			// This exclusively owned test instance must retain only its prior history.
			if ($initialAgendaId !== null) {
				$autoEvents = bookingQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id > '.$initialAgendaId
					.' AND fk_action IN (SELECT id FROM '.MAIN_DB_PREFIX.'c_actioncomm WHERE type = \'systemauto\')');
				while ($row = $db->fetch_object($autoEvents)) {
					$autoEvent = new ActionComm($db);
					if ($autoEvent->fetch((int) $row->id) > 0) bookingAssert($autoEvent->delete($admin) > 0, 'Native automatic history cleanup failed');
				}
				bookingAssert((int) bookingRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_action IN (SELECT id FROM '
					.MAIN_DB_PREFIX.'c_actioncomm WHERE type = \'systemauto\')')->nb === $initialAutoCount,
					'Native automatic history differs from its initial snapshot');
			}
		}
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
		fwrite(STDERR, 'Cleanup failed: '.$cleanupFailure->getMessage().PHP_EOL);
	}
	foreach ($cookies as $cookie) if (is_file($cookie)) unlink($cookie);
}
$proof['seconds'] = round(microtime(true) - $started, 3);
$proof['result'] = $failure ? 'FAIL' : 'PASS';
$proof['error'] = $failure ? $failure->getMessage() : null;
$proofPath = getenv('CDAV_BOOKING_PROOF');
if ($proofPath) file_put_contents($proofPath, json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($failure) {
	fwrite(STDERR, 'Booking live test failed: '.$failure->getMessage().PHP_EOL);
	exit(1);
}
bookingOk('native cleanup and configuration restoration (R04)');
fwrite(STDOUT, "All CDav public booking live tests passed.\n");
