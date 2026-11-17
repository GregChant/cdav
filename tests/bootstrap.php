<?php

// Developer helpers are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Turn active PHP diagnostics into failures, allowing native Sabre deprecations.
 *
 * @param string $dolibarrRoot Target Dolibarr htdocs directory
 * @return void
 */
function cdavTestStrictErrors($dolibarrRoot)
{
	$vendorDirectory = realpath($dolibarrRoot.'/includes/sabre');
	if ($vendorDirectory === false) {
		throw new RuntimeException('Set DOLIBARR_ROOT to a Dolibarr htdocs directory.');
	}
	$vendorRoot = $vendorDirectory.DIRECTORY_SEPARATOR;
	$vendorDeprecations = 0;
	error_reporting(E_ALL);
	set_error_handler(static function ($severity, $message, $file, $line) use ($vendorRoot, &$vendorDeprecations) {
		// Keep intentional probes such as @getimagesize responsible for their errors.
		if (!(error_reporting() & $severity)) {
			return false;
		}
		if ($severity === E_DEPRECATED && strpos($file, $vendorRoot) === 0) {
			$vendorDeprecations++;
			return true;
		}
		throw new ErrorException($message, 0, $severity, $file, $line);
	});
	register_shutdown_function(static function () use (&$vendorDeprecations) {
		fwrite(STDOUT, '[INFO] Bundled Sabre vendor deprecations: '.$vendorDeprecations." (application warnings remain fatal).\n");
	});
}
