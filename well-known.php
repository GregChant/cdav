<?php

// Target for web-server rewrites of /.well-known/caldav and carddav.
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

$loaded = 0;
$scriptFilename = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
$bootstraps = array(
	(string) ($_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? '').'/main.inc.php',
	(string) ($_SERVER['DOCUMENT_ROOT'] ?? '').'/main.inc.php',
	$scriptFilename !== '' ? dirname($scriptFilename, 3).'/main.inc.php' : '',
	__DIR__.'/../../main.inc.php',
	__DIR__.'/../../../main.inc.php',
);
foreach (array_unique($bootstraps) as $bootstrap) {
	if (!$loaded && is_file($bootstrap)) $loaded = @include $bootstrap;
}
if (!$loaded || !defined('DOL_DOCUMENT_ROOT')) {
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo "Dolibarr bootstrap unavailable\n";
	exit;
}

header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Location: '.rtrim(dol_buildpath('/cdav/server.php', 2), '/').'/', true, 301);
exit;
