<?php

namespace Dolibarr\CDav;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;

/** Pure booking rules. Native Sabre owns calendar parsing and recurrence maths. */
class BookingAvailability
{
	public const MAX_EVENTS = 5000;
	public const MAX_CALENDAR_BYTES = 8388608;
	public const MAX_ITERATIONS = 50000;
	public const MAX_SLOTS = 10000;
	public const MAX_RANGE_SECONDS = 93 * 86400;
	public const MAX_SELECTION_SLOTS = 24;
	public const MAX_SELECTION_DAYS = 8;

	/**
	 * Validate server-owned settings; never infer them from a submitted slot.
	 *
	 * @param array $config Profile settings
	 * @return array Normalized profile settings
	 */
	public static function validateConfig(array $config): array
	{
		$timezone = $config['timezone'] ?? 'Europe/Zurich';
		if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
			throw new \InvalidArgumentException('Invalid booking timezone');
		}
		$horizonUnit = $config['horizon_unit'] ?? 'days';
		if (!in_array($horizonUnit, array('days', 'months'), true)) {
			throw new \InvalidArgumentException('Invalid booking horizon unit');
		}
		$result = array('timezone' => $timezone, 'horizon_unit' => $horizonUnit);
		foreach (array('allow_contiguous', 'allow_split') as $permission) {
			$value = array_key_exists($permission, $config) ? $config[$permission] : false;
			if (!is_bool($value)) throw new \InvalidArgumentException('Invalid booking selection permission');
			$result[$permission] = $value;
		}
		foreach (array('duration' => array(30, 5, 480), 'step' => array(30, 5, 240),
			'buffer_before' => array(0, 0, 240), 'buffer_after' => array(0, 0, 240),
			'notice' => array(1440, 0, 43200), 'horizon' => array($horizonUnit === 'months' ? 6 : 60, 1, $horizonUnit === 'months' ? 6 : 180)) as $key => $rule) {
			$value = $config[$key] ?? $rule[0];
			if ((!is_int($value) && (!is_string($value) || !preg_match('/^[0-9]{1,6}$/D', $value)))
				|| (int) $value < $rule[1] || (int) $value > $rule[2]) {
				throw new \InvalidArgumentException('Invalid booking '.$key);
			}
			$result[$key] = (int) $value;
		}
		$defaultWeek = array_fill(1, 5, array(array('09:00', '12:00'), array('13:00', '17:00')));
		$weekly = $config['weekly'] ?? $defaultWeek;
		if (!is_array($weekly) || count($weekly) > 7) {
			throw new \InvalidArgumentException('Invalid booking weekly hours');
		}
		$result['weekly'] = array_fill(1, 7, array());
		foreach ($weekly as $weekday => $ranges) {
			if (!preg_match('/^[1-7]$/D', (string) $weekday) || !is_array($ranges) || count($ranges) > 4) {
				throw new \InvalidArgumentException('Invalid booking weekly hours');
			}
			$minutes = array();
			foreach ($ranges as $range) {
				if (!is_array($range) || array_keys($range) !== array(0, 1)) {
					throw new \InvalidArgumentException('Invalid booking time range');
				}
				$from = self::timeMinutes($range[0], false);
				$to = self::timeMinutes($range[1], true);
				if ($from >= $to) throw new \InvalidArgumentException('Invalid booking time range');
				$minutes[] = array($from, $to);
			}
			usort($minutes, static function ($a, $b) {
				return $a[0] <=> $b[0]; });
			$merged = array();
			foreach ($minutes as $range) {
				$last = count($merged) - 1;
				if ($last >= 0 && $range[0] < $merged[$last][1]) {
					throw new \InvalidArgumentException('Booking working hours overlap');
				}
				if ($last >= 0 && $range[0] === $merged[$last][1]) $merged[$last][1] = $range[1];
				else $merged[] = $range;
			}
			foreach ($merged as $range) {
				$result['weekly'][(int) $weekday][] = array(self::formatMinutes($range[0]), self::formatMinutes($range[1]));
			}
		}
		return $result;
	}

	/**
	 * Check whether weekly hours can fit any start, before calendar or notice rules.
	 *
	 * @param array $config Profile settings
	 * @return bool Whether a complete reservation fits the configured start grid
	 */
	public static function hasWeeklySlot(array $config): bool
	{
		$config = self::validateConfig($config);
		$firstStart = intdiv($config['buffer_before'] + $config['step'] - 1, $config['step']) * $config['step'];
		$required = $firstStart + $config['duration'] + $config['buffer_after'];
		foreach ($config['weekly'] as $ranges) {
			foreach ($ranges as $range) {
				if (self::timeMinutes($range[1], true) - self::timeMinutes($range[0], false) >= $required) return true;
			}
		}
		return false;
	}

	/**
	 * Calculate the local booking deadline, clamping calendar months at month end.
	 *
	 * @param array $config Profile settings
	 * @param int $now Current Unix timestamp, supplied explicitly for deterministic tests
	 * @return int Exclusive booking deadline as a Unix timestamp
	 */
	public static function horizonEnd(array $config, int $now): int
	{
		$config = self::validateConfig($config);
		$reference = (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($config['timezone']));
		if ($config['horizon_unit'] === 'days') return $reference->modify('+'.$config['horizon'].' days')->getTimestamp();
		$target = $reference->setDate((int) $reference->format('Y'), (int) $reference->format('m'), 1)
			->modify('+'.$config['horizon'].' months');
		return $target->setDate((int) $target->format('Y'), (int) $target->format('m'),
			min((int) $reference->format('d'), (int) $target->format('t')))
			->setTime((int) $reference->format('H'), (int) $reference->format('i'), (int) $reference->format('s'))->getTimestamp();
	}

	/**
	 * Expand private calendars into anonymous occupied intervals, or fail closed.
	 *
	 * @param string[] $calendarData Native-exported iCalendar resources
	 * @param int $start Range start, Unix timestamp
	 * @param int $end Exclusive range end, Unix timestamp
	 * @param string $timezone Reference timezone for floating and date-only values
	 * @return array Occupied intervals as pairs of Unix timestamps
	 */
	public static function busyIntervals(array $calendarData, int $start, int $end, string $timezone): array
	{
		self::validateRange($start, $end);
		$zone = new DateTimeZone($timezone);
		if (count($calendarData) > self::MAX_EVENTS) throw new \RuntimeException('Booking calendar event limit exceeded');
		$busy = array();
		$bytes = 0;
		$iterations = 0;
		$deadline = microtime(true) + 2.0;
		$previousLimit = VObject\Settings::$maxRecurrences;
		VObject\Settings::$maxRecurrences = 10000;
		try {
			foreach ($calendarData as $data) {
				if (microtime(true) > $deadline) throw new \RuntimeException('Booking calculation time limit exceeded');
				if (!is_string($data) || strlen($data) > 1048576 || ($bytes += strlen($data)) > self::MAX_CALENDAR_BYTES) {
					throw new \RuntimeException('Booking calendar size limit exceeded');
				}
				$calendar = VObject\Reader::read($data);
				try {
					if (!$calendar instanceof VObject\Component\VCalendar) throw new \RuntimeException('Invalid booking calendar');
					$groups = array();
					$components = 0;
					foreach ($calendar->select('VEVENT') as $event) {
						if (++$components > self::MAX_EVENTS) throw new \RuntimeException('Booking calendar component limit exceeded');
						if (!isset($event->UID) || !isset($event->DTSTART)) throw new \RuntimeException('Incomplete booking event');
						$groups[(string) $event->UID][] = $event;
					}
					foreach ($groups as $events) {
						$masters = array_values(array_filter($events, static function ($event) {
							return !isset($event->{'RECURRENCE-ID'}); }));
						if (count($masters) > 1) throw new \RuntimeException('Ambiguous booking recurrence');
						$master = $masters[0] ?? null;
						$hasExceptions = count($events) !== count($masters);
						foreach ($events as $event) self::validateEvent($event, $zone);
						if ($master && !$hasExceptions && self::isFree($master)) continue;
						if ($master && !$hasExceptions && !isset($master->RRULE) && !isset($master->RDATE)) {
							$from = $master->DTSTART->getDateTime($zone)->getTimestamp();
							$to = self::eventEnd($master, $zone);
							if ($from < $end && $to > $start) $busy[] = array(max($start, $from), min($end, $to));
							if (microtime(true) > $deadline) throw new \RuntimeException('Booking calculation time limit exceeded');
							continue;
						}
						$allDaySpan = null;
						if ($master && !$master->DTSTART->hasTime()) {
							$allDaySpan = (new DateTimeImmutable('@'.self::eventEnd($master, $zone)))->setTimezone($zone)->diff($master->DTSTART->getDateTime($zone))->days;
							if (!$allDaySpan) throw new \RuntimeException('Invalid all-day booking duration');
						}
						// Sabre chooses RDATE instead of RRULE when both exist. Expand
						// their union using two native iterators, then merge the result.
						$passes = ($master && isset($master->RRULE) && isset($master->RDATE)) ? 2 : 1;
						for ($pass = 0; $pass < $passes; $pass++) {
							$source = array();
							$overrides = array();
							foreach ($events as $event) {
								$clone = clone $event;
								if ($passes === 2 && !isset($clone->{'RECURRENCE-ID'})) $clone->remove($pass === 0 ? 'RDATE' : 'RRULE');
								if (isset($clone->RDATE)) self::normalizeRecurrenceDates($clone, $zone);
								if (isset($clone->{'RECURRENCE-ID'})) $overrides[spl_object_id($clone)] = true;
								$source[] = $clone;
							}
							try { $iterator = new EventIterator($source, null, $zone); } catch (NoInstancesException $e) { continue; }
							while ($iterator->valid()) {
								if (++$iterations > self::MAX_ITERATIONS || microtime(true) > $deadline) {
									throw new \RuntimeException('Booking recurrence calculation limit exceeded');
								}
								$occurrenceStart = $iterator->getDTStart();
								$from = $occurrenceStart->getTimestamp();
								if ($from >= $end) break;
								if (!$hasExceptions) {
									// An all-day duration is a number of calendar days,
									// not the fixed seconds used by EventIterator.
									$to = $allDaySpan !== null ? $occurrenceStart->modify('+'.$allDaySpan.' days')->getTimestamp() : $iterator->getDTEnd()->getTimestamp();
								} else {
									$event = $iterator->getEventObject();
									if (self::isFree($event)) { $iterator->next(); continue; }
									$to = $allDaySpan !== null && !isset($overrides[spl_object_id($event)])
										? $occurrenceStart->modify('+'.$allDaySpan.' days')->getTimestamp() : self::eventEnd($event, $zone);
								}
								if ($to > $start) $busy[] = array(max($start, $from), min($end, $to));
								$iterator->next();
							}
						}
					}
				} finally { $calendar->destroy(); }
			}
		} finally { VObject\Settings::$maxRecurrences = $previousLimit; }
		return self::mergeIntervals($busy);
	}

	/**
	 * Generate slots in local working hours; omit ambiguous/nonexistent hours.
	 *
	 * @param array $config Profile settings
	 * @param array $busy Occupied intervals as timestamp pairs
	 * @param int $start Range start, Unix timestamp
	 * @param int $end Exclusive range end, Unix timestamp
	 * @param int $now Current Unix timestamp, supplied explicitly for deterministic tests
	 * @return array Available slots with start and end timestamps
	 */
	public static function slots(array $config, array $busy, int $start, int $end, int $now): array
	{
		return self::calculateSlots($config, $busy, $start, $end, $now);
	}

	/**
	 * Reuse the slot engine for one configured unit or an internally merged segment.
	 *
	 * @param array $config Profile settings
	 * @param array $busy Occupied timestamp pairs
	 * @param int $start Requested range start
	 * @param int $end Requested exclusive range end
	 * @param int $now Current timestamp
	 * @param int|null $segmentDuration Internal segment duration in seconds
	 * @return array Available timestamp pairs
	 */
	private static function calculateSlots(array $config, array $busy, int $start, int $end, int $now, ?int $segmentDuration = null): array
	{
		self::validateRange($start, $end);
		$config = self::validateConfig($config);
		$zone = new DateTimeZone($config['timezone']);
		$cutoff = $now + $config['notice'] * 60;
		$horizon = self::horizonEnd($config, $now);
		$start = max($start, $cutoff);
		$end = min($end, $horizon);
		if ($start >= $end) return array();
		$busy = self::mergeIntervals($busy);
		$duration = $segmentDuration ?? $config['duration'] * 60;
		$before = $config['buffer_before'] * 60;
		$after = $config['buffer_after'] * 60;
		// Every occupied event reserves its own margins, just like a candidate.
		// This makes the required gap before + after, regardless of booking order.
		// Constant expansion preserves the ordering established by mergeIntervals.
		foreach ($busy as $index => $interval) {
			$busy[$index] = array($interval[0] - $before, $interval[1] + $after);
		}
		$day = (new DateTimeImmutable('@'.$start))->setTimezone($zone)->setTime(0, 0);
		$slots = array();
		while ($day->getTimestamp() < $end) {
			$offsets = array();
			foreach ($zone->getTransitions($day->getTimestamp() - 86400, $day->getTimestamp() + 2 * 86400) ?: array() as $transition) {
				$offsets[(int) $transition['offset']] = (int) $transition['offset'];
			}
			if (!$offsets) $offsets[] = $zone->getOffset($day);
			foreach ($config['weekly'][(int) $day->format('N')] as $range) {
				$fromMinute = self::timeMinutes($range[0], false);
				$toMinute = self::timeMinutes($range[1], true);
				$windowStart = self::localTimestamp($day->format('Y-m-d'), $fromMinute, $zone, $offsets);
				$windowEnd = self::localTimestamp($day->format('Y-m-d'), $toMinute, $zone, $offsets);
				if ($windowStart === null || $windowEnd === null) continue;
				for ($minute = $fromMinute; $minute < $toMinute; $minute += $config['step']) {
					$from = self::localTimestamp($day->format('Y-m-d'), $minute, $zone, $offsets);
					if ($from === null || $from < $start) continue;
					$to = $from + $duration;
					if ($to > $end || $from - $before < $windowStart || $to + $after > $windowEnd) continue;
					$endLocal = (new DateTimeImmutable('@'.$to))->setTimezone($zone);
					$endMinute = (int) $endLocal->format('H') * 60 + (int) $endLocal->format('i');
					if (self::localTimestamp($endLocal->format('Y-m-d'), $endMinute, $zone, $offsets) !== $to) continue;
					$conflict = false;
					foreach ($busy as $interval) {
						if ($interval[0] >= $to + $after) break;
						if ($interval[1] > $from - $before) { $conflict = true; break; }
					}
					if (!$conflict) {
						$slots[] = array('start' => $from, 'end' => $to);
						if (count($slots) > self::MAX_SLOTS) throw new \RuntimeException('Booking slot limit exceeded');
					}
				}
			}
			$day = $day->modify('+1 day')->setTime(0, 0);
		}
		return $slots;
	}

	/**
	 * Canonicalize bounded unit selections without applying a moving clock.
	 *
	 * @param array $config Profile settings
	 * @param array $starts Submitted unit starts as strict integer timestamps
	 * @return array Canonical starts, distinct native segments and total minutes
	 */
	public static function planSelection(array $config, array $starts): array
	{
		$config = self::validateConfig($config);
		if (!$starts || count($starts) > self::MAX_SELECTION_SLOTS || $starts !== array_values($starts)) {
			throw new \InvalidArgumentException('Invalid booking selection count');
		}
		$seen = array();
		$duration = $config['duration'] * 60;
		foreach ($starts as $start) {
			if (!is_int($start) || $start <= 0 || $start > PHP_INT_MAX - $duration || isset($seen[$start])) {
				throw new \InvalidArgumentException('Invalid or duplicated booking selection');
			}
			$seen[$start] = true;
		}
		sort($starts, SORT_NUMERIC);
		$zone = new DateTimeZone($config['timezone']);
		$days = array();
		$segments = array();
		$lastDay = '';
		foreach ($starts as $start) {
			$day = (new DateTimeImmutable('@'.$start))->setTimezone($zone)->format('Y-m-d');
			$days[$day] = true;
			if (count($days) > self::MAX_SELECTION_DAYS) throw new \InvalidArgumentException('Too many booking selection days');
			$last = count($segments) - 1;
			if ($last >= 0 && $start < $segments[$last]['end']) throw new \InvalidArgumentException('Booking selections overlap');
			if ($last >= 0 && $start === $segments[$last]['end'] && $day === $lastDay) {
				if (!$config['allow_contiguous']) throw new \InvalidArgumentException('CDavBookingSelectionContiguousDisabled');
				$segments[$last]['end'] = $start + $duration;
			} else {
				$segments[] = array('start' => $start, 'end' => $start + $duration);
			}
			$lastDay = $day;
		}
		if (count($segments) > 1 && !$config['allow_split']) throw new \InvalidArgumentException('CDavBookingSelectionSplitDisabled');
		return array('starts' => $starts, 'segments' => $segments, 'duration_minutes' => count($starts) * $config['duration']);
	}

	/**
	 * Validate every chosen unit and each complete segment against fresh occupations.
	 *
	 * @param array $config Profile settings
	 * @param array $busy Native occupied timestamp pairs
	 * @param array $starts Submitted unit start timestamps
	 * @param int $now Current timestamp
	 * @return array Canonical selection safe to reserve at this instant
	 */
	public static function availableSelection(array $config, array $busy, array $starts, int $now): array
	{
		$config = self::validateConfig($config);
		$selection = self::planSelection($config, $starts);
		$zone = new DateTimeZone($config['timezone']);
		$unitConfig = $config;
		$unitConfig['buffer_before'] = $unitConfig['buffer_after'] = 0;
		$checkedDays = array();
		foreach ($selection['starts'] as $start) {
			$day = (new DateTimeImmutable('@'.$start))->setTimezone($zone)->setTime(0, 0);
			$key = $day->format('Y-m-d');
			if (!isset($checkedDays[$key])) {
				$unitSlots = self::calculateSlots($unitConfig, array(), $day->getTimestamp(), $day->modify('+1 day')->getTimestamp(), $now);
				$checkedDays[$key] = array_fill_keys(array_column($unitSlots, 'start'), true);
			}
			if (!isset($checkedDays[$key][$start])) throw new \OutOfBoundsException('A selected booking unit is unavailable');
		}
		$previousEnd = null;
		foreach ($selection['segments'] as $segment) {
			if ($previousEnd !== null && $segment['start'] - $config['buffer_before'] * 60 < $previousEnd + $config['buffer_after'] * 60) {
				throw new \OutOfBoundsException('Selected booking segments have conflicting margins');
			}
			$available = self::calculateSlots($config, $busy, $segment['start'], $segment['end'], $now, $segment['end'] - $segment['start']);
			if ($available !== array($segment)) throw new \OutOfBoundsException('A selected booking segment is unavailable');
			$previousEnd = $segment['end'];
		}
		return $selection;
	}

	/**
	 * Bound a public calculation range.
	 *
	 * @param int $start Range start, Unix timestamp
	 * @param int $end Exclusive range end, Unix timestamp
	 * @return void
	 */
	public static function validateRange(int $start, int $end): void
	{
		if ($start <= 0 || $end <= $start || $end - $start > self::MAX_RANGE_SECONDS) {
			throw new \InvalidArgumentException('Invalid booking date range');
		}
	}

	/**
	 * Parse a validated wall-clock time without accepting PHP date coercions.
	 *
	 * @param mixed $value HH:MM string
	 * @param bool $allowMidnight Whether the exclusive end may be 24:00
	 * @return int Minutes after midnight
	 */
	private static function timeMinutes($value, bool $allowMidnight): int
	{
		if (!is_string($value) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) {
			if ($allowMidnight && $value === '24:00') return 1440;
			throw new \InvalidArgumentException('Invalid booking local time');
		}
		return (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);
	}

	/**
	 * Format normalized working hours.
	 *
	 * @param int $minutes Minutes after midnight
	 * @return string HH:MM
	 */
	private static function formatMinutes(int $minutes): string
	{
		return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
	}

	/**
	 * Resolve a wall time only if the timezone maps it to exactly one instant.
	 *
	 * @param string $date Local date, YYYY-MM-DD
	 * @param int $minute Minutes after midnight
	 * @param DateTimeZone $zone Profile timezone
	 * @param int[] $offsets Possible offsets around the current day
	 * @return int|null Unique timestamp, or null for missing/ambiguous wall time
	 */
	private static function localTimestamp(string $date, int $minute, DateTimeZone $zone, array $offsets): ?int
	{
		$wall = new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
		$wall = $wall->modify('+'.$minute.' minutes');
		$expected = $wall->format('Y-m-d H:i');
		$found = array();
		foreach ($offsets as $offset) {
			$candidate = $wall->getTimestamp() - $offset;
			if ((new DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i') === $expected) $found[] = $candidate;
		}
		return count($found) === 1 ? $found[0] : null;
	}

	/**
	 * Use iCalendar availability semantics rather than Dolibarr's numeric status.
	 *
	 * @param VObject\Component\VEvent $event Event or recurrence exception
	 * @return bool Whether the event does not occupy time
	 */
	private static function isFree($event): bool
	{
		return (isset($event->TRANSP) && strtoupper((string) $event->TRANSP) === 'TRANSPARENT')
			|| (isset($event->STATUS) && strtoupper((string) $event->STATUS) === 'CANCELLED');
	}

	/**
	 * Reject uncertain/unsupported data before considering a period available.
	 *
	 * @param VObject\Component\VEvent $event Event or recurrence exception
	 * @param DateTimeZone $zone Reference timezone
	 * @return void
	 */
	private static function validateEvent($event, DateTimeZone $zone): void
	{
		if (!isset($event->UID) || !isset($event->DTSTART) || (string) $event->UID === '') throw new \RuntimeException('Incomplete booking event');
		foreach (array('UID', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RECURRENCE-ID') as $property) {
			if (count($event->select($property)) > 1) throw new \RuntimeException('Ambiguous booking event property');
		}
		if (isset($event->DTEND) && isset($event->DURATION)) throw new \RuntimeException('Ambiguous booking duration');
		if (isset($event->EXRULE)) throw new \RuntimeException('Unsupported deprecated booking recurrence');
		if (isset($event->{'RECURRENCE-ID'}['RANGE'])) throw new \RuntimeException('Unsupported ranged booking recurrence exception');
		if (isset($event->RRULE)) {
			$rule = $event->RRULE->getParts();
			if (!isset($rule['FREQ']) || !in_array(strtoupper((string) $rule['FREQ']), array('DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'), true)) {
				throw new \RuntimeException('Unsupported booking recurrence frequency');
			}
		}
		// An occupied timed event without a usable end cannot safely free time.
		if (!self::isFree($event)) self::eventEnd($event, $zone);
	}

	/**
	 * Normalize multiple RDATE properties and their timezones for Sabre's iterator.
	 *
	 * @param VObject\Component\VEvent $event Recurrence master or exception
	 * @param DateTimeZone $zone Reference timezone
	 * @return void
	 */
	private static function normalizeRecurrenceDates($event, DateTimeZone $zone): void
	{
		$values = array();
		$property = null;
		foreach ($event->select('RDATE') as $rdate) {
			if (!$rdate instanceof VObject\Property\ICalendar\DateTime || $rdate->hasTime() !== $event->DTSTART->hasTime()) {
				throw new \RuntimeException('Unsupported booking recurrence date type');
			}
			if ($property === null) $property = clone $rdate;
			foreach ($rdate->getDateTimes($zone) as $date) $values[] = $rdate->hasTime() ? $date->setTimezone(new DateTimeZone('UTC')) : $date;
		}
		if (!$property || !$values) throw new \RuntimeException('Empty booking recurrence dates');
		$property->setDateTimes($values);
		$event->remove('RDATE');
		$event->add($property);
	}

	/**
	 * Determine a usable event end using native Sabre date/duration parsers.
	 *
	 * @param VObject\Component\VEvent $event Event or recurrence exception
	 * @param DateTimeZone $zone Reference timezone
	 * @return int Exclusive end timestamp
	 */
	private static function eventEnd($event, DateTimeZone $zone): int
	{
		$start = $event->DTSTART->getDateTime($zone);
		if (isset($event->DTEND)) $end = $event->DTEND->getDateTime($zone);
		elseif (isset($event->DURATION)) $end = $start->add($event->DURATION->getDateInterval());
		elseif (!$event->DTSTART->hasTime()) $end = $start->modify('+1 day');
		else throw new \RuntimeException('Occupied booking event has no duration');
		if ($end <= $start) throw new \RuntimeException('Occupied booking event has invalid duration');
		return $end->getTimestamp();
	}

	/**
	 * Coalesce occupied intervals for deterministic overlap checks.
	 *
	 * @param array $intervals Occupied timestamp pairs
	 * @return array Sorted and merged occupied timestamp pairs
	 */
	private static function mergeIntervals(array $intervals): array
	{
		foreach ($intervals as $interval) {
			if (!is_array($interval) || array_keys($interval) !== array(0, 1) || !is_int($interval[0]) || !is_int($interval[1]) || $interval[1] <= $interval[0]) {
				throw new \InvalidArgumentException('Invalid booking busy interval');
			}
		}
		usort($intervals, static function ($a, $b) {
			return $a[0] <=> $b[0]; });
		$merged = array();
		foreach ($intervals as $interval) {
			$last = count($merged) - 1;
			if ($last >= 0 && $interval[0] <= $merged[$last][1]) $merged[$last][1] = max($merged[$last][1], $interval[1]);
			else $merged[] = $interval;
		}
		return $merged;
	}
}
