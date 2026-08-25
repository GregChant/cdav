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
 
define('NOTOKENRENEWAL',1); 								// Disables token renewal
if (! defined('NOLOGIN')) define('NOLOGIN','1');
if (! defined('NOCSRFCHECK')) define('NOCSRFCHECK','1');	// We accept to go on this page from external web site.
if (! defined('NOREQUIREMENU')) define('NOREQUIREMENU','1');
if (! defined('NOREQUIREHTML')) define('NOREQUIREHTML','1');
if (! defined('NOREQUIREAJAX')) define('NOREQUIREAJAX','1');
function llxHeader() { }
function llxFooter() { }

function base64url_decode($data) {
	$data = strtr((string) $data, '-_', '+/');
	$data .= str_repeat('=', (4 - strlen($data) % 4) % 4);
	return base64_decode($data, true);
} 

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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';

// Load traductions files requiredby by page
$langs->load("cdav");


//Get all event
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

// Parse the signed token. Keep the historical encrypted formats readable so
// existing subscriptions continue to work after the module upgrade.
$tokenData = base64url_decode(GETPOST('token', 'alphanohtml'));
$tokenPayload = false;
if (is_string($tokenData) && strlen($tokenData) > 33 && substr($tokenData, -33, 1) === '.') {
	$payload = substr($tokenData, 0, -33);
	$signature = substr($tokenData, -32);
	if (hash_equals(hash_hmac('sha256', $payload, CDAV_URI_KEY, true), $signature)) {
		$tokenPayload = $payload;
	}
}
if ($tokenPayload === false && is_string($tokenData)) {
	$tokenPayload = @openssl_decrypt($tokenData, 'aes-256-cbc', CDAV_URI_KEY, true);
	if ($tokenPayload === false) {
		$tokenPayload = @openssl_decrypt($tokenData, 'bf-ecb', CDAV_URI_KEY, true);
	}
}
$arrTmp = is_string($tokenPayload) ? explode('+ø+', $tokenPayload, 2) : array();

if (count($arrTmp) !== 2 || !ctype_digit(trim($arrTmp[0])) || (int) trim($arrTmp[0]) <= 0 || !in_array(trim($arrTmp[1]), array('nolabel', 'full'), true))
{
	http_response_code(403);
	echo 'Unauthorized Access !';
	exit;
}

$id 	= (int) trim($arrTmp[0]);
$type 	= trim($arrTmp[1]);

// Refuse deleted, inactive, external or cross-entity users even if an old
// token is still known.
$sql = 'SELECT u.rowid FROM '.MAIN_DB_PREFIX.'user AS u'
	.' WHERE u.rowid = '.$id
	.' AND u.statut = 1 AND u.fk_soc IS NULL'
	.' AND u.entity IN ('.getEntity('user').')';
$result = $db->query($sql);
if (!$result || !$db->fetch_object($result)) {
	http_response_code(403);
	echo 'Unauthorized Access !';
	exit;
}

header('Content-type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename=Calendar-'.$id.'-'.$type.'.ics');

// Use a real Dolibarr User object. Replacing the bootstrap's global $user with
// stdClass used to break core logging and permission helpers (hasRight()).
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
$calendarUser = new User($db);
if ($calendarUser->fetch($id) <= 0) {
	http_response_code(403);
	echo 'Unauthorized Access !';
	exit;
}
$calendarUser->rights = new stdClass();
$calendarUser->rights->agenda = new stdClass();
$calendarUser->rights->agenda->myactions = new stdClass();
$calendarUser->rights->agenda->allactions = new stdClass();
$calendarUser->rights->societe = new stdClass();
$calendarUser->rights->societe->client = new stdClass();
$calendarUser->rights->agenda->myactions->read = true;
$calendarUser->rights->agenda->allactions->read = true;
$calendarUser->rights->societe->client->voir = false;

$cdavLib = new CdavLib($calendarUser, $db, $langs);

// Format them as one valid VCALENDAR document. getFullCalendarObjects()
// returns one complete VCALENDAR per object; concatenating those documents
// used to create invalid nested calendars.
$arrEvents = $cdavLib->getFullCalendarObjects($id, true);
$calendar = new \Sabre\VObject\Component\VCalendar();
$calendar->PRODID = '-//Dolibarr CDav//FR';
$timezones = array();
foreach($arrEvents as $event)
{
	try {
		$sourceCalendar = \Sabre\VObject\Reader::read($event['calendardata']);
	} catch (\Throwable $e) {
		dol_syslog('cdav/ics.php: skipped invalid calendar object: '.$e->getMessage(), LOG_ERR);
		continue;
	}
	foreach ($sourceCalendar->children() as $component) {
		if ($component->name === 'VEVENT' || $component->name === 'VTODO') {
			$component = clone $component;
			if ($type === 'nolabel') {
				$component->SUMMARY = $langs->transnoentitiesnoconv('Busy');
				$component->DESCRIPTION = '.';
				$component->LOCATION = '';
			}
			$calendar->add($component);
		} elseif ($component->name === 'VTIMEZONE') {
			$timezoneId = isset($component->TZID) ? (string) $component->TZID : md5($component->serialize());
			if (!isset($timezones[$timezoneId])) {
				$calendar->add(clone $component);
				$timezones[$timezoneId] = true;
			}
		}
	}
}
echo $calendar->serialize();
