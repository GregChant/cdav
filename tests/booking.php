<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Booking business regressions A01–A07; no database or HTTP server required. */
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
	try { $call(); } catch (Throwable $e) { $thrown = true; }
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
foreach (array(array('duration' => 0), array('step' => '1e9'), array('horizon' => 91), array('timezone' => 'private/invalid'),
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
fwrite(STDOUT, '[INFO] Bundled Sabre vendor deprecations: '.$vendorDeprecations." (application warnings remain fatal).\n");
restore_error_handler();
fwrite(STDOUT, "All CDav booking business tests passed.\n");
