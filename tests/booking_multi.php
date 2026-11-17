<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Deterministic group-booking rules; no database or server is required. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require dirname(__DIR__).'/class/CDavBookingAvailability.php';
require dirname(__DIR__).'/class/CDavBookingService.php';

use Dolibarr\CDav\BookingAvailability;
use Dolibarr\CDav\BookingException;
use Dolibarr\CDav\BookingService;

/**
 * Assert an observable selection outcome.
 * @param bool $condition Expected true value
 * @param string $message Failure explanation
 * @return void
 */
function multiCheck($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Require the intended rejection class, including strict PHP diagnostics.
 * @param callable $operation Operation expected to fail
 * @param string $class Expected exception type
 * @param string $message Failure explanation
 * @return void
 */
function multiRejects(callable $operation, string $class, string $message): void
{
	try { $operation(); } catch (Throwable $exception) {
		multiCheck($exception instanceof $class, $message.' ('.get_class($exception).')');
		return;
	}
	throw new RuntimeException($message.' (not rejected)');
}

/**
 * Use a fixed professional-zone clock.
 * @param string $value Local date and time
 * @return int Timestamp
 */
function multiStamp(string $value): int
{
	return (new DateTimeImmutable($value, new DateTimeZone('Europe/Zurich')))->getTimestamp();
}

$now = multiStamp('2026-11-01 08:00');
$first = multiStamp('2026-11-02 09:30');
$config = BookingAvailability::validateConfig(array('notice' => 0, 'buffer_before' => 15, 'buffer_after' => 15,
	'weekly' => array_fill(1, 7, array(array('09:00', '12:00'), array('13:00', '17:00')))));
multiCheck($config['allow_contiguous'] === false && $config['allow_split'] === false,
	'M01: existing profiles disable both group permissions by default');
$single = BookingAvailability::availableSelection($config, array(), array($first), $now);
multiCheck($single === array('starts' => array($first), 'segments' => array(array('start' => $first, 'end' => $first + 1800)), 'duration_minutes' => 30),
	'M01: a single appointment retains its original duration with both permissions off');
foreach (array('allow_contiguous', 'allow_split') as $flag) {
	foreach (array(0, 1, '1', 'true', null, array()) as $invalid) {
		multiRejects(static function () use ($config, $flag, $invalid) {
			BookingAvailability::validateConfig(array_replace($config, array($flag => $invalid)));
		}, InvalidArgumentException::class, 'M01: profile permissions must be actual booleans');
	}
}
fwrite(STDOUT, "[OK] M01 legacy defaults, single appointments and strict profile permissions\n");

$continuous = array_replace($config, array('allow_contiguous' => true));
$merged = BookingAvailability::availableSelection($continuous, array(), array($first + 1800, $first), $now);
multiCheck($merged['starts'] === array($first, $first + 1800)
	&& $merged['segments'] === array(array('start' => $first, 'end' => $first + 3600)) && $merged['duration_minutes'] === 60,
	'M02: consecutive units are sorted and reserve one complete hour with margins only around that hour');
multiRejects(static function () use ($config, $first) {
	BookingAvailability::planSelection($config, array($first, $first + 1800));
}, InvalidArgumentException::class, 'M02: consecutive units cannot bypass the profile permission');
$long = array_replace($continuous, array('buffer_before' => 0, 'buffer_after' => 0,
	'weekly' => array_fill(1, 7, array(array('00:00', '24:00')))));
$longStarts = array();
for ($index = 0; $index < 24; $index++) $longStarts[] = multiStamp('2026-11-02 08:00') + $index * 1800;
$longSelection = BookingAvailability::availableSelection($long, array(), $longStarts, $now);
multiCheck(count($longSelection['segments']) === 1 && $longSelection['duration_minutes'] === 720,
	'M02: a merged segment may exceed the maximum duration of one configured unit');
fwrite(STDOUT, "[OK] M02 continuous segments, outer margins, sorted input and long merged appointments\n");

$split = array_replace($config, array('allow_split' => true));
$separated = array($first, multiStamp('2026-11-02 13:30'), multiStamp('2026-11-03 09:30'));
$splitSelection = BookingAvailability::availableSelection($split, array(), array_reverse($separated), $now);
multiCheck(count($splitSelection['segments']) === 3 && $splitSelection['duration_minutes'] === 90
	&& $splitSelection['segments'][1]['start'] === $separated[1],
	'M03: lunch gaps and changes of day remain three separate native appointment intervals');
multiRejects(static function () use ($continuous, $separated) {
	BookingAvailability::planSelection($continuous, $separated);
}, InvalidArgumentException::class, 'M03: the continuous permission does not enable split bookings');
multiRejects(static function () use ($split, $first) {
	BookingAvailability::planSelection($split, array($first, $first + 1800));
}, InvalidArgumentException::class, 'M03: the split permission does not enable continuous bookings');
fwrite(STDOUT, "[OK] M03 independent permissions, preserved breaks and multiple days\n");

$both = array_replace($continuous, array('allow_split' => true));
foreach (array(array(), array($first, $first), array((string) $first), array(1.5), array(0),
	array('start' => $first), array_fill(0, 25, $first)) as $invalid) {
	multiRejects(static function () use ($both, $invalid) {
		BookingAvailability::planSelection($both, $invalid);
	}, InvalidArgumentException::class, 'M04: malformed, duplicated or excessive selected units are rejected');
}
$tooManyDays = array();
for ($index = 0; $index < 9; $index++) $tooManyDays[] = $first + $index * 86400;
multiCheck(count(BookingAvailability::availableSelection($both, array(), array_slice($tooManyDays, 0, 8), $now)['segments']) === 8,
	'M04: exactly eight selected local days remain allowed');
multiRejects(static function () use ($both, $tooManyDays) {
	BookingAvailability::planSelection($both, $tooManyDays);
}, InvalidArgumentException::class, 'M04: selections are bounded to eight distinct professional-zone days');
$overlapping = array_replace($both, array('step' => 7));
multiRejects(static function () use ($overlapping, $first) {
	BookingAvailability::planSelection($overlapping, array($first, $first + 7 * 60));
}, InvalidArgumentException::class, 'M04: two overlapping thirty-minute units cannot become a thirty-seven-minute booking');
fwrite(STDOUT, "[OK] M04 bounded input, strict timestamps, duplicates and overlap rejection\n");

foreach (array(array($first + 60), array(multiStamp('2026-11-02 09:00')),
	array(multiStamp('2026-11-02 11:30'), multiStamp('2026-11-02 12:00'))) as $invalidAvailability) {
	multiRejects(static function () use ($both, $invalidAvailability, $now) {
		BookingAvailability::availableSelection($both, array(), $invalidAvailability, $now);
	}, OutOfBoundsException::class, 'M05: every selected start respects the grid, margins and lunch closure');
}
multiRejects(static function () use ($continuous, $first, $now) {
	BookingAvailability::availableSelection($continuous, array(array($first + 1200, $first + 1500)), array($first, $first + 1800), $now);
}, OutOfBoundsException::class, 'M05: an existing native event blocks the complete continuous segment');
multiRejects(static function () use ($continuous, $first, $now) {
	BookingAvailability::availableSelection($continuous, array(array($first - 1800, $first - 600)), array($first), $now);
}, OutOfBoundsException::class, 'M05: the occupied event reserves its margins as well as the selected segment');
multiRejects(static function () use ($both, $first, $now) {
	BookingAvailability::availableSelection($both, array(), array($first, $first + 2700), $now);
}, OutOfBoundsException::class, 'M05: separate selected segments cannot overlap each other through their margins');
$wideGap = BookingAvailability::availableSelection($both, array(), array($first, $first + 3600), $now);
multiCheck(count($wideGap['segments']) === 2, 'M05: a gap exactly equal to both margins remains reservable');
fwrite(STDOUT, "[OK] M05 occupied events, start grids, complete working ranges and segment margins\n");

$clock = multiStamp('2026-11-02 09:00');
$notice = array_replace($both, array('notice' => 60));
multiRejects(static function () use ($notice, $first, $clock) {
	BookingAvailability::availableSelection($notice, array(), array($first), $clock);
}, OutOfBoundsException::class, 'M06: group selection cannot bypass minimum notice');
$sixMonths = array_replace($both, array('horizon' => 6, 'horizon_unit' => 'months'));
$sixMonthsNow = multiStamp('2026-08-31 10:00');
$farSelection = BookingAvailability::availableSelection($sixMonths, array(),
	array(multiStamp('2026-09-01 09:30'), multiStamp('2027-02-28 09:30')), $sixMonthsNow);
multiCheck(count($farSelection['segments']) === 2, 'M06: separate chosen days may span the six-calendar-month horizon');
multiRejects(static function () use ($sixMonths, $sixMonthsNow) {
	BookingAvailability::availableSelection($sixMonths, array(), array(multiStamp('2027-03-01 09:30')), $sixMonthsNow);
}, OutOfBoundsException::class, 'M06: a selected day after the calendar-month deadline stays closed');
$dst = array_replace($both, array('weekly' => array_fill(1, 7, array(array('00:00', '05:00')))));
multiRejects(static function () use ($dst) {
	BookingAvailability::availableSelection($dst, array(), array(multiStamp('2026-10-25 02:00')), multiStamp('2026-10-24 00:00'));
}, OutOfBoundsException::class, 'M06: ambiguous fall-back hours remain unavailable to group selections');
fwrite(STDOUT, "[OK] M06 minimum notice, six-month selection and ambiguous DST hours\n");

$midnight = array_replace($long, array('allow_split' => true));
$midnightSelection = BookingAvailability::availableSelection($midnight, array(),
	array(multiStamp('2026-11-02 23:30'), multiStamp('2026-11-03 00:00')), $now);
multiCheck(count($midnightSelection['segments']) === 2, 'M07: midnight keeps distinct daily native appointments');
multiRejects(static function () use ($long, $now) {
	BookingAvailability::availableSelection($long, array(), array(multiStamp('2026-11-02 23:30'), multiStamp('2026-11-03 00:00')), $now);
}, InvalidArgumentException::class, 'M07: a change of local day requires the split permission');
multiCheck(BookingAvailability::planSelection($both, $separated) === BookingAvailability::planSelection($both, array_reverse($separated)),
	'M07: canonical starts and receipts do not depend on selection order');
fwrite(STDOUT, "[OK] M07 daily boundaries and canonical order for stable group receipts\n");

// The two visible half-day choices are separate appointments across lunch.
$halfDay = array_replace($continuous, array('duration' => 240, 'step' => 240,
	'buffer_before' => 0, 'buffer_after' => 0,
	'weekly' => array_fill(1, 7, array(array('08:00', '12:00'), array('13:00', '17:00')))));
$halfDayStarts = array(multiStamp('2026-11-25 08:00'), multiStamp('2026-11-25 13:00'));
$halfDaySelection = BookingAvailability::availableSelection(array_replace($halfDay, array('allow_split' => true)), array(), $halfDayStarts, $now);
multiCheck($halfDaySelection['duration_minutes'] === 480
	&& $halfDaySelection['segments'] === array(array('start' => $halfDayStarts[0], 'end' => $halfDayStarts[0] + 14400),
		array('start' => $halfDayStarts[1], 'end' => $halfDayStarts[1] + 14400)),
	'M08: the morning and afternoon reserve eight hours in two native periods when split booking is enabled');
$unbrokenDay = array_replace($halfDay, array('weekly' => array_fill(1, 7, array(array('08:00', '17:00')))));
$unbrokenSelection = BookingAvailability::availableSelection($unbrokenDay, array(), array($halfDayStarts[0], $halfDayStarts[0] + 14400), $now);
multiCheck($unbrokenSelection['duration_minutes'] === 480
	&& $unbrokenSelection['segments'] === array(array('start' => $halfDayStarts[0], 'end' => $halfDayStarts[0] + 28800)),
	'M08: genuinely adjacent four-hour units remain one reservable eight-hour appointment');
$previewService = new BookingService(null, 1);
foreach (array(
	array($halfDay, $halfDayStarts, 'CDavBookingSelectionSplitDisabled'),
	array($split, array($first, $first + 1800), 'CDavBookingSelectionContiguousDisabled'),
	array($both, array($first, $first), 'CDavBookingInvalid'),
	array(array_replace($both, array('timezone' => 'PRIVATE-SENTINEL')), array($first), 'CDavBookingInvalid'),
) as $rejectedPreview) {
	try {
		$previewService->previewSelection(array('config' => $rejectedPreview[0]), $rejectedPreview[1], $now);
		throw new RuntimeException('M08: the invalid preview was unexpectedly accepted');
	} catch (BookingException $exception) {
		multiCheck($exception->getCode() === 422 && $exception->getMessage() === $rejectedPreview[2],
			'M08: preview returns the precise safe mode refusal while other invalid input remains generic');
	}
}
fwrite(STDOUT, "[OK] M08 half-day selections, genuine adjacency and safe public refusal reasons\n");
fwrite(STDOUT, "All CDav multi-slot business tests passed.\n");
