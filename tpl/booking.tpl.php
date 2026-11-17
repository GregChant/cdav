<?php

/** Public booking view. The controller supplies only public profile and slot data. */
if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}
require_once dirname(__DIR__).'/class/CDavBookingAvailability.php';
$bookingConfig = $bookingProfile['config'] ?? array();
$bookingKey = is_string($bookingKey ?? null) ? $bookingKey : '';
$bookingDate = is_string($bookingDate ?? null) ? $bookingDate : '';
$bookingTimezone = new DateTimeZone($bookingConfig['timezone'] ?? 'UTC');
$bookingLanguage = substr((string) ($langs->defaultlang ?? 'en_US'), 0, 2) === 'fr' ? 'fr' : 'en';
$bookingValues = is_array($bookingValues ?? null) ? $bookingValues : array();
foreach (array('name', 'email', 'phone') as $bookingValueName) {
	if (!is_string($bookingValues[$bookingValueName] ?? '')) {
		unset($bookingValues[$bookingValueName]);
	}
}
$bookingPublicIdentity = null;
if (!empty($bookingProfile) && is_array($bookingIdentity ?? null)) {
	$bookingPublicIdentity = array();
	foreach (array('company_name' => 255, 'professional_name' => 255, 'email' => 254, 'phone' => 80, 'mobile' => 80) as $bookingIdentityField => $bookingIdentityLength) {
		$bookingPublicIdentity[$bookingIdentityField] = is_string($bookingIdentity[$bookingIdentityField] ?? null)
			? mb_substr($bookingIdentity[$bookingIdentityField], 0, $bookingIdentityLength) : '';
	}
	$bookingPublicIdentity['company_logo'] = '';
	$bookingLogoUrl = $bookingIdentity['company_logo'] ?? '';
	if (is_string($bookingLogoUrl) && strlen($bookingLogoUrl) <= 2048 && !preg_match('/[\x00-\x20\x7f\\\\]/', $bookingLogoUrl)) {
		$bookingLogoParts = parse_url($bookingLogoUrl);
		if (is_array($bookingLogoParts) && !isset($bookingLogoParts['scheme']) && !isset($bookingLogoParts['host'])
			&& preg_match('#^/(?:[A-Za-z0-9._~%\-]+/)*viewimage\.php$#D', $bookingLogoParts['path'] ?? '')) {
			parse_str($bookingLogoParts['query'] ?? '', $bookingLogoQuery);
			if (($bookingLogoQuery['modulepart'] ?? null) === 'mycompany' && is_string($bookingLogoQuery['file'] ?? null)
				&& str_starts_with($bookingLogoQuery['file'], 'logos/') && !preg_match('/[\x00-\x1f\x7f\\\\]/', $bookingLogoQuery['file'])
				&& !in_array('..', explode('/', $bookingLogoQuery['file']), true)) {
				$bookingPublicIdentity['company_logo'] = $bookingLogoUrl;
			}
		}
	}
	$bookingPublicIdentity['professional_photo'] = '';
	$bookingPhotoUrl = $bookingIdentity['professional_photo'] ?? '';
	if (is_string($bookingPhotoUrl) && strlen($bookingPhotoUrl) <= 1398200
		&& preg_match('~^data:image/(?:png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$~D', $bookingPhotoUrl)) {
		$bookingPublicIdentity['professional_photo'] = $bookingPhotoUrl;
	}
	$bookingPublicIdentity['email_href'] = '';
	$bookingEmailUrl = $bookingIdentity['email_href'] ?? '';
	if (is_string($bookingEmailUrl) && str_starts_with($bookingEmailUrl, 'mailto:')
		&& strpos($bookingEmailUrl, '?') === false && !preg_match('/[\x00-\x20\x7f]/', $bookingEmailUrl)
		&& rawurldecode(substr($bookingEmailUrl, 7)) === $bookingPublicIdentity['email'] && isValidEmail($bookingPublicIdentity['email'])) {
		$bookingPublicIdentity['email_href'] = $bookingEmailUrl;
	}
	foreach (array('phone', 'mobile') as $bookingIdentityField) {
		$bookingPhoneUrl = $bookingIdentity[$bookingIdentityField.'_href'] ?? '';
		$bookingPublicIdentity[$bookingIdentityField.'_href'] = is_string($bookingPhoneUrl) && preg_match('/^tel:\+?[0-9]{3,20}$/D', $bookingPhoneUrl) ? $bookingPhoneUrl : '';
	}
}
$bookingAllowsContiguous = ($bookingConfig['allow_contiguous'] ?? false) === true;
$bookingAllowsSplit = ($bookingConfig['allow_split'] ?? false) === true;
$bookingMultiple = $bookingAllowsContiguous || $bookingAllowsSplit;
$bookingSelectionLimit = \Dolibarr\CDav\BookingAvailability::MAX_SELECTION_SLOTS;
$bookingSelectionInputValid = is_array($bookingSelection ?? array());
$bookingSelectionInput = $bookingSelectionInputValid && $bookingMultiple ? $bookingSelection ?? array() : array();
if (count($bookingSelectionInput) > $bookingSelectionLimit) $bookingSelectionInputValid = false;
$bookingSelection = array();
foreach (array_slice($bookingSelectionInput, 0, $bookingSelectionLimit) as $bookingSelectedStart) {
	if (!is_int($bookingSelectedStart) || !preg_match('/^[0-9]{10}$/D', (string) $bookingSelectedStart)) {
		$bookingSelectionInputValid = false;
		continue;
	}
	$bookingSelection[$bookingSelectedStart] = $bookingSelectedStart;
}
$bookingSelection = array_values($bookingSelection);
sort($bookingSelection, SORT_NUMERIC);
$bookingSelectionCsv = implode(',', $bookingSelection);
$bookingSelectionQuery = $bookingSelectionCsv !== '' ? '&amp;selection='.rawurlencode($bookingSelectionCsv) : '';
$bookingSelectionSegmentInput = is_array($bookingSelectionSegments ?? null) ? $bookingSelectionSegments : array();
$bookingSelectionSegments = array();
if (count($bookingSelectionSegmentInput) > $bookingSelectionLimit) $bookingSelectionInputValid = false;
foreach (array_slice($bookingSelectionSegmentInput, 0, $bookingSelectionLimit) as $bookingSegment) {
	if (!is_array($bookingSegment) || !is_int($bookingSegment['start'] ?? null) || !is_int($bookingSegment['end'] ?? null)
		|| !preg_match('/^[0-9]{10}$/D', (string) $bookingSegment['start']) || !preg_match('/^[0-9]{10}$/D', (string) $bookingSegment['end'])
		|| $bookingSegment['end'] <= $bookingSegment['start']) {
		$bookingSelectionInputValid = false;
		continue;
	}
	$bookingSelectionSegments[] = array('start' => $bookingSegment['start'], 'end' => $bookingSegment['end']);
}
$bookingSelectionDuration = is_int($bookingSelectionDuration ?? null) && $bookingSelectionDuration > 0 ? $bookingSelectionDuration : 0;
$bookingSelectionValid = $bookingMultiple && ($bookingSelectionValid ?? false) === true && $bookingSelectionInputValid
	&& count($bookingSelection) > 0 && count($bookingSelectionSegments) > 0 && $bookingSelectionDuration > 0;
$bookingConfirmationSegments = null;
if (is_array($bookingResult ?? null) && array_key_exists('segments', $bookingResult)) {
	$bookingConfirmationSegments = array();
	foreach (array_slice(is_array($bookingResult['segments']) ? $bookingResult['segments'] : array(), 0, $bookingSelectionLimit) as $bookingSegment) {
		if (is_array($bookingSegment) && is_int($bookingSegment['start'] ?? null) && is_int($bookingSegment['end'] ?? null)
			&& preg_match('/^[0-9]{10}$/D', (string) $bookingSegment['start']) && preg_match('/^[0-9]{10}$/D', (string) $bookingSegment['end'])
			&& $bookingSegment['end'] > $bookingSegment['start']) {
			$bookingConfirmationSegments[] = $bookingSegment;
		}
	}
}
$bookingToday = (new DateTimeImmutable('now', $bookingTimezone))->format('Y-m-d');
if (!isset($bookingLastDate)) {
	$bookingLastDate = (new DateTimeImmutable('@'.\Dolibarr\CDav\BookingAvailability::horizonEnd($bookingConfig, time())))
		->setTimezone($bookingTimezone)->format('Y-m-d');
}
$bookingCalendarMonth = null;
$bookingCalendarDays = array();
$bookingCalendarPrevious = null;
$bookingCalendarNext = null;
if (is_array($bookingCalendar ?? null) && is_string($bookingCalendar['month'] ?? null)
	&& preg_match('/^[0-9]{4}-[0-9]{2}$/D', $bookingCalendar['month'])) {
	$bookingCalendarMonth = DateTimeImmutable::createFromFormat('!Y-m-d', $bookingCalendar['month'].'-01', $bookingTimezone);
	if (!$bookingCalendarMonth || $bookingCalendarMonth->format('Y-m') !== $bookingCalendar['month']) {
		$bookingCalendarMonth = null;
	} else {
		$bookingCalendarLength = (int) $bookingCalendarMonth->format('t');
		$bookingCalendarSourceDays = is_array($bookingCalendar['days'] ?? null) ? $bookingCalendar['days'] : array();
		for ($bookingCalendarIndex = 0; $bookingCalendarIndex < $bookingCalendarLength; $bookingCalendarIndex++) {
			$bookingCalendarDay = $bookingCalendarMonth->modify('+'.$bookingCalendarIndex.' days')->format('Y-m-d');
			$bookingCalendarCount = $bookingCalendarSourceDays[$bookingCalendarDay] ?? 0;
			$bookingCalendarDays[$bookingCalendarDay] = is_int($bookingCalendarCount) && $bookingCalendarCount > 0 && $bookingCalendarCount <= \Dolibarr\CDav\BookingAvailability::MAX_SLOTS ? $bookingCalendarCount : 0;
		}
		if (($bookingCalendar['previous'] ?? null) === $bookingCalendarMonth->modify('-1 month')->format('Y-m')) {
			$bookingCalendarPrevious = $bookingCalendar['previous'];
		}
		if (($bookingCalendar['next'] ?? null) === $bookingCalendarMonth->modify('+1 month')->format('Y-m')) {
			$bookingCalendarNext = $bookingCalendar['next'];
		}
	}
}
$bookingWeekdays = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
?>
<!doctype html>
<html lang="<?php print dol_escape_htmltag($bookingLanguage); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php print dol_escape_htmltag($langs->trans('CDavBookingTitle')); ?></title>
	<style>
		* { box-sizing: border-box; }
		body { margin: 0; background: #f3f5f7; color: #202b37; font: 1rem/1.55 system-ui, sans-serif; }
		main { max-width: 48rem; margin: 2rem auto; padding: 1.5rem; background: white; border: 1px solid #dce2e8; border-radius: .6rem; }
		h1 { font-size: 1.8rem; line-height: 1.2; margin-top: 0; }
		h2, legend { font-size: 1.15rem; font-weight: 650; }
		a { color: #174a78; }
		label { display: block; font-weight: 600; }
		input, button { font: inherit; }
		input[type=date], input[type=text], input[type=email], input[type=tel] {
			width: 100%; padding: .6rem; border: 1px solid #637385; border-radius: .25rem;
		}
		button { margin-top: 1rem; padding: .6rem 1rem; border: 1px solid #174a78; border-radius: .3rem; background: #174a78; color: white; cursor: pointer; }
		fieldset { padding: 1rem; border: 1px solid #bdc7d1; margin: 1.2rem 0; }
		.slots { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .5rem; }
		.slot { display: flex; gap: .5rem; align-items: center; font-weight: 400; border: 1px solid #bdc7d1; border-radius: .3rem; padding: .6rem; }
		.slot input { width: 1.1rem; height: 1.1rem; flex: none; }
		.field { margin: 1rem 0; }
		.notice { padding: 1rem; background: #eef3f8; border-left: 4px solid #386387; }
		.error { background: #fff2f1; border-color: #9a2824; }
		.success { background: #eef8ef; border-color: #347140; }
		.muted { color: #455566; font-size: .94rem; }
		.language { display: flex; gap: 1rem; justify-content: flex-end; }
		.company-header { display: flex; align-items: center; flex-wrap: wrap; gap: 1rem; margin: 1rem 0; }
		.company-header img { width: 10rem; height: 4rem; object-fit: contain; object-position: left center; }
		.company-header strong { font-size: 1.15rem; overflow-wrap: anywhere; }
		.professional-card { display: flex; align-items: flex-start; gap: 1rem; margin: 1.2rem 0; padding: 1rem; border: 1px solid #dce2e8; border-radius: .4rem; }
		.professional-card img { flex: none; width: 5.5rem; height: 5.5rem; object-fit: cover; border-radius: 50%; }
		.professional-details { flex: 1; min-width: 0; }
		.professional-details h2 { margin: 0 0 .5rem; overflow-wrap: anywhere; }
		.professional-details dl { margin: 0; gap: .3rem .8rem; }
		.calendar { margin: 1.5rem 0; }
		.calendar-navigation { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: .4rem; }
		.calendar-navigation a, .calendar-navigation > span { padding: .5rem .25rem; }
		.calendar-navigation > :last-child { text-align: right; }
		.calendar-month { font-weight: 650; text-align: center; }
		.calendar-grid { width: 100%; table-layout: fixed; border-spacing: .25rem; }
		.calendar-grid th, .calendar-grid td { text-align: center; padding: 0; }
		.calendar-grid th { font-size: .9rem; font-weight: 600; }
		.calendar-day { display: flex; flex-direction: column; justify-content: center; align-items: center; width: 100%; min-height: 3.4rem; margin: 0; padding: .2rem; border: 1px solid #c8d1da; border-radius: .3rem; font: inherit; line-height: 1.2; }
		a.calendar-day { color: #174a78; background: #eef7ff; font-weight: 650; text-decoration: underline; text-underline-offset: .15rem; }
		a.calendar-day:hover { background: #dceeff; }
		.calendar-day.unavailable { color: #657180; background: #f3f5f7; cursor: default; }
		.calendar-day.selected { border: 3px solid #174a78; }
		.calendar-day[aria-current=date] { outline: 1px dashed #455566; outline-offset: 2px; }
		.calendar-count { display: block; font-size: .72rem; margin-top: .25rem; }
		.calendar-legend { display: flex; flex-wrap: wrap; gap: .5rem 1.2rem; font-size: .9rem; }
		.calendar-legend span { display: inline-flex; align-items: center; gap: .35rem; }
		.calendar-symbol { display: inline-block; width: 1.25rem; height: 1.25rem; text-align: center; border: 1px solid #c8d1da; border-radius: .2rem; background: #eef7ff; }
		.calendar-symbol.unavailable { background: #f3f5f7; }
		.selection-list { padding-left: 1.3rem; }
		.selection-list li { margin: .6rem 0; }
		.booking-selection-remove { margin-left: .5rem; }
		.selection-summary { border-top: 1px solid #c8d1da; padding-top: .75rem; }
		.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
		dl { display: grid; grid-template-columns: auto 1fr; gap: .5rem 1rem; }
		dt { font-weight: 650; }
		dd { margin: 0; overflow-wrap: anywhere; }
		:focus-visible { outline: 3px solid #b45f00; outline-offset: 3px; }
		@media (max-width: 38rem) {
			main { margin: 0; padding: 1rem; border-radius: 0; border: 0; }
			.slots { grid-template-columns: 1fr; }
			dl { display: block; }
			dd { margin-bottom: .75rem; }
		}
	</style>
</head>
<body>
<main>
	<nav class="language" aria-label="<?php print dol_escape_htmltag($langs->trans('Language')); ?>">
		<a lang="fr" href="?key=<?php print rawurlencode($bookingKey); ?>&amp;lang=fr&amp;date=<?php print rawurlencode($bookingDate); ?><?php if ($bookingCalendarMonth) print '&amp;month='.$bookingCalendarMonth->format('Y-m'); ?><?php print $bookingSelectionQuery; ?>">Français</a>
		<a lang="en" href="?key=<?php print rawurlencode($bookingKey); ?>&amp;lang=en&amp;date=<?php print rawurlencode($bookingDate); ?><?php if ($bookingCalendarMonth) print '&amp;month='.$bookingCalendarMonth->format('Y-m'); ?><?php print $bookingSelectionQuery; ?>">English</a>
	</nav>
	<?php if ($bookingPublicIdentity !== null && ($bookingPublicIdentity['company_name'] !== '' || $bookingPublicIdentity['company_logo'] !== '')) { ?>
	<header class="company-header" id="booking-company">
		<?php if ($bookingPublicIdentity['company_logo'] !== '') { ?><img src="<?php print dol_escape_htmltag($bookingPublicIdentity['company_logo']); ?>" alt="<?php print dol_escape_htmltag($langs->trans('CDavBookingCompanyLogoAlt', $bookingPublicIdentity['company_name'] ?: $langs->trans('Company'))); ?>" width="160" height="64" decoding="async"><?php } ?>
		<?php if ($bookingPublicIdentity['company_name'] !== '') { ?><strong><?php print dol_escape_htmltag($bookingPublicIdentity['company_name']); ?></strong><?php } ?>
	</header>
	<?php } ?>
	<h1><?php print dol_escape_htmltag($langs->trans('CDavBookingTitle')); ?></h1>
	<?php if ($bookingPublicIdentity !== null) { ?>
	<section class="professional-card" id="booking-professional" aria-labelledby="booking-professional-name">
		<?php if ($bookingPublicIdentity['professional_photo'] !== '') { ?><img src="<?php print dol_escape_htmltag($bookingPublicIdentity['professional_photo']); ?>" alt="<?php print dol_escape_htmltag($langs->trans('CDavBookingProfessionalPhotoAlt', $bookingPublicIdentity['professional_name'] ?: $langs->trans('CDavBookingProfessional'))); ?>" width="88" height="88" decoding="async"><?php } ?>
		<div class="professional-details">
			<h2 id="booking-professional-name"><?php print dol_escape_htmltag($bookingPublicIdentity['professional_name'] ?: $langs->trans('CDavBookingProfessional')); ?></h2>
			<dl>
			<?php foreach (array('email' => 'Email', 'phone' => 'PhonePro', 'mobile' => 'PhoneMobile') as $bookingIdentityField => $bookingIdentityLabel) { ?>
				<?php if ($bookingPublicIdentity[$bookingIdentityField] !== '') { ?>
				<dt><?php print dol_escape_htmltag($langs->trans($bookingIdentityLabel)); ?></dt>
				<dd><?php if ($bookingPublicIdentity[$bookingIdentityField.'_href'] !== '') { ?><a href="<?php print dol_escape_htmltag($bookingPublicIdentity[$bookingIdentityField.'_href']); ?>"><?php print dol_escape_htmltag($bookingPublicIdentity[$bookingIdentityField]); ?></a><?php } else { print dol_escape_htmltag($bookingPublicIdentity[$bookingIdentityField]); } ?></dd>
				<?php } ?>
			<?php } ?>
			</dl>
		</div>
	</section>
	<?php } ?>
	<?php if (!empty($bookingError)) { ?>
		<div class="notice error" id="booking-error" role="alert" tabindex="-1"><p><?php print dol_escape_htmltag($bookingError); ?></p></div>
	<?php } ?>
	<?php if (!empty($bookingMessage)) { ?>
		<p class="notice" role="status"><?php print dol_escape_htmltag($bookingMessage); ?></p>
	<?php } ?>
	<?php if (!empty($bookingResult)) { ?>
		<section class="notice success" role="status" aria-labelledby="booking-confirmation">
			<h2 id="booking-confirmation"><?php print dol_escape_htmltag($langs->trans('CDavBookingConfirmation')); ?></h2>
			<dl>
				<dt><?php print dol_escape_htmltag($langs->trans('CDavBookingReference')); ?></dt>
				<dd><?php print dol_escape_htmltag((string) $bookingResult['reference']); ?></dd>
				<dt><?php print dol_escape_htmltag($langs->trans('CDavBookingDate')); ?></dt>
				<dd><?php if ($bookingConfirmationSegments !== null) { ?>
					<ol id="booking-confirmation-segments">
					<?php foreach ($bookingConfirmationSegments as $bookingSegment) { ?>
						<li><time datetime="<?php print dol_escape_htmltag((new DateTimeImmutable('@'.$bookingSegment['start']))->setTimezone($bookingTimezone)->format(DateTimeInterface::ATOM)); ?>"><?php print dol_escape_htmltag((new DateTimeImmutable('@'.$bookingSegment['start']))->setTimezone($bookingTimezone)->format('Y-m-d H:i')); ?></time> – <?php print dol_escape_htmltag((new DateTimeImmutable('@'.$bookingSegment['end']))->setTimezone($bookingTimezone)->format('Y-m-d H:i')); ?></li>
					<?php } ?>
					</ol>
					<?php } else { ?><time datetime="<?php print dol_escape_htmltag((new DateTimeImmutable('@'.((int) $bookingResult['start'])))->setTimezone($bookingTimezone)->format(DateTimeInterface::ATOM)); ?>"><?php print dol_escape_htmltag((new DateTimeImmutable('@'.((int) $bookingResult['start'])))->setTimezone($bookingTimezone)->format('Y-m-d H:i')); ?></time> – <?php print dol_escape_htmltag((new DateTimeImmutable('@'.((int) $bookingResult['end'])))->setTimezone($bookingTimezone)->format('H:i')); ?><?php } ?></dd>
				<dt><?php print dol_escape_htmltag($langs->trans('CDavBookingTimezone')); ?></dt>
				<dd><?php print dol_escape_htmltag($bookingTimezone->getName()); ?></dd>
			</dl>
			<p><?php print dol_escape_htmltag($langs->trans('CDavBookingKeepReference')); ?></p>
		</section>
	<?php } elseif (!empty($bookingProfile)) { ?>
		<p><?php print dol_escape_htmltag($langs->trans($bookingMultiple ? 'CDavBookingUnitDuration' : 'CDavBookingDuration')); ?> : <?php print (int) $bookingConfig['duration']; ?> <?php print dol_escape_htmltag($langs->trans('CDavBookingMinutes')); ?>.<br>
		<?php print dol_escape_htmltag($langs->trans('CDavBookingTimezone')); ?> : <strong><?php print dol_escape_htmltag($bookingTimezone->getName()); ?></strong>.</p>
		<?php if ($bookingCalendarMonth) { ?>
		<section class="calendar" aria-labelledby="booking-calendar-heading" aria-describedby="booking-calendar-help">
			<h2 id="booking-calendar-heading"><?php print dol_escape_htmltag($langs->trans('CDavBookingCalendar')); ?></h2>
			<nav class="calendar-navigation" aria-label="<?php print dol_escape_htmltag($langs->trans('CDavBookingCalendar')); ?>">
				<?php if ($bookingCalendarPrevious !== null) { ?>
				<a rel="prev" href="?key=<?php print rawurlencode($bookingKey); ?>&amp;lang=<?php print rawurlencode($bookingLanguage); ?>&amp;month=<?php print $bookingCalendarPrevious; ?><?php print $bookingSelectionQuery; ?>">← <?php print dol_escape_htmltag($langs->trans('CDavBookingPreviousMonth')); ?></a>
				<?php } else { ?><span aria-disabled="true" class="muted"><?php print dol_escape_htmltag($langs->trans('CDavBookingPreviousMonth')); ?></span><?php } ?>
				<span class="calendar-month"><?php print dol_escape_htmltag($langs->trans('Month'.$bookingCalendarMonth->format('m')).' '.$bookingCalendarMonth->format('Y')); ?></span>
				<?php if ($bookingCalendarNext !== null) { ?>
				<a rel="next" href="?key=<?php print rawurlencode($bookingKey); ?>&amp;lang=<?php print rawurlencode($bookingLanguage); ?>&amp;month=<?php print $bookingCalendarNext; ?><?php print $bookingSelectionQuery; ?>"><?php print dol_escape_htmltag($langs->trans('CDavBookingNextMonth')); ?> →</a>
				<?php } else { ?><span aria-disabled="true" class="muted"><?php print dol_escape_htmltag($langs->trans('CDavBookingNextMonth')); ?></span><?php } ?>
			</nav>
			<p id="booking-calendar-help" class="muted"><?php print dol_escape_htmltag($langs->trans('CDavBookingCalendarHelp')); ?></p>
			<table class="calendar-grid">
				<caption class="sr-only"><?php print dol_escape_htmltag($langs->trans('Month'.$bookingCalendarMonth->format('m')).' '.$bookingCalendarMonth->format('Y')); ?></caption>
				<thead><tr><?php foreach ($bookingWeekdays as $bookingWeekday) { ?><th scope="col"><abbr title="<?php print dol_escape_htmltag($langs->trans($bookingWeekday)); ?>"><?php print dol_escape_htmltag($langs->trans($bookingWeekday.'Min')); ?></abbr></th><?php } ?></tr></thead>
				<tbody><tr>
				<?php $bookingCalendarColumn = (int) $bookingCalendarMonth->format('N') - 1;
				for ($bookingCalendarIndex = 0; $bookingCalendarIndex < $bookingCalendarColumn; $bookingCalendarIndex++) print '<td></td>';
				foreach ($bookingCalendarDays as $bookingCalendarDay => $bookingCalendarCount) {
					$bookingCalendarDayDate = new DateTimeImmutable($bookingCalendarDay, $bookingTimezone);
					$bookingCalendarDayLabel = $bookingCalendarDayDate->format('j').' '.$langs->trans('Month'.$bookingCalendarDayDate->format('m')).' '.$bookingCalendarDayDate->format('Y');
					$bookingCalendarDayLabel .= ' : '.($bookingCalendarCount > 0 ? $langs->trans('CDavBookingSlotCount', $bookingCalendarCount) : $langs->trans('CDavBookingUnavailableDay'));
					if ($bookingCalendarDay === $bookingDate) $bookingCalendarDayLabel .= ' ; '.$langs->trans('CDavBookingSelectedDay');
					?>
					<td>
						<?php if ($bookingCalendarCount > 0) { ?>
						<a class="calendar-day<?php if ($bookingCalendarDay === $bookingDate) print ' selected'; ?>" href="?key=<?php print rawurlencode($bookingKey); ?>&amp;lang=<?php print rawurlencode($bookingLanguage); ?>&amp;month=<?php print $bookingCalendarMonth->format('Y-m'); ?>&amp;date=<?php print $bookingCalendarDay; ?><?php print $bookingSelectionQuery; ?>#booking-times" aria-label="<?php print dol_escape_htmltag($bookingCalendarDayLabel); ?>"<?php if ($bookingCalendarDay === $bookingToday) print ' aria-current="date"'; ?>>
							<span><?php print (int) $bookingCalendarDayDate->format('j'); ?></span><small class="calendar-count" aria-hidden="true">✓ <?php print $bookingCalendarCount; ?></small>
						</a>
						<?php } else { ?>
						<button type="button" disabled class="calendar-day unavailable<?php if ($bookingCalendarDay === $bookingDate) print ' selected'; ?>" aria-label="<?php print dol_escape_htmltag($bookingCalendarDayLabel); ?>"<?php if ($bookingCalendarDay === $bookingToday) print ' aria-current="date"'; ?>><?php print (int) $bookingCalendarDayDate->format('j'); ?><small class="calendar-count" aria-hidden="true">—</small></button>
						<?php } ?>
					</td>
					<?php if (++$bookingCalendarColumn === 7) {
						print '</tr>';
						if ($bookingCalendarDay !== array_key_last($bookingCalendarDays)) print '<tr>';
						$bookingCalendarColumn = 0;
					}
				}
				if ($bookingCalendarColumn > 0) {
					for (; $bookingCalendarColumn < 7; $bookingCalendarColumn++) print '<td></td>';
					print '</tr>';
				}
				?>
				</tbody>
			</table>
			<div class="calendar-legend"><span><span class="calendar-symbol" aria-hidden="true">✓</span><?php print dol_escape_htmltag($langs->trans('CDavBookingAvailableDay')); ?></span><span><span class="calendar-symbol unavailable" aria-hidden="true">—</span><?php print dol_escape_htmltag($langs->trans('CDavBookingUnavailableDay')); ?></span></div>
			<?php if ($bookingDate !== '') { ?><p class="muted"><?php print dol_escape_htmltag($langs->trans('CDavBookingSelectedDay').' : '.$bookingDate); ?></p><?php } ?>
			<?php if (array_sum($bookingCalendarDays) === 0) { ?><p class="notice"><?php print dol_escape_htmltag($langs->trans('CDavBookingNoMonthSlots')); ?></p><?php } ?>
		</section>
		<?php } ?>
		<form method="get">
			<input type="hidden" name="key" value="<?php print dol_escape_htmltag($bookingKey); ?>">
			<input type="hidden" name="lang" value="<?php print dol_escape_htmltag($bookingLanguage); ?>">
			<?php if ($bookingSelectionCsv !== '') { ?><input type="hidden" name="selection" value="<?php print dol_escape_htmltag($bookingSelectionCsv); ?>"><?php } ?>
			<label for="booking-date"><?php print dol_escape_htmltag($langs->trans('CDavBookingDate')); ?></label>
			<input type="date" id="booking-date" name="date" required min="<?php print dol_escape_htmltag($bookingToday); ?>" max="<?php print dol_escape_htmltag($bookingLastDate); ?>" value="<?php print dol_escape_htmltag($bookingDate); ?>">
			<button type="submit"><?php print dol_escape_htmltag($langs->trans('CDavBookingShowSlots')); ?></button>
		</form>
		<?php if ($bookingMultiple) { ?>
		<p class="notice"><?php print dol_escape_htmltag($langs->trans($bookingAllowsContiguous && $bookingAllowsSplit ? 'CDavBookingSelectionModesBoth' : ($bookingAllowsContiguous ? 'CDavBookingSelectionModesContiguous' : 'CDavBookingSelectionModesSplit'))); ?></p>
			<?php if (!empty($bookingSlots)) { ?>
		<form method="get" id="booking-add-selection">
			<input type="hidden" name="key" value="<?php print dol_escape_htmltag($bookingKey); ?>">
			<input type="hidden" name="lang" value="<?php print dol_escape_htmltag($bookingLanguage); ?>">
			<input type="hidden" name="date" value="<?php print dol_escape_htmltag($bookingDate); ?>">
				<?php if ($bookingCalendarMonth) { ?><input type="hidden" name="month" value="<?php print $bookingCalendarMonth->format('Y-m'); ?>"><?php } ?>
			<input type="hidden" name="selection" value="<?php print dol_escape_htmltag($bookingSelectionCsv); ?>">
			<input type="hidden" name="selection_action" value="add">
			<fieldset id="booking-times">
				<legend><?php print dol_escape_htmltag($langs->trans('CDavBookingSlot')); ?></legend>
				<div class="slots">
				<?php foreach ($bookingSlots as $bookingIndex => $bookingSlot) { ?>
					<label class="slot" for="booking-slot-<?php print (int) $bookingIndex; ?>">
							<input type="checkbox" id="booking-slot-<?php print (int) $bookingIndex; ?>" name="add[]" value="<?php print (int) $bookingSlot['start']; ?>"<?php if (in_array((int) $bookingSlot['start'], $bookingSelection, true)) print ' checked'; ?><?php if (!empty($bookingError)) print ' aria-describedby="booking-error"'; ?>>
						<span><?php print dol_escape_htmltag((new DateTimeImmutable('@'.((int) $bookingSlot['start'])))->setTimezone($bookingTimezone)->format('H:i')); ?> – <?php print dol_escape_htmltag((new DateTimeImmutable('@'.((int) $bookingSlot['end'])))->setTimezone($bookingTimezone)->format('H:i')); ?></span>
					</label>
				<?php } ?>
				</div>
			</fieldset>
			<button type="submit"><?php print dol_escape_htmltag($langs->trans('CDavBookingAddSelection')); ?></button>
		</form>
			<?php } ?>
			<?php if (!empty($bookingSelection)) { ?>
		<section id="booking-selection" aria-labelledby="booking-selection-heading">
			<h2 id="booking-selection-heading"><?php print dol_escape_htmltag($langs->trans('CDavBookingSelection')); ?></h2>
			<ul class="selection-list">
				<?php foreach ($bookingSelection as $bookingSelectedStart) {
					$bookingSelectedDate = (new DateTimeImmutable('@'.$bookingSelectedStart))->setTimezone($bookingTimezone);
					$bookingSelectionRemaining = implode(',', array_diff($bookingSelection, array($bookingSelectedStart)));
					?>
				<li data-start="<?php print $bookingSelectedStart; ?>"><time datetime="<?php print dol_escape_htmltag($bookingSelectedDate->format(DateTimeInterface::ATOM)); ?>"><?php print dol_escape_htmltag($bookingSelectedDate->format('Y-m-d H:i')); ?></time> – <?php print dol_escape_htmltag((new DateTimeImmutable('@'.($bookingSelectedStart + (int) $bookingConfig['duration'] * 60)))->setTimezone($bookingTimezone)->format('H:i')); ?>
					<a class="booking-selection-remove" href="?key=<?php print rawurlencode($bookingKey); ?>&amp;lang=<?php print rawurlencode($bookingLanguage); ?>&amp;date=<?php print rawurlencode($bookingDate); ?><?php if ($bookingCalendarMonth) print '&amp;month='.$bookingCalendarMonth->format('Y-m'); ?>&amp;selection=<?php print rawurlencode($bookingSelectionRemaining); ?>#booking-selection" aria-label="<?php print dol_escape_htmltag($langs->trans('CDavBookingRemoveSlot', $bookingSelectedDate->format('Y-m-d H:i'))); ?>"><?php print dol_escape_htmltag($langs->trans('CDavBookingRemoveSelection')); ?></a>
				</li>
				<?php } ?>
			</ul>
			<p class="selection-summary"><strong><?php print dol_escape_htmltag($langs->trans('CDavBookingSelectionTotal')); ?> : <?php print $bookingSelectionDuration; ?> <?php print dol_escape_htmltag($langs->trans('CDavBookingMinutes')); ?></strong></p>
				<?php if (!empty($bookingSelectionSegments)) { ?>
			<h3><?php print dol_escape_htmltag($langs->trans('CDavBookingSelectionSegments')); ?></h3>
			<ol class="selection-list" id="booking-selection-segments">
					<?php foreach ($bookingSelectionSegments as $bookingSegment) { ?>
				<li><?php print dol_escape_htmltag((new DateTimeImmutable('@'.$bookingSegment['start']))->setTimezone($bookingTimezone)->format('Y-m-d H:i')); ?> – <?php print dol_escape_htmltag((new DateTimeImmutable('@'.$bookingSegment['end']))->setTimezone($bookingTimezone)->format('Y-m-d H:i')); ?></li>
					<?php } ?>
			</ol>
				<?php } ?>
				<?php if (!$bookingSelectionValid && $bookingError === '') { ?><p class="notice error"><?php print dol_escape_htmltag($langs->trans('CDavBookingSelectionInvalid')); ?></p><?php } ?>
			<p><a id="booking-selection-clear" href="?key=<?php print rawurlencode($bookingKey); ?>&amp;lang=<?php print rawurlencode($bookingLanguage); ?>&amp;date=<?php print rawurlencode($bookingDate); ?><?php if ($bookingCalendarMonth) print '&amp;month='.$bookingCalendarMonth->format('Y-m'); ?>&amp;selection="><?php print dol_escape_htmltag($langs->trans('CDavBookingClearSelection')); ?></a></p>
		</section>
			<?php } ?>
		<?php } ?>
		<?php if (($bookingMultiple && $bookingSelectionValid) || (!$bookingMultiple && !empty($bookingSlots))) { ?>
		<form method="post" action="?"<?php if ($bookingMultiple) print ' id="booking-confirm-selection"'; ?>>
			<input type="hidden" name="key" value="<?php print dol_escape_htmltag($bookingKey); ?>">
			<input type="hidden" name="token" value="<?php print dol_escape_htmltag(newToken()); ?>">
			<input type="hidden" name="request" value="<?php print dol_escape_htmltag($bookingRequest); ?>">
			<input type="hidden" name="lang" value="<?php print dol_escape_htmltag($bookingLanguage); ?>">
			<input type="hidden" name="date" value="<?php print dol_escape_htmltag($bookingDate); ?>">
			<?php if ($bookingMultiple) { ?><input type="hidden" name="selection" value="<?php print dol_escape_htmltag($bookingSelectionCsv); ?>"><?php } else { ?>
			<fieldset id="booking-times">
				<legend><?php print dol_escape_htmltag($langs->trans('CDavBookingSlot')); ?></legend>
				<div class="slots">
				<?php foreach ($bookingSlots as $bookingIndex => $bookingSlot) { ?>
					<label class="slot" for="booking-slot-<?php print (int) $bookingIndex; ?>">
						<input type="radio" id="booking-slot-<?php print (int) $bookingIndex; ?>" name="start" value="<?php print (int) $bookingSlot['start']; ?>" required<?php if (!empty($bookingError)) print ' aria-describedby="booking-error"'; ?><?php if ((int) ($bookingValues['start'] ?? 0) === (int) $bookingSlot['start']) print ' checked'; ?>>
						<span><?php print dol_escape_htmltag((new DateTimeImmutable('@'.((int) $bookingSlot['start'])))->setTimezone($bookingTimezone)->format('H:i')); ?> – <?php print dol_escape_htmltag((new DateTimeImmutable('@'.((int) $bookingSlot['end'])))->setTimezone($bookingTimezone)->format('H:i')); ?></span>
					</label>
				<?php } ?>
				</div>
			</fieldset>
			<?php } ?>
			<div class="field"><label for="booking-name"><?php print dol_escape_htmltag($langs->trans('CDavBookingName')); ?></label><input type="text" id="booking-name" name="name" autocomplete="name" maxlength="120" required<?php if (!empty($bookingError)) print ' aria-describedby="booking-error"'; ?> value="<?php print dol_escape_htmltag($bookingValues['name'] ?? ''); ?>"></div>
			<div class="field"><label for="booking-email"><?php print dol_escape_htmltag($langs->trans('CDavBookingEmail')); ?></label><input type="email" id="booking-email" name="email" autocomplete="email" maxlength="254" required<?php if (!empty($bookingError)) print ' aria-describedby="booking-error"'; ?> value="<?php print dol_escape_htmltag($bookingValues['email'] ?? ''); ?>"></div>
			<div class="field"><label for="booking-phone"><?php print dol_escape_htmltag($langs->trans('CDavBookingPhone')); ?></label><input type="tel" id="booking-phone" name="phone" autocomplete="tel" maxlength="40"<?php if (!empty($bookingError)) print ' aria-describedby="booking-error"'; ?> value="<?php print dol_escape_htmltag($bookingValues['phone'] ?? ''); ?>"></div>
			<div hidden aria-hidden="true"><label for="booking-website">Website</label><input type="text" id="booking-website" name="website" tabindex="-1" autocomplete="off" value=""></div>
			<p class="muted"><?php print dol_escape_htmltag($langs->trans('CDavBookingPrivacy')); ?></p>
			<button type="submit"><?php print dol_escape_htmltag($langs->trans($bookingMultiple && count($bookingSelection) > 1 ? 'CDavBookingSubmitSelection' : 'CDavBookingSubmit')); ?></button>
		</form>
		<?php } ?>
		<?php if (empty($bookingSlots)) { ?>
			<p class="notice" role="status"><?php print dol_escape_htmltag($langs->trans('CDavBookingNoSlots')); ?></p>
		<?php } ?>
	<?php } ?>
</main>
</body>
</html>
