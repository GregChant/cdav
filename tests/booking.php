<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Booking business regressions A01–A15; no database or HTTP server required. */
date_default_timezone_set('Europe/Zurich');
error_reporting(E_ALL);
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
if (!is_file($root.'/includes/sabre/autoload.php')) {
	fwrite(STDERR, "Set DOLIBARR_ROOT to a Dolibarr htdocs directory.\n");
	exit(2);
}
$vendorDeprecations = 0;
$vendorRoot = realpath($root.'/includes/sabre').DIRECTORY_SEPARATOR;
set_error_handler(static function ($severity, $message, $file, $line) use ($vendorRoot, &$vendorDeprecations) {
	if ($severity === E_DEPRECATED && strpos($file, $vendorRoot) === 0) {
		$vendorDeprecations++;
		return true;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavBookingAvailability.php';

use Dolibarr\CDav\BookingAvailability;

/**
 * Assert an observable business outcome.
 *
 * @param bool $condition Expected true value
 * @param string $message Failure explanation
 * @return void
 */
function bookingCheck($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Assert fail-closed handling.
 *
 * @param callable $call Operation expected to fail
 * @param string $message Failure explanation
 * @return void
 */
function bookingThrows(callable $call, $message)
{
	$thrown = false;
	try { $call(); } catch (ErrorException $e) { throw $e; } catch (Throwable $e) { $thrown = true; }
	bookingCheck($thrown, $message);
}

/**
 * Build an explicit reference-zone test timestamp.
 *
 * @param string $date Test date/time
 * @return int Unix timestamp
 */
function bookingStamp($date)
{
	return (new DateTimeImmutable($date, new DateTimeZone('Europe/Zurich')))->getTimestamp();
}

/**
 * Wrap test events in an iCalendar resource.
 *
 * @param string $events Serialized events
 * @return string Calendar data
 */
function bookingCalendar($events)
{
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Booking tests//EN\r\n".$events."END:VCALENDAR\r\n";
}

/**
 * Build an event fixture.
 *
 * @param string $uid Event identity
 * @param string $start Local DTSTART value
 * @param string $end Local DTEND value, or empty
 * @param string $extra Additional iCalendar content
 * @return string Serialized event
 */
function bookingEvent($uid, $start, $end, $extra = '')
{
	return "BEGIN:VEVENT\r\nUID:".$uid."\r\nDTSTAMP:20260101T000000Z\r\n"
		.'DTSTART;TZID=Europe/Zurich:'.$start."\r\n"
		.($end !== '' ? 'DTEND;TZID=Europe/Zurich:'.$end."\r\n" : '')
		.$extra."END:VEVENT\r\n";
}

/**
 * Extract local labels from actual slot instants.
 *
 * @param array $slots Calculated slots
 * @return string[] Local start labels
 */
function bookingHours(array $slots)
{
	return array_map(static function ($slot) {
		return (new DateTimeImmutable('@'.$slot['start']))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('H:i');
	}, $slots);
}

$now = bookingStamp('2026-11-01 00:00');
$start = bookingStamp('2026-11-02 00:00');
$end = bookingStamp('2026-11-03 00:00');
$config = BookingAvailability::validateConfig(array('notice' => 0, 'weekly' => array(1 => array(array('13:00', '17:00')))));
$busy = array(array(bookingStamp('2026-11-02 14:00'), bookingStamp('2026-11-02 15:00')),
	array(bookingStamp('2026-11-02 14:15'), bookingStamp('2026-11-02 14:45')));
$slots = BookingAvailability::slots($config, $busy, $start, $end, $now);
bookingCheck(bookingHours($slots) === array('13:00', '13:30', '15:00', '15:30', '16:00', '16:30'), 'A01: all overlapping intervals must block full slot duration');
$partial = array(array(bookingStamp('2026-11-02 14:20'), bookingStamp('2026-11-02 14:40')));
bookingCheck(!in_array('14:00', bookingHours(BookingAvailability::slots($config, $partial, $start, $end, $now)), true)
	&& !in_array('14:30', bookingHours(BookingAvailability::slots($config, $partial, $start, $end, $now)), true), 'A01: partial overlaps block both possible starts');
fwrite(STDOUT, "[OK] A01 overlaps and nested occupations\n");

$config['weekly'][1] = array(array('08:00', '10:00'), array('11:00', '13:00'));
$config['buffer_before'] = 15;
$config['buffer_after'] = 15;
bookingCheck(bookingHours(BookingAvailability::slots($config, array(), $start, $end, $now)) === array('08:30', '09:00', '11:30', '12:00'), 'A02: buffers stay within each working range and lunch breaks');
$config['buffer_before'] = $config['buffer_after'] = 0;
$config['weekly'][1] = array(array('08:00', '13:00'));
$adjacent = BookingAvailability::slots($config, array(array(bookingStamp('2026-11-02 10:00'), bookingStamp('2026-11-02 11:00'))), $start, $end, $now);
bookingCheck(in_array('09:30', bookingHours($adjacent), true) && in_array('11:00', bookingHours($adjacent), true), 'A02: adjacent intervals use half-open boundaries');
foreach (array(array(0, 15), array(15, 0), array(10, 15)) as $margins) {
	$marginConfig = BookingAvailability::validateConfig(array('notice' => 0, 'step' => 5,
		'buffer_before' => $margins[0], 'buffer_after' => $margins[1],
		'weekly' => array(1 => array(array('08:00', '13:00')))));
	$early = bookingStamp('2026-11-02 09:00');
	$gap = array_sum($margins) * 60;
	foreach (array(0, $gap - 300, $gap) as $separation) {
		$late = $early + 1800 + $separation;
		foreach (array(array($early, $late), array($late, $early)) as $order) {
			$emptyStarts = array_column(BookingAvailability::slots($marginConfig, array(), $start, $end, $now), 'start');
			bookingCheck(in_array($order[0], $emptyStarts, true), 'A02: first booking is individually available');
			$occupied = array(array($order[0], $order[0] + 1800));
			$remainingStarts = array_column(BookingAvailability::slots($marginConfig, $occupied, $start, $end, $now), 'start');
			bookingCheck(in_array($order[1], $remainingStarts, true) === ($separation === $gap),
				'A02: each event reserves its margins; combined gap must be exact and independent of creation order');
		}
	}
}
fwrite(STDOUT, "[OK] A02 boundaries, buffers and breaks\n");

$allDay = bookingCalendar("BEGIN:VEVENT\r\nUID:all-day\r\nDTSTART;VALUE=DATE:20261101\r\nDTEND;VALUE=DATE:20261104\r\nEND:VEVENT\r\n");
bookingCheck(BookingAvailability::busyIntervals(array($allDay), $start, $end, 'Europe/Zurich') === array(array($start, $end)), 'A03: multi-day events block the entire requested day');
$midnight = bookingCalendar(bookingEvent('midnight', '20261101T230000', '20261102T010000'));
bookingCheck(BookingAvailability::busyIntervals(array($midnight), $start, $end, 'Europe/Zurich') === array(array($start, bookingStamp('2026-11-02 01:00'))), 'A03: previous-day starts block after midnight');
bookingThrows(static function () use ($start, $end) {
	BookingAvailability::busyIntervals(array(bookingCalendar(bookingEvent('no-end', '20261102T090000', ''))), $start, $end, 'Europe/Zurich');
}, 'A03: occupied event without end fails closed');
bookingThrows(static function () use ($start, $end) {
	BookingAvailability::busyIntervals(array(bookingCalendar(bookingEvent('reversed', '20261102T100000', '20261102T090000'))), $start, $end, 'Europe/Zurich');
}, 'A03: reversed event fails closed');
fwrite(STDOUT, "[OK] A03 all-day, midnight and invalid durations\n");

$recurrence = bookingCalendar(bookingEvent('recurring', '20261101T100000', '20261101T110000',
	"RRULE:FREQ=DAILY;COUNT=4\r\nEXDATE;TZID=Europe/Zurich:20261102T100000\r\nRDATE;TZID=Europe/Zurich:20261107T100000\r\n")
	.bookingEvent('recurring', '20261103T130000', '20261103T140000', "RECURRENCE-ID;TZID=Europe/Zurich:20261103T100000\r\n")
	.bookingEvent('recurring', '20261104T100000', '20261104T110000', "RECURRENCE-ID;TZID=Europe/Zurich:20261104T100000\r\nSTATUS:CANCELLED\r\n"));
$recurBusy = BookingAvailability::busyIntervals(array($recurrence), $start, bookingStamp('2026-11-08 00:00'), 'Europe/Zurich');
bookingCheck($recurBusy === array(array(bookingStamp('2026-11-03 13:00'), bookingStamp('2026-11-03 14:00')),
	array(bookingStamp('2026-11-07 10:00'), bookingStamp('2026-11-07 11:00'))), 'A04: RRULE/RDATE union, exclusions and moved/cancelled exceptions must survive');
$old = bookingCalendar(bookingEvent('old', '20200101T100000', '20200101T110000', "RRULE:FREQ=DAILY\r\n"));
bookingCheck(BookingAvailability::busyIntervals(array($old), $start, $end, 'Europe/Zurich') === array(array(bookingStamp('2026-11-02 10:00'), bookingStamp('2026-11-02 11:00'))), 'A04: old series still produces current occurrences');
$separateRdates = bookingCalendar(bookingEvent('rdate-zones', '20261101T100000', '20261101T110000',
	"RDATE;TZID=America/New_York:20261102T100000\r\nRDATE:20261102T170000Z\r\n"));
bookingCheck(BookingAvailability::busyIntervals(array($separateRdates), $start, $end, 'Europe/Zurich') === array(
	array(bookingStamp('2026-11-02 16:00'), bookingStamp('2026-11-02 17:00')),
	array(bookingStamp('2026-11-02 18:00'), bookingStamp('2026-11-02 19:00'))), 'A04: all RDATE properties retain their own timezone');
fwrite(STDOUT, "[OK] A04 recurrence, RDATE/EXDATE, exceptions and old series\n");

foreach (array('2026-03-29', '2026-10-25') as $date) {
	$dstStart = bookingStamp($date.' 00:00');
	$dstEnd = (new DateTimeImmutable($date.' 00:00', new DateTimeZone('Europe/Zurich')))->modify('+1 day')->getTimestamp();
	$dstConfig = BookingAvailability::validateConfig(array('notice' => 0, 'weekly' => array(7 => array(array('00:00', '05:00')))));
	$dstSlots = BookingAvailability::slots($dstConfig, array(), $dstStart, $dstEnd, $dstStart - 86400);
	bookingCheck(!in_array('02:00', bookingHours($dstSlots), true) && !in_array('02:30', bookingHours($dstSlots), true), 'A05: missing/ambiguous local hours are never offered');
	foreach ($dstSlots as $slot) bookingCheck($slot['end'] - $slot['start'] === 1800, 'A05: actual slot duration is preserved across DST');
}
$dstCalendar = bookingCalendar(bookingEvent('dst-series', '20261024T100000', '20261024T110000', "RRULE:FREQ=DAILY;COUNT=3\r\n"));
$dstBusy = BookingAvailability::busyIntervals(array($dstCalendar), bookingStamp('2026-10-24 00:00'), bookingStamp('2026-10-27 00:00'), 'Europe/Zurich');
bookingCheck(count($dstBusy) === 3 && $dstBusy[1][0] - $dstBusy[0][0] === 25 * 3600, 'A05: recurring local time survives fall-back transition');
foreach (array(array('20260328', '20260329', '2026-03-28', '2026-03-31'), array('20261024', '20261025', '2026-10-24', '2026-10-27')) as $range) {
	$allDayRecurring = bookingCalendar("BEGIN:VEVENT\r\nUID:all-day-recurring\r\nDTSTART;VALUE=DATE:".$range[0]."\r\nDTEND;VALUE=DATE:".$range[1]."\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n");
	$allDayBusy = BookingAvailability::busyIntervals(array($allDayRecurring), bookingStamp($range[2]), bookingStamp($range[3]), 'Europe/Zurich');
	bookingCheck($allDayBusy === array(array(bookingStamp($range[2]), bookingStamp($range[3]))), 'A05: recurring all-day events cover complete calendar days across both DST transitions');
}
fwrite(STDOUT, "[OK] A05 Europe/Zurich DST and real durations\n");

$config = BookingAvailability::validateConfig(array('notice' => 60, 'horizon' => 1, 'weekly' => array(1 => array(array('09:00', '17:00')))));
$clock = bookingStamp('2026-11-02 09:00');
bookingCheck(bookingHours(BookingAvailability::slots($config, array(), $start, $end, $clock))[0] === '10:00', 'A06: minimum notice includes exact boundary');
bookingCheck(BookingAvailability::slots($config, array(), bookingStamp('2026-11-09'), bookingStamp('2026-11-10'), $clock) === array(), 'A06: future dates beyond horizon stay unavailable');
foreach (array(array('duration' => 0), array('step' => '1e9'), array('horizon' => 181), array('timezone' => 'private/invalid'),
	array('weekly' => array(1 => array(array('10:00', '09:00')))), array('weekly' => array(1 => array(array('09:00', '11:00'), array('10:00', '12:00'))))) as $invalid) {
	bookingThrows(static function () use ($invalid) {
		BookingAvailability::validateConfig($invalid); }, 'A06: invalid profile rejected');
}
bookingThrows(static function () use ($config, $clock) {
	BookingAvailability::slots($config, array(), 0, $clock, $clock); }, 'A06: invalid date range rejected');
fwrite(STDOUT, "[OK] A06 notice, horizon and validation\n");

$free = bookingCalendar(bookingEvent('free', '20261102T090000', '', "TRANSP:TRANSPARENT\r\n")
	.bookingEvent('cancelled', '20261102T100000', '', "STATUS:CANCELLED\r\n")
	."BEGIN:VTODO\r\nUID:excluded-task\r\nDTSTART:20261102T100000Z\r\nDUE:20261102T110000Z\r\nEND:VTODO\r\n");
bookingCheck(BookingAvailability::busyIntervals(array($free), $start, $end, 'Europe/Zurich') === array(), 'A07: free, cancelled and VTODO resources do not block');
foreach (array(true, false) as $freeMaster) {
	foreach (array('10', '13') as $exceptionHour) {
		$availabilitySeries = bookingCalendar(bookingEvent('availability-exception', '20261101T100000', '20261101T110000',
			"RRULE:FREQ=DAILY;COUNT=3\r\nTRANSP:".($freeMaster ? 'TRANSPARENT' : 'OPAQUE')."\r\n")
			.bookingEvent('availability-exception', '20261102T'.$exceptionHour.'0000', '20261102T'.sprintf('%02d', (int) $exceptionHour + 1).'0000',
				"RECURRENCE-ID;TZID=Europe/Zurich:20261102T100000\r\nTRANSP:".($freeMaster ? 'OPAQUE' : 'TRANSPARENT')."\r\n"));
		$expectedBusy = $freeMaster
			? array(array(bookingStamp('2026-11-02 '.$exceptionHour.':00'), bookingStamp('2026-11-02 '.sprintf('%02d', (int) $exceptionHour + 1).':00')))
			: array(array(bookingStamp('2026-11-03 10:00'), bookingStamp('2026-11-03 11:00')));
		bookingCheck(BookingAvailability::busyIntervals(array($availabilitySeries), $start, bookingStamp('2026-11-04 00:00'), 'Europe/Zurich') === $expectedBusy,
			'A07: each recurrence exception controls its own availability, including moved instances and a free master');
	}
}
$seriesAnchor = new DateTimeImmutable('2026-11-19 12:00', new DateTimeZone('Europe/Zurich'));
$ancientMaster = $seriesAnchor->modify('-70 days');
$laterFreeException = $seriesAnchor->modify('+21 days');
$ordinaryAfterException = $seriesAnchor->modify('+23 days');
$ancientSeries = bookingCalendar(bookingEvent('ancient-opaque-master', $ancientMaster->format('Ymd\THis'), $ancientMaster->modify('+1 hour')->format('Ymd\THis'),
	"RRULE:FREQ=DAILY;COUNT=120\r\nTRANSP:OPAQUE\r\n")
	.bookingEvent('ancient-opaque-master', $laterFreeException->format('Ymd\THis'), $laterFreeException->modify('+1 hour')->format('Ymd\THis'),
		'RECURRENCE-ID;TZID=Europe/Zurich:'.$laterFreeException->format('Ymd\THis')."\r\nTRANSP:TRANSPARENT\r\n"));
foreach (array($laterFreeException, $ordinaryAfterException) as $checkedOccurrence) {
	$checkedDay = $checkedOccurrence->setTime(0, 0);
	$expected = $checkedOccurrence === $laterFreeException ? array()
		: array(array($checkedOccurrence->getTimestamp(), $checkedOccurrence->modify('+1 hour')->getTimestamp()));
	bookingCheck(BookingAvailability::busyIntervals(array($ancientSeries), $checkedDay->getTimestamp(), $checkedDay->modify('+1 day')->getTimestamp(), 'Europe/Zurich') === $expected,
		'A07: an old occupied series keeps ordinary occurrences after a future free exception across daylight saving changes');
}
bookingThrows(static function () use ($start, $end) {
	BookingAvailability::busyIntervals(array('not a calendar'), $start, $end, 'Europe/Zurich'); }, 'A07: malformed calendars fail closed');
bookingThrows(static function () use ($start, $end) {
	BookingAvailability::busyIntervals(array(bookingCalendar(bookingEvent('costly', '20261102T000000', '20261102T000100', "RRULE:FREQ=SECONDLY\r\n"))), $start, $end, 'Europe/Zurich');
}, 'A07: excessively fine recurrences are refused');
bookingThrows(static function () use ($start, $end) {
	BookingAvailability::busyIntervals(array_fill(0, 5001, ''), $start, $end, 'Europe/Zurich'); }, 'A07: calendar event count is bounded');
fwrite(STDOUT, "[OK] A07 free/cancelled/tasks, recurrence availability exceptions, malformed data and limits\n");

$bounds = array('duration' => array(5, 480), 'step' => array(5, 240),
	'buffer_before' => array(0, 240), 'buffer_after' => array(0, 240),
	'notice' => array(0, 43200), 'horizon' => array(1, 180));
foreach ($bounds as $setting => $limits) {
	foreach ($limits as $limit) {
		$normalized = BookingAvailability::validateConfig(array($setting => (string) $limit));
		bookingCheck($normalized[$setting] === $limit, 'A08: valid numeric boundaries are normalized to integers for '.$setting);
	}
	foreach (array($limits[0] - 1, $limits[1] + 1, 1.5, true, false, array(), ' 5', '5 ', '+5', '5e0', "5\n") as $invalid) {
		bookingThrows(static function () use ($setting, $invalid) {
			BookingAvailability::validateConfig(array($setting => $invalid));
		}, 'A08: invalid or coerced numeric settings are rejected for '.$setting);
	}
}
foreach (array(
	array('timezone' => ''), array('timezone' => true), array('timezone' => array()),
	array('weekly' => true), array('weekly' => array(0 => array())), array('weekly' => array(8 => array())),
	array('weekly' => array(1 => array(array('9:00', '10:00')))),
	array('weekly' => array(1 => array(array('09:00', '09:60')))),
	array('weekly' => array(1 => array(array('24:00', '24:00')))),
	array('weekly' => array(1 => array(array('23:00', '24:01')))),
	array('weekly' => array(1 => array(array('09:00', '10:00', '11:00')))),
	array('weekly' => array(1 => array(array('start' => '09:00', 'end' => '10:00')))),
	array('weekly' => array(1 => array_fill(0, 5, array('09:00', '10:00'))))
) as $invalid) {
	bookingThrows(static function () use ($invalid) {
		BookingAvailability::validateConfig($invalid);
	}, 'A08: invalid timezone or malformed working hours are rejected');
}
foreach (array(array($start, $start), array($end, $start), array(-1, $end), array($start, $start + 93 * 86400 + 1)) as $invalid) {
	bookingThrows(static function () use ($invalid) {
		BookingAvailability::validateRange($invalid[0], $invalid[1]);
	}, 'A08: nonpositive, reversed or excessive calculation ranges are rejected');
}
BookingAvailability::validateRange($start, $start + 93 * 86400);
foreach (array(array($start, $start), array($end, $start), array((string) $start, $end), array('start' => $start, 'end' => $end)) as $invalid) {
	bookingThrows(static function () use ($invalid, $start, $end, $now) {
		BookingAvailability::slots(array('notice' => 0), array($invalid), $start, $end, $now);
	}, 'A08: malformed occupied intervals cannot silently free time');
}
fwrite(STDOUT, "[OK] A08 numeric boundaries, strict types, working-hour validation and invalid occupied intervals\n");

$workingHours = BookingAvailability::validateConfig(array('notice' => 0,
	'weekly' => array(1 => array(array('13:00', '14:00'), array('10:00', '11:00'), array('09:00', '10:00')))));
bookingCheck($workingHours['weekly'][1] === array(array('09:00', '11:00'), array('13:00', '14:00')),
	'A09: unordered adjacent working ranges have a stable canonical meaning');
bookingCheck(bookingHours(BookingAvailability::slots($workingHours, array(), $start, $end, $now))
	=== array('09:00', '09:30', '10:00', '10:30', '13:00', '13:30'), 'A09: range normalization preserves all available starts and the lunch break');
$offsetGrid = array('notice' => 0, 'duration' => 45, 'step' => 20,
	'weekly' => array(1 => array(array('09:10', '11:00'))));
bookingCheck(bookingHours(BookingAvailability::slots($offsetGrid, array(), $start, $end, $now)) === array('09:10', '09:30', '09:50', '10:10'),
	'A09: the start grid is anchored to working hours, while the full independent duration must fit');
$offsetGrid['buffer_before'] = 5;
$offsetGrid['buffer_after'] = 10;
bookingCheck(bookingHours(BookingAvailability::slots($offsetGrid, array(), $start, $end, $now)) === array('09:30', '09:50'),
	'A09: asymmetric buffers fit within working hours without shifting the start grid');
$midnightHours = array('notice' => 0, 'weekly' => array(1 => array(array('23:00', '24:00'))));
$midnightSlots = BookingAvailability::slots($midnightHours, array(), $start, $end, $now);
bookingCheck(bookingHours($midnightSlots) === array('23:00', '23:30') && $midnightSlots[1]['end'] === $end,
	'A09: a 24:00 closing time permits a slot ending exactly at next-day midnight');
bookingCheck(BookingAvailability::slots(array('notice' => 0, 'weekly' => array()), array(), $start, $end, $now) === array(),
	'A09: an explicitly empty working week remains closed');
fwrite(STDOUT, "[OK] A09 normalized working hours, independent duration/step, asymmetric margins and midnight closure\n");

$profileZone = new DateTimeZone('America/New_York');
$profileNow = new DateTimeImmutable('2026-10-31 12:00', $profileZone);
$profileDeadline = $profileNow->modify('+1 day')->getTimestamp();
$profileStart = $profileNow->setTime(0, 0)->getTimestamp();
$profileEnd = $profileNow->modify('+2 days')->setTime(0, 0)->getTimestamp();
$profileConfig = array('timezone' => 'America/New_York', 'notice' => 0, 'horizon' => 1,
	'weekly' => array_fill(1, 7, array(array('00:00', '24:00'))));
$profileSlots = BookingAvailability::slots($profileConfig, array(), $profileStart, $profileEnd, $profileNow->getTimestamp());
bookingCheck($profileDeadline - $profileNow->getTimestamp() === 25 * 3600
	&& $profileSlots[0]['start'] === $profileNow->getTimestamp()
	&& $profileSlots[count($profileSlots) - 1]['end'] === $profileDeadline,
	'A10: the booking horizon is a calendar day in the profile timezone, with an inclusive exact slot end across DST');
foreach ($profileSlots as $slot) {
	bookingCheck($slot['start'] >= $profileNow->getTimestamp() && $slot['end'] <= $profileDeadline,
		'A10: no slot crosses notice or the profile-local horizon');
}
$processTimezone = date_default_timezone_get();
try {
	date_default_timezone_set('Asia/Tokyo');
	bookingCheck(BookingAvailability::slots($profileConfig, array(), $profileStart, $profileEnd, $profileNow->getTimestamp()) === $profileSlots,
		'A10: availability must not depend on the PHP process timezone');
} finally {
	date_default_timezone_set($processTimezone);
}
fwrite(STDOUT, "[OK] A10 profile timezone, local DST horizon, exact deadline and process-timezone independence\n");

for ($scenario = 0; $scenario < 24; $scenario++) {
	$propertyConfig = array('notice' => 0, 'duration' => array(5, 30, 45, 90)[$scenario % 4],
		'step' => array(5, 20, 30, 60)[intdiv($scenario, 2) % 4],
		'buffer_before' => $scenario % 3 === 0 ? 10 : 0, 'buffer_after' => $scenario % 2 === 0 ? 15 : 0,
		'weekly' => array(1 => array(array('09:00', '12:00'), array('13:00', '17:00'))));
	$occupied = array();
	for ($index = 0; $index < 3; $index++) {
		$occupiedStart = $start + (9 * 60 + ($scenario * 17 + $index * 101) % 420) * 60;
		$occupied[] = array($occupiedStart, $occupiedStart + (10 + ($scenario + $index) % 40) * 60);
	}
	$original = BookingAvailability::slots($propertyConfig, $occupied, $start, $end, $now);
	$reordered = array_merge(array_reverse($occupied), array($occupied[0], $occupied[1]));
	bookingCheck(BookingAvailability::slots($propertyConfig, $reordered, $start, $end, $now) === $original,
		'A11: event ordering and duplicate occupied intervals cannot change availability, scenario '.$scenario);
	$additional = array_merge($occupied, array(array($start + 14 * 3600, $start + 15 * 3600)));
	$reduced = BookingAvailability::slots($propertyConfig, $additional, $start, $end, $now);
	$originalStarts = array_column($original, 'start');
	bookingCheck(array_diff(array_column($reduced, 'start'), $originalStarts) === array(),
		'A11: adding an occupied event cannot create a newly available slot, scenario '.$scenario);
	$previousStart = null;
	foreach ($original as $slot) {
		bookingCheck(($previousStart === null || $slot['start'] > $previousStart)
			&& $slot['end'] - $slot['start'] === $propertyConfig['duration'] * 60,
			'A11: results have unique chronological starts and the configured real duration, scenario '.$scenario);
		$previousStart = $slot['start'];
		$before = $propertyConfig['buffer_before'] * 60;
		$after = $propertyConfig['buffer_after'] * 60;
		$fitsMorning = $slot['start'] - $before >= $start + 9 * 3600 && $slot['end'] + $after <= $start + 12 * 3600;
		$fitsAfternoon = $slot['start'] - $before >= $start + 13 * 3600 && $slot['end'] + $after <= $start + 17 * 3600;
		bookingCheck($fitsMorning || $fitsAfternoon, 'A11: complete reservations and margins remain inside one working range, scenario '.$scenario);
		foreach ($occupied as $interval) {
			bookingCheck($slot['end'] + $after <= $interval[0] - $before || $slot['start'] - $before >= $interval[1] + $after,
				'A11: no result overlaps an event or either event margin, scenario '.$scenario);
		}
	}
}
fwrite(STDOUT, "[OK] A11 24 deterministic schedules: ordering, duplicates, monotonic occupation, unique starts, durations and margin safety\n");

$explicitDuration = bookingCalendar("BEGIN:VEVENT\r\nUID:utc-duration\r\nDTSTART:20261102T090000Z\r\nDURATION:PT45M\r\nEND:VEVENT\r\n");
$floatingTime = bookingCalendar("BEGIN:VEVENT\r\nUID:floating-time\r\nDTSTART:20261102T130000\r\nDTEND:20261102T140000\r\nEND:VEVENT\r\n");
bookingCheck(BookingAvailability::busyIntervals(array($explicitDuration, $floatingTime), $start, $end, 'Europe/Zurich')
	=== array(array(bookingStamp('2026-11-02 10:00'), bookingStamp('2026-11-02 10:45')),
		array(bookingStamp('2026-11-02 13:00'), bookingStamp('2026-11-02 14:00'))),
	'A12: explicit durations, UTC values and floating values retain their native calendar meaning');
$implicitAllDay = bookingCalendar("BEGIN:VEVENT\r\nUID:implicit-all-day\r\nDTSTART;VALUE=DATE:20261102\r\nEND:VEVENT\r\n");
bookingCheck(BookingAvailability::busyIntervals(array($implicitAllDay), $start, $end, 'Europe/Zurich') === array(array($start, $end)),
	'A12: an all-day event without an explicit end occupies one complete local day');
foreach (array("DTSTART;TZID=Europe/Zurich:20261102T120000\r\n", "DURATION:PT1H\r\n", "UID:second-uid\r\n",
	"EXRULE:FREQ=DAILY\r\n", "RECURRENCE-ID;RANGE=THISANDFUTURE;TZID=Europe/Zurich:20261102T100000\r\n",
	"RDATE;VALUE=DATE:20261103\r\n") as $ambiguous) {
	bookingThrows(static function () use ($ambiguous, $start, $end) {
		BookingAvailability::busyIntervals(array(bookingCalendar(bookingEvent('ambiguous', '20261102T100000', '20261102T110000', $ambiguous))), $start, $end, 'Europe/Zurich');
	}, 'A12: ambiguous properties and unsupported recurrence data fail closed');
}
$previousRecurrenceLimit = Sabre\VObject\Settings::$maxRecurrences;
try {
	Sabre\VObject\Settings::$maxRecurrences = 1234;
	BookingAvailability::busyIntervals(array($explicitDuration), $start, $end, 'Europe/Zurich');
	bookingCheck(Sabre\VObject\Settings::$maxRecurrences === 1234, 'A12: successful calculations preserve the shared native recurrence limit');
	bookingThrows(static function () use ($start, $end) {
		BookingAvailability::busyIntervals(array('invalid calendar'), $start, $end, 'Europe/Zurich');
	}, 'A12: invalid calendar parsing still fails closed');
	bookingCheck(Sabre\VObject\Settings::$maxRecurrences === 1234, 'A12: failed calculations restore the shared native recurrence limit');
} finally {
	Sabre\VObject\Settings::$maxRecurrences = $previousRecurrenceLimit;
}
fwrite(STDOUT, "[OK] A12 native duration/date semantics, ambiguous calendars and recurrence-setting restoration\n");

$longAppointment = BookingAvailability::validateConfig(array('duration' => 240, 'step' => 7,
	'buffer_before' => 60, 'buffer_after' => 60, 'notice' => 1440, 'horizon' => 90,
	'weekly' => array_fill(1, 5, array(array('07:00', '12:00'), array('13:00', '17:00')))));
$longAppointmentSnapshot = $longAppointment;
bookingCheck(!BookingAvailability::hasWeeklySlot($longAppointment)
	&& BookingAvailability::slots($longAppointment, array(), $start, $end, $now) === array(),
	'A13: the reported 240-minute appointment and two 60-minute margins fit no split working range, even in an empty calendar');
bookingCheck($longAppointment === $longAppointmentSnapshot && $longAppointment['step'] === 7,
	'A13: the feasibility warning neither rejects a valid seven-minute grid nor changes stored settings');
$extendedAppointment = $longAppointment;
$extendedAppointment['weekly'][1] = array(array('07:00', '13:00'));
bookingCheck(!BookingAvailability::hasWeeklySlot($extendedAppointment),
	'A13: a six-hour window remains too short when a seven-minute grid rounds a sixty-minute margin up to sixty-three minutes');
$extendedAppointment['weekly'][1] = array(array('07:00', '13:03'));
bookingCheck(BookingAvailability::hasWeeklySlot($extendedAppointment)
	&& bookingHours(BookingAvailability::slots($extendedAppointment, array(), $start, $end, $now)) === array('08:03'),
	'A13: the exact first start permitted by the grid fits a six-hour-three-minute working window');
$extendedAppointment['weekly'][1] = array(array('07:00', '13:00'));
$extendedAppointment['step'] = 15;
bookingCheck(BookingAvailability::hasWeeklySlot($extendedAppointment)
	&& bookingHours(BookingAvailability::slots($extendedAppointment, array(), $start, $end, $now)) === array('08:00'),
	'A13: six continuous hours suffice when the grid aligns with the margins');
$shorterAppointment = $longAppointment;
$shorterAppointment['duration'] = 120;
bookingCheck(BookingAvailability::hasWeeklySlot($shorterAppointment)
	&& BookingAvailability::slots($shorterAppointment, array(), $start, $end, $now) !== array(),
	'A13: reducing the appointment duration can restore available starts without silently changing margins');
$sameDayAppointment = $longAppointment;
$sameDayAppointment['buffer_before'] = $sameDayAppointment['buffer_after'] = 0;
bookingCheck(BookingAvailability::hasWeeklySlot($sameDayAppointment)
	&& BookingAvailability::slots($sameDayAppointment, array(), $start, $end, bookingStamp('2026-11-02 07:00')) === array(),
	'A13: weekly feasibility does not bypass a twenty-four-hour notice period on the selected day');
$closedAppointment = $longAppointment;
$closedAppointment['weekly'] = array();
bookingCheck(!BookingAvailability::hasWeeklySlot($closedAppointment), 'A13: a fully closed week has no possible start');
bookingThrows(static function () {
	BookingAvailability::hasWeeklySlot(array('step' => 0));
}, 'A13: feasibility diagnostics use the same profile validation as the booking engine');
fwrite(STDOUT, "[OK] A13 impossible split hours, unchanged valid settings, exact start-grid margins and minimum notice\n");

$legacyHorizon = array('timezone' => 'Europe/Zurich', 'horizon' => 90);
bookingCheck(BookingAvailability::validateConfig($legacyHorizon)['horizon_unit'] === 'days'
	&& BookingAvailability::horizonEnd($legacyHorizon, $clock) === (new DateTimeImmutable('@'.$clock))
	->setTimezone(new DateTimeZone('Europe/Zurich'))->modify('+90 days')->getTimestamp(),
	'A14: stored profiles without an explicit unit retain the exact previous local-day deadline');
bookingCheck(BookingAvailability::validateConfig(array('horizon' => 180))['horizon'] === 180
	&& BookingAvailability::validateConfig(array('horizon_unit' => 'months', 'horizon' => 6))['horizon'] === 6,
	'A14: booking may open for up to one hundred eighty days or six calendar months');
foreach (array(array('horizon' => 181), array('horizon_unit' => 'months', 'horizon' => 7),
	array('horizon_unit' => 'months', 'horizon' => 0), array('horizon_unit' => 'weeks'),
	array('horizon_unit' => array()), array('horizon_unit' => true)) as $invalidHorizon) {
	bookingThrows(static function () use ($invalidHorizon) {
		BookingAvailability::validateConfig($invalidHorizon);
	}, 'A14: calendar-month and day limits remain server-validated');
}
foreach (array(
	array('2026-08-31 10:15:30', 6, '2027-02-28 10:15:30'),
	array('2027-08-31 10:15:30', 6, '2028-02-29 10:15:30'),
	array('2026-01-31 10:15:30', 1, '2026-02-28 10:15:30'),
	array('2026-10-31 10:15:30', 1, '2026-11-30 10:15:30'),
	array('2026-03-15 10:15:30', 1, '2026-04-15 10:15:30')
) as $monthCase) {
	$calendarConfig = array('timezone' => 'Europe/Zurich', 'horizon_unit' => 'months', 'horizon' => $monthCase[1]);
	$calendarEnd = BookingAvailability::horizonEnd($calendarConfig, bookingStamp($monthCase[0]));
	bookingCheck($calendarEnd === bookingStamp($monthCase[2]),
		'A14: calendar-month deadlines clamp to the last valid day while preserving local time and seconds across DST');
}
$calendarConfig = array('timezone' => 'Europe/Zurich', 'horizon_unit' => 'months', 'horizon' => 6,
	'notice' => 0, 'weekly' => array_fill(1, 7, array(array('09:00', '12:00'))));
$calendarNow = bookingStamp('2026-08-31 10:00');
$calendarLastDay = bookingStamp('2027-02-28 00:00');
bookingCheck(bookingHours(BookingAvailability::slots($calendarConfig, array(), $calendarLastDay,
	bookingStamp('2027-03-01 00:00'), $calendarNow)) === array('09:00', '09:30'),
	'A14: sixth-month slots end exactly at the local deadline and never cross it');
bookingCheck(BookingAvailability::slots($calendarConfig, array(), bookingStamp('2027-03-01 00:00'),
	bookingStamp('2027-03-02 00:00'), $calendarNow) === array(), 'A14: dates after six calendar months remain closed');
fwrite(STDOUT, "[OK] A14 legacy day horizons, six calendar months, month-end clamping, leap years and local DST deadlines\n");

$legacyNotice = BookingAvailability::validateConfig(array('notice' => 1440));
$explicitMinuteNotice = BookingAvailability::validateConfig(array('notice' => 1440, 'notice_unit' => 'minutes'));
bookingCheck($legacyNotice === $explicitMinuteNotice && !array_key_exists('notice_unit', $legacyNotice)
	&& hash('sha256', json_encode($legacyNotice)) === hash('sha256', json_encode($explicitMinuteNotice)),
	'A15: the default minutes unit does not change legacy canonical settings or signed form hashes');
$dayNotice = BookingAvailability::validateConfig(array('notice' => 2880, 'notice_unit' => 'days'));
bookingCheck($dayNotice['notice'] === 2880 && $dayNotice['notice_unit'] === 'days'
	&& BookingAvailability::validateConfig($dayNotice) === $dayNotice,
	'A15: a day display preference remains stable while the stored notice stays in minutes');
foreach (array(array('0', 'minutes', 0), array(2, 'minutes', 2), array('43200', 'minutes', 43200),
	array('0', 'days', 0), array('2', 'days', 2880), array(30, 'days', 43200), array('30.0000000000', 'days', 43200),
	array('0.0625', 'days', 90), array('0.5', 'days', 720), array('1.5', 'days', 2160)) as $noticeCase) {
	bookingCheck(BookingAvailability::noticeMinutes($noticeCase[0], $noticeCase[1]) === $noticeCase[2],
		'A15: explicit notice inputs preserve their complete duration and inclusive limits');
}
foreach (array(1, 2, 7, 15, 30, 59, 61, 90, 1439, 1440, 1441, 43199, 43200) as $noticeMinutes) {
	$displayDays = rtrim(rtrim(number_format($noticeMinutes / 1440, 10, '.', ''), '0'), '.');
	bookingCheck(BookingAvailability::noticeMinutes($displayDays, 'days') === $noticeMinutes,
		'A15: switching the display to days never silently changes an existing minute value');
}
foreach (array('hours', '', null, true, 1, array(), "days\n") as $invalidNoticeUnit) {
	bookingThrows(static function () use ($invalidNoticeUnit) {
		BookingAvailability::validateConfig(array('notice_unit' => $invalidNoticeUnit));
	}, 'A15: unknown or malformed notice display units fail closed');
	bookingThrows(static function () use ($invalidNoticeUnit) {
		BookingAvailability::noticeMinutes('2', $invalidNoticeUnit);
	}, 'A15: conversion never coerces an unsupported notice unit');
}
foreach (array(-1, 1.5, NAN, INF, -INF, true, false, null, array(), '', ' 2', '2 ', '+2', '-1', '2e0', '1,5', "2\n", '<b>2</b>') as $invalidNoticeValue) {
	foreach (array('minutes', 'days') as $noticeUnit) {
		bookingThrows(static function () use ($invalidNoticeValue, $noticeUnit) {
			BookingAvailability::noticeMinutes($invalidNoticeValue, $noticeUnit);
		}, 'A15: notice conversion rejects invalid, nonfinite, injected and coerced inputs');
	}
}
foreach (array(array('43201', 'minutes'), array('1.5', 'minutes'), array('31', 'days'), array('30.0000000001', 'days'),
	array('0.3333', 'days'), array('0.0003472222', 'days'), array('0.0000000001', 'days'), array('0.00069444444', 'days')) as $invalidNoticeCase) {
	bookingThrows(static function () use ($invalidNoticeCase) {
		BookingAvailability::noticeMinutes($invalidNoticeCase[0], $invalidNoticeCase[1]);
	}, 'A15: out-of-range values and fractional-minute days are rejected without truncation');
}
foreach (array('2026-03-28 09:00', '2026-10-24 09:00') as $noticeClock) {
	$noticeNow = bookingStamp($noticeClock);
	$noticeStart = (new DateTimeImmutable('@'.$noticeNow))->setTimezone(new DateTimeZone('Europe/Zurich'))->setTime(0, 0)->getTimestamp();
	$noticeEnd = (new DateTimeImmutable('@'.$noticeStart))->setTimezone(new DateTimeZone('Europe/Zurich'))->modify('+4 days')->getTimestamp();
	$minuteConfig = array('notice' => 1440, 'weekly' => array_fill(1, 7, array(array('00:00', '24:00'))));
	$dayConfig = array_replace($minuteConfig, array('notice_unit' => 'days'));
	$minuteSlots = BookingAvailability::slots($minuteConfig, array(), $noticeStart, $noticeEnd, $noticeNow);
	bookingCheck($minuteSlots === BookingAvailability::slots($dayConfig, array(), $noticeStart, $noticeEnd, $noticeNow)
		&& $minuteSlots[0]['start'] === $noticeNow + 1440 * 60,
		'A15: one notice day remains twenty-four elapsed hours through both local DST transitions');
}
fwrite(STDOUT, "[OK] A15 notice minute/day inputs, lossless display, strict limits, legacy hashes and elapsed-day DST behavior\n");

if (getenv('CDAV_BOOKING_BENCHMARK') === '1') {
	$load = array();
	for ($i = 0; $i < 1000; $i++) $load[] = bookingCalendar(bookingEvent('load-'.$i, '20261102T100000', '20261102T110000'));
	for ($i = 0; $i < 100; $i++) $load[] = bookingCalendar(bookingEvent('series-'.$i, '20261101T140000', '20261101T150000', "RRULE:FREQ=DAILY;COUNT=60\r\n"));
	$measurements = array();
	for ($i = 0; $i < 5; $i++) {
		$before = microtime(true);
		$loadBusy = BookingAvailability::busyIntervals($load, $start, bookingStamp('2026-12-31 00:00'), 'Europe/Zurich');
		$measurements[] = microtime(true) - $before;
		bookingCheck(count($loadBusy) === 60, 'R03 pure calculation retains merged occupations');
	}
	sort($measurements);
	$p95 = $measurements[count($measurements) - 1];
	bookingCheck($p95 < 1.0, 'R03 pure calculation p95 target exceeded');
	fwrite(STDOUT, sprintf("[OK] R03 pure calculation: 1000 events + 100 series / 60 days / 5 runs; p95 %.3f s; peak %.1f MiB\n", $p95, memory_get_peak_usage(true) / 1048576));
} else {
	fwrite(STDOUT, "[INFO] R03 performance benchmark skipped; set CDAV_BOOKING_BENCHMARK=1 on a controlled runner.\n");
}
fwrite(STDOUT, '[INFO] Bundled Sabre vendor deprecations: '.$vendorDeprecations." (application warnings remain fatal).\n");
restore_error_handler();
fwrite(STDOUT, "All CDav booking business tests passed.\n");
