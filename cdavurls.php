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

/**
 *   	\file       cdav/cdavurls.php
 *		\ingroup    cdav
 *		\brief      This page displays urls for carddav and caldav sync
 *
 */

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
require_once DOL_DOCUMENT_ROOT.'/core/lib/barcode.lib.php'; // This is to include def like $genbarcode_loc and $font_loc

function base64url_encode($data) {
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Build a tamper-proof ICS subscription token. */
function cdav_create_ics_token($userId, $type) {
	$payload = ((int) $userId).'+ø+'.(string) $type;
	return base64url_encode($payload.'.'.hash_hmac('sha256', $payload, CDAV_URI_KEY, true));
}

// Load traductions files requiredby by page
$langs->load("cdav");


// define CDAV_URI_KEY if not
if(!defined('CDAV_URI_KEY'))
{
	define('CDAV_URI_KEY', getDolGlobalString('CDAV_URI_KEY', substr(md5($_SERVER['HTTP_HOST'] ?? 'localhost'), 0, 8)));
}

// Get parameters
$id			= GETPOST('id','int');
$action		= GETPOST('action','alpha');
$backtopage = GETPOST('backtopage');
$type		= GETPOST('type','alpha');

// Protection if external user
if (!empty($user->societe_id) || !empty($user->socid)) // external user
{
	accessforbidden();
}

/***************************************************
* VIEW
*
* Put here all code to build page
****************************************************/

llxHeader('',$langs->trans($type.'url'),'');

echo '<H2>'.$langs->trans($type.'url').'</H2>';

if(getDolGlobalInt('CDAV_QRCODE_DAVX5_ENABLED')) {
	echo '<h3>'.$langs->trans('URLForDavX5').'</h3>';
	echo '<p>'.$langs->trans('URLForDavX5Tooltip').'</p>';

	require_once DOL_DOCUMENT_ROOT.'/core/modules/barcode/doc/tcpdfbarcode.modules.php';
	$qrmodule = new modTcpdfbarcode();
	$tcpdfEncoding = $qrmodule->getTcpdfEncodingType('QRCODE');
	require_once TCPDF_PATH.'tcpdf_barcodes_2d.php';
	$davx = "davx5://" . $user->login . ":@";
	$uri = str_replace(["http://", "https://"],[$davx, $davx],dol_buildpath('cdav', 2));
	$barcodeobj = new TCPDF2DBarcode($uri, $tcpdfEncoding);
	$qrdata = $barcodeobj->getBarcodePngData();
	print "<img src='data:image/png;base64," . base64_encode($qrdata) . "' /> <br />";
}

if($type=='CardDAV')
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	$serverUrl = dol_buildpath('cdav/server.php', 2);
	$principalUrl = $serverUrl.'/principals/'.rawurlencode($user->login).'/';
	echo '<PRE>';
	echo dol_escape_htmltag(dol_buildpath('cdav', 2))."\n";
	echo dol_escape_htmltag($serverUrl)."\n";
	echo dol_escape_htmltag($principalUrl);
	echo '</PRE>';

	echo '<h3>'.$langs->trans('URLforCardDAV', 2).'</h3>';
	echo '<PRE>'.dol_escape_htmltag($serverUrl.'/addressbooks/'.rawurlencode($user->login).'/default/').'</PRE>';
}
elseif($type=='CalDAV')
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	$serverUrl = dol_buildpath('cdav/server.php', 2);
	$principalUrl = $serverUrl.'/principals/'.rawurlencode($user->login).'/';
	echo '<PRE>';
	echo dol_escape_htmltag(dol_buildpath('cdav', 2))."\n";
	echo dol_escape_htmltag($serverUrl)."\n";
	echo dol_escape_htmltag($principalUrl);
	echo '</PRE>';

	echo '<h3>'.$langs->trans('URLforCalDAV').'</h3>';

	if ($user->hasRight('agenda', 'allactions', 'read'))
	{
		$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname
			FROM '.MAIN_DB_PREFIX.'user u WHERE u.fk_soc IS NULL
			AND u.statut = 1 AND u.entity IN ('.getEntity('user').')
			ORDER BY u.login';
		$result = $db->query($sql);
		while($row = $db->fetch_array($result))
		{
			if($row['rowid'] == $user->id)
				echo '<strong>';
			echo dol_escape_htmltag(trim($row['firstname'].' '.$row['lastname'])).' :';
			$calendarUrl = $serverUrl.'/calendars/'.rawurlencode($user->login).'/'.$row['rowid'].'-cal-'.rawurlencode($row['login']);
			echo '<PRE>'.dol_escape_htmltag($calendarUrl).'</PRE><br/>';
			if($row['rowid'] == $user->id)
				echo '</strong>';
		}
	}
	else
	{
		$calendarUrl = $serverUrl.'/calendars/'.rawurlencode($user->login).'/'.$user->id.'-cal-'.rawurlencode($user->login);
		echo '<PRE>'.dol_escape_htmltag($calendarUrl).'</PRE>';
	}

}
elseif($type=='ICS')
{

	echo '<h3>'.$langs->trans('URLforICS').'</h3>';

	if ($user->hasRight('agenda', 'allactions', 'read'))
	{
		$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname
			FROM '.MAIN_DB_PREFIX.'user u WHERE u.fk_soc IS NULL
			AND u.statut = 1 AND u.entity IN ('.getEntity('user').')
			ORDER BY u.login';
		$result = $db->query($sql);
		while($row = $db->fetch_array($result))
		{
			echo '<h4>'.dol_escape_htmltag(trim($row['firstname'].' '.$row['lastname'])).' :</h4>';

			$fullUrl = dol_buildpath('cdav/ics.php', 2).'?token='.cdav_create_ics_token($row['rowid'], 'full');
			$busyUrl = dol_buildpath('cdav/ics.php', 2).'?token='.cdav_create_ics_token($row['rowid'], 'nolabel');
			echo '<PRE>'.dol_escape_htmltag($langs->trans('Full')." :\n".$fullUrl."\n\n");
			echo dol_escape_htmltag($langs->trans('NoLabel')." :\n".$busyUrl).'</PRE><br/>';

		}
	}
	else
	{
		$fullUrl = dol_buildpath('cdav/ics.php', 2).'?token='.cdav_create_ics_token($user->id, 'full');
		$busyUrl = dol_buildpath('cdav/ics.php', 2).'?token='.cdav_create_ics_token($user->id, 'nolabel');
		echo '<PRE>'.dol_escape_htmltag($langs->trans('Full')." :\n".$fullUrl."\n\n");
		echo dol_escape_htmltag($langs->trans('NoLabel')." :\n".$busyUrl).'</PRE><br/>';
	}

}
else
{
	echo '<h3>'.$langs->trans('URLGeneric').'</h3>';
	echo '<PRE>'.dol_buildpath('cdav', 2).'</PRE>';
}

// End of page
llxFooter();
$db->close();
