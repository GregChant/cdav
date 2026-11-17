<?php

/** Public appointment configuration; all mutations require a native admin session. */
define('CSRFCHECK_WITH_TOKEN', 1);

// Use the web path as well as the module path: custom modules may be symlinks.
$res = 0;
$bookingMainCandidates = array();
foreach (array('CONTEXT_DOCUMENT_ROOT', 'DOCUMENT_ROOT') as $bookingRootName) {
	if (!empty($_SERVER[$bookingRootName])) {
		$bookingMainCandidates[] = rtrim((string) $_SERVER[$bookingRootName], '/').'/main.inc.php';
	}
}
for ($bookingDepth = 2; $bookingDepth <= 5; $bookingDepth++) {
	if (!empty($_SERVER['SCRIPT_FILENAME'])) {
		$bookingMainCandidates[] = dirname((string) $_SERVER['SCRIPT_FILENAME'], $bookingDepth).'/main.inc.php';
	}
	$bookingMainCandidates[] = dirname(__DIR__, $bookingDepth - 1).'/main.inc.php';
}
foreach (array_unique($bookingMainCandidates) as $bookingMainPath) {
	if (!$res && is_file($bookingMainPath)) {
		$res = include $bookingMainPath;
	}
}
if (!$res) {
	http_response_code(500);
	exit('Unable to load Dolibarr');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once dirname(__DIR__).'/class/CDavBookingService.php';

$langs->loadLangs(array('admin', 'agenda', 'cdav@cdav'));
if (!$user->admin || !empty($user->socid) || !empty($user->design)
	|| !isModEnabled('cdav') || !isModEnabled('agenda')) {
	accessforbidden();
}
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

$form = new Form($db);
$bookingService = new \Dolibarr\CDav\BookingService($db, (int) $conf->entity);
$bookingOwnerId = GETPOSTINT('owner_id') ?: (int) $user->id;
$action = GETPOST('action', 'aZ09');
$bookingNewKey = '';
$bookingPostedConfig = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	try {
		if ($action === 'save_global') {
			$bookingEnabled = GETPOSTINT('CDAV_BOOKING_ENABLED') === 1 ? '1' : '0';
			if (dolibarr_set_const($db, 'CDAV_BOOKING_ENABLED', $bookingEnabled, 'chaine', 0, '', $conf->entity) <= 0) {
				throw new RuntimeException('Unable to save the booking activation setting');
			}
			$conf->global->CDAV_BOOKING_ENABLED = $bookingEnabled;
			setEventMessages($langs->trans('CDavBookingSaved'), null, 'mesgs');
		} elseif ($action === 'save_profile') {
			$bookingPostedConfig = array('timezone' => GETPOST('timezone', 'alphanohtml'),
				'horizon_unit' => GETPOSTISSET('horizon_unit') ? GETPOST('horizon_unit', 'aZ09') : 'days', 'weekly' => array());
			foreach (array('allow_contiguous', 'allow_split') as $bookingPermission) {
				$bookingPostedConfig[$bookingPermission] = GETPOSTINT($bookingPermission) === 1;
			}
			foreach (array('duration', 'step', 'buffer_before', 'buffer_after', 'notice', 'horizon') as $bookingSetting) {
				$bookingPostedConfig[$bookingSetting] = GETPOSTINT($bookingSetting);
			}
			for ($bookingDay = 1; $bookingDay <= 7; $bookingDay++) {
				$bookingPostedConfig['weekly'][$bookingDay] = array();
				for ($bookingRange = 0; $bookingRange < 2; $bookingRange++) {
					$bookingStart = trim(GETPOST('weekly_'.$bookingDay.'_'.$bookingRange.'_start', 'alphanohtml'));
					$bookingEnd = trim(GETPOST('weekly_'.$bookingDay.'_'.$bookingRange.'_end', 'alphanohtml'));
					if ($bookingStart !== '' || $bookingEnd !== '') {
						$bookingPostedConfig['weekly'][$bookingDay][] = array($bookingStart, $bookingEnd);
					}
				}
			}
			$bookingService->saveProfile($bookingOwnerId, $bookingPostedConfig, GETPOSTINT('profile_enabled') === 1);
			$bookingPostedConfig = null;
			setEventMessages($langs->trans('CDavBookingSaved'), null, 'mesgs');
		} elseif ($action === 'rotate_token') {
			$bookingNewKey = $bookingService->rotateToken($bookingOwnerId);
			setEventMessages($langs->trans('CDavBookingLinkCreated'), null, 'mesgs');
		} elseif ($action === 'disable_profile') {
			$bookingService->disableProfile($bookingOwnerId);
			setEventMessages($langs->trans('CDavBookingRevoked'), null, 'mesgs');
		} else {
			throw new InvalidArgumentException('Unsupported booking administration action');
		}
	} catch (Throwable $bookingException) {
		dol_syslog('CDav booking administration: '.get_class($bookingException), LOG_ERR);
		setEventMessages($langs->trans('CDavBookingSaveError'), null, 'errors');
	}
}

$bookingProfile = null;
try {
	$bookingProfile = $bookingService->profileForOwner($bookingOwnerId);
} catch (Throwable $bookingException) {
	dol_syslog('CDav booking configuration read: '.get_class($bookingException), LOG_ERR);
	setEventMessages($langs->trans('CDavBookingUnavailable'), null, 'errors');
}
$bookingDefaultWeekly = array();
for ($bookingDay = 1; $bookingDay <= 7; $bookingDay++) {
	$bookingDefaultWeekly[$bookingDay] = $bookingDay <= 5
		? array(array('09:00', '12:00'), array('13:00', '17:00')) : array();
}
$bookingConfig = $bookingPostedConfig ?? ($bookingProfile['config'] ?? array(
	'timezone' => $_SESSION['dol_tz_string'] ?? getDolGlobalString('MAIN_DOLIBARR_USER_TIMEZONE', 'UTC'),
	'duration' => 30, 'step' => 30, 'buffer_before' => 0, 'buffer_after' => 0,
	'notice' => 1440, 'horizon' => 60, 'horizon_unit' => 'days', 'allow_contiguous' => false, 'allow_split' => false,
	'weekly' => $bookingDefaultWeekly,
));
$bookingTimezoneOptions = array();
foreach (DateTimeZone::listIdentifiers() as $bookingTimezoneName) {
	$bookingTimezoneOptions[$bookingTimezoneName] = $bookingTimezoneName;
}
$bookingDays = array(1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');

try {
	if (!\Dolibarr\CDav\BookingAvailability::hasWeeklySlot($bookingConfig)) {
		$bookingRequiredMinutes = (int) $bookingConfig['duration'] + (int) $bookingConfig['buffer_before'] + (int) $bookingConfig['buffer_after'];
		setEventMessages($langs->trans('CDavBookingWeeklyTooShort', $bookingRequiredMinutes), null, 'warnings');
	}
} catch (InvalidArgumentException $bookingException) {
	// An invalid submitted profile already has its native error message above.
}

llxHeader('', $langs->trans('CDavBooking'));
print load_fiche_titre($langs->trans('CDavBooking'), '', 'calendar');
print '<p>'.dol_escape_htmltag($langs->trans('CDavBookingAdminHelp')).'</p>';
print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
print '<input type="hidden" name="action" value="save_global">';
print '<input type="hidden" name="owner_id" value="'.$bookingOwnerId.'">';
print '<label for="CDAV_BOOKING_ENABLED">'.dol_escape_htmltag($langs->trans('CDavBookingGlobalEnabled')).'</label> ';
print $form->selectyesno('CDAV_BOOKING_ENABLED', getDolGlobalInt('CDAV_BOOKING_ENABLED'), 1);
print ' <button class="button" type="submit">'.dol_escape_htmltag($langs->trans('Save')).'</button>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingGlobalHelp')).'</p></form>';

print '<form method="get" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<label for="owner_id">'.dol_escape_htmltag($langs->trans('CDavBookingProfessional')).'</label> ';
print $form->select_dolusers($bookingOwnerId, 'owner_id', 0, null, 0, '', '', (string) $conf->entity,
	0, 0, '(u.statut:=:1) AND (u.fk_soc:is:NULL)');
print ' <button class="button" type="submit">'.dol_escape_htmltag($langs->trans('CDavBookingLoadProfile')).'</button></form>';

print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
print '<input type="hidden" name="action" value="save_profile">';
print '<input type="hidden" name="owner_id" value="'.$bookingOwnerId.'">';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.dol_escape_htmltag($langs->trans('CDavBookingProfileSettings')).'</th></tr>';
print '<tr><td><label for="profile_enabled">'.dol_escape_htmltag($langs->trans('CDavBookingProfileEnabled')).'</label></td><td>';
print $form->selectyesno('profile_enabled', !empty($bookingProfile['enabled']) ? 1 : 0, 1);
print '</td></tr>';
foreach (array('allow_contiguous' => 'CDavBookingAllowContiguous', 'allow_split' => 'CDavBookingAllowSplit') as $bookingPermission => $bookingPermissionLabel) {
	print '<tr><td><label for="'.$bookingPermission.'">'.dol_escape_htmltag($langs->trans($bookingPermissionLabel)).'</label></td><td>';
	print $form->selectyesno($bookingPermission, !empty($bookingConfig[$bookingPermission]) ? 1 : 0, 1);
	print '</td></tr>';
}
print '<tr><td colspan="2" class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingSelectionPermissionsHelp')).'</td></tr>';
print '<tr><td><label for="timezone">'.dol_escape_htmltag($langs->trans('CDavBookingTimezone')).'</label></td><td>';
print Form::selectarray('timezone', $bookingTimezoneOptions, $bookingConfig['timezone'], 0, 0, 0, 'required', 0, 0, 0, '', 'maxwidth400');
print '</td></tr>';
$bookingNumericFields = array(
	'duration' => array('CDavBookingDuration', 5, 480, 'CDavBookingMinutes'),
	'step' => array('CDavBookingStep', 5, 240, 'CDavBookingMinutes'),
	'buffer_before' => array('CDavBookingBufferBefore', 0, 240, 'CDavBookingMinutes'),
	'buffer_after' => array('CDavBookingBufferAfter', 0, 240, 'CDavBookingMinutes'),
	'notice' => array('CDavBookingNotice', 0, 43200, 'CDavBookingMinutes'),
	'horizon' => array('CDavBookingHorizon', 1, 180, 'CDavBookingDays'),
);
foreach ($bookingNumericFields as $bookingField => $bookingFieldSpec) {
	print '<tr><td><label for="'.$bookingField.'">'.dol_escape_htmltag($langs->trans($bookingFieldSpec[0])).'</label></td><td>';
	print '<input class="maxwidth100" type="number" required name="'.$bookingField.'" id="'.$bookingField.'" min="'.$bookingFieldSpec[1].'" max="'.$bookingFieldSpec[2].'" value="'.((int) $bookingConfig[$bookingField]).'"> ';
	if ($bookingField === 'horizon') {
		print '<label for="horizon_unit">'.dol_escape_htmltag($langs->trans('CDavBookingHorizonUnit')).'</label> ';
		print Form::selectarray('horizon_unit', array('days' => $langs->trans('CDavBookingDays'), 'months' => $langs->trans('CDavBookingMonths')),
			$bookingConfig['horizon_unit'] ?? 'days', 0, 0, 0, 'required');
		print '<br><span class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingHorizonHelp')).'</span>';
	} else {
		print dol_escape_htmltag($langs->trans($bookingFieldSpec[3]));
	}
	print '</td></tr>';
}
print '</table></div>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingTimingHelp')).'</p>';
print '<h2>'.dol_escape_htmltag($langs->trans('CDavBookingWeekly')).'</h2>';
print '<p>'.dol_escape_htmltag($langs->trans('CDavBookingWeeklyHelp')).'</p>';
print '<div class="div-table-responsive"><table class="noborder centpercent"><thead><tr class="liste_titre">';
print '<th scope="col">'.dol_escape_htmltag($langs->trans('Day')).'</th>';
print '<th scope="col">'.dol_escape_htmltag($langs->trans('CDavBookingRange1')).'</th>';
print '<th scope="col">'.dol_escape_htmltag($langs->trans('CDavBookingRange2')).'</th></tr></thead><tbody>';
foreach ($bookingDays as $bookingDay => $bookingDayKey) {
	print '<tr><th scope="row">'.dol_escape_htmltag($langs->trans($bookingDayKey)).'</th>';
	for ($bookingRange = 0; $bookingRange < 2; $bookingRange++) {
		print '<td>';
		foreach (array('start' => 0, 'end' => 1) as $bookingBound => $bookingBoundIndex) {
			$bookingInputId = 'weekly_'.$bookingDay.'_'.$bookingRange.'_'.$bookingBound;
			print '<label for="'.$bookingInputId.'">'.dol_escape_htmltag($langs->trans($bookingBound === 'start' ? 'CDavStartsAt' : 'CDavEndsAt')).'</label> ';
			print '<input type="time" step="60" name="'.$bookingInputId.'" id="'.$bookingInputId.'" value="'.dol_escape_htmltag($bookingConfig['weekly'][$bookingDay][$bookingRange][$bookingBoundIndex] ?? '').'"> ';
		}
		print '</td>';
	}
	print '</tr>';
}
print '</tbody></table></div>';
print '<p><button class="button button-save" type="submit">'.dol_escape_htmltag($langs->trans('CDavBookingSaveProfile')).'</button></p></form>';

print '<h2>'.dol_escape_htmltag($langs->trans('CDavBookingPublicLink')).'</h2>';
print '<p>'.dol_escape_htmltag($langs->trans('CDavBookingRotateHelp')).'</p>';
if ($bookingNewKey !== '') {
	$bookingLink = dol_buildpath('/cdav/public/booking.php', 2).'?key='.rawurlencode($bookingNewKey);
	print '<p><label for="booking_public_link">'.dol_escape_htmltag($langs->trans('CDavBookingCopyLink')).'</label></p>';
	print '<input class="quatrevingtpercent" type="url" readonly id="booking_public_link" value="'.dol_escape_htmltag($bookingLink).'">';
	print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingLinkOnce')).'</p>';
}
if ($bookingProfile) {
	print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
	print '<input type="hidden" name="owner_id" value="'.$bookingOwnerId.'">';
	print '<button class="button" type="submit" name="action" value="rotate_token">'.dol_escape_htmltag($langs->trans('CDavBookingRotate')).'</button> ';
	print '<button class="button" type="submit" name="action" value="disable_profile">'.dol_escape_htmltag($langs->trans('CDavBookingRevoke')).'</button>';
	print '</form>';
}
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingScopeHelp')).'</p>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingConcurrencyHelp')).'</p>';
llxFooter();
$db->close();
