<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Native template regressions; no Dolibarr database or browser is required. */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) return false;
	throw new RuntimeException($message.' at '.$file.':'.$line);
});
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
if (!is_file($root.'/core/lib/functions.lib.php')) {
	fwrite(STDERR, "Set DOLIBARR_ROOT to a Dolibarr htdocs directory.\n");
	exit(2);
}
define('DOL_DOCUMENT_ROOT', $root);
$conf = (object) array('global' => new stdClass(),
	'file' => (object) array('dol_document_root' => array($root)), 'cache' => array());
require $root.'/core/lib/functions.lib.php';
require $root.'/core/class/translate.class.php';
$_SESSION = array('newtoken' => 'native-csrf-sentinel');

/**
 * Fail a UI regression with a useful message.
 *
 * @param bool $condition Expected condition
 * @param string $message Failure explanation
 * @return void
 */
function bookingUiAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

$renderCount = 0;
foreach (array('fr_FR', 'en_US') as $language) {
	$langs = new Translate($root, $conf);
	$langs->setDefaultLang($language);
	// Load native and module strings without bootstrapping a database session.
	foreach (array($root.'/langs/'.$language.'/main.lang', dirname(__DIR__).'/langs/'.$language.'/cdav.lang') as $file) {
		foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
			if (strpos($line, '=') === false || str_starts_with($line, '#')) continue;
			list($key, $value) = explode('=', $line, 2);
			$langs->tab_translate[$key] = $value;
		}
	}
	foreach (array('slots', 'empty', 'error', 'confirmed', 'malformed') as $scenario) {
		$bookingProfile = array('config' => array('timezone' => 'Europe/Zurich', 'duration' => 30, 'horizon' => 60));
		$bookingKey = str_repeat('a', 64);
		$bookingDate = '2026-11-20';
		$bookingRequest = 'request-sentinel';
		$bookingSlots = in_array($scenario, array('slots', 'error'), true)
			? array(array('start' => 1795179600, 'end' => 1795181400)) : array();
		$bookingResult = $scenario === 'confirmed'
			? array('reference' => 'public-ref', 'start' => 1795179600, 'end' => 1795181400) : null;
		$bookingMessage = '';
		$bookingError = $scenario === 'error' ? 'Please reload <script>alert(1)</script>' : '';
		$bookingValues = array('name' => 'Client " onfocus="alert(1)', 'email' => 'client@example.test', 'phone' => '0041000000000');
		if ($scenario === 'malformed') {
			$bookingProfile = null;
			$bookingDate = array('hostile');
			$bookingKey = array('hostile');
			$bookingValues = array('name' => array('hostile'), 'email' => array('hostile'));
		}
		ob_start();
		require dirname(__DIR__).'/tpl/booking.tpl.php';
		$html = ob_get_clean();
		$previous = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$dom->loadHTML($html);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		$xpath = new DOMXPath($dom);
		bookingUiAssert($xpath->query('//script')->length === 0, 'The public template must not execute injected scripts');
		bookingUiAssert($xpath->query('//*[@onfocus or @onclick]')->length === 0, 'Input must not create executable attributes');
		bookingUiAssert($xpath->query('//h1')->length === 1, 'The public page must have one main heading');
		bookingUiAssert(strpos($html, 'CDavBooking') === false, 'All public booking labels must be translated');
		foreach ($xpath->query('//input[not(@type="hidden")]') as $input) {
			bookingUiAssert($input->hasAttribute('id'), 'Visible fields must have an identifier');
			bookingUiAssert($xpath->query('//label[@for="'.$input->getAttribute('id').'"]')->length === 1,
				'Visible fields must have exactly one associated label');
		}
		if (in_array($scenario, array('slots', 'error'), true)) {
			bookingUiAssert($xpath->query('//form[@method="post"]')->length === 1, 'Available slots must have a confirmation form');
			foreach (array('key', 'token', 'request', 'date', 'start', 'name', 'email', 'phone', 'website') as $name) {
				bookingUiAssert($xpath->query('//form[@method="post"]//input[@name="'.$name.'"]')->length === 1,
					'The confirmation form must preserve '.$name);
			}
			bookingUiAssert($xpath->query('//input[@name="token"]')->item(0)->getAttribute('value') === 'native-csrf-sentinel',
				'The form must use the native session CSRF token');
		}
		if ($scenario === 'confirmed') {
			bookingUiAssert($xpath->query('//form[@method="post"]')->length === 0, 'Confirmation must not offer an accidental repeat submission');
			bookingUiAssert(strpos($html, 'public-ref') !== false, 'Confirmation must expose the public reference');
		}
		if ($scenario === 'error') {
			bookingUiAssert($xpath->query('//*[@role="alert" and @id="booking-error"]')->length === 1, 'Errors must be announced');
			foreach (array('name', 'email', 'phone', 'start') as $name) {
				bookingUiAssert($xpath->query('//input[@name="'.$name.'" and @aria-describedby="booking-error"]')->length === 1,
					'Errors must be associated with the '.$name.' field');
			}
		}
		$renderCount++;
	}
}
fwrite(STDOUT, 'CDav public template regressions passed: '.$renderCount." language/scenario renders.\n");
