<?php

/**
 * Load the native environment in the calling entry point's global scope.
 * The entry point supplies its own filename so symlink discovery uses its path.
 */
if (empty($cdavBootstrapFile) || !is_file($cdavBootstrapFile)) {
	http_response_code(404);
	exit;
}

$cdavBootstrapResult = 0;
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$cdavBootstrapResult = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}

$cdavBootstrapScript = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$cdavBootstrapReal = realpath($cdavBootstrapFile);
$cdavBootstrapScriptEnd = strlen($cdavBootstrapScript) - 1;
$cdavBootstrapRealEnd = strlen($cdavBootstrapReal) - 1;
while ($cdavBootstrapScriptEnd > 0 && $cdavBootstrapRealEnd > 0
	&& $cdavBootstrapScript[$cdavBootstrapScriptEnd] === $cdavBootstrapReal[$cdavBootstrapRealEnd]) {
	$cdavBootstrapScriptEnd--;
	$cdavBootstrapRealEnd--;
}

// Native module-builder discovery supports custom modules outside htdocs too.
$cdavBootstrapCandidates = array();
if ($cdavBootstrapScriptEnd > 0) {
	$cdavBootstrapPrefix = substr($cdavBootstrapScript, 0, $cdavBootstrapScriptEnd + 1);
	$cdavBootstrapCandidates[] = $cdavBootstrapPrefix.'/main.inc.php';
	$cdavBootstrapCandidates[] = dirname($cdavBootstrapPrefix).'/main.inc.php';
}
$cdavBootstrapCandidates = array_merge($cdavBootstrapCandidates, array('../main.inc.php', '../../main.inc.php', '../../../main.inc.php'));
foreach ($cdavBootstrapCandidates as $cdavBootstrapCandidate) {
	if (!$cdavBootstrapResult && file_exists($cdavBootstrapCandidate)) {
		$cdavBootstrapResult = @include $cdavBootstrapCandidate;
	}
}
if (!$cdavBootstrapResult) {
	die('Include of main fails');
}

unset($cdavBootstrapFile, $cdavBootstrapResult, $cdavBootstrapScript, $cdavBootstrapReal,
	$cdavBootstrapScriptEnd, $cdavBootstrapRealEnd, $cdavBootstrapPrefix, $cdavBootstrapCandidates, $cdavBootstrapCandidate);
