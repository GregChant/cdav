<?php

/** Availability feed privacy and recurrence regressions; no database required. */
date_default_timezone_set('UTC');
error_reporting(E_ALL & ~E_DEPRECATED);
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
if (!is_file($root.'/includes/sabre/autoload.php')) {
	fwrite(STDERR, "Set DOLIBARR_ROOT to a Dolibarr htdocs directory.\n");
	exit(2);
}
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavFreeBusyFilter.php';

function availabilityAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

function availabilityTimes($calendar)
{
	$expanded = $calendar->expand(new DateTime('2026-10-23T00:00:00Z'), new DateTime('2026-10-31T00:00:00Z'));
	$times = array();
	foreach ($expanded->select('VEVENT') as $event) {
		$times[] = $event->DTSTART->getDateTime()->format('c').'|'.$event->DTEND->getDateTime()->format('c');
	}
	sort($times);
	return $times;
}

$private = 'PRIVATE-SENTINEL';
$input = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tests//FR\r\n"
	."BEGIN:VTIMEZONE\r\nTZID:Europe/Zurich\r\nTZURL:https://PRIVATE-SENTINEL.example/timezone\r\nX-PRIVATE:PRIVATE-SENTINEL\r\n"
	."BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nRRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\r\nTZNAME:PRIVATE-SENTINEL\r\nEND:STANDARD\r\n"
	."BEGIN:DAYLIGHT\r\nDTSTART:19700329T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nRRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3\r\nEND:DAYLIGHT\r\nEND:VTIMEZONE\r\n"
	."BEGIN:VEVENT\r\nUID:PRIVATE-SENTINEL@customer.example\r\nDTSTAMP:20261001T080000Z\r\n"
	."DTSTART;TZID=Europe/Zurich;X-PRIVATE=PRIVATE-SENTINEL:20261024T100000\r\nDTEND;TZID=Europe/Zurich:20261024T110000\r\n"
	."RRULE:FREQ=DAILY;COUNT=4\r\nEXDATE;TZID=Europe/Zurich:20261025T100000\r\nRDATE;TZID=Europe/Zurich:20261030T100000\r\n"
	."SUMMARY:PRIVATE-SENTINEL\r\nDESCRIPTION:PRIVATE-SENTINEL\r\nLOCATION:PRIVATE-SENTINEL\r\n"
	."ATTACH;VALUE=BINARY;ENCODING=BASE64:UFJJVkFURS1TRU5USU5FTA==\r\nATTACH:https://PRIVATE-SENTINEL.example/file.pdf\r\n"
	."ATTENDEE;CN=PRIVATE-SENTINEL:mailto:PRIVATE-SENTINEL@example.test\r\nORGANIZER:mailto:PRIVATE-SENTINEL@example.test\r\n"
	."URL:https://PRIVATE-SENTINEL.example/event\r\nCONTACT:PRIVATE-SENTINEL\r\nRELATED-TO:PRIVATE-SENTINEL\r\nCATEGORIES:PRIVATE-SENTINEL\r\nX-PRIVATE:PRIVATE-SENTINEL\r\n"
	."STATUS:CONFIRMED\r\nTRANSP:OPAQUE\r\nX-MICROSOFT-CDO-BUSYSTATUS:BUSY\r\nSEQUENCE:3\r\n"
	."BEGIN:VALARM\r\nACTION:EMAIL\r\nTRIGGER:-PT15M\r\nDESCRIPTION:PRIVATE-SENTINEL\r\nEND:VALARM\r\nEND:VEVENT\r\n"
	."BEGIN:VEVENT\r\nUID:PRIVATE-SENTINEL@customer.example\r\nDTSTAMP:20261002T080000Z\r\nRECURRENCE-ID;TZID=Europe/Zurich:20261026T100000\r\n"
	."DTSTART;TZID=Europe/Zurich:20261026T130000\r\nDTEND;TZID=Europe/Zurich:20261026T140000\r\nSUMMARY:PRIVATE-SENTINEL\r\nEND:VEVENT\r\n"
	."BEGIN:VTODO\r\nUID:PRIVATE-SENTINEL-task\r\nDTSTAMP:20261001T080000Z\r\nDTSTART;VALUE=DATE:20261028\r\nDUE;VALUE=DATE:20261029\r\n"
	."SUMMARY:PRIVATE-SENTINEL\r\nDESCRIPTION:PRIVATE-SENTINEL\r\nSTATUS:NEEDS-ACTION\r\nPERCENT-COMPLETE:20\r\nATTACH:https://PRIVATE-SENTINEL.example/task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
$source = Sabre\VObject\Reader::read($input);
$sourceSerialized = $source->serialize();
$filtered = new Sabre\VObject\Component\VCalendar();
foreach ($source->getComponents() as $component) {
	$filtered->add($component->name === 'VTIMEZONE'
		? Dolibarr\CDav\FreeBusyFilter::timezoneComponent($component)
		: Dolibarr\CDav\FreeBusyFilter::calendarComponent($component, 'Occupé', '42', 'test-private-secret'));
}
$serialized = $filtered->serialize();
availabilityAssert(strpos($serialized, $private) === false, 'The availability feed must contain no private source value');
availabilityAssert(!preg_match('/(?:^|\r\n)(?:ATTACH|ATTENDEE|ORGANIZER|DESCRIPTION|LOCATION|URL|CONTACT|RELATED-TO|CATEGORIES|TZURL|TZNAME|X-PRIVATE|PERCENT-COMPLETE)[;:]/', $serialized),
	'Attachments, participants, links, notes and custom metadata must be absent');
availabilityAssert(strpos($serialized, 'VALARM') === false, 'Alarm components must never be exported through a shared availability feed');
availabilityAssert($sourceSerialized === $source->serialize(), 'Filtering must not mutate the full calendar');
availabilityAssert((string) $filtered->VEVENT->SUMMARY === 'Occupé', 'The busy label must retain the owner language');
availabilityAssert((string) $filtered->VEVENT->RRULE === 'FREQ=DAILY;COUNT=4', 'Recurring availability must retain its recurrence rule');
availabilityAssert((string) $filtered->VEVENT->STATUS === 'CONFIRMED' && (string) $filtered->VEVENT->TRANSP === 'OPAQUE', 'Busy and cancellation semantics must survive');
availabilityAssert(availabilityTimes($source) === availabilityTimes($filtered), 'Free/busy filtering must preserve expanded occurrence times, exclusions and exceptions across daylight saving changes');
$events = $filtered->select('VEVENT');
availabilityAssert((string) $events[0]->UID === (string) $events[1]->UID, 'A recurrence exception must share its master pseudonymous UID');
$repeat = Dolibarr\CDav\FreeBusyFilter::calendarComponent($source->VEVENT, 'Occupé', '42', 'test-private-secret');
$otherOwner = Dolibarr\CDav\FreeBusyFilter::calendarComponent($source->VEVENT, 'Occupé', '43', 'test-private-secret');
availabilityAssert((string) $repeat->UID === (string) $filtered->VEVENT->UID && (string) $otherOwner->UID !== (string) $repeat->UID,
	'Pseudonymous UIDs must stay stable while remaining scoped to the shared calendar');
availabilityAssert((string) $filtered->VTODO->DUE === '20261029' && (string) $filtered->VTODO->DTSTART['VALUE'] === 'DATE'
	&& (string) $filtered->VTODO->STATUS === 'NEEDS-ACTION', 'VTODO dates and status must remain intact');

$duration = Sabre\VObject\Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:duration-private\r\nDTSTART;VALUE=DATE:20261105\r\nDURATION:P1D\r\nSTATUS:PRIVATE-SENTINEL\r\nX-MICROSOFT-CDO-BUSYSTATUS:PRIVATE-SENTINEL\r\nSEQUENCE:PRIVATE-SENTINEL\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n")->VEVENT;
$allDay = Dolibarr\CDav\FreeBusyFilter::calendarComponent($duration, 'Occupé', '42', 'test-private-secret');
availabilityAssert((string) $allDay->DURATION === 'P1D' && (string) $allDay->DTSTART['VALUE'] === 'DATE', 'All-day durations must remain unchanged');
availabilityAssert(strpos($allDay->serialize(), $private) === false, 'Availability status properties must accept only their known standard values');
fwrite(STDOUT, "All CDav free/busy privacy and recurrence tests passed.\n");
