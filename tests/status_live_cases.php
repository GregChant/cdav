<?php

// Developer helpers are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Read the appointment state without retaining VObject parent/child cycles.
 * @param array $response Real HTTP calendar response
 * @return array Stable identity and appointment state
 */
function appointmentStatusLiveState(array $response): array
{
	liveHttpStatus($response, array(200), 'Appointment state GET');
	$calendar = \Sabre\VObject\Reader::read($response['body']);
	try {
		$event = $calendar->VEVENT;
		return array('uid' => (string) $event->UID, 'status' => (string) $event->STATUS,
			'sequence' => isset($event->SEQUENCE) ? (int) $event->SEQUENCE->getValue() : 0,
			'transparency' => (string) $event->TRANSP,
			'outlook' => isset($event->{'X-MICROSOFT-CDO-BUSYSTATUS'}) ? (string) $event->{'X-MICROSOFT-CDO-BUSYSTATUS'} : '');
	} finally {
		$calendar->destroy();
	}
}

/**
 * Change the native extra field through its transactional public API.
 * @param DoliDB $db Native isolated database
 * @param User $actor Real authenticated native actor
 * @param int $id Appointment identifier
 * @param string $status Requested native appointment state
 * @return int Native API result
 */
function appointmentStatusLiveNative($db, $actor, int $id, string $status): int
{
	$event = new ActionComm($db);
	liveAssert($event->fetch($id) > 0, 'Unable to load native appointment state');
	$event->array_options['options_cdav_status'] = $status;
	return (int) $event->updateExtraField('cdav_status', 'ACTION_MODIFY', $actor);
}

/**
 * Log into the real Agenda form using a transient in-memory cookie jar.
 * @param array $context Isolated HTTP context
 * @param int $id Appointment to edit
 * @param string|null $status Optional forged or legitimate form state to submit
 * @param bool|null $occupied Optional explicit availability checkbox edit
 * @return string Native response HTML
 */
function appointmentStatusLiveForm(array $context, int $id, ?string $status = null, ?bool $occupied = null): string
{
	$url = substr($context['base_url'], 0, strpos($context['base_url'], '/custom/cdav/')).'/comm/action/card.php?id='.$id.'&action=edit';
	$handle = curl_init($url);
	curl_setopt_array($handle, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5));
	try {
		$page = curl_exec($handle);
		liveAssert(is_string($page) && preg_match('/name="token"[^>]*value="([^"]+)"/', $page, $token),
			'Native Agenda login token missing');
		curl_setopt($handle, CURLOPT_POST, true);
		curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query(array('token' => html_entity_decode($token[1]),
			'actionlogin' => 'login', 'loginfunction' => 'loginfunction', 'username' => $context['login'], 'password' => $context['password'])));
		$page = curl_exec($handle);
		liveAssert(is_string($page) && (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200
			&& !str_contains($page, 'name="username"'), 'Unable to authenticate the native Agenda edit form');
		if ($status !== null) {
			$dom = new DOMDocument();
			liveAssert($dom->loadHTML($page, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR), 'Native Agenda form is unreadable');
			$xpath = new DOMXPath($dom);
			$pairs = array();
			foreach ($xpath->query('//form[@name="formaction"]//*[self::input or self::select or self::textarea]') as $field) {
				$name = $field->getAttribute('name');
				if ($name === '' || $field->hasAttribute('disabled') || $name === 'options_cdav_status') continue;
				if ($name === 'transparency' && $occupied === false) continue;
				$type = strtolower($field->getAttribute('type'));
				if (in_array($type, array('submit', 'button', 'reset', 'file'), true)
					|| (in_array($type, array('checkbox', 'radio'), true) && !$field->hasAttribute('checked')
						&& !($name === 'transparency' && $occupied === true))) continue;
				if ($field->tagName === 'select') {
					$selected = $xpath->query('.//option[@selected]', $field);
					if ($selected->length === 0 && !$field->hasAttribute('multiple')) $selected = $xpath->query('.//option[1]', $field);
					foreach ($selected as $option) $pairs[] = rawurlencode($name).'='.rawurlencode($option->getAttribute('value'));
				} else {
					$value = $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value');
					if (in_array($type, array('checkbox', 'radio'), true) && !$field->hasAttribute('value')) $value = 'on';
					$pairs[] = rawurlencode($name).'='.rawurlencode($value);
				}
			}
			$pairs[] = 'options_cdav_status='.rawurlencode($status);
			liveAssert(count($pairs) > 5, 'Native Agenda edit form has no editable submission');
			$formAction = $xpath->evaluate('string(//form[@name="formaction"]/@action)');
			liveAssert(str_starts_with($formAction, '/') && !str_starts_with($formAction, '//'), 'Native Agenda form action is outside the isolated application');
			$origin = substr($url, 0, strpos($url, '/comm/action/card.php'));
			curl_setopt($handle, CURLOPT_URL, $origin.$formAction);
			curl_setopt($handle, CURLOPT_POST, true);
			curl_setopt($handle, CURLOPT_POSTFIELDS, implode('&', $pairs));
			$page = curl_exec($handle);
			liveAssert(is_string($page) && in_array((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), array(200, 403), true),
				'The native Agenda form submission failed unexpectedly');
		}
		return $page;
	} finally {
		unset($handle);
	}
}

/**
 * Qualify native and DAV appointment state separately from an attendee answer.
 * @param DoliDB $db Isolated native database
 * @param User $organizer Native owner with Agenda rights
 * @param User $invitee Native attendee with own Agenda rights only
 * @param array $context Existing isolated credentials and paths
 * @param int $projectId Native protected project
 * @param int $contactId Native protected contact
 * @param array $events Native cleanup registry
 * @param array $proof Sanitized invitation proof
 * @return void
 */
function appointmentStatusLiveChecks($db, $organizer, $invitee, array $context, int $projectId, int $contactId, array &$events, array &$proof): void
{
	global $langs;
	$http = static function ($actor, $method, $path, $body = null, $extra = array()) use ($context) {
		return liveHttp($context['base_url'], $context[$actor === 'owner' ? 'login' : 'limited_login'],
			$context[$actor === 'owner' ? 'password' : 'limited_password'], $method, $path, $body,
			array_merge(array('Content-Type: text/calendar; charset=utf-8'), $extra));
	};
	$definition = liveRow($db, 'SELECT type, param FROM '.MAIN_DB_PREFIX."extrafields WHERE elementtype = 'actioncomm' AND name = 'cdav_status'");
	liveAssert($definition !== null && $definition->type === 'select', 'Native appointment state extra field was not installed');
	foreach (array('TENTATIVE', 'CONFIRMED', 'CANCELLED') as $status) {
		liveAssert(str_contains((string) $definition->param, $status), 'Native appointment state selector is incomplete');
	}
	$fixture = invitationLiveCreate($db, $organizer, $invitee, $context, $projectId, $contactId, $events);
	$id = $fixture['id'];
	// ActionComm has no protocol ORGANIZER API; remove only the fixture's sidecar
	// to exercise a genuinely native standalone appointment rather than an invitation.
	liveAssert((bool) $db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$id), 'Unable to prepare standalone native appointment');
	$current = $http('owner', 'GET', $fixture['organizer_path']);
	$initial = appointmentStatusLiveState($current);
	$protected = invitationLiveProtectedState($db, $id);
	liveAssert($initial['status'] === 'CONFIRMED', 'A native appointment without a selected state must remain confirmed');
	$form = appointmentStatusLiveForm($context, $id);
	liveAssert(str_contains($form, 'name="options_cdav_status"') && str_contains($form, 'value="TENTATIVE"')
		&& str_contains($form, 'value="CONFIRMED"') && str_contains($form, 'value="CANCELLED"'),
		'The real native Agenda edit form does not expose the three appointment states');
	$dom = new DOMDocument();
	liveAssert($dom->loadHTML($form, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR), 'Native Agenda edit form is unreadable');
	$xpath = new DOMXPath($dom);
	liveAssert($xpath->evaluate('string(//select[@name="options_cdav_status"]/option[@selected]/@value)') === 'CONFIRMED',
		'The native Agenda form selected a provisional state for an existing confirmed appointment');
	liveAssert($xpath->query('//form[@name="formaction"]//input[@name="transparency" and @checked]')->length === 1,
		'The native Agenda edit form lost its occupied availability checkbox');
	liveAssert(appointmentStatusLiveNative($db, $organizer, $id, 'TENTATIVE') > 0, 'Native owner could not select a provisional appointment');
	$current = $http('owner', 'GET', $fixture['organizer_path']);
	$native = appointmentStatusLiveState($current);
	liveAssert($native['status'] === 'TENTATIVE' && $native['uid'] === $initial['uid'] && $native['sequence'] > $initial['sequence']
		&& invitationLiveProtectedState($db, $id) === $protected, 'Native state edit lost business values or failed to advance the DAV revision');
	$dates = liveRow($db, 'SELECT datep,datep2 FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
	$start = (int) $db->jdate($dates->datep);
	$end = (int) $db->jdate($dates->datep2);
	liveAssert(!empty((new \Dolibarr\CDav\BookingCalendarReader($db, $organizer, $langs))->busy($start, $end, 'Europe/Zurich')),
		'A provisional occupied native appointment stopped blocking booking availability');
	$before = invitationLiveNoopState($db, $id);
	liveAssert(appointmentStatusLiveNative($db, $invitee, $id, 'CONFIRMED') < 0 && invitationLiveNoopState($db, $id) === $before,
		'An attendee changed the global appointment state through the native extra field API');
	foreach (array('CONFIRMED', 'TENTATIVE') as $status) {
		$body = businessLiveCalendar($current['body'], static function ($event) use ($status) {
			$event->STATUS = $status;
			$event->remove('X-MICROSOFT-CDO-BUSYSTATUS');
		});
		liveHttpStatus($http('owner', 'PUT', $fixture['organizer_path'], $body, array('If-Match: '.$current['headers']['etag'])),
			array(200, 204), 'AST standalone owner '.$status);
		$current = $http('owner', 'GET', $fixture['organizer_path']);
		$actual = appointmentStatusLiveState($current);
		$row = liveRow($db, 'SELECT cdav_status FROM '.MAIN_DB_PREFIX.'actioncomm_extrafields WHERE fk_object = '.$id);
		liveAssert($actual['status'] === $status && $row !== null && (string) $row->cdav_status === $status
			&& $actual['uid'] === $initial['uid'] && invitationLiveProtectedState($db, $id) === $protected,
			'A native owner DAV state update failed to synchronize its native extra field or changed business data');
	}
	// CalDav Synchronizer models Outlook Show As using the Microsoft extension.
	foreach (array('BUSY' => 'CONFIRMED', 'TENTATIVE' => 'TENTATIVE') as $outlook => $status) {
		$body = businessLiveCalendar($current['body'], static function ($event) use ($outlook) {
			$event->{'X-MICROSOFT-CDO-BUSYSTATUS'} = $outlook;
		});
		liveHttpStatus($http('owner', 'PUT', $fixture['organizer_path'], $body, array('If-Match: '.$current['headers']['etag'])),
			array(200, 204), 'AST Outlook Show As '.$outlook);
		$current = $http('owner', 'GET', $fixture['organizer_path']);
		$actual = appointmentStatusLiveState($current);
		liveAssert($actual['status'] === $status && $actual['outlook'] === $outlook && $actual['transparency'] === 'OPAQUE',
			'Outlook Show As failed to round-trip independently of the unchanged incoming STATUS');
	}
	$before = invitationLiveNoopState($db, $id);
	foreach (array('business' => static function ($event) {
		$event->STATUS = 'CONFIRMED';
		$event->SUMMARY = 'A state change must not authorize business edits';
	}, 'invalid status' => static function ($event) {
		$event->STATUS = 'PUBLISHED';
	}, 'ambiguous status' => static function ($event) {
		$event->add('STATUS', 'CONFIRMED');
	}) as $name => $change) {
		liveHttpStatus($http('owner', 'PUT', $fixture['organizer_path'], businessLiveCalendar($current['body'], $change)),
			$name === 'ambiguous status' ? array(400, 403, 415) : array(400, 403), 'AST rejected '.$name);
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'A rejected appointment state write had native or scheduling side effects');
	}
	$replay = $http('owner', 'PUT', $fixture['organizer_path'], $current['body'], array('If-Match: '.$current['headers']['etag']));
	liveHttpStatus($replay, array(200, 204), 'AST identical appointment state replay');
	liveAssert(invitationLiveNoopState($db, $id) === $before, 'A state replay rewrote native data or advanced its revision');
	liveHttpStatus($http('owner', 'PUT', $fixture['organizer_path'], $current['body'], array('If-Match: "obsolete-appointment-state"')),
		array(412), 'AST stale appointment state ETag');
	liveAssert(invitationLiveNoopState($db, $id) === $before, 'A stale state ETag caused side effects');
	$cancel = businessLiveCalendar($current['body'], static function ($event) {
		$event->STATUS = 'CANCELLED';
		$event->TRANSP = 'TRANSPARENT';
		$event->SUMMARY = 'Cancelled: '.((string) $event->SUMMARY);
		$event->remove('X-MICROSOFT-CDO-BUSYSTATUS');
	});
	liveHttpStatus($http('owner', 'PUT', $fixture['organizer_path'], $cancel, array('If-Match: '.$current['headers']['etag'])),
		array(200, 204), 'AST standalone native cancellation');
	$current = $http('owner', 'GET', $fixture['organizer_path']);
	$cancelled = appointmentStatusLiveState($current);
	liveAssert($cancelled['status'] === 'CANCELLED' && $cancelled['uid'] === $initial['uid'] && $cancelled['transparency'] === 'TRANSPARENT'
		&& (string) liveRow($db, 'SELECT cdav_status FROM '.MAIN_DB_PREFIX.'actioncomm_extrafields WHERE fk_object = '.$id)->cdav_status === 'CANCELLED'
		&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id.' AND transparency <> 0')->nb === 0
		&& empty((new \Dolibarr\CDav\BookingCalendarReader($db, $organizer, $langs))->busy($start, $end, 'Europe/Zurich')),
		'Cancelling a native standalone appointment failed to free all resources while retaining its identity');
	$afterCancel = invitationLiveNoopState($db, $id);
	$cancelReplay = businessLiveCalendar($current['body'], static function ($event) {
		$event->SUMMARY = 'Cancelled: '.((string) $event->SUMMARY);
	});
	liveHttpStatus($http('owner', 'PUT', $fixture['organizer_path'], $cancelReplay), array(200, 204), 'AST Outlook cancelled subject presentation replay');
	liveAssert(invitationLiveNoopState($db, $id) === $afterCancel, 'An Outlook cancellation presentation replay changed the native subject or advanced its revision');
	$restore = businessLiveCalendar($current['body'], static function ($event) {
		$event->STATUS = 'CONFIRMED';
		$event->TRANSP = 'OPAQUE';
	});
	liveHttpStatus($http('owner', 'PUT', $fixture['organizer_path'], $restore), array(403), 'AST cancelled native appointment cannot be restored by DAV');
	liveAssert(invitationLiveNoopState($db, $id) === $afterCancel, 'A DAV client restored a cancelled native appointment');
	$proof[] = array('case' => 'AST native standalone status', 'result' => 'PASS', 'native_form' => true,
		'outlook_show_as' => true, 'native_api_role_guard' => true, 'uid_stable' => true, 'cancelled_resources_free' => true);
	liveOk('AST native form and state API, standalone Outlook/DAV state, protection, replay, ETag and cancellation');
	$shared = invitationLiveCreate($db, $organizer, $invitee, $context, $projectId, $contactId, $events);
	$id = $shared['id'];
	$ownerGet = $http('owner', 'GET', $shared['organizer_path']);
	$sharedInitial = appointmentStatusLiveState($ownerGet);
	$attendeeGet = $http('attendee', 'GET', $shared['invitee_path']);
	$answer = invitationLiveAnswer($attendeeGet['body'], (string) $invitee->email, 'TENTATIVE', '-//CalDavSynchronizer//Outlook//EN');
	$answer = businessLiveCalendar($answer, static function ($event) {
		$event->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'TENTATIVE';
	});
	liveHttpStatus($http('attendee', 'PUT', $shared['invitee_path'], $answer, array('If-Match: '.$attendeeGet['headers']['etag'])),
		array(200, 204), 'AST individual Outlook tentative invitation answer');
	foreach (array('owner' => $shared['organizer_path'], 'attendee' => $shared['invitee_path']) as $actor => $path) {
		$actual = appointmentStatusLiveState($http($actor, 'GET', $path));
		liveAssert($actual['status'] === 'CONFIRMED' && $actual['uid'] === $sharedInitial['uid']
			&& $actual['sequence'] === $sharedInitial['sequence'] && $actual['outlook'] === ($actor === 'attendee' ? 'TENTATIVE' : 'BUSY'),
			'A tentative attendee answer changed the global state or failed to retain its personal Outlook availability');
	}
	liveAssert((string) liveRow($db, 'SELECT cdav_status FROM '.MAIN_DB_PREFIX.'actioncomm_extrafields WHERE fk_object = '.$id)->cdav_status === 'CONFIRMED',
		'An Outlook attendee Show As extension changed the global native appointment field');
	$protected = invitationLiveProtectedState($db, $id);
	$ownerGet = $http('owner', 'GET', $shared['organizer_path']);
	$tentative = businessLiveCalendar($ownerGet['body'], static function ($event) {
		$event->STATUS = 'TENTATIVE';
		$event->remove('X-MICROSOFT-CDO-BUSYSTATUS');
	});
	liveHttpStatus($http('owner', 'PUT', $shared['organizer_path'], $tentative, array('If-Match: '.$ownerGet['headers']['etag'])),
		array(200, 204), 'AST organizer provisional shared appointment');
	foreach (array('owner' => $shared['organizer_path'], 'attendee' => $shared['invitee_path']) as $actor => $path) {
		$actual = appointmentStatusLiveState($http($actor, 'GET', $path));
		liveAssert($actual['status'] === 'TENTATIVE' && $actual['uid'] === $sharedInitial['uid']
			&& $actual['sequence'] === $sharedInitial['sequence'] + 1, 'The organizer tentative state was not shared consistently across calendars');
	}
	liveAssert(invitationLiveProtectedState($db, $id) === $protected,
		'An organizer state transition replaced protected links, notes or participant answers');
	$before = invitationLiveNoopState($db, $id);
	$attendeeGet = $http('attendee', 'GET', $shared['invitee_path']);
	$forged = businessLiveCalendar($attendeeGet['body'], static function ($event) {
		$event->STATUS = 'CONFIRMED';
		$event->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'BUSY';
	});
	liveHttpStatus($http('attendee', 'PUT', $shared['invitee_path'], $forged), array(200, 204, 403), 'AST attendee cannot confirm a shared provisional appointment');
	liveAssert(invitationLiveNoopState($db, $id) === $before, 'An attendee confirmed a shared provisional appointment');
	$ownerGet = $http('owner', 'GET', $shared['organizer_path']);
	$confirmed = businessLiveCalendar($ownerGet['body'], static function ($event) {
		$event->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'BUSY';
	});
	liveHttpStatus($http('owner', 'PUT', $shared['organizer_path'], $confirmed, array('If-Match: '.$ownerGet['headers']['etag'])),
		array(200, 204), 'AST organizer Outlook Show As confirmed shared appointment');
	$actual = appointmentStatusLiveState($http('owner', 'GET', $shared['organizer_path']));
	liveAssert($actual['status'] === 'CONFIRMED' && $actual['sequence'] === $sharedInitial['sequence'] + 2
		&& invitationLiveProtectedState($db, $id) === $protected, 'Organizer Outlook confirmation changed business fields or failed to advance one revision');
	$dates = liveRow($db, 'SELECT datep,datep2 FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
	$pending = new ActionCommReminder($db);
	$pending->fk_actioncomm = $id;
	$pending->fk_user = (int) $invitee->id;
	$pending->typeremind = 'browser';
	$pending->offsetvalue = 20;
	$pending->offsetunit = 'i';
	$pending->status = 0;
	$pending->dateremind = (int) $db->jdate($dates->datep) - 1200;
	liveAssert($pending->create($organizer) > 0, 'Unable to create a pending native state reminder');
	$historical = clone $pending;
	$historical->id = 0;
	$historical->status = 1;
	$historical->offsetvalue = 30;
	$historical->dateremind = (int) $db->jdate($dates->datep) - 1800;
	liveAssert($historical->create($organizer) > 0, 'Unable to create a processed native state reminder');
	$historicalState = (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.((int) $historical->id));
	$protected = invitationLiveProtectedState($db, $id, 0, true);
	liveAssert(appointmentStatusLiveNative($db, $organizer, $id, 'CANCELLED') > 0, 'Native owner could not cancel a shared appointment');
	foreach (array('owner' => $shared['organizer_path'], 'attendee' => $shared['invitee_path']) as $actor => $path) {
		$actual = appointmentStatusLiveState($http($actor, 'GET', $path));
		liveAssert($actual['status'] === 'CANCELLED' && $actual['uid'] === $sharedInitial['uid']
			&& $actual['transparency'] === 'TRANSPARENT' && $actual['sequence'] === $sharedInitial['sequence'] + 3,
			'The native global cancellation was not exported identically to every assigned calendar');
	}
	liveAssert(invitationLiveProtectedState($db, $id, 0, true) === $protected
		&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id.' AND transparency <> 0')->nb === 0
		&& liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.((int) $pending->id)) === null
		&& (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.((int) $historical->id)) === $historicalState,
		'Native global cancellation changed business fields, retained pending reminders or destroyed reminder history');
	$proof[] = array('case' => 'AST shared invitation status', 'result' => 'PASS', 'individual_response_isolated' => true,
		'global_state_shared' => true, 'revision_increment_per_transition' => 1, 'pending_reminders_removed' => true, 'processed_reminders_preserved' => true);
	liveOk('AST shared state and Outlook organizer transitions, individual response isolation and native cancellation lifecycle');
	$ui = invitationLiveCreate($db, $organizer, $invitee, $context, $projectId, $contactId, $events);
	$id = $ui['id'];
	// The standard native Agenda form has no RFC invitation fields. Exercise it
	// on a plain native shared appointment, keeping the separate invitation tests above.
	liveAssert((bool) $db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$id), 'Unable to prepare the native form appointment');
	liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET mandatory = 1 WHERE fk_actioncomm = '.$id),
		'Unable to prepare non-default native participant metadata for the Agenda form');
	liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET answer_status = NULL WHERE fk_actioncomm = '.$id
		.' AND element_type = \'user\' AND fk_element = '.((int) $organizer->id)), 'Unable to prepare an unanswered native participant for the form');
	$linksSql = 'SELECT label,note,location,fk_soc,fk_project,fk_contact,fk_user_action FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id;
	$links = (array) liveRow($db, $linksSql);
	$assignments = businessLiveEventState($db, $id)['resources'];
	appointmentStatusLiveForm($context, $id, 'TENTATIVE');
	$actual = appointmentStatusLiveState($http('owner', 'GET', $ui['organizer_path']));
	liveAssert($actual['status'] === 'TENTATIVE', 'A real native Agenda edit POST failed to save the provisional state');
	liveAssert((array) liveRow($db, $linksSql) === $links, 'A real native state edit POST changed business links');
	liveAssert(businessLiveEventState($db, $id)['resources'] === $assignments,
		'A real native state edit POST changed participant metadata');
	$before = invitationLiveNoopState($db, $id);
	appointmentStatusLiveForm($context, $id, 'RESERVED');
	liveAssert(invitationLiveNoopState($db, $id) === $before,
		'An unsupported native form state changed appointment data instead of failing without side effects');
	$participantContext = $context;
	$participantContext['login'] = $context['limited_login'];
	$participantContext['password'] = $context['limited_password'];
	appointmentStatusLiveForm($participantContext, $id, 'CONFIRMED');
	liveAssert(appointmentStatusLiveState($http('owner', 'GET', $ui['organizer_path']))['status'] === 'TENTATIVE',
		'A forged native Agenda form POST by an attendee changed the global state');
	$uiReminder = new ActionCommReminder($db);
	$uiReminder->fk_actioncomm = $id;
	$uiReminder->fk_user = (int) $organizer->id;
	$uiReminder->typeremind = 'browser';
	$uiReminder->offsetvalue = 15;
	$uiReminder->offsetunit = 'i';
	$uiReminder->status = 0;
	$uiReminder->dateremind = (int) $db->jdate(liveRow($db, 'SELECT datep FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id)->datep) - 900;
	liveAssert($uiReminder->create($organizer) > 0, 'Unable to enable the native Agenda form reminder');
	appointmentStatusLiveForm($context, $id, 'CANCELLED');
	$actual = appointmentStatusLiveState($http('owner', 'GET', $ui['organizer_path']));
	liveAssert($actual['status'] === 'CANCELLED' && $actual['transparency'] === 'TRANSPARENT'
		&& (array) liveRow($db, $linksSql) === $links
		&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id.' AND transparency <> 0')->nb === 0
		&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE fk_actioncomm = '.$id.' AND status = 0')->nb === 0,
		'A real native Agenda cancellation POST failed to free its resources or lost native business links');
	$proof[] = array('case' => 'AST native Agenda HTTP POST', 'result' => 'PASS', 'owner_tentative' => true,
		'forged_attendee_state_blocked' => true, 'owner_cancelled' => true, 'business_links_preserved' => true);
	liveOk('AST real native Agenda edit POST, forged attendee state and owner cancellation');
	foreach (array(false, true) as $invitation) {
		$allDay = invitationLiveCreate($db, $organizer, $invitee, $context, $projectId, $contactId, $events);
		$id = $allDay['id'];
		if (!$invitation) liveAssert((bool) $db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$id), 'Unable to prepare the all-day standalone fixture');
		$event = new ActionComm($db);
		liveAssert($event->fetch($id) > 0, 'Unable to load the all-day state fixture');
		$zone = new DateTimeZone('Europe/Zurich');
		$event->datep = (new DateTimeImmutable('2026-10-24 00:00:00', $zone))->getTimestamp();
		$event->datef = (new DateTimeImmutable('2026-10-25 23:59:59', $zone))->getTimestamp();
		$event->fulldayevent = 1;
		liveAssert($event->update($organizer) > 0, 'Unable to save the native all-day fixture across DST');
		$sql = 'SELECT datep,datep2,fulldayevent,fk_soc,fk_project,fk_contact FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id;
		$nativeDates = (array) liveRow($db, $sql);
		$current = $http('owner', 'GET', $allDay['organizer_path']);
		$calendar = \Sabre\VObject\Reader::read($current['body']);
		try {
			$calendarDates = array((string) $calendar->VEVENT->DTSTART, (string) $calendar->VEVENT->DTEND);
			liveAssert((string) $calendar->VEVENT->DTSTART['VALUE'] === 'DATE' && (string) $calendar->VEVENT->DTEND['VALUE'] === 'DATE',
				'All-day state fixture is not exported as calendar DATE values');
		} finally {
			$calendar->destroy();
		}
		foreach (array('TENTATIVE', 'CONFIRMED', 'CANCELLED') as $status) {
			$body = businessLiveCalendar($current['body'], static function ($component) use ($status) {
				if ($status === 'CONFIRMED') $component->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'BUSY';
				else {
					$component->STATUS = $status;
					$component->remove('X-MICROSOFT-CDO-BUSYSTATUS');
				}
				if ($status === 'CANCELLED') {
					$component->TRANSP = 'TRANSPARENT';
					$component->SUMMARY = 'Cancelled: '.((string) $component->SUMMARY);
				}
			});
			liveHttpStatus($http('owner', 'PUT', $allDay['organizer_path'], $body, array('If-Match: '.$current['headers']['etag'])),
				array(200, 204), 'AST all-day state transition '.$status);
			$current = $http('owner', 'GET', $allDay['organizer_path']);
			$actual = appointmentStatusLiveState($current);
			$calendar = \Sabre\VObject\Reader::read($current['body']);
			try {
				liveAssert($actual['status'] === $status && (array) liveRow($db, $sql) === $nativeDates
					&& array((string) $calendar->VEVENT->DTSTART, (string) $calendar->VEVENT->DTEND) === $calendarDates,
					'A status-only all-day edit shifted native dates, DATE boundaries or business links across DST');
			} finally {
				$calendar->destroy();
			}
		}
	}
	$proof[] = array('case' => 'AST all-day native state', 'result' => 'PASS', 'standalone_and_invitation' => true,
		'dst_native_dates_preserved' => true, 'date_boundaries_preserved' => true);
	liveOk('AST standalone and invited all-day state changes preserve exact native dates and DATE boundaries across DST');
	$legacy = invitationLiveCreate($db, $organizer, $invitee, $context, $projectId, $contactId, $events);
	$id = $legacy['id'];
	$current = $http('owner', 'GET', $legacy['organizer_path']);
	$stored = businessLiveCalendar($current['body'], static function ($event) {
		$event->STATUS = 'TENTATIVE';
		$event->remove('X-MICROSOFT-CDO-BUSYSTATUS');
	});
	// Reproduce an appointment predating the optional field: retain the original
	// DAV state and leave the newly added SQL column empty, as migration does.
	liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'cdav_scheduling SET calendardata = \''.$db->escape($stored).'\' WHERE fk_actioncomm = '.$id)
		&& (bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_extrafields SET cdav_status = NULL WHERE fk_object = '.$id),
		'Unable to prepare an older appointment without a native state field');
	$before = invitationLiveNoopState($db, $id);
	liveAssert(\Dolibarr\CDav\AppointmentStatus::migrate($db) > 0, 'The native appointment field migration is not idempotent');
	liveAssert(appointmentStatusLiveState($http('owner', 'GET', $legacy['organizer_path']))['status'] === 'TENTATIVE',
		'An existing provisional DAV appointment became confirmed after the native field migration');
	$form = appointmentStatusLiveForm($context, $id);
	$dom = new DOMDocument();
	liveAssert($dom->loadHTML($form, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR), 'Legacy native Agenda form is unreadable');
	$xpath = new DOMXPath($dom);
	liveAssert($xpath->evaluate('string(//select[@name="options_cdav_status"]/option[@selected]/@value)') === 'TENTATIVE'
		&& invitationLiveNoopState($db, $id) === $before, 'Reading or migrating the native form replaced an existing provisional appointment');
	$proof[] = array('case' => 'AST legacy appointment migration', 'result' => 'PASS', 'provisional_state_preserved' => true,
		'idempotent_migration' => true, 'form_and_dav_reads_do_not_write' => true);
	liveOk('AST legacy provisional metadata survives idempotent field migration and native form/DAV reads without writes');
	$ownContext = $context;
	$ownContext['login'] = $context['limited_login'];
	$ownContext['password'] = $context['limited_password'];
	$ownContext['calendar_path'] = $context['limited_calendar_path'];
	$ownContext['limited_calendar_path'] = $context['calendar_path'];
	$own = invitationLiveCreate($db, $invitee, $organizer, $ownContext, 0, 0, $events);
	liveAssert(!$invitee->hasRight('agenda', 'allactions', 'write') && $invitee->hasRight('agenda', 'myactions', 'write'),
		'The own-Agenda owner fixture unexpectedly has all-Agenda write privileges');
	liveAssert(appointmentStatusLiveNative($db, $invitee, $own['id'], 'TENTATIVE') > 0,
		'A non-administrator native owner could not make its own appointment provisional');
	$current = $http('attendee', 'GET', $own['organizer_path']);
	liveAssert(appointmentStatusLiveState($current)['status'] === 'TENTATIVE'
		&& str_contains(appointmentStatusLiveForm($ownContext, $own['id']), 'name="options_cdav_status"'),
		'The native field or provisional state is unavailable to a non-administrator appointment owner');
	$confirmed = businessLiveCalendar($current['body'], static function ($component) {
		$component->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'BUSY';
	});
	liveHttpStatus($http('attendee', 'PUT', $own['organizer_path'], $confirmed, array('If-Match: '.$current['headers']['etag'])),
		array(200, 204), 'AST own-Agenda owner confirms its own invitation');
	liveAssert(appointmentStatusLiveState($http('attendee', 'GET', $own['organizer_path']))['status'] === 'CONFIRMED',
		'A non-administrator native owner required all-Agenda permissions to confirm its appointment');
	appointmentStatusLiveForm($ownContext, $own['id'], 'TENTATIVE', false);
	$resource = liveRow($db, 'SELECT transparency FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$own['id']
		.' AND element_type = \'user\' AND fk_element = '.((int) $invitee->id));
	$actual = appointmentStatusLiveState($http('attendee', 'GET', $own['organizer_path']));
	liveAssert($actual['status'] === 'TENTATIVE' && $actual['transparency'] === 'TRANSPARENT' && (int) $resource->transparency === 0,
		'A legitimate native unchecked availability edit was overridden by appointment state synchronization');
	$proof[] = array('case' => 'AST non-administrator native owner', 'result' => 'PASS', 'own_agenda_only' => true,
		'native_state_and_form' => true, 'outlook_global_transition' => true, 'explicit_unchecked_availability_respected' => true);
	liveOk('AST non-administrator owners use their own Agenda rights for native and Outlook state changes');
}
