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
$zero = calendarPolicyEvent("DTSTART:20261120T090000Z\r\nDTEND:20261120T090000Z");
$zeroMove = calendarPolicyEvent("DTSTART:20261121T090000Z\r\nDTEND:20261121T090000Z");
$zeroDates = CalendarWritePolicy::assertMoveOnly($zero, $zeroMove);
calendarPolicyAssert($zeroDates['start'] === $zeroDates['end'], 'Native zero-duration appointments must not acquire an invented hour');

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
