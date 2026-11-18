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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once dirname(__DIR__).'/class/CDavBookingService.php';

$langs->loadLangs(array('admin', 'agenda', 'companies', 'projects', 'cdav@cdav'));
if (!$user->admin || !empty($user->socid) || !empty($user->design)
	|| !isModEnabled('cdav') || !isModEnabled('agenda')) {
	accessforbidden();
}
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

$form = new Form($db);
$bookingService = new \Dolibarr\CDav\BookingService($db, (int) $conf->entity);
$bookingIds = array('owner_id' => (int) $user->id, 'profile_id' => null);
foreach ($bookingIds as $bookingIdField => $bookingIdDefault) {
	if (!GETPOSTISSET($bookingIdField)) continue;
	$bookingRawId = GETPOST($bookingIdField, 'none');
	if (!is_string($bookingRawId) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $bookingRawId)
		|| (int) $bookingRawId > 2147483647 || ($bookingIdField === 'owner_id' && $bookingRawId === '0')) {
		accessforbidden($langs->trans('CDavBookingInvalid'));
	}
	$bookingIds[$bookingIdField] = (int) $bookingRawId;
}
$bookingOwnerId = $bookingIds['owner_id'];
$bookingProfileId = $bookingIds['profile_id'];
$action = GETPOST('action', 'aZ09');
if ($action === 'save_profile' && GETPOSTINT('refresh_projects') === 1) $action = 'refresh_profile';
$bookingNewKey = '';
$bookingPostedConfig = null;
$bookingPostedMetadata = null;
$bookingPostedNotice = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
	try {
		if ($action === 'save_global') {
			$bookingEnabled = GETPOSTINT('CDAV_BOOKING_ENABLED') === 1 ? '1' : '0';
			if (dolibarr_set_const($db, 'CDAV_BOOKING_ENABLED', $bookingEnabled, 'chaine', 0, '', $conf->entity) <= 0) {
				throw new RuntimeException('Unable to save the booking activation setting');
			}
			$conf->global->CDAV_BOOKING_ENABLED = $bookingEnabled;
			setEventMessages($langs->trans('CDavBookingSaved'), null, 'mesgs');
		} elseif ($action === 'save_profile' || $action === 'refresh_profile') {
			$bookingPostedNotice = array('value' => GETPOST('notice', 'none'),
				'unit' => GETPOSTISSET('notice_unit') ? GETPOST('notice_unit', 'none') : 'minutes');
			$bookingPostedMetadata = array();
			if (array_key_exists('profile_label', $_POST)) $bookingPostedMetadata['label'] = $_POST['profile_label'];
			foreach (array('thirdparty_id', 'project_id') as $bookingMetadataField) {
				if (!GETPOSTISSET($bookingMetadataField)) continue;
				$bookingRawLink = GETPOST($bookingMetadataField, 'none');
				// Native optional selectors use -1 or 0 for their empty choice.
				if ($bookingRawLink === '-1' || $bookingRawLink === '') $bookingRawLink = '0';
				if (!is_string($bookingRawLink) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $bookingRawLink)
					|| (int) $bookingRawLink > 2147483647) throw new InvalidArgumentException('Invalid booking business link');
				$bookingPostedMetadata[$bookingMetadataField] = (int) $bookingRawLink;
			}
			$bookingPostedConfig = array('timezone' => GETPOST('timezone', 'alphanohtml'),
				'horizon_unit' => GETPOSTISSET('horizon_unit') ? GETPOST('horizon_unit', 'aZ09') : 'days', 'weekly' => array());
			foreach (array('valid_from', 'valid_until') as $bookingValidityField) {
				if (array_key_exists($bookingValidityField, $_POST)) $bookingPostedConfig[$bookingValidityField] = $_POST[$bookingValidityField];
			}
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
			try {
				$bookingPostedConfig['notice'] = \Dolibarr\CDav\BookingAvailability::noticeMinutes($bookingPostedNotice['value'], $bookingPostedNotice['unit']);
				if ($bookingPostedNotice['unit'] === 'days') $bookingPostedConfig['notice_unit'] = 'days';
			} catch (InvalidArgumentException $bookingNoticeException) {
				// Refreshing optional projects must accept an unfinished form.
				if ($action !== 'refresh_profile') throw $bookingNoticeException;
			}
			if ($action === 'save_profile') {
				$bookingSavedProfile = $bookingService->saveProfile($bookingOwnerId, $bookingPostedConfig,
					GETPOSTINT('profile_enabled') === 1, $bookingProfileId, $bookingPostedMetadata);
				$bookingProfileId = $bookingSavedProfile['id'];
				$bookingPostedConfig = $bookingPostedMetadata = $bookingPostedNotice = null;
				setEventMessages($langs->trans('CDavBookingSaved'), null, 'mesgs');
			}
		} elseif ($action === 'rotate_token') {
			$bookingNewKey = $bookingService->rotateToken($bookingOwnerId, $bookingProfileId);
			setEventMessages($langs->trans('CDavBookingLinkCreated'), null, 'mesgs');
		} elseif ($action === 'disable_profile') {
			$bookingService->disableProfile($bookingOwnerId, $bookingProfileId);
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
$bookingProfiles = array();
$bookingProfileLoadFailed = false;
try {
	$bookingProfiles = $bookingService->profilesForOwner($bookingOwnerId);
	if ($bookingProfileId !== 0) $bookingProfile = $bookingService->profileForOwner($bookingOwnerId, $bookingProfileId);
	if ($bookingProfileId !== null && $bookingProfileId > 0 && !$bookingProfile) {
		throw new \Dolibarr\CDav\BookingException('CDavBookingNotFound', 404);
	}
	$bookingProfileId = (int) ($bookingProfile['id'] ?? 0);
} catch (Throwable $bookingException) {
	$bookingProfileLoadFailed = true;
	http_response_code($bookingException instanceof \Dolibarr\CDav\BookingException ? $bookingException->getCode() : 503);
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
$bookingMetadata = array_replace(array('label' => $bookingProfile['label'] ?? '',
	'thirdparty_id' => $bookingProfile['thirdparty_id'] ?? 0, 'project_id' => $bookingProfile['project_id'] ?? 0), $bookingPostedMetadata ?? array());
if (!is_string($bookingMetadata['label'])) $bookingMetadata['label'] = '';
if ($bookingMetadata['label'] === '' && $bookingProfile) $bookingMetadata['label'] = $langs->transnoentitiesnoconv('CDavBookingDefaultProfile');
$bookingProfileEnabled = $bookingPostedConfig !== null ? GETPOSTINT('profile_enabled') : (int) !empty($bookingProfile['enabled']);
$bookingThirdparty = new Societe($db);
$bookingThirdpartyId = (int) $bookingMetadata['thirdparty_id'];
if ($bookingThirdpartyId > 0 && ($bookingThirdparty->fetch($bookingThirdpartyId) <= 0
	|| (int) $bookingThirdparty->entity !== (int) $conf->entity)) {
	$bookingThirdpartyId = 0;
}
$bookingProjectId = $bookingThirdpartyId > 0 ? (int) $bookingMetadata['project_id'] : 0;
if ($action === 'refresh_profile') $bookingProjectId = 0;

try {
	if (!\Dolibarr\CDav\BookingAvailability::hasWeeklySlot($bookingConfig)) {
		$bookingRequiredMinutes = (int) $bookingConfig['duration'] + (int) $bookingConfig['buffer_before'] + (int) $bookingConfig['buffer_after'];
		setEventMessages($langs->trans('CDavBookingWeeklyTooShort', $bookingRequiredMinutes), null, 'warnings');
	}
} catch (InvalidArgumentException $bookingException) {
	// An invalid submitted profile already has its native error message above.
}

llxHeader('', $langs->trans('CDavBooking'), '', '', 0, 0, array('/cdav/js/booking_admin.js'));
print load_fiche_titre($langs->trans('CDavBooking'), '', 'calendar');
print '<p>'.dol_escape_htmltag($langs->trans('CDavBookingAdminHelp')).'</p>';
print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
print '<input type="hidden" name="action" value="save_global">';
print '<input type="hidden" name="owner_id" value="'.$bookingOwnerId.'">';
print '<input type="hidden" name="profile_id" value="'.$bookingProfileId.'">';
print '<label for="CDAV_BOOKING_ENABLED">'.dol_escape_htmltag($langs->trans('CDavBookingGlobalEnabled')).'</label> ';
print $form->selectyesno('CDAV_BOOKING_ENABLED', getDolGlobalInt('CDAV_BOOKING_ENABLED'), 1);
print ' <button class="button" type="submit">'.dol_escape_htmltag($langs->trans('Save')).'</button>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingGlobalHelp')).'</p></form>';

print '<form method="get" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<label for="owner_id">'.dol_escape_htmltag($langs->trans('CDavBookingProfessional')).'</label> ';
print $form->select_dolusers($bookingOwnerId, 'owner_id', 0, null, 0, '', '', (string) $conf->entity,
	0, 0, '(u.statut:=:1) AND (u.fk_soc:is:NULL)');
print ' <button class="button" type="submit">'.dol_escape_htmltag($langs->trans('CDavBookingLoadCollaborator')).'</button></form>';

print '<form method="get" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="owner_id" value="'.$bookingOwnerId.'">';
print '<label for="profile_id">'.dol_escape_htmltag($langs->trans('CDavBookingProfile')).'</label> ';
$bookingProfileOptions = array(0 => $langs->trans('CDavBookingNewProfile'));
foreach ($bookingProfiles as $bookingAvailableProfile) {
	$bookingProfileOptions[$bookingAvailableProfile['id']] = $bookingAvailableProfile['label'] ?: $langs->trans('CDavBookingDefaultProfile');
}
print Form::selectarray('profile_id', $bookingProfileOptions, $bookingProfileId, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 maxwidth400');
print ' <button class="button" type="submit">'.dol_escape_htmltag($langs->trans('CDavBookingLoadProfile')).'</button></form>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingProfilesHelp')).'</p>';

if ($bookingProfileLoadFailed) {
	llxFooter();
	$db->close();
	exit;
}

print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
print '<input type="hidden" name="action" value="save_profile">';
print '<input type="hidden" name="owner_id" value="'.$bookingOwnerId.'">';
print '<input type="hidden" name="profile_id" value="'.$bookingProfileId.'">';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="2">'.dol_escape_htmltag($langs->trans('CDavBookingProfileSettings')).'</th></tr>';
print '<tr><td><label for="profile_label">'.dol_escape_htmltag($langs->trans('CDavBookingProfileName')).'</label></td><td>';
print '<input type="text" required maxlength="120" class="minwidth200 maxwidth400" name="profile_label" id="profile_label" value="'.dol_escape_htmltag($bookingMetadata['label']).'">';
print '</td></tr>';
print '<tr><td><label for="thirdparty_id">'.dol_escape_htmltag($langs->trans('CDavBookingThirdparty')).'</label></td><td>';
if (isModEnabled('societe')) {
	print $form->select_company($bookingThirdpartyId, 'thirdparty_id', '', '1', 0, 0, array(), 0, 'minwidth200 maxwidth400');
	print ' <button class="button" type="submit" name="refresh_projects" value="1" formnovalidate>'.dol_escape_htmltag($langs->trans('CDavBookingLoadProjects')).'</button>';
} else {
	print '<input type="hidden" name="thirdparty_id" value="0">'.dol_escape_htmltag($langs->trans('None'));
}
print '</td></tr>';
print '<tr><td><label for="project_id">'.dol_escape_htmltag($langs->trans('CDavBookingProject')).'</label></td><td>';
if (isModEnabled('project') && $bookingThirdpartyId > 0) {
	$bookingFormProject = new FormProjets($db);
	print $bookingFormProject->select_projects($bookingThirdpartyId, (string) $bookingProjectId, 'project_id', 0, 0, 1, 0, 0, 0, 0, '', 1, 0,
		'minwidth200 maxwidth400', 'project_id', 'p.fk_soc = '.$bookingThirdpartyId);
} else {
	print '<input type="hidden" name="project_id" value="0"><span class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingProjectHelp')).'</span>';
}
print '</td></tr>';
print '<tr><td colspan="2" class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingLinksPrivate')).'</td></tr>';
print '<tr><td><label for="profile_enabled">'.dol_escape_htmltag($langs->trans('CDavBookingProfileEnabled')).'</label></td><td>';
print $form->selectyesno('profile_enabled', $bookingProfileEnabled, 1);
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
$bookingNoticeUnit = ($bookingPostedNotice['unit'] ?? $bookingConfig['notice_unit'] ?? 'minutes') === 'days' ? 'days' : 'minutes';
foreach ($bookingNumericFields as $bookingField => $bookingFieldSpec) {
	$bookingNumericValue = (string) ((int) $bookingConfig[$bookingField]);
	$bookingNumericStep = '1';
	if ($bookingField === 'notice') {
		if ($bookingNoticeUnit === 'days') {
			$bookingFieldSpec[2] = 30;
			$bookingNumericStep = 'any';
			$bookingNumericValue = rtrim(rtrim(number_format($bookingConfig['notice'] / 1440, 10, '.', ''), '0'), '.');
		}
		if (is_string($bookingPostedNotice['value'] ?? null)) $bookingNumericValue = $bookingPostedNotice['value'];
	}
	print '<tr><td><label for="'.$bookingField.'">'.dol_escape_htmltag($langs->trans($bookingFieldSpec[0])).'</label></td><td>';
	print '<input class="maxwidth100" type="number" required name="'.$bookingField.'" id="'.$bookingField.'" min="'.$bookingFieldSpec[1].'" max="'.$bookingFieldSpec[2].'" step="'.$bookingNumericStep.'" value="'.dol_escape_htmltag($bookingNumericValue).'"> ';
	if ($bookingField === 'horizon') {
		print '<label for="horizon_unit">'.dol_escape_htmltag($langs->trans('CDavBookingHorizonUnit')).'</label> ';
		print Form::selectarray('horizon_unit', array('days' => $langs->trans('CDavBookingDays'), 'months' => $langs->trans('CDavBookingMonths')),
			$bookingConfig['horizon_unit'] ?? 'days', 0, 0, 0, 'required');
		print '<br><span class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingHorizonHelp')).'</span>';
	} elseif ($bookingField === 'notice') {
		print '<label for="notice_unit">'.dol_escape_htmltag($langs->trans('CDavBookingNoticeUnit')).'</label> ';
		print Form::selectarray('notice_unit', array('minutes' => $langs->trans('CDavBookingMinutes'), 'days' => $langs->trans('CDavBookingDays')),
			$bookingNoticeUnit, 0, 0, 0, 'required');
		print '<br><span class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingNoticeHelp')).'</span>';
	} else {
		print dol_escape_htmltag($langs->trans($bookingFieldSpec[3]));
	}
	print '</td></tr>';
}
foreach (array('valid_from' => 'CDavBookingValidFrom', 'valid_until' => 'CDavBookingValidUntil') as $bookingValidityField => $bookingValidityLabel) {
	$bookingValidityValue = is_string($bookingConfig[$bookingValidityField] ?? null) ? $bookingConfig[$bookingValidityField] : '';
	print '<tr><td><label for="'.$bookingValidityField.'">'.dol_escape_htmltag($langs->trans($bookingValidityLabel)).'</label></td><td>';
	print '<input type="date" name="'.$bookingValidityField.'" id="'.$bookingValidityField.'" value="'.dol_escape_htmltag($bookingValidityValue).'">';
	print '</td></tr>';
}
print '<tr><td colspan="2" class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingValidityHelp')).'</td></tr>';
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
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingEmailSetupHelp')).'</p>';

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
	print '<input type="hidden" name="profile_id" value="'.$bookingProfileId.'">';
	print '<button class="button" type="submit" name="action" value="rotate_token">'.dol_escape_htmltag($langs->trans('CDavBookingRotate')).'</button> ';
	print '<button class="button" type="submit" name="action" value="disable_profile">'.dol_escape_htmltag($langs->trans('CDavBookingRevoke')).'</button>';
	print '</form>';
}
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingScopeHelp')).'</p>';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('CDavBookingConcurrencyHelp')).'</p>';
llxFooter();
$db->close();
