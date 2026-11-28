<?php

// Developer helpers are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Read an attendee without relying on property order or a client's CN label.
 * @param object $component Native calendar component
 * @param string $email Native account email
 * @return object|null Matching attendee
 */
function invitationLiveAttendee($component, string $email)
{
	foreach ($component->select('ATTENDEE') as $attendee) {
		if (strcasecmp((string) $attendee, 'mailto:'.$email) === 0) return $attendee;
	}
	return null;
}

/**
 * Repeat one Outlook attendee, optionally with a conflicting participation status.
 * @param string $body Invitation with a single matching participant
 * @param string $email Participant's native address
 * @param string|null $otherStatus Optional response on the repeated property
 * @return string Invitation containing the repeated participant
 */
function invitationLiveDuplicateAttendee(string $body, string $email, ?string $otherStatus = null): string
{
	return businessLiveCalendar($body, static function ($component) use ($email, $otherStatus) {
		$attendee = invitationLiveAttendee($component, $email);
		liveAssert($attendee !== null, 'The fixture must contain the attendee being repeated');
		$duplicate = clone $attendee;
		$duplicate->setValue('MAILTO:'.strtoupper($email));
		if ($otherStatus !== null) $duplicate['PARTSTAT'] = $otherStatus;
		$component->add($duplicate);
	});
}

/**
 * Produce a client PUT that changes only the authenticated attendee's answer.
 * @param string $body Current calendar representation
 * @param string $email Native account email
 * @param string $status RFC participation status
 * @param string $client Client product identifier
 * @return string Client document
 */
function invitationLiveAnswer(string $body, string $email, string $status, string $client): string
{
	$calendar = \Sabre\VObject\Reader::read($body);
	$calendar->PRODID = $client;
	if (str_contains($client, 'CalDavSynchronizer')) $calendar->VEVENT->SEQUENCE = (int) $calendar->VEVENT->SEQUENCE->getValue() + 1;
	$attendee = invitationLiveAttendee($calendar->VEVENT, $email);
	liveAssert($attendee !== null, 'The native invitation does not contain its authenticated attendee');
	$attendee['PARTSTAT'] = $status;
	$attendee['RSVP'] = 'FALSE';
	return $calendar->serialize();
}

/**
 * Capture business fields which a personal answer or cancellation cannot rewrite.
 * @param DoliDB $db Native database
 * @param int $id Native appointment
 * @param int $respondent Personal resource permitted to change
 * @param bool $cancel Whether all resources may become transparent
 * @return array Protected native values
 */
function invitationLiveProtectedState($db, int $id, int $respondent = 0, bool $cancel = false): array
{
	$state = businessLiveEventState($db, $id);
	if ($cancel) unset($state['event']['transparency']);
	foreach ($state['resources'] as &$resource) {
		if ($cancel) unset($resource['transparency']);
		if ($resource['element_type'] === 'user' && (int) $resource['fk_element'] === $respondent) {
			unset($resource['answer_status'], $resource['transparency']);
		}
	}
	unset($resource);
	return $state;
}

/**
 * Snapshot native writes and scheduling delivery around a repeated response.
 * @param DoliDB $db Native database
 * @param int $id Appointment
 * @return array Native and technical values
 */
function invitationLiveNoopState($db, int $id): array
{
	$rows = $db->query('SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id.' ORDER BY rowid');
	liveAssert((bool) $rows, 'Unable to inspect invitation resource identities');
	$resources = array();
	while ($row = $db->fetch_object($rows)) $resources[] = (array) $row;
	return array('event' => (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id),
		'resources' => $resources,
		'appointment_status' => (array) liveRow($db, 'SELECT cdav_status FROM '.MAIN_DB_PREFIX.'actioncomm_extrafields WHERE fk_object = '.$id),
		'metadata' => (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$id),
		'journal_count' => (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_schedule_object')->nb);
}

/**
 * Read real local scheduling messages as their owner, without leaking CRM context.
 * @param DoliDB $db Native database
 * @param array $context Private fixture context
 * @param string $uid Native invitation UID
 * @param string $secret Project text inaccessible to this recipient
 * @return void
 */
function invitationLiveInboxPrivacy($db, array $context, string $uid, string $secret): void
{
	$result = $db->query('SELECT uri, calendardata FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE fk_principal = '
		.((int) $context['limited_user_id']).' AND direction = \'I\' ORDER BY rowid');
	liveAssert((bool) $result, 'Unable to inspect local invitation delivery');
	$matched = 0;
	while ($row = $db->fetch_object($result)) {
		$calendar = \Sabre\VObject\Reader::read((string) $row->calendardata);
		if (!isset($calendar->VEVENT) || (string) $calendar->VEVENT->UID !== $uid) continue;
		$matched++;
		$path = liveDavPath(array('calendars', $context['limited_login'], 'inbox', (string) $row->uri));
		$message = liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], 'GET', $path);
		liveHttpStatus($message, array(200), 'INV17 restricted recipient scheduling inbox');
		liveAssert(!str_contains($message['body'], $secret) && !isset($calendar->VEVENT->DESCRIPTION)
			&& !isset($calendar->VEVENT->{'X-ALT-DESC'}), 'Local scheduling delivery leaked an organizer-only project description');
	}
	liveAssert($matched > 0, 'No real invitation notification reached the restricted recipient inbox');
}

/**
 * Create a genuinely native shared appointment and its missing iCalendar metadata.
 * Native ActionComm has no ORGANIZER, SEQUENCE or external ATTENDEE API.
 * @param DoliDB $db Native database
 * @param User $organizer Native organizer
 * @param User $invitee Native respondent
 * @param array $context Private integration context
 * @param int $projectId Native project
 * @param int $contactId Native contact
 * @param array $events Cleanup registry
 * @param int $taskId Native task from which the invitation was created
 * @return array Native identifier and two calendar paths
 */
function invitationLiveCreate($db, $organizer, $invitee, array $context, int $projectId, int $contactId, array &$events, int $taskId = 0): array
{
	$event = new ActionComm($db);
	$event->type_code = $event->code = 'AC_RDV';
	$event->label = $context['prefix'].' Native invitation '.count($events);
	$event->location = 'Invitation test location';
	$event->note_private = '<p>Keep <strong>native business</strong> notes</p>';
	$event->datep = strtotime('+25 days 11:00 UTC') + count($events) * 7200;
	$event->datef = $event->datep + 3600;
	$event->percentage = -1;
	$event->priority = 2;
	$event->transparency = 1;
	$event->socid = (int) $context['company_id'];
	$event->fk_project = $projectId;
	if ($taskId > 0) {
		$event->elementtype = 'project_task';
		$event->elementid = $taskId;
	}
	$event->contact_id = $contactId;
	$event->socpeopleassigned = array($contactId => array('id' => $contactId));
	$event->userownerid = (int) $organizer->id;
	$event->userassigned = array((int) $organizer->id => array('id' => (int) $organizer->id, 'transparency' => 1),
		(int) $invitee->id => array('id' => (int) $invitee->id, 'transparency' => 1));
	$id = (int) $event->create($organizer);
	liveAssert($id > 0, 'Unable to create a native invitation appointment');
	$events[] = $id;
	// Native 23/24 cast RFC statuses to integers. Seed the existing resource API gap.
	liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET answer_status = \'NEEDS-ACTION\'
		WHERE fk_actioncomm = '.$id.' AND element_type = \'user\''), 'Unable to seed native invitation answers');
	liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET answer_status = \'ACCEPTED\'
		WHERE fk_actioncomm = '.$id.' AND element_type = \'user\' AND fk_element = '.((int) $organizer->id)),
		'Unable to seed the organizer resource');
	$uri = $id.'-ev-'.getDolGlobalString('CDAV_URI_KEY');
	$path = $context['calendar_path'].rawurlencode($uri);
	$get = liveHttp($context['base_url'], $context['login'], $context['password'], 'GET', $path);
	liveHttpStatus($get, array(200), 'INV native invitation initialization');
	$calendar = \Sabre\VObject\Reader::read($get['body']);
	$calendar->VEVENT->ORGANIZER = 'mailto:'.$organizer->email;
	$calendar->VEVENT->SEQUENCE = 0;
	$calendar->VEVENT->add('ATTENDEE', 'mailto:'.$invitee->email,
		array('CN' => 'Native collaborator', 'ROLE' => 'REQ-PARTICIPANT', 'PARTSTAT' => 'NEEDS-ACTION', 'RSVP' => 'TRUE'));
	$calendar->VEVENT->add('ATTENDEE', 'mailto:external-invitation@example.test',
		array('CN' => 'External participant', 'ROLE' => 'OPT-PARTICIPANT', 'PARTSTAT' => 'NEEDS-ACTION', 'RSVP' => 'TRUE'));
	liveAssert((bool) $db->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_scheduling (fk_actioncomm, calendardata) VALUES ('
		.$id.', \''.$db->escape($calendar->serialize()).'\')'), 'Unable to attach native invitation protocol metadata');
	liveAssert(liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$id) === null,
		'A native invitation fixture unexpectedly acquired DAV creation provenance');
	return array('id' => $id, 'organizer_path' => $path,
		'invitee_path' => $context['limited_calendar_path'].rawurlencode($uri));
}

/**
 * Import an external mailbox invitation through a private native user's DAV calendar.
 * No email transport, Outlook process or Android application is simulated here.
 * @param DoliDB $db Native database
 * @param User $organizer Internal fixture administrator
 * @param User $invitee Private calendar owner
 * @param array $context Private integration context
 * @param int $projectId Existing native project
 * @param int $contactId Existing native contact
 * @param array $events Native cleanup registry
 * @param array $proof Sanitized protocol results
 * @return void
 */
function invitationLiveExternalCases($db, $organizer, $invitee, array $context, int $projectId, int $contactId, array &$events, array &$proof): void
{
	global $langs;
	$http = static function ($method, $path, $body = null, $extra = array()) use ($context) {
		return liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], $method, $path, $body,
			array_merge(array('Content-Type: text/calendar; charset=utf-8'), $extra));
	};
	// A fictitious Outlook GlobalAppointmentID with data beyond 255 bytes.
	$uid = '040000008200E00074C5B7101A82E008'.strtoupper(str_repeat(hash('sha256', $context['token']), 5));
	$path = $context['limited_calendar_path'].rawurlencode($context['prefix'].'-external-mail.ics');
	$start = strtotime('+35 days 13:00 UTC');
	$end = $start + 3600;
	$raw = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CalDavSynchronizer//Outlook//EN\r\nBEGIN:VEVENT\r\nUID:".$uid
		."\r\nDTSTAMP:".gmdate('Ymd\THis\Z')."\r\nDTSTART:".gmdate('Ymd\THis\Z', $start)."\r\nDTEND:".gmdate('Ymd\THis\Z', $end)
		."\r\nSEQUENCE:0\r\nSUMMARY:External mailbox invitation\r\nDESCRIPTION:Mailbox invitation notes\r\nLOCATION:External meeting location";
	$raw .= "\r\nORGANIZER:mailto:office365-organizer@example.test\r\nATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:"
		.$invitee->email."\r\nSTATUS:CONFIRMED\r\nTRANSP:OPAQUE\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	$raw = \Sabre\VObject\Reader::read($raw)->serialize();
	$creationHeaders = array('If-None-Match: *', 'User-Agent: CalDavSynchronizer/4.5.0');
	foreach (array('' => 'The calendar UID is missing or ambiguous.', str_repeat('A', 1025) => 'The calendar UID exceeds 1024 bytes.') as $invalidUid => $message) {
		$invalid = businessLiveCalendar($raw, static function ($component) use ($invalidUid) {
			$component->UID = $invalidUid;
		});
		$rejected = $http('PUT', $path, $invalid, $creationHeaders);
		liveHttpStatus($rejected, array(400), 'INV20 invalid UID');
		liveAssert(liveDavErrorMessage($rejected) === $message, 'An invalid UID did not return its specific DAV error');
	}
	$mapSql = 'SELECT fk_object, dav_origin, sourceuid FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE sourceuid = \''.$db->escape($uid).'\'';
	$submittedConflict = 'The submitted invitation has conflicting ATTENDEE entries for the same calendar address.';
	$nativeCountSql = 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX."actioncomm WHERE label = 'External mailbox invitation'";
	$nativeCount = (int) liveRow($db, $nativeCountSql)->nb;
	$invalidCreate = $http('PUT', $path, invitationLiveDuplicateAttendee($raw, $invitee->email, 'DECLINED'), $creationHeaders);
	liveHttpStatus($invalidCreate, array(400), 'INV20 conflicting participants during Outlook invitation creation');
	liveAssert(liveDavErrorMessage($invalidCreate) === $submittedConflict && liveRow($db, $mapSql) === null
		&& (int) liveRow($db, $nativeCountSql)->nb === $nativeCount,
		'A conflicting invitation creation must identify the submitted data and leave no native appointment or UID mapping');
	liveHttpStatus($http('GET', $path), array(404), 'INV20 rejected Outlook invitation creation left no resource');
	liveHttpStatus($http('PUT', $path, invitationLiveDuplicateAttendee($raw, $invitee->email), $creationHeaders),
		array(201), 'INV20 Outlook long-UID invitation import with an equivalent duplicate');
	$mapping = liveRow($db, $mapSql);
	liveAssert($mapping !== null && (int) $mapping->dav_origin === 1, 'External import did not record immutable DAV creation provenance');
	$id = (int) $mapping->fk_object;
	$events[] = $id;
	$native = new ActionComm($db);
	liveAssert($native->fetch($id) > 0, 'Unable to fetch the imported native appointment');
	$native->socid = (int) $context['company_id'];
	$native->fk_project = $projectId;
	$native->contact_id = $contactId;
	$native->socpeopleassigned = array($contactId => array('id' => $contactId));
	liveAssert($native->update($organizer) > 0, 'Unable to attach native business context to the imported appointment');
	// Preserve the RFC answer across the native 23/24 integer-cast API gap.
	liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET answer_status = \'ACCEPTED\', mandatory = 1
		WHERE fk_actioncomm = '.$id.' AND element_type = \'user\' AND fk_element = '.((int) $invitee->id)),
		'Unable to prepare native imported invitation resource metadata');
	$before = invitationLiveNoopState($db, $id);
	$fresh = $http('GET', $path);
	liveHttpStatus($fresh, array(200), 'INV20 private imported invitation GET');
	$created = \Sabre\VObject\Reader::read($fresh['body'])->VEVENT;
	liveAssert((string) $created->UID === $uid && count($created->select('ATTENDEE')) === 1
		&& (string) invitationLiveAttendee($created, $invitee->email)['PARTSTAT'] === 'ACCEPTED',
		'GET must preserve the long Outlook UID and one canonical, accepted attendee');
	$equivalent = invitationLiveDuplicateAttendee($fresh['body'], $invitee->email);
	liveHttpStatus($http('PUT', $path, $equivalent, array('If-Match: '.$fresh['headers']['etag'])),
		array(200, 204), 'INV20 equivalent incoming attendee replay');
	liveAssert(invitationLiveNoopState($db, $id) === $before, 'An equivalent attendee replay changed the native meeting or scheduling state');
	$rejected = $http('PUT', $path, invitationLiveDuplicateAttendee($fresh['body'], $invitee->email, 'DECLINED'));
	liveHttpStatus($rejected, array(400), 'INV20 duplicate invitee');
	liveAssert(liveDavErrorMessage($rejected) === $submittedConflict
		&& invitationLiveNoopState($db, $id) === $before, 'The ambiguous invitee did not fail cleanly');
	for ($attempt = 0; $attempt < 2; $attempt++) {
		$accepted = invitationLiveAnswer($fresh['body'], (string) $invitee->email, 'ACCEPTED', '-//CalDavSynchronizer//Outlook//EN');
		liveHttpStatus($http('PUT', $path, $accepted, array('If-Match: '.$fresh['headers']['etag'])), array(200, 204), 'INV21 external accepted response replay');
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'An accepted external invitation replay changed native identity, resources or scheduling state');
	}
	$outlookHeader = 'User-Agent: CalDavSynchronizer/4.5.0';
	$availability = new \Dolibarr\CDav\BookingCalendarReader($db, $invitee, $langs);
	foreach (array(false, true) as $busy) {
		$body = businessLiveCalendar($fresh['body'], static function ($component) use ($busy) {
			if ($busy) {
				$component->TRANSP = 'OPAQUE';
				foreach (array('DESCRIPTION', 'LOCATION', 'PRIORITY') as $name) $component->remove($name);
			} else {
				$component->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'FREE';
				$component->DESCRIPTION = 'Mailbox invitation notes'; // A mail client may only retain the original note.
				$component->SEQUENCE = 1; // Outlook increments even when the organizer made no change.
			}
		});
		$headers = array($busy ? 'User-Agent: DAVx5' : $outlookHeader);
		liveHttpStatus($http('PUT', $path, $body, array_merge($headers, array('If-Match: '.$fresh['headers']['etag']))),
			array(200, 204), 'INV21 external personal availability');
		$fresh = $http('GET', $path);
		$personal = \Sabre\VObject\Reader::read($fresh['body'])->VEVENT;
		liveAssert((string) $personal->UID === $uid && (int) $personal->SEQUENCE->getValue() === 0
			&& (string) $personal->TRANSP === ($busy ? 'OPAQUE' : 'TRANSPARENT')
			&& (string) invitationLiveAttendee($personal, $invitee->email)['PARTSTAT'] === 'ACCEPTED'
			&& (bool) $availability->busy($start, $end, 'Europe/Zurich') === $busy, 'The imported meeting lost its response or personal free/busy slot');
		$expected = $before;
		foreach ($expected['resources'] as &$resource) {
			if ($resource['element_type'] === 'user' && (int) $resource['fk_element'] === (int) $invitee->id) $resource['transparency'] = (string) (int) $busy;
		}
		unset($resource);
		liveAssert(invitationLiveNoopState($db, $id) === $expected, 'Changing Show As on a mailbox copy rewrote the invitation, links, responses or scheduling journal');
		if (!$busy) {
			liveHttpStatus($http('PUT', $path, $body, $headers), array(200, 204), 'INV21 repeated Outlook Show As PUT');
			liveAssert(invitationLiveNoopState($db, $id) === $expected && $http('GET', $path)['headers']['etag'] === $fresh['headers']['etag'],
				'Retrying the same Outlook body toggled the availability or scheduled another operation');
		}
	}
	foreach (array('another-external-organizer@example.test', (string) $organizer->email) as $forgedEmail) {
		$forged = businessLiveCalendar($fresh['body'], static function ($component) use ($forgedEmail) {
			$component->ORGANIZER = 'mailto:'.$forgedEmail;
			$component->SUMMARY = 'Forged external organizer update';
		});
		liveHttpStatus($http('PUT', $path, $forged), array(403), 'INV22 imported organizer identity substitution');
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'A forged external organizer identity changed a native appointment');
	}
	$updatedStart = $start + 86400;
	$updatedEnd = $end + 86400;
	$update = businessLiveCalendar($raw, static function ($component) use ($updatedStart, $updatedEnd) {
		$component->SEQUENCE = 3;
		$component->SUMMARY = 'Updated external mailbox subject';
		$component->DESCRIPTION = 'Updated mailbox invitation notes';
		$component->LOCATION = 'Updated external meeting location';
		$component->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'FREE';
		$component->DTSTART->setDateTime(new DateTimeImmutable('@'.$updatedStart));
		$component->DTEND->setDateTime(new DateTimeImmutable('@'.$updatedEnd));
	});
	liveHttpStatus($http('PUT', $path, $update, array($outlookHeader, 'If-Match: '.$fresh['headers']['etag'])), array(200, 204), 'INV23 external organizer email update and personal availability');
	$changed = liveRow($db, 'SELECT label, note, datep, datep2, fk_soc, fk_project, fk_contact FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
	liveAssert($changed !== null && (string) $changed->label === 'Updated external mailbox subject'
		&& (string) $changed->note === 'Updated mailbox invitation notes' && (int) $db->jdate($changed->datep) === $updatedStart
		&& (int) $db->jdate($changed->datep2) === $updatedEnd && (int) $changed->fk_soc === (int) $context['company_id']
		&& (int) $changed->fk_project === $projectId && (int) $changed->fk_contact === $contactId,
		'External organizer update lost the native identity, new details or protected business links');
	$actual = $http('GET', $path);
	liveHttpStatus($actual, array(200), 'INV23 external organizer update readback');
	$component = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
	liveAssert((string) $component->UID === $uid && (int) $component->SEQUENCE->getValue() === 3
		&& $component->DTSTART->getDateTime()->getTimestamp() === $updatedStart && (string) $component->SUMMARY === 'Updated external mailbox subject'
		&& (string) $component->ORGANIZER === 'mailto:office365-organizer@example.test' && (string) $component->TRANSP === 'TRANSPARENT'
		&& (string) invitationLiveAttendee($component, $invitee->email)['PARTSTAT'] === 'ACCEPTED'
		&& !$availability->busy($updatedStart, $updatedEnd, 'Europe/Zurich') && invitationLiveNoopState($db, $id)['journal_count'] === $before['journal_count']
		&& (int) liveRow($db, $mapSql)->fk_object === $id
		&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE sourceuid = \''.$db->escape($uid).'\'')->nb === 1,
		'An external email update created a second appointment or changed its UID/revision');
	$before = invitationLiveNoopState($db, $id);
	$stale = invitationLiveAnswer($fresh['body'], (string) $invitee->email, 'TENTATIVE', '-//DAVx5//EN');
	liveHttpStatus($http('PUT', $path, $stale, array('If-Match: '.$actual['headers']['etag'])), array(403, 409, 412), 'INV23 stale external invitation response');
	liveAssert(invitationLiveNoopState($db, $id) === $before, 'An old external invitation restored stale dates or business details');
	foreach (array(4 => null, 5 => 'OPAQUE') as $sequence => $organizerTransparency) {
		$revision = businessLiveCalendar($update, static function ($component) use ($sequence, $organizerTransparency) {
			$component->SEQUENCE = $sequence;
			$component->LOCATION = 'External room '.$sequence;
			$component->remove('X-MICROSOFT-CDO-BUSYSTATUS');
			if ($organizerTransparency === null) $component->remove('TRANSP');
			else $component->TRANSP = $organizerTransparency;
		});
		liveHttpStatus($http('PUT', $path, $revision, array($outlookHeader, 'If-Match: '.$actual['headers']['etag'])),
			array(200, 204), 'INV23 independent external room revision while the private copy is free');
		$actual = $http('GET', $path);
		$component = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
		liveAssert((int) $component->SEQUENCE->getValue() === $sequence && (string) $component->LOCATION === 'External room '.$sequence
			&& (string) $component->TRANSP === 'TRANSPARENT' && (string) invitationLiveAttendee($component, $invitee->email)['PARTSTAT'] === 'ACCEPTED'
			&& !$availability->busy($updatedStart, $updatedEnd, 'Europe/Zurich') && invitationLiveNoopState($db, $id)['journal_count'] === $before['journal_count'],
			'An external room change reset the participant availability, lost the revision or sent a reply');
		$revisionState = invitationLiveNoopState($db, $id);
		liveHttpStatus($http('PUT', $path, $revision, array($outlookHeader)), array(200, 204), 'INV23 repeated independent external revision');
		$replayedState = invitationLiveNoopState($db, $id);
		foreach ($revisionState as $field => $value) {
			liveAssert($replayedState[$field] === $value, 'Retry of organizer revision '.$sequence.' changed '.$field);
		}
		liveAssert($http('GET', $path)['headers']['etag'] === $actual['headers']['etag'], 'Retry of organizer revision '.$sequence.' changed its ETag');
	}
	$cancel = businessLiveCalendar($update, static function ($component) {
		$component->SEQUENCE = 6;
		$component->STATUS = 'CANCELLED';
		$component->TRANSP = 'TRANSPARENT';
	});
	liveHttpStatus($http('PUT', $path, $cancel), array(200, 204), 'INV24 external cancellation import');
	$actual = $http('GET', $path);
	$component = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
	$cancelled = liveRow($db, 'SELECT id, fk_soc, fk_project, fk_contact, transparency FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
	liveAssert($cancelled !== null && (string) $component->UID === $uid && (string) $component->STATUS === 'CANCELLED'
		&& (int) $component->SEQUENCE->getValue() === 6 && (int) $cancelled->fk_soc === (int) $context['company_id']
		&& (int) $cancelled->fk_project === $projectId && (int) $cancelled->fk_contact === $contactId && (int) $cancelled->transparency === 0
		&& (string) invitationLiveAttendee($component, $invitee->email)['PARTSTAT'] === 'ACCEPTED'
		&& invitationLiveNoopState($db, $id)['journal_count'] === $before['journal_count']
		&& (new \Dolibarr\CDav\BookingCalendarReader($db, $invitee, $langs))->busy($updatedStart, $updatedEnd, 'Europe/Zurich') === array(),
		'An imported cancellation lost native links/identity or continued blocking availability');
	$internalUid = $context['prefix'].'-local-organizer-private-uid';
	$internalPath = $context['limited_calendar_path'].rawurlencode($context['prefix'].'-local-organizer-private.ics');
	$internal = businessLiveCalendar($raw, static function ($component) use ($internalUid, $organizer) {
		$component->UID = $internalUid;
		$component->ORGANIZER = 'mailto:'.$organizer->email;
	});
	liveHttpStatus($http('PUT', $internalPath, $internal), array(201, 204), 'INV25 private copy with a local organizer');
	$localMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE sourceuid = \''.$db->escape($internalUid).'\'');
	liveAssert($localMap !== null, 'Unable to identify the native private local-organizer appointment');
	$localId = (int) $localMap->fk_object;
	$events[] = $localId;
	$before = invitationLiveNoopState($db, $localId);
	$internalUpdate = businessLiveCalendar($internal, static function ($component) {
		$component->SEQUENCE = 1;
		$component->SUMMARY = 'A local invitation must not inherit the email-import exception';
		$component->DTSTART->setDateTime($component->DTSTART->getDateTime()->modify('+1 day'));
		$component->DTEND->setDateTime($component->DTEND->getDateTime()->modify('+1 day'));
	});
	liveHttpStatus($http('PUT', $internalPath, $internalUpdate), array(403), 'INV25 local organizer import bypass rejected');
	liveAssert(invitationLiveNoopState($db, $localId) === $before, 'A local invitation gained external organizer import authority');
	$proof[] = array('case' => 'external mailbox import INV20-INV25', 'result' => 'PASS', 'native_uid_stable' => true, 'duplicate_appointments' => 0);
	liveOk('INV20-INV25 long Outlook UID round-trip, invalid UID and attendee diagnostics, external updates and local identity isolation');
}

/**
 * Exercise native Agenda delegation without impersonating attendee responses.
 * @param DoliDB $db Native database
 * @param User $delegate Account with explicit all-agenda rights
 * @param User $owner Native organizer of calendar B
 * @param array $context Private integration context
 * @param int $projectId Existing native project
 * @param int $contactId Existing native contact
 * @param array $events Native cleanup registry
 * @param array $proof Sanitized results
 * @return void
 */
function invitationLiveDelegationCases($db, $delegate, $owner, array $context, int $projectId, int $contactId, array &$events, array &$proof): void
{
	liveAssert($delegate->hasRight('agenda', 'allactions', 'write') && $delegate->hasRight('agenda', 'allactions', 'delete'),
		'Native delegation requires explicit all-agenda write and delete rights');
	$inverted = $context;
	$inverted['login'] = $context['limited_login'];
	$inverted['password'] = $context['limited_password'];
	$inverted['calendar_path'] = $context['limited_calendar_path'];
	$inverted['limited_calendar_path'] = $context['calendar_path'];
	$http = static function ($method, $path, $body = null, $extra = array()) use ($context) {
		return liveHttp($context['base_url'], $context['login'], $context['password'], $method, $path, $body,
			array_merge(array('Content-Type: text/calendar; charset=utf-8'), $extra));
	};
	$fixture = invitationLiveCreate($db, $owner, $delegate, $inverted, $projectId, $contactId, $events);
	$id = $fixture['id'];
	$nativeState = businessLiveEventState($db, $id);
	$fresh = $http('GET', $fixture['organizer_path']);
	liveHttpStatus($fresh, array(200), 'INV26 delegated calendar B invitation GET');
	$original = \Sabre\VObject\Reader::read($fresh['body'])->VEVENT;
	$uid = (string) $original->UID;
	$sequence = (int) $original->SEQUENCE->getValue();
	$before = invitationLiveNoopState($db, $id);
	$forgedReply = invitationLiveAnswer($fresh['body'], (string) $delegate->email, 'ACCEPTED', '-//DAVx5//EN');
	liveHttpStatus($http('PUT', $fixture['organizer_path'], $forgedReply), array(403), 'INV26 personal replies do not use another calendar');
	liveAssert(invitationLiveNoopState($db, $id) === $before, 'Calendar delegation impersonated a personal attendee response');
	$move = businessLiveCalendar($fresh['body'], static function ($component) {
		$component->DTSTART->setDateTime($component->DTSTART->getDateTime()->modify('+1 day'));
		$component->DTEND->setDateTime($component->DTEND->getDateTime()->modify('+1 day'));
	});
	liveHttpStatus($http('PUT', $fixture['organizer_path'], $move, array('If-Match: '.$fresh['headers']['etag'])),
		array(200, 204), 'INV26 delegated native organizer date update');
	liveAssert(businessLiveEventState($db, $id) === $nativeState, 'A delegated move changed native ownership, participant responses or business context');
	$actual = $http('GET', $fixture['organizer_path']);
	$component = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
	liveAssert((string) $component->UID === $uid && (int) $component->SEQUENCE->getValue() > $sequence
		&& strcasecmp((string) $component->ORGANIZER, 'mailto:'.$owner->email) === 0,
		'Delegation changed the invitation identity/organizer or did not advance its revision');
	$deliveries = $db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE fk_principal = '.((int) $delegate->id)
		.' AND direction = \'I\' ORDER BY rowid DESC');
	liveAssert((bool) $deliveries, 'Unable to inspect delegated native scheduling delivery');
	$delivered = false;
	while ($row = $db->fetch_object($deliveries)) {
		$message = \Sabre\VObject\Reader::read((string) $row->calendardata);
		if (!isset($message->VEVENT) || (string) $message->VEVENT->UID !== $uid) continue;
		if ((string) $message->METHOD === 'REQUEST' && strcasecmp((string) $message->VEVENT->ORGANIZER, 'mailto:'.$owner->email) === 0) $delivered = true;
	}
	liveAssert($delivered, 'A delegated update was not scheduled under the proven native organizer identity');
	$beforeCancel = invitationLiveProtectedState($db, $id, 0, true);
	$cancel = businessLiveCalendar($actual['body'], static function ($component) {
		$component->STATUS = 'CANCELLED';
		$component->TRANSP = 'TRANSPARENT';
	});
	liveHttpStatus($http('PUT', $fixture['organizer_path'], $cancel), array(200, 204), 'INV26 delegated organizer cancellation');
	liveAssert(invitationLiveProtectedState($db, $id, 0, true) === $beforeCancel, 'A delegated cancellation changed native business links or participant identities');
	$ownerView = liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], 'GET', $fixture['organizer_path']);
	liveHttpStatus($ownerView, array(200), 'INV26 native owner sees delegated cancellation');
	liveAssert((string) \Sabre\VObject\Reader::read($ownerView['body'])->VEVENT->STATUS === 'CANCELLED', 'The native owner did not receive its delegated cancellation');
	$deleted = invitationLiveCreate($db, $owner, $delegate, $inverted, $projectId, $contactId, $events);
	liveHttpStatus($http('DELETE', $deleted['organizer_path']), array(200, 204), 'INV26 delegated organizer deletion');
	liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$deleted['id']) === null,
		'Explicit native all-agenda delete rights only detached the organizer instead of deleting its shared invitation');
	$proof[] = array('case' => 'native delegation INV26', 'result' => 'PASS', 'personal_reply_impersonation' => false);
	liveOk('INV26 native all-agenda delegation moves/cancels/deletes without attendee impersonation');
}

/**
 * Force two real DAV workers to overlap before the first personal answer commits.
 * @param DoliDB $db Native database
 * @param User $organizer Native organizer
 * @param User $invitee Responding native user
 * @param array $context Private integration context
 * @param int $projectId Existing native project
 * @param int $contactId Existing native contact
 * @param array $events Native cleanup registry
 * @param array $proof Sanitized results
 * @return void
 */
function invitationLiveConcurrentCase($db, $organizer, $invitee, array $context, int $projectId, int $contactId, array &$events, array &$proof): void
{
	global $dolibarr_main_db_name;
	$fixture = invitationLiveCreate($db, $organizer, $invitee, $context, $projectId, $contactId, $events);
	$id = $fixture['id'];
	$label = (string) liveRow($db, 'SELECT label FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id)->label;
	$get = liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], 'GET', $fixture['invitee_path']);
	liveHttpStatus($get, array(200), 'INV27 concurrent participant invitation GET');
	$component = \Sabre\VObject\Reader::read($get['body'])->VEVENT;
	$uid = (string) $component->UID;
	$sequence = (int) $component->SEQUENCE->getValue();
	$body = invitationLiveAnswer($get['body'], (string) $invitee->email, 'ACCEPTED', '-//DAVx5//EN');
	$before = invitationLiveProtectedState($db, $id, (int) $invitee->id);
	$journalStart = (int) liveRow($db, 'SELECT COALESCE(MAX(rowid),0) AS id FROM '.MAIN_DB_PREFIX.'cdav_schedule_object')->id;
	liveAssert((bool) $db->begin() && liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id.' FOR UPDATE') !== null,
		'Unable to hold the real native concurrent-answer barrier');
	$released = false;
	$overlappingWorkers = 0;
	try {
		$request = array('method' => 'PUT', 'path' => $fixture['invitee_path'], 'body' => $body,
			'headers' => array('Content-Type: text/calendar; charset=utf-8'));
		$responses = liveHttpConcurrent($context['base_url'], $context['limited_login'], $context['limited_password'], array($request, $request),
			static function ($multi) use ($db, $id, $invitee, $dolibarr_main_db_name, &$released, &$overlappingWorkers) {
				$deadline = microtime(true) + 10;
				do {
					liveAssert(curl_multi_exec($multi, $running) === CURLM_OK, 'Unable to dispatch concurrent native invitation answers');
					$processes = $db->query('SHOW FULL PROCESSLIST');
					liveAssert((bool) $processes, 'Unable to observe concurrent invitation workers');
					$workers = array();
					while ($process = $db->fetch_object($processes)) {
						$sql = (string) ($process->Info ?? '');
						if (($process->db ?? '') !== $dolibarr_main_db_name || stripos($sql, 'FOR UPDATE') === false) continue;
						$eventLock = str_contains($sql, MAIN_DB_PREFIX.'actioncomm') && preg_match('/\ba\.id\s*=\s*'.$id.'\b/', $sql);
						$ownerLock = str_contains($sql, MAIN_DB_PREFIX.'user') && preg_match('/\browid\s*=\s*'.((int) $invitee->id).'\b/', $sql);
						if ($eventLock || $ownerLock) $workers[(int) $process->Id] = true;
					}
					$overlappingWorkers = count($workers);
					if ($overlappingWorkers >= 2) break;
					curl_multi_select($multi, 0.02);
				} while ($running && microtime(true) < $deadline);
				liveAssert($overlappingWorkers >= 2, 'Two native DAV workers did not overlap at the real appointment/owner locks');
				liveAssert((bool) $db->commit(), 'Unable to release the concurrent native answer barrier');
				$released = true;
			});
	} finally {
		if (!$released) $db->rollback();
	}
	foreach ($responses as $response) liveHttpStatus($response, array(200, 204), 'INV27 simultaneous unconditioned ACCEPTED PUT');
	$actual = liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], 'GET', $fixture['invitee_path']);
	$component = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
	$nativeAnswer = liveRow($db, 'SELECT answer_status, transparency FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id
		.' AND element_type = \'user\' AND fk_element = '.((int) $invitee->id));
	liveAssert((string) $component->UID === $uid && (int) $component->SEQUENCE->getValue() === $sequence
		&& (string) $nativeAnswer->answer_status === 'ACCEPTED' && (int) $nativeAnswer->transparency === 1
		&& invitationLiveProtectedState($db, $id, (int) $invitee->id) === $before
		&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE label = \''.$db->escape($label).'\'')->nb === 1,
		'Concurrent answers duplicated the appointment or changed identity, organizer revision or unrelated native data');
	$messages = $db->query('SELECT fk_principal, direction, calendardata FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE rowid > '.$journalStart);
	liveAssert((bool) $messages, 'Unable to inspect concurrent-answer scheduling delivery');
	$count = 0;
	$organizerReplies = 0;
	while ($message = $db->fetch_object($messages)) {
		$calendar = \Sabre\VObject\Reader::read((string) $message->calendardata);
		if (!isset($calendar->VEVENT) || (string) $calendar->VEVENT->UID !== $uid) continue;
		$count++;
		if ((int) $message->fk_principal === (int) $organizer->id && $message->direction === 'I' && (string) $calendar->METHOD === 'REPLY') $organizerReplies++;
	}
	liveAssert($organizerReplies === 1 && $count <= 4, 'Concurrent identical answers duplicated the organizer reply or party notifications');
	$proof[] = array('case' => 'concurrent native answer INV27', 'result' => 'PASS', 'overlapping_workers' => $overlappingWorkers,
		'http' => array_column($responses, 'status'), 'organizer_replies' => $organizerReplies, 'new_schedule_rows' => $count);
	liveOk('INV27 two overlapping unconditioned ACCEPTED PUTs keep one native appointment and one organizer reply');
}

/**
 * Qualify two client PUT styles, native invitation answers, updates and cancellations.
 * These are real DAV HTTP tests, not automated Outlook or Android application runs.
 * @param DoliDB $db Native database
 * @param User $organizer Native organizer
 * @param User $admin Native fixture administrator
 * @param array $context Existing isolated suite credentials and paths
 * @param bool $calendarClientsOnly Run the native editor and external client workflows only
 * @return void
 */
function invitationLiveCases($db, $organizer, $admin, array $context, bool $calendarClientsOnly = false): void
{
	global $langs;
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
	require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
	require_once dirname(__DIR__).'/class/CDavBookingCalendarReader.php';
	$invitee = new User($db);
	liveAssert($invitee->fetch((int) $context['limited_user_id']) > 0 && $invitee->loadRights('', 1) >= 0
		&& $invitee->hasRight('agenda', 'myactions', 'write'), 'The native invitation respondent lacks own Agenda write rights');
	$events = array();
	$contact = null;
	$project = null;
	$task = null;
	$proof = array();
	$headers = array('Content-Type: text/calendar; charset=utf-8');
	$http = static function ($actor, $method, $path, $body = null, $extra = array()) use ($context, $headers) {
		$login = $actor === 'organizer' ? $context['login'] : $context['limited_login'];
		$password = $actor === 'organizer' ? $context['password'] : $context['limited_password'];
		return liveHttp($context['base_url'], $login, $password, $method, $path, $body, array_merge($headers, $extra));
	};
	try {
		$contact = new Contact($db);
		$contact->lastname = $context['prefix'].' Invitation contact';
		$contact->socid = (int) $context['company_id'];
		liveAssert($contact->create($organizer) > 0, 'Unable to create the native invitation contact');
		$project = new Project($db);
		$project->ref = 'DAVINV-'.$context['token'];
		$project->title = $context['prefix'].' Invitation project';
		$project->socid = (int) $context['company_id'];
		$project->public = 0;
		$projectSecret = 'PRIVATE-PROJECT-'.$context['token'];
		$project->description = '<p>'.$projectSecret.'</p>';
		liveAssert($project->create($organizer) > 0 && $project->setValid($organizer) > 0, 'Unable to open the native invitation project');
		require_once __DIR__.'/status_live_cases.php';
		appointmentStatusLiveChecks($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events, $proof);
		if ($calendarClientsOnly) {
			invitationLiveExternalCases($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events, $proof);
			return;
		}
		$task = new Task($db);
		$task->ref = 'INV-TASK-'.$context['token'];
		$task->label = $context['prefix'].' Invitation task';
		$task->fk_project = (int) $project->id;
		liveAssert($task->create($organizer) > 0, 'Unable to create the invitation task');
		$fixture = invitationLiveCreate($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events, (int) $task->id);
		$id = $fixture['id'];
		$state = invitationLiveProtectedState($db, $id, (int) $invitee->id);
		$initial = $http('invitee', 'GET', $fixture['invitee_path']);
		liveHttpStatus($initial, array(200), 'INV01 shared native invitation GET');
		$initialCalendar = \Sabre\VObject\Reader::read($initial['body']);
		$organizerInitial = $http('organizer', 'GET', $fixture['organizer_path']);
		liveAssert(str_contains((string) \Sabre\VObject\Reader::read($organizerInitial['body'])->VEVENT->DESCRIPTION, $projectSecret)
			&& !str_contains((string) $initialCalendar->VEVENT->DESCRIPTION, $projectSecret),
			'The native private-project fixture does not distinguish organizer and attendee CRM read access');
		$uid = (string) $initialCalendar->VEVENT->UID;
		$sequence = (int) $initialCalendar->VEVENT->SEQUENCE->getValue();
		$dates = liveRow($db, 'SELECT datep, datep2 FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
		$start = (int) $db->jdate($dates->datep);
		$end = (int) $db->jdate($dates->datep2);
		$pendingReminders = array();
		$historicalReminder = 0;
		foreach (array(array((int) $organizer->id, 15, 0), array((int) $invitee->id, 15, 0), array((int) $invitee->id, 30, 1)) as $spec) {
			$reminder = new ActionCommReminder($db);
			$reminder->fk_actioncomm = $id;
			$reminder->fk_user = $spec[0];
			$reminder->typeremind = 'browser';
			$reminder->offsetvalue = $spec[1];
			$reminder->offsetunit = 'i';
			$reminder->status = $spec[2];
			$reminder->dateremind = $start - $spec[1] * 60;
			liveAssert($reminder->create($organizer) > 0, 'Unable to create native cancellation reminder fixtures');
			if ($spec[2] === 0) $pendingReminders[] = (int) $reminder->id;
			else $historicalReminder = (int) $reminder->id;
		}
		$historyBefore = (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.$historicalReminder);
		businessLivePersonalReminders($db, $id, (int) $invitee->id,
			static fn($method, $body = null, $extra = array()) => $http('invitee', $method, $fixture['invitee_path'], $body, $extra),
			static fn() => $http('organizer', 'GET', $fixture['organizer_path']));
		foreach (array('Outlook CalDavSynchronizer' => '-//CalDavSynchronizer//Outlook//EN', 'DAVx5' => '-//bitfire web engineering//DAVx5//EN') as $client => $product) {
			foreach (array('ACCEPTED', 'TENTATIVE', 'DECLINED', 'ACCEPTED') as $answer) {
				$current = $http('invitee', 'GET', $fixture['invitee_path']);
				$body = invitationLiveAnswer($current['body'], (string) $invitee->email, $answer, $product);
				$response = $http('invitee', 'PUT', $fixture['invitee_path'], $body,
					array('If-Match: '.$current['headers']['etag'], 'User-Agent: '.$client));
				liveHttpStatus($response, array(200, 204), 'INV02 '.$client.' authenticated '.$answer);
				foreach (array('organizer', 'invitee') as $actor) {
					$actual = $http($actor, 'GET', $fixture[$actor.'_path']);
					liveHttpStatus($actual, array(200), 'INV03 shared answer read by '.$actor);
					$component = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
					liveAssert((string) $component->UID === $uid && (int) $component->SEQUENCE->getValue() === $sequence
						&& (string) invitationLiveAttendee($component, (string) $invitee->email)['PARTSTAT'] === $answer,
						'The native invitation answer or stable UID/sequence differs between calendars');
				}
				$resource = liveRow($db, 'SELECT answer_status, transparency FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '
					.$id.' AND element_type = \'user\' AND fk_element = '.((int) $invitee->id));
				liveAssert((string) $resource->answer_status === $answer && (int) $resource->transparency === ($answer === 'DECLINED' ? 0 : 1),
					'An invitation answer did not update only its personal native occupancy');
				liveAssert(invitationLiveProtectedState($db, $id, (int) $invitee->id) === $state,
					'An invitation answer changed native customer/project/contact links or another participant');
				$busy = (new \Dolibarr\CDav\BookingCalendarReader($db, $invitee, $langs))->busy($start, $end, 'Europe/Zurich');
				liveAssert(($answer === 'DECLINED') === empty($busy), 'A personal invitation answer was not reflected in booking availability');
				$proof[] = array('case' => 'PUT response', 'client' => $client, 'partstat' => $answer, 'http' => $response['status']);
			}
		}
		$before = invitationLiveNoopState($db, $id);
		$organizerTag = $http('organizer', 'GET', $fixture['organizer_path'])['headers']['etag'];
		foreach (array(false, true) as $busyAgain) {
			$current = $http('invitee', 'GET', $fixture['invitee_path']);
			$body = businessLiveCalendar($current['body'], static function ($event) use ($busyAgain) {
				if ($busyAgain) {
					$event->TRANSP = 'OPAQUE';
					foreach ($event->select('VALARM') as $alarm) if ((string) $alarm->TRIGGER === '-PT19M') $event->remove($alarm);
				} else {
					$event->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'FREE';
					$event->add('VALARM', array('ACTION' => 'DISPLAY', 'TRIGGER' => '-PT19M', 'DESCRIPTION' => 'Personal reminder'));
				}
			});
			liveHttpStatus($http('invitee', 'PUT', $fixture['invitee_path'], $body, array('If-Match: '.$current['headers']['etag'])),
				array(200, 204), 'Accepted participant personal availability and reminder');
			$current = $http('invitee', 'GET', $fixture['invitee_path']);
			$personal = \Sabre\VObject\Reader::read($current['body']);
			liveAssert((string) $personal->VEVENT->TRANSP === ($busyAgain ? 'OPAQUE' : 'TRANSPARENT')
				&& (string) $personal->VEVENT->{'X-MICROSOFT-CDO-BUSYSTATUS'} === ($busyAgain ? 'BUSY' : 'FREE')
				&& str_contains($personal->serialize(), '-PT19M') === !$busyAgain
				&& (string) invitationLiveAttendee($personal->VEVENT, (string) $invitee->email)['PARTSTAT'] === 'ACCEPTED',
				'The participant did not retain the requested local availability, alarm and accepted RSVP');
			$personal->destroy();
			$after = invitationLiveNoopState($db, $id);
			foreach ($before['resources'] as $index => $original) {
				if ($original['element_type'] !== 'user' || (int) $original['fk_element'] !== (int) $invitee->id) continue;
				liveAssert((int) $after['resources'][$index]['transparency'] === (int) $busyAgain, 'Native participant availability differs from the DAV representation');
				$after['resources'][$index]['transparency'] = $original['transparency'];
			}
			liveAssert($after === $before && $http('organizer', 'GET', $fixture['organizer_path'])['headers']['etag'] === $organizerTag,
				'Private participant availability rewrote the event, assignments, shared revision or scheduling journal');
			liveAssert((bool) (new \Dolibarr\CDav\BookingCalendarReader($db, $invitee, $langs))->busy($start, $end, 'Europe/Zurich') === $busyAgain,
				'The accepted participant free/busy projection did not follow their personal setting');
		}
		liveOk('Accepted participant personal Free/Busy edits retain RSVP and never send a scheduling message');
		$current = $http('invitee', 'GET', $fixture['invitee_path']);
		$before = invitationLiveNoopState($db, $id);
		liveHttpStatus($http('invitee', 'PUT', $fixture['invitee_path'], $current['body'], array('If-Match: '.$current['headers']['etag'])),
			array(200, 204), 'INV04 repeated answer echo');
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'A repeated answer rewrote native rows or sent another scheduling message');
		$forged = invitationLiveAnswer($current['body'], (string) $invitee->email, 'TENTATIVE', '-//DAVx5//EN');
		$forgedCalendar = \Sabre\VObject\Reader::read($forged);
		invitationLiveAttendee($forgedCalendar->VEVENT, 'external-invitation@example.test')['PARTSTAT'] = 'DECLINED';
		liveHttpStatus($http('invitee', 'PUT', $fixture['invitee_path'], $forgedCalendar->serialize()), array(200, 204), 'INV05 foreign answer ignored');
		$actual = $http('organizer', 'GET', $fixture['organizer_path']);
		liveAssert((string) invitationLiveAttendee(\Sabre\VObject\Reader::read($actual['body'])->VEVENT, 'external-invitation@example.test')['PARTSTAT'] === 'NEEDS-ACTION',
			'An authenticated attendee forged another participant response');
		foreach (array('role' => static function ($component) use ($invitee) {
			invitationLiveAttendee($component, (string) $invitee->email)['ROLE'] = 'CHAIR';
		}, 'business' => static function ($component) {
			$component->SUMMARY = 'Forged native subject';
		}, 'participant removal' => static function ($component) {
			$component->remove('ATTENDEE');
		}) as $mutation => $change) {
			$before = invitationLiveNoopState($db, $id);
			$fresh = $http('invitee', 'GET', $fixture['invitee_path']);
			$forged = businessLiveCalendar($fresh['body'], $change);
			liveHttpStatus($http('invitee', 'PUT', $fixture['invitee_path'], $forged), array(403), 'INV06 forbidden '.$mutation);
			liveAssert(invitationLiveNoopState($db, $id) === $before, 'A refused invitation mutation had native or scheduling side effects');
		}
		liveHttpStatus($http('invitee', 'PUT', $fixture['invitee_path'], $initial['body'], array('If-Match: '.$initial['headers']['etag'])),
			array(412), 'INV07 stale answer ETag');
		liveOk('INV01-INV07 native protected answers, two client PUT styles, replay, ACL and ETag');

		// RFC 6638 clients can also answer through their own scheduling outbox.
		$fresh = $http('invitee', 'GET', $fixture['invitee_path']);
		$reply = \Sabre\VObject\Reader::read(invitationLiveAnswer($fresh['body'], (string) $invitee->email, 'DECLINED', '-//DAVx5//EN'));
		$reply->METHOD = 'REPLY';
		$self = clone invitationLiveAttendee($reply->VEVENT, (string) $invitee->email);
		$reply->VEVENT->remove('ATTENDEE');
		$reply->VEVENT->add($self);
		$outbox = liveDavPath(array('calendars', $context['limited_login'], 'outbox'), true);
		$replyHeaders = array('Originator: mailto:'.$invitee->email, 'Recipient: mailto:'.$organizer->email);
		liveHttpStatus($http('invitee', 'POST', $outbox, $reply->serialize(), $replyHeaders), array(200), 'INV08 authenticated outbox REPLY');
		liveAssert((string) liveRow($db, 'SELECT answer_status FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id
			.' AND element_type = \'user\' AND fk_element = '.((int) $invitee->id))->answer_status === 'DECLINED', 'An accepted outbox REPLY did not reach the same native appointment');
		$before = invitationLiveNoopState($db, $id);
		liveHttpStatus($http('invitee', 'POST', $outbox, $reply->serialize(), $replyHeaders), array(200), 'INV09 duplicate outbox REPLY');
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'A duplicate REPLY modified native data or duplicated scheduling delivery');
		$forgedReply = clone $reply;
		invitationLiveAttendee($forgedReply->VEVENT, (string) $invitee->email)->setValue('mailto:external-invitation@example.test');
		liveHttpStatus($http('invitee', 'POST', $outbox, $forgedReply->serialize(),
			array('Originator: mailto:external-invitation@example.test', 'Recipient: mailto:'.$organizer->email)),
			array(400, 403), 'INV09 forged outbox respondent');
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'A forged REPLY changed another participant or sent scheduling messages');
		foreach (array('UID', 'SEQUENCE', 'ORGANIZER') as $ambiguousProperty) {
			$ambiguous = clone $reply;
			$ambiguous->VEVENT->add(clone $reply->VEVENT->{$ambiguousProperty});
			liveHttpStatus($http('invitee', 'POST', $outbox, $ambiguous->serialize(), $replyHeaders), array(400, 403),
				'INV09 ambiguous outbox '.$ambiguousProperty);
			liveAssert(invitationLiveNoopState($db, $id) === $before, 'An ambiguous REPLY caused a native write or scheduling delivery');
		}
		$stale = $http('invitee', 'GET', $fixture['invitee_path']);
		$organizerGet = $http('organizer', 'GET', $fixture['organizer_path']);
		$expected = businessLiveEventState($db, $id);
		liveAssert((int) $expected['event']['fk_element'] === (int) $task->id && $expected['event']['elementtype'] === 'project_task'
			&& (int) $expected['event']['fk_project'] === (int) $project->id, 'INV10 requires a native task-linked invitation');
		$expected['event']['label'] = $context['prefix'].' Updated appointment';
		$expected['event']['location'] = 'Updated meeting place';
		$expected['event']['note'] = 'Visit notes edited in the calendar';
		$moved = businessLiveCalendar($organizerGet['body'], static function ($component) use ($start, $end, $expected) {
			$component->SUMMARY = $expected['event']['label'];
			$component->LOCATION = $expected['event']['location'];
			$component->DESCRIPTION = $expected['event']['note'];
			$component->DTSTART->setDateTime(new DateTimeImmutable('@'.($start + 86400)));
			$component->DTEND->setDateTime(new DateTimeImmutable('@'.($end + 86400 + 1800)));
		});
		liveHttpStatus($http('organizer', 'PUT', $fixture['organizer_path'], $moved, array('If-Match: '.$organizerGet['headers']['etag'])),
			array(200, 204), 'INV10 organizer invitation update');
		$end += 1800;
		liveAssert(businessLiveEventState($db, $id) === $expected, 'INV10 changed a native project, task, contact, attendee response or assignment');
		foreach (array('organizer', 'invitee') as $actor) {
			$actual = $http($actor, 'GET', $fixture[$actor.'_path']);
			liveHttpStatus($actual, array(200), 'INV10 edited appointment read by '.$actor);
			$item = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
			liveAssert((string) $item->UID === $uid && (int) (string) $item->SEQUENCE === $sequence + 1
				&& (string) $item->SUMMARY === $expected['event']['label'] && (string) $item->LOCATION === $expected['event']['location']
				&& str_contains((string) $item->DESCRIPTION, $expected['event']['note']) && $item->DTEND->getDateTime()->getTimestamp() === $end + 86400
				&& str_contains((string) $item->DESCRIPTION, $task->ref) === ($actor === 'organizer'),
				'INV10 lost the edited appointment fields, single revision or project-scoped task visibility');
		}
		$protection = liveSnapshotConstant($db, 'CDAV_PROTECT_NATIVE_EVENTS', (int) $context['entity']);
		try {
			liveAssert(dolibarr_set_const($db, 'CDAV_PROTECT_NATIVE_EVENTS', '0', 'chaine', 0, '', (int) $context['entity']) > 0,
				'Unable to qualify an organizer update with native protection disabled');
			$editable = $http('organizer', 'GET', $fixture['organizer_path']);
			$editableState = invitationLiveProtectedState($db, $id, (int) $organizer->id);
			$editableMove = businessLiveCalendar($editable['body'], static function ($component) use ($start, $end) {
				$component->DTSTART->setDateTime(new DateTimeImmutable('@'.($start + 172800)));
				$component->DTEND->setDateTime(new DateTimeImmutable('@'.($end + 172800)));
			});
			liveHttpStatus($http('organizer', 'PUT', $fixture['organizer_path'], $editableMove, array('If-Match: '.$editable['headers']['etag'])),
				array(200, 204), 'INV19 editable organizer move preserves personal responses');
			$declined = liveRow($db, 'SELECT answer_status, transparency FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id
				.' AND element_type = \'user\' AND fk_element = '.((int) $invitee->id));
			liveAssert((string) $declined->answer_status === 'DECLINED' && (int) $declined->transparency === 0
				&& invitationLiveProtectedState($db, $id, (int) $organizer->id) === $editableState,
				'An editable organizer update overwrote another participant response, occupancy or native business fields');
			liveAssert((new \Dolibarr\CDav\BookingCalendarReader($db, $invitee, $langs))->busy($start + 172800, $end + 172800, 'Europe/Zurich') === array(),
				'An organizer move made a declined participant busy again');
			$start += 86400;
			$end += 86400;
		} finally {
			liveAssert(liveRestoreConstant($db, 'CDAV_PROTECT_NATIVE_EVENTS', (int) $context['entity'], $protection) > 0,
				'Unable to restore native appointment protection');
		}
		$fresh = $http('invitee', 'GET', $fixture['invitee_path']);
		liveAssert((int) \Sabre\VObject\Reader::read($fresh['body'])->VEVENT->SEQUENCE->getValue() > $sequence,
			'An organizer update did not advance the invitation sequence');
		$before = invitationLiveNoopState($db, $id);
		$staleAnswer = invitationLiveAnswer($stale['body'], (string) $invitee->email, 'ACCEPTED', '-//CalDavSynchronizer//EN');
		liveHttpStatus($http('invitee', 'PUT', $fixture['invitee_path'], $staleAnswer, array('If-Match: '.$fresh['headers']['etag'])),
			array(403, 409, 412), 'INV11 stale invitation sequence despite a current HTTP ETag');
		liveHttpStatus($http('invitee', 'POST', $outbox, $reply->serialize(), $replyHeaders), array(403, 409, 412), 'INV11 stale outbox REPLY');
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'A stale invitation answer overwrote the organizer update');
		invitationLiveInboxPrivacy($db, $context, $uid, $projectSecret);
		liveOk('INV08-INV11 scheduling outbox replies, idempotence and organizer sequence updates');

		$cancelBefore = invitationLiveProtectedState($db, $id, 0, true);
		$organizerGet = $http('organizer', 'GET', $fixture['organizer_path']);
		$cancel = businessLiveCalendar($organizerGet['body'], static function ($component) {
			$component->STATUS = 'CANCELLED';
			$component->TRANSP = 'TRANSPARENT';
		});
		liveHttpStatus($http('organizer', 'PUT', $fixture['organizer_path'], $cancel, array('If-Match: '.$organizerGet['headers']['etag'])),
			array(200, 204), 'INV12 organizer cancellation PUT');
		liveAssert(invitationLiveProtectedState($db, $id, 0, true) === $cancelBefore, 'Cancellation changed native links, notes or participant identities');
		foreach (array('organizer' => $organizer, 'invitee' => $invitee) as $actor => $account) {
			$actual = $http($actor, 'GET', $fixture[$actor.'_path']);
			liveHttpStatus($actual, array(200), 'INV13 cancelled invitation retained for '.$actor);
			$component = \Sabre\VObject\Reader::read($actual['body'])->VEVENT;
			liveAssert((string) $component->UID === $uid && (string) $component->STATUS === 'CANCELLED'
				&& (string) $component->TRANSP === 'TRANSPARENT', 'A cancelled native invitation lost its identity or still appears occupied');
			liveAssert((new \Dolibarr\CDav\BookingCalendarReader($db, $account, $langs))->busy($start + 86400, $end + 86400, 'Europe/Zurich') === array(),
				'A cancelled invitation still blocks public booking availability');
		}
		liveAssert((int) liveRow($db, 'SELECT transparency FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id)->transparency === 0
			&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id.' AND transparency <> 0')->nb === 0,
			'Cancellation did not release all native invitation resources');
		liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid IN ('
			.implode(',', $pendingReminders).')')->nb === 0, 'Cancellation left pending native reminders armed');
		liveAssert((array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.$historicalReminder) === $historyBefore,
			'Cancellation changed a processed native reminder');
		$before = invitationLiveNoopState($db, $id);
		liveHttpStatus($http('invitee', 'PUT', $fixture['invitee_path'], $staleAnswer), array(403, 409, 412), 'INV14 answer after organizer cancellation');
		liveAssert(invitationLiveNoopState($db, $id) === $before, 'A late answer resurrected a cancelled invitation');

		$detached = invitationLiveCreate($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events, (int) $task->id);
		$metadata = liveRow($db, 'SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$detached['id']);
		// Old versions stored the original request, including duplicate attendees.
		$storeLegacyAttendees = static function (?string $secondStatus) use ($db, $metadata, $invitee, $detached) {
			$historical = invitationLiveDuplicateAttendee($metadata->calendardata, $invitee->email, $secondStatus);
			liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX."cdav_scheduling SET calendardata = '".$db->escape($historical)
				."' WHERE fk_actioncomm = ".$detached['id']), 'Unable to seed a legacy invitation');
		};
		$storeLegacyAttendees('ACCEPTED');
		$before = invitationLiveNoopState($db, $detached['id']);
		$legacyRead = $http('invitee', 'GET', $detached['invitee_path']);
		liveHttpStatus($legacyRead, array(200), 'INV15 stored conflicting attendees remain readable');
		$legacyComponent = \Sabre\VObject\Reader::read($legacyRead['body'])->VEVENT;
		liveAssert(count($legacyComponent->select('ATTENDEE')) === 3 && invitationLiveNoopState($db, $detached['id']) === $before,
			'Reading an ambiguous invitation must retain both responses and leave stored state untouched');
		$rejected = $http('invitee', 'DELETE', $detached['invitee_path']);
		liveHttpStatus($rejected, array(400), 'INV15 attendee removal with conflicting stored responses');
		liveAssert(liveDavErrorMessage($rejected) === 'The stored invitation has conflicting ATTENDEE entries for the same calendar address.'
			&& invitationLiveNoopState($db, $detached['id']) === $before, 'Ambiguous stored responses must leave all assignments and notifications intact');
		$storeLegacyAttendees(null);
		$before = invitationLiveNoopState($db, $detached['id']);
		$legacyRead = $http('invitee', 'GET', $detached['invitee_path']);
		liveHttpStatus($legacyRead, array(200), 'INV15 readable canonical representation of equivalent stored attendees');
		$legacyComponent = \Sabre\VObject\Reader::read($legacyRead['body'])->VEVENT;
		liveAssert(count($legacyComponent->select('ATTENDEE')) === 2 && invitationLiveNoopState($db, $detached['id']) === $before,
			'Reading equivalent historical attendees must expose one entry per participant without any database or calendar write');
		$expected = businessLiveEventState($db, $detached['id']);
		$expected['resources'] = array_values(array_filter($expected['resources'],
			static fn($resource) => $resource['element_type'] !== 'user' || (int) $resource['fk_element'] !== (int) $invitee->id));
		liveHttpStatus($http('invitee', 'DELETE', $detached['invitee_path']), array(200, 204), 'INV15 attendee removal from a legacy invitation with equivalent duplicates');
		$remaining = $http('organizer', 'GET', $detached['organizer_path']);
		liveHttpStatus($remaining, array(200), 'INV15 organizer keeps the appointment');
		$eventAfterRemoval = \Sabre\VObject\Reader::read($remaining['body'])->VEVENT;
		liveAssert((string) $eventAfterRemoval->STATUS !== 'CANCELLED' && count($eventAfterRemoval->select('ATTENDEE')) === 2
			&& (string) invitationLiveAttendee($eventAfterRemoval, $invitee->email)['PARTSTAT'] === 'DECLINED'
			&& businessLiveEventState($db, $detached['id']) === $expected,
			'Attendee removal must decline once and preserve the organizer, other assignments, project and task');
		liveHttpStatus($http('invitee', 'GET', $detached['invitee_path']), array(404), 'INV15 attendee calendar no longer contains the detached appointment');
		$deleted = invitationLiveCreate($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events);
		liveHttpStatus($http('organizer', 'DELETE', $deleted['organizer_path']), array(200, 204), 'INV16 organizer invitation deletion');
		liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$deleted['id']) === null,
			'Organizer deletion left a shared native invitation behind');
		liveHttpStatus($http('invitee', 'GET', $deleted['invitee_path']), array(404), 'INV16 attendee copy removed with organizer deletion');
		liveAssert($project->fetch((int) $project->id) > 0 && $contact->fetch((int) $contact->id, $admin) > 0,
			'Invitation deletion removed independent native project or contact objects');
		$forgedOwner = invitationLiveCreate($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events);
		$metadata = liveRow($db, 'SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$forgedOwner['id']);
		$forged = \Sabre\VObject\Reader::read((string) $metadata->calendardata);
		$forged->VEVENT->ORGANIZER = 'mailto:'.$invitee->email;
		$forged->VEVENT->remove('ATTENDEE');
		$forged->VEVENT->add('ATTENDEE', 'mailto:'.$organizer->email, array('PARTSTAT' => 'NEEDS-ACTION'));
		liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'cdav_scheduling SET calendardata = \''.$db->escape($forged->serialize())
			.'\' WHERE fk_actioncomm = '.$forgedOwner['id']), 'Unable to prepare mismatched native/iCalendar organizer provenance');
		$fresh = $http('invitee', 'GET', $forgedOwner['invitee_path']);
		$before = invitationLiveNoopState($db, $forgedOwner['id']);
		foreach (array('cancel' => static function ($component) {
			$component->STATUS = 'CANCELLED';
			$component->TRANSP = 'TRANSPARENT';
		}, 'move' => static function ($component) {
			$component->DTSTART->setDateTime($component->DTSTART->getDateTime()->modify('+1 day'));
			$component->DTEND->setDateTime($component->DTEND->getDateTime()->modify('+1 day'));
		}) as $kind => $mutation) {
			liveHttpStatus($http('invitee', 'PUT', $forgedOwner['invitee_path'], businessLiveCalendar($fresh['body'], $mutation)),
				array(403), 'INV18 forged iCalendar organizer cannot '.$kind.' another native owner meeting');
			liveAssert(invitationLiveNoopState($db, $forgedOwner['id']) === $before, 'Forged organizer metadata overrode native meeting ownership');
		}
		invitationLiveExternalCases($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events, $proof);
		invitationLiveDelegationCases($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events, $proof);
		invitationLiveConcurrentCase($db, $organizer, $invitee, $context, (int) $project->id, (int) $contact->id, $events, $proof);
		$proof[] = array('case' => 'native invitations INV01-INV27', 'result' => 'PASS', 'mail_transport' => 'disabled');
		liveOk('INV12-INV18 cancellation, free/busy release, deletion, private inbox data and native organizer authority');
	} finally {
		foreach ($events as $id) {
			$event = new ActionComm($db);
			if ($event->fetch($id) > 0) liveAssert($event->delete($admin) > 0, 'Unable to clean up a native invitation fixture');
			foreach (array('actioncomm_cdav' => 'fk_object', 'cdav_scheduling' => 'fk_actioncomm') as $table => $column) {
				liveAssert((bool) $db->query('DELETE FROM '.MAIN_DB_PREFIX.$table.' WHERE '.$column.' = '.$id), 'Unable to clean up invitation protocol metadata');
			}
		}
		if ($contact && $contact->id > 0) liveAssert($contact->delete($admin) > 0, 'Unable to clean up the native invitation contact');
		if ($task && $task->id > 0) liveAssert($task->delete($admin) > 0, 'Unable to clean up the native invitation task');
		if ($project && $project->id > 0) liveAssert($project->delete($admin) > 0, 'Unable to clean up the native invitation project');
		$proofFile = getenv('CDAV_INVITATION_PROOF');
		if (is_string($proofFile) && $proofFile !== '') file_put_contents($proofFile, json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
	}
}
