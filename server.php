<?php
/******************************************************************
 * cdav is a Dolibarr module
 * It allows caldav and carddav clients to sync with Dolibarr
 * calendars and contacts.
 *
 * cdav is distributed under GNU/GPLv3 license
 * (see COPYING file)
 *
 * cdav uses Sabre/dav library http://sabre.io/dav/
 * Sabre/dav is distributed under use the three-clause BSD-license
 * 
 * Author : Befox SARL http://www.befox.fr/
 *
 ******************************************************************/

error_reporting(E_ALL & ~E_NOTICE);
ini_set("display_errors", 0);
ini_set("log_errors", 1);

function cdav_exception_error_handler($errno, $errstr, $errfile, $errline) {
	if(function_exists("debug_log"))
	{
		debug_log("Error $errno : $errstr - $errfile @ $errline");
		foreach(debug_backtrace(false) as $trace)
			debug_log(" - ".($trace['file'] ?? '').'@'.($trace['line'] ?? '').' '.($trace['function'] ?? '').'(...)');
	}
	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

function debug_log($txt)
{
	// Keep DAV diagnostics inside Dolibarr's configured logger. Verbose CDav
	// traces are opt-in to avoid separate, unrotated files containing PII.
	if (function_exists('getDolGlobalInt') && getDolGlobalInt('CDAV_DEBUG') && function_exists('dol_syslog')) {
		dol_syslog('[CDav] '.(string) $txt, LOG_DEBUG);
	}
}

/** Import an HTTP Basic header when PHP-FPM did not populate PHP_AUTH_*. */
function cdav_import_basic_auth($header)
{
	if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
		return;
	}
	if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', trim((string) $header), $matches)) {
		return;
	}
	$decoded = base64_decode($matches[1], true);
	if ($decoded === false || strpos($decoded, ':') === false) {
		return;
	}
	list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $decoded, 2);
}

// HTTP auth workaround for php in fastcgi mode HTTP_AUTHORIZATION set by rewrite engine in .htaccess
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
	cdav_import_basic_auth($_SERVER['HTTP_AUTHORIZATION']);
}

// HTTP auth workaround for php in fastcgi mode REDIRECT_HTTP_AUTHORIZATION set by rewrite engine in .htaccess
if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
	cdav_import_basic_auth($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}

define('NOTOKENRENEWAL',1); 								// Disables token renewal
if (! defined('NOLOGIN')) define('NOLOGIN','1');
if (! defined('NOCSRFCHECK')) define('NOCSRFCHECK','1');	// We accept to go on this page from external web site.
if (! defined('NOREQUIREMENU')) define('NOREQUIREMENU','1');
if (! defined('NOREQUIREHTML')) define('NOREQUIREHTML','1');
if (! defined('NOREQUIREAJAX')) define('NOREQUIREAJAX','1');
function llxHeader() { }
function llxFooter() { }

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

if(!defined('DOL_DOCUMENT_ROOT'))
	define('DOL_DOCUMENT_ROOT', $dolibarr_main_document_root);

require DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';	// auth method
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

if(!isModEnabled('cdav'))
	die('module CDav not enabled !');

/** Plain HTTP is acceptable only on the machine-local test transport. */
function cdav_is_loopback_address($address)
{
	$address = strtolower(trim((string) $address));
	if ($address === '::1' || $address === '0:0:0:0:0:0:0:1') {
		return true;
	}
	if (str_starts_with($address, '::ffff:')) {
		$address = substr($address, 7);
	}
	return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
		&& str_starts_with($address, '127.');
}

/** Accept forwarded HTTPS only from an explicitly configured proxy address. */
function cdav_is_secure_transport()
{
	if (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on') {
		return true;
	}
	$trusted = preg_split('/[\s,;]+/', getDolGlobalString('CDAV_TRUSTED_PROXY_IPS'), -1, PREG_SPLIT_NO_EMPTY);
	$remoteAddress = function_exists('getUserRemoteIP') ? getUserRemoteIP(1) : ($_SERVER['REMOTE_ADDR'] ?? '');
	if (!$trusted || !in_array($remoteAddress, $trusted, true)) {
		return false;
	}
	$forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
	$forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
	return $forwardedProto === 'https' || $forwardedSsl === 'on';
}

// RFC 4918 forbids offering Basic authentication over an insecure transport.
// Keep loopback available for the destructive, isolated local integration suite.
if (!cdav_is_secure_transport()
	&& !cdav_is_loopback_address($_SERVER['REMOTE_ADDR'] ?? '')
	&& !getDolGlobalInt('CDAV_ALLOW_INSECURE_HTTP')) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo "HTTPS is required for DAV authentication.\n";
	if (is_object($db)) $db->close();
	exit;
}

//set_error_handler("cdav_exception_error_handler", E_ERROR | E_USER_ERROR |
//				E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR );


require_once __DIR__.'/lib/cdav.lib.php';

// define CDAV_CONTACT_TAG if not
if(!defined('CDAV_CONTACT_TAG'))
{
	define('CDAV_CONTACT_TAG', getDolGlobalInt('CDAV_CONTACT_TAG'));
}

// define CDAV_URI_KEY if not
if(!defined('CDAV_URI_KEY'))
{
	define('CDAV_URI_KEY', getDolGlobalString('CDAV_URI_KEY', substr(md5($_SERVER['HTTP_HOST'] ?? 'localhost'), 0, 8)));
}

// define CDAV_TASK_USER_ROLE if not
if(!defined('CDAV_TASK_USER_ROLE'))
{
	define('CDAV_TASK_USER_ROLE', getDolGlobalInt('CDAV_TASK_USER_ROLE'));
}

// define CDAV_SYNC_PAST if not
if(!defined('CDAV_SYNC_PAST'))
{
	define('CDAV_SYNC_PAST', getDolGlobalInt('CDAV_SYNC_PAST', 31));
}

// define CDAV_SYNC_FUTURE if not
if(!defined('CDAV_SYNC_FUTURE'))
{
	define('CDAV_SYNC_FUTURE', getDolGlobalInt('CDAV_SYNC_FUTURE', 365));
}

// define CDAV_TASK_SYNC if not
if(!defined('CDAV_TASK_SYNC'))
{
	define('CDAV_TASK_SYNC', getDolGlobalInt('CDAV_TASK_SYNC'));
}

// define CDAV_INTERV_SYNC if not
if(!defined('CDAV_INTERV_SYNC'))
{
	define('CDAV_INTERV_SYNC', getDolGlobalInt('CDAV_INTERV_SYNC'));
}

// define CDAV_INTERV_USER_ROLE if not
if(!defined('CDAV_INTERV_USER_ROLE'))
{
	define('CDAV_INTERV_USER_ROLE', getDolGlobalInt('CDAV_INTERV_USER_ROLE'));
}

// define CDAV_THIRD_SYNC if not
if(!defined('CDAV_THIRD_SYNC'))
{
	define('CDAV_THIRD_SYNC', getDolGlobalInt('CDAV_THIRD_SYNC'));
}

// define CDAV_MEMBER_SYNC if not
if(!defined('CDAV_MEMBER_SYNC'))
{
	define('CDAV_MEMBER_SYNC', getDolGlobalInt('CDAV_MEMBER_SYNC'));
}

// 0 < CDAV_ADDRESSBOOK_ID_SHIFT = Contacts
// CDAV_ADDRESSBOOK_ID_SHIFT   < 2*CDAV_ADDRESSBOOK_ID_SHIFT = Thirdparties
// 2*CDAV_ADDRESSBOOK_ID_SHIFT < 3*CDAV_ADDRESSBOOK_ID_SHIFT = Members
define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000); 

// Sabre/dav configuration

use Sabre\DAV;
use Sabre\DAVACL;

// The autoloader
require DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
require __DIR__.'/class/CDavSyncStore.php';
require __DIR__.'/class/CDavVCardStore.php';
require __DIR__.'/class/CDavManagedAttachmentStore.php';
require __DIR__.'/class/PrincipalsDolibarr.php';
require __DIR__.'/class/CardDAVDolibarr.php';
require __DIR__.'/class/CalDAVDolibarr.php';
require __DIR__.'/class/CDavRequestGuardPlugin.php';
require __DIR__.'/class/CDavCardDAVPlugin.php';
require __DIR__.'/class/CDavCalDAVPlugin.php';
require __DIR__.'/class/CDavSchedulingPlugin.php';
require __DIR__.'/class/CDavManagedAttachmentPlugin.php';

$user = new User($db);

if(isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER']!='')
{
	$user->fetch('',$_SERVER['PHP_AUTH_USER']);
	$user->loadRights();
}

// main.inc.php runs before HTTP Basic authentication, so its translator may
// not use the DAV account's language. Recreate it for the authenticated user.
if (!empty($user->lang)) {
	$langs = new Translate('', $conf);
	$langs->setDefaultLang($user->lang);
}
$langs->load("cdav@cdav");

$cdavLib = new CdavLib($user, $db, $langs);

// Authentication
$authBackend = new DAV\Auth\Backend\BasicCallBack(function ($username, $password)
{
	global $user;
	global $conf;
	global $dolibarr_main_authentication;
	
	
	if ( ! isset($user->login) || $user->login=='')
	{
		debug_log("Authentication failed 1 for user $username with pass ".str_pad('', strlen($password), '*'));
		return false;
	}
	if (!empty($user->societe_id) || !empty($user->socid)) // external user
	{
		debug_log("Authentication failed 2 for user $username with pass ".str_pad('', strlen($password), '*'));
		return false;
	}
	if ($user->login!=$username)
	{
		debug_log("Authentication failed 3 for user $username with pass ".str_pad('', strlen($password), '*'));
		return false;
	}
	/*if ($user->pass_indatabase_crypted == '' || dol_hash($password) != $user->pass_indatabase_crypted)
		return false;*/
	
	// Authentication mode
	// disable googlerecaptcha
	$dolibarr_main_authentication = str_replace('googlerecaptcha', 'dolibarr', (string) $dolibarr_main_authentication);
	if (empty($dolibarr_main_authentication))
		$dolibarr_main_authentication='http,dolibarr';
	$authmode = explode(',',$dolibarr_main_authentication);
	// The virtual host/context selects the entity. Never let a DAV caller
	// switch authentication scope with a query parameter.
	$entity = !empty($conf->entity) ? (int) $conf->entity : 1;
	if (checkLoginPassEntity($username, $password, $entity, $authmode, 'dav') != $username)
	{
		debug_log("Authentication failed 4 for user $username with pass ".str_pad('', strlen($password), '*'));
		return false;
	}
	debug_log("Authentication OK for user $username ");
	return true;
});

$authBackend->setRealm('Dolibarr');

// The lock manager is reponsible for making sure users don't overwrite
// each others changes.
$lockBackend = new DAV\Locks\Backend\File($dolibarr_main_data_root.'/cdav/.locks');

// Principals Backend
$principalBackend = new DAVACL\PrincipalBackend\Dolibarr($user,$db);

// CardDav & CalDav Backend
$carddavBackend   = new Sabre\CardDAV\Backend\Dolibarr($user,$db,$langs);
$schedulingEnabled = (bool) getDolGlobalInt('CDAV_SCHEDULING');
$caldavBackend = $schedulingEnabled
	? new Sabre\CalDAV\Backend\DolibarrScheduling($user, $db, $langs, $cdavLib)
	: new Sabre\CalDAV\Backend\Dolibarr($user, $db, $langs, $cdavLib);
$managedAttachmentStore = new \Dolibarr\CDav\ManagedAttachmentStore($db, $user);

// Setting up the directory tree //
$nodes = array(
	// /principals
	new \Sabre\CalDAV\Principal\Collection($principalBackend),
	// /addressbook
	new \Sabre\CardDAV\AddressBookRoot($principalBackend, $carddavBackend),
	// /calendars
	new \Sabre\CalDAV\CalendarRoot($principalBackend, $caldavBackend),
);
if (getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS') && $managedAttachmentStore->isAvailable()) {
	$nodes[] = new \Dolibarr\CDav\ManagedAttachmentCollection($managedAttachmentStore, $user);
}

// Files are deliberately not exposed from this calendar/contact endpoint.
// A raw DAV\FS\Directory on DOL_DATA_ROOT bypasses Dolibarr's document
// permissions and can reveal unrelated backups, temporary files or private
// business documents. Install and configure Dolibarr's native DAV module for
// WebDAV access; it scopes the exposed public/private/ECM directories itself.

// The server object is responsible for making sense out of the WebDAV protocol
$server = new DAV\Server($nodes);
DAV\Server::$exposeVersion = false;
$server->on('exception', static function ($exception) {
	$message = str_replace(array("\r", "\n"), ' ', (string) $exception->getMessage());
	$httpCode = method_exists($exception, 'getHTTPCode') ? (int) $exception->getHTTPCode() : 500;
	dol_syslog('[CDav] DAV exception '.get_class($exception).': '.$message
		.' in '.$exception->getFile().':'.$exception->getLine(), $httpCode >= 500 ? LOG_ERR : LOG_DEBUG);
	if (getDolGlobalInt('CDAV_DEBUG')) {
		dol_syslog('[CDav] DAV exception trace '.str_replace(array("\r", "\n"), ' ', $exception->getTraceAsString()), LOG_DEBUG);
	}
}, 1);

// If your server is not on your webroot, make sure the following line has the
// correct information
$server->setBaseUri(dol_buildpath('cdav/server.php', 1).'/');


$server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend));
$maxRequestMb = getDolGlobalInt('CDAV_MAX_REQUEST_MB', 16);
if ($maxRequestMb <= 0) $maxRequestMb = 16;
$maxRequestBytes = min($maxRequestMb, 64) * 1024 * 1024;
$server->addPlugin(new \Dolibarr\CDav\RequestGuardPlugin(
	$maxRequestBytes,
	$carddavBackend,
	$caldavBackend,
	$user
));
$server->addPlugin(new \Sabre\DAV\Locks\Plugin($lockBackend));
$server->addPlugin(new \Sabre\DAV\Sync\Plugin());
$server->addPlugin(new \Sabre\DAV\Browser\Plugin());
$server->addPlugin(new \Dolibarr\CDav\CardDAVPlugin($maxRequestBytes));
// llx_cdav_scheduling.calendardata is MEDIUMTEXT (2^24 - 1 bytes).
$server->addPlugin(new \Dolibarr\CDav\CalDAVPlugin(
	min($maxRequestBytes, 16777215),
	(bool) getDolGlobalInt('CDAV_DELEGATION')
));
$DAVACL_plugin = new \Sabre\DAVACL\Plugin();
$DAVACL_plugin->allowUnauthenticatedAccess = false;
$server->addPlugin($DAVACL_plugin);
if ($schedulingEnabled) {
	// Sabre local delivery is enabled; no iMIP plugin is installed, so the
	// module never emits invitation email behind Dolibarr's back.
	$server->addPlugin(new \Dolibarr\CDav\SchedulingPlugin($caldavBackend));
}
if (getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS') && $managedAttachmentStore->isAvailable()) {
	$server->addPlugin(new \Dolibarr\CDav\ManagedAttachmentPlugin($caldavBackend, $managedAttachmentStore, $user));
}

debug_log("Ready : ".$user->login);

// All we need to do now, is to fire up the server
$server->exec();

if (is_object($db)) $db->close();
