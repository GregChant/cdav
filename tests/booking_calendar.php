<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Month presentation rules use the real availability engine, without SQL. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavBookingService.php';

/** Replace only the native Agenda read; keep the real slot and month rules. */
class BookingCalendarFixture extends \Dolibarr\CDav\BookingService
{
	public $busy = array();
	public $calls = array();
	public $fail = false;

	/**
	 * Calculate against fixture occupations instead of a database.
	 * @param array $profile Profile settings
	 * @param int $start Requested range start
	 * @param int $end Requested range end
	 * @param int|null $now Fixed test clock
	 * @return array Available slots
	 */
	public function slots(array $profile, $start, $end, $now = null)
	{
		$this->calls[] = array($start, $end);
		if ($this->fail) throw new RuntimeException('Native calendar read failed');
		return \Dolibarr\CDav\BookingAvailability::slots($profile['config'], $this->busy, $start, $end, $now);
	}
}

/**
 * Assert a public calendar outcome.
 * @param bool $condition Expected true condition
 * @param string $message Failure description
 * @return void
 */
function calendarCheck($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

$zone = new DateTimeZone('Europe/Zurich');
$now = (new DateTimeImmutable('2026-11-17 12:00', $zone))->getTimestamp();
$profile = array('owner_id' => 1, 'config' => \Dolibarr\CDav\BookingAvailability::validateConfig(array(
	'timezone' => 'Europe/Zurich', 'notice' => 1440, 'horizon' => 6, 'horizon_unit' => 'months',
	'weekly' => array(1 => array(array('09:00', '10:00')), 3 => array(array('13:00', '15:00'))),
)));
$fixture = new BookingCalendarFixture(null, 1);
$month = $fixture->calendar($profile, '2026-11', '', $now);
calendarCheck(count($fixture->calls) === 1, 'A month reads the native agenda once, rather than once per day');
calendarCheck(count($month['calendar']['days']) === 30, 'The public month includes every real day');
calendarCheck($month['calendar']['days']['2026-11-16'] === 0 && $month['calendar']['days']['2026-11-17'] === 0,
	'Past days and minimum notice cannot become selectable');
calendarCheck($month['date'] === '2026-11-18' && count($month['slots']) === 4,
	'The default selects the first day with a real slot, including the minimum notice');
calendarCheck($month['calendar']['previous'] === null && $month['calendar']['next'] === '2026-12',
	'Navigation starts at the current month');
calendarCheck($month['calendar']['days']['2026-11-21'] === 0, 'A closed Saturday has no bookable day link');
$stamp = static function ($date) use ($zone) {
	return (new DateTimeImmutable($date, $zone))->getTimestamp(); };
$fixture->busy = array(array($stamp('2026-11-18 00:00'), $stamp('2026-11-19 00:00')));
$closed = $fixture->calendar($profile, '2026-11', '2026-11-18', $now);
calendarCheck($closed['date'] === '2026-11-18' && $closed['slots'] === array()
	&& $closed['calendar']['days']['2026-11-18'] === 0, 'An explicitly selected occupied day stays selected and empty');
$automatic = $fixture->calendar($profile, '2026-11', '', $now);
calendarCheck($automatic['date'] === '2026-11-23', 'An occupied first day is skipped when choosing automatically');

foreach (array('2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05') as $value) {
	$result = $fixture->calendar($profile, $value, '', $now);
	foreach ($result['calendar']['days'] as $date => $count) {
		$daily = $fixture->calendar($profile, $value, $date, $now);
		calendarCheck(count($daily['slots']) === $count, 'Monthly day counts exactly match the selected-day slot list');
	}
}
$last = $fixture->calendar($profile, '2027-05', '', $now);
calendarCheck($last['calendar']['next'] === null && $last['calendar']['days']['2027-05-19'] === 0,
	'The last month is clipped to the exact six-month deadline');
calendarCheck(array_keys($last['calendar']) === array('month', 'previous', 'next', 'days'),
	'Calendar metadata exposes no native event, owner, contact or private label');

foreach (array('2026-10', '2027-06', '2026-00', '2026-13', '2026-1', "2026-11\0", "2026-11\n") as $invalid) {
	$rejected = false;
	try { $fixture->calendar($profile, $invalid, '', $now); } catch (\Dolibarr\CDav\BookingException $e) { $rejected = $e->getCode() === 422; }
	calendarCheck($rejected, 'Invalid or out-of-horizon months must fail closed');
}
foreach (array('2026-11-31', '2026-12-01', '2026-11-1', "2026-11-18\0") as $invalid) {
	$rejected = false;
	try { $fixture->calendar($profile, '2026-11', $invalid, $now); } catch (\Dolibarr\CDav\BookingException $e) { $rejected = $e->getCode() === 422; }
	calendarCheck($rejected, 'The selected date must be real and belong to the displayed month');
}
$fixture->fail = true;
$rejected = false;
try { $fixture->calendar($profile, '2026-11', '', $now); } catch (RuntimeException $e) { $rejected = $e->getMessage() === 'Native calendar read failed'; }
calendarCheck($rejected, 'A failed agenda read cannot produce apparently available days');

$fixture->fail = false;
$fixture->busy = array();
$leapNow = (new DateTimeImmutable('2027-12-31 12:00', $zone))->getTimestamp();
$leap = $fixture->calendar($profile, '2028-02', '', $leapNow);
calendarCheck(count($leap['calendar']['days']) === 29, 'Leap-year February keeps its real final day');
$processZone = date_default_timezone_get();
try {
	date_default_timezone_set('Pacific/Honolulu');
	calendarCheck($fixture->calendar($profile, '2028-02', '', $leapNow) === $leap,
		'Month presentation depends on the profile timezone, not the server timezone');
} finally { date_default_timezone_set($processZone); }

$windowProfile = array('owner_id' => 1, 'config' => \Dolibarr\CDav\BookingAvailability::validateConfig(array(
	'timezone' => 'Europe/Zurich', 'notice' => 0, 'horizon' => 6, 'horizon_unit' => 'months',
	'weekly' => array_fill(1, 7, array(array('09:00', '10:00'))),
	'valid_from' => '2026-12-07', 'valid_until' => '2026-12-07',
)));
$window = $fixture->calendar($windowProfile, '', '', $now);
calendarCheck($window['calendar']['month'] === '2026-12' && $window['date'] === '2026-12-07' && count($window['slots']) === 2,
	'A future one-day profile opens directly on its first bookable month and date');
calendarCheck(array_filter($window['calendar']['days']) === array('2026-12-07' => 2)
	&& $window['calendar']['previous'] === null && $window['calendar']['next'] === null,
	'Only the configured inclusive date is selectable and month navigation respects its bounds');
foreach (array('2026-11', '2027-01') as $outsideMonth) {
	$rejected = false;
	try { $fixture->calendar($windowProfile, $outsideMonth, '', $now); } catch (\Dolibarr\CDav\BookingException $e) { $rejected = $e->getCode() === 422; }
	calendarCheck($rejected, 'A forged calendar month cannot escape the profile validity window');
}
foreach (array(array('valid_until' => '2026-11-16'), array('valid_from' => '2028-01-01')) as $closedBounds) {
	$closedProfile = $windowProfile;
	unset($closedProfile['config']['valid_from'], $closedProfile['config']['valid_until']);
	$closedProfile['config'] = array_replace($closedProfile['config'], $closedBounds);
	$callCount = count($fixture->calls);
	$closedWindow = $fixture->calendar($closedProfile, '', '', $now);
	calendarCheck(array_sum($closedWindow['calendar']['days']) === 0 && $closedWindow['slots'] === array()
		&& $closedWindow['calendar']['previous'] === null && $closedWindow['calendar']['next'] === null,
		'A past or beyond-horizon validity window remains visibly closed without inventing available dates');
	calendarCheck(count($fixture->calls) === $callCount, 'An empty profile window requires no native calendar read');
}
fwrite(STDOUT, "All CDav monthly calendar business tests passed.\n");
