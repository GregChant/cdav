<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Inclusive profile validity dates with native local-clock and legacy semantics. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require dirname(__DIR__).'/class/CDavBookingAvailability.php';

use Dolibarr\CDav\BookingAvailability;

/**
 * Assert a public availability result.
 * @param bool $condition Expected condition
 * @param string $message Failure detail
 * @return void
 */
function validityCheck(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Reject malformed configuration or an unauthorized appointment date.
 * @param callable $operation Calculation
 * @param string $exception Expected rejection type
 * @param string $message Failure detail
 * @return void
 */
function validityRejects(callable $operation, string $exception, string $message): void
{
	try {
		$operation();
	} catch (Throwable $error) {
		validityCheck($error instanceof $exception, $message.' ('.get_class($error).')');
		return;
	}
	throw new RuntimeException($message.' (not rejected)');
}

/**
 * Convert a profile-local wall date to its native timestamp.
 * @param string $date Local date and optional time
 * @param string $timezone Profile timezone
 * @return int Unix timestamp
 */
function validityStamp(string $date, string $timezone = 'Europe/Zurich'): int
{
	return (new DateTimeImmutable($date, new DateTimeZone($timezone)))->getTimestamp();
}

$base = array('notice' => 0, 'horizon' => 180, 'weekly' => array_fill(1, 7, array(array('00:00', '24:00'))));
$legacy = BookingAvailability::validateConfig($base);
$empty = BookingAvailability::validateConfig(array_replace($base, array('valid_from' => '', 'valid_until' => '')));
validityCheck($legacy === $empty && !array_key_exists('valid_from', $legacy) && !array_key_exists('valid_until', $legacy),
	'V01: absent and explicitly empty validity dates retain the exact historical canonical settings');
// Snapshot computed from the released 6.1.3 validator, before validity was added.
validityCheck(hash('sha256', json_encode($legacy)) === '8f702f378aae0297c4d6da735e505091af48944a42513f2cbadb327cd51ee5d5',
	'V01: profiles without a validity window preserve their historical ticket-settings hash');
validityCheck(BookingAvailability::validityRange($empty) === array('start' => null, 'end' => null),
	'V01: two empty dates add no deadline beyond the independent rolling horizon');
foreach (array('valid_from', 'valid_until') as $field) {
	foreach (array(null, false, true, 20261102, array(), "\xc3\x28", ' ', '2026-11-2', '2026-2-02', '2026-13-01',
		'2026-11-31', '2026-02-29', '0000-01-01', '2026-11-02T00:00:00', "2026-11-02\0", '<script>bad</script>') as $invalid) {
		validityRejects(static function () use ($base, $field, $invalid) {
			BookingAvailability::validateConfig(array_replace($base, array($field => $invalid)));
		}, InvalidArgumentException::class, 'V01: validity dates are strict scalar UTF-8 ISO calendar dates');
	}
}
validityRejects(static function () use ($base) {
	BookingAvailability::validateConfig(array_replace($base, array('valid_from' => '2026-11-09', 'valid_until' => '2026-11-08')));
}, InvalidArgumentException::class, 'V01: validity dates cannot be reversed');
validityCheck(BookingAvailability::validateConfig(array_replace($base, array('valid_from' => '2024-02-29')))['valid_from'] === '2024-02-29',
	'V01: a real leap date remains valid');
$lastCalendarDate = BookingAvailability::validityRange(array_replace($base, array('valid_until' => '9999-12-31')));
validityCheck((new DateTimeImmutable('@'.$lastCalendarDate['end']))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d H:i') === '10000-01-01 00:00',
	'V01: the final four-digit ISO date can resolve its exclusive next-day boundary');
fwrite(STDOUT, "[OK] V01 strict inclusive dates, optional bounds and legacy canonical hash\n");

$now = validityStamp('2026-11-01 00:00');
$from = validityStamp('2026-11-01 00:00');
$until = validityStamp('2026-11-10 00:00');
$oneDay = array_replace($base, array('valid_from' => '2026-11-02', 'valid_until' => '2026-11-02'));
$range = BookingAvailability::validityRange($oneDay);
validityCheck($range === array('start' => validityStamp('2026-11-02 00:00'), 'end' => validityStamp('2026-11-03 00:00')),
	'V02: matching bounds mean one full local day, with an exclusive next-day midnight');
$slots = BookingAvailability::slots($oneDay, array(), $from, $until, $now);
validityCheck(count($slots) === 48 && $slots[0]['start'] === $range['start'] && end($slots)['end'] === $range['end'],
	'V02: the entire valid day is bookable through an appointment ending exactly at the next midnight');
foreach ($slots as $slot) validityCheck($slot['start'] >= $range['start'] && $slot['end'] <= $range['end'],
	'V02: no proposed appointment escapes the configured date window');
$week = array_replace($base, array('valid_from' => '2026-11-02', 'valid_until' => '2026-11-08'));
$weekSlots = BookingAvailability::slots($week, array(), $from, $until, $now);
validityCheck(count($weekSlots) === 7 * 48 && end($weekSlots)['end'] === validityStamp('2026-11-09 00:00'),
	'V02: an inclusive Monday-to-Sunday window contains seven complete appointment days');
fwrite(STDOUT, "[OK] V02 one-day and one-week availability with exact inclusive end dates\n");

$shortEnd = validityStamp('2026-11-05 00:00');
$startOnly = array_replace($base, array('valid_from' => '2026-11-03'));
$endOnly = array_replace($base, array('valid_until' => '2026-11-03'));
validityCheck(count(BookingAvailability::slots($startOnly, array(), $from, $shortEnd, $now)) === 2 * 48,
	'V03: an open upper bound permits later days while excluding dates before the lower bound');
validityCheck(count(BookingAvailability::slots($endOnly, array(), $from, $shortEnd, $now)) === 3 * 48,
	'V03: an open lower bound preserves earlier available days through the inclusive upper date');
validityCheck(count(BookingAvailability::slots($empty, array(), $from, $shortEnd, $now)) === 4 * 48,
	'V03: empty dates preserve the normal unbounded-by-profile calendar');
validityCheck(count(BookingAvailability::slots(array_replace($empty, array('horizon' => 1)), array(), $from, $shortEnd, $now)) === 48,
	'V03: empty validity dates do not bypass the independent rolling booking horizon');
validityCheck(BookingAvailability::slots(array_replace($oneDay, array('notice' => 4320)), array(), $from, $shortEnd, $now) === array(),
	'V03: the minimum notice still excludes an otherwise valid day');
validityCheck(BookingAvailability::slots(array_replace($startOnly, array('horizon' => 1)), array(), $from, $shortEnd, $now) === array(),
	'V03: a validity window beginning after the rolling deadline yields no slot');
validityCheck(BookingAvailability::slots(array_replace($base, array('valid_until' => '2026-10-31')), array(), $from, $shortEnd, $now) === array(),
	'V03: an expired validity window yields no current slot');
fwrite(STDOUT, "[OK] V03 open bounds remain subject to notice and rolling horizon\n");

$group = array_replace($oneDay, array('allow_contiguous' => true, 'allow_split' => true));
$morning = validityStamp('2026-11-02 08:00');
$afternoon = validityStamp('2026-11-02 13:00');
$continuous = BookingAvailability::availableSelection($group, array(), array($morning, $morning + 1800), $now);
validityCheck(count($continuous['segments']) === 1 && $continuous['duration_minutes'] === 60,
	'V04: permitted consecutive units remain bookable inside the validity day');
$split = BookingAvailability::availableSelection($group, array(), array($morning, $afternoon), $now);
validityCheck(count($split['segments']) === 2 && $split['duration_minutes'] === 60,
	'V04: permitted separate periods remain bookable inside the validity day');
$lastStarts = array(validityStamp('2026-11-02 23:00'), validityStamp('2026-11-02 23:30'));
$lastSelection = BookingAvailability::availableSelection($group, array(), $lastStarts, $now);
validityCheck($lastSelection['segments'][0]['end'] === $range['end'],
	'V04: a merged appointment may finish exactly at the exclusive window end');
foreach (array(array(validityStamp('2026-11-01 23:30'), validityStamp('2026-11-02 00:00')),
	array($morning, validityStamp('2026-11-03 08:00')),
	array(validityStamp('2026-11-02 23:30'), validityStamp('2026-11-03 00:00'))) as $forged) {
	validityRejects(static function () use ($group, $forged, $now) {
		BookingAvailability::availableSelection($group, array(), $forged, $now);
	}, OutOfBoundsException::class, 'V04: forged continuous/split selections cannot include a unit outside either validity boundary');
}
fwrite(STDOUT, "[OK] V04 continuous and split selections enforce every validity boundary\n");

foreach (array('2026-03-29' => 23, '2026-10-25' => 25) as $date => $hours) {
	$dst = array_replace($base, array('valid_from' => $date, 'valid_until' => $date));
	$bounds = BookingAvailability::validityRange($dst);
	validityCheck($bounds['end'] - $bounds['start'] === $hours * 3600, 'V05: validity follows the native 23/25-hour local day across DST');
	$clock = (new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('Europe/Zurich')))->modify('-1 day')->getTimestamp();
	$dstSlots = BookingAvailability::slots($dst, array(), $clock, $bounds['end'] + 86400, $clock);
	validityCheck($dstSlots && $dstSlots[0]['start'] === $bounds['start'] && end($dstSlots)['end'] === $bounds['end'],
		'V05: DST does not clip the beginning or exact final midnight of the valid day');
	foreach ($dstSlots as $slot) validityCheck($slot['start'] >= $bounds['start'] && $slot['end'] <= $bounds['end']
		&& $slot['end'] - $slot['start'] === 1800
		&& (new DateTimeImmutable('@'.$slot['start']))->setTimezone(new DateTimeZone('Europe/Zurich'))->format('H') !== '02',
		'V05: appointments remain inside the local date, keep real duration and omit ambiguous/nonexistent hours');
	$processZone = date_default_timezone_get();
	date_default_timezone_set('America/New_York');
	try {
		validityCheck(BookingAvailability::slots($dst, array(), $clock, $bounds['end'] + 86400, $clock) === $dstSlots,
			'V05: process timezone cannot shift the profile validity dates');
	} finally {
		date_default_timezone_set($processZone);
	}
}
$skipped = array_replace($base, array('timezone' => 'Pacific/Apia', 'valid_from' => '2011-12-30', 'valid_until' => '2011-12-30'));
$skippedRange = BookingAvailability::validityRange($skipped);
validityCheck($skippedRange['start'] === $skippedRange['end'], 'V05: a timezone-skipped date does not accidentally include the following day');
$skippedClock = validityStamp('2011-12-28 00:00', 'Pacific/Apia');
validityCheck(BookingAvailability::slots($skipped, array(), $skippedClock, validityStamp('2012-01-02 00:00', 'Pacific/Apia'), $skippedClock) === array(),
	'V05: a skipped local validity day yields no phantom availability');
fwrite(STDOUT, "[OK] V05 DST, exact midnight, process timezone and skipped-day boundaries\n");
fwrite(STDOUT, "All CDav profile validity tests passed.\n");
