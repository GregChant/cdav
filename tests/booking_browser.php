<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Browser fixture runner. Requires an exclusively disposable local test tree. */
if (getenv('CDAV_LIVE_TEST') !== '1' || getenv('CDAV_BOOKING_TEST_FIXTURES') !== '1') {
	fwrite(STDERR, "Refusing to run: CDAV_LIVE_TEST=1 and CDAV_BOOKING_TEST_FIXTURES=1 are required.\n");
	exit(2);
}
$root = realpath((string) getenv('DOLIBARR_ROOT'));
$isolatedRoot = realpath((string) getenv('CDAV_BOOKING_BROWSER_ROOT'));
$bookingUrl = rtrim((string) getenv('CDAV_BOOKING_LIVE_URL'), '/');
$parsed = parse_url($bookingUrl);
if (!$root || !$isolatedRoot || $root !== $isolatedRoot || !is_file($root.'/main.inc.php')
	|| !$parsed || !in_array($parsed['host'] ?? '', array('127.0.0.1', 'localhost', '::1', '[::1]'), true)
	|| !in_array($parsed['scheme'] ?? '', array('http', 'https'), true)
	|| !str_ends_with($parsed['path'] ?? '', '/public/booking.php')) {
	fwrite(STDERR, "Set matching DOLIBARR_ROOT/CDAV_BOOKING_BROWSER_ROOT and a local CDAV_BOOKING_LIVE_URL.\n");
	exit(2);
}
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOCSRFCHECK', 1); // CLI fixtures only; browser requests use native CSRF.
$_SERVER['SERVER_NAME'] = (string) $parsed['host'];
$_SERVER['HTTP_HOST'] = $parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir($root);
require $root.'/main.inc.php';
date_default_timezone_set('Europe/Zurich');
if (!str_contains(strtolower((string) $dolibarr_main_db_name), 'test')) {
	fwrite(STDERR, "Refusing to run: the database name must explicitly contain test.\n");
	exit(2);
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once dirname(__DIR__).'/class/CDavBookingService.php';

/**
 * Execute technical fixture SQL and fail before continuing after an error.
 *
 * @param string $sql Technical or read-only SQL
 * @return mixed Native database result
 */
function bookingBrowserQuery($sql)
{
	global $db;
	$result = $db->query($sql);
	if (!$result) throw new RuntimeException('Browser fixture database operation failed');
	return $result;
}

$entity = (int) $conf->entity;
$agendaSnapshot = $db->fetch_object(bookingBrowserQuery('SELECT COALESCE(MAX(id),0) AS last_id FROM '.MAIN_DB_PREFIX.'actioncomm'));
$automaticScope = ' FROM '.MAIN_DB_PREFIX.'actioncomm a INNER JOIN '.MAIN_DB_PREFIX
	."c_actioncomm c ON c.id = a.fk_action WHERE c.type = 'systemauto' AND a.entity = ".$entity;
$automaticSnapshot = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb'.$automaticScope));
if (!$agendaSnapshot || !$automaticSnapshot) throw new RuntimeException('Unable to snapshot native Agenda audit records');
$adminRow = $db->fetch_object(bookingBrowserQuery('SELECT rowid FROM '.MAIN_DB_PREFIX
	.'user WHERE admin = 1 AND statut = 1 AND entity IN (0, '.$entity.') ORDER BY rowid LIMIT 1'));
$admin = new User($db);
if (!$adminRow || $admin->fetch($adminRow->rowid) <= 0 || $admin->loadRights() < 0) {
	throw new RuntimeException('A setup administrator is required in the isolated instance');
}
$GLOBALS['user'] = $admin;
$settingSnapshots = array();
foreach (array('CDAV_BOOKING_ENABLED', 'CDAV_BOOKING_ALLOW_LOCAL_HTTP') as $setting) {
	$settingSnapshots[$setting] = $db->fetch_object(bookingBrowserQuery('SELECT * FROM '.MAIN_DB_PREFIX.'const'
		." WHERE name = '".$db->escape($setting)."' AND entity = ".$entity));
}
$rateSnapshot = array();
$rateResult = bookingBrowserQuery('SELECT bucket_hash,hits,expires_at FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
while ($row = $db->fetch_object($rateResult)) $rateSnapshot[] = $row;
$ownerId = 0;
$temporaryDirectory = '';
$failure = null;
$service = new \Dolibarr\CDav\BookingService($db, $entity);
try {
	$owner = new User($db);
	$owner->entity = $entity;
	$owner->login = 'cdav-browser-'.bin2hex(random_bytes(6));
	$owner->firstname = 'Browser';
	$owner->lastname = 'CDav isolated fixture';
	$owner->email = $owner->login.'@example.test';
	$owner->admin = 1;
	$owner->employee = 1;
	$owner->statut = $owner->status = 1;
	$owner->lang = 'fr_FR';
	$ownerId = $owner->create($admin);
	if ($ownerId <= 0 || $owner->addrights(0, 'agenda', '', $entity) <= 0) {
		throw new RuntimeException('Unable to create the isolated browser owner');
	}
	$password = 'Browser!'.bin2hex(random_bytes(16)).'7aA';
	$passwordResult = $owner->setPassword($admin, $password, 0, 0, 1);
	if (is_int($passwordResult) && $passwordResult < 0) throw new RuntimeException('Unable to set the fixture password');
	$config = array('timezone' => 'Europe/Zurich', 'duration' => 30, 'step' => 30,
		'buffer_before' => 0, 'buffer_after' => 0, 'notice' => 0, 'horizon' => 60,
		'weekly' => array_fill(1, 7, array(array('09:00', '12:00'), array('13:00', '17:00'))));
	$service->saveProfile($ownerId, $config, true);
	$key = $service->rotateToken($ownerId);
	$openDay = new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Zurich'));
	$closedDay = $openDay->modify('+1 day');
	$absence = new ActionComm($db);
	$absence->type_code = 'AC_RDV';
	$absence->label = 'CDav browser closed-day fixture';
	$absence->note_private = 'CDav isolated browser absence fixture';
	$absence->datep = $closedDay->getTimestamp();
	$absence->datef = $closedDay->modify('+1 day')->getTimestamp() - 1;
	$absence->fulldayevent = 1;
	$absence->percentage = -1;
	$absence->transparency = 1;
	$absence->userownerid = $ownerId;
	$absence->userassigned = array($ownerId => array('id' => $ownerId, 'transparency' => 1));
	if ($absence->create($owner) <= 0) throw new RuntimeException('Unable to create the native closed-day fixture');
	if (dolibarr_set_const($db, 'CDAV_BOOKING_ENABLED', '1', 'chaine', 0, '', $entity) <= 0) {
		throw new RuntimeException('Unable to enable isolated browser booking');
	}
	if ($parsed['scheme'] === 'http'
		&& dolibarr_set_const($db, 'CDAV_BOOKING_ALLOW_LOCAL_HTTP', '1', 'chaine', 0, '', $entity) <= 0) {
		throw new RuntimeException('Unable to enable the local-only test transport');
	}
	$temporaryDirectory = sys_get_temp_dir().'/cdav-browser-'.bin2hex(random_bytes(8));
	if (!mkdir($temporaryDirectory, 0700)) throw new RuntimeException('Unable to create the private fixture directory');
	$origin = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
	$fixture = array('isolated' => true, 'booking_url' => $bookingUrl,
		'home_url' => $origin.DOL_URL_ROOT.'/index.php',
		'admin_url' => substr($bookingUrl, 0, -strlen('/public/booking.php')).'/admin/booking.php',
		'owner_id' => $ownerId, 'key' => $key, 'login' => $owner->login, 'password' => $password,
		'date' => $openDay->format('Y-m-d'), 'closed_date' => $closedDay->format('Y-m-d'),
		'php' => PHP_VERSION, 'dolibarr' => DOL_VERSION);
	$fixturePath = $temporaryDirectory.'/fixture.json';
	if (file_put_contents($fixturePath, json_encode($fixture, JSON_THROW_ON_ERROR)) === false || !chmod($fixturePath, 0600)) {
		throw new RuntimeException('Unable to write the private browser fixture');
	}
	putenv('CDAV_BOOKING_BROWSER_FIXTURE='.$fixturePath);
	if (!getenv('CDAV_BOOKING_BROWSER_OUTPUT')) putenv('CDAV_BOOKING_BROWSER_OUTPUT='.dirname(__DIR__).'/dist/qa');
	$node = getenv('CDAV_NODE_BIN') ?: 'node';
	$process = proc_open(array($node, __DIR__.'/booking_browser.cjs'), array(0 => array('pipe', 'r'), 1 => STDOUT, 2 => STDERR), $pipes);
	if (!is_resource($process)) throw new RuntimeException('Unable to launch the browser checks');
	fclose($pipes[0]);
	$exitCode = proc_close($process);
	if ($exitCode !== 0) throw new RuntimeException('Browser checks failed with exit code '.$exitCode);
	// One native absence and two accepted public requests; replay adds no event.
	$eventCount = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX
		.'actioncomm WHERE fk_user_action = '.$ownerId.' AND entity = '.$entity));
	if (!$eventCount || (int) $eventCount->nb !== 3) throw new RuntimeException('Lost-response replay duplicated or lost a native event');
} catch (Throwable $exception) {
	$failure = $exception;
	fwrite(STDERR, 'Browser checks failed: '.get_class($exception).PHP_EOL);
} finally {
	try {
		if ($ownerId > 0) {
			$service->disableProfile($ownerId);
			$events = bookingBrowserQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId.' AND entity = '.$entity);
			$eventIds = array();
			while ($row = $db->fetch_object($events)) $eventIds[] = (int) $row->id;
			foreach ($eventIds as $eventId) {
				$event = new ActionComm($db);
				if ($event->fetch($eventId) > 0 && $event->delete($admin) <= 0) throw new RuntimeException('Native event cleanup failed');
			}
			$owner = new User($db);
			if ($owner->fetch($ownerId) > 0 && $owner->delete($admin) <= 0) throw new RuntimeException('Native user cleanup failed');
			foreach (array(
				'user' => 'rowid = '.$ownerId,
				'cdav_booking_profile' => 'fk_user = '.$ownerId,
				'actioncomm_resources' => "element_type = 'user' AND fk_element = ".$ownerId,
			) as $table => $condition) {
				$remaining = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table.' WHERE '.$condition));
				if (!$remaining || (int) $remaining->nb !== 0) throw new RuntimeException('Browser fixture data remains after native cleanup');
			}
		}
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
		fwrite(STDERR, 'Native browser fixture cleanup failed: '.get_class($cleanupFailure).PHP_EOL);
	}
	try {
		// Native user lifecycle triggers also create systemauto Agenda records.
		// This exclusively isolated test may remove only records born after its snapshot.
		$automaticEvents = bookingBrowserQuery('SELECT a.id'.$automaticScope.' AND a.id > '.((int) $agendaSnapshot->last_id));
		$automaticIds = array();
		while ($row = $db->fetch_object($automaticEvents)) $automaticIds[] = (int) $row->id;
		foreach ($automaticIds as $eventId) {
			$event = new ActionComm($db);
			if ($event->fetch($eventId) > 0 && $event->delete($admin) <= 0) throw new RuntimeException('Native automatic Agenda cleanup failed');
		}
		$automaticRemaining = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb'.$automaticScope));
		if (!$automaticRemaining || (int) $automaticRemaining->nb !== (int) $automaticSnapshot->nb) {
			throw new RuntimeException('Native automatic Agenda record count was not restored');
		}
		fwrite(STDOUT, 'Native automatic Agenda records restored: '.((int) $automaticSnapshot->nb)
			.' -> '.((int) $automaticRemaining->nb).' (removed '.count($automaticIds).').'.PHP_EOL);
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
		fwrite(STDERR, 'Native automatic Agenda cleanup failed: '.get_class($cleanupFailure).PHP_EOL);
	}
	try {
		foreach ($settingSnapshots as $setting => $snapshot) {
			if ($snapshot) {
				$restored = dolibarr_set_const($db, $setting, $snapshot->value, $snapshot->type,
					$snapshot->visible, $snapshot->note, $entity);
			} else $restored = dolibarr_del_const($db, $setting, $entity);
			if ($restored <= 0) throw new RuntimeException('Booking setting restoration failed');
		}
		bookingBrowserQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
		foreach ($rateSnapshot as $row) {
			bookingBrowserQuery('INSERT INTO '.MAIN_DB_PREFIX.'cdav_booking_rate (bucket_hash,hits,expires_at) VALUES ('
				."'".$db->escape($row->bucket_hash)."',".((int) $row->hits).','.((int) $row->expires_at).')');
		}
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
		fwrite(STDERR, 'Browser configuration restoration failed: '.get_class($cleanupFailure).PHP_EOL);
	}
	if ($temporaryDirectory !== '') {
		if (is_file($temporaryDirectory.'/fixture.json')) unlink($temporaryDirectory.'/fixture.json');
		rmdir($temporaryDirectory);
	}
	putenv('CDAV_BOOKING_BROWSER_FIXTURE');
}
if ($failure) exit(1);
fwrite(STDOUT, "Browser fixture cleanup and configuration restoration passed.\n");
