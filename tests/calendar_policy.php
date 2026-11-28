<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Real Sabre parsing checks for the date-only native appointment policy. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavCalendarWritePolicy.php';
require dirname(__DIR__).'/class/CDavSchedulingPlugin.php';

use Dolibarr\CDav\CalendarWritePolicy;

/**
 * Fail the suite on a broken invariant.
 *
 * @param bool $condition Expected invariant
 * @param string $message Failure explanation
 * @return void
 */
function calendarPolicyAssert($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

/**
 * Require an explicit business-policy refusal.
 *
 * @param string $before Native representation
 * @param string $after Replacement representation
 * @param string $message Failure explanation
 * @return void
 */
function calendarPolicyRejects($before, $after, $message)
{
	try {
		CalendarWritePolicy::assertMoveOnly($before, $after);
	} catch (Sabre\DAV\Exception\Forbidden $e) {
		return;
	}
	throw new RuntimeException($message);
}

/**
 * Build a native appointment representation for semantic parsing.
 *
 * @param string $dates Serialized endpoints
 * @param string $extra Additional protected properties
 * @return string Serialized calendar
 */
function calendarPolicyEvent($dates, $extra = '')
{
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav policy tests//EN\r\nBEGIN:VEVENT\r\nUID:42-ev-policy\r\nSUMMARY:Native appointment\r\nDESCRIPTION:First line\\nSecond line\r\nLOCATION:Client address\r\nPRIORITY:5\r\nCLASS:PUBLIC\r\nTRANSP:OPAQUE\r\nSTATUS:CONFIRMED\r\n"
		.$dates."\r\n".$extra."END:VEVENT\r\nEND:VCALENDAR\r\n";
}

// A native alarm returned by the server must never become a new DAV-owned row.
$nativeReminder = array('rowid' => 41, 'entity' => 1, 'fk_user' => 7, 'fk_soc' => null, 'fk_contact' => null,
	'typeremind' => 'browser', 'offsetvalue' => 15, 'offsetunit' => 'i', 'status' => 0, 'cdav_reminder_id' => null);
$alarm15 = array('start' => 1760000000, 'value' => 15, 'unit' => 'i');
$alarm30 = array('start' => 1760000000, 'value' => 30, 'unit' => 'i');
$plan = CalendarWritePolicy::planReminders(array($nativeReminder), array($alarm15), 7);
calendarPolicyAssert($plan === array('delete' => array(), 'preserve' => array(41), 'create' => array()),
	'An echoed native reminder must retain its ID and ownership without duplicate creation');
$duplicateNative = array_replace($nativeReminder, array('rowid' => 42));
$plan = CalendarWritePolicy::planReminders(array($nativeReminder, $duplicateNative), array($alarm15), 7);
calendarPolicyAssert($plan['preserve'] === array(41, 42) && !$plan['create'] && !$plan['delete'],
	'Existing duplicate native rows on Dolibarr 24 must not cause another reminder or destructive cleanup');
$ownedReminder = array_replace($nativeReminder, array('rowid' => 43, 'offsetvalue' => 30, 'cdav_reminder_id' => 43));
$plan = CalendarWritePolicy::planReminders(array($nativeReminder, $ownedReminder), array($alarm15, $alarm30), 7);
calendarPolicyAssert($plan['preserve'] === array(41, 43) && !$plan['create'] && !$plan['delete'],
	'An unchanged DAV-owned reminder must also retain its identity and status');
$plan = CalendarWritePolicy::planReminders(array($nativeReminder, $ownedReminder), array($alarm15), 7);
calendarPolicyAssert($plan['delete'] === array(43) && $plan['preserve'] === array(41),
	'Only an explicitly removed DAV-owned pending reminder may be deleted');
$plan = CalendarWritePolicy::planReminders(array($nativeReminder), array($alarm15, $alarm30), 7);
calendarPolicyAssert($plan['create'] === array($alarm30) && $plan['preserve'] === array(41),
	'Adding an alarm must create only the new offset while preserving the native reminder');
foreach (array(array(), array($alarm30)) as $replacementAlarms) {
	try {
		CalendarWritePolicy::planReminders(array($nativeReminder), $replacementAlarms, 7);
		throw new RuntimeException('A native reminder was removed or changed through CalDAV');
	} catch (Sabre\DAV\Exception\Forbidden $e) {
		// Native alarm changes must be refused before scheduling and business writes.
	}
}
foreach (array(1, -1) as $processedStatus) {
	$processed = array_replace($nativeReminder, array('status' => $processedStatus));
	$plan = CalendarWritePolicy::planReminders(array($processed), array(), 7);
	calendarPolicyAssert($plan['preserve'] === array(41) && !$plan['create'] && !$plan['delete'], 'Sent and failed native reminders must remain historical records');
	try {
		CalendarWritePolicy::planReminders(array($processed), array($alarm15), 7);
		throw new RuntimeException('A processed reminder was rearmed by an echoed alarm');
	} catch (Sabre\DAV\Exception\Forbidden $e) {
		// A stale or newly requested alarm must not reset a native processed status.
	}
}
$ownedHistory = array_replace($ownedReminder, array('status' => 1));
calendarPolicyAssert(CalendarWritePolicy::planReminders(array($ownedHistory), array(), 7)['preserve'] === array(43),
	'A regular edit must retain processed DAV-owned reminder history too');
foreach (array(array_replace($nativeReminder, array('fk_user' => 8)), array_replace($nativeReminder, array('fk_soc' => 99)),
	array_replace($nativeReminder, array('fk_contact' => 99)), array_replace($nativeReminder, array('typeremind' => 'email')),
	array_replace($nativeReminder, array('typeremind' => 'sms'))) as $foreignReminder) {
	$plan = CalendarWritePolicy::planReminders(array($foreignReminder), array($alarm15), 7);
	calendarPolicyAssert($plan['create'] === array($alarm15) && !$plan['delete'], 'Different recipients and non-browser reminder types must never be adopted or deleted');
}
$hourReminder = array_replace($nativeReminder, array('offsetvalue' => 60));
calendarPolicyAssert(CalendarWritePolicy::planReminders(array($hourReminder), array(array('start' => $alarm15['start'], 'value' => 1, 'unit' => 'h')), 7)['preserve'] === array(41),
	'Equivalent elapsed-hour and minute offsets must reconcile to the same native row');
$dayReminder = array_replace($nativeReminder, array('offsetvalue' => 1, 'offsetunit' => 'd'));
try {
	CalendarWritePolicy::planReminders(array($dayReminder), array(array('start' => $alarm15['start'], 'value' => 24, 'unit' => 'h')), 7);
	throw new RuntimeException('A calendar-day reminder was silently replaced by a fixed elapsed duration');
} catch (Sabre\DAV\Exception\Forbidden $e) {
	// Calendar days and fixed hours differ at daylight-saving boundaries.
}
calendarPolicyAssert(count(CalendarWritePolicy::planReminders(array(), array($alarm15, $alarm15), 7)['create']) === 1,
	'Duplicate incoming alarms must create at most one native reminder');

// The native rich text and generated CRM context must survive repeated GET/PUT.
$noteParts = array('note_prefix' => "Rendez-vous\nObjet : Native appointment\n\n", 'note_suffix' => "\n\nClient et contact\nTiers : Client SA\n\nProjet\n[PRJ-42] Native project",
	'native_note' => '<p>Original <strong>rich</strong> note</p>', 'notes_editable' => true);
$noteParts['text'] = $noteParts['note_prefix'].'Original rich note'.$noteParts['note_suffix'];
for ($echo = 0; $echo < 2; $echo++) {
	calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($noteParts, $noteParts['text']) === $noteParts['native_note'],
		'Repeated enriched echoes must preserve exact native HTML without duplicating business context');
}
calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($noteParts, 'Original rich note') === $noteParts['native_note'],
	'A plain equivalent note must retain its original rich SQL text');
calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($noteParts, "Raw client replacement\nProject : my own paragraph") === "Raw client replacement\nProject : my own paragraph",
	'A client that PUTs plain notes after CREATE must remain compatible and retain its own section-like lines');
calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($noteParts, '') === '', 'A raw replacement may clear editable notes without detaching business links');
$editedNote = "Edited native note\n\nClient et contact\nMy own paragraph must remain.";
calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($noteParts, $noteParts['note_prefix'].$editedNote.$noteParts['note_suffix']) === $editedNote,
	'Only the exact note region may be imported; user-authored section-like lines within it must survive');
$replacementPrefix = str_replace('Native appointment', 'New allowed subject', $noteParts['note_prefix']);
calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($noteParts, $replacementPrefix.'Original rich note'.$noteParts['note_suffix'], $replacementPrefix) === $noteParts['native_note'],
	'An authorized subject/address echo must preserve native HTML when its note region is unchanged');
calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($noteParts, $replacementPrefix.$editedNote.$noteParts['note_suffix'], $replacementPrefix) === $editedNote,
	'Authorized field changes may accompany an edited note while retaining the trusted CRM suffix');
try {
	CalendarWritePolicy::nativeNoteForReplacement($noteParts, str_replace('New allowed subject', 'Inconsistent subject', $replacementPrefix).'Original rich note'.$noteParts['note_suffix'], $replacementPrefix);
	throw new RuntimeException('An inconsistent generated subject was accepted');
} catch (Sabre\DAV\Exception\Forbidden $e) {
	// Displayed mutable fields must correspond to the authorized native replacements.
}
foreach (array(str_replace('Client SA', 'Another company', $noteParts['text']),
	str_replace('[PRJ-42]', '[PRJ-99]', $noteParts['text']), str_replace('Rendez-vous', 'Changed heading', $noteParts['text'])) as $changedEnvelope) {
	try {
		CalendarWritePolicy::nativeNoteForReplacement($noteParts, $changedEnvelope);
		throw new RuntimeException('A changed generated customer/project envelope was accepted');
	} catch (Sabre\DAV\Exception\Forbidden $e) {
		// Business-context edits belong to the native Dolibarr interface.
	}
}
$bookingParts = $noteParts;
$bookingParts['notes_editable'] = false;
calendarPolicyAssert(CalendarWritePolicy::nativeNoteForReplacement($bookingParts, $bookingParts['text']) === $bookingParts['native_note'],
	'A structured booking echo must retain all original booking fields');
foreach (array('New notes', $bookingParts['native_note'], $bookingParts['note_prefix'].'Changed'.$bookingParts['note_suffix']) as $changedBooking) {
	try {
		CalendarWritePolicy::nativeNoteForReplacement($bookingParts, $changedBooking);
		throw new RuntimeException('Structured booking notes were changed through CalDAV');
	} catch (Sabre\DAV\Exception\Forbidden $e) {
		// Structured client details remain native even when event protection is disabled.
	}
}

date_default_timezone_set('Europe/Zurich');
$task = implode("\r\n", array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CDav//EN', 'BEGIN:VTODO',
	'UID:native-agenda-task', 'DTSTART;TZID=Europe/Zurich:20261028T093000', 'DUE;TZID=Europe/Zurich:20261029T164500',
	'SUMMARY:Relire le dossier', 'DESCRIPTION:Appointment\nAppointment subject : Relire le dossier',
	'URL:https://example.test/comm/action/card.php?id=71', 'LOCATION:Native office', 'TRANSP:TRANSPARENT', 'CLASS:PUBLIC',
	'PRIORITY:0', 'STATUS:COMPLETED', 'PERCENT-COMPLETE:100', 'SEQUENCE:7', 'END:VTODO', 'END:VCALENDAR', ''));
$outlookTask = implode("\r\n", array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Task client//EN', 'BEGIN:VTODO',
	'UID:native-agenda-task', 'DTSTART:20261028T000000', 'DUE:20261029T235959', 'SUMMARY:Relire le dossier',
	'DESCRIPTION:Appointment\nAppointment subject : Relire le dossier\n', 'PRIORITY:5', 'CLASS:PUBLIC', 'STATUS:COMPLETED',
	'PERCENT-COMPLETE:100', 'COMPLETED:20261029T230000Z', 'SEQUENCE:8', 'END:VTODO', 'END:VCALENDAR', ''));
$echo = CalendarWritePolicy::planTaskChange($task, $outlookTask);
calendarPolicyAssert(!$echo['changed'] && !$echo['date_changed'] && $echo['sequence'] === 7 && !$echo['fields'],
	'A rebuilt, already-completed Outlook task must preserve its hours, original priority, note and revision.');
$allDayTask = str_replace(array('DTSTART;TZID=Europe/Zurich:20261028T093000', 'DUE;TZID=Europe/Zurich:20261029T164500'),
	array('DTSTART;VALUE=DATE:20261028', 'DUE;VALUE=DATE:20261029'), $task);
calendarPolicyAssert(!CalendarWritePolicy::planTaskChange($allDayTask, $outlookTask)['changed'],
	'An Outlook day-level echo must also retain the original native all-day flag.');
$inProgress = str_replace(array('STATUS:COMPLETED', 'PERCENT-COMPLETE:100'), array('STATUS:IN-PROCESS', 'PERCENT-COMPLETE:50'), $task);
$complete = CalendarWritePolicy::planTaskChange($inProgress, $outlookTask);
calendarPolicyAssert($complete['fields'] === array('percentage' => 100) && $complete['sequence'] === 8 && !$complete['date_changed'],
	'Marking a native task complete must change only its progress.');
$taskMetadata = CalendarWritePolicy::metadataAfterStatusChange('', $complete);
calendarPolicyAssert(str_contains($taskMetadata, 'COMPLETED:20261029T230000Z') && !str_contains($taskMetadata, 'STATUS:')
	&& !str_contains($taskMetadata, 'DESCRIPTION:'), 'Completion metadata must retain only the verified timestamp and server-owned protocol fields.');
$reopen = CalendarWritePolicy::planTaskChange($complete['calendar_data'], str_replace(array('STATUS:COMPLETED', 'PERCENT-COMPLETE:100'),
	array('STATUS:NEEDS-ACTION', 'PERCENT-COMPLETE:0'), $complete['calendar_data']));
calendarPolicyAssert($reopen['fields'] === array('percentage' => 0)
	&& !str_contains(CalendarWritePolicy::metadataAfterStatusChange($taskMetadata, $reopen), 'COMPLETED:'),
	'Reopening a native task must clear its completion date even if a client echoed the previous one.');
$edited = str_replace(array('SUMMARY:Relire le dossier', '20261029T235959', 'STATUS:COMPLETED', 'PERCENT-COMPLETE:100', "COMPLETED:20261029T230000Z\r\n"),
	array('SUMMARY:Rapport final', '20261030T235959', 'STATUS:IN-PROCESS', 'PERCENT-COMPLETE:30', ''), $outlookTask);
$plan = CalendarWritePolicy::planTaskChange($task, $edited);
calendarPolicyAssert($plan['fields'] === array('label' => 'Rapport final', 'percentage' => 30) && $plan['resized']
	&& date('Ymd His', $plan['dates']['end']) === '20261030 164500',
	'Editing the day of the deadline in Outlook must keep its native clock time while updating authorized fields.');
$noDue = preg_replace('/DUE:[^\r]+\r\n/', '', $outlookTask);
calendarPolicyAssert(CalendarWritePolicy::planTaskChange($task, $noDue)['dates']['task_without_due'],
	'Clearing a deadline must leave a missing native end instead of creating a synthetic duration.');
$addDue = CalendarWritePolicy::planTaskChange(str_replace("DUE;VALUE=DATE:20261029\r\n", '', $allDayTask), $outlookTask);
calendarPolicyAssert($addDue['resized'] && !$addDue['dates']['task_without_due'] && $addDue['dates']['fullday'] === 1
	&& date('Ymd His', $addDue['dates']['end']) === '20261029 000000', 'Adding a deadline to an all-day task must preserve its native date type.');
$fractionOnly = str_replace(array("STATUS:COMPLETED\r\n", 'PERCENT-COMPLETE:100'), array('', 'PERCENT-COMPLETE:30'), $task);
calendarPolicyAssert(CalendarWritePolicy::planTaskChange($task, $fractionOnly)['fields'] === array('percentage' => 30),
	'Clients may send just a native percentage and the exported status follows Dolibarr.');
$statusOnly = str_replace(array("PERCENT-COMPLETE:100\r\n", 'STATUS:COMPLETED'), array('', 'STATUS:NEEDS-ACTION'), $task);
calendarPolicyAssert(CalendarWritePolicy::planTaskChange($task, $statusOnly)['fields'] === array('percentage' => 0),
	'Clients may reopen a completed task using only STATUS.');
foreach (array(str_replace('UID:native-agenda-task', 'UID:other', $outlookTask),
	str_replace('END:VTODO', "ATTENDEE:mailto:another@example.test\r\nEND:VTODO", $outlookTask),
	str_replace('END:VTODO', "URL:https://example.test/other\r\nEND:VTODO", $outlookTask),
	str_replace('STATUS:COMPLETED', 'STATUS:CANCELLED', $outlookTask), str_replace('20261029T230000Z', '20260230T230000Z', $outlookTask),
	str_replace('PERCENT-COMPLETE:100', 'PERCENT-COMPLETE:101', $outlookTask),
	str_replace('PERCENT-COMPLETE:100', 'PERCENT-COMPLETE:40', $outlookTask),
	str_replace('PERCENT-COMPLETE:100', 'PERCENT-COMPLETE:12foo', $outlookTask),
	str_replace('PERCENT-COMPLETE:100', "PERCENT-COMP\r\n LETE;VALUE=INTEGER:bad", $outlookTask),
	str_replace('PERCENT-COMPLETE:100', "PERCENT-COMPLETE:100\r\nPERCENT-COMPLETE:20", $outlookTask),
	str_replace(array('STATUS:COMPLETED', 'PERCENT-COMPLETE:100'), array('STATUS:NEEDS-ACTION', 'PERCENT-COMPLETE:30'), $outlookTask),
	str_replace('VTODO', 'VEVENT', $outlookTask)) as $invalidTask) {
	try {
		CalendarWritePolicy::planTaskChange($task, $invalidTask);
		throw new RuntimeException('An invalid or protected native task change was accepted.');
	} catch (Sabre\DAV\Exception\Forbidden | Sabre\DAV\Exception\BadRequest $e) {
		// A failed task plan must produce no native or protocol writes.
	}
}
$before = calendarPolicyEvent("DTSTART;TZID=Europe/Zurich:20261120T100000\r\nDTEND;TZID=Europe/Zurich:20261120T110000",
	"ATTENDEE;CN=Alice;PARTSTAT=ACCEPTED:mailto:alice@example.test\r\n");
$moved = str_replace(array('20261120T100000', '20261120T110000'), array('20261121T140000', '20261121T150000'), $before);
$dates = CalendarWritePolicy::assertMoveOnly($before, $moved);
calendarPolicyAssert($dates['end'] - $dates['start'] === 3600 && $dates['fullday'] === 0, 'Moving an appointment must preserve its timed duration');

$equivalent = str_replace(array('DTSTART;TZID=Europe/Zurich:20261120T100000', 'DTEND;TZID=Europe/Zurich:20261120T110000',
	'ATTENDEE;CN=Alice;PARTSTAT=ACCEPTED:mailto:alice@example.test', 'SUMMARY:Native appointment'),
	array('DTEND:20261120T100000Z', 'DTSTART:20261120T090000Z',
		'ATTENDEE;PARTSTAT="ACCEPTED";CN="Alice":MAILTO:ALICE@EXAMPLE.TEST', "SUMMARY:Native app\r\n ointment"), $before);
$equivalent = str_replace('END:VEVENT', "DTSTAMP:20261118T000000Z\r\nSEQUENCE:5\r\nX-CLIENT-HINT:ignored\r\nEND:VEVENT", $equivalent);
calendarPolicyAssert(CalendarWritePolicy::assertMoveOnly($before, $equivalent)['start'] === CalendarWritePolicy::assertMoveOnly($before, $before)['start'],
	'Equivalent UTC dates, unfolded text, reordered parameters and volatile client hints must be accepted');

$duration = str_replace('DTEND;TZID=Europe/Zurich:20261120T110000', 'DURATION:PT1H', $before);
$durationDates = CalendarWritePolicy::assertMoveOnly($before, $duration);
calendarPolicyAssert($durationDates['end'] - $durationDates['start'] === 3600, 'Equivalent DTSTART plus DURATION must retain its endpoint');

$withSequence = str_replace('END:VEVENT', "SEQUENCE:4\r\nEND:VEVENT", $before);
calendarPolicyAssert(CalendarWritePolicy::metadataAfterMove($withSequence, $withSequence, CalendarWritePolicy::assertMoveOnly($withSequence, $withSequence)) === null,
	'An unchanged echo must not rewrite protocol metadata or advance its scheduling revision');
$firstMetadata = CalendarWritePolicy::metadataAfterMove($withSequence, $withSequence, $dates);
$firstRevision = Sabre\VObject\Reader::read($firstMetadata);
calendarPolicyAssert((int) (string) $firstRevision->VEVENT->SEQUENCE === 5
	&& (string) $firstRevision->VEVENT->ATTENDEE === 'mailto:alice@example.test', 'A move must advance the trusted revision while preserving original participants');
$secondExport = str_replace('END:VEVENT', "SEQUENCE:5\r\nEND:VEVENT", $moved);
$secondMove = str_replace('20261121T', '20261122T', $secondExport);
$secondDates = CalendarWritePolicy::assertMoveOnly($secondExport, str_replace('SEQUENCE:5', 'SEQUENCE:99999', $secondMove));
$secondMetadata = CalendarWritePolicy::metadataAfterMove($firstMetadata, $secondExport, $secondDates);
$secondRevision = Sabre\VObject\Reader::read($secondMetadata);
calendarPolicyAssert((int) (string) $secondRevision->VEVENT->SEQUENCE === 6, 'Successive moves must advance the server revision and ignore arbitrary client SEQUENCE values');
$minimalMetadata = CalendarWritePolicy::metadataAfterMove('', $before, $dates);
$minimalRevision = Sabre\VObject\Reader::read($minimalMetadata);
calendarPolicyAssert((int) (string) $minimalRevision->VEVENT->SEQUENCE === 1 && !isset($minimalRevision->VEVENT->DESCRIPTION)
	&& !isset($minimalRevision->VEVENT->LOCATION), 'The first native move must store protocol metadata without copying rendered business information');
try {
	CalendarWritePolicy::metadataAfterMove('', str_replace('END:VEVENT', "SEQUENCE:2147483647\r\nEND:VEVENT", $before), $dates);
	throw new RuntimeException('Scheduling revision overflow was accepted');
} catch (Sabre\DAV\Exception\Forbidden $e) {
	// Exhausted revision space must fail before the native business write.
}
$firstRevision->destroy();
$secondRevision->destroy();
$minimalRevision->destroy();

foreach (array('SUMMARY:Native appointment' => 'SUMMARY:Changed subject', 'LOCATION:Client address' => 'LOCATION:Different address',
	'DESCRIPTION:First line' => 'DESCRIPTION:Changed notes', 'UID:42-ev-policy' => 'UID:client-replacement',
	'PRIORITY:5' => 'PRIORITY:1', 'CLASS:PUBLIC' => 'CLASS:PRIVATE', 'TRANSP:OPAQUE' => 'TRANSP:TRANSPARENT',
	'STATUS:CONFIRMED' => 'STATUS:CANCELLED', 'PARTSTAT=ACCEPTED' => 'PARTSTAT=DECLINED',
	'alice@example.test' => 'another@example.test', 'BEGIN:VEVENT' => 'BEGIN:VTODO') as $old => $new) {
	$changed = str_replace($old, $new, $before);
	if ($old === 'BEGIN:VEVENT') {
		$changed = str_replace('END:VEVENT', 'END:VTODO', $changed);
	}
	calendarPolicyRejects($before, $changed, 'Protected field change must fail: '.$old);
}
$modifiedIdentity = str_replace(array('UID:42-ev-policy', 'alice@example.test'), array('UID:private-record', 'private@example.test'), $before);
try {
	CalendarWritePolicy::assertMoveOnly($before, $modifiedIdentity);
	throw new RuntimeException('A protected identity change was accepted');
} catch (Sabre\DAV\Exception\Forbidden $e) {
	calendarPolicyAssert(str_contains($e->getMessage(), 'UID') && str_contains($e->getMessage(), 'ATTENDEE')
		&& !str_contains($e->getMessage(), 'private'), 'The diagnostic must identify changed fields without including personal values');
}
foreach (array("ATTACH:https://files.example.test/new.pdf\r\n", "RRULE:FREQ=DAILY\r\n", "RDATE:20261201T090000Z\r\n",
	"EXDATE:20261120T090000Z\r\n", "RECURRENCE-ID:20261120T090000Z\r\n", "ORGANIZER:mailto:owner@example.test\r\n",
	"BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Reminder\r\nEND:VALARM\r\n",
	"DTSTART:20261120T090000Z\r\n", "DTEND:20261120T100000Z\r\n", "DURATION:PT1H\r\n") as $extra) {
	calendarPolicyRejects($before, str_replace('END:VEVENT', $extra.'END:VEVENT', $before), 'Additional protected data or ambiguous endpoints must fail');
}
calendarPolicyRejects($before, str_replace('20261120T110000', '20261120T120000', $before), 'Resizing is not moving a protected appointment');
calendarPolicyRejects($before, str_replace('20261120T110000', '20261120T090000', $before), 'Invalid endpoints must not be silently repaired');
calendarPolicyRejects($before, str_replace('END:VCALENDAR', "METHOD:REQUEST\r\nEND:VCALENDAR", $before), 'Scheduling METHOD must not be injected');
calendarPolicyRejects($before, str_replace('END:VCALENDAR', "BEGIN:VEVENT\r\nUID:42-ev-policy\r\nDTSTART:20261122T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR", $before),
	'A recurrence exception must not bypass protection');
calendarPolicyRejects($before, str_replace("LOCATION:Client address\r\n", '', $before), 'Omitted business data must not clear native fields');

$allDay = calendarPolicyEvent('DTSTART;VALUE=DATE:20261024');
$allDayMove = calendarPolicyEvent("DTSTART;VALUE=DATE:20261025\r\nDTEND;VALUE=DATE:20261026");
calendarPolicyAssert(CalendarWritePolicy::assertMoveOnly($allDay, $allDayMove)['fullday'] === 1,
	'All-day moves across the Zurich DST boundary must preserve calendar-day duration');
calendarPolicyRejects($allDay, calendarPolicyEvent("DTSTART;VALUE=DATE:20261025\r\nDTEND;VALUE=DATE:20261027"), 'Extending all-day appointments must fail');
calendarPolicyRejects($allDay, calendarPolicyEvent("DTSTART:20261025T000000Z\r\nDTEND:20261026T000000Z"), 'All-day conversion must fail');
$threeDays = calendarPolicyEvent("DTSTART;VALUE=DATE:20261024\r\nDTEND;VALUE=DATE:20261027");
$timed = calendarPolicyEvent("DTSTART:20261024T090000Z\r\nDTEND:20261024T100000Z");
$resized = CalendarWritePolicy::planStatusChange($timed, $threeDays);
calendarPolicyAssert($resized['resized'] && $resized['dates']['fullday'] === 1
	&& $resized['dates']['start'] === strtotime('2026-10-24 00:00:00 Europe/Zurich')
	&& $resized['dates']['end'] === strtotime('2026-10-27 00:00:00 Europe/Zurich'),
	'Switching to three whole days over the Swiss autumn clock change must preserve both local midnights');
calendarPolicyAssert(CalendarWritePolicy::planStatusChange($threeDays, $timed)['dates']['fullday'] === 0,
	'An owner may switch an individual appointment back to a timed event');
$series = str_replace('END:VEVENT', "RRULE:FREQ=WEEKLY\r\nEND:VEVENT", $before);
try {
	CalendarWritePolicy::planStatusChange($series, str_replace('20261120T110000', '20261120T120000', $series));
	throw new RuntimeException('A change to the duration of the recurring series was accepted');
} catch (Sabre\DAV\Exception\Forbidden $expected) {
	// A series may contain exceptions whose endpoints are maintained in Dolibarr.
}
$zero = calendarPolicyEvent("DTSTART:20261120T090000Z\r\nDTEND:20261120T090000Z");
$zeroMove = calendarPolicyEvent("DTSTART:20261121T090000Z\r\nDTEND:20261121T090000Z");
$zeroDates = CalendarWritePolicy::assertMoveOnly($zero, $zeroMove);
calendarPolicyAssert($zeroDates['start'] === $zeroDates['end'], 'Native zero-duration appointments must not acquire an invented hour');

// Global appointment state is separate from availability and attendee responses.
$tentative = str_replace('STATUS:CONFIRMED', 'STATUS:TENTATIVE', $before);
$statusPlan = CalendarWritePolicy::planStatusChange($before, $tentative);
calendarPolicyAssert($statusPlan['status'] === 'TENTATIVE' && $statusPlan['previous_status'] === 'CONFIRMED'
	&& $statusPlan['changed'] && !$statusPlan['date_changed'] && $statusPlan['sequence'] === 1,
	'A provisional native appointment must advance its server revision without changing its dates');
$provisionalCalendar = Sabre\VObject\Reader::read($statusPlan['calendar_data']);
calendarPolicyAssert((string) $provisionalCalendar->VEVENT->DESCRIPTION === "First line\nSecond line"
	&& (string) $provisionalCalendar->VEVENT->ATTENDEE['PARTSTAT'] === 'ACCEPTED'
	&& (string) $provisionalCalendar->VEVENT->TRANSP === 'OPAQUE',
	'Global state changes must retain native notes, participant responses and independent occupancy');
$provisionalCalendar->destroy();
calendarPolicyAssert(CalendarWritePolicy::planStatusChange($tentative, $before)['status'] === 'CONFIRMED',
	'A provisional appointment can be confirmed through the authorized status policy');
calendarPolicyAssert(!CalendarWritePolicy::planStatusChange($tentative, $tentative)['changed'], 'An identical provisional replay must be idempotent');
calendarPolicyAssert(!CalendarWritePolicy::planStatusChange($before, str_replace("STATUS:CONFIRMED\r\n", '', $before))['changed'],
	'An omitted legacy STATUS must mean confirmed');
$free = str_replace('TRANSP:OPAQUE', 'TRANSP:TRANSPARENT', $before);
calendarPolicyAssert(str_contains(CalendarWritePolicy::planStatusChange($free,
	str_replace('STATUS:CONFIRMED', 'STATUS:TENTATIVE', $free))['calendar_data'], 'TRANSP:TRANSPARENT'),
	'An intentionally free appointment must remain free when its confirmation state changes');
$outlookBusy = str_replace('END:VEVENT', "X-MICROSOFT-CDO-BUSYSTATUS:BUSY\r\nEND:VEVENT", $before);
$outlookProvisional = str_replace('BUSYSTATUS:BUSY', 'BUSYSTATUS:TENTATIVE', $outlookBusy);
calendarPolicyAssert(CalendarWritePolicy::planStatusChange($outlookBusy, $outlookProvisional)['status'] === 'TENTATIVE',
	'CalDav Synchronizer marks Provisional through its changed Microsoft availability hint');
$exportedProvisional = str_replace('STATUS:CONFIRMED', 'STATUS:TENTATIVE', $outlookProvisional);
calendarPolicyAssert(CalendarWritePolicy::planStatusChange($exportedProvisional,
	str_replace('BUSYSTATUS:TENTATIVE', 'BUSYSTATUS:BUSY', $exportedProvisional))['status'] === 'CONFIRMED',
	'Changing the Outlook display from Provisional to Busy must confirm the appointment');
calendarPolicyAssert(CalendarWritePolicy::planStatusChange($exportedProvisional,
	str_replace("\r\nSTATUS:TENTATIVE", "\r\nSTATUS:CONFIRMED", $exportedProvisional))['status'] === 'CONFIRMED',
	'A DAVx5 STATUS edit must override a preserved Microsoft hint');
calendarPolicyAssert(!CalendarWritePolicy::planStatusChange($exportedProvisional,
	str_replace("\r\nSTATUS:TENTATIVE\r\n", "\r\n", $exportedProvisional))['changed'],
	'An Outlook echo without STATUS must retain its unchanged Provisional hint');
$cancelStatus = str_replace(array('STATUS:CONFIRMED', 'TRANSP:OPAQUE'), array('STATUS:CANCELLED', 'TRANSP:TRANSPARENT'), $before);
$cancellation = CalendarWritePolicy::planStatusChange($before, $cancelStatus);
calendarPolicyAssert($cancellation['kind'] === 'cancel' && $cancellation['status'] === 'CANCELLED'
	&& str_contains($cancellation['calendar_data'], 'TRANSP:TRANSPARENT'), 'Explicit cancellation must free the shared appointment');
calendarPolicyAssert(!CalendarWritePolicy::planStatusChange($cancellation['calendar_data'], $cancellation['calendar_data'])['changed'],
	'A cancelled standalone echo must be idempotent');
foreach (array(
	array($before, str_replace('STATUS:CONFIRMED', 'STATUS:UNKNOWN', $before), Sabre\DAV\Exception\BadRequest::class),
	array($before, str_replace('STATUS:CONFIRMED', 'STATUS:', $before), Sabre\DAV\Exception\BadRequest::class),
	array($before, str_replace('END:VEVENT', "STATUS:TENTATIVE\r\nEND:VEVENT", $before), Sabre\DAV\Exception\BadRequest::class),
	array($outlookBusy, str_replace('END:VEVENT', "X-MICROSOFT-CDO-BUSYSTATUS:TENTATIVE\r\nEND:VEVENT", $outlookBusy), Sabre\DAV\Exception\BadRequest::class),
	array($before, str_replace('SUMMARY:Native appointment', 'SUMMARY:', $tentative), Sabre\DAV\Exception\BadRequest::class),
	array($before, str_replace('LOCATION:Client address', 'LOCATION:0', $before), Sabre\DAV\Exception\BadRequest::class),
	array($before, str_replace('TRANSP:OPAQUE', 'TRANSP:UNKNOWN', $tentative), Sabre\DAV\Exception\BadRequest::class),
	array($before, str_replace('PRIORITY:5', 'PRIORITY:12', $tentative), Sabre\DAV\Exception\BadRequest::class),
	array($before, str_replace('20261120T', '20261121T', $cancelStatus), Sabre\DAV\Exception\Forbidden::class),
	array($cancellation['calendar_data'], $before, Sabre\DAV\Exception\Forbidden::class),
	array($cancellation['calendar_data'], str_replace('20261120T', '20261121T', $cancellation['calendar_data']), Sabre\DAV\Exception\Forbidden::class),
) as $invalidStatusCase) {
	try {
		CalendarWritePolicy::planStatusChange($invalidStatusCase[0], $invalidStatusCase[1]);
		throw new RuntimeException('An invalid, unauthorized or ambiguous status transition was accepted');
	} catch (Sabre\DAV\Exception $error) {
		calendarPolicyAssert($error instanceof $invalidStatusCase[2], 'A status refusal must use the expected protocol error');
	}
}

$fieldsCalendar = Sabre\VObject\Reader::read($before);
$fieldsCalendar->VEVENT->LOCATION = "Rue de la Gare 8\n1003 Lausanne";
calendarPolicyAssert(CalendarWritePolicy::planStatusChange($before, $fieldsCalendar->serialize())['fields']['location'] === "Rue de la Gare 8\n1003 Lausanne",
	'An address pasted on two lines must remain a valid native appointment location');
$heading = "Appointment\nAppointment subject : Native appointment\n\n";
$suffix = "\n\nProject\n[P-12] Client\n\nTask\n[T-23] Review";
$descriptionContext = array('text' => $heading.'Meeting notes'.$suffix, 'note_prefix' => $heading, 'note_suffix' => $suffix,
	'native_note' => '<p>Meeting <strong>notes</strong></p>', 'notes_editable' => true,
	'note_prefix_fields' => array('original_subject' => 'Native appointment', 'original_location' => ''),
	'prefix_for_fields' => static fn($context, $title, $location) => "Appointment\nAppointment subject : ".$title
		.($location === '' ? '' : "\nAppointment address : ".$location)."\n\n");
$fieldsCalendar->VEVENT->DESCRIPTION = $descriptionContext['text'];
$sourceFields = $fieldsCalendar->serialize();
$fieldsCalendar->VEVENT->SUMMARY = 'Client review';
$newHeading = $descriptionContext['prefix_for_fields']($descriptionContext, 'Client review', '');
$fieldsCalendar->VEVENT->DESCRIPTION = $newHeading.'Meeting notes'.$suffix;
$titlePlan = CalendarWritePolicy::planStatusChange($sourceFields, $fieldsCalendar->serialize(), $descriptionContext);
calendarPolicyAssert($titlePlan['fields'] === array('label' => 'Client review') && $titlePlan['sequence'] === 1
	&& !$titlePlan['date_changed'], 'Renaming the appointment must retain the rich note and keep a derived address out of the native location');
$fieldsCalendar->VEVENT->LOCATION = 'Rue du Lac 24';
$fieldsCalendar->VEVENT->PRIORITY = 2;
$fieldsCalendar->VEVENT->TRANSP = 'TRANSPARENT';
$newHeading = $descriptionContext['prefix_for_fields']($descriptionContext, 'Client review', 'Rue du Lac 24');
$fieldsCalendar->VEVENT->DESCRIPTION = $newHeading."New notes\nNext line".$suffix;
$fieldPlan = CalendarWritePolicy::planStatusChange($sourceFields, $fieldsCalendar->serialize(), $descriptionContext);
calendarPolicyAssert($fieldPlan['fields'] === array('label' => 'Client review', 'location' => 'Rue du Lac 24', 'priority' => 2,
	'transparency' => 0, 'note' => "New notes\nNext line") && $fieldPlan['sequence'] === 1,
	'A combined update must extract only native appointment fields and advance one revision');
$fieldsCalendar->VEVENT->DESCRIPTION = $newHeading.'New notes'.str_replace('[T-23]', '[T-24]', $suffix);
try {
	CalendarWritePolicy::planStatusChange($sourceFields, $fieldsCalendar->serialize(), $descriptionContext);
	throw new RuntimeException('A title and note change permitted altering generated task context');
} catch (Sabre\DAV\Exception\Forbidden $error) {
	// The entire update is rejected.
}
$fieldsCalendar->VEVENT->DESCRIPTION = $newHeading.'Meeting notes'.$suffix;
$descriptionContext['notes_editable'] = false;
calendarPolicyAssert(!isset(CalendarWritePolicy::planStatusChange($sourceFields, $fieldsCalendar->serialize(), $descriptionContext)['fields']['note']),
	'An Outlook heading refresh on a structured booking must not rewrite the original receipt or contact details');
$fieldsCalendar->VEVENT->DESCRIPTION = 'Changed structured booking';
try {
	CalendarWritePolicy::planStatusChange($sourceFields, $fieldsCalendar->serialize(), $descriptionContext);
	throw new RuntimeException('Structured booking information was editable');
} catch (Sabre\DAV\Exception\Forbidden $error) {
	// Structured visitor details remain under native control.
}
$fieldsCalendar->destroy();
$freePlan = CalendarWritePolicy::planStatusChange($outlookBusy, str_replace('BUSYSTATUS:BUSY', 'BUSYSTATUS:FREE', $outlookBusy));
calendarPolicyAssert($freePlan['fields'] === array('transparency' => 0) && $freePlan['status'] === 'CONFIRMED',
	'An Outlook Free display change must update personal availability without cancelling the meeting');

$nativeAlarm = "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nDESCRIPTION:Native appointment\r\nEND:VALARM\r\n";
$withPersonalAlarm = str_replace('END:VEVENT', $nativeAlarm.'END:VEVENT', $before);
list($sharedComparison, $requestedReminders) = CalendarWritePolicy::personalReminderChange($before, $withPersonalAlarm);
calendarPolicyAssert(count($requestedReminders) === 1 && $requestedReminders[0]['unit'] === 'i' && $requestedReminders[0]['value'] === 15
	&& !CalendarWritePolicy::planStatusChange($before, $sharedComparison)['changed'],
	'A new personal reminder must not advance the shared appointment revision');
list($sharedComparison, $requestedReminders) = CalendarWritePolicy::personalReminderChange($withPersonalAlarm,
	str_replace('TRIGGER:-PT15M', 'TRIGGER:-PT30M', $withPersonalAlarm));
calendarPolicyAssert($requestedReminders[0]['value'] === 30 && !CalendarWritePolicy::planStatusChange($withPersonalAlarm, $sharedComparison)['changed'],
	'A reminder offset edit must stay personal and retain all shared fields for comparison');
list($sharedComparison, $requestedReminders) = CalendarWritePolicy::personalReminderChange($withPersonalAlarm, $before);
calendarPolicyAssert($requestedReminders === array() && !CalendarWritePolicy::planStatusChange($withPersonalAlarm, $sharedComparison)['changed'],
	'Removing a personal reminder must request only the native ownership-checked removal');
list($sharedComparison, $requestedReminders) = CalendarWritePolicy::personalReminderChange($withPersonalAlarm, str_replace('-PT15M', '-PT900S', $withPersonalAlarm));
calendarPolicyAssert($requestedReminders === null && !CalendarWritePolicy::planStatusChange($withPersonalAlarm, $sharedComparison)['changed'],
	'Equivalent personal reminders must keep their native IDs without shared writes');
foreach (array('TRIGGER;RELATED=END:-PT15M', 'TRIGGER:-PT35S', "ATTACH:https://example.test/file\r\nTRIGGER:-PT15M") as $unsupported) {
	$alarmChange = str_replace('TRIGGER:-PT15M', $unsupported, $withPersonalAlarm);
	list($sharedComparison) = CalendarWritePolicy::personalReminderChange($before, $alarmChange);
	calendarPolicyRejects($before, $sharedComparison, 'An unrepresentable alarm must remain protected');
}

$outlookFirstCancel = str_replace('SUMMARY:Native appointment', 'SUMMARY:Cancelled: Native appointment', $cancelStatus);
$firstOutlookCancellation = CalendarWritePolicy::planStatusChange($before, $outlookFirstCancel);
calendarPolicyAssert($firstOutlookCancellation['kind'] === 'cancel'
	&& str_contains($firstOutlookCancellation['calendar_data'], 'SUMMARY:Native appointment'),
	'An explicit first Outlook cancellation may carry its exact display prefix without replacing the native subject');
try {
	CalendarWritePolicy::planStatusChange($before, str_replace('SUMMARY:Native appointment', 'SUMMARY:Cancelled: Changed subject', $cancelStatus));
	throw new RuntimeException('An initial cancellation permitted an unrelated business subject replacement');
} catch (Sabre\DAV\Exception\Forbidden $error) {
	// An explicit cancellation grants only the exact connector presentation prefix.
}

$outlookCancelledEcho = str_replace('SUMMARY:Native appointment', 'SUMMARY:Cancelled: Native appointment', $cancellation['calendar_data']);
$cancelEchoPlan = CalendarWritePolicy::planStatusChange($cancellation['calendar_data'], $outlookCancelledEcho);
calendarPolicyAssert(!$cancelEchoPlan['changed'] && str_contains($cancelEchoPlan['calendar_data'], 'SUMMARY:Native appointment')
	&& !str_contains($cancelEchoPlan['calendar_data'], 'SUMMARY:Cancelled:'),
	'An already cancelled Outlook echo may add its exact display prefix without changing the native subject');
try {
	CalendarWritePolicy::planStatusChange($cancellation['calendar_data'],
		str_replace('SUMMARY:Native appointment', 'SUMMARY:Cancelled: Changed subject', $cancellation['calendar_data']));
	throw new RuntimeException('A cancelled display prefix permitted an unrelated native subject replacement');
} catch (Sabre\DAV\Exception\Forbidden $error) {
	// The display exception is exact and never authorizes another subject.
}

$minimalStatusMetadata = CalendarWritePolicy::metadataAfterStatusChange('', $statusPlan);
$protocolMetadata = Sabre\VObject\Reader::read($minimalStatusMetadata);
calendarPolicyAssert((string) $protocolMetadata->VEVENT->STATUS === 'TENTATIVE'
	&& (int) (string) $protocolMetadata->VEVENT->SEQUENCE === 1 && !isset($protocolMetadata->VEVENT->SUMMARY)
	&& !isset($protocolMetadata->VEVENT->DESCRIPTION) && !isset($protocolMetadata->VEVENT->LOCATION)
	&& !isset($protocolMetadata->VEVENT->ATTENDEE), 'A first state edit must persist only protocol metadata, never rendered CRM context');
$protocolMetadata->destroy();
$trustedStatusMetadata = CalendarWritePolicy::metadataAfterStatusChange($withSequence,
	CalendarWritePolicy::planStatusChange($withSequence, str_replace('STATUS:CONFIRMED', 'STATUS:TENTATIVE', $withSequence)));
$trustedCalendar = Sabre\VObject\Reader::read($trustedStatusMetadata);
calendarPolicyAssert((string) $trustedCalendar->VEVENT->DESCRIPTION === "First line\nSecond line"
	&& (string) $trustedCalendar->VEVENT->ATTENDEE['PARTSTAT'] === 'ACCEPTED'
	&& (int) (string) $trustedCalendar->VEVENT->SEQUENCE === 5 && (string) $trustedCalendar->VEVENT->STATUS === 'TENTATIVE',
	'An existing trusted sidecar must retain its original participant and note metadata when state changes');
$trustedCalendar->destroy();
$dateOnlyMetadata = Sabre\VObject\Reader::read(CalendarWritePolicy::metadataAfterStatusChange('',
	CalendarWritePolicy::planStatusChange($before, $moved)));
calendarPolicyAssert(!isset($dateOnlyMetadata->VEVENT->STATUS) && !isset($dateOnlyMetadata->VEVENT->DESCRIPTION),
	'A date-only state plan must not introduce a global STATUS or business description into minimal metadata');
$dateOnlyMetadata->destroy();
$cancelMetadata = Sabre\VObject\Reader::read(CalendarWritePolicy::metadataAfterStatusChange($withSequence,
	CalendarWritePolicy::planStatusChange($withSequence, str_replace('STATUS:CONFIRMED', 'STATUS:CANCELLED', $withSequence))));
calendarPolicyAssert((string) $cancelMetadata->VEVENT->STATUS === 'CANCELLED'
	&& (string) $cancelMetadata->VEVENT->TRANSP === 'TRANSPARENT' && !isset($cancelMetadata->VEVENT->VALARM),
	'Cancellation protocol metadata must remain cancelled, transparent and without pending alarms');
$cancelMetadata->destroy();
calendarPolicyAssert(CalendarWritePolicy::metadataAfterStatusChange('', CalendarWritePolicy::planStatusChange($before, $before)) === null,
	'An unchanged appointment state must not create or rewrite a protocol sidecar');

// Exercise the actual scheduling hook: effects wait for a successful response.
$backend = new class($before) {
	public $current;
	public $present = true;
	/** @param string $before Native representation */
	public function __construct($before)
	{
		$this->current = $before;
	}
	/**
	 * Validate using the real policy while replacing database access.
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $uri Object basename
	 * @param string $calendar Replacement calendar
	 * @param bool $creating Whether a new resource is requested
	 * @return array<string,int> Validated dates
	 */
	public function validateProtectedCalendarWrite($calendarId, $uri, $calendar, $creating = false)
	{
		return CalendarWritePolicy::assertMoveOnly($this->current, $calendar);
	}
	/**
	 * Return the currently committed representation.
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $uri Object basename
	 * @return array<string,string> Native representation
	 */
	public function getCalendarObject($calendarId, $uri)
	{
		return array('calendardata' => $this->current);
	}
	/**
	 * Expose the protected fixture used by the deletion hook.
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $uri Object basename
	 * @return array<string,mixed> Protected native event
	 */
	public function getProtectedCalendarObject($calendarId, $uri)
	{
		return array('id' => 42, 'calendardata' => $this->current);
	}
	/**
	 * Distinguish a committed deletion from a shared detachment.
	 *
	 * @param int $eventId Native event identifier
	 * @return bool Whether the native record still exists
	 */
	public function calendarEventExists($eventId)
	{
		return $this->present;
	}
};
$scheduler = new class($backend) extends Dolibarr\CDav\SchedulingPlugin {
	public $notifications = array();
	public $cancellations = array();
	/**
	 * Capture notifications without delivering invitations to real accounts.
	 *
	 * @param object $oldCalendar Previous native representation
	 * @param object $newCalendar Committed native representation
	 * @param string $ownerPrincipal Calendar owner principal
	 * @return void
	 */
	public function notifyManagedAttachmentChange($oldCalendar, $newCalendar, $ownerPrincipal)
	{
		$this->notifications[] = $newCalendar->serialize();
	}
	/**
	 * Resolve only the isolated organizer address.
	 *
	 * @param string $principal Calendar owner principal
	 * @return array<string> Organizer addresses
	 */
	protected function getAddressesForPrincipal($principal)
	{
		return array('mailto:owner@example.test');
	}
	/**
	 * Capture cancellations without delivery to real accounts.
	 *
	 * @param Sabre\VObject\ITip\Message $message Cancellation message
	 * @return void
	 */
	public function deliver(Sabre\VObject\ITip\Message $message)
	{
		$this->cancellations[] = $message;
	}
};
$server = new Sabre\DAV\Server();
$server->tree = new class extends Sabre\DAV\Tree {
	/** Keep the test tree independent of native server collections. */
	public function __construct()
	{
	}
	/**
	 * Resolve the isolated calendar owner.
	 *
	 * @param string $path Requested calendar path
	 * @return object Inert calendar node
	 */
	public function getNodeForPath($path)
	{
		return new class {
			/** @return string Isolated owner principal */
			public function getOwner()
			{
				return 'principals/test';
			}
		};
	}
};
$scheduler->initialize($server);
$request = new Sabre\HTTP\Request('PUT', '/calendars/test/1-cal-policy/42-ev-policy');
$response = new Sabre\HTTP\Response(204);
$modified = false;
$vCal = Sabre\VObject\Reader::read($moved);
$scheduler->calendarObjectChange($request, $response, $vCal, 'calendars/test/1-cal-policy', $modified, false);
calendarPolicyAssert(count($scheduler->notifications) === 0, 'A protected move must not notify before commit');
$response->setStatus(412);
$scheduler->notifyProtectedMoves($request, $response);
calendarPolicyAssert(count($scheduler->notifications) === 0, 'A refused concurrent move must not notify attendees');
$scheduler->calendarObjectChange($request, $response, $vCal, 'calendars/test/1-cal-policy', $modified, false);
$backend->current = $moved;
$response->setStatus(204);
$scheduler->notifyProtectedMoves($request, $response);
calendarPolicyAssert(count($scheduler->notifications) === 1 && strpos($scheduler->notifications[0], '20261121T140000') !== false,
	'A committed move must notify once using its saved representation');
$scheduler->notifyProtectedMoves($request, $response);
calendarPolicyAssert(count($scheduler->notifications) === 1, 'Scheduling flush must not duplicate notifications');
$vCal->destroy();

$deleteRequest = new Sabre\HTTP\Request('DELETE', '/calendars/test/1-cal-policy/42-ev-policy');
$server->httpRequest = $deleteRequest;
$backend->current = str_replace('END:VEVENT', "ORGANIZER:mailto:owner@example.test\r\nEND:VEVENT", $before);
$scheduler->beforeUnbind('calendars/test/1-cal-policy/42-ev-policy');
calendarPolicyAssert(count($scheduler->cancellations) === 0, 'Protected deletion must not cancel invitations before commit');
$response->setStatus(412);
$scheduler->notifyProtectedDeletes($deleteRequest, $response);
calendarPolicyAssert(count($scheduler->cancellations) === 0, 'A stale deletion must not cancel invitations');
$scheduler->beforeUnbind('calendars/test/1-cal-policy/42-ev-policy');
$response->setStatus(204);
$scheduler->notifyProtectedDeletes($deleteRequest, $response);
calendarPolicyAssert(count($scheduler->cancellations) === 0, 'A shared-calendar detachment must not cancel the appointment for everyone');
$scheduler->beforeUnbind('calendars/test/1-cal-policy/42-ev-policy');
$backend->present = false;
$scheduler->notifyProtectedDeletes($deleteRequest, $response);
calendarPolicyAssert(count($scheduler->cancellations) === 1, 'Final committed deletion must send the actual cancellation');
$scheduler->notifyProtectedDeletes($deleteRequest, $response);
calendarPolicyAssert(count($scheduler->cancellations) === 1, 'Deletion flush must not duplicate its cancellation');

fwrite(STDOUT, "Protected native calendar policy and deferred scheduling regressions passed.\n");
