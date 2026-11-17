<?php

namespace Dolibarr\CDav;

/** Read the owner's native agenda; public callers never receive these calendars. */
class BookingCalendarReader
{
	private $db;
	private $owner;
	private $langs;

	/**
	 * Bind reads to the validated owner's native permissions.
	 *
	 * @param \DoliDB $db Native database connection
	 * @param \User $owner Owner with native rights loaded
	 * @param \Translate $langs Native translation service
	 */
	public function __construct($db, $owner, $langs)
	{
		$this->db = $db;
		$this->owner = $owner;
		$this->langs = $langs;
	}

	/**
	 * Throw on unreadable/invalid calendars instead of inventing free time.
	 *
	 * @param int $start Requested start timestamp
	 * @param int $end Requested exclusive end timestamp
	 * @param string $timezone Reference timezone for date-only/floating values
	 * @return array Occupied timestamp pairs
	 */
	public function busy(int $start, int $end, string $timezone): array
	{
		require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
		require_once __DIR__.'/CDavBookingAvailability.php';
		require_once dirname(__DIR__).'/lib/cdav.lib.php';
		BookingAvailability::validateRange($start, $end);
		if (!defined('CDAV_URI_KEY')) define('CDAV_URI_KEY', getDolGlobalString('CDAV_URI_KEY', 'booking'));
		$lib = new \CdavLib($this->owner, $this->db, $this->langs);
		$calendars = $lib->getBookingCalendarData((int) $this->owner->id, $start, $end);
		return BookingAvailability::busyIntervals($calendars, $start, $end, $timezone);
	}
}
