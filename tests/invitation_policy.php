<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Real Sabre invitation response and organizer authorization checks. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavInvitationPolicy.php';

use Dolibarr\CDav\InvitationPolicy;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\PreconditionFailed;
use Sabre\VObject;

/**
 * Require a meaningful invitation invariant.
 *
 * @param bool $condition Expected invariant
 * @param string $message Failure detail
 * @return void
 */
function invitationPolicyAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Apply an actual iCalendar edit without relying on text replacement folding.
 *
 * @param string $data Current serialized calendar
 * @param callable $change Calendar mutation
 * @return string Edited calendar
 */
function invitationPolicyChange($data, callable $change)
{
	$calendar = VObject\Reader::read($data);
	try {
		$change($calendar->VEVENT, $calendar);
		return $calendar->serialize();
	} finally {
		$calendar->destroy();
	}
}

/**
 * Extract canonical state without retaining live VObject trees.
 *
 * @param array $plan Policy result
 * @return array<string,mixed> Canonical event state
 */
function invitationPolicyState(array $plan)
{
	$calendar = VObject\Reader::read($plan['calendar_data']);
	try {
		$event = $calendar->VEVENT;
		$attendees = array();
		foreach ($event->select('ATTENDEE') as $property) {
			$attendees[strtolower((string) $property)] = array('partstat' => (string) ($property['PARTSTAT'] ?? ''), 'rsvp' => (string) ($property['RSVP'] ?? ''));
		}
		return array('uid' => (string) $event->UID, 'summary' => (string) $event->SUMMARY,
			'description' => (string) ($event->DESCRIPTION ?? ''), 'status' => (string) ($event->STATUS ?? ''),
			'transp' => (string) ($event->TRANSP ?? ''), 'sequence' => (int) (string) ($event->SEQUENCE ?? 0),
			'start' => $event->DTSTART->getDateTime()->getTimestamp(), 'end' => $event->DTEND->getDateTime()->getTimestamp(),
			'alarm' => isset($event->VALARM) ? $event->VALARM->serialize() : '', 'attendees' => $attendees);
	} finally {
		$calendar->destroy();
	}
}

/**
 * Require the specific protocol refusal rather than any runtime failure.
 *
 * @param string $before Current invitation
 * @param string $after Replacement invitation
 * @param string $actor Authenticated identity
 * @param string $message Failure detail
 * @param string $exception Expected exception class
 * @param bool $protect Whether native business-field protection is enabled
 * @param bool $external Backend-verified private external invitation import
 * @param array $context Trusted native description boundaries
 * @return void
 */
function invitationPolicyRejects($before, $after, $actor, $message, $exception = Forbidden::class, $protect = true, $external = false, array $context = array())
{
	try {
		InvitationPolicy::plan($before, $after, $actor, $protect, $external, $context);
	} catch (Throwable $error) {
		if ($error instanceof $exception) return;
		throw $error;
	}
	throw new RuntimeException($message);
}

$before = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav invitation policy tests//EN\r\nBEGIN:VEVENT\r\nUID:native-invitation-42\r\nDTSTART:20261120T090000Z\r\nDTEND:20261120T100000Z\r\nDTSTAMP:20261118T080000Z\r\nSEQUENCE:4\r\nSUMMARY:Native appointment\r\nDESCRIPTION:Native notes\\nClient and private project context\r\nLOCATION:Client address\r\nCLASS:PUBLIC\r\nSTATUS:CONFIRMED\r\nTRANSP:OPAQUE\r\nORGANIZER;CN=Organizer:mailto:organizer@example.test\r\nATTENDEE;CN=Alice;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:alice@example.test\r\nATTENDEE;CN=Bob;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:bob@example.test\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Native reminder\r\nEND:VALARM\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$original = invitationPolicyState(InvitationPolicy::plan($before, $before, 'organizer@example.test'));
$accept = invitationPolicyChange($before, static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'ACCEPTED';
	$event->ATTENDEE['RSVP'] = 'FALSE';
	$event->SEQUENCE = 5; // Outlook CalDav Synchronizer increments even on a response.
});
$accepted = InvitationPolicy::plan($before, $accept, ' MAILTO:ALICE@EXAMPLE.TEST ');
$state = invitationPolicyState($accepted);
invitationPolicyAssert($accepted['kind'] === 'reply' && $accepted['changed'] && !$accepted['actor_is_organizer'], 'A verified attendee must be able to accept a native invitation');
invitationPolicyAssert($accepted['partstat'] === 'ACCEPTED' && $accepted['personal_transparency'] === 1 && $accepted['organizer_email'] === 'organizer@example.test', 'The canonical response must identify its actor and personal occupancy');
invitationPolicyAssert($state['sequence'] === 4 && $accepted['sequence'] === 4, 'Outlook response increments must never advance the organizer revision');
invitationPolicyAssert($state['description'] === $original['description'] && $state['alarm'] === $original['alarm'] && $state['uid'] === $original['uid'], 'Accepting must preserve notes, native alarms and the original identity');
invitationPolicyAssert($state['attendees']['mailto:alice@example.test']['partstat'] === 'ACCEPTED', 'The authoritative actor response must be merged into the current calendar');
invitationPolicyAssert(InvitationPolicy::plan($accepted['calendar_data'], $accept, 'alice@example.test')['kind'] === 'noop', 'Replaying the same Outlook acceptance must be idempotent');

$acceptedBusy = str_replace('TRANSP:OPAQUE', "TRANSP:OPAQUE\r\nX-MICROSOFT-CDO-BUSYSTATUS:BUSY", $accepted['calendar_data']);
$outlookFree = str_replace('BUSYSTATUS:BUSY', 'BUSYSTATUS:FREE', $acceptedBusy);
$free = InvitationPolicy::plan($acceptedBusy, $outlookFree, 'alice@example.test', true, false, array(), true);
invitationPolicyAssert($free['kind'] === 'availability' && $free['personal_transparency'] === 0 && $free['sequence'] === $accepted['sequence']
	&& invitationPolicyState($free) === invitationPolicyState($accepted), 'An Outlook Show As edit must be private and retain the accepted response');
$acceptedFree = str_replace('TRANSP:OPAQUE', 'TRANSP:TRANSPARENT', $outlookFree);
$backToBusy = str_replace('TRANSP:TRANSPARENT', 'TRANSP:OPAQUE', $acceptedFree);
$occupied = InvitationPolicy::plan($acceptedFree, $backToBusy, 'alice@example.test', true, false, array(), true);
invitationPolicyAssert($occupied['kind'] === 'availability' && $occupied['personal_transparency'] === 1,
	'DAVx5 may set a participant busy again without another RSVP, even with an echoed Outlook FREE hint');
invitationPolicyAssert(InvitationPolicy::plan($acceptedFree, $acceptedFree, 'alice@example.test', true, false, array(), true)['kind'] === 'noop'
	&& InvitationPolicy::plan($acceptedBusy, $outlookFree, 'alice@example.test')['kind'] === 'noop'
	&& InvitationPolicy::plan($acceptedBusy, $outlookFree, 'alice@example.test', false, true)['kind'] === 'noop',
	'An echo or iTIP response must not set personal availability');
$externalFree = InvitationPolicy::plan($acceptedBusy, $outlookFree, 'alice@example.test', false, true, array(), true, true);
invitationPolicyAssert($externalFree === $free && !$externalFree['external_import'],
	'Changing Show As on a private accepted external invitation must only update the participant occupancy');
$plainNote = invitationPolicyChange($outlookFree, static function ($event) {
	$event->DESCRIPTION = 'Native notes';
});
$noteContext = array('text' => "Native notes\nClient and private project context", 'native_note' => 'Native notes', 'notes_editable' => true,
	'note_prefix' => '', 'note_suffix' => "\nClient and private project context");
invitationPolicyAssert(InvitationPolicy::plan($acceptedBusy, $plainNote, 'alice@example.test', false, true, $noteContext, true, true) === $free,
	'A client that kept its original mailbox note must not import the shared invitation again when marking itself free');
$sparseFree = invitationPolicyChange($outlookFree, static function ($event) {
	foreach (array('DESCRIPTION', 'LOCATION', 'PRIORITY') as $name) $event->remove($name);
});
$withPriority = invitationPolicyChange($acceptedBusy, static function ($event) {
	$event->PRIORITY = 3;
});
$sparsePlan = InvitationPolicy::plan($withPriority, $sparseFree, 'alice@example.test', false, true, $noteContext, true, true);
invitationPolicyAssert($sparsePlan['kind'] === 'availability' && $sparsePlan['personal_transparency'] === 0
	&& (string) \Sabre\VObject\Reader::read($sparsePlan['calendar_data'])->VEVENT->PRIORITY === '3',
	'An accepted attendee may omit notes, location and priority when choosing Show As');
invitationPolicyAssert(InvitationPolicy::plan($acceptedFree, $outlookFree, 'alice@example.test', false, true, array(), true, true)['kind'] === 'noop'
	&& InvitationPolicy::plan($acceptedFree, $backToBusy, 'alice@example.test', false, true, array(), true)['personal_transparency'] === 1,
	'Replaying an Outlook PUT must keep the free slot, and a subsequent RFC client can explicitly occupy it');
foreach (array('TENTATIVE', 'NEEDS-ACTION', 'DECLINED') as $partstat) {
	$notAccepted = invitationPolicyChange($acceptedBusy, static function ($event) use ($partstat) {
		$event->ATTENDEE['PARTSTAT'] = $partstat;
	});
	$requestFree = str_replace('BUSYSTATUS:BUSY', 'BUSYSTATUS:FREE', $notAccepted);
	invitationPolicyAssert(InvitationPolicy::plan($notAccepted, $requestFree, 'alice@example.test', false, true, array(), true, true)['kind'] === 'noop',
		'Only an accepted attendee can change Show As on an external mailbox copy');
}
$organizerRevision = invitationPolicyChange($outlookFree, static function ($event) {
	$event->SEQUENCE = 5;
	$event->STATUS = 'TENTATIVE';
});
$withOrganizerRevision = InvitationPolicy::plan($acceptedBusy, $organizerRevision, 'alice@example.test', false, true, array(), true, true);
invitationPolicyAssert($withOrganizerRevision['kind'] === 'organizer' && $withOrganizerRevision['external_import']
	&& $withOrganizerRevision['status'] === 'TENTATIVE' && $withOrganizerRevision['sequence'] === 5
	&& $withOrganizerRevision['personal_transparency'] === 0 && !$withOrganizerRevision['actor_is_organizer'],
	'An organizer status revision received with a personal availability change must retain both');
$previousImport = invitationPolicyChange($organizerRevision, static function ($event) {
	$event->SEQUENCE = 5; // The previous importer moved this property to the end.
});
invitationPolicyAssert($withOrganizerRevision['calendar_data'] === $organizerRevision
	&& InvitationPolicy::sameImportedRevision($organizerRevision, str_replace("\r\n", "\n", $organizerRevision))
	&& InvitationPolicy::sameImportedRevision($previousImport, $organizerRevision)
	&& !InvitationPolicy::sameImportedRevision($previousImport, $acceptedBusy),
	'Recognize an exact organizer retry for both new and historical property order without ignoring other changes');

$davx5 = invitationPolicyChange($before, static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'TENTATIVE';
	$event->ATTENDEE['RSVP'] = 'FALSE';
});
invitationPolicyAssert(InvitationPolicy::plan($before, $davx5, 'alice@example.test')['partstat'] === 'TENTATIVE', 'A DAVx5-style response retaining the current sequence must be accepted');
foreach (array(3, 6) as $invalidSequence) {
	$invalid = invitationPolicyChange($accept, static function ($event) use ($invalidSequence) {
		$event->SEQUENCE = $invalidSequence;
	});
	invitationPolicyRejects($before, $invalid, 'alice@example.test', 'Unknown organizer revisions must be rejected', PreconditionFailed::class);
}
$stalePeer = invitationPolicyChange($accept, static function ($event) {
	$event->select('ATTENDEE')[1]['PARTSTAT'] = 'DECLINED';
	$event->select('ATTENDEE')[1]['RSVP'] = 'TRUE';
});
invitationPolicyAssert(invitationPolicyState(InvitationPolicy::plan($before, $stalePeer, 'alice@example.test'))['attendees']['mailto:bob@example.test'] === $original['attendees']['mailto:bob@example.test'], 'A response must never overwrite a peer response from a stale client copy');

$outlook = invitationPolicyChange($accept, static function ($event) {
	$event->remove('VALARM');
	$event->remove('DESCRIPTION');
	$event->ATTENDEE['CN'] = 'Alice Outlook';
	$event->ATTENDEE['ROLE'] = 'REQ-PARTICIPANT';
	$event->ATTENDEE['CUTYPE'] = 'INDIVIDUAL';
	$event->ATTENDEE['SCHEDULE-AGENT'] = 'CLIENT';
});
$outlookState = invitationPolicyState(InvitationPolicy::plan($before, $outlook, 'alice@example.test'));
invitationPolicyAssert($outlookState['alarm'] === $original['alarm'] && $outlookState['description'] === $original['description'], 'Outlook Reminder=None and a mail-only body must never erase native reminders or CRM notes');
$changedBody = invitationPolicyChange($accept, static function ($event) {
	$event->DESCRIPTION = 'Untrusted email body';
	$event->VALARM->TRIGGER = '-PT30M';
});
invitationPolicyAssert(invitationPolicyState(InvitationPolicy::plan($before, $changedBody, 'alice@example.test'))['description'] === $original['description'], 'A response may carry a different invitation body, but only CURRENT notes may survive');
$noteOnly = invitationPolicyChange($before, static function ($event) {
	$event->DESCRIPTION = 'Attempted business edit';
});
invitationPolicyRejects($before, $noteOnly, 'alice@example.test', 'An attendee note-only edit must be refused');

$decline = invitationPolicyChange($before, static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'DECLINED';
	$event->ATTENDEE['RSVP'] = 'FALSE';
	$event->TRANSP = 'TRANSPARENT';
	$event->STATUS = 'CANCELLED'; // Some clients model a personal refusal this way.
});
$declined = InvitationPolicy::plan($before, $decline, 'alice@example.test');
invitationPolicyAssert($declined['personal_transparency'] === 0 && invitationPolicyState($declined)['status'] === 'CONFIRMED', 'A personal refusal must free only that participant and must not cancel the shared event');
$reaccept = invitationPolicyChange($declined['calendar_data'], static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'ACCEPTED';
});
invitationPolicyAssert(InvitationPolicy::plan($declined['calendar_data'], $reaccept, 'alice@example.test')['personal_transparency'] === 1, 'Accepting after declining must restore that participant occupancy');

invitationPolicyRejects($before, $accept, 'outsider@example.test', 'An uninvited authenticated account must not submit a response');
foreach (array('UID', 'ORGANIZER', 'SUMMARY', 'LOCATION', 'CLASS', 'RRULE') as $property) {
	$bad = invitationPolicyChange($accept, static function ($event) use ($property) {
		$values = array('UID' => 'another-uid', 'ORGANIZER' => 'mailto:attacker@example.test', 'SUMMARY' => 'Changed business subject',
			'LOCATION' => 'Changed business address', 'CLASS' => 'PRIVATE', 'RRULE' => 'FREQ=DAILY;COUNT=2');
		$event->{$property} = $values[$property];
	});
	invitationPolicyRejects($before, $bad, 'alice@example.test', 'A response must not alter '.$property);
}
foreach (array('role', 'membership', 'dates', 'duration', 'cancel') as $change) {
	$bad = invitationPolicyChange($accept, static function ($event) use ($change) {
		if ($change === 'role') $event->ATTENDEE['ROLE'] = 'CHAIR';
		if ($change === 'membership') $event->add('ATTENDEE', 'mailto:outsider@example.test');
		if ($change === 'dates') {
			$event->DTSTART = '20261121T090000Z';
			$event->DTEND = '20261121T100000Z';
		}
		if ($change === 'duration') $event->DTEND = '20261120T120000Z';
		if ($change === 'cancel') $event->STATUS = 'CANCELLED';
	});
	invitationPolicyRejects($before, $bad, 'alice@example.test', 'An attendee response must not alter '.$change);
}
$badReplyMethod = invitationPolicyChange($accept, static function ($event, $calendar) {
	$calendar->METHOD = 'REPLY';
});
invitationPolicyRejects($before, $badReplyMethod, 'alice@example.test', 'An iTIP message must not be stored as a resource');

$move = invitationPolicyChange($before, static function ($event) {
	$event->DTSTART = '20261121T140000Z';
	$event->DTEND = '20261121T150000Z';
	$event->SEQUENCE = 99;
	$event->select('ATTENDEE')[1]['PARTSTAT'] = 'NEEDS-ACTION';
});
$moved = InvitationPolicy::plan($before, $move, 'organizer@example.test');
invitationPolicyAssert($moved['kind'] === 'organizer' && $moved['date_changed'] && $moved['sequence'] === 5, 'A protected organizer move must advance the authoritative revision exactly once');
invitationPolicyAssert(invitationPolicyState($moved)['attendees'] === $original['attendees'], 'Moving must retain the authoritative participant responses');
invitationPolicyRejects($moved['calendar_data'], $accept, 'alice@example.test', 'A pre-move response must not move the invitation back even when its client increment equals the new revision');
$title = invitationPolicyChange($before, static function ($event) {
	$event->SUMMARY = 'DAV-edited subject';
	$event->select('ATTENDEE')[1]['PARTSTAT'] = 'DECLINED';
});
$renamed = InvitationPolicy::plan($before, $title, 'organizer@example.test');
invitationPolicyAssert($renamed['sequence'] === 5 && !$renamed['date_changed'] && $renamed['fields'] === array('label' => 'DAV-edited subject')
	&& invitationPolicyState($renamed)['summary'] === 'DAV-edited subject' && invitationPolicyState($renamed)['attendees'] === $original['attendees'],
	'An organizer may rename the shared appointment exactly once while preserving attendee responses');
invitationPolicyAssert(InvitationPolicy::plan($renamed['calendar_data'], $renamed['calendar_data'], 'organizer@example.test')['kind'] === 'noop',
	'Replaying the committed invitation must not advance its revision');
$context = array('text' => $original['description'], 'note_prefix' => '', 'note_suffix' => "\nClient and private project context",
	'native_note' => 'Native notes', 'notes_editable' => true);
$notes = invitationPolicyChange($before, static function ($event) {
	$event->DESCRIPTION = "Updated visit notes\nClient and private project context";
});
$noted = InvitationPolicy::plan($before, $notes, 'organizer@example.test', true, false, $context);
invitationPolicyAssert($noted['fields'] === array('note' => 'Updated visit notes') && $noted['sequence'] === 5 && !$noted['date_changed'],
	'Only the organizer-edited notes belong in the native field update');
$combined = invitationPolicyChange($notes, static function ($event) {
	$event->LOCATION = 'New meeting place';
	$event->PRIORITY = 2;
	$event->TRANSP = 'TRANSPARENT';
	$event->SUMMARY = 'New subject';
	$event->DTEND = '20261120T110000Z';
});
$plan = InvitationPolicy::plan($before, $combined, 'organizer@example.test', true, false, $context);
invitationPolicyAssert($plan['fields'] === array('label' => 'New subject', 'location' => 'New meeting place', 'priority' => 2,
	'transparency' => 0, 'note' => 'Updated visit notes') && $plan['date_changed'] && $plan['sequence'] === 5
	&& invitationPolicyState($plan)['attendees'] === $original['attendees'], 'A combined organizer update must advance one revision and preserve responses');
$shared = invitationPolicyChange($before, static function ($event) {
	$event->VALARM->DESCRIPTION = 'Previously stored shared alarm';
});
foreach (array('', $shared) as $stored) {
	$sidecar = InvitationPolicy::nativeMetadata($plan, $stored);
	$item = VObject\Reader::read($sidecar);
	try {
		invitationPolicyAssert(!isset($item->VEVENT->DESCRIPTION) && !isset($item->VEVENT->LOCATION) && !str_contains($sidecar, 'Native reminder')
			&& str_contains($sidecar, 'Previously stored shared alarm') === ($stored !== '')
			&& (string) $item->VEVENT->SEQUENCE === '5', 'Shared scheduling storage must exclude the current calendar notes, venue and personal alarms');
	} finally {
		$item->destroy();
	}
}
$forged = invitationPolicyChange($combined, static function ($event) {
	$event->add('ATTENDEE', 'mailto:unexpected@example.test');
});
invitationPolicyRejects($before, $forged, 'organizer@example.test', 'Editable organizer fields must not authorize adding a participant', Forbidden::class, true, false, $context);
invitationPolicyRejects($before, $combined, 'alice@example.test', 'An attendee cannot adopt organizer write permissions', Forbidden::class, true, false, $context);
foreach (array('', str_repeat('é', 256)) as $invalidTitle) {
	$invalid = invitationPolicyChange($before, static function ($event) use ($invalidTitle) {
		$event->SUMMARY = $invalidTitle;
	});
	invitationPolicyRejects($before, $invalid, 'organizer@example.test', 'A protected organizer edit must supply a valid native title', BadRequest::class);
}
$edited = InvitationPolicy::plan($before, $title, 'organizer@example.test', false);
invitationPolicyAssert($edited['sequence'] === 5 && invitationPolicyState($edited)['summary'] === 'DAV-edited subject'
	&& invitationPolicyState($edited)['attendees'] === $original['attendees'], 'DAV-created invitation edits must work without overwriting current responses');
$legacyOrganizer = invitationPolicyChange($title, static function ($event) {
	$event->remove('SEQUENCE');
});
invitationPolicyAssert(InvitationPolicy::plan($before, $legacyOrganizer, 'organizer@example.test', false)['sequence'] === 5,
	'A legacy organizer without SEQUENCE must use the locked server revision and retain ordinary editing capability');
$explicitOldOrganizer = invitationPolicyChange($title, static function ($event) {
	$event->SEQUENCE = 0;
});
invitationPolicyRejects($before, $explicitOldOrganizer, 'organizer@example.test', 'An explicitly outdated organizer revision must remain stale', PreconditionFailed::class, false);
$missingReplySequence = invitationPolicyChange($accept, static function ($event) {
	$event->remove('SEQUENCE');
});
invitationPolicyRejects($before, $missingReplySequence, 'alice@example.test', 'An attendee without a current revision must not inherit organizer legacy compatibility', PreconditionFailed::class);
$newParticipant = invitationPolicyChange($before, static function ($event) {
	$event->add('ATTENDEE', 'mailto:new@example.test', array('PARTSTAT' => 'ACCEPTED', 'RSVP' => 'FALSE'));
});
$participantPlan = InvitationPolicy::plan($before, $newParticipant, 'organizer@example.test', false);
invitationPolicyAssert(invitationPolicyState($participantPlan)['attendees']['mailto:new@example.test'] === array('partstat' => 'NEEDS-ACTION', 'rsvp' => 'TRUE'), 'Organizers must not fabricate acceptance for new invitees');
$resized = invitationPolicyChange($before, static function ($event) {
	$event->DTEND = '20261120T120000Z';
});
invitationPolicyAssert(InvitationPolicy::plan($before, $resized, 'organizer@example.test')['dates']['end'] === strtotime('2026-11-20 12:00 UTC'),
	'A native organizer may resize a standalone invitation');
invitationPolicyAssert(InvitationPolicy::plan($before, $resized, 'organizer@example.test', false)['sequence'] === 5, 'DAV-created invitations may retain their ordinary duration editing capability');

$provisional = invitationPolicyChange($before, static function ($event) {
	$event->STATUS = 'TENTATIVE';
});
$provisionalPlan = InvitationPolicy::plan($before, $provisional, 'organizer@example.test');
invitationPolicyAssert($provisionalPlan['changed'] && !$provisionalPlan['date_changed'] && $provisionalPlan['sequence'] === 5
	&& invitationPolicyState($provisionalPlan)['status'] === 'TENTATIVE'
	&& invitationPolicyState($provisionalPlan)['attendees'] === $original['attendees'],
	'A native organizer may mark its invitation provisional without changing participant responses');
$confirmedAgain = invitationPolicyChange($provisionalPlan['calendar_data'], static function ($event) {
	$event->STATUS = 'CONFIRMED';
});
invitationPolicyAssert(InvitationPolicy::plan($provisionalPlan['calendar_data'], $confirmedAgain, 'organizer@example.test')['sequence'] === 6,
	'Confirming a provisional invitation must advance the original revision once');
$tentativeResponse = InvitationPolicy::plan($before, $davx5, 'alice@example.test');
invitationPolicyAssert($tentativeResponse['status'] === 'CONFIRMED' && $tentativeResponse['partstat'] === 'TENTATIVE',
	'A tentative participant response must never change the global appointment state');
$attendeeHint = invitationPolicyChange($accept, static function ($event) {
	$event->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'TENTATIVE';
	$event->STATUS = 'TENTATIVE';
});
invitationPolicyAssert(InvitationPolicy::plan($before, $attendeeHint, 'alice@example.test')['status'] === 'CONFIRMED',
	'An Outlook personal availability hint must never give an attendee organizer authority');
$outlookGlobal = invitationPolicyChange($before, static function ($event) {
	$event->{'X-MICROSOFT-CDO-BUSYSTATUS'} = 'TENTATIVE';
});
invitationPolicyAssert(InvitationPolicy::plan($before, $outlookGlobal, 'organizer@example.test')['status'] === 'TENTATIVE',
	'An authorized organizer Provisional edit from CalDav Synchronizer must update global state');
foreach (array('unknown', 'duplicate', 'business') as $invalidState) {
	$invalid = invitationPolicyChange($provisional, static function ($event) use ($invalidState) {
		if ($invalidState === 'unknown') $event->STATUS = 'UNKNOWN';
		if ($invalidState === 'duplicate') $event->add('STATUS', 'CONFIRMED');
		if ($invalidState === 'business') $event->CLASS = 'PRIVATE';
	});
	invitationPolicyRejects($before, $invalid, 'organizer@example.test', 'Invalid state changes must not bypass protected native fields',
		$invalidState === 'business' ? Forbidden::class : BadRequest::class);
}

$cancel = invitationPolicyChange($before, static function ($event) {
	$event->STATUS = 'CANCELLED';
	$event->TRANSP = 'TRANSPARENT';
	$event->remove('VALARM');
});
$cancelled = InvitationPolicy::plan($before, $cancel, 'organizer@example.test');
$cancelState = invitationPolicyState($cancelled);
invitationPolicyAssert(!str_contains(InvitationPolicy::nativeMetadata($cancelled, $shared), 'VALARM'), 'Cancelling a protected invitation must deactivate stored alarms');
invitationPolicyAssert($cancelled['kind'] === 'cancel' && $cancelled['sequence'] === 5 && $cancelState['status'] === 'CANCELLED' && $cancelState['transp'] === 'TRANSPARENT', 'Only an organizer cancellation may cancel and free the shared event');
invitationPolicyAssert($cancelState['uid'] === $original['uid'] && $cancelState['description'] === $original['description'] && $cancelState['attendees'] === $original['attendees'], 'Cancellation must preserve stable identity, native notes and all participant associations');
invitationPolicyAssert(InvitationPolicy::plan($cancelled['calendar_data'], $cancelled['calendar_data'], 'organizer@example.test')['kind'] === 'noop', 'Replaying a cancellation must be idempotent');
$initialOutlookCancellation = invitationPolicyChange($cancel, static function ($event) {
	$event->SUMMARY = 'Cancelled: '.(string) $event->SUMMARY;
});
$initialCancelPlan = InvitationPolicy::plan($before, $initialOutlookCancellation, 'organizer@example.test');
invitationPolicyAssert($initialCancelPlan['kind'] === 'cancel' && invitationPolicyState($initialCancelPlan)['summary'] === $original['summary'],
	'An explicit initial Outlook cancellation may add its exact display prefix while retaining the native subject');
$invalidCancelSubject = invitationPolicyChange($initialOutlookCancellation, static function ($event) {
	$event->SUMMARY = 'Cancelled: Unrelated business replacement';
});
invitationPolicyRejects($before, $invalidCancelSubject, 'organizer@example.test', 'An initial cancellation must not adopt an unrelated client subject');

$cancelledOutlookEcho = invitationPolicyChange($cancelled['calendar_data'], static function ($event) {
	$event->SUMMARY = 'Cancelled: '.(string) $event->SUMMARY;
});
foreach (array('organizer@example.test', 'alice@example.test') as $actor) {
	$cancelEcho = InvitationPolicy::plan($cancelled['calendar_data'], $cancelledOutlookEcho, $actor);
	invitationPolicyAssert($cancelEcho['kind'] === 'noop' && invitationPolicyState($cancelEcho)['summary'] === $original['summary'],
		'An Outlook cancelled invitation display prefix must not reject a replay or change its trusted subject');
}

$resurrection = invitationPolicyChange($cancelled['calendar_data'], static function ($event) {
	$event->STATUS = 'CONFIRMED';
});
invitationPolicyRejects($cancelled['calendar_data'], $resurrection, 'organizer@example.test', 'A cancelled invitation must not be resurrected implicitly');
$afterCancelReply = invitationPolicyChange($cancelled['calendar_data'], static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'ACCEPTED';
});
invitationPolicyRejects($cancelled['calendar_data'], $afterCancelReply, 'alice@example.test', 'Responses after cancellation must not reactivate personal occupancy');
$cancelAndMove = invitationPolicyChange($cancel, static function ($event) {
	$event->DTSTART = '20261121T090000Z';
	$event->DTEND = '20261121T100000Z';
});
invitationPolicyRejects($before, $cancelAndMove, 'organizer@example.test', 'A cancellation must not hide a simultaneous date replacement');

$recurring = invitationPolicyChange($before, static function ($event) {
	$event->RRULE = 'FREQ=WEEKLY;COUNT=3';
});
invitationPolicyAssert(InvitationPolicy::plan($recurring, $recurring, 'organizer@example.test')['kind'] === 'noop', 'An unchanged master-only recurrence must remain usable');
$recurringAccept = invitationPolicyChange($recurring, static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'ACCEPTED';
});
invitationPolicyAssert(InvitationPolicy::plan($recurring, $recurringAccept, 'alice@example.test')['kind'] === 'reply', 'A master-only recurrence response may retain its unchanged RRULE');
$exceptions = invitationPolicyChange($recurring, static function ($event, $calendar) {
	$exception = clone $event;
	$exception->{'RECURRENCE-ID'} = '20261127T090000Z';
	$exception->DTSTART = '20261127T090000Z';
	$exception->DTEND = '20261127T100000Z';
	$exception->remove('RRULE');
	$calendar->add($exception);
});
invitationPolicyAssert(InvitationPolicy::plan($exceptions, $exceptions, 'organizer@example.test')['kind'] === 'noop', 'A complete unchanged legacy exception series must not regress');
$exceptionReply = invitationPolicyChange($exceptions, static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'ACCEPTED';
});
invitationPolicyRejects($exceptions, $exceptionReply, 'alice@example.test', 'Scoped recurrence responses must fail explicitly until their native persistence is implemented');

$repeatedAttendee = static function ($event) {
	$copy = clone $event->ATTENDEE;
	$copy->setValue(strtoupper((string) $copy));
	$copy['ROLE'] = 'req-participant';
	$copy['CUTYPE'] = 'INDIVIDUAL';
	$event->add($copy);
};
$legacyRepeated = invitationPolicyChange($before, $repeatedAttendee);
$repeatedReply = invitationPolicyChange($accept, $repeatedAttendee);
$deduplicated = InvitationPolicy::plan($legacyRepeated, $repeatedReply, 'alice@example.test');
invitationPolicyAssert($deduplicated['kind'] === 'reply' && invitationPolicyState($deduplicated) === $state
	&& substr_count($deduplicated['calendar_data'], 'ATTENDEE;') === 2,
	'Equivalent repeated attendees must yield one canonical response per address');

// Two clients may put the equivalent, expanded row first on different syncs.
$clientAttendees = static function ($calendarData, $product, $expandedFirst, $bareAddress) {
	return invitationPolicyChange($calendarData, static function ($master, $calendar) use ($product, $expandedFirst, $bareAddress) {
		$calendar->PRODID = $product;
		foreach ($calendar->select('VEVENT') as $event) {
			$compact = clone $event->select('ATTENDEE')[1]; // Bob, the peer whose answer must survive Alice's response.
			unset($compact['RSVP']);
			$expanded = clone $compact;
			$expanded->setValue($bareAddress ? 'BOB@EXAMPLE.TEST' : 'MAILTO:BOB@EXAMPLE.TEST');
			$expanded['ROLE'] = 'req-participant';
			$expanded['CUTYPE'] = 'individual';
			$expanded['RSVP'] = 'false';
			$expanded['PARTSTAT'] = 'accepted';
			$event->remove($event->select('ATTENDEE')[1]);
			$event->add($expandedFirst ? $expanded : $compact);
			$event->add($expandedFirst ? $compact : $expanded);
		}
	});
};
foreach (array(false, true) as $expandedFirst) {
	$stored = $clientAttendees($before, '-//Outlook CalDavSynchronizer//EN', $expandedFirst, true);
	$answer = $clientAttendees($accept, '-//DAVx5//EN', !$expandedFirst, true);
	$echo = $clientAttendees($before, '-//DAVx5//EN', !$expandedFirst, true);
	invitationPolicyAssert(InvitationPolicy::plan($stored, $echo, 'organizer@example.test')['kind'] === 'noop'
		&& InvitationPolicy::plan($stored, $echo, 'alice@example.test')['kind'] === 'noop', 'Reordering equivalent address forms must preserve an unchanged appointment');
	$reply = InvitationPolicy::plan($stored, $answer, 'alice@example.test');
	$answers = invitationPolicyState($reply)['attendees'];
	$bob = $answers['mailto:bob@example.test'] ?? $answers['bob@example.test'];
	invitationPolicyAssert($reply['kind'] === 'reply' && $reply['sequence'] === 4 && count($answers) === 2
		&& $answers['mailto:alice@example.test']['partstat'] === 'ACCEPTED' && strtoupper($bob['partstat']) === 'ACCEPTED'
		&& InvitationPolicy::plan($reply['calendar_data'], $answer, 'alice@example.test')['kind'] === 'noop',
		'A response and its replay must preserve the peer response regardless of which equivalent attendee was first');
	$series = $clientAttendees($exceptions, '-//DAVx5//EN', $expandedFirst, false);
	$seriesEcho = $clientAttendees($exceptions, '-//Outlook CalDavSynchronizer//EN', !$expandedFirst, false);
	invitationPolicyAssert(InvitationPolicy::plan($series, $seriesEcho, 'alice@example.test')['kind'] === 'noop',
		'A series with an exception must recognize equivalent attendee defaults in either order');
}
foreach (array('CN' => 'Another person', 'ROLE' => 'NON-PARTICIPANT', 'PARTSTAT' => 'DECLINED') as $parameter => $value) {
	$ambiguous = invitationPolicyChange($accept, static function ($event) use ($parameter, $value) {
		$copy = clone $event->ATTENDEE;
		$copy[$parameter] = $value;
		$event->add($copy);
	});
	invitationPolicyRejects($before, $ambiguous, 'alice@example.test', 'Attendee copies with contradictory '.$parameter.' must be rejected', BadRequest::class);
}

foreach (array('duplicate-attendee', 'empty-attendee', 'duplicate-uid', 'duplicate-sequence', 'negative-sequence', 'invalid-rsvp') as $invalid) {
	$bad = invitationPolicyChange($accept, static function ($event) use ($invalid) {
		if ($invalid === 'duplicate-attendee') {
			$copy = clone $event->ATTENDEE;
			$copy['ROLE'] = 'NON-PARTICIPANT';
			$event->add($copy);
		}
		if ($invalid === 'empty-attendee') $event->add('ATTENDEE', 'mailto:');
		if ($invalid === 'duplicate-uid') $event->add('UID', 'another-uid');
		if ($invalid === 'duplicate-sequence') $event->add('SEQUENCE', 5);
		if ($invalid === 'negative-sequence') $event->SEQUENCE = -1;
		if ($invalid === 'invalid-rsvp') $event->ATTENDEE['RSVP'] = 'MAYBE';
	});
	invitationPolicyRejects($before, $bad, 'alice@example.test', 'Malformed invitation state must be rejected: '.$invalid, BadRequest::class);
}
$maxSequence = invitationPolicyChange($before, static function ($event) {
	$event->SEQUENCE = 2147483647;
});
$maxMove = invitationPolicyChange($maxSequence, static function ($event) {
	$event->DTSTART = '20261121T090000Z';
	$event->DTEND = '20261121T100000Z';
});
invitationPolicyRejects($maxSequence, $maxMove, 'organizer@example.test', 'Organizer revision overflow must fail safely');
$noSequence = invitationPolicyChange($before, static function ($event) {
	$event->remove('SEQUENCE');
});
$firstResponse = invitationPolicyChange($noSequence, static function ($event) {
	$event->ATTENDEE['PARTSTAT'] = 'ACCEPTED';
	$event->SEQUENCE = 1;
});
invitationPolicyAssert(InvitationPolicy::plan($noSequence, $firstResponse, 'alice@example.test')['sequence'] === 0, 'An absent server sequence must behave as zero for Outlook first responses');
$ordinary = invitationPolicyChange($before, static function ($event) {
	$event->remove('ORGANIZER');
});
invitationPolicyAssert(InvitationPolicy::plan($ordinary, $ordinary, 'alice@example.test') === null, 'Ordinary appointments must continue through the existing write policy');

// Outlook may transport updates received by mail from an external organizer.
// Only the backend can authorize this exception for an unshared, DAV-created copy.
$externalUpdate = invitationPolicyChange($before, static function ($event) {
	$event->SEQUENCE = 12;
	$event->SUMMARY = 'Microsoft 365 organizer update';
	$event->LOCATION = 'New external meeting room';
	$event->DESCRIPTION = 'Updated external organizer body';
	$event->DTSTART = '20261124T100000Z';
	$event->DTEND = '20261124T120000Z';
});
invitationPolicyRejects($before, $externalUpdate, 'alice@example.test', 'A regular attendee must never gain organizer authority', PreconditionFailed::class, false);
invitationPolicyRejects($before, $externalUpdate, 'alice@example.test', 'The import flag cannot unprotect a native invitation', PreconditionFailed::class, true, true);
$externalPlan = InvitationPolicy::plan($before, $externalUpdate, 'alice@example.test', false, true);
$externalState = invitationPolicyState($externalPlan);
invitationPolicyAssert($externalPlan['kind'] === 'organizer' && $externalPlan['external_import'] && !$externalPlan['actor_is_organizer']
	&& $externalPlan['sequence'] === 12 && $externalPlan['date_changed'], 'An authorized private import must retain the actual external organizer revision without claiming organizer identity');
invitationPolicyAssert($externalState['summary'] === 'Microsoft 365 organizer update' && $externalState['description'] === 'Updated external organizer body'
	&& $externalState['uid'] === $original['uid'], 'External updates must replace their calendar fields while retaining the original UID');
$externalReply = InvitationPolicy::plan($before, $accept, 'alice@example.test', false, true);
invitationPolicyAssert($externalReply['kind'] === 'reply' && !$externalReply['external_import'] && $externalReply['sequence'] === 4,
	'A normal personal response must not be reinterpreted as an external organizer update or advance its revision');
$externalCancel = invitationPolicyChange($externalPlan['calendar_data'], static function ($event) {
	$event->SEQUENCE = 14;
	$event->STATUS = 'CANCELLED';
	$event->TRANSP = 'TRANSPARENT';
});
$externalCancelled = InvitationPolicy::plan($externalPlan['calendar_data'], $externalCancel, 'alice@example.test', false, true);
invitationPolicyAssert($externalCancelled['kind'] === 'cancel' && $externalCancelled['external_import'] && $externalCancelled['sequence'] === 14
	&& !$externalCancelled['actor_is_organizer'] && invitationPolicyState($externalCancelled)['status'] === 'CANCELLED',
	'A cancellation transported from the external organizer must retain its revision and close the original event');
invitationPolicyRejects($externalPlan['calendar_data'], $before, 'alice@example.test', 'A stale external request must not undo a newer organizer update', PreconditionFailed::class, false, true);
$forgedExternalUid = invitationPolicyChange($externalUpdate, static function ($event) {
	$event->UID = 'replacement-for-duplicate';
});
invitationPolicyRejects($before, $forgedExternalUid, 'alice@example.test', 'The private import exception must not replace the original event identity', Forbidden::class, false, true);
invitationPolicyRejects($before, $externalUpdate, 'outsider@example.test', 'The private import exception must not authorize another calendar actor', Forbidden::class, false, true);
$externalDecline = InvitationPolicy::plan($before, $decline, 'alice@example.test', false, true);
invitationPolicyAssert($externalDecline['kind'] === 'reply' && !$externalDecline['external_import'] && invitationPolicyState($externalDecline)['status'] === 'CONFIRMED',
	'A personal decline of an external invitation must remain a personal response');

fwrite(STDOUT, "[PASS] Invitation policy: authenticated responses, immutable business data, stable UID, organizer revisions and cancellation.\n");
