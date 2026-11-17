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
	foreach (array($root.'/langs/'.$language.'/main.lang', $root.'/langs/'.$language.'/companies.lang', dirname(__DIR__).'/langs/'.$language.'/cdav.lang') as $file) {
		foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
			if (strpos($line, '=') === false || str_starts_with($line, '#')) continue;
			list($key, $value) = explode('=', $line, 2);
			$langs->tab_translate[$key] = $value;
		}
	}
	foreach (array('slots', 'empty', 'error', 'confirmed', 'malformed', 'invalid-month', 'invalid-days', 'leap-month', 'full-week-month', 'current-day', 'six-month-horizon',
		'details-malformed', 'details-hostile', 'details-multiline', 'details-oversized',
		'confirmed-legacy', 'confirmed-details-hostile', 'confirmed-details-malformed',
		'multi-empty', 'multi-contiguous', 'multi-split', 'multi-both', 'multi-disabled', 'multi-invalid', 'multi-malformed', 'multi-scalar', 'multi-dst', 'confirmed-split',
		'branding-full', 'branding-no-images', 'branding-no-contacts', 'branding-hostile', 'branding-hidden', 'branding-malformed', 'branding-large-photo', 'branding-oversized-photo') as $scenario) {
		$bookingProfile = array('config' => array('timezone' => 'Europe/Zurich', 'duration' => 30, 'horizon' => 60));
		$bookingKey = str_repeat('a', 64);
		$bookingDate = '2026-11-20';
		unset($bookingLastDate);
		$bookingCalendar = array('month' => '2026-11', 'previous' => '2026-10', 'next' => '2026-12',
			'days' => array('2026-11-20' => 4, '2026-11-21' => 0, '2026-11-23' => 2));
		$bookingRequest = 'request-sentinel';
		$bookingSlots = in_array($scenario, array('slots', 'error', 'details-malformed', 'details-hostile', 'details-multiline', 'details-oversized'), true)
			? array(array('start' => 1795179600, 'end' => 1795181400)) : array();
		$bookingResult = in_array($scenario, array('confirmed', 'confirmed-legacy', 'confirmed-details-hostile', 'confirmed-details-malformed'), true)
			? array('reference' => 'public-ref', 'start' => 1795179600, 'end' => 1795181400) : null;
		$bookingMessage = '';
		$bookingError = $scenario === 'error' ? 'Please reload <script>alert(1)</script>' : '';
		$bookingValues = array('name' => 'Client " onfocus="alert(1)', 'email' => 'client@example.test', 'phone' => '0041000000000',
			'subject' => 'Diagnostic chauffage', 'address' => "Rue de l’Église 12\n2e étage\n1000 Lausanne");
		$bookingSelection = array();
		$bookingSelectionSegments = array();
		$bookingSelectionDuration = 0;
		$bookingSelectionValid = false;
		$bookingIdentity = null;
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
		if ($scenario === 'details-malformed') {
			$bookingValues['subject'] = array('malformed');
			$bookingValues['address'] = array('malformed');
		}
		if ($scenario === 'details-hostile') {
			$bookingValues['subject'] = 'Visite" autofocus onfocus="alert(1)';
			$bookingValues['address'] = "</textarea><script>alert(1)</script><textarea>\n1000 Lausanne";
		}
		if ($scenario === 'details-multiline') {
			$bookingValues['subject'] = 'Réparation — chaudière';
			$bookingValues['address'] = "Rue de l’Église 12\r\n2e étage\n1000 Lausanne";
			$bookingError = 'Please correct the selected appointment.';
		}
		if ($scenario === 'details-oversized') {
			$bookingValues['subject'] = str_repeat('s', 121);
			$bookingValues['address'] = str_repeat('a', 129);
			$bookingError = 'Please correct the appointment details.';
		}
		if ($scenario === 'confirmed-legacy') unset($bookingValues['subject'], $bookingValues['address']);
		if ($scenario === 'confirmed-details-hostile') {
			$bookingValues['subject'] = '</dd><script>alert(1)</script>';
			$bookingValues['address'] = "</dd><script>alert(1)</script>\n1000 Lausanne";
		}
		if ($scenario === 'confirmed-details-malformed') {
			$bookingValues['subject'] = array('malformed');
			$bookingValues['address'] = array('malformed');
		}
		if (str_starts_with($scenario, 'multi-') || $scenario === 'confirmed-split') {
			$selectionStart = (new DateTimeImmutable('2026-11-20 09:00', new DateTimeZone('Europe/Zurich')))->getTimestamp();
			$selectionAfternoon = $selectionStart + 4 * 3600;
			$selectionOtherDay = $selectionStart + 3 * 86400;
			$bookingProfile['config']['allow_contiguous'] = in_array($scenario, array('multi-empty', 'multi-contiguous', 'multi-both', 'multi-invalid', 'multi-malformed', 'multi-scalar', 'multi-dst', 'confirmed-split'), true);
			$bookingProfile['config']['allow_split'] = !in_array($scenario, array('multi-contiguous', 'multi-disabled'), true);
			$bookingSlots = array(array('start' => $selectionStart, 'end' => $selectionStart + 1800),
				array('start' => $selectionStart + 1800, 'end' => $selectionStart + 3600),
				array('start' => $selectionAfternoon, 'end' => $selectionAfternoon + 1800));
			$bookingSelection = array($selectionStart, $selectionStart + 1800);
			$bookingSelectionSegments = array(array('start' => $selectionStart, 'end' => $selectionStart + 3600));
			$bookingSelectionDuration = 60;
			$bookingSelectionValid = true;
			if ($scenario === 'multi-empty') {
				$bookingSelection = $bookingSelectionSegments = array();
				$bookingSelectionDuration = 0;
				$bookingSelectionValid = false;
			}
			if (in_array($scenario, array('multi-split', 'multi-invalid'), true)) {
				$bookingSelection = array($selectionStart, $selectionAfternoon);
				$bookingSelectionSegments = array(array('start' => $selectionStart, 'end' => $selectionStart + 1800),
					array('start' => $selectionAfternoon, 'end' => $selectionAfternoon + 1800));
			}
			if ($scenario === 'multi-both' || $scenario === 'confirmed-split') {
				$bookingSelection = array($selectionStart, $selectionStart + 1800, $selectionAfternoon, $selectionOtherDay);
				$bookingSelectionSegments = array(array('start' => $selectionStart, 'end' => $selectionStart + 3600),
					array('start' => $selectionAfternoon, 'end' => $selectionAfternoon + 1800),
					array('start' => $selectionOtherDay, 'end' => $selectionOtherDay + 1800));
				$bookingSelectionDuration = 120;
			}
			if ($scenario === 'multi-invalid') {
				$bookingSelectionValid = false;
				$bookingError = 'The chosen slot is no longer available.';
				$bookingSlots = array($bookingSlots[2]);
			}
			if ($scenario === 'multi-malformed') {
				$bookingSelection = array($selectionStart, (string) ($selectionStart + 1800), array('PRIVATE-SENTINEL'), '<script>PRIVATE-SENTINEL</script>');
				$bookingSelectionSegments = array(array('start' => array('PRIVATE-SENTINEL'), 'end' => $selectionStart + 3600));
				$bookingSelectionDuration = array('PRIVATE-SENTINEL');
			}
			if ($scenario === 'multi-scalar') $bookingSelection = '<script>PRIVATE-SENTINEL</script>';
			if ($scenario === 'multi-dst') {
				$selectionStart = (new DateTimeImmutable('2026-03-29 00:00', new DateTimeZone('Europe/Zurich')))->getTimestamp();
				$bookingDate = '2026-03-29';
				$bookingProfile['config']['duration'] = 240;
				$bookingSelection = array($selectionStart);
				$bookingSelectionSegments = array(array('start' => $selectionStart, 'end' => $selectionStart + 4 * 3600));
				$bookingSelectionDuration = 240;
				$bookingSlots = $bookingSelectionSegments;
				$bookingCalendar = array('month' => '2026-03', 'previous' => null, 'next' => null, 'days' => array('2026-03-29' => 1));
			}
			if ($scenario === 'confirmed-split') {
				$bookingResult = array('reference' => 'public-ref', 'start' => $selectionStart, 'end' => $selectionOtherDay + 1800,
					'segments' => $bookingSelectionSegments);
			}
		}
		if (str_starts_with($scenario, 'branding-')) {
			$tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
			$bookingIdentity = array('company_name' => 'BRAND-COMPANY-SENTINEL',
				'company_logo' => '/dolibarr/viewimage.php?modulepart=mycompany&entity=1&file=logos%2Flogo.png',
				'professional_name' => 'BRAND-PROFESSIONAL-SENTINEL', 'professional_photo' => 'data:image/png;base64,'.base64_encode($tinyPng),
				'email' => 'professional@example.test', 'email_href' => 'mailto:professional%40example.test',
				'phone' => '+41 21 555 01 00', 'phone_href' => 'tel:+41215550100',
				'mobile' => '+41 79 555 01 00', 'mobile_href' => 'tel:+41795550100');
			if ($scenario === 'branding-no-images') $bookingIdentity['company_logo'] = $bookingIdentity['professional_photo'] = '';
			if ($scenario === 'branding-no-contacts') {
				foreach (array('email', 'email_href', 'phone', 'phone_href', 'mobile', 'mobile_href') as $identityField) $bookingIdentity[$identityField] = '';
			}
			if ($scenario === 'branding-hostile') {
				$bookingIdentity['company_name'] = '<img src=x onerror="alert(1)">';
				$bookingIdentity['professional_name'] = '<script>alert(1)</script>';
				$bookingIdentity['company_logo'] = 'javascript:alert(1)';
				$bookingIdentity['professional_photo'] = 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>');
				$bookingIdentity['email'] = 'owner" onmouseover="alert(1)@example.test';
				$bookingIdentity['email_href'] = $bookingIdentity['phone_href'] = $bookingIdentity['mobile_href'] = 'javascript:alert(1)';
			}
			if ($scenario === 'branding-hidden') $bookingProfile = null;
			if ($scenario === 'branding-malformed') {
				foreach (array_keys($bookingIdentity) as $identityField) $bookingIdentity[$identityField] = array('malformed');
			}
			if ($scenario === 'branding-large-photo') {
				$bookingIdentity['professional_photo'] = 'data:image/png;base64,'.base64_encode($tinyPng.str_repeat("\0", 300000));
			}
			if ($scenario === 'branding-oversized-photo') $bookingIdentity['professional_photo'] = 'data:image/png;base64,'.str_repeat('A', 1398201);
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
		bookingUiAssert($xpath->query('//form[@method="get"]//input[@name="action" or @name="token"]')->length === 0,
			'Read-only selection forms use no native mutation action and expose no CSRF token in URLs');
		bookingUiAssert(strpos($html, 'PRIVATE-SENTINEL') === false, 'Calendar rendering must not expose malformed or private source values');
		foreach ($xpath->query('//input[not(@type="hidden")] | //textarea') as $input) {
			bookingUiAssert($input->hasAttribute('id'), 'Visible fields must have an identifier');
			bookingUiAssert($xpath->query('//label[@for="'.$input->getAttribute('id').'"]')->length === 1,
				'Visible fields must have exactly one associated label');
		}
		foreach ($xpath->query('//form[@method="post"]') as $confirmationForm) {
			bookingUiAssert($confirmationForm->getAttribute('action') === '?', 'A confirmation POST clears month/add parameters from the current URL');
			$subject = $xpath->query('.//input[@name="subject"]', $confirmationForm);
			$address = $xpath->query('.//textarea[@name="address"]', $confirmationForm);
			bookingUiAssert($subject->length === 1 && $address->length === 1,
				'Every single or grouped confirmation collects exactly one subject and one client address');
			bookingUiAssert($subject->item(0)->hasAttribute('required') && $subject->item(0)->getAttribute('maxlength') === '120'
				&& $address->item(0)->hasAttribute('required') && $address->item(0)->getAttribute('maxlength') === '128',
				'Subject and address are required with limits matching the native Agenda fields');
			bookingUiAssert($address->item(0)->getAttribute('autocomplete') === 'street-address'
				&& $address->item(0)->getAttribute('rows') === '3', 'A client can enter a complete multiline intervention address');
			bookingUiAssert($subject->item(0)->getAttribute('value') === ($bookingValues['subject'] ?? '')
				&& str_replace("\r\n", "\n", $address->item(0)->textContent) === str_replace("\r\n", "\n", $bookingValues['address'] ?? ''),
				'Rendered appointment details preserve the visitor input, including address line breaks, after an error');
			$addressDescriptions = preg_split('/\s+/', $address->item(0)->getAttribute('aria-describedby'));
			bookingUiAssert(in_array('booking-address-help', $addressDescriptions, true)
				&& $xpath->query('//*[@id="booking-address-help"]')->length === 1, 'The address field has associated explanatory text');
			if ($bookingError !== '') {
				bookingUiAssert($subject->item(0)->getAttribute('aria-describedby') === 'booking-error'
					&& in_array('booking-error', $addressDescriptions, true), 'Validation errors are associated with both appointment-detail fields');
			}
		}
		bookingUiAssert($xpath->query('//form[@method="get"]//*[@name="subject" or @name="address"]')->length === 0,
			'Subject and client address are submitted only by POST');
		foreach ($xpath->query('//a[@href]') as $navigationLink) {
			parse_str(parse_url($navigationLink->getAttribute('href'), PHP_URL_QUERY) ?? '', $query);
			bookingUiAssert(!array_intersect(array_keys($query), array('subject', 'address')),
				'Calendar, language and selection links never expose appointment details in URLs');
		}
		if (in_array($scenario, array('slots', 'error'), true)) {
			bookingUiAssert($xpath->query('//form[@method="post"]')->length === 1, 'Available slots must have a confirmation form');
			foreach (array('key', 'token', 'request', 'date', 'start', 'name', 'email', 'phone', 'subject', 'website') as $name) {
				bookingUiAssert($xpath->query('//form[@method="post"]//input[@name="'.$name.'"]')->length === 1,
					'The confirmation form must preserve '.$name);
			}
			bookingUiAssert($xpath->query('//input[@name="token"]')->item(0)->getAttribute('value') === 'native-csrf-sentinel',
				'The form must use the native session CSRF token');
		}
		if (in_array($scenario, array('confirmed', 'confirmed-legacy', 'confirmed-details-hostile', 'confirmed-details-malformed'), true)) {
			bookingUiAssert($xpath->query('//form[@method="post"]')->length === 0, 'Confirmation must not offer an accidental repeat submission');
			bookingUiAssert(strpos($html, 'public-ref') !== false, 'Confirmation must expose the public reference');
		}
		if ($bookingResult !== null) {
			foreach (array('subject', 'address') as $detailField) {
				$detail = $xpath->query('//*[@id="booking-confirmation-'.$detailField.'"]');
				if (($bookingValues[$detailField] ?? '') === '') {
					bookingUiAssert($detail->length === 0, 'Legacy or missing details do not invent a confirmation value');
				} else {
					bookingUiAssert($detail->length === 1
						&& str_replace("\r\n", "\n", $detail->item(0)->textContent) === str_replace("\r\n", "\n", $bookingValues[$detailField]),
						'Confirmation preserves and escapes the visitor\'s own '.$detailField.' without exposing it in navigation');
					if ($detailField === 'address') bookingUiAssert(strpos($detail->item(0)->getAttribute('class'), 'booking-address-value') !== false,
						'The confirmed address preserves its visible line breaks');
				}
			}
		}
		if ($scenario === 'error') {
			bookingUiAssert($xpath->query('//*[@role="alert" and @id="booking-error"]')->length === 1, 'Errors must be announced');
			foreach (array('name', 'email', 'phone', 'subject', 'start') as $name) {
				bookingUiAssert($xpath->query('//input[@name="'.$name.'" and @aria-describedby="booking-error"]')->length === 1,
					'Errors must be associated with the '.$name.' field');
			}
		}
		if ($bookingResult === null && !in_array($scenario, array('malformed', 'invalid-month', 'branding-hidden'), true)) {
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
				$expectedFields = array('key', 'lang', 'month', 'date');
				if ($bookingSelectionCsv !== '') $expectedFields[] = 'selection';
				bookingUiAssert(array_keys($query) === $expectedFields, 'Calendar day links carry only public navigation fields and an optional slot selection');
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
		if (str_starts_with($scenario, 'multi-') && $scenario !== 'multi-disabled') {
			bookingUiAssert($xpath->query('//form[@id="booking-add-selection" and @method="get"]')->length === 1, 'An enabled profile offers a GET form to build its selection');
			bookingUiAssert($xpath->query('//input[@type="radio"]')->length === 0, 'An enabled multi-selection profile uses checkboxes instead of legacy radios');
			bookingUiAssert($xpath->query('//input[@type="checkbox" and @name="add[]"]')->length === count($bookingSlots), 'Each current free slot has one add checkbox');
			$permissionKey = $scenario === 'multi-contiguous' ? 'CDavBookingSelectionModesContiguous'
				: ($scenario === 'multi-split' ? 'CDavBookingSelectionModesSplit' : 'CDavBookingSelectionModesBoth');
			bookingUiAssert(strpos($html, $langs->trans($permissionKey)) !== false, 'The public hint describes this profile\'s own selection permissions');
			foreach ($xpath->query('//nav[contains(@class,"language")]/a | //nav[contains(@class,"calendar-navigation")]/a | //a[contains(@class,"calendar-day")]') as $navigationLink) {
				parse_str(parse_url($navigationLink->getAttribute('href'), PHP_URL_QUERY), $query);
				bookingUiAssert(($query['selection'] ?? '') === $bookingSelectionCsv, 'Changing day, month or language preserves only the sanitized timestamp selection');
				bookingUiAssert(!array_intersect(array_keys($query), array('name', 'email', 'phone', 'subject', 'address', 'website')), 'Contact and appointment details never appear in navigation URLs');
			}
			if (!empty($bookingSelection)) {
				bookingUiAssert($xpath->query('//section[@id="booking-selection"]//li[@data-start]')->length === count($bookingSelection), 'The basket lists every selected unit separately');
				bookingUiAssert($xpath->query('//input[@id="booking-date"]/ancestor::form/input[@name="selection"]')->item(0)->getAttribute('value') === $bookingSelectionCsv,
					'The independent date form preserves the selected units');
				foreach ($xpath->query('//a[contains(@class,"booking-selection-remove")]') as $removeLink) {
					parse_str(parse_url($removeLink->getAttribute('href'), PHP_URL_QUERY), $query);
					$removedStart = (int) $removeLink->parentNode->getAttribute('data-start');
					bookingUiAssert($query['selection'] === implode(',', array_diff($bookingSelection, array($removedStart))), 'Each remove link removes exactly its own unit');
					bookingUiAssert($query['key'] === $bookingKey && $query['date'] === $bookingDate && $query['month'] === $bookingCalendar['month'], 'Removing a unit preserves its public navigation context');
				}
				parse_str(parse_url($xpath->query('//a[@id="booking-selection-clear"]')->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
				bookingUiAssert(array_key_exists('selection', $query) && $query['selection'] === '', 'The clear link explicitly empties the URL basket');
				bookingUiAssert($xpath->query('//ol[@id="booking-selection-segments"]/li')->length === count($bookingSelectionSegments), 'The basket distinguishes merged periods from its individual units');
			}
			if ($bookingSelectionValid) {
				bookingUiAssert($xpath->query('//form[@id="booking-confirm-selection" and @method="post"]')->length === 1, 'A verified selection has exactly one contact confirmation form');
				bookingUiAssert($xpath->query('//form[@method="post"]')->length === 1, 'A basket cannot create multiple independent confirmation forms');
				bookingUiAssert($xpath->query('//form[@method="post"]//input[@name="selection"]')->item(0)->getAttribute('value') === $bookingSelectionCsv,
					'Confirmation submits the exact verified selection');
				bookingUiAssert($xpath->query('//form[@method="post"]//input[@name="start" or @name="add[]" or @name="month" or @name="action"]')->length === 0,
					'A selection POST does not mix legacy starts or transient GET actions');
				foreach (array('key', 'token', 'request', 'date', 'name', 'email', 'phone', 'subject', 'website') as $name) {
					bookingUiAssert($xpath->query('//form[@method="post"]//input[@name="'.$name.'"]')->length === 1, 'Selection confirmation preserves the single '.$name.' field');
				}
			} else {
				bookingUiAssert($xpath->query('//form[@method="post"]')->length === 0, 'An empty, stale or malformed selection cannot be confirmed');
			}
		}
		if ($scenario === 'multi-disabled') {
			bookingUiAssert($xpath->query('//input[@type="radio" and @name="start"]')->length === count($bookingSlots), 'A profile without permissions keeps its original single-slot radios');
			bookingUiAssert($xpath->query('//input[@type="checkbox"] | //input[@name="selection"] | //*[@id="booking-selection"]')->length === 0,
				'A forged basket cannot enable multi-selection on another profile');
			bookingUiAssert($xpath->query('//form[@method="post"]')->length === 1, 'The original single-slot confirmation remains available');
		}
		if ($scenario === 'multi-contiguous') {
			bookingUiAssert($xpath->query('//input[@name="add[]" and @checked]')->length === 2, 'Selected current-day units stay checked without being disabled');
			bookingUiAssert($xpath->query('//input[@name="add[]" and @disabled]')->length === 0, 'The visitor can still interact with selected checkboxes');
		}
		if ($scenario === 'multi-invalid') {
			bookingUiAssert($xpath->query('//*[@id="booking-error" and @role="alert"]')->length === 1, 'A stale selection retains an announced error and its remove links');
			bookingUiAssert($xpath->query('//a[contains(@class,"booking-selection-remove")]')->length === 2, 'A stale basket remains repairable');
		}
		if ($scenario === 'multi-dst') {
			$unit = $xpath->query('//section[@id="booking-selection"]//li[@data-start]')->item(0)->textContent;
			bookingUiAssert(strpos($unit, '2026-03-29 00:00') !== false && strpos($unit, '05:00') !== false,
				'Basket unit labels preserve the real four-hour duration across the spring clock change');
		}
		if ($scenario === 'confirmed-split') {
			bookingUiAssert($xpath->query('//form[@method="post"]')->length === 0, 'A confirmed split booking cannot be submitted again');
			$periods = $xpath->query('//ol[@id="booking-confirmation-segments"]/li');
			bookingUiAssert($periods->length === 3, 'Split confirmation lists the three actual periods, preserving breaks and day boundaries');
			foreach ($bookingResult['segments'] as $index => $segment) {
				$expected = (new DateTimeImmutable('@'.$segment['start']))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d H:i')
					.' – '.(new DateTimeImmutable('@'.$segment['end']))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d H:i');
				bookingUiAssert(trim($periods->item($index)->textContent) === $expected, 'Confirmation renders each real period rather than an envelope containing breaks');
			}
		}
		if (str_starts_with($scenario, 'branding-') && $scenario !== 'branding-hidden') {
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]')->length === 1, 'A valid booking profile can display its professional identity');
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]//h2[@id="booking-professional-name"]')->length === 1, 'The professional card has a named accessible heading');
			foreach ($xpath->query('//img') as $image) {
				bookingUiAssert($image->getAttribute('alt') !== '', 'Published identity images have a meaningful translated alternative');
				$source = $image->getAttribute('src');
				bookingUiAssert(str_starts_with($source, '/dolibarr/viewimage.php?modulepart=mycompany')
					|| str_starts_with($source, 'data:image/png;base64,'), 'Identity image sources remain native company-logo URLs or bounded raster data');
			}
			bookingUiAssert($xpath->query('//a[starts-with(@href,"javascript:")] | //img[starts-with(@src,"javascript:")]')->length === 0,
				'Identity fields cannot introduce executable URI schemes');
		}
		if ($scenario === 'branding-full' || $scenario === 'branding-no-images') {
			bookingUiAssert($xpath->query('//header[@id="booking-company"]/strong')->item(0)->textContent === $bookingIdentity['company_name'], 'The public header displays the native company name');
			bookingUiAssert($xpath->query('//h2[@id="booking-professional-name"]')->item(0)->textContent === $bookingIdentity['professional_name'], 'The public card displays the native professional name');
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]//a[starts-with(@href,"mailto:")]')->length === 1
				&& $xpath->query('//section[@id="booking-professional"]//a[starts-with(@href,"tel:")]')->length === 2,
				'The professional email, office number and mobile have their own safe contact links');
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]//dt')->item(1)->textContent === $langs->transnoentitiesnoconv('PhonePro')
				&& $xpath->query('//section[@id="booking-professional"]//dt')->item(2)->textContent === $langs->transnoentitiesnoconv('PhoneMobile'),
				'Office and mobile labels use the native translated company strings');
			bookingUiAssert($xpath->query('//img')->length === ($scenario === 'branding-full' ? 2 : 0), 'Missing photos and logos fall back to the same readable names and contacts');
		}
		if ($scenario === 'branding-no-contacts' || $scenario === 'branding-malformed') {
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]//a')->length === 0, 'Missing or malformed contact fields do not create empty contact links');
		}
		if ($scenario === 'branding-hostile') {
			bookingUiAssert($xpath->query('//img')->length === 0, 'Hostile logo URLs and SVG photo data are rejected');
			bookingUiAssert($xpath->query('//h2[@id="booking-professional-name"]')->item(0)->textContent === $bookingIdentity['professional_name'], 'A hostile professional name remains escaped readable text');
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]//a')->length === 0, 'Hostile mail and telephone URI fields remain plain escaped text');
		}
		if ($scenario === 'branding-hidden') {
			bookingUiAssert($xpath->query('//*[@id="booking-company" or @id="booking-professional"] | //img')->length === 0,
				'An invalid or absent booking profile cannot reveal company or professional identity');
			bookingUiAssert(strpos($html, 'BRAND-COMPANY-SENTINEL') === false && strpos($html, 'BRAND-PROFESSIONAL-SENTINEL') === false
				&& strpos($html, 'professional@example.test') === false, 'Identity fields do not leak through an unavailable publication link');
		}
		if ($scenario === 'branding-large-photo') {
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]//img')->length === 1, 'A verified native raster photo larger than 256KiB remains displayable below the one-MiB binary limit');
		}
		if ($scenario === 'branding-oversized-photo') {
			bookingUiAssert($xpath->query('//section[@id="booking-professional"]//img')->length === 0, 'An oversized photo URI is omitted without losing the professional card');
		}
		$renderCount++;
	}
}
fwrite(STDOUT, 'CDav public template regressions passed: '.$renderCount." language/scenario renders.\n");
