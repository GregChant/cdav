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
$_SERVER['QUERY_STRING'] = '';
chdir($root);
require $root.'/main.inc.php';
date_default_timezone_set('Europe/Zurich');
if (!str_contains(strtolower((string) $dolibarr_main_db_name), 'test')) {
	fwrite(STDERR, "Refusing to run: the database name must explicitly contain test.\n");
	exit(2);
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
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

/**
 * Install a uniquely named raster fixture under the isolated native data root.
 *
 * @param string $path Native test image path
 * @param string $bytes Small image fixture bytes
 * @return void
 */
function bookingBrowserImage($path, $bytes)
{
	global $fileSnapshots, $createdDirectories;
	if (strpos($path, DOL_DATA_ROOT.'/') !== 0 || in_array('..', explode('/', $path), true) || is_link($path)) {
		throw new RuntimeException('Browser image fixture escapes the isolated native data root');
	}
	$fileSnapshots[$path] = is_file($path) ? file_get_contents($path) : null;
	if ($fileSnapshots[$path] === false) throw new RuntimeException('Unable to snapshot a test image');
	$directory = dirname($path);
	$missing = $directory;
	while (!is_dir($missing)) {
		$createdDirectories[$missing] = $missing;
		$missing = dirname($missing);
	}
	if (dol_mkdir($directory) < 0 || file_put_contents($path, $bytes) === false) {
		throw new RuntimeException('Unable to install a native browser image fixture');
	}
}

// The browser may request narrow native fixture mutations through a child CLI
// process. Nothing is installed as an HTTP endpoint or exposed to the product.
$browserAction = (string) getenv('CDAV_BOOKING_BROWSER_ACTION');
if ($browserAction !== '') {
	try {
		$fixtureFile = (string) getenv('CDAV_BOOKING_BROWSER_FIXTURE');
		$resolvedFixture = realpath($fixtureFile);
		if ($resolvedFixture !== $fixtureFile || !preg_match('#^'.preg_quote(realpath(sys_get_temp_dir()), '#').'/cdav-browser-[a-f0-9]{16}/fixture\.json$#D', $fixtureFile)) {
			throw new RuntimeException('Native browser actions require their private fixture file');
		}
		$actionFixture = json_decode((string) file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);
		$actionOwner = new User($db);
		if (empty($actionFixture['isolated']) || $actionOwner->fetch((int) ($actionFixture['owner_id'] ?? 0)) <= 0
			|| strpos((string) $actionOwner->login, 'cdav-browser-') !== 0 || $actionOwner->login !== ($actionFixture['login'] ?? '')) {
			throw new RuntimeException('Native browser actions require their isolated fixture owner');
		}
		$actionEntity = (int) $conf->entity;
		$actionResult = array();
		if ($browserAction === 'profile_snapshot') {
			$actionProfileId = (string) getenv('CDAV_BOOKING_BROWSER_PROFILE_ID');
			if (!preg_match('/^[1-9][0-9]*$/D', $actionProfileId)) throw new RuntimeException('A native fixture profile identifier is required');
			$actionRow = $db->fetch_object(bookingBrowserQuery('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_booking_profile WHERE rowid = '.((int) $actionProfileId)
				.' AND entity = '.$actionEntity.' AND fk_user = '.((int) $actionOwner->id)));
			if (!$actionRow) throw new RuntimeException('Native browser actions cannot read another collaborator profile');
			$actionEvents = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE entity = '.$actionEntity
				.' AND fk_user_action = '.((int) $actionOwner->id)));
			$actionReceipts = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request WHERE fk_profile = '.((int) $actionRow->rowid)));
			$actionResult = array('fingerprint' => hash('sha256', json_encode($actionRow, JSON_THROW_ON_ERROR)),
				'project_id' => (int) ($actionRow->fk_project ?? 0), 'thirdparty_id' => (int) ($actionRow->fk_soc ?? 0),
				'events' => (int) $actionEvents->nb, 'receipts' => (int) $actionReceipts->nb);
		} elseif ($browserAction === 'project_selector') {
			$actionValue = (string) getenv('CDAV_BOOKING_BROWSER_ACTION_VALUE');
			if (!in_array($actionValue, array('0', '1'), true)
				|| dolibarr_set_const($db, 'PROJECT_USE_SEARCH_TO_SELECT', $actionValue, 'chaine', 0, '', $actionEntity) <= 0) {
				throw new RuntimeException('Unable to select the native fixture project search mode');
			}
			$actionResult = array('project_search_mode' => (int) $actionValue);
		} elseif (in_array($browserAction, array('close_project', 'open_project'), true)) {
			$actionAdminRow = $db->fetch_object(bookingBrowserQuery('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1 AND entity IN (0, '.$actionEntity.') ORDER BY rowid LIMIT 1'));
			$actionAdmin = new User($db);
			if (!$actionAdminRow || $actionAdmin->fetch((int) $actionAdminRow->rowid) <= 0 || $actionAdmin->loadRights() < 0) {
				throw new RuntimeException('A native fixture administrator is required');
			}
			$GLOBALS['user'] = $actionAdmin;
			$actionProject = new Project($db);
			if ($actionProject->fetch((int) ($actionFixture['project_id'] ?? 0)) <= 0 || (int) $actionProject->entity !== $actionEntity
				|| (int) $actionProject->socid !== (int) ($actionFixture['thirdparty_id'] ?? 0)) {
				throw new RuntimeException('Native browser actions cannot alter another project');
			}
			$actionStatus = $browserAction === 'close_project' ? Project::STATUS_CLOSED : Project::STATUS_VALIDATED;
			if ((int) $actionProject->status !== $actionStatus) {
				$actionChanged = $browserAction === 'close_project' ? $actionProject->setClose($actionAdmin) : $actionProject->setValid($actionAdmin);
				if ($actionChanged <= 0) throw new RuntimeException('Unable to change the native fixture project lifecycle');
			}
			$actionResult = array('project_id' => (int) $actionProject->id, 'status' => $actionStatus);
		} else throw new RuntimeException('Unsupported native browser action');
		fwrite(STDOUT, json_encode($actionResult, JSON_THROW_ON_ERROR).PHP_EOL);
		exit(0);
	} catch (Throwable $actionError) {
		fwrite(STDERR, 'Native browser fixture action failed: '.get_class($actionError).PHP_EOL);
		exit(1);
	}
}

$entity = (int) $conf->entity;
$agendaSnapshot = $db->fetch_object(bookingBrowserQuery('SELECT COALESCE(MAX(id),0) AS last_id FROM '.MAIN_DB_PREFIX.'actioncomm'));
$automaticScope = ' FROM '.MAIN_DB_PREFIX.'actioncomm a INNER JOIN '.MAIN_DB_PREFIX
	."c_actioncomm c ON c.id = a.fk_action WHERE c.type = 'systemauto' AND a.entity = ".$entity;
$automaticSnapshot = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb'.$automaticScope));
if (!$agendaSnapshot || !$automaticSnapshot) throw new RuntimeException('Unable to snapshot native Agenda audit records');
$orphanScope = ' FROM '.MAIN_DB_PREFIX.'actioncomm_resources r LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm a ON a.id = r.fk_actioncomm WHERE a.id IS NULL';
$orphanSnapshot = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb'.$orphanScope));
if (!$orphanSnapshot) throw new RuntimeException('Unable to snapshot native Agenda resource integrity');
$adminRow = $db->fetch_object(bookingBrowserQuery('SELECT rowid FROM '.MAIN_DB_PREFIX
	.'user WHERE admin = 1 AND statut = 1 AND entity IN (0, '.$entity.') ORDER BY rowid LIMIT 1'));
$admin = new User($db);
if (!$adminRow || $admin->fetch($adminRow->rowid) <= 0 || $admin->loadRights() < 0) {
	throw new RuntimeException('A setup administrator is required in the isolated instance');
}
$GLOBALS['user'] = $admin;
$settingSnapshots = array();
foreach (array('CDAV_BOOKING_ENABLED', 'CDAV_BOOKING_ALLOW_LOCAL_HTTP',
	'MAIN_INFO_SOCIETE_NOM', 'MAIN_INFO_SOCIETE_LOGO', 'MAIN_INFO_SOCIETE_LOGO_SMALL',
	'MAIN_INFO_SOCIETE_LOGO_SQUARRED', 'MAIN_INFO_SOCIETE_LOGO_SQUARRED_SMALL', 'MAIN_INFO_SOCIETE_LOGO_SQUARRED_MINI',
	'PROJECT_USE_SEARCH_TO_SELECT') as $setting) {
	$settingSnapshots[$setting] = $db->fetch_object(bookingBrowserQuery('SELECT * FROM '.MAIN_DB_PREFIX.'const'
		." WHERE name = '".$db->escape($setting)."' AND entity = ".$entity));
}
$rateSnapshot = array();
$rateResult = bookingBrowserQuery('SELECT bucket_hash,hits,expires_at FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
while ($row = $db->fetch_object($rateResult)) $rateSnapshot[] = $row;
$mappingSnapshot = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request_event'));
$ownerId = 0;
$thirdpartyId = 0;
$projectId = 0;
$projectIds = array();
$thirdpartyIds = array();
$draftProjectId = $replacementProjectId = $closedProjectId = $otherProjectId = 0;
$privateThirdpartyName = 'CDav Browser Private Thirdparty '.bin2hex(random_bytes(5));
$privateProjectName = 'CDav Browser Private Project '.bin2hex(random_bytes(5));
$temporaryDirectory = '';
$fileSnapshots = array();
$createdDirectories = array();
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
	if (isModEnabled('societe') && isModEnabled('project')) {
		if ($owner->addrights(0, 'societe', '', $entity) <= 0 || $owner->addrights(0, 'projet', '', $entity) <= 0) {
			throw new RuntimeException('Unable to grant native browser business-context rights');
		}
		$thirdparty = new Societe($db);
		$thirdparty->entity = $entity;
		$thirdparty->name = $privateThirdpartyName;
		$thirdpartyId = $thirdparty->create($admin);
		if ($thirdpartyId <= 0) throw new RuntimeException('Unable to create the isolated native third party');
		$thirdpartyIds[] = $thirdpartyId;
		$project = new Project($db);
		$project->entity = $entity;
		$project->ref = 'CDAV-BROWSER-'.bin2hex(random_bytes(6));
		$project->title = $privateProjectName;
		$project->socid = $thirdpartyId;
		$project->public = 1;
		$projectId = $project->create($admin);
		if ($projectId <= 0) throw new RuntimeException('Unable to create the isolated native project');
		$projectIds[] = $projectId;
		if ($project->setValid($admin) <= 0) throw new RuntimeException('Unable to open the isolated native project for selection');
		$otherThirdparty = new Societe($db);
		$otherThirdparty->entity = $entity;
		$otherThirdparty->name = $privateThirdpartyName.' other';
		$otherThirdpartyId = (int) $otherThirdparty->create($admin);
		if ($otherThirdpartyId <= 0) throw new RuntimeException('Unable to create the second native browser company');
		$thirdpartyIds[] = $otherThirdpartyId;
		foreach (array('draft' => 0, 'replacement' => 1, 'closed' => 2, 'other' => 1) as $kind => $status) {
			$extraProject = new Project($db);
			$extraProject->entity = $entity;
			$extraProject->ref = 'CDAV-BROWSER-'.bin2hex(random_bytes(6));
			$extraProject->title = $privateProjectName.' '.$kind;
			$extraProject->socid = $kind === 'other' ? $otherThirdpartyId : $thirdpartyId;
			$extraProject->public = 1;
			$extraId = (int) $extraProject->create($admin);
			if ($extraId <= 0) throw new RuntimeException('Unable to create a native browser project lifecycle fixture');
			$projectIds[] = $extraId;
			if ($status > 0 && $extraProject->setValid($admin) <= 0) throw new RuntimeException('Unable to open a native browser project lifecycle fixture');
			if ($status === 2 && $extraProject->setClose($admin) <= 0) throw new RuntimeException('Unable to close the native browser project fixture');
			if ($kind === 'draft') $draftProjectId = $extraId;
			elseif ($kind === 'replacement') $replacementProjectId = $extraId;
			elseif ($kind === 'closed') $closedProjectId = $extraId;
			else $otherProjectId = $extraId;
		}
	}
	$password = 'Browser!'.bin2hex(random_bytes(16)).'7aA';
	$passwordResult = $owner->setPassword($admin, $password, 0, 0, 1);
	if (is_int($passwordResult) && $passwordResult < 0) throw new RuntimeException('Unable to set the fixture password');
	$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
	$photoName = 'cdav-browser-photo-'.bin2hex(random_bytes(8)).'.png';
	$photoDirectory = $conf->user->dir_output.'/'.get_exdir(0, 0, 0, 0, $owner, 'user').'photos';
	bookingBrowserImage($photoDirectory.'/'.$photoName, $png);
	$owner->photo = $photoName;
	$owner->office_phone = '+41 21 555 01 01';
	$owner->user_mobile = '+41 79 555 01 02';
	$owner->email = 'booking-professional@example.test';
	if ($owner->update($admin) <= 0) throw new RuntimeException('Unable to save the native professional identity');
	$companyName = 'CDav Booking Browser Company';
	$widePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAAEElEQVR4nGPQCFgARwzIHABuGgjB/DGEGgAAAABJRU5ErkJggg==', true);
	$squarePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGOQmxYFRAwQCgAcpgQ5XmV20AAAAABJRU5ErkJggg==', true);
	$logoName = 'cdav-browser-logo-'.bin2hex(random_bytes(8)).'.png';
	$squareName = 'cdav-browser-square-'.bin2hex(random_bytes(8)).'.png';
	bookingBrowserImage($conf->mycompany->dir_output.'/logos/'.$logoName, $widePng);
	bookingBrowserImage($conf->mycompany->dir_output.'/logos/thumbs/'.$logoName, $widePng);
	bookingBrowserImage($conf->mycompany->dir_output.'/logos/'.$squareName, $squarePng);
	bookingBrowserImage($conf->mycompany->dir_output.'/logos/thumbs/'.$squareName, $squarePng);
	foreach (array('MAIN_INFO_SOCIETE_NOM' => $companyName, 'MAIN_INFO_SOCIETE_LOGO' => $logoName,
		'MAIN_INFO_SOCIETE_LOGO_SMALL' => $logoName, 'MAIN_INFO_SOCIETE_LOGO_SQUARRED' => $squareName,
		'MAIN_INFO_SOCIETE_LOGO_SQUARRED_SMALL' => $squareName, 'MAIN_INFO_SOCIETE_LOGO_SQUARRED_MINI' => $squareName) as $setting => $value) {
		if (dolibarr_set_const($db, $setting, $value, 'chaine', 0, '', $entity) <= 0) {
			throw new RuntimeException('Unable to configure the isolated native company identity');
		}
	}
	$config = array('timezone' => 'Europe/Zurich', 'duration' => 30, 'step' => 30,
		'buffer_before' => 0, 'buffer_after' => 0, 'notice' => 0, 'horizon' => 60,
		'weekly' => array_fill(1, 7, array(array('09:00', '12:00'), array('13:00', '17:00'))));
	$profileLabel = 'Consultation atelier';
	$secondaryProfileLabel = 'Visites & maintenance';
	$tertiaryProfileLabel = 'Consultation projet';
	$profile = $service->saveProfile($ownerId, $config, true, 0, array('label' => $profileLabel));
	// Seed a publication created before administrators could retrieve its URL.
	$key = bin2hex(random_bytes(32));
	bookingBrowserQuery('UPDATE '.MAIN_DB_PREFIX."cdav_booking_profile SET token_hash = '".$db->escape(hash('sha256', $key))."'"
		.' WHERE rowid = '.$profile['id'].' AND entity = '.$entity.' AND fk_user = '.$ownerId);
	$openDay = new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Zurich'));
	$closedDay = $openDay->modify('+1 day');
	$contiguousDay = $openDay->modify('+5 days');
	$splitDay = $openDay->modify('+7 days');
	$otherSplitDay = $openDay->modify('+8 days');
	$longDay = $openDay->modify('+10 days');
	$longSplitDay = $openDay->modify('+11 days');
	$fallbackDay = $openDay->modify('+12 days');
	$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Zurich'));
	$limitMonth = $today->setDate((int) $today->format('Y'), (int) $today->format('m'), 1)->modify('+6 months');
	$limitDay = $limitMonth->setDate((int) $limitMonth->format('Y'), (int) $limitMonth->format('m'),
		min((int) $today->format('d'), (int) $limitMonth->format('t')));
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
	$conf->global->CDAV_BOOKING_ENABLED = '1';
	$hideSettings = array('USER_PUBLIC_HIDE_EMAIL' => '1', 'USER_PUBLIC_HIDE_OFFICE_PHONE' => '1',
		'USER_PUBLIC_HIDE_USER_MOBILE' => '1', 'USER_PUBLIC_HIDE_PHOTO' => '1');
	if (dol_set_user_param($db, $conf, $owner, $hideSettings, $entity) <= 0) throw new RuntimeException('Unable to configure native professional privacy');
	$hidden = $service->publicProfile($key)['professional'];
	foreach (array('email', 'phone', 'mobile', 'photo') as $field) {
		if ($hidden[$field] !== '') throw new RuntimeException('A native professional privacy setting was ignored');
	}
	if (dol_set_user_param($db, $conf, $owner, array_fill_keys(array_keys($hideSettings), '0'), $entity) <= 0) {
		throw new RuntimeException('Unable to restore visible test professional fields');
	}
	fwrite(STDOUT, "Native professional privacy settings hide all four optional public fields.\n");
	$temporaryDirectory = sys_get_temp_dir().'/cdav-browser-'.bin2hex(random_bytes(8));
	if (!mkdir($temporaryDirectory, 0700)) throw new RuntimeException('Unable to create the private fixture directory');
	$origin = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');
	$appointmentSubjects = array('single' => 'Entretien de chaudière — visite', 'lost' => 'Dépannage d’urgence',
		'continuous' => 'Révision annuelle continue', 'split' => 'Visite technique fractionnée',
		'recovery' => 'Intervention matinale après retrait', 'longsplit' => 'Intervention matin et après-midi');
	$clientAddress = "Rue de l’Église 12\n1000 Lausanne";
	$fixture = array('isolated' => true, 'booking_url' => $bookingUrl,
		'home_url' => $origin.DOL_URL_ROOT.'/index.php',
		'admin_url' => substr($bookingUrl, 0, -strlen('/public/booking.php')).'/admin/booking.php',
		'owner_id' => $ownerId, 'key' => $key, 'login' => $owner->login, 'password' => $password,
		'profile_id' => $profile['id'], 'profile_label' => $profileLabel,
		'secondary_profile_label' => $secondaryProfileLabel, 'tertiary_profile_label' => $tertiaryProfileLabel,
		'thirdparty_id' => $thirdpartyId, 'project_id' => $projectId,
		'draft_project_id' => $draftProjectId, 'replacement_project_id' => $replacementProjectId,
		'closed_project_id' => $closedProjectId, 'other_project_id' => $otherProjectId,
		'project_names' => array('open' => $privateProjectName, 'replacement' => $privateProjectName.' replacement',
			'draft' => $privateProjectName.' draft', 'closed' => $privateProjectName.' closed', 'other' => $privateProjectName.' other'),
		'php_bin' => PHP_BINARY, 'fixture_runner' => __FILE__,
		'private_thirdparty_name' => $privateThirdpartyName, 'private_project_name' => $privateProjectName,
		'date' => $openDay->format('Y-m-d'), 'closed_date' => $closedDay->format('Y-m-d'),
		'contiguous_date' => $contiguousDay->format('Y-m-d'),
		'contiguous_starts' => array($contiguousDay->setTime(9, 0)->getTimestamp(), $contiguousDay->setTime(9, 30)->getTimestamp()),
		'split_date' => $splitDay->format('Y-m-d'), 'other_split_date' => $otherSplitDay->format('Y-m-d'),
		'split_starts' => array($splitDay->setTime(9, 0)->getTimestamp(), $splitDay->setTime(13, 0)->getTimestamp(),
			$otherSplitDay->setTime(9, 0)->getTimestamp()),
		'long_date' => $longDay->format('Y-m-d'), 'long_split_date' => $longSplitDay->format('Y-m-d'),
		'long_starts' => array($longDay->setTime(8, 0)->getTimestamp(), $longDay->setTime(13, 0)->getTimestamp()),
		'long_split_starts' => array($longSplitDay->setTime(8, 0)->getTimestamp(), $longSplitDay->setTime(13, 0)->getTimestamp()),
		'fallback_date' => $fallbackDay->format('Y-m-d'),
		'fallback_starts' => array($fallbackDay->setTime(8, 0)->getTimestamp(), $fallbackDay->setTime(13, 0)->getTimestamp()),
		'calendar_month' => $today->format('Y-m'), 'six_month_limit' => $limitDay->format('Y-m-d'),
		'calendar_today' => $today->format('Y-m-d'), 'validity_date' => $openDay->modify('+14 days')->format('Y-m-d'),
		'validity_until' => $openDay->modify('+20 days')->format('Y-m-d'),
		'company_name' => $companyName, 'professional_name' => $owner->getFullName($langs),
		'company_favicon_file' => 'logos/thumbs/'.$squareName,
		'professional_email' => $owner->email, 'professional_phone_href' => 'tel:+41215550101',
		'professional_mobile_href' => 'tel:+41795550102',
		'appointment_subjects' => $appointmentSubjects, 'client_address' => $clientAddress,
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
	$profiles = $service->profilesForOwner($ownerId);
	if (count($profiles) !== 3) throw new RuntimeException('Browser administration lost or duplicated an independent profile');
	$profileByLabel = array_column($profiles, null, 'label');
	if (!isset($profileByLabel[$profileLabel], $profileByLabel[$secondaryProfileLabel], $profileByLabel[$tertiaryProfileLabel])
		|| $profileByLabel[$secondaryProfileLabel]['config']['duration'] !== 75
		|| $profileByLabel[$secondaryProfileLabel]['thirdparty_id'] !== $thirdpartyId
		|| $profileByLabel[$secondaryProfileLabel]['project_id'] !== $projectId
		|| isset($profileByLabel[$secondaryProfileLabel]['config']['valid_from']) || isset($profileByLabel[$secondaryProfileLabel]['config']['valid_until'])
		|| $profileByLabel[$tertiaryProfileLabel]['enabled'] !== false
		|| $profileByLabel[$tertiaryProfileLabel]['config']['duration'] !== 90) {
		throw new RuntimeException('Browser profile settings, links or optional native business context were not isolated');
	}
	fwrite(STDOUT, "Three collaborator profiles retain independent settings, publication keys and native business context.\n");
	// One absence, two single bookings and seven grouped/recovered segments.
	$eventCount = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX
		.'actioncomm WHERE fk_user_action = '.$ownerId.' AND entity = '.$entity));
	if (!$eventCount || (int) $eventCount->nb !== 10) throw new RuntimeException('Browser bookings duplicated or lost a native segment');
	$expectedGroups = array(
		'continuous' => array('starts' => array($contiguousDay->setTime(9, 0)->getTimestamp()), 'duration' => 3600),
		'split' => array('starts' => array($splitDay->setTime(9, 0)->getTimestamp(), $splitDay->setTime(13, 0)->getTimestamp(),
			$otherSplitDay->setTime(9, 0)->getTimestamp()), 'duration' => 1800),
		'recovery' => array('starts' => array($longDay->setTime(8, 0)->getTimestamp()), 'duration' => 14400),
		'longsplit' => array('starts' => array($longSplitDay->setTime(8, 0)->getTimestamp(), $longSplitDay->setTime(13, 0)->getTimestamp()), 'duration' => 14400)
	);
	foreach ($expectedGroups as $kind => $expectedGroup) {
		$subject = $appointmentSubjects[$kind];
		$expectedStarts = $expectedGroup['starts'];
		$seenStarts = array();
		$events = bookingBrowserQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId
			.' AND entity = '.$entity." AND label = '".$db->escape($subject)."' ORDER BY id");
		while ($row = $db->fetch_object($events)) {
			$event = new ActionComm($db);
			if ($event->fetch((int) $row->id) <= 0 || $event->datef - $event->datep !== $expectedGroup['duration']) {
				throw new RuntimeException('Browser selection has an incorrect native duration');
			}
			if ($event->label !== $subject || $event->location !== $clientAddress
				|| strpos($event->note_private, $subject) === false || strpos($event->note_private, $clientAddress) === false) {
				throw new RuntimeException('Browser selection lost or truncated its native subject/client address');
			}
			$seenStarts[] = (int) $event->datep;
		}
		sort($seenStarts);
		sort($expectedStarts);
		if ($seenStarts !== $expectedStarts) throw new RuntimeException('Browser selection has incorrect native segment dates');
	}
	foreach (array('single', 'lost') as $kind) {
		$rows = bookingBrowserQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_action = '.$ownerId
			.' AND entity = '.$entity." AND label = '".$db->escape($appointmentSubjects[$kind])."'");
		$row = $db->fetch_object($rows);
		$event = new ActionComm($db);
		if (!$row || $event->fetch((int) $row->id) <= 0 || $event->location !== $clientAddress || $db->fetch_object($rows)) {
			throw new RuntimeException('Browser single/lost-response booking lost its native subject or address');
		}
	}
	$receiptGroups = bookingBrowserQuery('SELECT COUNT(m.fk_actioncomm) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request r INNER JOIN '
		.MAIN_DB_PREFIX.'cdav_booking_request_event m ON m.request_hash = r.request_hash INNER JOIN '
		.MAIN_DB_PREFIX.'cdav_booking_profile p ON p.rowid = r.fk_profile WHERE p.fk_user = '.$ownerId
		.' GROUP BY r.request_hash ORDER BY nb');
	$receiptCounts = array();
	while ($row = $db->fetch_object($receiptGroups)) $receiptCounts[] = (int) $row->nb;
	if ($receiptCounts !== array(1, 1, 1, 1, 2, 3)) throw new RuntimeException('Browser grouped booking receipts do not match their native segments');
	fwrite(STDOUT, "Native browser selections: adjacent units, three short split segments, recovered four-hour event and two four-hour lunch segments; shared receipts verified.\n");
} catch (Throwable $exception) {
	$failure = $exception;
	fwrite(STDERR, 'Browser checks failed: '.get_class($exception).PHP_EOL);
} finally {
	try {
		if ($ownerId > 0) {
			foreach ($service->profilesForOwner($ownerId) as $cleanupProfile) $service->disableProfile($ownerId, $cleanupProfile['id']);
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
			$mappingRemaining = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_request_event'));
			if (!$mappingRemaining || !$mappingSnapshot || (int) $mappingRemaining->nb !== (int) $mappingSnapshot->nb) {
				throw new RuntimeException('Browser group receipt mappings remain after native cleanup');
			}
		}
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
		fwrite(STDERR, 'Native browser fixture cleanup failed: '.get_class($cleanupFailure).PHP_EOL);
	}
	try {
		foreach (array_reverse($projectIds) as $cleanupProjectId) {
			$project = new Project($db);
			if ($project->fetch($cleanupProjectId) > 0 && $project->delete($admin) <= 0) throw new RuntimeException('Native browser project cleanup failed');
		}
		foreach (array_reverse($thirdpartyIds) as $cleanupThirdpartyId) {
			$thirdparty = new Societe($db);
			if ($thirdparty->fetch($cleanupThirdpartyId) > 0) {
				// Societe's raw Agenda cascade omits resources. Remove only this
				// fixture's new history natively and avoid recreating deletion history.
				$companyEvents = bookingBrowserQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_soc = '.$cleanupThirdpartyId
					.' AND id > '.((int) $agendaSnapshot->last_id));
				$companyIds = array();
				while ($row = $db->fetch_object($companyEvents)) $companyIds[] = (int) $row->id;
				foreach ($companyIds as $eventId) {
					$event = new ActionComm($db);
					if ($event->fetch($eventId) <= 0 || $event->delete($admin) <= 0) throw new RuntimeException('Native browser company history cleanup failed');
				}
				if ($thirdparty->delete($cleanupThirdpartyId, $admin, 0) <= 0) throw new RuntimeException('Native browser third-party cleanup failed');
			}
		}
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
		fwrite(STDERR, 'Native browser business-context cleanup failed: '.get_class($cleanupFailure).PHP_EOL);
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
		$orphanRemaining = $db->fetch_object(bookingBrowserQuery('SELECT COUNT(*) AS nb'.$orphanScope));
		if (!$orphanRemaining || (int) $orphanRemaining->nb !== (int) $orphanSnapshot->nb) {
			throw new RuntimeException('Browser fixture introduced orphaned native Agenda resources');
		}
		fwrite(STDOUT, 'Native automatic Agenda records restored: '.((int) $automaticSnapshot->nb)
			.' -> '.((int) $automaticRemaining->nb).' (removed '.count($automaticIds).').'.PHP_EOL);
		fwrite(STDOUT, 'Native Agenda orphan resources unchanged: '.((int) $orphanSnapshot->nb).' -> '.((int) $orphanRemaining->nb).'.'.PHP_EOL);
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
	try {
		foreach ($fileSnapshots as $path => $bytes) {
			if ($bytes === null) {
				if (is_file($path) && !unlink($path)) throw new RuntimeException('Unable to remove an isolated browser image');
			} else {
				if (dol_mkdir(dirname($path)) < 0 || file_put_contents($path, $bytes) === false || file_get_contents($path) !== $bytes) {
					throw new RuntimeException('Unable to restore a native image snapshot');
				}
			}
		}
		$directories = array_values($createdDirectories);
		usort($directories, static function ($left, $right) {
			return strlen($right) <=> strlen($left); });
		foreach ($directories as $directory) {
			if (is_dir($directory) && !rmdir($directory)) throw new RuntimeException('Unable to remove an isolated browser image directory');
		}
		fwrite(STDOUT, "Native company constants and isolated image files restored.\n");
	} catch (Throwable $cleanupFailure) {
		$failure = $failure ?: $cleanupFailure;
		fwrite(STDERR, 'Browser native image restoration failed: '.get_class($cleanupFailure).PHP_EOL);
	}
	if ($temporaryDirectory !== '') {
		if (is_file($temporaryDirectory.'/fixture.json')) unlink($temporaryDirectory.'/fixture.json');
		rmdir($temporaryDirectory);
	}
	putenv('CDAV_BOOKING_BROWSER_FIXTURE');
}
if ($failure) exit(1);
fwrite(STDOUT, "Browser fixture cleanup and configuration restoration passed.\n");
