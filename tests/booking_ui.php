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
	foreach (array('slots', 'empty', 'error', 'confirmed', 'malformed', 'invalid-month', 'invalid-days', 'leap-month', 'full-week-month', 'current-day', 'six-month-horizon') as $scenario) {
		$bookingProfile = array('config' => array('timezone' => 'Europe/Zurich', 'duration' => 30, 'horizon' => 60));
		$bookingKey = str_repeat('a', 64);
		$bookingDate = '2026-11-20';
		unset($bookingLastDate);
		$bookingCalendar = array('month' => '2026-11', 'previous' => '2026-10', 'next' => '2026-12',
			'days' => array('2026-11-20' => 4, '2026-11-21' => 0, '2026-11-23' => 2));
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
		if ($scenario === 'empty') {
			$bookingCalendar['days'] = array();
			$bookingCalendar['previous'] = null;
		}
		if ($scenario === 'invalid-month') {
			$bookingCalendar['month'] = '2026-13';
		}
		if ($scenario === 'invalid-days') {
			$bookingCalendar['days'] = array('2026-11-20' => '<script>PRIVATE-SENTINEL</script>', '2026-11-21' => true,
				'2026-11-22' => array('PRIVATE-SENTINEL'), '2026-11-23' => '2', '2026-11-99' => 12);
			$bookingCalendar['previous'] = 'javascript:alert(1)';
			$bookingCalendar['next'] = array('PRIVATE-SENTINEL');
			$bookingKey = 'key" onmouseover="alert(1)';
		}
		if ($scenario === 'leap-month') {
			$bookingDate = '2028-02-29';
			$bookingCalendar = array('month' => '2028-02', 'previous' => '2028-01', 'next' => '2028-03',
				'days' => array('2028-02-29' => 3));
		}
		if ($scenario === 'full-week-month') {
			$bookingDate = '2027-10-31';
			$bookingCalendar = array('month' => '2027-10', 'previous' => null, 'next' => null,
				'days' => array('2027-10-31' => 1));
		}
		if ($scenario === 'current-day') {
			$today = new DateTimeImmutable('today', new DateTimeZone('Europe/Zurich'));
			$bookingDate = $today->format('Y-m-d');
			$bookingCalendar = array('month' => $today->format('Y-m'), 'previous' => null, 'next' => null,
				'days' => array($bookingDate => 1));
		}
		if ($scenario === 'six-month-horizon') {
			$bookingProfile['config']['horizon'] = 6;
			$bookingProfile['config']['horizon_unit'] = 'months';
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
		bookingUiAssert($xpath->query('//*[@onfocus or @onclick or @onmouseover or @onerror]')->length === 0, 'Input must not create executable attributes');
		bookingUiAssert($xpath->query('//h1')->length === 1, 'The public page must have one main heading');
		bookingUiAssert(strpos($html, 'CDavBooking') === false, 'All public booking labels must be translated');
		bookingUiAssert(strpos($html, 'PRIVATE-SENTINEL') === false, 'Calendar rendering must not expose malformed or private source values');
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
		if (!in_array($scenario, array('confirmed', 'malformed', 'invalid-month'), true)) {
			bookingUiAssert($xpath->query('//table[contains(@class,"calendar-grid")]')->length === 1, 'A valid public month must render a calendar table');
			bookingUiAssert($xpath->query('//table[contains(@class,"calendar-grid")]//th[@scope="col"]')->length === 7, 'The monthly calendar has seven named weekday columns');
			$firstHeader = $xpath->query('//table[contains(@class,"calendar-grid")]//th')->item(0);
			bookingUiAssert($firstHeader->textContent === $langs->trans('MondayMin'), 'The calendar week starts with the native translated Monday label');
			$month = new DateTimeImmutable($bookingCalendar['month'].'-01', new DateTimeZone('Europe/Zurich'));
			$dayCells = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," calendar-day ")]');
			bookingUiAssert($dayCells->length === (int) $month->format('t'), 'Every real calendar day is rendered exactly once, including leap day');
			bookingUiAssert($xpath->query('//table[contains(@class,"calendar-grid")]/tbody/tr')->length === (int) ceil(((int) $month->format('N') - 1 + (int) $month->format('t')) / 7),
				'The calendar has exactly the required complete weeks, including months ending on Sunday');
			bookingUiAssert($xpath->query('//table[contains(@class,"calendar-grid")]/tbody/tr[1]/td[not(*)]')->length === (int) $month->format('N') - 1,
				'First-week empty cells align the first calendar day to its real weekday');
			bookingUiAssert($xpath->query('//*[contains(@class,"unavailable")]/a')->length === 0, 'Unavailable days cannot become booking links');
			foreach ($xpath->query('//a[contains(concat(" ",normalize-space(@class)," ")," calendar-day ")]') as $dayLink) {
				parse_str(parse_url($dayLink->getAttribute('href'), PHP_URL_QUERY), $query);
				bookingUiAssert(array_keys($query) === array('key', 'lang', 'month', 'date'), 'Calendar day links carry only public navigation fields');
				bookingUiAssert($query['key'] === $bookingKey && $query['lang'] === substr($language, 0, 2)
					&& $query['month'] === $bookingCalendar['month'], 'Day selection preserves the publication key, language and displayed month');
				bookingUiAssert(is_int($bookingCalendar['days'][$query['date']] ?? null) && $bookingCalendar['days'][$query['date']] > 0,
					'Only dates with real integer availability become links');
				bookingUiAssert(strpos($dayLink->getAttribute('aria-label'), $langs->transnoentitiesnoconv('CDavBookingSlotCount', $bookingCalendar['days'][$query['date']])) !== false,
					'Each available date announces its slot count');
			}
			$selected = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," calendar-day ") and contains(concat(" ",normalize-space(@class)," ")," selected ")]');
			bookingUiAssert($selected->length === 1 && strpos($selected->item(0)->getAttribute('aria-label'), $langs->trans('CDavBookingSelectedDay')) !== false,
				'The selected date has a visible state and an accessible selected-day label');
		}
		if (in_array($scenario, array('slots', 'error'), true)) {
			bookingUiAssert($xpath->query('//a[contains(@class,"calendar-day")]')->length === 2, 'The calendar distinguishes the two available dates from closed days');
			bookingUiAssert($xpath->query('//a[contains(@class,"calendar-day") and contains(@href,"date=2026-11-21")]')->length === 0,
				'The closed Saturday cannot be selected');
			bookingUiAssert($xpath->query('//nav[contains(@class,"calendar-navigation")]/a[@rel="prev"]')->length === 1
				&& $xpath->query('//nav[contains(@class,"calendar-navigation")]/a[@rel="next"]')->length === 1, 'Both permitted month-navigation links are available');
			foreach ($xpath->query('//nav[contains(@class,"calendar-navigation")]/a') as $monthLink) {
				parse_str(parse_url($monthLink->getAttribute('href'), PHP_URL_QUERY), $query);
				bookingUiAssert(array_keys($query) === array('key', 'lang', 'month'), 'Month navigation does not force a stale selected date');
			}
		}
		if ($scenario === 'empty' || $scenario === 'invalid-days') {
			bookingUiAssert($xpath->query('//a[contains(@class,"calendar-day")]')->length === 0, 'Empty or malformed month data never invents availability');
			bookingUiAssert(strpos($html, $langs->trans('CDavBookingNoMonthSlots')) !== false, 'A month without slots has an explicit explanation');
			bookingUiAssert($xpath->query('//nav[contains(@class,"calendar-navigation")]/a[@rel="prev"]')->length === 0,
				'Missing or hostile previous-month navigation remains disabled');
		}
		if ($scenario === 'invalid-month') {
			bookingUiAssert($xpath->query('//table[contains(@class,"calendar-grid")]')->length === 0, 'An invalid month fails closed without date coercion');
		}
		if ($scenario === 'current-day') {
			bookingUiAssert($xpath->query('//*[@aria-current="date"]')->length === 1, 'Today is announced as the current date exactly once');
		}
		if ($scenario === 'six-month-horizon') {
			$expectedMaximum = (new DateTimeImmutable('@'.\Dolibarr\CDav\BookingAvailability::horizonEnd($bookingProfile['config'], time())))
				->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d');
			bookingUiAssert($xpath->query('//input[@id="booking-date"]')->item(0)->getAttribute('max') === $expectedMaximum,
				'The native date field preserves the exact six-calendar-month booking horizon');
		}
		$renderCount++;
	}
}
fwrite(STDOUT, 'CDav public template regressions passed: '.$renderCount." language/scenario renders.\n");
