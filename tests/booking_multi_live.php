<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}
if (getenv('CDAV_LIVE_TEST') !== '1' || getenv('CDAV_BOOKING_TEST_FIXTURES') !== '1') {
	fwrite(STDERR, "Set CDAV_LIVE_TEST=1 and CDAV_BOOKING_TEST_FIXTURES=1 for a disposable instance.\n");
	exit(2);
}
$root = realpath((string) getenv('DOLIBARR_ROOT'));
$bookingUrl = (string) getenv('CDAV_BOOKING_LIVE_URL');
$endpoint = parse_url($bookingUrl);
if (!$root || !is_file($root.'/main.inc.php') || !$endpoint
	|| !in_array($endpoint['host'] ?? '', array('127.0.0.1', 'localhost', '::1', '[::1]'), true)) {
	fwrite(STDERR, "Use a local disposable Dolibarr tree and booking endpoint.\n");
	exit(2);
}
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOCSRFCHECK', 1); // CLI setup only; public HTTP requests use native CSRF.
$_SERVER['SERVER_NAME'] = $endpoint['host'];
$_SERVER['HTTP_HOST'] = $endpoint['host'].(isset($endpoint['port']) ? ':'.$endpoint['port'] : '');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir($root);
require $root.'/main.inc.php';
if (!str_contains(strtolower((string) $dolibarr_main_db_name), 'test')) {
	fwrite(STDERR, "The database must be explicitly named as a test database.\n");
	exit(2);
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once dirname(__DIR__).'/class/CDavBookingService.php';

/**
 * Reject a failed native or HTTP assertion.
 * @param mixed $condition Expected truthy condition
 * @param string $message Failure explanation
 * @return void
 */
function multiLiveCheck($condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Execute only fixture, technical metadata or inspection SQL.
 * @param string $sql Test statement
 * @return mixed Native result
 */
function multiLiveQuery(string $sql)
{
	global $db;
	$result = $db->query($sql);
	if (!$result) throw new RuntimeException('Multi-booking fixture SQL failed');
	return $result;
}

/**
 * Fetch one inspection row.
 * @param string $sql Test statement
 * @return object|false|null Native row
 */
function multiLiveRow(string $sql)
{
	global $db;
	return $db->fetch_object(multiLiveQuery($sql));
}

/**
 * Build a real public request with its own native browser session.
 * @param array $fields Query or form fields
 * @param string $cookie Private cookie jar
 * @param bool $post Whether to submit a form
 * @return CurlHandle Prepared request
 */
function multiLiveHandle(array $fields, string $cookie, bool $post = false)
{
	global $bookingUrl;
	$url = $post ? $bookingUrl : $bookingUrl.'?'.http_build_query($fields);
	$handle = curl_init($url);
	curl_setopt_array($handle, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
		CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie));
	if ($post) {
		$parsed = parse_url($bookingUrl);
		$origin = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
		curl_setopt_array($handle, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
			CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded', 'Origin: '.$origin, 'Referer: '.$bookingUrl)));
	}
	return $handle;
}

/**
 * Send one HTTP request and decode only its public payload.
 * @param array $fields Query or form fields
 * @param string $cookie Private cookie jar
 * @param bool $post Whether to submit a form
 * @return array Status and JSON payload
 */
function multiLiveHttp(array $fields, string $cookie, bool $post = false): array
{
	$handle = multiLiveHandle($fields, $cookie, $post);
	$body = curl_exec($handle);
	multiLiveCheck($body !== false, 'Multi-booking HTTP transport failed');
	$result = array('status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'json' => json_decode($body, true));
	unset($handle);
	return $result;
}

/**
 * Obtain a fresh public ticket and native CSRF token.
 * @param string $key Publication key
 * @param string $date Chosen local day
 * @return array Public form and its private cookie path
 */
function multiLiveForm(string $key, string $date): array
{
	global $temporaryDirectory;
	$cookie = tempnam($temporaryDirectory, 'cookie-');
	$response = multiLiveHttp(array('key' => $key, 'date' => $date, 'format' => 'json'), $cookie);
	multiLiveCheck($response['status'] === 200 && !empty($response['json']['request']) && !empty($response['json']['token']), 'Cannot get public multi-booking form');
	return array('key' => $key, 'date' => $date, 'cookie' => $cookie, 'ticket' => $response['json']['request'], 'token' => $response['json']['token']);
}

/**
 * Build the new group-booking POST without exposing technical identifiers.
 * @param array $form Fresh form
 * @param array $starts Chosen start timestamps
 * @param array $changes Overrides for rejection scenarios
 * @return array Encodable form fields
 */
function multiLiveFields(array $form, array $starts, array $changes = array()): array
{
	return array_replace(array('key' => $form['key'], 'date' => $form['date'], 'format' => 'json',
		'token' => $form['token'], 'request' => $form['ticket'], 'selection' => implode(',', $starts),
		'name' => 'CDav isolated group fixture', 'email' => 'group@example.test', 'phone' => '', 'website' => ''), $changes);
}

/**
 * Submit a selected group using the native session of its form.
 * @param array $form Fresh form
 * @param array $starts Chosen start timestamps
 * @param array $changes Overrides for rejection scenarios
 * @return array Public response
 */
function multiLivePost(array $form, array $starts, array $changes = array()): array
{
	return multiLiveHttp(multiLiveFields($form, $starts, $changes), $form['cookie'], true);
}

/**
 * Prove two independent group requests reach the same native owner lock.
 * @param array $forms Independent browser forms
 * @param array $starts Selected groups
 * @param int $ownerId Native owner providing the lock barrier
 * @return array HTTP statuses
 */
function multiLiveRace(array $forms, array $starts, int $ownerId): array
{
	global $db, $dolibarr_main_db_name;
	$multi = curl_multi_init();
	$handles = array();
	multiLiveCheck($db->begin(), 'Cannot open native group-race barrier');
	multiLiveQuery('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$ownerId.' FOR UPDATE');
	try {
		foreach ($forms as $index => $form) {
			$handles[] = multiLiveHandle(multiLiveFields($form, $starts[$index]), $form['cookie'], true);
			curl_multi_add_handle($multi, $handles[$index]);
		}
		$deadline = microtime(true) + 10;
		$ownerLock = 'FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$ownerId.' AND ';
		$waiting = 0;
		do {
			multiLiveCheck(curl_multi_exec($multi, $running) === CURLM_OK, 'Cannot dispatch concurrent group requests');
			$waiting = 0;
			$rows = multiLiveQuery('SHOW FULL PROCESSLIST');
			while ($row = $db->fetch_object($rows)) {
				if (($row->db ?? '') === $dolibarr_main_db_name && strpos((string) ($row->Info ?? ''), 'FOR UPDATE') !== false
					&& strpos((string) $row->Info, $ownerLock) !== false) $waiting++;
			}
			if ($waiting >= 2) break;
			curl_multi_select($multi, 0.02);
		} while ($running && microtime(true) < $deadline);
		multiLiveCheck($db->commit(), 'Cannot release native group-race barrier');
		do {
			multiLiveCheck(curl_multi_exec($multi, $running) === CURLM_OK, 'Concurrent group response failed');
			if ($running) curl_multi_select($multi, 0.05);
		} while ($running);
		$statuses = array();
		foreach ($handles as $handle) {
			multiLiveCheck(curl_errno($handle) === 0, 'Concurrent group HTTP request failed');
			$statuses[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			curl_multi_remove_handle($multi, $handle);
		}
		multiLiveCheck($waiting >= 2, 'Two independent group workers must reach the owner-row lock; waiting='
			.$waiting.'; statuses='.json_encode($statuses));
		return $statuses;
	} catch (Throwable $exception) {
		$db->rollback();
		throw $exception;
	} finally {
		unset($handles, $multi);
	}
}

$entity = (int) $conf->entity;
$prefix = 'cdav-group-'.bin2hex(random_bytes(6));
$temporaryDirectory = sys_get_temp_dir().'/'.$prefix;
multiLiveCheck(mkdir($temporaryDirectory, 0700), 'Cannot create private multi-booking fixture directory');
$ownerId = 0;
$failure = null;
$triggerPath = '';
$constantSnapshots = $rateSnapshot = array();
$admin = null;
$initialAgendaId = (int) multiLiveRow('SELECT COALESCE(MAX(id),0) AS id FROM '.MAIN_DB_PREFIX.'actioncomm')->id;
$initialAutoCount = (int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_action IN (SELECT id FROM '.MAIN_DB_PREFIX."c_actioncomm WHERE type = 'systemauto')")->nb;
$proof = array('dolibarr' => DOL_VERSION, 'php' => PHP_VERSION, 'checks' => array());

try {
	multiLiveCheck($db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_booking_request_event'), 'Reactivate CDav to install the native group-receipt mapping table');
	$row = multiLiveRow('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1');
	$admin = new User($db);
	multiLiveCheck($row && $admin->fetch((int) $row->rowid) > 0 && $admin->loadRights() >= 0, 'Cannot load isolated setup administrator');
	$GLOBALS['user'] = $admin;
	foreach (array('CDAV_BOOKING_ENABLED' => '1', 'CDAV_BOOKING_ALLOW_LOCAL_HTTP' => '1', 'MAIN_SECURITY_CSRF_WITH_TOKEN' => '1') as $name => $value) {
		$constantSnapshots[$name] = multiLiveRow('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name = '".$db->escape($name)."' AND entity = ".$entity);
		multiLiveCheck(dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $entity) > 0, 'Cannot configure isolated booking fixture');
		$conf->global->$name = $value;
	}
	$rates = multiLiveQuery('SELECT bucket_hash,hits,expires_at FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	while ($row = $db->fetch_object($rates)) $rateSnapshot[] = $row;
	multiLiveQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$owner = new User($db);
	$owner->entity = $entity;
	$owner->login = $prefix;
	$owner->lastname = 'Group isolated fixture';
	$owner->firstname = 'CDav';
	$owner->email = 'group@example.test';
	$owner->admin = 0;
	$owner->employee = 1;
	$owner->statut = $owner->status = 1;
	$ownerId = $owner->create($admin);
	multiLiveCheck($ownerId > 0, 'Cannot create isolated group owner');
	foreach (array('read', 'create', 'delete') as $permission) {
		$right = multiLiveRow('SELECT id FROM '.MAIN_DB_PREFIX."rights_def WHERE module = 'agenda' AND perms = 'myactions' AND subperms = '".$permission."' LIMIT 1");
		multiLiveCheck($right && $owner->addrights((int) $right->id, '', '', $entity) > 0, 'Cannot grant isolated own-agenda right');
	}
	$owner->loadRights('', 1);
	$service = new \Dolibarr\CDav\BookingService($db, $entity);
	$config = array('timezone' => 'Europe/Zurich', 'duration' => 30, 'step' => 30,
		'buffer_before' => 15, 'buffer_after' => 15, 'notice' => 0, 'horizon' => 6, 'horizon_unit' => 'months',
		'allow_contiguous' => true, 'allow_split' => true,
		'weekly' => array_fill(1, 7, array(array('09:00', '12:00'), array('13:00', '17:00'))));
	$service->saveProfile($ownerId, $config, true);
	$key = $service->rotateToken($ownerId);
	$day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->modify('+2 days')->setTime(0, 0);
	$at = static function (int $offset, int $hour, int $minute = 0) use ($day): int {
		return $day->modify('+'.$offset.' days')->setTime($hour, $minute)->getTimestamp();
	};
	$form = multiLiveForm($key, $day->format('Y-m-d'));
	$chosen = array($at(0, 9, 30), $at(0, 10));
	$accepted = multiLivePost($form, $chosen);
	multiLiveCheck($accepted['status'] === 201 && count($accepted['json']['segments']) === 1 && $accepted['json']['duration_minutes'] === 60,
		'Continuous units must create a single sixty-minute native appointment');
	$replayed = multiLivePost($form, array_reverse($chosen));
	multiLiveCheck($replayed['status'] === 200 && $replayed['json']['reference'] === $accepted['json']['reference'] && $replayed['json']['replayed'],
		'Reordered units must replay one stable group receipt');
	multiLiveCheck(multiLivePost($form, $chosen, array('email' => 'changed@example.test'))['status'] === 409, 'Divergent group body must conflict');
	$proof['checks'][] = 'continuous native event, canonical replay and divergent body';

	$splitForm = multiLiveForm($key, $day->modify('+1 day')->format('Y-m-d'));
	$splitStarts = array($at(1, 9, 30), $at(1, 13, 30), $at(2, 9, 30));
	$split = multiLivePost($splitForm, $splitStarts);
	multiLiveCheck($split['status'] === 201 && count($split['json']['segments']) === 3 && $split['json']['duration_minutes'] === 90,
		'Lunch and next-day selections must create three separate native events');
	$members = multiLiveQuery('SELECT m.segment_no,m.fk_actioncomm FROM '.MAIN_DB_PREFIX.'cdav_booking_request_event m INNER JOIN '
		.MAIN_DB_PREFIX."cdav_booking_request r ON r.request_hash = m.request_hash WHERE r.reference = '".$db->escape($split['json']['reference'])."' ORDER BY m.segment_no");
	$memberIds = array();
	while ($member = $db->fetch_object($members)) $memberIds[] = (int) $member->fk_actioncomm;
	multiLiveCheck(count($memberIds) === 3, 'Every native segment must belong to the technical receipt');
	$moved = new ActionComm($db);
	multiLiveCheck($moved->fetch($memberIds[1]) > 0, 'Cannot fetch second native segment');
	$moved->datep += 1800;
	$moved->datef += 1800;
	multiLiveCheck($moved->update($owner) > 0, 'Cannot move native fixture segment');
	multiLiveCheck(multiLivePost($splitForm, $splitStarts)['status'] === 410, 'A moved native segment invalidates the original group receipt');
	$moved->datep -= 1800;
	$moved->datef -= 1800;
	$moved->percentage = 0;
	multiLiveCheck($moved->update($owner) > 0, 'Cannot convert native fixture segment to a task');
	$beforeReplay = (int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId)->nb;
	multiLiveCheck(multiLivePost($splitForm, $splitStarts)['status'] === 410, 'A native segment converted to a task invalidates the original group receipt');
	multiLiveCheck((int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId)->nb === $beforeReplay,
		'Replaying a group containing a converted native task must create no event');
	$moved->percentage = -1;
	$moved->transparency = 0;
	$moved->userassigned[$ownerId]['transparency'] = 0;
	multiLiveCheck($moved->update($owner) > 0, 'Cannot mark the native fixture segment free');
	multiLiveCheck(multiLivePost($splitForm, $splitStarts)['status'] === 410, 'A native segment marked free invalidates the original group receipt');
	multiLiveCheck($moved->delete($owner) > 0, 'Cannot cancel second native fixture segment');
	multiLiveCheck(multiLivePost($splitForm, $splitStarts)['status'] === 410, 'Deleting a non-first native segment must invalidate the whole group receipt');
	$proof['checks'][] = 'split native events, moved/task/free-segment replay and cancelled non-first segment';

	$config['allow_contiguous'] = false;
	$config['allow_split'] = false;
	$service->saveProfile($ownerId, $config, true);
	$offForm = multiLiveForm($key, $day->modify('+4 days')->format('Y-m-d'));
	multiLiveCheck(multiLivePost($offForm, array($at(4, 9, 30), $at(4, 10)))['status'] === 422, 'Forged continuous selection must obey the profile switch');
	multiLiveCheck(multiLivePost($offForm, array($at(4, 9, 30), $at(4, 13, 30)))['status'] === 422, 'Forged split selection must obey the profile switch');
	$config['allow_contiguous'] = $config['allow_split'] = true;
	$service->saveProfile($ownerId, $config, true);
	$proof['checks'][] = 'both disabled profile permissions enforced by public POST';

	$rollbackForm = multiLiveForm($key, $day->modify('+5 days')->format('Y-m-d'));
	$rollbackStarts = array($at(5, 9, 30), $at(5, 13, 30));
	$rollbackName = $prefix.' rollback';
	$marker = $temporaryDirectory.'/trigger-count';
	$triggerClass = 'InterfaceCdavMultiQa'.bin2hex(random_bytes(5));
	$triggerPath = DOL_DOCUMENT_ROOT.'/core/triggers/interface_99_modCDav_'.substr($triggerClass, 9).'.class.php';
	multiLiveCheck(!file_exists($triggerPath) && is_writable(dirname($triggerPath)), 'Use an exclusively disposable trigger tree');
	$trigger = '<?php require_once DOL_DOCUMENT_ROOT."/core/triggers/dolibarrtriggers.class.php";class '.$triggerClass
		.' extends DolibarrTriggers {public function runTrigger($action,$object,$user,$langs,$conf) {if($action==="ACTION_CREATE" && ($object->label??"")==='
		.var_export('RDV — '.$rollbackName, true).') {$p='.var_export($marker, true).';$n=(int)@file_get_contents($p)+1;file_put_contents($p,(string)$n);if($n===2){$this->error="Isolated second-segment rollback";return -1;}}return 0;}}';
	multiLiveCheck(file_put_contents($triggerPath, $trigger) !== false, 'Cannot install native group rollback trigger');
	$before = (int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId)->nb;
	$rollbackCounts = array();
	foreach (array('actioncomm_resources', 'cdav_booking_request', 'cdav_booking_request_event') as $table) {
		$rollbackCounts[$table] = (int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table)->nb;
	}
	multiLiveCheck(multiLivePost($rollbackForm, $rollbackStarts, array('name' => $rollbackName))['status'] === 503
		&& file_get_contents($marker) === '2', 'The second real native create trigger must reject the group');
	multiLiveCheck((int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId)->nb === $before,
		'Failure on the second segment must roll back the first native event');
	foreach ($rollbackCounts as $table => $count) {
		multiLiveCheck((int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table)->nb === $count,
			'Failure on the second segment must roll back resources, receipt and membership rows');
	}
	unlink($triggerPath);
	$triggerPath = '';
	multiLiveCheck(multiLivePost($rollbackForm, $rollbackStarts, array('name' => $rollbackName))['status'] === 201, 'The same group ticket may succeed after a complete rollback');
	$proof['checks'][] = 'second native trigger failure rolls back every segment and permits retry';

	$raceDate = $day->modify('+6 days')->format('Y-m-d');
	$forms = array(multiLiveForm($key, $raceDate), multiLiveForm($key, $raceDate));
	$before = (int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId)->nb;
	$statuses = multiLiveRace($forms, array(array($at(6, 9, 30), $at(6, 10)), array($at(6, 10), $at(6, 10, 30))), $ownerId);
	sort($statuses);
	multiLiveCheck($statuses === array(201, 409), 'Two overlapping real group requests must produce one success and one conflict');
	multiLiveCheck((int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId)->nb === $before + 1,
		'The losing concurrent group must create no partial event');
	$proof['checks'][] = 'two independent HTTP workers wait on owner lock; losing group leaves no event';
} catch (Throwable $exception) {
	$failure = $exception;
} finally {
	try {
		if ($triggerPath !== '' && is_file($triggerPath)) unlink($triggerPath);
		if ($db->transaction_opened) $db->rollback();
		if ($admin && $ownerId > 0) {
			$events = multiLiveQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId);
			$ids = array();
			while ($row = $db->fetch_object($events)) $ids[] = (int) $row->id;
			foreach ($ids as $id) {
				$event = new ActionComm($db);
				multiLiveCheck($event->fetch($id) > 0 && $event->delete($admin) > 0, 'Cannot clean a native group fixture event');
			}
			$owner = new User($db);
			multiLiveCheck($owner->fetch($ownerId) > 0 && $owner->delete($admin) > 0, 'Cannot clean native group fixture owner');
			foreach (array('user' => 'rowid = '.$ownerId, 'cdav_booking_profile' => 'fk_user = '.$ownerId,
				'actioncomm_resources' => "element_type = 'user' AND fk_element = ".$ownerId) as $table => $condition) {
				multiLiveCheck((int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table.' WHERE '.$condition)->nb === 0,
					'No native owner, profile or assigned resource may remain after group fixture cleanup');
			}
			multiLiveCheck((int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request_event m LEFT JOIN '
				.MAIN_DB_PREFIX.'cdav_booking_request r ON r.request_hash = m.request_hash WHERE r.request_hash IS NULL')->nb === 0,
				'No orphan group receipt membership may remain after native cleanup');
			$automatic = multiLiveQuery('SELECT a.id FROM '.MAIN_DB_PREFIX.'actioncomm a INNER JOIN '.MAIN_DB_PREFIX
				."c_actioncomm c ON c.id = a.fk_action WHERE c.type = 'systemauto' AND a.id > ".$initialAgendaId);
			$ids = array();
			while ($row = $db->fetch_object($automatic)) $ids[] = (int) $row->id;
			foreach ($ids as $id) {
				$event = new ActionComm($db);
				multiLiveCheck($event->fetch($id) > 0 && $event->delete($admin) > 0, 'Cannot clean new native automatic history');
			}
			multiLiveCheck((int) multiLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_action IN (SELECT id FROM '
				.MAIN_DB_PREFIX."c_actioncomm WHERE type = 'systemauto')")->nb === $initialAutoCount, 'Existing automatic history must remain unchanged');
		}
		foreach ($constantSnapshots as $name => $row) {
			if ($row) dolibarr_set_const($db, $name, $row->value, $row->type, $row->visible, $row->note, $entity);
			else dolibarr_del_const($db, $name, $entity);
		}
		multiLiveQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
		foreach ($rateSnapshot as $row) {
			multiLiveQuery('INSERT INTO '.MAIN_DB_PREFIX."cdav_booking_rate (bucket_hash,hits,expires_at) VALUES ('"
				.$db->escape($row->bucket_hash)."',".((int) $row->hits).','.((int) $row->expires_at).')');
		}
		foreach (glob($temporaryDirectory.'/*') as $path) unlink($path);
		rmdir($temporaryDirectory);
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
	}
}
$proof['result'] = $failure ? 'FAIL' : 'PASS';
$proof['error'] = $failure ? $failure->getMessage() : null;
$output = getenv('CDAV_BOOKING_MULTI_PROOF');
if ($output) file_put_contents($output, json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
if ($failure) {
	fwrite(STDERR, 'Multi-booking live checks failed: '.$failure->getMessage()."\n");
	exit(1);
}
foreach ($proof['checks'] as $check) fwrite(STDOUT, '[OK] '.$check."\n");
fwrite(STDOUT, 'All native multi-booking HTTP checks passed on Dolibarr '.DOL_VERSION.' / PHP '.PHP_VERSION.".\n");
