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
if (!str_contains(strtolower((string) $dolibarr_main_db_name), '_test_')) {
	fwrite(STDERR, "The database must be explicitly named as a test database.\n");
	exit(2);
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once dirname(__DIR__).'/class/CDavBookingService.php';
require_once dirname(__DIR__).'/class/CDavBookingSchema.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
$langs->load('cdav@cdav');

/**
 * Reject a failed native or HTTP assertion.
 * @param mixed $condition Expected truthy condition
 * @param string $message Failure explanation
 * @return void
 */
function profilesLiveCheck($condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Execute only fixture, technical metadata or inspection SQL.
 * @param string $sql Test statement
 * @return mixed Native result
 */
function profilesLiveQuery(string $sql)
{
	global $db;
	$result = $db->query($sql);
	if (!$result) throw new RuntimeException('Profile-booking fixture SQL failed');
	return $result;
}

/**
 * Fetch one inspection row.
 * @param string $sql Test statement
 * @return object|false|null Native row
 */
function profilesLiveRow(string $sql)
{
	global $db;
	return $db->fetch_object(profilesLiveQuery($sql));
}

/**
 * Build a real public request with its own native browser session.
 * @param array $fields Query or form fields
 * @param string $cookie Private cookie jar
 * @param bool $post Whether to submit a form
 * @return CurlHandle Prepared request
 */
function profilesLiveHandle(array $fields, string $cookie, bool $post = false)
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
function profilesLiveHttp(array $fields, string $cookie, bool $post = false): array
{
	$handle = profilesLiveHandle($fields, $cookie, $post);
	$body = curl_exec($handle);
	profilesLiveCheck($body !== false, 'Profile-booking HTTP transport failed');
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
function profilesLiveForm(string $key, string $date): array
{
	global $temporaryDirectory;
	$cookie = tempnam($temporaryDirectory, 'cookie-');
	$response = profilesLiveHttp(array('key' => $key, 'date' => $date, 'format' => 'json'), $cookie);
	profilesLiveCheck($response['status'] === 200 && !empty($response['json']['request']) && !empty($response['json']['token']), 'Cannot get public profile-booking form');
	return array('key' => $key, 'date' => $date, 'cookie' => $cookie, 'ticket' => $response['json']['request'],
		'token' => $response['json']['token'], 'slots' => $response['json']['slots']);
}

/**
 * Build the new group-booking POST without exposing technical identifiers.
 * @param array $form Fresh form
 * @param array $starts Chosen start timestamps
 * @param array $changes Overrides for rejection scenarios
 * @return array Encodable form fields
 */
function profilesLiveFields(array $form, array $starts, array $changes = array()): array
{
	return array_replace(array('key' => $form['key'], 'date' => $form['date'], 'format' => 'json',
		'token' => $form['token'], 'request' => $form['ticket'], 'selection' => implode(',', $starts),
		'name' => 'CDav isolated group fixture', 'email' => 'visitor-profiles@example.test', 'phone' => '', 'website' => '',
		'subject' => 'Visite d’entretien client', 'address' => "Rue de l’Église 12\n1000 Lausanne"), $changes);
}

/**
 * Submit a selected group using the native session of its form.
 * @param array $form Fresh form
 * @param array $starts Chosen start timestamps
 * @param array $changes Overrides for rejection scenarios
 * @return array Public response
 */
function profilesLivePost(array $form, array $starts, array $changes = array()): array
{
	return profilesLiveHttp(profilesLiveFields($form, $starts, $changes), $form['cookie'], true);
}

/**
 * Prove two independent group requests reach the same native owner lock.
 * @param array $forms Independent browser forms
 * @param array $starts Selected groups
 * @param int $ownerId Native owner providing the lock barrier
 * @return array HTTP statuses
 */
function profilesLiveRace(array $forms, array $starts, int $ownerId): array
{
	global $db, $dolibarr_main_db_name;
	$multi = curl_multi_init();
	$handles = array();
	profilesLiveCheck($db->begin(), 'Cannot open native group-race barrier');
	profilesLiveQuery('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$ownerId.' FOR UPDATE');
	try {
		foreach ($forms as $index => $form) {
			$handles[] = profilesLiveHandle(profilesLiveFields($form, $starts[$index]), $form['cookie'], true);
			curl_multi_add_handle($multi, $handles[$index]);
		}
		$deadline = microtime(true) + 10;
		$ownerLock = 'FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$ownerId.' AND ';
		$waiting = 0;
		do {
			profilesLiveCheck(curl_multi_exec($multi, $running) === CURLM_OK, 'Cannot dispatch concurrent group requests');
			$waiting = 0;
			$rows = profilesLiveQuery('SHOW FULL PROCESSLIST');
			while ($row = $db->fetch_object($rows)) {
				if (($row->db ?? '') === $dolibarr_main_db_name && strpos((string) ($row->Info ?? ''), 'FOR UPDATE') !== false
					&& strpos((string) $row->Info, $ownerLock) !== false) $waiting++;
			}
			if ($waiting >= 2) break;
			curl_multi_select($multi, 0.02);
		} while ($running && microtime(true) < $deadline);
		profilesLiveCheck($db->commit(), 'Cannot release native group-race barrier');
		do {
			profilesLiveCheck(curl_multi_exec($multi, $running) === CURLM_OK, 'Concurrent group response failed');
			if ($running) curl_multi_select($multi, 0.05);
		} while ($running);
		$statuses = $responses = array();
		foreach ($handles as $handle) {
			profilesLiveCheck(curl_errno($handle) === 0, 'Concurrent group HTTP request failed');
			$statuses[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			$responses[] = json_decode(curl_multi_getcontent($handle), true);
			curl_multi_remove_handle($multi, $handle);
		}
		profilesLiveCheck($waiting >= 2, 'Two independent group workers must reach the owner-row lock; waiting='
			.$waiting.'; statuses='.json_encode($statuses));
		return array('statuses' => $statuses, 'responses' => $responses, 'waiting' => $waiting);
	} catch (Throwable $exception) {
		$db->rollback();
		throw $exception;
	} finally {
		unset($handles, $multi);
	}
}

/**
 * Require a public-safe rejection from one administration operation.
 * @param callable $operation Operation under test
 * @param int $status Expected public HTTP status
 * @return void
 */
function profilesLiveReject(callable $operation, int $status): void
{
	try { $operation(); } catch (\Dolibarr\CDav\BookingException $exception) {
		profilesLiveCheck($exception->getCode() === $status, 'A rejected profile operation must keep its safe status');
		return;
	}
	throw new RuntimeException('An invalid profile operation was unexpectedly accepted');
}

/**
 * Decode the fixture mailer's plain or multipart MIME using native PHP codecs.
 * @param string $mime Captured local SMTP message
 * @return string Decoded headers and text parts
 */
function profilesLiveMailText(string $mime): string
{
	$parts = preg_split('/\r?\n\r?\n/', $mime, 2);
	$header = $parts[0];
	$body = $parts[1] ?? '';
	if (preg_match('/boundary="?([^"\r\n; ]+)/i', $header, $boundary)) {
		$text = '';
		foreach (explode('--'.$boundary[1], $body) as $part) {
			if (str_contains($part, 'Content-Type:')) $text .= profilesLiveMailText(ltrim($part))."\n";
		}
		return $text;
	}
	if (preg_match('/^Content-Transfer-Encoding:\s*base64/im', $header)) {
		$decoded = base64_decode(preg_replace('/\s+/', '', $body), true);
		profilesLiveCheck($decoded !== false, 'Captured MIME base64 must be valid');
		$body = $decoded;
	} elseif (preg_match('/^Content-Transfer-Encoding:\s*quoted-printable/im', $header)) {
		$body = quoted_printable_decode($body);
	}
	return iconv_mime_decode($header, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')."\n".$body;
}

/**
 * Read only messages belonging to this committed public receipt.
 * @param string $reference Booking reference
 * @return array Local captured envelopes and their decoded text
 */
function profilesLiveMails(string $reference): array
{
	global $mailSink;
	$result = array();
	foreach (glob($mailSink.'/*.json') as $file) {
		$message = json_decode(file_get_contents($file), true);
		profilesLiveCheck(is_array($message) && is_string($message['mime'] ?? null), 'The SMTP sink must preserve its MIME envelope');
		$message['sender'] = trim(preg_replace('/^MAIL FROM:\s*/i', '', (string) $message['sender']), ' <>');
		$message['recipients'] = array_map(static function ($recipient) {
			return trim(preg_replace('/^RCPT TO:\s*/i', '', (string) $recipient), ' <>');
		}, $message['recipients']);
		$message['text'] = profilesLiveMailText($message['mime']);
		if (str_contains($message['text'], $reference)) $result[] = $message;
	}
	return $result;
}

/**
 * Create an internal collaborator with native Agenda and optional CRM rights.
 * @param string $login Unique fixture login
 * @param bool $businessRights Whether to grant access to native CRM and projects
 * @return User Fixture owner
 */
function profilesLiveOwner(string $login, bool $businessRights): User
{
	global $db, $admin, $entity, $ownerIds;
	$owner = new User($db);
	$owner->entity = $entity;
	$owner->login = $login;
	$owner->firstname = 'CDav';
	$owner->lastname = 'Profiles isolated fixture';
	$owner->email = $login.'@example.test';
	$owner->admin = 0;
	$owner->employee = 1;
	$owner->statut = $owner->status = 1;
	$id = $owner->create($admin);
	profilesLiveCheck($id > 0, 'Cannot create isolated collaborator: '.$owner->error);
	$ownerIds[] = $id;
	$rights = profilesLiveQuery('SELECT id FROM '.MAIN_DB_PREFIX."rights_def WHERE (module = 'agenda' AND perms = 'myactions')"
		.($businessRights ? " OR module IN ('societe','projet')" : ''));
	$rightIds = array();
	while ($right = $db->fetch_object($rights)) $rightIds[] = (int) $right->id;
	foreach ($rightIds as $rightId) profilesLiveCheck($owner->addrights($rightId, '', '', $entity) > 0, 'Cannot grant fixture native rights');
	profilesLiveCheck($owner->loadRights('', 1) >= 0, 'Cannot reload native collaborator rights');
	return $owner;
}

/**
 * Create native third parties and projects, including foreign-entity fixtures.
 * @param string $label Unique fixture name
 * @param int $targetEntity Native entity for the fixture
 * @param int $thirdpartyId Zero creates a company; positive creates its project
 * @param bool $public Whether the project is public
 * @return Societe|Project Native fixture object
 */
function profilesLiveBusiness(string $label, int $targetEntity, int $thirdpartyId = 0, bool $public = true)
{
	global $db, $conf, $admin, $companyIds, $projectIds;
	$originalEntity = $conf->entity;
	$conf->entity = $targetEntity;
	try {
		if ($thirdpartyId) {
			$object = new Project($db);
			$object->ref = 'CP-'.bin2hex(random_bytes(6));
			$object->title = $label;
			$object->socid = $thirdpartyId;
			$object->public = $public ? 1 : 0;
			$object->status = 1;
			$id = $object->create($admin);
			if ($id > 0) $projectIds[$id] = $targetEntity;
		} else {
			$object = new Societe($db);
			$object->name = $label;
			$object->client = 1;
			$object->code_client = 'CP'.bin2hex(random_bytes(5));
			$id = $object->create($admin);
			if ($id > 0) $companyIds[$id] = $targetEntity;
		}
		profilesLiveCheck($id > 0 && $object->fetch($id) > 0 && (int) $object->entity === $targetEntity, 'Cannot create native business fixture in its explicit entity');
		return $object;
	} finally {
		$conf->entity = $originalEntity;
	}
}

$entity = (int) $conf->entity;
$prefix = 'cdav-profiles-'.bin2hex(random_bytes(6));
$temporaryDirectory = sys_get_temp_dir().'/'.$prefix;
profilesLiveCheck(mkdir($temporaryDirectory, 0700), 'Cannot create private profile fixture directory');
$mailSink = realpath((string) getenv('CDAV_BOOKING_MAIL_SINK'));
$mailServer = getDolGlobalString('MAIN_MAIL_SMTP_SERVER');
$rejectFile = '/tmp/cdav-profiles-qa/smtp-reject';
if ($mailSink !== '/tmp/cdav-profiles-qa/messages' || !is_dir($mailSink)
	|| getDolGlobalString('MAIN_MAIL_SENDMODE') !== 'smtps'
	|| !in_array($mailServer, array('localhost', '127.0.0.1'), true) || gethostbyname($mailServer) !== '127.0.0.1'
	|| getDolGlobalInt('MAIN_MAIL_SMTP_PORT') !== 9125 || getDolGlobalInt('MAIN_DISABLE_ALL_MAILS')
	|| file_exists($rejectFile)) {
	fwrite(STDERR, "Use only the explicitly isolated loopback SMTP sink on port 9125.\n");
	rmdir($temporaryDirectory);
	exit(2);
}
$ownerIds = $companyIds = $projectIds = $constantSnapshots = $rateSnapshot = array();
$rejectCreated = false;
$triggerPath = '';
$failure = null;
$admin = null;
$initialAgendaId = (int) profilesLiveRow('SELECT COALESCE(MAX(id),0) AS id FROM '.MAIN_DB_PREFIX.'actioncomm')->id;
$initialOrphans = profilesLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources r LEFT JOIN '
	.MAIN_DB_PREFIX.'actioncomm a ON a.id = r.fk_actioncomm WHERE a.id IS NULL')->nb;
$proof = array('dolibarr' => DOL_VERSION, 'php' => PHP_VERSION, 'checks' => array());

try {
	profilesLiveCheck(isModEnabled('societe') && isModEnabled('project'), 'Activate native third parties and projects in the disposable instance');
	$row = profilesLiveRow('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1');
	$admin = new User($db);
	profilesLiveCheck($row && $admin->fetch((int) $row->rowid) > 0 && $admin->loadRights() >= 0, 'Cannot load isolated native administrator');
	$GLOBALS['user'] = $admin;
	foreach (array('CDAV_BOOKING_ENABLED' => '1', 'CDAV_BOOKING_ALLOW_LOCAL_HTTP' => '1', 'MAIN_SECURITY_CSRF_WITH_TOKEN' => '1') as $name => $value) {
		$constantSnapshots[$name] = profilesLiveRow('SELECT * FROM '.MAIN_DB_PREFIX."const WHERE name = '".$db->escape($name)."' AND entity = ".$entity);
		profilesLiveCheck(dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $entity) > 0, 'Cannot configure the disposable profile fixture');
		$conf->global->$name = $value;
	}
	$rates = profilesLiveQuery('SELECT bucket_hash,hits,expires_at FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	while ($row = $db->fetch_object($rates)) $rateSnapshot[] = $row;
	profilesLiveQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
	$owner = profilesLiveOwner($prefix, true);
	$restrictedOwner = profilesLiveOwner($prefix.'-restricted', false);
	profilesLiveCheck(dol_set_user_param($db, $conf, $owner, array('USER_PUBLIC_HIDE_EMAIL' => '1'), $entity) > 0, 'Cannot hide the collaborator email using native privacy settings');
	$company = profilesLiveBusiness($prefix.' customer', $entity);
	$otherCompany = profilesLiveBusiness($prefix.' other customer', $entity);
	$foreignCompany = profilesLiveBusiness($prefix.' foreign customer', $entity + 100);
	$project = profilesLiveBusiness($prefix.' project', $entity, (int) $company->id);
	$otherProject = profilesLiveBusiness($prefix.' other project', $entity, (int) $otherCompany->id);
	$foreignProject = profilesLiveBusiness($prefix.' foreign project', $entity + 100, (int) $foreignCompany->id);
	$service = new \Dolibarr\CDav\BookingService($db, $entity);
	$config = array('timezone' => 'Europe/Zurich', 'duration' => 30, 'step' => 30,
		'buffer_before' => 0, 'buffer_after' => 0, 'notice' => 0, 'horizon' => 6, 'horizon_unit' => 'months',
		'allow_contiguous' => true, 'allow_split' => true,
		'weekly' => array_fill(1, 7, array(array('09:00', '12:00'), array('13:00', '17:00'))));
	$first = $service->saveProfile($owner->id, $config, true);
	$key1 = $service->rotateToken($owner->id);
	$second = $service->saveProfile($owner->id, $config, true, 0, array('label' => 'Calendrier générique Équipe'));
	$key2 = $service->rotateToken($owner->id, $second['id']);
	profilesLiveCheck($first['id'] !== $second['id'] && $key1 !== $key2 && count($service->profilesForOwner($owner->id)) === 2,
		'One collaborator must have independent profile identifiers and links');
	profilesLiveCheck($service->profileForOwner($owner->id)['id'] === $first['id'], 'The old API must target only its stable first profile');
	$secondConfig = array_replace($config, array('step' => 15));
	$service->saveProfile($owner->id, $secondConfig, true, $second['id'], array('label' => 'Autre calendrier'));
	profilesLiveCheck($service->publicProfile($key1)['config']['step'] === 30 && $service->publicProfile($key2)['config']['step'] === 15,
		'Changing one profile must preserve the other profile settings');
	$service->disableProfile($owner->id, $second['id']);
	profilesLiveReject(static function () use ($service, $key2) {
		$service->publicProfile($key2);
	}, 404);
	profilesLiveCheck($service->publicProfile($key1)['id'] === $first['id'], 'Revoking one profile must leave the first link active');
	$service->saveProfile($owner->id, $config, true, $second['id']);
	$key2 = $service->rotateToken($owner->id, $second['id']);
	$restrictedProfile = $service->saveProfile($restrictedOwner->id, $config, true);
	$restrictedKey = $service->rotateToken($restrictedOwner->id, $restrictedProfile['id']);
	profilesLiveReject(static function () use ($service, $owner, $restrictedProfile, $config) {
		$service->saveProfile($owner->id, $config, true, $restrictedProfile['id']);
	}, 404);
	profilesLiveReject(static function () use ($service, $owner, $restrictedProfile) {
		$service->rotateToken($owner->id, $restrictedProfile['id']);
	}, 404);
	profilesLiveReject(static function () use ($service, $owner, $restrictedProfile) {
		$service->disableProfile($owner->id, $restrictedProfile['id']);
	}, 404);
	profilesLiveCheck($service->profileForOwner($owner->id, $restrictedProfile['id']) === null, 'A forged profile identifier must never select another collaborator');
	profilesLiveCheck($service->publicProfile($restrictedKey)['id'] === $restrictedProfile['id'], 'Forged mutations must leave the other collaborator publication unchanged');
	$proof['checks'][] = 'P01 independent profiles, targeted editing and revocation, legacy API and owner isolation';

	$metadata = array('label' => 'Intervention client École', 'thirdparty_id' => (int) $company->id, 'project_id' => (int) $project->id);
	$linked = $service->saveProfile($owner->id, $config, true, 0, $metadata);
	$key3 = $service->rotateToken($owner->id, $linked['id']);
	$tierOnly = $service->saveProfile($owner->id, $config, true, 0, array('label' => 'Client sans projet', 'thirdparty_id' => (int) $company->id));
	$tierKey = $service->rotateToken($owner->id, $tierOnly['id']);
	foreach (array(
		array('thirdparty_id' => 0, 'project_id' => (int) $project->id),
		array('thirdparty_id' => (int) $company->id, 'project_id' => (int) $otherProject->id),
		array('thirdparty_id' => (int) $foreignCompany->id, 'project_id' => 0),
		array('thirdparty_id' => (int) $company->id, 'project_id' => (int) $foreignProject->id),
		array('thirdparty_id' => array($company->id)),
		array('label' => '<script>invalid</script>')
	) as $invalidMetadata) {
		profilesLiveReject(static function () use ($service, $owner, $config, $metadata, $invalidMetadata) {
			$service->saveProfile($owner->id, $config, true, 0, array_replace($metadata, $invalidMetadata));
		}, 422);
	}
	profilesLiveReject(static function () use ($service, $restrictedOwner, $config, $metadata) {
		$service->saveProfile($restrictedOwner->id, $config, true, 0, $metadata);
	}, 422);
	profilesLiveCheck($service->publicProfile($key3)['professional']['email'] === '', 'The collaborator private email must stay hidden on the public profile');
	$crmRights = profilesLiveQuery('SELECT id FROM '.MAIN_DB_PREFIX."rights_def WHERE module='societe'");
	$crmRightIds = array();
	while ($right = $db->fetch_object($crmRights)) $crmRightIds[] = (int) $right->id;
	profilesLiveCheck($owner->delrights(0, 'societe', '', $entity, 1) > 0, 'Cannot revoke fixture collaborator CRM access natively');
	profilesLiveReject(static function () use ($service, $key3) {
		$service->publicProfile($key3);
	}, 404);
	profilesLiveCheck($service->publicProfile($key1)['id'] === $first['id'], 'Revoked CRM access must not disable a generic Agenda profile');
	foreach ($crmRightIds as $rightId) profilesLiveCheck($owner->addrights($rightId, '', '', $entity) > 0, 'Cannot restore native fixture CRM access');
	$moduleSnapshot = $conf->modules;
	try {
		foreach (array('societe', 'thirdparty', 'projet', 'project') as $moduleName) unset($conf->modules[$moduleName]);
		profilesLiveCheck($service->publicProfile($key1)['id'] === $first['id'], 'An unlinked profile must not require CRM or projects modules');
		profilesLiveReject(static function () use ($service, $key3) {
			$service->publicProfile($key3);
		}, 404);
	} finally {
		$conf->modules = $moduleSnapshot;
	}
	profilesLiveCheck(\Dolibarr\CDav\BookingSchema::migrate($db) > 0 && \Dolibarr\CDav\BookingSchema::migrate($db) > 0,
		'Repeated native migration must remain idempotent');
	profilesLiveCheck($service->publicProfile($key1)['id'] === $first['id'] && $service->publicProfile($key3)['id'] === $linked['id'],
		'Migration must preserve every existing profile identifier and key');
	$proof['checks'][] = 'P02 native business scope, strict metadata, CRM rights rechecked, unlinked module independence and idempotent schema';

	$day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->modify('+3 days')->setTime(0, 0);
	$at = static function (int $offset, int $hour, int $minute = 0) use ($day): int {
		return $day->modify('+'.$offset.' days')->setTime($hour, $minute)->getTimestamp();
	};
	$form = profilesLiveForm($key3, $day->format('Y-m-d'));
	$starts = array($at(0, 9, 30), $at(0, 13, 30));
	$accepted = profilesLivePost($form, $starts);
	profilesLiveCheck($accepted['status'] === 201 && count($accepted['json']['segments']) === 2
		&& empty($accepted['json']['notification_warning']), 'The linked profile must create its group and send both native confirmations: '.json_encode($accepted));
	$reference = $accepted['json']['reference'];
	$members = profilesLiveQuery('SELECT m.fk_actioncomm FROM '.MAIN_DB_PREFIX.'cdav_booking_request_event m INNER JOIN '
		.MAIN_DB_PREFIX."cdav_booking_request r ON r.request_hash=m.request_hash WHERE r.reference='".$db->escape($reference)."' ORDER BY m.segment_no");
	$memberCount = 0;
	while ($member = $db->fetch_object($members)) {
		$event = new ActionComm($db);
		profilesLiveCheck($event->fetch((int) $member->fk_actioncomm) > 0 && (int) $event->socid === (int) $company->id
			&& (int) $event->fk_project === (int) $project->id, 'Every native segment must be linked to the selected third party and project');
		$memberCount++;
	}
	profilesLiveCheck($memberCount === 2, 'The group must contain exactly two native business events');
	$mails = profilesLiveMails($reference);
	profilesLiveCheck(count($mails) === 2, 'A committed group must produce exactly two SMTP messages');
	$recipients = array();
	foreach ($mails as $mail) {
		profilesLiveCheck(count($mail['recipients']) === 1 && $mail['sender'] === 'booking-sender@example.test', 'Customer and collaborator require separate native mail envelopes');
		$recipients[] = $mail['recipients'][0];
		profilesLiveCheck(!preg_match('/^(?:Cc|Bcc):\s*\S/im', $mail['mime']), 'Native confirmations must not use CC or BCC');
		foreach (array($metadata['label'], 'Visite d’entretien client', 'Rue de l’Église 12', '1000 Lausanne', 'Europe/Zurich', '09:30', '13:30') as $value) {
			profilesLiveCheck(str_contains($mail['text'], $value), 'Confirmation content must retain Unicode details, context and every booked period');
		}
		if ($mail['recipients'][0] === 'visitor-profiles@example.test') {
			profilesLiveCheck(!str_contains($mail['text'], $owner->email), 'The visitor confirmation must not disclose the collaborator hidden email');
		}
	}
	sort($recipients);
	$expectedRecipients = array($owner->email, 'visitor-profiles@example.test');
	sort($expectedRecipients);
	profilesLiveCheck($recipients === $expectedRecipients, 'The private owner email must still receive its own confirmation');
	$states = profilesLiveRow('SELECT mail_customer,mail_owner FROM '.MAIN_DB_PREFIX."cdav_booking_request WHERE reference='".$db->escape($reference)."'");
	profilesLiveCheck((int) $states->mail_customer === 2 && (int) $states->mail_owner === 2, 'Both accepted SMTP confirmations must have persisted sent states');
	$replay = profilesLivePost($form, $starts);
	profilesLiveCheck($replay['status'] === 200 && $replay['json']['replayed'] && count(profilesLiveMails($reference)) === 2,
		'Replaying a confirmed group must create neither event nor duplicate mail');
	$busyForm = profilesLiveForm($key1, $day->format('Y-m-d'));
	profilesLiveCheck(!in_array($starts[0], array_column($busyForm['slots'], 'start'), true), 'One collaborator agenda must be shared across all profile links');
	profilesLiveCheck(profilesLivePost($busyForm, array($starts[0]))['status'] === 409, 'Another profile cannot reserve the same collaborator occupation');
	$proof['checks'][] = 'P03 native segment business links, two separate SMTP confirmations, private owner email and replay deduplication';

	$tierForm = profilesLiveForm($tierKey, $day->modify('+1 day')->format('Y-m-d'));
	$tierResponse = profilesLivePost($tierForm, array($at(1, 9, 30)));
	profilesLiveCheck($tierResponse['status'] === 201, 'A third-party-only profile must allow an appointment without a project');
	$tierEventRow = profilesLiveRow('SELECT fk_actioncomm FROM '.MAIN_DB_PREFIX."cdav_booking_request WHERE reference='".$db->escape($tierResponse['json']['reference'])."'");
	$tierEvent = new ActionComm($db);
	profilesLiveCheck($tierEvent->fetch((int) $tierEventRow->fk_actioncomm) > 0 && (int) $tierEvent->socid === (int) $company->id && (int) $tierEvent->fk_project === 0,
		'An optional project must remain absent in the native Agenda event');
	$staleForm = profilesLiveForm($key3, $day->modify('+2 days')->format('Y-m-d'));
	$service->saveProfile($owner->id, $config, true, $linked['id'], array_replace($metadata,
		array('thirdparty_id' => (int) $otherCompany->id, 'project_id' => (int) $otherProject->id)));
	profilesLiveCheck(profilesLivePost($staleForm, array($at(2, 9, 30)))['status'] === 409, 'Changing native business context must invalidate an already issued ticket');
	$service->saveProfile($owner->id, $config, true, $linked['id'], $metadata);
	$crossForm = profilesLiveForm($key3, $day->modify('+2 days')->format('Y-m-d'));
	profilesLiveCheck(profilesLivePost($crossForm, array($at(2, 9, 30)), array('key' => $key1))['status'] === 409, 'A ticket issued for one profile cannot authorize another profile');
	$forged = profilesLiveFields($crossForm, array($at(2, 9, 30)), array('thirdparty_id' => $otherCompany->id));
	profilesLiveCheck(profilesLiveHttp($forged, $crossForm['cookie'], true)['status'] === 422, 'Anonymous form fields cannot override server-owned business context');
	$proof['checks'][] = 'P04 optional project, stale context ticket, cross-profile ticket and forged public context rejected';

	$raceDate = $day->modify('+3 days')->format('Y-m-d');
	$race = profilesLiveRace(array(profilesLiveForm($key1, $raceDate), profilesLiveForm($key2, $raceDate)),
		array(array($at(3, 10)), array($at(3, 10))), (int) $owner->id);
	$statuses = $race['statuses'];
	sort($statuses);
	profilesLiveCheck($statuses === array(201, 409) && $race['waiting'] >= 2, 'Independent profile requests must serialize on the same native owner row');
	$raceReferences = array();
	foreach ($race['responses'] as $response) if (isset($response['reference'])) $raceReferences[] = $response['reference'];
	profilesLiveCheck(count($raceReferences) === 1 && count(profilesLiveMails($raceReferences[0])) === 2, 'Only the winning profile race may send confirmation messages');
	$proof['checks'][] = 'P05 real two-worker owner lock race across distinct profiles produces one201 and one409';

	$failedForm = profilesLiveForm($key1, $day->modify('+4 days')->format('Y-m-d'));
	profilesLiveCheck(file_put_contents($rejectFile, 'reject disposable profile SMTP') !== false, 'Cannot enable the isolated SMTP rejection fixture');
	$rejectCreated = true;
	$failedMail = profilesLivePost($failedForm, array($at(4, 10)));
	profilesLiveCheck($failedMail['status'] === 201 && !empty($failedMail['json']['notification_warning']), 'SMTP failure must warn without rolling back the reserved Agenda event');
	$failedReference = $failedMail['json']['reference'];
	$states = profilesLiveRow('SELECT fk_actioncomm,mail_customer,mail_owner FROM '.MAIN_DB_PREFIX."cdav_booking_request WHERE reference='".$db->escape($failedReference)."'");
	$failedEvent = new ActionComm($db);
	profilesLiveCheck($failedEvent->fetch((int) $states->fk_actioncomm) > 0 && (int) $states->mail_customer === 3 && (int) $states->mail_owner === 3,
		'Both transport failures must persist without losing the native reservation');
	profilesLiveCheck(count(profilesLiveMails($failedReference)) === 0, 'A rejected local SMTP transaction must not be reported as delivered');
	unlink($rejectFile);
	$rejectCreated = false;
	$failedReplay = profilesLivePost($failedForm, array($at(4, 10)));
	profilesLiveCheck($failedReplay['status'] === 200 && $failedReplay['json']['replayed'] && !empty($failedReplay['json']['notification_warning'])
		&& count(profilesLiveMails($failedReference)) === 0,
		'An ambiguous or failed SMTP delivery must never send a duplicate on replay');
	$proof['checks'][] = 'P06 SMTP rejection preserves committed event and failed states; replay cannot duplicate delivery';

	$rollbackForm = profilesLiveForm($key3, $day->modify('+5 days')->format('Y-m-d'));
	$rollbackSubject = $prefix.' rollback';
	$marker = $temporaryDirectory.'/trigger-count';
	$triggerClass = 'InterfaceCdavProfilesQa'.bin2hex(random_bytes(5));
	$triggerPath = DOL_DOCUMENT_ROOT.'/core/triggers/interface_99_modCDav_'.substr($triggerClass, 9).'.class.php';
	profilesLiveCheck(!file_exists($triggerPath) && is_writable(dirname($triggerPath)), 'Use only the disposable native trigger tree');
	$trigger = '<?php require_once DOL_DOCUMENT_ROOT."/core/triggers/dolibarrtriggers.class.php";class '.$triggerClass
		.' extends DolibarrTriggers {public function runTrigger($action,$object,$user,$langs,$conf) {if($action==="ACTION_CREATE" && ($object->label??"")==='
		.var_export($rollbackSubject, true).') {$p='.var_export($marker, true).';$n=(int)@file_get_contents($p)+1;file_put_contents($p,(string)$n);if($n===2){$this->error="Isolated profiles rollback";return -1;}}return 0;}}';
	profilesLiveCheck(file_put_contents($triggerPath, $trigger) !== false, 'Cannot install native rollback probe');
	$counts = array();
	foreach (array('actioncomm', 'actioncomm_resources', 'cdav_booking_request', 'cdav_booking_request_event') as $table) {
		$counts[$table] = (int) profilesLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table)->nb;
	}
	profilesLiveCheck(count(profilesLiveMails($rollbackSubject)) === 0, 'A new rollback probe must not have a preexisting confirmation');
	$rollback = profilesLivePost($rollbackForm, array($at(5, 9, 30), $at(5, 13, 30)), array('subject' => $rollbackSubject));
	profilesLiveCheck($rollback['status'] === 503 && file_get_contents($marker) === '2', 'The second real native create must fail inside the group transaction');
	foreach ($counts as $table => $count) profilesLiveCheck((int) profilesLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table)->nb === $count,
		'Native group rollback must preserve events, resources and receipt counts');
	profilesLiveCheck(count(profilesLiveMails($rollbackSubject)) === 0, 'A rolled-back group must not emit any confirmation email');
	unlink($triggerPath);
	$triggerPath = '';
	$proof['checks'][] = 'P07 second native event rollback emits no email and preserves all event/resource/receipt counts';

	$windowDay = $day->modify('+45 days');
	$windowConfig = array_replace($config, array('valid_from' => $windowDay->format('Y-m-d'), 'valid_until' => $windowDay->format('Y-m-d')));
	$windowProfile = $service->saveProfile($owner->id, $windowConfig, true, 0, array('label' => 'Profil valable un jour'));
	$windowKey = $service->rotateToken($owner->id, $windowProfile['id']);
	$windowPublic = $service->publicProfile($windowKey);
	$beforeDay = $windowDay->modify('-1 day');
	$afterDay = $windowDay->modify('+1 day');
	profilesLiveCheck($service->slots($windowPublic, $beforeDay->getTimestamp(), $windowDay->getTimestamp()) === array()
		&& $service->slots($windowPublic, $afterDay->getTimestamp(), $afterDay->modify('+1 day')->getTimestamp()) === array(),
		'A one-day validity window must exclude both adjacent local days');
	$windowSlots = $service->slots($windowPublic, $windowDay->getTimestamp(), $afterDay->getTimestamp());
	profilesLiveCheck(in_array($windowDay->setTime(9, 0)->getTimestamp(), array_column($windowSlots, 'start'), true)
		&& in_array($windowDay->setTime(16, 30)->getTimestamp(), array_column($windowSlots, 'start'), true),
		'Both inclusive validity bounds must retain the entire allowed local day');
	$windowCalendar = $service->calendar($windowPublic, '');
	profilesLiveCheck($windowCalendar['calendar']['month'] === $windowDay->format('Y-m')
		&& $windowCalendar['date'] === $windowDay->format('Y-m-d')
		&& $windowCalendar['calendar']['previous'] === null && $windowCalendar['calendar']['next'] === null,
		'The first calendar month and navigation must follow the effective profile validity window');
	$openDates = array_keys(array_filter($windowCalendar['calendar']['days']));
	profilesLiveCheck($openDates === array($windowDay->format('Y-m-d')), 'Only the profile validity day may be offered in its month');
	$windowForm = profilesLiveForm($windowKey, $windowDay->format('Y-m-d'));
	$outsideSubject = $prefix.' outside validity';
	$outsideCounts = array();
	foreach (array('actioncomm', 'actioncomm_resources', 'cdav_booking_request', 'cdav_booking_request_event') as $table) {
		$outsideCounts[$table] = (int) profilesLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table)->nb;
	}
	$outside = profilesLivePost($windowForm, array($beforeDay->setTime(9, 30)->getTimestamp()), array('subject' => $outsideSubject));
	profilesLiveCheck($outside['status'] === 409, 'A forged slot outside the validity dates must be rejected by native reservation checks');
	foreach ($outsideCounts as $table => $count) profilesLiveCheck((int) profilesLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.$table)->nb === $count,
		'An out-of-window reservation must not mutate Agenda or technical receipt state');
	profilesLiveCheck(count(profilesLiveMails($outsideSubject)) === 0, 'An out-of-window reservation must not send a confirmation');
	$within = profilesLivePost($windowForm, array($windowDay->setTime(9, 30)->getTimestamp()));
	profilesLiveCheck($within['status'] === 201 && count(profilesLiveMails($within['json']['reference'])) === 2,
		'A slot inside the one-day validity window must reserve and notify normally');
	$staleWindowForm = profilesLiveForm($windowKey, $windowDay->format('Y-m-d'));
	$extendedWindow = array_replace($windowConfig, array('valid_until' => $afterDay->format('Y-m-d')));
	$service->saveProfile($owner->id, $extendedWindow, true, $windowProfile['id']);
	profilesLiveCheck(profilesLivePost($staleWindowForm, array($windowDay->setTime(10, 0)->getTimestamp()))['status'] === 409,
		'Changing the validity dates must invalidate a ticket even for a still-allowed unoccupied slot');
	$unlimited = $service->saveProfile($owner->id, array_replace($config, array('valid_from' => '', 'valid_until' => '')), true, 0,
		array('label' => 'Profil sans restriction de dates'));
	profilesLiveCheck(!array_key_exists('valid_from', $unlimited['config']) && !array_key_exists('valid_until', $unlimited['config']),
		'Empty validity fields must normalize to the historical unlimited configuration');
	$proof['checks'][] = 'P08 inclusive local validity dates, first possible month, forged-slot rejection, normal reservation and stale-ticket protection';
} catch (Throwable $exception) {
	$failure = $exception;
} finally {
	try {
		if ($rejectCreated && file_exists($rejectFile)) unlink($rejectFile);
		if ($triggerPath !== '' && is_file($triggerPath)) unlink($triggerPath);
		if ($db->transaction_opened) $db->rollback();
		$conf->entity = $entity;
		if ($admin) {
			// Remove business events natively before companies can cascade their audits.
			$events = profilesLiveQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id > '.$initialAgendaId);
			$eventIds = array();
			while ($row = $db->fetch_object($events)) $eventIds[] = (int) $row->id;
			foreach ($eventIds as $id) {
				$event = new ActionComm($db);
				profilesLiveCheck($event->fetch($id) > 0 && $event->delete($admin) > 0, 'Cannot remove native profile fixture event');
			}
			foreach (array_reverse($projectIds, true) as $id => $fixtureEntity) {
				$conf->entity = $fixtureEntity;
				$project = new Project($db);
				profilesLiveCheck($project->fetch($id) > 0 && $project->delete($admin, 1) > 0, 'Cannot remove native project fixture');
			}
			foreach (array_reverse($companyIds, true) as $id => $fixtureEntity) {
				$conf->entity = $fixtureEntity;
				$company = new Societe($db);
				profilesLiveCheck($company->fetch($id) > 0 && $company->delete($id, $admin, 0) > 0, 'Cannot remove native third-party fixture without cascade audits: '.$company->error);
			}
			$conf->entity = $entity;
			foreach (array_reverse($ownerIds) as $id) {
				$owner = new User($db);
				profilesLiveCheck($owner->fetch($id) > 0 && $owner->delete($admin) > 0, 'Cannot remove native collaborator fixture');
				profilesLiveCheck((int) profilesLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_booking_profile WHERE fk_user='.$id)->nb === 0,
					'All profiles of a removed fixture collaborator must cascade cleanly');
			}
			// User deletion can emit a native system audit; remove only new fixture history.
			$events = profilesLiveQuery('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id > '.$initialAgendaId);
			$eventIds = array();
			while ($row = $db->fetch_object($events)) $eventIds[] = (int) $row->id;
			foreach ($eventIds as $id) {
				$event = new ActionComm($db);
				profilesLiveCheck($event->fetch($id) > 0 && $event->delete($admin) > 0, 'Cannot remove recent native fixture deletion audit');
			}
			$orphans = profilesLiveRow('SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources r LEFT JOIN '
				.MAIN_DB_PREFIX.'actioncomm a ON a.id=r.fk_actioncomm WHERE a.id IS NULL')->nb;
			profilesLiveCheck((int) $orphans === (int) $initialOrphans, 'Profile fixtures must not leave new orphan Agenda resources');
		}
		foreach ($constantSnapshots as $name => $row) {
			if ($row) dolibarr_set_const($db, $name, $row->value, $row->type, $row->visible, $row->note, $entity);
			else dolibarr_del_const($db, $name, $entity);
		}
		profilesLiveQuery('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate');
		foreach ($rateSnapshot as $row) profilesLiveQuery('INSERT INTO '.MAIN_DB_PREFIX."cdav_booking_rate (bucket_hash,hits,expires_at) VALUES ('"
			.$db->escape($row->bucket_hash)."',".((int) $row->hits).','.((int) $row->expires_at).')');
		foreach (glob($temporaryDirectory.'/*') as $path) unlink($path);
		rmdir($temporaryDirectory);
	} catch (Throwable $cleanupFailure) {
		$proof['cleanup_error'] = $cleanupFailure->getMessage();
		fwrite(STDERR, 'Profile fixture cleanup failed: '.$cleanupFailure->getMessage()."\n");
		$failure = $failure ?: $cleanupFailure;
	}
}
$proof['result'] = $failure ? 'FAIL' : 'PASS';
$proof['error'] = $failure ? $failure->getMessage() : null;
$output = getenv('CDAV_BOOKING_PROFILES_PROOF');
if ($output) file_put_contents($output, json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
if ($failure) {
	fwrite(STDERR, 'Profile booking live checks failed: '.$failure->getMessage()."\n");
	exit(1);
}
foreach ($proof['checks'] as $check) fwrite(STDOUT, '[OK] '.$check."\n");
fwrite(STDOUT, 'All native multi-profile and SMTP checks passed on Dolibarr '.DOL_VERSION.' / PHP '.PHP_VERSION.".\n");
