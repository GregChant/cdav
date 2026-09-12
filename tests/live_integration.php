<?php

/**
 * Destructive, opt-in integration test against a real local Dolibarr database.
 *
 * The script creates records with a unique prefix, exercises the DAV HTTP
 * endpoint, checks the native Dolibarr objects/database, then removes all test
 * records through Dolibarr business APIs. It must never be aimed at production.
 */

if (getenv('CDAV_LIVE_TEST') !== '1') {
	fwrite(STDERR, "Refusing to run: set CDAV_LIVE_TEST=1 for an isolated test database.\n");
	exit(2);
}

$dolibarrRoot = rtrim((string) getenv('DOLIBARR_ROOT'), '/');
$davBaseUrl = rtrim((string) getenv('CDAV_LIVE_URL'), '/').'/';
if ($dolibarrRoot === '' || !is_file($dolibarrRoot.'/main.inc.php') || $davBaseUrl === '/') {
	fwrite(STDERR, "DOLIBARR_ROOT and CDAV_LIVE_URL are required.\n");
	exit(2);
}

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Europe/Zurich');

define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOCSRFCHECK', 1);

$parsedUrl = parse_url($davBaseUrl);
$_SERVER['SERVER_NAME'] = (string) ($parsedUrl['host'] ?? '127.0.0.1');
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'].(isset($parsedUrl['port']) ? ':'.$parsedUrl['port'] : '');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

chdir($dolibarrRoot);
require $dolibarrRoot.'/main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
require_once dirname(__DIR__).'/lib/cdav.lib.php';
require_once dirname(__DIR__).'/class/CDavSyncStore.php';

/** Fail the current test with a useful message. */
function liveAssert($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

/** Emit one successful test stage. */
function liveOk($message)
{
	fwrite(STDOUT, "[OK] ".$message."\n");
}

/** Return one database row or null. */
function liveRow($db, $sql)
{
	$result = $db->query($sql);
	if (!$result) {
		throw new RuntimeException('SQL check failed: '.$db->lasterror());
	}
	$row = $db->fetch_object($result);
	return $row ?: null;
}

/** Build a DAV path without allowing a test identifier to alter its structure. */
function liveDavPath(array $segments, $trailingSlash = false)
{
	$path = implode('/', array_map('rawurlencode', $segments));
	return $path.($trailingSlash ? '/' : '');
}

/** Build one authenticated cURL handle shared by sequential and concurrent tests. */
function liveHttpHandle($baseUrl, $login, $password, $method, $path, $body, array $headers, array &$responseHeaders)
{
	$handle = curl_init($baseUrl.ltrim($path, '/'));
	curl_setopt_array($handle, array(
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
		CURLOPT_USERPWD => $login.':'.$password,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders) {
			$length = strlen($line);
			if (strpos($line, ':') !== false) {
				list($name, $value) = explode(':', $line, 2);
				$responseHeaders[strtolower(trim($name))] = trim($value);
			}
			return $length;
		},
	));
	if ($method === 'HEAD') {
		curl_setopt($handle, CURLOPT_NOBODY, true);
	}
	if ($body !== null) {
		curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
	}
	return $handle;
}

/** Execute an authenticated HTTP DAV request. */
function liveHttp($baseUrl, $login, $password, $method, $path, $body = null, array $headers = array())
{
	$responseHeaders = array();
	$handle = liveHttpHandle($baseUrl, $login, $password, $method, $path, $body, $headers, $responseHeaders);
	$responseBody = curl_exec($handle);
	if ($responseBody === false) {
		$error = curl_error($handle);
		curl_close($handle);
		throw new RuntimeException('DAV HTTP transport failed: '.$error);
	}
	$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
	curl_close($handle);
	return array('status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody);
}

/** Execute several authenticated requests at the same time. */
function liveHttpConcurrent($baseUrl, $login, $password, array $requests)
{
	$multi = curl_multi_init();
	$handles = array();
	$responseHeaders = array();
	foreach ($requests as $index => $request) {
		$responseHeaders[$index] = array();
		$handles[$index] = liveHttpHandle(
			$baseUrl,
			$login,
			$password,
			(string) $request['method'],
			(string) $request['path'],
			$request['body'] ?? null,
			$request['headers'] ?? array(),
			$responseHeaders[$index]
		);
		curl_multi_add_handle($multi, $handles[$index]);
	}
	do {
		$status = curl_multi_exec($multi, $running);
		if ($running && $status === CURLM_OK) curl_multi_select($multi, 1.0);
	} while ($running && $status === CURLM_OK);
	if ($status !== CURLM_OK) {
		throw new RuntimeException('Concurrent DAV HTTP transport failed: '.curl_multi_strerror($status));
	}
	$responses = array();
	foreach ($handles as $index => $handle) {
		$error = curl_error($handle);
		if ($error !== '') throw new RuntimeException('Concurrent DAV request failed: '.$error);
		$responses[$index] = array(
			'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
			'headers' => $responseHeaders[$index],
			'body' => curl_multi_getcontent($handle),
		);
		curl_multi_remove_handle($multi, $handle);
		curl_close($handle);
	}
	curl_multi_close($multi);
	return $responses;
}

/** Require one of the expected HTTP status codes. */
function liveHttpStatus(array $response, array $expected, $context)
{
	if (!in_array($response['status'], $expected, true)) {
		$body = trim(strip_tags((string) $response['body']));
		throw new RuntimeException($context.' returned HTTP '.$response['status'].($body !== '' ? ': '.$body : ''));
	}
}

/** Require one successful conditional mutation and one concurrency loser. */
function liveAssertSingleConditionalWinner(array $responses, $context)
{
	$successes = array_filter($responses, static fn($response) => $response['status'] >= 200 && $response['status'] < 300);
	$preconditionFailures = array_filter($responses, static fn($response) => $response['status'] === 412);
	liveAssert(count($successes) === 1 && count($preconditionFailures) === 1,
		$context.' did not produce exactly one success and one HTTP 412; statuses='
		.implode(',', array_map(static fn($response) => (string) $response['status'], $responses)));
}

/** Extract a collection tag from a DAV multistatus response. */
function liveCollectionTag($xml)
{
	return preg_match('/<[^>]*getctag[^>]*>([^<]+)</i', (string) $xml, $matches)
		? html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_XML1)
		: '';
}

/** Extract the opaque RFC 6578 sync token returned in a multistatus body. */
function liveSyncToken($xml)
{
	return preg_match('/<[^>]*sync-token[^>]*>([^<]+)</i', (string) $xml, $matches)
		? html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_XML1)
		: '';
}

/** Unfold RFC 5545/6350 content lines before testing their semantic value. */
function liveUnfold($data)
{
	return preg_replace("/\r?\n[ \t]/", '', (string) $data);
}

/** Save a Dolibarr constant so the exact local configuration can be restored. */
function liveSnapshotConstant($db, $name, $entity)
{
	$result = $db->query('SELECT name, value, type, visible, note, entity FROM '.MAIN_DB_PREFIX.'const
		WHERE name = \''.$db->escape($name).'\' AND entity = '.((int) $entity).' LIMIT 1');
	if (!$result) {
		throw new RuntimeException('Unable to snapshot Dolibarr constant '.$name);
	}
	$row = $db->fetch_object($result);
	return $row ?: null;
}

/** Restore a Dolibarr constant after the test. */
function liveRestoreConstant($db, $name, $entity, $snapshot)
{
	if ($snapshot) {
		return dolibarr_set_const(
			$db,
			$name,
			(string) $snapshot->value,
			(string) $snapshot->type,
			(int) $snapshot->visible,
			(string) $snapshot->note,
			(int) $snapshot->entity
		);
	}
	return dolibarr_del_const($db, $name, $entity);
}

$entity = (int) $conf->entity;
$token = strtolower(bin2hex(random_bytes(6)));
$login = 'cdavlive'.$token;
$password = 'Cdav!'.bin2hex(random_bytes(10)).'9aA';
$userEmail = $login.'@example.test';
$limitedLogin = 'cdavlimited'.$token;
$limitedPassword = 'Cdav!'.bin2hex(random_bytes(10)).'8bB';
$prefix = 'CDAVLIVE-'.$token;
$created = array(
	'users' => array(),
	'contacts' => array(),
	'thirdparties' => array(),
	'events' => array(),
	'document_dirs' => array(),
	'photo_dirs' => array(),
);
$constantSnapshots = array();
$failure = null;
$admin = null;
$testUser = null;
$limitedUser = null;
$agendaOutputRoot = !empty($conf->agenda->multidir_output[$entity])
	? $conf->agenda->multidir_output[$entity]
	: ($conf->agenda->dir_output ?? '');
$societeOutputRoot = !empty($conf->societe->multidir_output[$entity])
	? $conf->societe->multidir_output[$entity]
	: ($conf->societe->dir_output ?? '');

try {
	liveAssert(str_contains(strtolower((string) $dolibarr_main_db_name), 'test'), 'The configured database name is not explicitly a test database');
	liveAssert(isModEnabled('cdav'), 'The CDav module is not enabled');
	liveAssert(isModEnabled('agenda'), 'The native Dolibarr agenda module is not enabled');
	foreach (array(
		'llx_actioncomm_cdav', 'llx_cdav_scheduling', 'llx_cdav_cardmap', 'llx_cdav_attachment',
		'llx_cdav_vcard', 'llx_cdav_reminder', 'llx_cdav_recurrence',
		'llx_cdav_sync_collection', 'llx_cdav_sync_state', 'llx_cdav_sync_change',
		'llx_cdav_schedule_object', 'llx_cdav_managed_attachment',
	) as $table) {
		liveAssert((bool) $db->DDLInfoTable($table), 'Missing module table '.$table);
	}
	foreach (array('entity', 'sourceuid_hash') as $column) {
		liveAssert(liveRow($db, 'SHOW COLUMNS FROM '.MAIN_DB_PREFIX."cdav_cardmap LIKE '".$column."'") !== null,
			'Missing entity-safe CardDAV mapping column '.$column);
	}
	foreach (array('uk_cdav_cardmap_uri', 'uk_cdav_cardmap_uid') as $index) {
		$indexRow = liveRow($db, 'SHOW INDEX FROM '.MAIN_DB_PREFIX."cdav_cardmap WHERE Key_name = '".$index."'");
		liveAssert($indexRow !== null && (int) $indexRow->Non_unique === 0, 'Missing unique CardDAV identity index '.$index);
	}
	liveOk('module enabled and complete P1/P2 schema available');

	$adminRow = liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user
		WHERE admin = 1 AND statut = 1 AND entity IN (0, '.$entity.') ORDER BY entity DESC, rowid LIMIT 1');
	liveAssert($adminRow !== null, 'No active Dolibarr administrator is available for test setup');
	$admin = new User($db);
	liveAssert($admin->fetch((int) $adminRow->rowid) > 0, 'Unable to load the setup administrator');
	$admin->loadRights();
	$GLOBALS['user'] = $admin;

	foreach (array(
		'CDAV_THIRD_SYNC' => '2', 'CDAV_CONTACT_TAG' => '', 'CDAV_SYNC_PAST' => '3650',
		'CDAV_SYNC_FUTURE' => '3650', 'CDAV_ALLOW_INSECURE_HTTP' => '0',
		'CDAV_MAX_REQUEST_MB' => '16', 'CDAV_TRUSTED_PROXY_IPS' => '',
		'CDAV_NATIVE_REMINDERS' => '1', 'AGENDA_REMINDER_BROWSER' => '1',
		'CDAV_DELEGATION' => '1', 'CDAV_SCHEDULING' => '1',
		'CDAV_MANAGED_ATTACHMENTS' => '1', 'CDAV_MANAGED_ATTACHMENT_MAX_MB' => '1',
		'CDAV_MANAGED_ATTACHMENT_MAX_COUNT' => '3', 'CDAV_MANAGED_ATTACHMENT_QUOTA_MB' => '2',
		'CDAV_SYNC_RETENTION_DAYS' => '180', 'CDAV_SCHEDULING_RETENTION_DAYS' => '30',
	) as $name => $value) {
		$constantSnapshots[$name] = liveSnapshotConstant($db, $name, $entity);
		liveAssert(dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $entity) > 0, 'Unable to configure '.$name);
	}

	$testUser = new User($db);
	$testUser->entity = $entity;
	$testUser->login = $login;
	$testUser->lastname = $prefix;
	$testUser->firstname = 'Integration';
	$testUser->email = $userEmail;
	$testUser->admin = 1;
	$testUser->employee = 1;
	$testUser->statut = 1;
	$testUser->status = 1;
	$testUser->lang = 'fr_FR';
	$userId = $testUser->create($admin);
	liveAssert($userId > 0, 'Unable to create the isolated DAV user: '.$testUser->error);
	$created['users'][] = $userId;
	$passwordResult = $testUser->setPassword($admin, $password, 0, 0, 1);
	liveAssert(!(is_int($passwordResult) && $passwordResult < 0), 'Unable to set the isolated DAV password: '.$testUser->error);
	// Admin status alone does not populate Dolibarr's rights tree. Grant the
	// same explicit business permissions that a real DAV account requires.
	liveAssert($testUser->addrights(0, 'societe', '', $entity) > 0, 'Unable to grant native third-party/contact rights');
	liveAssert($testUser->addrights(0, 'agenda', '', $entity) > 0, 'Unable to grant native agenda rights');
	liveAssert($testUser->fetch($userId) > 0 && $testUser->loadRights() >= 0, 'Unable to reload the isolated DAV user');
	liveAssert($testUser->isAdmin(), 'The isolated DAV user does not have the required test rights');
	liveAssert($testUser->hasRight('societe', 'contact', 'read') && $testUser->hasRight('agenda', 'myactions', 'write'), 'The isolated DAV user rights were not loaded');
	$GLOBALS['user'] = $testUser;
	liveOk('isolated authenticated Dolibarr user created');

	$limitedUser = new User($db);
	$limitedUser->entity = $entity;
	$limitedUser->login = $limitedLogin;
	$limitedUser->lastname = $prefix;
	$limitedUser->firstname = 'Restricted';
	$limitedUser->email = $limitedLogin.'@example.test';
	$limitedUser->admin = 0;
	$limitedUser->employee = 1;
	$limitedUser->statut = 1;
	$limitedUser->status = 1;
	$limitedUser->lang = 'fr_FR';
	$limitedUserId = $limitedUser->create($admin);
	liveAssert($limitedUserId > 0, 'Unable to create the restricted DAV user: '.$limitedUser->error);
	$created['users'][] = $limitedUserId;
	$passwordResult = $limitedUser->setPassword($admin, $limitedPassword, 0, 0, 1);
	liveAssert(!(is_int($passwordResult) && $passwordResult < 0), 'Unable to set the restricted DAV password: '.$limitedUser->error);
	foreach (array(
		array('agenda', 'myactions', 'read'),
		array('agenda', 'myactions', 'create'),
		array('agenda', 'myactions', 'delete'),
		array('societe', 'contact', 'lire'),
	) as $rightSpec) {
		$right = liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'rights_def
			WHERE module = \''.$db->escape($rightSpec[0]).'\'
			AND perms = \''.$db->escape($rightSpec[1]).'\'
			AND subperms = \''.$db->escape($rightSpec[2]).'\' LIMIT 1');
		liveAssert($right !== null && $limitedUser->addrights((int) $right->id, '', '', $entity) > 0, 'Unable to grant the restricted DAV right '.implode('/', $rightSpec));
	}
	liveAssert($limitedUser->fetch($limitedUserId) > 0 && $limitedUser->loadRights('', 1) >= 0, 'Unable to reload the restricted DAV user');
	liveAssert($limitedUser->hasRight('agenda', 'myactions', 'write') && !$limitedUser->hasRight('agenda', 'allactions', 'read'), 'Restricted agenda rights are not isolated');
	liveAssert($limitedUser->hasRight('societe', 'contact', 'read') && !$limitedUser->hasRight('societe', 'contact', 'write'), 'Restricted contact rights are not read-only');
	$GLOBALS['user'] = $testUser;
	liveOk('restricted user created with own-agenda and read-only contact rights');

	$unauthorized = liveHttp($davBaseUrl, $login, $password.'-wrong', 'OPTIONS', '');
	liveAssert($unauthorized['status'] === 401, 'Invalid DAV credentials were not rejected');
	$options = liveHttp($davBaseUrl, $login, $password, 'OPTIONS', '');
	liveHttpStatus($options, array(200, 204), 'Authenticated DAV OPTIONS');
	liveAssert(isset($options['headers']['dav']), 'Authenticated OPTIONS did not advertise DAV capabilities');
	$wellKnownUrl = preg_replace('#server\.php/$#', 'well-known.php', $davBaseUrl);
	$wellKnown = liveHttp($wellKnownUrl, $login, $password, 'GET', '');
	liveAssert($wellKnown['status'] === 301 && str_ends_with((string) ($wellKnown['headers']['location'] ?? ''), '/cdav/server.php/'), 'RFC 6764 discovery endpoint did not return the canonical DAV service URL');

	$propfindBody = '<?xml version="1.0" encoding="utf-8"?>'
		.'<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">'
		.'<d:prop><d:displayname/><d:current-user-principal/><cs:getctag/></d:prop></d:propfind>';
	$limitPropfindBody = '<?xml version="1.0" encoding="utf-8"?>'
		.'<d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav" xmlns:c="urn:ietf:params:xml:ns:caldav">'
		.'<d:prop><card:max-resource-size/><c:max-resource-size/></d:prop></d:propfind>';
	$rootDiscovery = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', '', $propfindBody, array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($rootDiscovery, array(207), 'DAV root discovery');
	liveAssert(stripos($rootDiscovery['body'], 'principals') !== false, 'DAV discovery did not expose principals');

	$principalPath = liveDavPath(array('principals', $login), true);
	$principalDiscovery = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $principalPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($principalDiscovery, array(207), 'Principal discovery');
	$rawDocumentRoot = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', 'documents/', $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveAssert($rawDocumentRoot['status'] === 404, 'The calendar endpoint exposed the raw Dolibarr document root');
	liveOk('Basic authentication, OPTIONS and principal discovery');

	$linkCompany = new Societe($db);
	$linkCompany->name = $prefix.' LINK SA';
	$linkCompany->nom = $linkCompany->name;
	$linkCompany->client = 0;
	$linkCompany->fournisseur = 0;
	$linkCompany->status = 1;
	$linkCompanyId = $linkCompany->create($testUser);
	liveAssert($linkCompanyId > 0, 'Unable to create native link company: '.$linkCompany->error);
	$created['thirdparties'][] = $linkCompanyId;

	$addressBookPath = liveDavPath(array('addressbooks', $login, 'default'), true);
	$addressBookBefore = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($addressBookBefore, array(207), 'Contact address-book discovery');
	$ctagBefore = liveCollectionTag($addressBookBefore['body']);
	liveAssert($ctagBefore !== '', 'Contact address-book ctag is missing');
	$addressBookLimits = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, $limitPropfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($addressBookLimits, array(207), 'Contact address-book limits discovery');
	liveAssert(str_contains($addressBookLimits['body'], '16777216'), 'CardDAV did not advertise its configured maximum resource size');

	$contactUri = $prefix.'-contact.vcf';
	$contactUid = $prefix.'-contact-uid';
	$contactEmail = 'alice-'.$token.'@example.test';
	$tinyPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
	$contactCard = "BEGIN:VCARD\r\n"
		."VERSION:4.0\r\n"
		."UID:".$contactUid."\r\n"
		."FN:Alice Tester ".$token."\r\n"
		."N:Tester ".$token.";Alice;;;\r\n"
		."ORG:".$linkCompany->name."\r\n"
		."TITLE:Responsable tests\r\n"
		."TEL;TYPE=work,voice:+41225550100\r\n"
		."TEL;TYPE=cell:+41795550100\r\n"
		."EMAIL;TYPE=work:".$contactEmail."\r\n"
		."item1.EMAIL;TYPE=home:alice.secondary-".$token."@example.test\r\n"
		."item1.X-ABLABEL:Private mailbox\r\n"
		."URL:https://example.test/alice-".$token."\r\n"
		."X-PRONOUNS:she/her\r\n"
		."X-SOCIALPROFILE;TYPE=linkedin:https://www.linkedin.com/in/alice-".$token."\r\n"
		."BDAY:1980-12-31\r\n"
		."PHOTO:data:image/png;base64,".$tinyPngBase64."\r\n"
		."NOTE:Created from external CardDAV\\nSecond line\r\n"
		."END:VCARD\r\n";
	$contactPath = $addressBookPath.rawurlencode($contactUri);
	$contactCreate = liveHttp($davBaseUrl, $login, $password, 'PUT', $contactPath, $contactCard, array('Content-Type: text/vcard; charset=utf-8'));
	liveHttpStatus($contactCreate, array(201, 204), 'CardDAV contact creation');
	$contactMap = liveRow($db, 'SELECT fk_object, sourceuid FROM '.MAIN_DB_PREFIX.'cdav_cardmap
		WHERE object_type = \'ct\' AND uuidext = \''.$db->escape($contactUri).'\'');
	liveAssert($contactMap !== null && (string) $contactMap->sourceuid === $contactUid, 'Contact URI/UID mapping was not preserved');
	$contactId = (int) $contactMap->fk_object;
	$created['contacts'][] = $contactId;
	$contactRow = liveRow($db, 'SELECT firstname, lastname, email, phone, phone_mobile, url, fk_soc, statut, photo
		FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId);
	liveAssert($contactRow !== null && (int) $contactRow->statut === 1, 'Native contact was not created as active');
	liveAssert((int) $contactRow->fk_soc === $linkCompanyId, 'vCard ORG was not linked to the exact Dolibarr third party');
	liveAssert((string) $contactRow->email === $contactEmail && (string) $contactRow->url !== '', 'Native contact fields were not populated');
	liveAssert((string) $contactRow->photo === 'cdavimage.jpg', 'RFC 6350 data-URI photo was not linked to the native contact');
	$contactPhotoDirectory = rtrim($societeOutputRoot, '/').'/contact/'.$contactId.'/photos';
	$contactPhotoFile = $contactPhotoDirectory.'/cdavimage.jpg';
	$created['photo_dirs'][] = $contactPhotoDirectory;
	$contactPhotoInfo = is_file($contactPhotoFile) ? @getimagesize($contactPhotoFile) : false;
	liveAssert(is_array($contactPhotoInfo) && ($contactPhotoInfo['mime'] ?? '') === 'image/jpeg',
		'The bounded vCard photo was not converted into a native JPEG document');

	$contactGet = liveHttp($davBaseUrl, $login, $password, 'GET', $contactPath);
	liveHttpStatus($contactGet, array(200), 'CardDAV contact read-back');
	liveAssert(str_contains($contactGet['body'], $contactUid) && str_contains($contactGet['body'], $contactEmail), 'Contact read-back lost its UID or email');
	liveAssert(str_contains(liveUnfold($contactGet['body']), 'alice.secondary-'.$token.'@example.test')
		&& str_contains(liveUnfold($contactGet['body']), 'X-PRONOUNS:she/her')
		&& str_contains(liveUnfold($contactGet['body']), 'X-ABLABEL:Private mailbox')
		&& str_contains(liveUnfold($contactGet['body']), 'PHOTO;'), 'Lossless repeated/labeled/X-* vCard properties or photo did not round-trip');
	liveAssert(isset($contactGet['headers']['etag']), 'Contact GET did not return an ETag');
	$contactCountBeforeReplay = (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId)->nb;
	$contactReplay = liveHttp($davBaseUrl, $login, $password, 'PUT', $contactPath, $contactCard, array('Content-Type: text/vcard; charset=utf-8'));
	liveHttpStatus($contactReplay, array(200, 204), 'Idempotent CardDAV retry');
	liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId)->nb === $contactCountBeforeReplay, 'A retried CardDAV PUT duplicated the contact');
	$duplicateContactUri = $prefix.'-duplicate-contact.vcf';
	$duplicateContact = liveHttp($davBaseUrl, $login, $password, 'PUT', $addressBookPath.rawurlencode($duplicateContactUri), $contactCard, array('Content-Type: text/vcard; charset=utf-8'));
	liveAssert($duplicateContact['status'] === 409, 'The same vCard UID was accepted under a second resource name');

	$staleUpdate = liveHttp($davBaseUrl, $login, $password, 'PUT', $contactPath, str_replace('Alice', 'Stale', $contactCard), array('Content-Type: text/vcard; charset=utf-8', 'If-Match: "not-the-current-etag"'));
	liveAssert($staleUpdate['status'] === 412, 'A stale CardDAV If-Match update was not rejected');

	$contactReplacement = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:".$contactUid."\r\n"
		."FN:Alice Edited ".$token."\r\nN:Tester ".$token.";Alice Edited;;;\r\nEND:VCARD\r\n";
	$contactUpdate = liveHttp($davBaseUrl, $login, $password, 'PUT', $contactPath, $contactReplacement, array('Content-Type: text/vcard; charset=utf-8'));
	liveHttpStatus($contactUpdate, array(200, 204), 'CardDAV full contact replacement');
	$contactRow = liveRow($db, 'SELECT firstname, email, phone, phone_mobile, url, fk_soc, photo FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId);
	liveAssert($contactRow !== null && (string) $contactRow->firstname === 'Alice Edited', 'Contact name update did not reach Dolibarr');
	liveAssert((string) $contactRow->email === '' && (string) $contactRow->phone === '' && (string) $contactRow->phone_mobile === '' && (string) $contactRow->url === '', 'Removed vCard properties were not cleared in Dolibarr');
	liveAssert(empty($contactRow->fk_soc), 'Removing ORG did not detach the native third-party link');
	liveAssert((string) $contactRow->photo === '' && !is_file($contactPhotoFile), 'Removing PHOTO did not clean the native contact photo');
	$contactAfterReplacement = liveHttp($davBaseUrl, $login, $password, 'GET', $contactPath);
	liveAssert(!str_contains($contactAfterReplacement['body'], 'alice.secondary-'.$token.'@example.test')
		&& !str_contains($contactAfterReplacement['body'], 'X-PRONOUNS'), 'A full vCard replacement retained properties removed by the client');

	$nativeContact = new Contact($db);
	liveAssert($nativeContact->fetch($contactId, $testUser) > 0, 'Unable to reload contact through native API');
	$nativeContact->firstname = 'Alice Dolibarr';
	$nativeContact->email = 'reverse-'.$token.'@example.test';
	$nativeContact->phone_mobile = '+41790000001';
	liveAssert($nativeContact->update($contactId, $testUser) > 0, 'Native Contact::update failed: '.$nativeContact->error);
	$contactReverse = liveHttp($davBaseUrl, $login, $password, 'GET', $contactPath);
	liveHttpStatus($contactReverse, array(200), 'Native-to-CardDAV contact read-back');
	liveAssert(str_contains($contactReverse['body'], 'Alice Dolibarr') && str_contains($contactReverse['body'], 'reverse-'.$token.'@example.test'), 'Native Dolibarr contact changes did not return through CardDAV');

	$addressBookAfter = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($addressBookAfter, array(207), 'Updated contact address-book discovery');
	liveAssert(liveCollectionTag($addressBookAfter['body']) !== $ctagBefore, 'Contact collection tag did not change');
	liveOk('contact create/update/read-back, ORG link, replacement clearing, ETag and ctag');

	$attendeeUri = $prefix.'-attendee.vcf';
	$attendeeUid = $prefix.'-attendee-uid';
	$attendeeEmail = 'attendee-'.$token.'@example.test';
	$attendeeCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:".$attendeeUid."\r\n"
		."FN:Event Attendee ".$token."\r\nN:Attendee ".$token.";Event;;;\r\n"
		."EMAIL:".$attendeeEmail."\r\nEND:VCARD\r\n";
	$attendeePath = $addressBookPath.rawurlencode($attendeeUri);
	$attendeeCreate = liveHttp($davBaseUrl, $login, $password, 'PUT', $attendeePath, $attendeeCard, array('Content-Type: text/vcard; charset=utf-8'));
	liveHttpStatus($attendeeCreate, array(201, 204), 'CardDAV attendee contact creation');
	$attendeeMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap
		WHERE object_type = \'ct\' AND uuidext = \''.$db->escape($attendeeUri).'\'');
	liveAssert($attendeeMap !== null, 'Attendee contact mapping is missing');
	$attendeeId = (int) $attendeeMap->fk_object;
	$created['contacts'][] = $attendeeId;

	$thirdBookPath = liveDavPath(array('addressbooks', $login, 'thirdparties'), true);
	$thirdDiscovery = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $thirdBookPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($thirdDiscovery, array(207), 'Third-party address-book discovery');
	$thirdCtagBefore = liveCollectionTag($thirdDiscovery['body']);
	liveAssert($thirdCtagBefore !== '', 'Third-party collection tag is missing');

	$thirdUri = $prefix.'-company.vcf';
	$thirdUid = $prefix.'-company-uid';
	$thirdName = $prefix.' EXTERNAL SA';
	$thirdCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:".$thirdUid."\r\n"
		."FN:".$thirdName."\r\nN:".$thirdName.";;;;\r\n"
		."TEL;TYPE=work:+41225550200\r\nEMAIL:office-".$token."@example.test\r\n"
		."URL:https://example.test/company-".$token."\r\nEND:VCARD\r\n";
	$thirdPath = $thirdBookPath.rawurlencode($thirdUri);
	$thirdCreate = liveHttp($davBaseUrl, $login, $password, 'PUT', $thirdPath, $thirdCard, array('Content-Type: text/vcard; charset=utf-8'));
	liveHttpStatus($thirdCreate, array(201, 204), 'CardDAV third-party creation');
	$thirdMap = liveRow($db, 'SELECT fk_object, sourceuid FROM '.MAIN_DB_PREFIX.'cdav_cardmap
		WHERE object_type = \'th\' AND uuidext = \''.$db->escape($thirdUri).'\'');
	liveAssert($thirdMap !== null && (string) $thirdMap->sourceuid === $thirdUid, 'Third-party URI/UID mapping was not preserved');
	$thirdId = (int) $thirdMap->fk_object;
	$created['thirdparties'][] = $thirdId;
	$thirdRow = liveRow($db, 'SELECT nom, email, phone, url, status FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$thirdId);
	liveAssert($thirdRow !== null && (int) $thirdRow->status === 1 && (string) $thirdRow->nom === $thirdName, 'Native third party was not created correctly');

	$thirdReplacement = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:".$thirdUid."\r\n"
		."FN:".$thirdName." EDITED\r\nN:".$thirdName." EDITED;;;;\r\nEMAIL:updated-".$token."@example.test\r\nEND:VCARD\r\n";
	$thirdUpdate = liveHttp($davBaseUrl, $login, $password, 'PUT', $thirdPath, $thirdReplacement, array('Content-Type: text/vcard; charset=utf-8'));
	liveHttpStatus($thirdUpdate, array(200, 204), 'CardDAV third-party replacement');
	$thirdRow = liveRow($db, 'SELECT nom, email, phone, url FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$thirdId);
	liveAssert($thirdRow !== null && (string) $thirdRow->email === 'updated-'.$token.'@example.test', 'Third-party update did not reach Dolibarr');
	liveAssert((string) $thirdRow->phone === '' && (string) $thirdRow->url === '', 'Removed third-party properties were not cleared');

	$nativeThird = new Societe($db);
	liveAssert($nativeThird->fetch($thirdId) > 0, 'Unable to reload third party through native API');
	$nativeThird->name = $prefix.' DOLIBARR SA';
	$nativeThird->nom = $nativeThird->name;
	liveAssert($nativeThird->update($thirdId, $testUser) >= 0, 'Native Societe::update failed: '.$nativeThird->error);
	$thirdReverse = liveHttp($davBaseUrl, $login, $password, 'GET', $thirdPath);
	liveHttpStatus($thirdReverse, array(200), 'Native-to-CardDAV third-party read-back');
	liveAssert(str_contains($thirdReverse['body'], $prefix.' DOLIBARR SA'), 'Native Dolibarr third-party changes did not return through CardDAV');
	$thirdDiscoveryAfter = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $thirdBookPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($thirdDiscoveryAfter, array(207), 'Updated third-party address-book discovery');
	liveAssert(liveCollectionTag($thirdDiscoveryAfter['body']) !== $thirdCtagBefore, 'Third-party collection tag did not change');
	liveOk('third-party create/update and native-to-CardDAV read-back');

	$calendarUri = $userId.'-cal-'.$login;
	$calendarPath = liveDavPath(array('calendars', $login, $calendarUri), true);
	$calendarDiscovery = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $calendarPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarDiscovery, array(207), 'Calendar discovery');
	$calendarCtagBefore = liveCollectionTag($calendarDiscovery['body']);
	liveAssert($calendarCtagBefore !== '', 'Calendar collection tag is missing');
	$calendarLimits = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $calendarPath, $limitPropfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarLimits, array(207), 'Calendar limits discovery');
	liveAssert(str_contains($calendarLimits['body'], '16777215'), 'CalDAV did not advertise its effective MEDIUMTEXT-safe maximum resource size');

	$start = strtotime('+5 days 10:00 UTC');
	$end = $start + 5400;
	$excluded = $start + 86400;
	$eventUri = $prefix.'-meeting.ics';
	$eventUid = $prefix.'-event-uid';
	$attachmentUrl = 'https://files.example.test/'.$token.'/agenda.pdf';
	$eventCard = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav live integration//EN\r\nCALSCALE:GREGORIAN\r\n"
		."BEGIN:VEVENT\r\nUID:".$eventUid."\r\nDTSTAMP:".gmdate('Ymd\\THis\\Z')."\r\n"
		."DTSTART:".gmdate('Ymd\\THis\\Z', $start)."\r\nDTEND:".gmdate('Ymd\\THis\\Z', $end)."\r\n"
		."SUMMARY:".$prefix." External appointment\r\nLOCATION:Meeting room A\r\n"
		."DESCRIPTION:Created by a CalDAV client\r\nRRULE:FREQ=DAILY;UNTIL=".gmdate('Ymd\\THis\\Z', $start + 2 * 86400)."\r\n"
		."EXDATE:".gmdate('Ymd\\THis\\Z', $excluded)."\r\n"
		."ORGANIZER;CN=Integration:mailto:".$userEmail."\r\n"
		."ATTENDEE;CN=Event Attendee;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:".$attendeeEmail."\r\n"
		."ATTACH;FILENAME=agenda.pdf;FMTTYPE=application/pdf:".$attachmentUrl."\r\n"
		."ATTACH;FILENAME=local.txt:file:///etc/passwd\r\n"
		."ATTACH;FILENAME=internal.txt:http://127.0.0.1/internal\r\n"
		."ATTACH;FILENAME=credential.txt:https://user:password@files.example.test/secret\r\n"
		."ATTACH;VALUE=BINARY;ENCODING=BASE64;FMTTYPE=text/plain:SGVsbG8=\r\n"
		."BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY\r\nDESCRIPTION:Appointment reminder\r\nEND:VALARM\r\n"
		."END:VEVENT\r\nEND:VCALENDAR\r\n";
	$eventPath = $calendarPath.rawurlencode($eventUri);
	$eventCreate = liveHttp($davBaseUrl, $login, $password, 'PUT', $eventPath, $eventCard, array('Content-Type: text/calendar; charset=utf-8'));
	liveHttpStatus($eventCreate, array(201, 204), 'CalDAV appointment creation');
	$eventMap = liveRow($db, 'SELECT fk_object, sourceuid FROM '.MAIN_DB_PREFIX.'actioncomm_cdav
		WHERE uuidext = \''.$db->escape($eventUri).'\'');
	liveAssert($eventMap !== null && (string) $eventMap->sourceuid === $eventUid, 'Appointment URI/UID mapping was not preserved');
	$eventId = (int) $eventMap->fk_object;
	$created['events'][] = $eventId;
	$eventRow = liveRow($db, 'SELECT label, location, datep, datep2, percent FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId);
	liveAssert($eventRow !== null && (string) $eventRow->label === $prefix.' External appointment', 'Native ActionComm appointment was not created');
	liveAssert((string) $eventRow->location === 'Meeting room A', 'Appointment location was not stored');
	$nativeRecurrence = liveRow($db, 'SELECT recurid, recurrule, recurdateend FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId);
	liveAssert($nativeRecurrence !== null && (string) $nativeRecurrence->recurrule === 'FREQ=DAILY'
		&& (string) $nativeRecurrence->recurid !== '', 'Compatible RRULE was not projected into ActionComm recurrence fields');
	$nativeReminder = liveRow($db, 'SELECT r.typeremind, r.offsetvalue, r.offsetunit FROM '.MAIN_DB_PREFIX.'actioncomm_reminder r'
		.' INNER JOIN '.MAIN_DB_PREFIX.'cdav_reminder cr ON cr.fk_reminder = r.rowid WHERE cr.fk_actioncomm = '.$eventId);
	liveAssert($nativeReminder !== null && (string) $nativeReminder->typeremind === 'browser'
		&& (int) $nativeReminder->offsetvalue === 15 && (string) $nativeReminder->offsetunit === 'i', 'DISPLAY VALARM was not projected through ActionCommReminder');
	$userResource = liveRow($db, 'SELECT answer_status FROM '.MAIN_DB_PREFIX.'actioncomm_resources
		WHERE fk_actioncomm = '.$eventId.' AND element_type = \'user\' AND fk_element = '.$userId);
	liveAssert($userResource !== null, 'Calendar owner was not linked as an ActionComm resource');
	$contactResource = liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'actioncomm_resources
		WHERE fk_actioncomm = '.$eventId.' AND element_type = \'socpeople\' AND fk_element = '.$attendeeId);
	liveAssert($contactResource !== null, 'Exact attendee email was not linked to the native Dolibarr contact');
	$scheduling = liveRow($db, 'SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$eventId);
	liveAssert($scheduling !== null && str_contains($scheduling->calendardata, 'RRULE:') && str_contains($scheduling->calendardata, 'BEGIN:VALARM'), 'Recurrence/alarm metadata was not preserved');
	$attachmentLink = liveRow($db, 'SELECT l.rowid, l.label FROM '.MAIN_DB_PREFIX.'links l
		INNER JOIN '.MAIN_DB_PREFIX.'cdav_attachment ca ON ca.fk_link = l.rowid
		WHERE ca.fk_actioncomm = '.$eventId.' AND l.objecttype = \'action\' AND l.objectid = '.$eventId.'
		AND l.url = \''.$db->escape($attachmentUrl).'\'');
	liveAssert($attachmentLink !== null && (string) $attachmentLink->label === 'agenda.pdf', 'CalDAV HTTPS ATTACH did not become a native Dolibarr appointment link');
	$attachmentMappingCount = liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_attachment WHERE fk_actioncomm = '.$eventId);
	liveAssert($attachmentMappingCount !== null && (int) $attachmentMappingCount->nb === 1, 'Unsafe CalDAV ATTACH schemes or credential-bearing URLs became native Dolibarr links');

	$eventGet = liveHttp($davBaseUrl, $login, $password, 'GET', $eventPath);
	liveHttpStatus($eventGet, array(200), 'CalDAV appointment read-back');
	$missingEventParts = array();
	$unfoldedEvent = liveUnfold($eventGet['body']);
	foreach (array('recurrence' => 'RRULE:', 'alarm' => 'BEGIN:VALARM', 'attendee' => $attendeeEmail) as $part => $needle) {
		if (!str_contains($unfoldedEvent, $needle)) {
			$missingEventParts[] = $part;
		}
	}
	liveAssert(!$missingEventParts, 'Appointment read-back lost: '.implode(', ', $missingEventParts));
	liveAssert(str_contains($unfoldedEvent, $attachmentUrl) && str_contains($unfoldedEvent, 'SGVsbG8='), 'External or inline appointment ATTACH did not round-trip');
	$eventReplay = liveHttp($davBaseUrl, $login, $password, 'PUT', $eventPath, $eventCard, array('Content-Type: text/calendar; charset=utf-8'));
	liveHttpStatus($eventReplay, array(200, 204), 'Idempotent CalDAV retry');
	liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId)->nb === 1, 'A retried CalDAV PUT duplicated the appointment');
	$duplicateEventUri = $prefix.'-duplicate-event.ics';
	$duplicateEvent = liveHttp($davBaseUrl, $login, $password, 'PUT', $calendarPath.rawurlencode($duplicateEventUri), $eventCard, array('Content-Type: text/calendar; charset=utf-8'));
	liveAssert($duplicateEvent['status'] === 409, 'The same iCalendar UID was accepted under a second resource name');

	$calendarBeforeNativeLink = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $calendarPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarBeforeNativeLink, array(207), 'Calendar tag before native attachment link');
	$ctagBeforeNativeLink = liveCollectionTag($calendarBeforeNativeLink['body']);
	$manualAttachmentUrl = 'https://files.example.test/'.$token.'/dolibarr-native.pdf';
	$manualLink = new Link($db);
	$manualLink->url = $manualAttachmentUrl;
	$manualLink->label = 'dolibarr-native.pdf';
	$manualLink->objecttype = 'action';
	$manualLink->objectid = $eventId;
	$manualLinkId = $manualLink->create($testUser);
	liveAssert($manualLinkId > 0, 'Unable to create a native Dolibarr appointment link: '.$manualLink->error);
	$calendarAfterNativeLink = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $calendarPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarAfterNativeLink, array(207), 'Calendar tag after native attachment link');
	liveAssert(liveCollectionTag($calendarAfterNativeLink['body']) !== $ctagBeforeNativeLink, 'A native Dolibarr appointment link did not change the calendar ctag');

	// Simulate a document uploaded on the native Dolibarr appointment tab and
	// render it with an HTTPS application URL. This validates the safe reverse
	// bridge without requiring TLS support from PHP's local development server.
	$physicalDirectory = $agendaOutputRoot.'/'.dol_sanitizeFileName((string) $eventId);
	liveAssert(dol_mkdir($physicalDirectory) >= 0, 'Unable to create the isolated native appointment document directory');
	$created['document_dirs'][] = $physicalDirectory;
	$physicalFilename = 'native-document-'.$token.'.pdf';
	$physicalFile = $physicalDirectory.'/'.$physicalFilename;
	liveAssert(file_put_contents($physicalFile, "%PDF-1.4\n% CDav isolated test\n") !== false, 'Unable to create the isolated native appointment document');
	$originalMainUrlRoot = $conf->file->dol_main_url_root;
	try {
		$conf->file->dol_main_url_root = 'https://dolibarr.example.test';
		$renderLib = new CdavLib($testUser, $db, $langs);
		$renderResult = $db->query($renderLib->getSqlCalEvents($userId, $eventId));
		$renderRow = $renderResult ? $db->fetch_object($renderResult) : null;
		liveAssert($renderRow !== null, 'Unable to load the native appointment for attachment rendering');
		$renderedWithDocument = liveUnfold($renderLib->toVCalendar($userId, $renderRow, true));
	} finally {
		$conf->file->dol_main_url_root = $originalMainUrlRoot;
	}
	liveAssert(str_contains($renderedWithDocument, 'https://dolibarr.example.test/document.php?modulepart=actions')
		&& str_contains($renderedWithDocument, $physicalFilename), 'A physical native Dolibarr appointment document was not exported through document.php as ATTACH');

	$nativeEvent = new ActionComm($db);
	liveAssert($nativeEvent->fetch($eventId) > 0, 'Unable to load appointment through ActionComm');
	$nativeEvent->label = $prefix.' Dolibarr appointment';
	$nativeEvent->location = 'Meeting room B';
	$nativeEvent->datep += 3600;
	$nativeEvent->datef += 3600;
	liveAssert($nativeEvent->update($testUser) > 0, 'Native ActionComm::update failed: '.$nativeEvent->error);
	$eventReverse = liveHttp($davBaseUrl, $login, $password, 'GET', $eventPath);
	liveHttpStatus($eventReverse, array(200), 'Native-to-CalDAV appointment read-back');
	liveAssert(str_contains($eventReverse['body'], $prefix.' Dolibarr appointment') && str_contains($eventReverse['body'], 'Meeting room B'), 'Native appointment changes did not return through CalDAV');
	liveAssert(str_contains($eventReverse['body'], 'RRULE:') && str_contains($eventReverse['body'], 'BEGIN:VALARM'), 'Native appointment update lost external scheduling metadata');
	liveAssert(str_contains(liveUnfold($eventReverse['body']), $manualAttachmentUrl), 'A native Dolibarr appointment link was not exported as CalDAV ATTACH');

	$updatedStart = $start + 7200;
	$updatedEnd = $updatedStart + 3600;
	$eventReplacement = str_replace(
		array($prefix.' External appointment', 'Meeting room A', gmdate('Ymd\\THis\\Z', $start), gmdate('Ymd\\THis\\Z', $end), 'FREQ=DAILY;UNTIL='.gmdate('Ymd\\THis\\Z', $start + 2 * 86400), 'TRIGGER:-PT15M'),
		array($prefix.' Phone appointment', 'Meeting room C', gmdate('Ymd\\THis\\Z', $updatedStart), gmdate('Ymd\\THis\\Z', $updatedEnd), 'FREQ=WEEKLY;UNTIL='.gmdate('Ymd\\THis\\Z', $updatedStart + 14 * 86400), 'TRIGGER:-PT5M'),
		$eventCard
	);
	$replacementAttachmentUrl = 'https://files.example.test/'.$token.'/agenda-v2.pdf';
	$eventReplacement = str_replace($attachmentUrl, $replacementAttachmentUrl, $eventReplacement);
	$eventUpdate = liveHttp($davBaseUrl, $login, $password, 'PUT', $eventPath, $eventReplacement, array('Content-Type: text/calendar; charset=utf-8'));
	liveHttpStatus($eventUpdate, array(200, 204), 'CalDAV appointment update');
	$eventRow = liveRow($db, 'SELECT label, location FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId);
	liveAssert($eventRow !== null && (string) $eventRow->label === $prefix.' Phone appointment' && (string) $eventRow->location === 'Meeting room C', 'CalDAV appointment update did not reach ActionComm');
	$eventUpdatedGet = liveHttp($davBaseUrl, $login, $password, 'GET', $eventPath);
	liveHttpStatus($eventUpdatedGet, array(200), 'Updated appointment read-back');
	liveAssert(str_contains($eventUpdatedGet['body'], 'FREQ=WEEKLY;') && str_contains($eventUpdatedGet['body'], ';UNTIL='), 'Updated recurrence did not round-trip: '.liveUnfold($eventUpdatedGet['body']));
	liveAssert(str_contains($eventUpdatedGet['body'], 'TRIGGER:-PT5M'), 'Updated alarm did not round-trip: '.liveUnfold($eventUpdatedGet['body']));
	$updatedNativeRecurrence = liveRow($db, 'SELECT recurrule FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId);
	liveAssert($updatedNativeRecurrence !== null && str_starts_with((string) $updatedNativeRecurrence->recurrule, 'FREQ=WEEKLY_BYDAY'), 'Updated compatible recurrence did not reach ActionComm');
	$updatedNativeReminder = liveRow($db, 'SELECT r.offsetvalue FROM '.MAIN_DB_PREFIX.'actioncomm_reminder r'
		.' INNER JOIN '.MAIN_DB_PREFIX.'cdav_reminder cr ON cr.fk_reminder = r.rowid WHERE cr.fk_actioncomm = '.$eventId);
	liveAssert($updatedNativeReminder !== null && (int) $updatedNativeReminder->offsetvalue === 5, 'Updated alarm did not replace the DAV-owned native reminder');
	$updatedAttachmentLink = liveRow($db, 'SELECT l.rowid FROM '.MAIN_DB_PREFIX.'links l
		INNER JOIN '.MAIN_DB_PREFIX.'cdav_attachment ca ON ca.fk_link = l.rowid
		WHERE ca.fk_actioncomm = '.$eventId.' AND l.url = \''.$db->escape($replacementAttachmentUrl).'\'');
	liveAssert($updatedAttachmentLink !== null, 'Updated CalDAV ATTACH did not replace the native Dolibarr link');
	liveAssert(liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'links WHERE rowid = '.((int) $attachmentLink->rowid)) === null, 'Removed CalDAV ATTACH left its module-owned native link behind');
	liveAssert(liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'links WHERE rowid = '.((int) $manualLinkId)) !== null, 'CalDAV replacement deleted a link created directly in Dolibarr');
	liveAssert(str_contains(liveUnfold($eventUpdatedGet['body']), $manualAttachmentUrl), 'CalDAV replacement stopped exporting a native Dolibarr appointment link');
	liveOk('VEVENT create/update, participant linkage, recurrence/alarm, attachments and native reverse sync');

	// RFC 8607 managed bytes must only be changed through calendar-object POST.
	$managedVersion = liveHttp($davBaseUrl, $login, $password, 'GET', $eventPath);
	liveHttpStatus($managedVersion, array(200), 'Calendar version before managed attachment POST');
	$managedEtag = (string) ($managedVersion['headers']['etag'] ?? '');
	liveAssert((bool) preg_match('/^"[a-f0-9]{32}"$/', $managedEtag), 'Calendar GET did not expose a strong ETag for RFC 8607');
	$managedCountBefore = liveRow($db, 'SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE fk_actioncomm = '.$eventId);
	$staleManagedAdd = liveHttp($davBaseUrl, $login, $password, 'POST', $eventPath.'?action=attachment-add', '%PDF-stale', array(
		'Content-Type: application/pdf', 'Content-Disposition: attachment; filename="stale.pdf"', 'If-Match: "stale-etag"',
	));
	liveAssert($staleManagedAdd['status'] === 412, 'A stale RFC 8607 If-Match was not rejected');
	$managedCountAfterStale = liveRow($db, 'SELECT COUNT(*) AS total FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE fk_actioncomm = '.$eventId);
	liveAssert((int) $managedCountBefore->total === (int) $managedCountAfterStale->total, 'A rejected managed POST left attachment metadata behind');
	$managedAdd = liveHttp($davBaseUrl, $login, $password, 'POST', $eventPath.'?action=attachment-add', '%PDF-managed-v1', array(
		'Content-Type: application/pdf', 'Content-Disposition: attachment; filename="managed-'.$token.'.pdf"', 'If-Match: '.$managedEtag,
	));
	liveHttpStatus($managedAdd, array(201), 'RFC 8607 attachment-add');
	$managedId = (string) ($managedAdd['headers']['cal-managed-id'] ?? '');
	liveAssert((bool) preg_match('/^[a-f0-9]{64}$/', $managedId), 'Managed attachment did not return an unguessable Cal-Managed-ID');
	$managedPath = liveDavPath(array('attachments', $managedId));
	$managedGet = liveHttp($davBaseUrl, $login, $password, 'GET', $managedPath);
	liveHttpStatus($managedGet, array(200), 'Managed attachment protected GET');
	liveAssert($managedGet['body'] === '%PDF-managed-v1' && (string) ($managedGet['headers']['content-type'] ?? '') === 'application/pdf', 'Managed attachment bytes/type were altered');
	liveAssert(str_contains((string) ($managedGet['headers']['content-disposition'] ?? ''), 'managed-'.$token.'.pdf'), 'Managed attachment download name is missing or unsafe');
	$managedEvent = liveHttp($davBaseUrl, $login, $password, 'GET', $eventPath);
	liveAssert(str_contains(liveUnfold($managedEvent['body']), 'MANAGED-ID='.$managedId)
		&& str_contains(liveUnfold($managedEvent['body']), 'FILENAME=managed-'.$token.'.pdf'), 'Managed ATTACH metadata was not written into the calendar resource');
	$managedRow = liveRow($db, 'SELECT filename, file_size FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE managed_id = \''.$db->escape($managedId).'\'');
	liveAssert($managedRow !== null && (int) $managedRow->file_size === strlen('%PDF-managed-v1'), 'Managed metadata was not persisted');
	liveAssert(is_file($agendaOutputRoot.'/'.dol_sanitizeFileName((string) $eventId).'/'.(string) $managedRow->filename), 'Managed bytes are not stored in the native Agenda document directory');
	$directManagedPut = liveHttp($davBaseUrl, $login, $password, 'PUT', $managedPath, 'overwrite', array('Content-Type: application/pdf'));
	liveAssert(in_array($directManagedPut['status'], array(403, 405), true), 'Direct PUT overwrote protected managed bytes');
	$directManagedDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $managedPath);
	liveAssert(in_array($directManagedDelete['status'], array(403, 405), true), 'Direct DELETE removed protected managed bytes');
	$foreignManagedGet = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'GET', $managedPath);
	liveAssert(in_array($foreignManagedGet['status'], array(403, 404), true), 'A user without event visibility read managed attachment bytes');
	$blockedManaged = liveHttp($davBaseUrl, $login, $password, 'POST', $eventPath.'?action=attachment-add', '<script>alert(1)</script>', array(
		'Content-Type: text/html', 'Content-Disposition: attachment; filename="active.html"',
	));
	liveAssert($blockedManaged['status'] === 403, 'Active HTML content was accepted as a managed attachment');
	$oversizedManagedBody = str_repeat('M', 1024 * 1024 + 1);
	$oversizedManaged = liveHttp($davBaseUrl, $login, $password, 'POST', $eventPath.'?action=attachment-add', $oversizedManagedBody, array(
		'Content-Type: application/pdf', 'Content-Disposition: attachment; filename="too-large.pdf"',
	));
	unset($oversizedManagedBody);
	liveAssert(in_array($oversizedManaged['status'], array(400, 413, 507), true), 'Managed attachment size quota was not enforced');
	$managedUpdate = liveHttp($davBaseUrl, $login, $password, 'POST', $eventPath.'?action=attachment-update&managed-id='.rawurlencode($managedId), '%PDF-managed-v2', array(
		'Content-Type: application/pdf', 'Content-Disposition: attachment; filename="managed-v2-'.$token.'.pdf"',
	));
	liveHttpStatus($managedUpdate, array(204), 'RFC 8607 attachment-update');
	$managedId2 = (string) ($managedUpdate['headers']['cal-managed-id'] ?? '');
	liveAssert((bool) preg_match('/^[a-f0-9]{64}$/', $managedId2) && !hash_equals($managedId, $managedId2), 'Attachment update reused its old MANAGED-ID');
	liveAssert(liveHttp($davBaseUrl, $login, $password, 'GET', $managedPath)['status'] === 404, 'Replaced managed bytes remain reachable');
	$managedPath2 = liveDavPath(array('attachments', $managedId2));
	$managedGet2 = liveHttp($davBaseUrl, $login, $password, 'GET', $managedPath2);
	liveAssert($managedGet2['status'] === 200 && $managedGet2['body'] === '%PDF-managed-v2', 'Replacement managed bytes are unavailable');
	$managedRemove = liveHttp($davBaseUrl, $login, $password, 'POST', $eventPath.'?action=attachment-remove&managed-id='.rawurlencode($managedId2));
	liveHttpStatus($managedRemove, array(204), 'RFC 8607 attachment-remove');
	liveAssert(liveHttp($davBaseUrl, $login, $password, 'GET', $managedPath2)['status'] === 404
		&& liveRow($db, 'SELECT managed_id FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE managed_id = \''.$db->escape($managedId2).'\'') === null,
		'Managed attachment removal left reachable bytes or metadata');
	liveOk('RFC 8607 add/read/update/remove, ETag concurrency, immutable byte URLs, ACL and active-content rejection');

	// A local iTIP attendee gets a persistent inbox object; no iMIP transport is installed.
	$scheduledUri = $prefix.'-scheduled.ics';
	$scheduledUid = $prefix.'-scheduled-uid';
	$scheduledBody = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav live integration//EN\r\n"
		."BEGIN:VEVENT\r\nUID:".$scheduledUid."\r\nDTSTAMP:".gmdate('Ymd\\THis\\Z')."\r\n"
		."DTSTART:".gmdate('Ymd\\THis\\Z', $start + 3 * 86400)."\r\nDTEND:".gmdate('Ymd\\THis\\Z', $end + 3 * 86400)."\r\n"
		."SUMMARY:".$prefix." Scheduled local meeting\r\nORGANIZER:mailto:".$userEmail."\r\n"
		."ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:".$limitedUser->email."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	$scheduledPath = $calendarPath.rawurlencode($scheduledUri);
	$scheduledCreate = liveHttp($davBaseUrl, $login, $password, 'PUT', $scheduledPath, $scheduledBody, array('Content-Type: text/calendar'));
	liveHttpStatus($scheduledCreate, array(201, 204), 'Scheduled local VEVENT creation');
	$scheduledMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE uuidext = \''.$db->escape($scheduledUri).'\'');
	liveAssert($scheduledMap !== null, 'Scheduled event mapping is missing');
	$scheduledId = (int) $scheduledMap->fk_object;
	$created['events'][] = $scheduledId;
	$inboxObject = liveRow($db, 'SELECT uri, calendardata FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE entity = '.$entity
		.' AND fk_principal = '.$limitedUserId." AND direction = 'I' ORDER BY rowid DESC LIMIT 1");
	$outboxAudit = liveRow($db, 'SELECT uri, calendardata FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE entity = '.$entity
		.' AND fk_principal = '.$userId." AND direction = 'O' ORDER BY rowid DESC LIMIT 1");
	liveAssert($inboxObject !== null && str_contains((string) $inboxObject->calendardata, 'METHOD:REQUEST')
		&& $outboxAudit !== null, 'Local iTIP delivery or bounded outgoing journal is missing');
	$inboxPath = liveDavPath(array('calendars', $limitedLogin, 'inbox', (string) $inboxObject->uri));
	$inboxGet = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'GET', $inboxPath);
	liveHttpStatus($inboxGet, array(200), 'Persistent scheduling inbox GET');
	liveAssert(str_contains($inboxGet['body'], $scheduledUid), 'Scheduling inbox object lost its UID');
	$foreignInbox = liveHttp($davBaseUrl, $login, $password, 'GET', $inboxPath);
	liveAssert(in_array($foreignInbox['status'], array(403, 404), true), 'Another principal read a scheduling inbox object');
	$inboxDelete = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'DELETE', $inboxPath);
	liveHttpStatus($inboxDelete, array(200, 204), 'Scheduling inbox DELETE');
	liveAssert(liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE entity = '.$entity
		.' AND fk_principal = '.$limitedUserId." AND direction = 'I' AND uri = '".$db->escape((string) $inboxObject->uri)."'") === null, 'Deleted inbox object remained in the journal');
	liveOk('RFC 6638 local iTIP inbox/outbox persistence, ownership ACL and outgoing audit');

	$todoUri = $prefix.'-todo.ics';
	$todoUid = $prefix.'-todo-uid';
	$todoStart = strtotime('+6 days 08:00 UTC');
	$todoDue = $todoStart + 7200;
	$todoCard = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav live integration//EN\r\n"
		."BEGIN:VTODO\r\nUID:".$todoUid."\r\nDTSTAMP:".gmdate('Ymd\\THis\\Z')."\r\n"
		."DTSTART:".gmdate('Ymd\\THis\\Z', $todoStart)."\r\nDUE:".gmdate('Ymd\\THis\\Z', $todoDue)."\r\n"
		."SUMMARY:".$prefix." External task\r\nDESCRIPTION:Created from a task client\r\n"
		."STATUS:IN-PROCESS\r\nPERCENT-COMPLETE:40\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	$todoPath = $calendarPath.rawurlencode($todoUri);
	$todoCreate = liveHttp($davBaseUrl, $login, $password, 'PUT', $todoPath, $todoCard, array('Content-Type: text/calendar; charset=utf-8'));
	liveHttpStatus($todoCreate, array(201, 204), 'CalDAV VTODO creation');
	$todoMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE uuidext = \''.$db->escape($todoUri).'\'');
	liveAssert($todoMap !== null, 'VTODO mapping is missing');
	$todoId = (int) $todoMap->fk_object;
	$created['events'][] = $todoId;
	$todoRow = liveRow($db, 'SELECT a.label, a.percent, ca.code FROM '.MAIN_DB_PREFIX.'actioncomm a
		LEFT JOIN '.MAIN_DB_PREFIX.'c_actioncomm ca ON ca.id = a.fk_action WHERE a.id = '.$todoId);
	liveAssert($todoRow !== null && (int) $todoRow->percent === 40
		&& in_array((string) $todoRow->code, array('AC_TACHE', 'AC_OTH'), true), 'VTODO did not become a native Dolibarr action');
	$todoReplacement = str_replace(array('External task', 'IN-PROCESS', 'PERCENT-COMPLETE:40'), array('Completed task', 'COMPLETED', 'PERCENT-COMPLETE:100'), $todoCard);
	$todoUpdate = liveHttp($davBaseUrl, $login, $password, 'PUT', $todoPath, $todoReplacement, array('Content-Type: text/calendar; charset=utf-8'));
	liveHttpStatus($todoUpdate, array(200, 204), 'CalDAV VTODO update');
	$todoRow = liveRow($db, 'SELECT label, percent FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$todoId);
	liveAssert($todoRow !== null && (int) $todoRow->percent === 100 && str_contains((string) $todoRow->label, 'Completed task'), 'VTODO completion did not reach Dolibarr');
	$todoGet = liveHttp($davBaseUrl, $login, $password, 'GET', $todoPath);
	liveHttpStatus($todoGet, array(200), 'VTODO read-back');
	liveAssert(str_contains($todoGet['body'], 'BEGIN:VTODO') && str_contains($todoGet['body'], 'PERCENT-COMPLETE:100'), 'VTODO did not round-trip');

	$calendarDiscoveryAfter = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $calendarPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarDiscoveryAfter, array(207), 'Updated calendar discovery');
	liveAssert(liveCollectionTag($calendarDiscoveryAfter['body']) !== $calendarCtagBefore, 'Calendar collection tag did not change');
	liveOk('VTODO create/update/read-back and calendar ctag');

	// RFC 4918/4791/6352 method and conditional-request coverage.
	$davFeatures = strtolower((string) ($options['headers']['dav'] ?? ''));
	liveAssert(str_contains($davFeatures, 'addressbook') && str_contains($davFeatures, 'calendar-access'), 'OPTIONS did not advertise CardDAV and CalDAV');
	liveAssert(str_contains($davFeatures, 'calendar-proxy'), 'OPTIONS did not advertise explicitly enabled native-right delegation');
	liveAssert(str_contains($davFeatures, 'calendar-auto-schedule') && str_contains($davFeatures, 'calendar-managed-attachments'), 'OPTIONS did not advertise enabled P2 scheduling/managed-attachment features');
	liveAssert(!isset($options['headers']['x-sabre-version']), 'The DAV server disclosed its Sabre/DAV version');
	liveAssert(strtolower((string) ($options['headers']['cache-control'] ?? '')) === 'no-store', 'DAV responses are not protected against caching');
	$proxyPropfind = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:group-member-set/></d:prop></d:propfind>';
	$limitedProxyWritePath = liveDavPath(array('principals', $limitedLogin, 'calendar-proxy-write'), true);
	$proxyWrite = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $limitedProxyWritePath, $proxyPropfind, array('Depth: 0', 'Content-Type: application/xml'));
	liveHttpStatus($proxyWrite, array(207), 'Dolibarr-derived write delegation discovery');
	liveAssert(str_contains($proxyWrite['body'], rawurlencode($login)), 'A user with native all-agenda write permission is missing from proxy-write membership');
	$ownerProxyReadPath = liveDavPath(array('principals', $login, 'calendar-proxy-read'), true);
	$proxyRead = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'PROPFIND', $ownerProxyReadPath, $proxyPropfind, array('Depth: 0', 'Content-Type: application/xml'));
	liveHttpStatus($proxyRead, array(207), 'Read delegation discovery');
	liveAssert(!str_contains($proxyRead['body'], rawurlencode($limitedLogin)), 'A user without all-agenda read permission gained proxy membership');

	$entityOverride = liveHttp($davBaseUrl, $login, $password, 'OPTIONS', '?entity=999999');
	liveHttpStatus($entityOverride, array(200, 204), 'DAV entity-query isolation');
	$contactFresh = liveHttp($davBaseUrl, $login, $password, 'GET', $contactPath);
	liveHttpStatus($contactFresh, array(200), 'Fresh contact for HTTP validators');
	$contactEtag = (string) ($contactFresh['headers']['etag'] ?? '');
	liveAssert($contactEtag !== '', 'Fresh CardDAV ETag is missing');
	$contactHead = liveHttp($davBaseUrl, $login, $password, 'HEAD', $contactPath);
	liveHttpStatus($contactHead, array(200), 'CardDAV HEAD');
	liveAssert($contactHead['body'] === '' && (string) ($contactHead['headers']['etag'] ?? '') === $contactEtag, 'CardDAV HEAD did not preserve empty-body/ETag semantics');
	$notModified = liveHttp($davBaseUrl, $login, $password, 'GET', $contactPath, null, array('If-None-Match: '.$contactEtag));
	liveAssert($notModified['status'] === 304 && $notModified['body'] === '', 'CardDAV If-None-Match did not return 304');
	$concurrentCards = liveHttpConcurrent($davBaseUrl, $login, $password, array(
		array('method' => 'PUT', 'path' => $contactPath, 'body' => str_replace('Alice Dolibarr', 'Alice Concurrent A', $contactFresh['body']),
			'headers' => array('Content-Type: text/vcard; charset=utf-8', 'If-Match: '.$contactEtag)),
		array('method' => 'PUT', 'path' => $contactPath, 'body' => str_replace('Alice Dolibarr', 'Alice Concurrent B', $contactFresh['body']),
			'headers' => array('Content-Type: text/vcard; charset=utf-8', 'If-Match: '.$contactEtag)),
	));
	liveAssertSingleConditionalWinner($concurrentCards, 'Concurrent conditional CardDAV PUTs');
	$changedSince = liveHttp($davBaseUrl, $login, $password, 'GET', $contactPath, null, array('If-None-Match: '.$contactEtag));
	liveAssert($changedSince['status'] === 200, 'The losing CardDAV ETag remained current after a concurrent write');
	$contactFresh = $changedSince;
	$contactEtag = (string) ($contactFresh['headers']['etag'] ?? '');
	$mustNotOverwrite = liveHttp($davBaseUrl, $login, $password, 'PUT', $contactPath, $contactFresh['body'], array('Content-Type: text/vcard; charset=utf-8', 'If-None-Match: *'));
	liveAssert($mustNotOverwrite['status'] === 412, 'CardDAV If-None-Match * overwrote an existing object');
	$staleDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $contactPath, null, array('If-Match: "stale-etag"'));
	liveAssert($staleDelete['status'] === 412, 'A stale conditional CardDAV DELETE was accepted');
	liveAssert((int) liveRow($db, 'SELECT statut FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId)->statut === 1, 'A failed conditional DELETE changed the native contact');

	$eventFresh = liveHttp($davBaseUrl, $login, $password, 'GET', $eventPath);
	liveHttpStatus($eventFresh, array(200), 'Fresh appointment for HTTP validators');
	$eventEtag = (string) ($eventFresh['headers']['etag'] ?? '');
	liveAssert($eventEtag !== '', 'Fresh CalDAV ETag is missing');
	$eventHead = liveHttp($davBaseUrl, $login, $password, 'HEAD', $eventPath);
	liveHttpStatus($eventHead, array(200), 'CalDAV HEAD');
	liveAssert($eventHead['body'] === '' && isset($eventHead['headers']['etag']), 'CalDAV HEAD did not return an ETag with an empty body');
	$concurrentEvents = liveHttpConcurrent($davBaseUrl, $login, $password, array(
		array('method' => 'PUT', 'path' => $eventPath, 'body' => str_replace('Meeting room C', 'Concurrent room A', $eventFresh['body']),
			'headers' => array('Content-Type: text/calendar; charset=utf-8', 'If-Match: '.$eventEtag)),
		array('method' => 'PUT', 'path' => $eventPath, 'body' => str_replace('Meeting room C', 'Concurrent room B', $eventFresh['body']),
			'headers' => array('Content-Type: text/calendar; charset=utf-8', 'If-Match: '.$eventEtag)),
	));
	liveAssertSingleConditionalWinner($concurrentEvents, 'Concurrent conditional CalDAV PUTs');
	liveAssert(liveRow($db, 'SELECT l.rowid FROM '.MAIN_DB_PREFIX.'links l WHERE l.objecttype = \'action\' AND l.objectid = '.$eventId
		." AND l.url LIKE '%modulepart=actions%'") === null, 'A CalDAV GET/PUT feedback loop duplicated a native document as a Link');
	$eventFresh = liveHttp($davBaseUrl, $login, $password, 'GET', $eventPath);
	liveHttpStatus($eventFresh, array(200), 'Appointment after concurrent conditional PUT');
	liveOk('DAV capability, HEAD, atomic ETag conditions, concurrent PUT/DELETE and entity isolation');

	$davEndpointPath = rtrim((string) parse_url($davBaseUrl, PHP_URL_PATH), '/').'/';
	$xmlEscape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
	$contactHref = $davEndpointPath.$contactPath;
	$eventHref = $davEndpointPath.$eventPath;
	$todoHref = $davEndpointPath.$todoPath;
	$missingCardHref = $davEndpointPath.$addressBookPath.rawurlencode($prefix.'-missing.vcf');
	$missingEventHref = $davEndpointPath.$calendarPath.rawurlencode($prefix.'-missing.ics');

	$addressQueryBody = '<?xml version="1.0" encoding="utf-8"?>'
		.'<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
		.'<d:prop><d:getetag/><card:address-data/></d:prop><card:filter>'
		.'<card:prop-filter name="EMAIL"><card:text-match collation="i;unicode-casemap" match-type="equals">'
		.$xmlEscape('reverse-'.$token.'@example.test').'</card:text-match></card:prop-filter>'
		.'</card:filter></card:addressbook-query>';
	$addressQuery = liveHttp($davBaseUrl, $login, $password, 'REPORT', $addressBookPath, $addressQueryBody, array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($addressQuery, array(207), 'CardDAV addressbook-query REPORT');
	liveAssert(str_contains($addressQuery['body'], rawurlencode($contactUri)) && str_contains($addressQuery['body'], 'reverse-'.$token.'@example.test'), 'CardDAV addressbook-query did not return the matching vCard');

	$addressMultigetBody = '<?xml version="1.0" encoding="utf-8"?>'
		.'<card:addressbook-multiget xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
		.'<d:prop><d:getetag/><card:address-data/></d:prop>'
		.'<d:href>'.$xmlEscape($contactHref).'</d:href><d:href>'.$xmlEscape($missingCardHref).'</d:href>'
		.'</card:addressbook-multiget>';
	$addressMultiget = liveHttp($davBaseUrl, $login, $password, 'REPORT', $addressBookPath, $addressMultigetBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($addressMultiget, array(207), 'CardDAV addressbook-multiget REPORT');
	liveAssert(str_contains($addressMultiget['body'], 'HTTP/1.1 200') && str_contains($addressMultiget['body'], 'HTTP/1.1 404'), 'CardDAV multiget did not distinguish existing and missing resources');

	$calendarQueryBody = '<?xml version="1.0" encoding="utf-8"?>'
		.'<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
		.'<d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR">'
		.'<c:comp-filter name="VEVENT"><c:time-range start="'.gmdate('Ymd\THis\Z', $updatedStart - 3600).'" end="'.gmdate('Ymd\THis\Z', $updatedEnd + 3600).'"/>'
		.'</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
	$calendarQuery = liveHttp($davBaseUrl, $login, $password, 'REPORT', $calendarPath, $calendarQueryBody, array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarQuery, array(207), 'CalDAV calendar-query REPORT');
	liveAssert(str_contains($calendarQuery['body'], rawurlencode($eventUri)) && str_contains($calendarQuery['body'], $prefix.' Phone appointment'), 'CalDAV time-range query did not return the appointment');

	$todoQueryBody = '<?xml version="1.0" encoding="utf-8"?>'
		.'<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
		.'<d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR">'
		.'<c:comp-filter name="VTODO"/></c:comp-filter></c:filter></c:calendar-query>';
	$todoQuery = liveHttp($davBaseUrl, $login, $password, 'REPORT', $calendarPath, $todoQueryBody, array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($todoQuery, array(207), 'CalDAV VTODO calendar-query REPORT');
	liveAssert(str_contains($todoQuery['body'], rawurlencode($todoUri)) && str_contains($todoQuery['body'], 'BEGIN:VTODO'), 'CalDAV component query did not return the VTODO');

	$calendarMultigetBody = '<?xml version="1.0" encoding="utf-8"?>'
		.'<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
		.'<d:prop><d:getetag/><c:calendar-data/></d:prop>'
		.'<d:href>'.$xmlEscape($eventHref).'</d:href><d:href>'.$xmlEscape($todoHref).'</d:href>'
		.'<d:href>'.$xmlEscape($missingEventHref).'</d:href></c:calendar-multiget>';
	$calendarMultiget = liveHttp($davBaseUrl, $login, $password, 'REPORT', $calendarPath, $calendarMultigetBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarMultiget, array(207), 'CalDAV calendar-multiget REPORT');
	liveAssert(substr_count($calendarMultiget['body'], 'HTTP/1.1 200') >= 2 && str_contains($calendarMultiget['body'], 'HTTP/1.1 404'), 'CalDAV multiget did not return both component types and the missing status');

	$syncBody = '<?xml version="1.0" encoding="utf-8"?><d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>';
	$initialSync = liveHttp($davBaseUrl, $login, $password, 'REPORT', $addressBookPath, $syncBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($initialSync, array(207), 'Initial RFC 6578 CardDAV sync');
	$initialSyncToken = liveSyncToken($initialSync['body']);
	liveAssert($initialSyncToken !== '' && str_contains($initialSync['body'], rawurlencode($contactUri)), 'Initial sync did not return its token and visible resources');
	usleep(1100000);
	$syncContact = new Contact($db);
	liveAssert($syncContact->fetch($contactId, $testUser) > 0, 'Unable to load contact for native sync-token update');
	$syncContact->note_public = 'Native update after sync token '.$token;
	liveAssert($syncContact->update($contactId, $testUser) > 0, 'Native contact update for RFC 6578 failed');
	$incrementalSyncBody = str_replace('<d:sync-token/>', '<d:sync-token>'.$xmlEscape($initialSyncToken).'</d:sync-token>', $syncBody);
	$incrementalSync = liveHttp($davBaseUrl, $login, $password, 'REPORT', $addressBookPath, $incrementalSyncBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($incrementalSync, array(207), 'Incremental RFC 6578 CardDAV sync');
	$cardTombstoneToken = liveSyncToken($incrementalSync['body']);
	liveAssert($cardTombstoneToken !== '' && $cardTombstoneToken !== $initialSyncToken
		&& str_contains($incrementalSync['body'], rawurlencode($contactUri)), 'Native Dolibarr contact update was not journaled as an incremental DAV change');
	$invalidSyncBody = str_replace('<d:sync-token/>', '<d:sync-token>forged-token</d:sync-token>', $syncBody);
	$invalidSync = liveHttp($davBaseUrl, $login, $password, 'REPORT', $addressBookPath, $invalidSyncBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveAssert($invalidSync['status'] === 403, 'A forged RFC 6578 token was not rejected with a valid-sync-token precondition');
	$calendarSync = liveHttp($davBaseUrl, $login, $password, 'REPORT', $calendarPath, $syncBody, array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($calendarSync, array(207), 'Initial RFC 6578 CalDAV sync');
	$calendarTombstoneToken = liveSyncToken($calendarSync['body']);
	liveAssert($calendarTombstoneToken !== '' && str_contains($calendarSync['body'], rawurlencode($eventUri)), 'Calendar sync did not expose a token and current resources');
	liveOk('CardDAV/CalDAV query, multiget and RFC 6578 sync/token validation');

	// Methods which could duplicate, rename or recursively remove business data.
	$copyDestination = $davBaseUrl.$addressBookPath.rawurlencode($prefix.'-copy.vcf');
	foreach (array('COPY', 'MOVE') as $unsafeMethod) {
		$response = liveHttp($davBaseUrl, $login, $password, $unsafeMethod, $contactPath, null, array('Destination: '.$copyDestination, 'Overwrite: F'));
		liveAssert($response['status'] === 405, $unsafeMethod.' on a CardDAV business resource was not rejected');
	}
	$copyGone = liveHttp($davBaseUrl, $login, $password, 'GET', $addressBookPath.rawurlencode($prefix.'-copy.vcf'));
	liveAssert($copyGone['status'] === 404, 'Rejected COPY/MOVE still created a business resource');
	liveHttpStatus(liveHttp($davBaseUrl, $login, $password, 'GET', $contactPath), array(200), 'Source after rejected COPY/MOVE');

	$addressDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $addressBookPath);
	liveAssert(in_array($addressDelete['status'], array(403, 405), true), 'Deleting an address-book collection was not rejected');
	$calendarDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $calendarPath);
	liveAssert(in_array($calendarDelete['status'], array(403, 405), true), 'Deleting a calendar collection was not rejected');
	$mkcalendarBody = '<?xml version="1.0" encoding="utf-8"?><c:mkcalendar xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:set><d:prop><d:displayname>Injected calendar</d:displayname></d:prop></d:set></c:mkcalendar>';
	$mkcalendarPath = liveDavPath(array('calendars', $login, $prefix.'-calendar'), true);
	$mkcalendar = liveHttp($davBaseUrl, $login, $password, 'MKCALENDAR', $mkcalendarPath, $mkcalendarBody, array('Content-Type: application/xml; charset=utf-8'));
	liveAssert(in_array($mkcalendar['status'], array(403, 405, 409), true), 'Creating an unmanaged calendar was not rejected');
	$mkAddressBook = liveHttp($davBaseUrl, $login, $password, 'MKCOL', liveDavPath(array('addressbooks', $login, $prefix.'-book'), true), null);
	liveAssert(in_array($mkAddressBook['status'], array(403, 405, 409), true), 'Creating an unmanaged address book was not rejected');

	$propPatchBody = '<?xml version="1.0" encoding="utf-8"?><d:propertyupdate xmlns:d="DAV:"><d:set><d:prop><d:displayname>Mutated by DAV</d:displayname></d:prop></d:set></d:propertyupdate>';
	$propPatch = liveHttp($davBaseUrl, $login, $password, 'PROPPATCH', $addressBookPath, $propPatchBody, array('Content-Type: application/xml; charset=utf-8'));
	liveAssert(in_array($propPatch['status'], array(207, 403, 405), true) && !str_contains($propPatch['body'], 'HTTP/1.1 200'), 'A protected address-book property was mutated');
	foreach (array('POST', 'TRACE') as $unsupportedMethod) {
		$response = liveHttp($davBaseUrl, $login, $password, $unsupportedMethod, $addressBookPath);
		liveAssert(in_array($response['status'], array(405, 501), true), $unsupportedMethod.' was not rejected safely');
	}
	liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId) !== null, 'Collection mutation attempt removed the appointment');
	liveAssert((int) liveRow($db, 'SELECT statut FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId)->statut === 1, 'Collection mutation attempt changed the contact');
	liveOk('dangerous business COPY/MOVE, collection deletion/creation, PROPPATCH and unsupported methods');

	// Lock semantics protect concurrent edits from desktop and mobile clients.
	$lockBody = '<?xml version="1.0" encoding="utf-8"?><d:lockinfo xmlns:d="DAV:"><d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype><d:owner><d:href>urn:cdav-live-test</d:href></d:owner></d:lockinfo>';
	$lock = liveHttp($davBaseUrl, $login, $password, 'LOCK', $contactPath, $lockBody, array('Depth: 0', 'Timeout: Second-60', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($lock, array(200, 201), 'CardDAV LOCK');
	$lockToken = (string) ($lock['headers']['lock-token'] ?? '');
	liveAssert($lockToken !== '', 'LOCK did not return a lock token');
	$lockedUpdate = liveHttp($davBaseUrl, $login, $password, 'PUT', $contactPath, $contactFresh['body'], array('Content-Type: text/vcard; charset=utf-8'));
	liveAssert($lockedUpdate['status'] === 423, 'A concurrent PUT without the lock token was accepted');
	$unlock = liveHttp($davBaseUrl, $login, $password, 'UNLOCK', $contactPath, null, array('Lock-Token: '.$lockToken));
	liveHttpStatus($unlock, array(204), 'CardDAV UNLOCK');
	liveOk('exclusive lock blocks a concurrent edit and unlocks with its opaque token');

	// Malformed XML, entity expansion, traversal and resource-consumption probes.
	$ambiguousFraming = liveHttp(
		$davBaseUrl,
		$login,
		$password,
		'PROPFIND',
		$addressBookPath,
		$propfindBody,
		array('Depth: 0', 'Content-Type: application/xml', 'Content-Length: '.strlen($propfindBody), 'Transfer-Encoding: chunked')
	);
	liveAssert($ambiguousFraming['status'] === 400, 'Ambiguous Content-Length/Transfer-Encoding request framing was accepted');
	$malformedXml = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, '<d:propfind xmlns:d="DAV:"><d:prop>', array('Depth: 0', 'Content-Type: application/xml'));
	liveAssert($malformedXml['status'] === 400, 'Malformed DAV XML was not rejected as a bad request');
	$xxeMarker = 'CDAV_XXE_MUST_NOT_APPEAR_'.$token;
	$xxeXml = '<?xml version="1.0"?><!DOCTYPE d:propfind [<!ENTITY xxe SYSTEM "data:text/plain,'.$xxeMarker.'">]>'
		.'<d:propfind xmlns:d="DAV:"><d:prop><d:displayname>&xxe;</d:displayname></d:prop></d:propfind>';
	$xxe = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, $xxeXml, array('Depth: 0', 'Content-Type: application/xml'));
	liveAssert($xxe['status'] === 400 && !str_contains($xxe['body'], $xxeMarker), 'External XML entity content was processed or reflected');
	$entityBomb = '<?xml version="1.0"?><!DOCTYPE d:propfind ['
		.'<!ENTITY a "1234567890"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
		.'<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">]>'
		.'<d:propfind xmlns:d="DAV:"><d:prop><d:displayname>&d;</d:displayname></d:prop></d:propfind>';
	$entityBombStart = microtime(true);
	$entityBombResponse = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, $entityBomb, array('Depth: 0', 'Content-Type: application/xml'));
	liveAssert($entityBombResponse['status'] === 400 && microtime(true) - $entityBombStart < 5, 'XML entity expansion was not rejected promptly');

	$traversal = liveHttp($davBaseUrl, $login, $password, 'GET', 'addressbooks/'.rawurlencode($login).'/default/%2e%2e/calendars');
	liveAssert(in_array($traversal['status'], array(400, 404), true), 'Encoded dot-segment traversal was not rejected');
	$backslashTraversal = liveHttp($davBaseUrl, $login, $password, 'GET', 'addressbooks/'.rawurlencode($login).'/default/%5c..%5cserver.php');
	liveAssert(in_array($backslashTraversal['status'], array(400, 403, 404), true), 'Encoded backslash traversal was not rejected safely');
	$depthInfinityStart = microtime(true);
	$depthInfinity = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, $propfindBody, array('Depth: infinity', 'Content-Type: application/xml; charset=utf-8'));
	liveHttpStatus($depthInfinity, array(207), 'Bounded Depth infinity PROPFIND');
	liveAssert(microtime(true) - $depthInfinityStart < 10 && strlen($depthInfinity['body']) < 5 * 1024 * 1024, 'Depth infinity was not bounded by Sabre/DAV');
	$invalidDepth = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $addressBookPath, $propfindBody, array('Depth: 2', 'Content-Type: application/xml; charset=utf-8'));
	liveAssert($invalidDepth['status'] === 400, 'An invalid WebDAV Depth header was accepted');

	$oversizedUri = $addressBookPath.str_repeat('u', 1025).'.vcf';
	$oversizedUriResponse = liveHttp($davBaseUrl, $login, $password, 'PUT', $oversizedUri, "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:short\r\nFN:Too long\r\nEND:VCARD\r\n", array('Content-Type: text/vcard'));
	liveAssert(in_array($oversizedUriResponse['status'], array(400, 414), true), 'An oversized CardDAV resource name was accepted');
	$oversizedUidUri = $prefix.'-oversized-uid.vcf';
	$oversizedUid = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:".str_repeat('x', 1025)."\r\nFN:Too long\r\nEND:VCARD\r\n";
	$oversizedUidResponse = liveHttp($davBaseUrl, $login, $password, 'PUT', $addressBookPath.rawurlencode($oversizedUidUri), $oversizedUid, array('Content-Type: text/vcard'));
	liveAssert($oversizedUidResponse['status'] === 400, 'An oversized vCard UID was accepted');
	$photoBombUri = $prefix.'-photo-bomb.vcf';
	$ihdr = pack('NNCCCCC', 100000, 100000, 8, 2, 0, 0, 0);
	$ihdrChunk = 'IHDR'.$ihdr;
	$iendChunk = 'IEND';
	$photoBomb = "\x89PNG\r\n\x1a\n"
		.pack('N', strlen($ihdr)).$ihdrChunk.pack('N', crc32($ihdrChunk))
		.pack('N', 0).$iendChunk.pack('N', crc32($iendChunk));
	$photoBombCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:".$prefix."-photo-bomb\r\nFN:Unsafe photo\r\n"
		."PHOTO:data:image/png;base64,".base64_encode($photoBomb)."\r\nEND:VCARD\r\n";
	$photoBombResponse = liveHttp($davBaseUrl, $login, $password, 'PUT', $addressBookPath.rawurlencode($photoBombUri), $photoBombCard, array('Content-Type: text/vcard'));
	liveAssert($photoBombResponse['status'] === 400, 'A compressed vCard image bomb was accepted');
	$malformedCardUri = $prefix.'-malformed.vcf';
	$malformedCard = liveHttp($davBaseUrl, $login, $password, 'PUT', $addressBookPath.rawurlencode($malformedCardUri), "This is not a vCard\r\n", array('Content-Type: text/vcard'));
	liveAssert(in_array($malformedCard['status'], array(400, 415), true), 'Non-vCard data was accepted');
	$journalUri = $prefix.'-journal.ics';
	$journalBody = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav test//EN\r\nBEGIN:VJOURNAL\r\nUID:".$prefix."-journal\r\nEND:VJOURNAL\r\nEND:VCALENDAR\r\n";
	$journal = liveHttp($davBaseUrl, $login, $password, 'PUT', $calendarPath.rawurlencode($journalUri), $journalBody, array('Content-Type: text/calendar'));
	liveAssert(in_array($journal['status'], array(400, 403, 415), true), 'Unsupported VJOURNAL returned HTTP '.$journal['status']);
	$oversizedBodyUri = $prefix.'-oversized-body.vcf';
	$oversizedBody = str_repeat('A', 16 * 1024 * 1024 + 1);
	$oversizedBodyResponse = liveHttp($davBaseUrl, $login, $password, 'PUT', $addressBookPath.rawurlencode($oversizedBodyUri), $oversizedBody, array('Content-Type: text/vcard'));
	unset($oversizedBody);
	liveAssert($oversizedBodyResponse['status'] === 413, 'The global DAV body-size limit did not return HTTP 413');
	foreach (array($oversizedUidUri, $photoBombUri, $malformedCardUri, $oversizedBodyUri) as $rejectedCardUri) {
		liveAssert(liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE uuidext = \''.$db->escape($rejectedCardUri).'\'') === null, 'Rejected vCard left a mapping or business record');
	}
	liveAssert(liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE uuidext = \''.$db->escape($journalUri).'\'') === null, 'Rejected calendar data left a mapping or event');
	liveOk('malformed/hostile XML, traversal, depth, size, URI, UID, image and media-component limits');

	// Object-level and function-level authorization with a non-admin account.
	$limitedCalendarPath = liveDavPath(array('calendars', $limitedLogin, $limitedUserId.'-cal-'.$limitedLogin), true);
	$limitedCalendar = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'PROPFIND', $limitedCalendarPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml'));
	liveHttpStatus($limitedCalendar, array(207), 'Restricted own calendar discovery');
	$foreignGet = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'GET', $eventPath);
	liveAssert(in_array($foreignGet['status'], array(403, 404), true), 'Restricted user read another user calendar object');
	$foreignPut = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'PUT', $eventPath, str_replace('Phone appointment', 'Unauthorized change', $eventFresh['body']), array('Content-Type: text/calendar'));
	liveAssert(in_array($foreignPut['status'], array(403, 404), true), 'Restricted user updated another user calendar object');
	$foreignDelete = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'DELETE', $eventPath);
	liveAssert(in_array($foreignDelete['status'], array(403, 404), true), 'Restricted user deleted another user calendar object');
	$guessedAttach = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'PUT', $limitedCalendarPath.rawurlencode($eventUri), $eventFresh['body'], array('Content-Type: text/calendar'));
	liveAssert(in_array($guessedAttach['status'], array(403, 404), true), 'Restricted user attached a guessed foreign object URI to their calendar');
	$eventStillOwned = liveRow($db, 'SELECT a.label, COUNT(ar.rowid) AS assignments FROM '.MAIN_DB_PREFIX.'actioncomm a
		LEFT JOIN '.MAIN_DB_PREFIX.'actioncomm_resources ar ON ar.fk_actioncomm = a.id AND ar.element_type = \'user\'
		WHERE a.id = '.$eventId.' GROUP BY a.id, a.label');
	liveAssert($eventStillOwned !== null && !str_contains((string) $eventStillOwned->label, 'Unauthorized') && (int) $eventStillOwned->assignments === 1, 'Foreign authorization probes changed the native appointment');

	$limitedEventUri = $prefix.'-limited-own.ics';
	$limitedEventBody = str_replace(array($eventUid, $prefix.' External appointment'), array($prefix.'-limited-own-uid', $prefix.' Limited own appointment'), $eventCard);
	$limitedEventPath = $limitedCalendarPath.rawurlencode($limitedEventUri);
	$limitedEventCreate = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'PUT', $limitedEventPath, $limitedEventBody, array('Content-Type: text/calendar'));
	liveHttpStatus($limitedEventCreate, array(201, 204), 'Restricted own appointment creation');
	$limitedEventMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE uuidext = \''.$db->escape($limitedEventUri).'\'');
	liveAssert($limitedEventMap !== null, 'Restricted own appointment was not mapped');
	$limitedEventId = (int) $limitedEventMap->fk_object;
	$created['events'][] = $limitedEventId;
	$delegatedRead = liveHttp($davBaseUrl, $login, $password, 'GET', $limitedEventPath);
	liveHttpStatus($delegatedRead, array(200), 'Native all-agenda delegated read');
	$delegatedUpdateBody = str_replace('Limited own appointment', 'Delegated Dolibarr update', $delegatedRead['body']);
	$delegatedUpdate = liveHttp($davBaseUrl, $login, $password, 'PUT', $limitedEventPath, $delegatedUpdateBody, array('Content-Type: text/calendar'));
	liveHttpStatus($delegatedUpdate, array(200, 204), 'Native all-agenda delegated write');
	$delegatedVerification = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'GET', $limitedEventPath);
	liveAssert($delegatedVerification['status'] === 200 && str_contains($delegatedVerification['body'], 'Delegated Dolibarr update'), 'Delegated calendar update did not reach its Dolibarr owner');
	$limitedEventDelete = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'DELETE', $limitedEventPath);
	liveHttpStatus($limitedEventDelete, array(200, 204), 'Restricted own appointment deletion');
	liveAssert(liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'GET', $limitedEventPath)['status'] === 404, 'Restricted calendar still exposes its detached appointment');
	if (liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$limitedEventId) !== null) {
		// The delegated organizer became another native assignee during iTIP
		// processing. Removing the last assignment performs the native delete.
		$delegatedOwnerPath = $calendarPath.rawurlencode($limitedEventUri);
		liveHttpStatus(liveHttp($davBaseUrl, $login, $password, 'DELETE', $delegatedOwnerPath), array(200, 204), 'Last delegated appointment assignment deletion');
	}
	liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$limitedEventId) === null, 'Last calendar detachment did not delete the module-owned ActionComm');
	$created['events'] = array_values(array_diff($created['events'], array($limitedEventId)));

	$limitedAddressBookPath = liveDavPath(array('addressbooks', $limitedLogin, 'default'), true);
	$foreignAddressBookDiscovery = liveHttp($davBaseUrl, $login, $password, 'PROPFIND', $limitedAddressBookPath, $propfindBody, array('Depth: 0', 'Content-Type: application/xml'));
	liveAssert(in_array($foreignAddressBookDiscovery['status'], array(403, 404), true), 'CardDAV exposed another principal address-book namespace');
	$publicContactRead = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'GET', $limitedAddressBookPath.rawurlencode($contactUri));
	liveHttpStatus($publicContactRead, array(200), 'Restricted public-contact read');
	$readOnlyPut = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'PUT', $limitedAddressBookPath.rawurlencode($contactUri), str_replace('Alice Dolibarr', 'Unauthorized contact', $publicContactRead['body']), array('Content-Type: text/vcard'));
	liveAssert(in_array($readOnlyPut['status'], array(403, 405), true), 'Read-only contact user performed PUT');
	$readOnlyDelete = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'DELETE', $limitedAddressBookPath.rawurlencode($contactUri));
	liveAssert(in_array($readOnlyDelete['status'], array(403, 405), true), 'Read-only contact user performed DELETE');

	$privateUri = $prefix.'-private.vcf';
	$privateCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:".$prefix."-private-uid\r\nFN:Private Contact\r\nN:Contact;Private;;;\r\nCLASS:PRIVATE\r\nEND:VCARD\r\n";
	$privatePath = $addressBookPath.rawurlencode($privateUri);
	$privateCreate = liveHttp($davBaseUrl, $login, $password, 'PUT', $privatePath, $privateCard, array('Content-Type: text/vcard'));
	liveHttpStatus($privateCreate, array(201, 204), 'Private contact creation');
	$privateMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE uuidext = \''.$db->escape($privateUri).'\'');
	liveAssert($privateMap !== null, 'Private contact mapping is missing');
	$privateId = (int) $privateMap->fk_object;
	$created['contacts'][] = $privateId;
	$privateLeak = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'GET', $limitedAddressBookPath.rawurlencode($privateUri));
	liveAssert($privateLeak['status'] === 404, 'Another user read a private contact by guessing its resource URI');
	$noDocuments = liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'PROPFIND', 'documents/', $propfindBody, array('Depth: 0', 'Content-Type: application/xml'));
	liveAssert(in_array($noDocuments['status'], array(403, 404), true), 'A non-admin user accessed the Dolibarr document tree');
	$orphanId = 2147000000 + hexdec(substr($token, 0, 4));
	$orphanUri = $prefix.'-orphan.vcf';
	$orphanUid = $prefix.'-orphan-uid';
	liveAssert((bool) $db->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_cardmap'
		.' (entity, object_type, fk_object, uuidext, uuidhash, sourceuid, sourceuid_hash) VALUES ('
		.$entity.", 'ct', ".$orphanId.", '".$db->escape($orphanUri)."', '".hash('sha256', $orphanUri)
		."', '".$db->escape($orphanUid)."', '".hash('sha256', $orphanUid)."')"), 'Unable to seed orphan CardDAV mapping metadata');
	liveAssert((bool) $db->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_vcard'
		.' (entity, object_type, fk_object, carddata, datahash) VALUES ('.$entity.", 'ct', ".$orphanId
		.", 'BEGIN:VCARD\\r\\nVERSION:3.0\\r\\nEND:VCARD\\r\\n', '".hash('sha256', 'orphan')."')"), 'Unable to seed orphan vCard metadata');
	$orphanReplacement = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:".$orphanUid."\r\nFN:Recovered identity\r\nN:Identity;Recovered;;;\r\nEND:VCARD\r\n";
	liveHttpStatus(liveHttp($davBaseUrl, $login, $password, 'PUT', $addressBookPath.rawurlencode($orphanUri),
		$orphanReplacement, array('Content-Type: text/vcard')), array(201, 204), 'CardDAV orphan identity reconciliation');
	$recoveredMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE entity = '.$entity
		.' AND object_type = \'ct\' AND uuidext = \''.$db->escape($orphanUri).'\'');
	liveAssert($recoveredMap !== null && (int) $recoveredMap->fk_object !== $orphanId, 'A native contact was not recreated after orphan reconciliation');
	$created['contacts'][] = (int) $recoveredMap->fk_object;
	liveAssert(liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE entity = '.$entity.' AND object_type = \'ct\' AND fk_object = '.$orphanId) === null
		&& liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_vcard WHERE entity = '.$entity.' AND object_type = \'ct\' AND fk_object = '.$orphanId) === null,
		'Orphaned CardDAV protocol metadata survived native-object reconciliation');
	liveAssert((int) liveRow($db, 'SELECT statut FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId)->statut === 1, 'Read-only authorization probes modified the native contact');
	liveOk('BOLA/function authorization, metadata reconciliation and native-right calendar delegation');

	$eventDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $eventPath);
	liveHttpStatus($eventDelete, array(200, 204), 'CalDAV appointment deletion');
	$todoDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $todoPath);
	liveHttpStatus($todoDelete, array(200, 204), 'CalDAV VTODO deletion');
	$scheduledDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $scheduledPath);
	liveHttpStatus($scheduledDelete, array(200, 204), 'Scheduled CalDAV appointment deletion');
	if (liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$scheduledId) !== null) {
		$scheduledAttendeePath = $limitedCalendarPath.rawurlencode($scheduledUri);
		liveHttpStatus(liveHttp($davBaseUrl, $limitedLogin, $limitedPassword, 'DELETE', $scheduledAttendeePath), array(200, 204), 'Scheduled attendee calendar detachment');
	}
	liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId) === null, 'Module-owned appointment still exists after CalDAV deletion');
	liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$todoId) === null, 'Module-owned VTODO still exists after CalDAV deletion');
	liveAssert(liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object IN ('.$eventId.', '.$todoId.')') === null, 'CalDAV deletion left an orphan technical mapping');
	liveAssert(liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'links WHERE objecttype = \'action\' AND objectid = '.$eventId) === null, 'CalDAV appointment deletion left orphan native attachment links');
	liveAssert(is_file($physicalFile), 'CalDAV deletion silently destroyed a physical Dolibarr appointment document');
	$calendarDeleteSyncBody = str_replace('<d:sync-token/>', '<d:sync-token>'.$xmlEscape($calendarTombstoneToken).'</d:sync-token>', $syncBody);
	$calendarDeleteSync = liveHttp($davBaseUrl, $login, $password, 'REPORT', $calendarPath, $calendarDeleteSyncBody, array('Depth: 0', 'Content-Type: application/xml'));
	liveHttpStatus($calendarDeleteSync, array(207), 'CalDAV tombstone sync');
	liveAssert(str_contains($calendarDeleteSync['body'], rawurlencode($eventUri)) && str_contains($calendarDeleteSync['body'], 'HTTP/1.1 404'), 'CalDAV sync did not return a deleted-resource tombstone');
	$created['events'] = array();
	liveOk('CalDAV deletion through native lifecycles, RFC 6578 tombstones and native document retention');

	$thirdDelete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $thirdPath);
	liveHttpStatus($thirdDelete, array(200, 204), 'CardDAV third-party deletion');
	$thirdInactive = liveRow($db, 'SELECT status FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$thirdId);
	liveAssert($thirdInactive !== null && (int) $thirdInactive->status === 0, 'Remote third-party deletion did not preserve an inactive Dolibarr row');
	$thirdGone = liveHttp($davBaseUrl, $login, $password, 'GET', $thirdPath);
	liveAssert($thirdGone['status'] === 404, 'Inactive third party is still exposed through CardDAV');

	foreach (array($contactPath => $contactId, $attendeePath => $attendeeId) as $path => $id) {
		$delete = liveHttp($davBaseUrl, $login, $password, 'DELETE', $path);
		liveHttpStatus($delete, array(200, 204), 'CardDAV contact deletion');
		$inactive = liveRow($db, 'SELECT statut FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.((int) $id));
		liveAssert($inactive !== null && (int) $inactive->statut === 0, 'Remote contact deletion did not preserve an inactive Dolibarr row');
		$gone = liveHttp($davBaseUrl, $login, $password, 'GET', $path);
		liveAssert($gone['status'] === 404, 'Inactive contact is still exposed through CardDAV');
	}
	$cardDeleteSyncBody = str_replace('<d:sync-token/>', '<d:sync-token>'.$xmlEscape($cardTombstoneToken).'</d:sync-token>', $syncBody);
	$cardDeleteSync = liveHttp($davBaseUrl, $login, $password, 'REPORT', $addressBookPath, $cardDeleteSyncBody, array('Depth: 0', 'Content-Type: application/xml'));
	liveHttpStatus($cardDeleteSync, array(207), 'CardDAV tombstone sync');
	liveAssert(str_contains($cardDeleteSync['body'], rawurlencode($contactUri)) && str_contains($cardDeleteSync['body'], 'HTTP/1.1 404'), 'CardDAV sync did not return the soft-deactivated contact as a tombstone');
	liveOk('remote company/contact deletion is soft-only, hidden from DAV and journaled as tombstones');
} catch (Throwable $throwable) {
	$failure = $throwable;
} finally {
	// Cleanup deliberately uses native Dolibarr object methods for business data.
	try {
		if ($admin) {
			$GLOBALS['user'] = $admin;
		}
		// A failed assertion can occur immediately after an HTTP write, before its
		// id was appended above. Recover every object made by the isolated users so
		// an interrupted/failed run cannot pollute the test database.
		$creatorIds = array_unique(array_filter(array_map('intval', $created['users'])));
		if ($creatorIds) {
			$creatorList = implode(', ', $creatorIds);
			foreach (array(
				'events' => 'SELECT id AS object_id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_user_author IN ('.$creatorList.')',
				'contacts' => 'SELECT rowid AS object_id FROM '.MAIN_DB_PREFIX.'socpeople WHERE fk_user_creat IN ('.$creatorList.')',
				'thirdparties' => 'SELECT rowid AS object_id FROM '.MAIN_DB_PREFIX.'societe WHERE fk_user_creat IN ('.$creatorList.')',
			) as $bucket => $cleanupSql) {
				$cleanupResult = $db->query($cleanupSql);
				if (!$cleanupResult) {
					throw new RuntimeException('Unable to discover isolated '.$bucket.' during cleanup: '.$db->lasterror());
				}
				while ($cleanupRow = $db->fetch_object($cleanupResult)) {
					$created[$bucket][] = (int) $cleanupRow->object_id;
				}
			}
		}
		foreach (array_unique(array_map('intval', $created['events'])) as $id) {
			$cleanupLinks = array();
			$linkReader = new Link($db);
			if ($id > 0 && $linkReader->fetchAll($cleanupLinks, 'action', $id) >= 0) {
				foreach ($cleanupLinks as $cleanupLink) {
					$cleanupLink->delete($admin);
				}
			}
			$event = new ActionComm($db);
			if ($id > 0 && $event->fetch($id) > 0) {
				$event->delete($admin);
			}
			$db->query('DELETE FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$id);
		}
		foreach (array_unique(array_map('intval', $created['contacts'])) as $id) {
			$contact = new Contact($db);
			if ($id > 0 && $contact->fetch($id, $admin) > 0) {
				$contact->delete($admin);
			}
			$db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE object_type = \'ct\' AND fk_object = '.$id);
		}
		foreach (array_reverse(array_unique(array_map('intval', $created['thirdparties']))) as $id) {
			$thirdParty = new Societe($db);
			if ($id > 0 && $thirdParty->fetch($id) > 0) {
				$thirdParty->delete($id, $admin, 1);
			}
			$db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE object_type = \'th\' AND fk_object = '.$id);
		}
		foreach (array_unique($created['document_dirs']) as $directory) {
			if ($agendaOutputRoot !== '' && is_string($directory) && str_starts_with($directory, rtrim($agendaOutputRoot, '/').'/')) {
				$deletedCount = 0;
				dol_delete_dir_recursive($directory, 0, 1, 0, $deletedCount, 1, 1);
			}
		}
		foreach (array_unique($created['photo_dirs']) as $directory) {
			if ($societeOutputRoot !== '' && is_string($directory)
				&& str_starts_with($directory, rtrim($societeOutputRoot, '/').'/contact/')) {
				$deletedCount = 0;
				dol_delete_dir_recursive($directory, 0, 1, 0, $deletedCount, 1, 1);
			}
		}
		foreach ($constantSnapshots as $name => $snapshot) {
			liveRestoreConstant($db, $name, $entity, $snapshot);
		}
		foreach (array_unique(array_map('intval', $created['users'])) as $id) {
			$cleanupUser = new User($db);
			$cleanupUser->id = $id;
			if ($id > 0 && $cleanupUser->fetch($id) > 0) {
				$cleanupUser->delete($admin);
			}
		}
		(new \Dolibarr\CDav\SyncStore($db, $entity))->pruneOrphanCollections();
	} catch (Throwable $cleanupFailure) {
		if ($failure === null) {
			$failure = $cleanupFailure;
		} else {
			fwrite(STDERR, '[CLEANUP WARNING] '.$cleanupFailure->getMessage()."\n");
		}
	}
}

if ($failure !== null) {
	fwrite(STDERR, '[FAIL] '.$failure->getMessage().' in '.$failure->getFile().':'.$failure->getLine()."\n");
	exit(1);
}

$orphanSync = liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_sync_collection collection'
	.' LEFT JOIN '.MAIN_DB_PREFIX.'user owner ON owner.rowid = CASE WHEN collection.scope = \'card\''
	.' THEN MOD(collection.collection_id, 100000) ELSE collection.collection_id END'
	.' WHERE collection.entity = '.((int) $entity).' AND collection.scope IN (\'cal\', \'card\') AND owner.rowid IS NULL');
liveAssert($orphanSync !== null && (int) $orphanSync->nb === 0, 'Orphan RFC 6578 collections remain after native user cleanup');

liveOk('isolated business records removed and local constants restored');
fwrite(STDOUT, "All live CDav integration tests passed.\n");
