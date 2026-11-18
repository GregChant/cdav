<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

// Each probe has its own global scope and constant table, like an HTTP request.
if (($argv[1] ?? '') === '--probe') {
	$probe = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
	error_reporting(E_ALL);
	set_error_handler(static function ($severity, $message, $file, $line) {
		if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
		return false;
	});
	if ($probe['kind'] === 'bootstrap') {
		$_SERVER = $probe['server'];
		chdir($probe['cwd']);
		define('NOLOGIN', 1);
		$cdavBootstrapFile = $probe['caller'];
		require dirname(__DIR__).'/lib/cdav_bootstrap.inc.php';
		echo json_encode(array($db->scope, $conf->scope, $langs->scope, $user->scope, NOLOGIN));
	} else {
		define('DOL_DOCUMENT_ROOT', $probe['root']);
		require DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
		$conf = (object) array('global' => (object) $probe['settings']);
		foreach ($probe['predefined'] as $name => $value) define($name, $value);
		$_SERVER['HTTP_HOST'] = 'sync.example.test';
		require dirname(__DIR__).'/lib/cdav_config.lib.php';
		cdav_initialize_sync_constants();
		cdav_initialize_sync_constants();
		$values = array();
		foreach ($probe['names'] as $name) $values[$name] = constant($name);
		echo json_encode($values);
	}
	exit;
}

/**
 * Run a separate request scope without invoking a shell or loading a database.
 * @param array $probe Deployment or configuration fixture
 * @return string Output from the entry point
 */
function syncBootstrapProbe(array $probe)
{
	$process = proc_open(array(PHP_BINARY, __FILE__, '--probe', json_encode($probe, JSON_THROW_ON_ERROR)),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($process)) throw new RuntimeException('Unable to start request scope');
	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ($status !== 0 || $errors !== '') throw new RuntimeException('Request probe failed: '.$errors);
	return $output;
}

/**
 * Remove only files owned by this test, including its external-module symlink.
 * @param string $path Owned temporary path
 * @return void
 */
function syncBootstrapCleanup($path)
{
	if (is_link($path) || !is_dir($path)) {
		unlink($path);
		return;
	}
	foreach (new DirectoryIterator($path) as $entry) {
		if (!$entry->isDot()) syncBootstrapCleanup($entry->getPathname());
	}
	rmdir($path);
}

$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
$temporary = sys_get_temp_dir().'/cdav-bootstrap-'.bin2hex(random_bytes(8));
mkdir($temporary, 0700);
try {
	$main = '<?php foreach (array("db", "conf", "langs", "user") as $name) $$name = (object) array("scope" => "native-global"); return 1;';
	foreach (array('htdocs/custom/cdav/admin', 'external/cdav/admin', 'empty') as $directory) mkdir($temporary.'/'.$directory, 0700, true);
	file_put_contents($temporary.'/htdocs/main.inc.php', $main);
	foreach (array('htdocs/custom/cdav/admin/setup.php', 'external/cdav/admin/setup.php') as $caller) touch($temporary.'/'.$caller);
	$fixtures = array(
		array('context-root', $temporary.'/empty', $temporary.'/external/cdav/admin/setup.php',
			array('CONTEXT_DOCUMENT_ROOT' => $temporary.'/htdocs')),
		array('custom-relative', $temporary.'/htdocs/custom/cdav/admin', $temporary.'/htdocs/custom/cdav/admin/setup.php', array()),
	);
	// The script path stays under htdocs while realpath points outside Dolibarr.
	mkdir($temporary.'/htdocs/modules', 0700);
	symlink($temporary.'/external/cdav', $temporary.'/htdocs/modules/cdav');
	$fixtures[] = array('external-module', $temporary.'/empty', $temporary.'/htdocs/modules/cdav/admin/setup.php',
		array('SCRIPT_FILENAME' => $temporary.'/htdocs/modules/cdav/admin/setup.php'));
	foreach ($fixtures as list($name, $cwd, $caller, $server)) {
		$output = syncBootstrapProbe(array('kind' => 'bootstrap', 'cwd' => $cwd, 'caller' => $caller, 'server' => $server));
		if (json_decode($output, true) !== array('native-global', 'native-global', 'native-global', 'native-global', 1)) {
			throw new RuntimeException('Global native environment or entry-point flags lost: '.$name);
		}
	}
	$missing = syncBootstrapProbe(array('kind' => 'bootstrap', 'cwd' => $temporary.'/empty',
		'caller' => $temporary.'/external/cdav/admin/setup.php', 'server' => array()));
	if ($missing !== 'Include of main fails') throw new RuntimeException('Missing bootstrap did not stop execution');

	$defaults = array('CDAV_CONTACT_TAG' => 0, 'CDAV_TASK_USER_ROLE' => 0, 'CDAV_SYNC_PAST' => 31,
		'CDAV_SYNC_FUTURE' => 365, 'CDAV_TASK_SYNC' => 0, 'CDAV_INTERV_SYNC' => 0, 'CDAV_INTERV_USER_ROLE' => 0,
		'CDAV_THIRD_SYNC' => 0, 'CDAV_MEMBER_SYNC' => 0, 'CDAV_URI_KEY' => substr(md5('sync.example.test'), 0, 8),
		'CDAV_ADDRESSBOOK_ID_SHIFT' => 100000);
	foreach (array(array(), array('CDAV_SYNC_PAST' => '15', 'CDAV_SYNC_FUTURE' => '180', 'CDAV_URI_KEY' => 'entity-key')) as $settings) {
		$predefined = array('CDAV_TASK_USER_ROLE' => 99);
		$actual = json_decode(syncBootstrapProbe(array('kind' => 'config', 'root' => $root,
			'settings' => $settings, 'predefined' => $predefined, 'names' => array_keys($defaults))), true, 512, JSON_THROW_ON_ERROR);
		$expected = array_replace($defaults, array_map(static fn($value) => is_numeric($value) ? (int) $value : $value, $settings), $predefined);
		if ($actual !== $expected) throw new RuntimeException('Native settings, defaults, precedence or idempotency changed');
	}
	echo "PASS sync bootstrap: native globals, entry-point flags, custom/external paths, failure boundary and entity settings.\n";
} finally {
	syncBootstrapCleanup($temporary);
}
