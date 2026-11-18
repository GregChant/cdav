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
 * @param bool $protect Whether native date-only protection is enabled
	 * @param bool $external Backend-verified private external invitation import
 * @return void
 */
function invitationPolicyRejects($before, $after, $actor, $message, $exception = Forbidden::class, $protect = true, $external = false)
{
	try {
		InvitationPolicy::plan($before, $after, $actor, $protect, $external);
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
invitationPolicyRejects($before, $title, 'organizer@example.test', 'Native organizer business edits must remain protected');
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
invitationPolicyRejects($before, $resized, 'organizer@example.test', 'Native organizer moves must preserve duration');
invitationPolicyAssert(InvitationPolicy::plan($before, $resized, 'organizer@example.test', false)['sequence'] === 5, 'DAV-created invitations may retain their ordinary duration editing capability');

$cancel = invitationPolicyChange($before, static function ($event) {
	$event->STATUS = 'CANCELLED';
	$event->TRANSP = 'TRANSPARENT';
	$event->remove('VALARM');
});
$cancelled = InvitationPolicy::plan($before, $cancel, 'organizer@example.test');
$cancelState = invitationPolicyState($cancelled);
invitationPolicyAssert($cancelled['kind'] === 'cancel' && $cancelled['sequence'] === 5 && $cancelState['status'] === 'CANCELLED' && $cancelState['transp'] === 'TRANSPARENT', 'Only an organizer cancellation may cancel and free the shared event');
invitationPolicyAssert($cancelState['uid'] === $original['uid'] && $cancelState['description'] === $original['description'] && $cancelState['attendees'] === $original['attendees'], 'Cancellation must preserve stable identity, native notes and all participant associations');
invitationPolicyAssert(InvitationPolicy::plan($cancelled['calendar_data'], $cancelled['calendar_data'], 'organizer@example.test')['kind'] === 'noop', 'Replaying a cancellation must be idempotent');
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

foreach (array('duplicate-attendee', 'duplicate-uid', 'duplicate-sequence', 'negative-sequence', 'invalid-rsvp') as $invalid) {
	$bad = invitationPolicyChange($accept, static function ($event) use ($invalid) {
		if ($invalid === 'duplicate-attendee') $event->add(clone $event->ATTENDEE);
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
