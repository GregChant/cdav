<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

$root = (string) getenv('DOLIBARR_ROOT');
if (!is_dir($root) || !getenv('CDAV_CI_DB_PASSWORD')) exit(2);
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
define('NOCSRFCHECK', 1);
$_SERVER['SERVER_NAME'] = $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = parse_url(getenv('CDAV_CI_URL'), PHP_URL_HOST).':'.parse_url(getenv('CDAV_CI_URL'), PHP_URL_PORT);
$_SERVER['QUERY_STRING'] = '';
require $root.'/main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

if ($dolibarr_main_db_name !== 'cdav_calendar_test' || $user->fetch(0, 'ciadmin') <= 0 || !$user->isAdmin()) {
	throw new RuntimeException('The disposable calendar test installation did not create its administrator');
}
$user->loadRights();
foreach (array('modSociete', 'modAdherent', 'modProjet', 'modAgenda', 'modCDav') as $module) {
	$result = activateModule($module);
	if (!empty($result['errors'])) throw new RuntimeException('Unable to enable '.$module.': '.implode(', ', $result['errors']));
}
