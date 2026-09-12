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
 *  \file          htdocs/cdav/admin/setup.php
 *  \ingroup   	cdav
 *  \brief        Setup page  of module cdav
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

dol_include_once("/cdav/lib/cdav.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.formadmin.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.form.class.php");
require_once(DOL_DOCUMENT_ROOT."/core/class/html.formother.class.php");

$langs->load("admin");
$langs->load("other");
$langs->load("cdav@cdav");

// Security check
if (! $user->admin || !empty($user->design)) accessforbidden();

$action = GETPOST('action', 'alpha');

$taskcontact_types=array();
$sql = 'SELECT rowid, libelle FROM '.MAIN_DB_PREFIX.'c_type_contact WHERE element="project_task" AND source="internal" AND active=1';
$result = $db->query($sql);
if ($result!==false)
{
	while(($row=$db->fetch_object($result))!==null)
		$taskcontact_types[$row->rowid] = $row->libelle;
}
$projcontact_types=array();
$sql = 'SELECT rowid, libelle FROM '.MAIN_DB_PREFIX.'c_type_contact WHERE element="project" AND source="internal" AND active=1';
$result = $db->query($sql);
if ($result!==false)
{
	while(($row=$db->fetch_object($result))!==null)
		$projcontact_types[$row->rowid] = $row->libelle;
}
$intervcontact_types=array();
$sql = 'SELECT rowid, libelle FROM '.MAIN_DB_PREFIX.'c_type_contact WHERE element="fichinter" AND source="internal" AND active=1';
$result = $db->query($sql);
if ($result!==false)
{
	while(($row=$db->fetch_object($result))!==null)
		$intervcontact_types[$row->rowid] = $row->libelle;
}

$tasksync_method=array(
	'0' => $langs->trans("CDavNotSynchronized"),
	'1' => $langs->trans("CDavSyncAsCalendarEventsOnly"),
	'2' => $langs->trans("CDavSyncAsTodoTasksOnly"),
	'3' => $langs->trans("CDavSyncAsCalendarEventsAndTodoTasks"),
);

$intervsync_method=array(
	'0' => $langs->trans("CDavNotSynchronized"),
	'1' => $langs->trans("CDavSyncAsCalendarEvents"),
);

$thirdsync_method=array(
	'0' => $langs->trans("CDavNotSynchronized"),
	'1' => $langs->trans("CDavOnlyThirdPartiesWithoutContact"),
	'2' => $langs->trans("CDavAllThirdParties"),
);

$form = new Form($db);
/*
 * Actions
 */

if ($action == 'setvalue') {
	// save the setting

	$valCDAV_URI_KEY = substr(GETPOST('CDAV_URI_KEY', 'alphanohtml'),0,8);
	if($valCDAV_URI_KEY=='')
		$valCDAV_URI_KEY = substr(md5(time()),0,8);

	dolibarr_set_const(
									$db, "CDAV_URI_KEY",
									$valCDAV_URI_KEY, 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_CONTACT_TAG",
									GETPOST('CDAV_CONTACT_TAG', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_THIRD_SYNC",
									GETPOST('CDAV_THIRD_SYNC', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_MEMBER_SYNC",
									GETPOST('CDAV_MEMBER_SYNC', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_SYNC_PAST",
									GETPOST('CDAV_SYNC_PAST', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_SYNC_FUTURE",
									GETPOST('CDAV_SYNC_FUTURE', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_SYNC",
									GETPOST('CDAV_TASK_SYNC', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_INTERV_SYNC",
									GETPOST('CDAV_INTERV_SYNC', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_INTERV_USER_ROLE",
									GETPOST('CDAV_INTERV_USER_ROLE', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_USER_ROLE",
									GETPOST('CDAV_TASK_USER_ROLE', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK",
									GETPOST('CDAV_GENTASK', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_INI1",
									GETPOST('CDAV_GENTASK_INI1', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_INI2",
									GETPOST('CDAV_GENTASK_INI2', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_INI3",
									GETPOST('CDAV_GENTASK_INI3', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_END1",
									GETPOST('CDAV_GENTASK_END1', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_END2",
									GETPOST('CDAV_GENTASK_END2', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_END3",
									GETPOST('CDAV_GENTASK_END3', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_PROJ_USER_ROLE",
									GETPOST('CDAV_PROJ_USER_ROLE', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_GENTASK_SERVICE_TAG",
									GETPOST('CDAV_GENTASK_SERVICE_TAG', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_EXTRAFIELD_DURATION",
									GETPOST('CDAV_EXTRAFIELD_DURATION', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_HOUR_INI",
									GETPOST('CDAV_TASK_HOUR_INI', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_TASK_HOUR_END",
									GETPOST('CDAV_TASK_HOUR_END', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_QRCODE_DAVX5_ENABLED",
									GETPOST('CDAV_QRCODE_DAVX5_ENABLED', 'alphanohtml'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_ALLOW_INSECURE_HTTP",
									GETPOSTINT('CDAV_ALLOW_INSECURE_HTTP'), 'chaine', 0, '', $conf->entity
	);
	dolibarr_set_const(
									$db, "CDAV_MAX_REQUEST_MB",
									max(1, min(64, GETPOSTINT('CDAV_MAX_REQUEST_MB'))), 'chaine', 0, '', $conf->entity
	);
	$trustedProxyIps = array();
	foreach (preg_split('/[\s,;]+/', GETPOST('CDAV_TRUSTED_PROXY_IPS', 'alphanohtml'), -1, PREG_SPLIT_NO_EMPTY) as $proxyIp) {
		if (filter_var($proxyIp, FILTER_VALIDATE_IP)) {
			$trustedProxyIps[$proxyIp] = true;
		}
	}
	dolibarr_set_const(
									$db, "CDAV_TRUSTED_PROXY_IPS",
									implode(',', array_keys($trustedProxyIps)), 'chaine', 0, '', $conf->entity
	);
	foreach (array('CDAV_NATIVE_REMINDERS', 'CDAV_DELEGATION', 'CDAV_SCHEDULING', 'CDAV_MANAGED_ATTACHMENTS') as $booleanSetting) {
		dolibarr_set_const($db, $booleanSetting, GETPOSTINT($booleanSetting) ? 1 : 0, 'chaine', 0, '', $conf->entity);
	}
	$boundedSettings = array(
		'CDAV_SYNC_RETENTION_DAYS' => array(7, 730),
		'CDAV_SCHEDULING_RETENTION_DAYS' => array(1, 90),
		'CDAV_MANAGED_ATTACHMENT_MAX_MB' => array(1, 64),
		'CDAV_MANAGED_ATTACHMENT_MAX_COUNT' => array(1, 50),
		'CDAV_MANAGED_ATTACHMENT_QUOTA_MB' => array(1, 4096),
	);
	foreach ($boundedSettings as $setting => $bounds) {
		dolibarr_set_const($db, $setting, max($bounds[0], min($bounds[1], GETPOSTINT($setting))), 'chaine', 0, '', $conf->entity);
	}


	$mesg = "<font class='ok'>".$langs->trans("SetupSaved")."</font>";
}

/*
 * View
 */

$page_name = $langs->trans("CDavSetup") . " - " . $langs->trans("CDavGeneralSettings");
llxHeader('', $page_name);

$linkback='<a href="'.DOL_URL_ROOT.'/admin/modules.php">'.$langs->trans("BackToModuleList").'</a>';
print_fiche_titre($page_name, $linkback, 'title_setup');

$CDAV_URI_KEY=substr(getDolGlobalString('CDAV_URI_KEY'),0,8);
$CDAV_CONTACT_TAG=getDolGlobalInt('CDAV_CONTACT_TAG');
$CDAV_THIRD_SYNC=getDolGlobalInt('CDAV_THIRD_SYNC');
$CDAV_MEMBER_SYNC=getDolGlobalInt('CDAV_MEMBER_SYNC');
$CDAV_SYNC_PAST=getDolGlobalInt('CDAV_SYNC_PAST');
$CDAV_SYNC_FUTURE=getDolGlobalInt('CDAV_SYNC_FUTURE');
$CDAV_TASK_SYNC=getDolGlobalInt('CDAV_TASK_SYNC');
$CDAV_INTERV_SYNC=getDolGlobalInt('CDAV_INTERV_SYNC');
$CDAV_INTERV_USER_ROLE=getDolGlobalInt('CDAV_INTERV_USER_ROLE');
$CDAV_TASK_USER_ROLE=getDolGlobalInt('CDAV_TASK_USER_ROLE');
$CDAV_GENTASK=getDolGlobalInt('CDAV_GENTASK');
$CDAV_GENTASK_INI1=getDolGlobalInt('CDAV_GENTASK_INI1');
$CDAV_GENTASK_INI2=getDolGlobalInt('CDAV_GENTASK_INI2');
$CDAV_GENTASK_INI3=getDolGlobalInt('CDAV_GENTASK_INI3');
$CDAV_GENTASK_END1=getDolGlobalInt('CDAV_GENTASK_END1');
$CDAV_GENTASK_END2=getDolGlobalInt('CDAV_GENTASK_END2');
$CDAV_GENTASK_END3=getDolGlobalInt('CDAV_GENTASK_END3');
$CDAV_PROJ_USER_ROLE=getDolGlobalInt('CDAV_PROJ_USER_ROLE');
$CDAV_GENTASK_SERVICE_TAG=getDolGlobalInt('CDAV_GENTASK_SERVICE_TAG');
$CDAV_EXTRAFIELD_DURATION=getDolGlobalString('CDAV_EXTRAFIELD_DURATION');
$CDAV_TASK_HOUR_INI=getDolGlobalInt('CDAV_TASK_HOUR_INI', 8);
$CDAV_TASK_HOUR_END=getDolGlobalInt('CDAV_TASK_HOUR_END', 17);
$CDAV_QRCODE_DAVX5_ENABLED=getDolGlobalInt('CDAV_QRCODE_DAVX5_ENABLED');
$CDAV_ALLOW_INSECURE_HTTP=getDolGlobalInt('CDAV_ALLOW_INSECURE_HTTP');
$CDAV_MAX_REQUEST_MB=getDolGlobalInt('CDAV_MAX_REQUEST_MB', 16);
$CDAV_TRUSTED_PROXY_IPS=getDolGlobalString('CDAV_TRUSTED_PROXY_IPS');
$CDAV_SYNC_RETENTION_DAYS=getDolGlobalInt('CDAV_SYNC_RETENTION_DAYS', 180);
$CDAV_NATIVE_REMINDERS=getDolGlobalInt('CDAV_NATIVE_REMINDERS');
$CDAV_DELEGATION=getDolGlobalInt('CDAV_DELEGATION');
$CDAV_SCHEDULING=getDolGlobalInt('CDAV_SCHEDULING');
$CDAV_SCHEDULING_RETENTION_DAYS=getDolGlobalInt('CDAV_SCHEDULING_RETENTION_DAYS', 30);
$CDAV_MANAGED_ATTACHMENTS=getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS');
$CDAV_MANAGED_ATTACHMENT_MAX_MB=getDolGlobalInt('CDAV_MANAGED_ATTACHMENT_MAX_MB', 8);
$CDAV_MANAGED_ATTACHMENT_MAX_COUNT=getDolGlobalInt('CDAV_MANAGED_ATTACHMENT_MAX_COUNT', 10);
$CDAV_MANAGED_ATTACHMENT_QUOTA_MB=getDolGlobalInt('CDAV_MANAGED_ATTACHMENT_QUOTA_MB', 256);


dol_fiche_head('', 'setup', $langs->trans("CDav"), 0, "cdav@cdav");

print_titre($langs->trans("CDavSettingValues"));
print '<br>';
print '<form method="post" action="setup.php">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setvalue">';
print '<table class="noborder" >';
print '<tr class="liste_titre">';
print '<td width="50%" align=left>'.$langs->trans("Description").'</td>';
print '<td align=left>'.$langs->trans("Value").'</td>';
print '</tr>'."\n";

print '<tr >';
print '<td align=left><strong>'.$langs->trans("CDavTrustedProxyIps").'</strong><br/>'.$langs->trans("CDavTrustedProxyIpsHelp").'</td>';
print '<td align=left><input size="48" type="text" class="flat" name="CDAV_TRUSTED_PROXY_IPS" value="'.dol_escape_htmltag($CDAV_TRUSTED_PROXY_IPS).'" placeholder="192.0.2.10,2001:db8::10"></td>';
print '</tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavSyncToken").'</strong><br/>'.$langs->trans("CDavSyncTokenHelp").'</td>';
print '<td  align=left>';
print '<input size="8" type="text" class="flat" name="CDAV_URI_KEY" value="'.htmlentities($CDAV_URI_KEY).'">';
print '</td></tr>'."\n";

print '<tr >';
print '<td align=left><strong>'.$langs->trans("CDavRequireHttps").'</strong><br/>'.$langs->trans("CDavRequireHttpsHelp").'</td>';
print '<td align=left>'.$form->selectyesno('CDAV_ALLOW_INSECURE_HTTP', $CDAV_ALLOW_INSECURE_HTTP, 1).'</td>';
print '</tr>'."\n";

print '<tr >';
print '<td align=left><strong>'.$langs->trans("CDavMaxRequestSize").'</strong><br/>'.$langs->trans("CDavMaxRequestSizeHelp").'</td>';
print '<td align=left><input size="6" type="number" min="1" max="64" class="flat" name="CDAV_MAX_REQUEST_MB" value="'.((int) $CDAV_MAX_REQUEST_MB).'"> MiB</td>';
print '</tr>'."\n";

print '<tr class="liste_titre"><td align="center" colspan="2">'.$langs->trans('CDavAdvancedDavFeatures').'</td></tr>';
$yesNoSettings = array(
	'CDAV_NATIVE_REMINDERS' => array('CDavNativeReminders', 'CDavNativeRemindersHelp', $CDAV_NATIVE_REMINDERS),
	'CDAV_DELEGATION' => array('CDavDelegation', 'CDavDelegationHelp', $CDAV_DELEGATION),
	'CDAV_SCHEDULING' => array('CDavScheduling', 'CDavSchedulingHelp', $CDAV_SCHEDULING),
	'CDAV_MANAGED_ATTACHMENTS' => array('CDavManagedAttachments', 'CDavManagedAttachmentsHelp', $CDAV_MANAGED_ATTACHMENTS),
);
foreach ($yesNoSettings as $name => $setting) {
	print '<tr><td><strong>'.$langs->trans($setting[0]).'</strong><br>'.$langs->trans($setting[1]).'</td>';
	print '<td>'.$form->selectyesno($name, $setting[2], 1).'</td></tr>';
}
$numberSettings = array(
	'CDAV_SYNC_RETENTION_DAYS' => array('CDavSyncRetention', 'CDavSyncRetentionHelp', $CDAV_SYNC_RETENTION_DAYS, 7, 730, $langs->trans('Days')),
	'CDAV_SCHEDULING_RETENTION_DAYS' => array('CDavSchedulingRetention', 'CDavSchedulingRetentionHelp', $CDAV_SCHEDULING_RETENTION_DAYS, 1, 90, $langs->trans('Days')),
	'CDAV_MANAGED_ATTACHMENT_MAX_MB' => array('CDavManagedAttachmentMaxSize', 'CDavManagedAttachmentMaxSizeHelp', $CDAV_MANAGED_ATTACHMENT_MAX_MB, 1, 64, 'MiB'),
	'CDAV_MANAGED_ATTACHMENT_MAX_COUNT' => array('CDavManagedAttachmentMaxCount', 'CDavManagedAttachmentMaxCountHelp', $CDAV_MANAGED_ATTACHMENT_MAX_COUNT, 1, 50, ''),
	'CDAV_MANAGED_ATTACHMENT_QUOTA_MB' => array('CDavManagedAttachmentQuota', 'CDavManagedAttachmentQuotaHelp', $CDAV_MANAGED_ATTACHMENT_QUOTA_MB, 1, 4096, 'MiB'),
);
foreach ($numberSettings as $name => $setting) {
	print '<tr><td><strong>'.$langs->trans($setting[0]).'</strong><br>'.$langs->trans($setting[1]).'</td>';
	print '<td><input type="number" class="flat" name="'.$name.'" min="'.$setting[3].'" max="'.$setting[4].'" value="'.((int) $setting[2]).'"> '.$setting[5].'</td></tr>';
}

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavContactsFilter").'</strong><br/>'.$langs->trans("CDavContactsFilterHelp").'</td>';
print '<td  align=left>';
print $form->select_all_categories("contact", $CDAV_CONTACT_TAG, 'CDAV_CONTACT_TAG', 0);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavEnableThirdPartiesSync").'</strong><br/>'.$langs->trans("CDavThirdPartiesSyncHelp").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_THIRD_SYNC', $thirdsync_method, $CDAV_THIRD_SYNC);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("EnableMembersSync").'</strong><br/>'.$langs->trans("GenerateAddressbookForMembership").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_MEMBER_SYNC', $CDAV_MEMBER_SYNC, 1);
print '</td></tr>'."\n";


print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavEnableInterventionsSync").'</strong><br/>'.$langs->trans("CDavInterventionsSyncHelp").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_INTERV_SYNC', $intervsync_method, $CDAV_INTERV_SYNC);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavInterventionUserRole").'</strong><br/>'.$langs->trans("CDavInterventionUserRoleHelp").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_INTERV_USER_ROLE', $intervcontact_types, $CDAV_INTERV_USER_ROLE);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavSyncPeriod").'</strong><br/>'.$langs->trans("CDavSyncPeriodHelp").'</td>';
print '<td  align=left>';
print $langs->trans("CDavPast").' : <input size="4" type="text" class="flat" name="CDAV_SYNC_PAST" value="'.htmlentities($CDAV_SYNC_PAST).'"> '.$langs->trans("Days");
print '<br />';
print $langs->trans("CDavFuture").' : <input size="4" type="text" class="flat" name="CDAV_SYNC_FUTURE" value="'.htmlentities($CDAV_SYNC_FUTURE).'"> '.$langs->trans("Days");
print '</td></tr>'."\n";

print '<tr class="liste_titre">';
print '<td align="center" colspan="2">'.$langs->trans("CDavProjectTaskSynchronization").'</td>';
print '</tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavEnableProjectTaskSync").'</strong><br/>'.$langs->trans("CDavProjectTaskSyncHelp").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_TASK_SYNC', $tasksync_method, $CDAV_TASK_SYNC);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavGenerateTasksFromDocuments").'</strong><br/>'.$langs->trans("CDavGenerateTasksFromDocumentsHelp").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_GENTASK', $CDAV_GENTASK, 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavGenerateInitialTasks").'</strong><br/>'.$langs->trans("CDavGenerateInitialTasksHelp").'</td>';
print '<td  align=left>';
print $form->select_produits($CDAV_GENTASK_INI1, 'CDAV_GENTASK_INI1', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_INI2, 'CDAV_GENTASK_INI2', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_INI3, 'CDAV_GENTASK_INI3', 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavGenerateFinalTasks").'</strong><br/>'.$langs->trans("CDavGenerateFinalTasksHelp").'</td>';
print '<td  align=left>';
print $form->select_produits($CDAV_GENTASK_END1, 'CDAV_GENTASK_END1', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_END2, 'CDAV_GENTASK_END2', 1);
print '<br />';
print $form->select_produits($CDAV_GENTASK_END3, 'CDAV_GENTASK_END3', 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavProjectUserRole").'</strong><br/>'.$langs->trans("CDavProjectUserRoleHelp").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_PROJ_USER_ROLE', $projcontact_types, $CDAV_PROJ_USER_ROLE);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavProjectTaskUserRole").'</strong><br/>'.$langs->trans("CDavProjectTaskUserRoleHelp").'</td>';
print '<td  align=left>';
print $form->selectarray('CDAV_TASK_USER_ROLE', $taskcontact_types, $CDAV_TASK_USER_ROLE);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavProjectTaskWorkingHours").'</strong><br/>'.$langs->trans("CDavProjectTaskWorkingHoursHelp").'</td>';
print '<td  align=left>';
print $langs->trans("CDavStartsAt").' : <input size="4" type="text" class="flat" name="CDAV_TASK_HOUR_INI" value="'.htmlentities($CDAV_TASK_HOUR_INI).'"> '.$langs->trans("Hours");
print '<br />';
print $langs->trans("CDavEndsAt").' : <input size="4" type="text" class="flat" name="CDAV_TASK_HOUR_END" value="'.htmlentities($CDAV_TASK_HOUR_END).'"> '.$langs->trans("Hours");
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavServicesFilter").'</strong><br/>'.$langs->trans("CDavServicesFilterHelp").'</td>';
print '<td  align=left>';
print $form->select_all_categories("product", $CDAV_GENTASK_SERVICE_TAG, 'CDAV_GENTASK_SERVICE_TAG', 0);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("CDavServiceDurationFromDocuments").'</strong><br/>'.$langs->trans("CDavServiceDurationFromDocumentsHelp").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_EXTRAFIELD_DURATION', $CDAV_EXTRAFIELD_DURATION, 1);
print '</td></tr>'."\n";

print '<tr >';
print '<td  align=left><strong>'.$langs->trans("ActivateDavX5autoURL").'</strong><br/>'.$langs->trans("ActivateDavX5autoURLTooltip").'</td>';
print '<td  align=left>';
print $form->selectyesno('CDAV_QRCODE_DAVX5_ENABLED', $CDAV_QRCODE_DAVX5_ENABLED, 1);
print '</td></tr>'."\n";

// Boutons d'action
print '<tr ><td>';
print '<div class="tabsAction">';
print '<input type="submit" class="button" value="'.$langs->trans("Modify").'">';
print '</div>';
print '</td></tr>'."\n";
print '</table>';
print '</form>';
// Show errors
print "<br>";

if(!empty($object)) { // Fix Warning: Attempt to read property "error" on null : Is $object really used on this page?
	dol_htmloutput_errors($object->error, $object->errors);
	// Show messages
	dol_htmloutput_mesg($object->mesg, '', 'ok');
}

// Footer
llxFooter();
$db->close();
