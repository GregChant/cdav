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

// Keep the native bootstrap at global scope for $db, $conf, $langs and $user.
$cdavBootstrapFile = __FILE__;
require __DIR__.'/lib/cdav_bootstrap.inc.php';

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
require_once __DIR__.'/class/CDavFreeBusyFilter.php';

// Load traductions files requiredby by page
$langs->load("cdav@cdav");


//Get all event
require_once __DIR__.'/lib/cdav.lib.php';


require_once __DIR__.'/lib/cdav_config.lib.php';
cdav_initialize_sync_constants();

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
$calendarUser->loadRights();
// The signed subscription grants read access to this specific calendar. Keep
// the real User object and its complete Dolibarr rights tree, then constrain
// company visibility to the historical private-feed behavior.
$calendarUser->rights->agenda->myactions->read = true;
$calendarUser->rights->agenda->allactions->read = true;
$calendarUser->rights->societe->client->voir = false;

// The subscription is generated without an interactive session. Use the
// calendar owner's Dolibarr language instead of the bootstrap/default one.
$calendarLangs = $langs;
if (!empty($calendarUser->lang)) {
	$calendarLangs = new Translate('', $conf);
	$calendarLangs->setDefaultLang($calendarUser->lang);
	$calendarLangs->load("main");
	$calendarLangs->load("cdav@cdav");
}

$cdavLib = new CdavLib($calendarUser, $db, $calendarLangs);

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
			if ($type === 'nolabel') {
				$component = \Dolibarr\CDav\FreeBusyFilter::calendarComponent(
					$component, $calendarLangs->transnoentitiesnoconv('Busy'), (string) $id, CDAV_URI_KEY
				);
			} else {
				$component = clone $component;
			}
			$calendar->add($component);
		} elseif ($component->name === 'VTIMEZONE') {
			$timezoneId = isset($component->TZID) ? (string) $component->TZID : md5($component->serialize());
			if (!isset($timezones[$timezoneId])) {
				$calendar->add($type === 'nolabel'
					? \Dolibarr\CDav\FreeBusyFilter::timezoneComponent($component)
					: clone $component);
				$timezones[$timezoneId] = true;
			}
		}
	}
}
echo $calendar->serialize();
