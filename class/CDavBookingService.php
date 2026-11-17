<?php

namespace Dolibarr\CDav;

require_once __DIR__.'/CDavBookingAvailability.php';
require_once __DIR__.'/CDavBookingCalendarReader.php';
require_once __DIR__.'/CDavBookingDetails.php';

/** Public errors are translation keys, never SQL, event titles or coordinates. */
class BookingException extends \RuntimeException
{
	/**
	 * Build an error safe to translate in a public response.
	 * @param string $key Translation key
	 * @param int $status HTTP status
	 */
	public function __construct($key, $status = 400)
	{
		parent::__construct($key, $status);
	}
}

/** Native Agenda writes with a deliberately narrow public authorization scope. */
class BookingService
{
	private $db;
	private $entity;
	public const REQUEST_TTL = 900;
	private const RECEIPT_RETENTION = 86400;
	private const SELECTION_ERROR_KEYS = array('CDavBookingSelectionContiguousDisabled', 'CDavBookingSelectionSplitDisabled');

	/**
	 * Bind technical state to the current native entity.
	 * @param \DoliDB $db Native database connection
	 * @param int $entity Current entity
	 */
	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = max(1, (int) $entity);
	}

	/**
	 * Fail closed without exposing SQL or native error messages publicly.
	 * @param string $sql Technical query
	 * @return mixed Native result
	 */
	private function query($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) throw new BookingException('CDavBookingUnavailable', 503);
		return $result;
	}

	/**
	 * Quote a technical value using the native database driver.
	 * @param string $value Value
	 * @return string SQL literal
	 */
	private function quote($value)
	{
		return "'".$this->db->escape((string) $value)."'";
	}

	/**
	 * Require the native administrator for profile management.
	 * @return void
	 */
	private function manager()
	{
		global $user;
		if (!is_object($user) || !$user->admin || !empty($user->socid)) {
			throw new BookingException('CDavBookingForbidden', 403);
		}
	}

	/**
	 * Load an active native owner with the required Agenda rights.
	 * @param int $ownerId Owner identifier
	 * @return \User Owner
	 */
	private function owner($ownerId)
	{
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		$row = $this->db->fetch_object($this->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $ownerId).' AND statut = 1 AND fk_soc IS NULL AND entity IN (0, '.$this->entity.')'));
		if (!$row) throw new BookingException('CDavBookingNotFound', 404);
		$owner = new \User($this->db);
		if ($owner->fetch((int) $ownerId) <= 0 || $owner->loadRights() < 0
			|| !$owner->hasRight('agenda', 'myactions', 'read')
			|| !$owner->hasRight('agenda', 'myactions', 'write')) {
			throw new BookingException('CDavBookingNotFound', 404);
		}
		return $owner;
	}

	/**
	 * Normalize stored settings before using them as booking authority.
	 * @param object $row Native database row
	 * @return array Profile
	 */
	private function profile($row)
	{
		$config = json_decode((string) $row->config, true);
		if (!is_array($config)) throw new BookingException('CDavBookingUnavailable', 503);
		return array('id' => (int) $row->rowid, 'owner_id' => (int) $row->fk_user,
			'enabled' => (bool) $row->enabled, 'config' => BookingAvailability::validateConfig($config),
			'token_hash' => (string) $row->token_hash);
	}

	/**
	 * Read settings in the authenticated administration context.
	 * @param int $ownerId Owner identifier
	 * @return array|null Profile
	 */
	public function profileForOwner($ownerId)
	{
		$this->manager();
		$row = $this->db->fetch_object($this->query('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_booking_profile WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId)));
		return $row ? $this->profile($row) : null;
	}

	/**
	 * Save server-controlled hours without restoring a revoked publication key.
	 * @param int $ownerId Owner identifier
	 * @param array $config Settings
	 * @param bool $enabled Profile activation
	 * @return array Profile
	 */
	public function saveProfile($ownerId, array $config, $enabled)
	{
		$this->manager();
		$this->owner($ownerId);
		$config = BookingAvailability::validateConfig($config);
		$json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		$this->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_booking_profile (entity, fk_user, enabled, config) VALUES ('
			.$this->entity.', '.((int) $ownerId).', '.((int) (bool) $enabled).', '.$this->quote($json).') ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config = VALUES(config)');
		return $this->profileForOwner($ownerId);
	}

	/**
	 * Publish a fresh random key, storing only its hash.
	 * @param int $ownerId Owner identifier
	 * @return string New publication key
	 */
	public function rotateToken($ownerId)
	{
		$this->manager();
		$this->owner($ownerId);
		if (!$this->profileForOwner($ownerId)) throw new BookingException('CDavBookingNotFound', 404);
		$key = bin2hex(random_bytes(32));
		$this->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_profile SET token_hash = '.$this->quote(hash('sha256', $key))
			.' WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId));
		return $key;
	}

	/**
	 * Disable the profile and permanently revoke its current key.
	 * @param int $ownerId Owner identifier
	 * @return void
	 */
	public function disableProfile($ownerId)
	{
		$this->manager();
		$this->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_profile SET enabled = 0, token_hash = NULL WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId));
	}

	/**
	 * Bound requests across workers without storing addresses in clear.
	 * @param string $scope Counter scope
	 * @param string $subject Counter identity
	 * @param int $limit Maximum requests
	 * @param int $window Counter window in seconds
	 * @param int|null $now Test clock, otherwise system time
	 * @return void
	 */
	public function throttle($scope, $subject, $limit, $window, $now = null)
	{
		$now = $now === null ? time() : (int) $now;
		$bucket = hash_hmac('sha256', $scope.'|'.$subject.'|'.intdiv($now, $window), $this->secret());
		$this->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_booking_rate (bucket_hash, hits, expires_at) VALUES ('
			.$this->quote($bucket).', 1, '.($now + $window).') ON DUPLICATE KEY UPDATE hits = hits + 1');
		$row = $this->db->fetch_object($this->query('SELECT hits FROM '.MAIN_DB_PREFIX.'cdav_booking_rate WHERE bucket_hash = '.$this->quote($bucket)));
		// Each request prunes a bounded batch; the expiry index keeps this inexpensive.
		$this->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_rate WHERE expires_at < '.$now.' LIMIT 1000');
		$this->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_booking_request WHERE expires_at < '.$now.' LIMIT 100');
		if (!$row || (int) $row->hits > $limit) throw new BookingException('CDavBookingRateLimit', 429);
	}

	/**
	 * Resolve the narrow public authorization and optionally recheck under lock.
	 * @param string $key Publication key
	 * @param bool $lock Request current locking reads inside a transaction
	 * @return array Profile
	 */
	public function publicProfile($key, $lock = false)
	{
		global $langs;
		if (!isModEnabled('cdav') || !isModEnabled('agenda') || !getDolGlobalInt('CDAV_BOOKING_ENABLED')
			|| !preg_match('/^[a-f0-9]{64}$/D', (string) $key)) {
			throw new BookingException('CDavBookingNotFound', 404);
		}
		if ($lock) {
			// Configuration loaded by main.inc.php may predate a concurrent disable.
			$active = $this->db->fetch_object($this->query('SELECT value FROM '.MAIN_DB_PREFIX.'const'
				." WHERE name = 'CDAV_BOOKING_ENABLED' AND entity = ".$this->entity.' FOR UPDATE'));
			if (!$active || (string) $active->value !== '1') throw new BookingException('CDavBookingNotFound', 404);
		}
		$row = $this->db->fetch_object($this->query('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_booking_profile WHERE entity = '.$this->entity.' AND enabled = 1 AND token_hash = '.$this->quote(hash('sha256', $key))
			.($lock ? ' FOR UPDATE' : '')));
		if (!$row) throw new BookingException('CDavBookingNotFound', 404);
		$profile = $this->profile($row);
		$owner = $this->owner($profile['owner_id']);
		if ($owner->loadPersonalConf() < 0) throw new BookingException('CDavBookingUnavailable', 503);
		$profile['professional'] = array('name' => $owner->getFullName($langs),
			'email' => getDolUserInt('USER_PUBLIC_HIDE_EMAIL', 0, $owner) ? '' : (string) $owner->email,
			'phone' => getDolUserInt('USER_PUBLIC_HIDE_OFFICE_PHONE', 0, $owner) ? '' : (string) $owner->office_phone,
			'mobile' => getDolUserInt('USER_PUBLIC_HIDE_USER_MOBILE', 0, $owner) ? '' : (string) $owner->user_mobile,
			'photo' => getDolUserInt('USER_PUBLIC_HIDE_PHOTO', 0, $owner) ? '' : (string) $owner->photo,
			'photo_directory' => get_exdir(0, 0, 0, 0, $owner, 'user').'photos/');
		return $profile;
	}

	/**
	 * Reuse the native instance secret without adding a second secret store.
	 * @return string Signing secret
	 */
	private function secret()
	{
		global $conf;
		$secret = (string) ($conf->file->instance_unique_id ?? '');
		if ($secret === '') throw new BookingException('CDavBookingUnavailable', 503);
		return $secret;
	}

	/**
	 * Bind a form to the current publication and settings.
	 * @param array $profile Profile
	 * @param string $payload Ticket payload
	 * @return string Signature
	 */
	private function requestSignature(array $profile, $payload)
	{
		return hash_hmac('sha256', 'cdav-booking|'.$profile['id'].'|'.$profile['token_hash'].'|'
			.hash('sha256', json_encode($profile['config'])).'|'.$payload, $this->secret());
	}

	/**
	 * Issue a stateless form ticket with a bounded lifetime.
	 * @param array $profile Profile
	 * @param int|null $now Test clock, otherwise system time
	 * @return string Signed ticket
	 */
	public function requestTicket(array $profile, $now = null)
	{
		$now = $now === null ? time() : (int) $now;
		$payload = bin2hex(random_bytes(24)).'.'.($now + self::REQUEST_TTL);
		return $payload.'.'.$this->requestSignature($profile, $payload);
	}

	/**
	 * Reject expired requests even after their idempotence receipt was purged.
	 * @param array $profile Profile
	 * @param string $ticket Submitted ticket
	 * @param int $now Current time
	 * @return array Request hash and expiry
	 */
	private function validateTicket(array $profile, $ticket, $now)
	{
		if (!preg_match('/^([a-f0-9]{48})\.([0-9]{10})\.([a-f0-9]{64})$/D', (string) $ticket, $parts)
			|| (int) $parts[2] <= $now || (int) $parts[2] > $now + self::REQUEST_TTL
			|| !hash_equals($this->requestSignature($profile, $parts[1].'.'.$parts[2]), $parts[3])) {
			throw new BookingException('CDavBookingExpired', 409);
		}
		return array(hash('sha256', $profile['id'].'|'.$parts[1]), (int) $parts[2]);
	}

	/**
	 * Return fresh slots using native Agenda data.
	 * @param array $profile Profile
	 * @param int $start Range start timestamp
	 * @param int $end Range end timestamp
	 * @param int|null $now Test clock, otherwise system time
	 * @return array Public start/end pairs
	 */
	public function slots(array $profile, $start, $end, $now = null)
	{
		global $langs;
		$now = $now === null ? time() : (int) $now;
		if ($end <= $start || $end - $start > 32 * 86400 || $start < $now - 86400
			|| $end > BookingAvailability::horizonEnd($profile['config'], $now) + 2 * 86400) {
			throw new BookingException('CDavBookingInvalid', 422);
		}
		$owner = $this->owner($profile['owner_id']);
		$reader = new BookingCalendarReader($this->db, $owner, $langs);
		$padding = ($profile['config']['buffer_before'] + $profile['config']['buffer_after']) * 60;
		// Both appointments reserve their preparation/follow-up time; include
		// nearby native occupations whose expanded interval reaches this day.
		$busy = $reader->busy($start - $padding, $end + $padding, $profile['config']['timezone']);
		return BookingAvailability::slots($profile['config'], $busy, $start, $end, $now);
	}

	/**
	 * Build a public month from one fresh native Agenda read.
	 *
	 * @param array $profile Validated public profile
	 * @param string $month Month in the owner's timezone, YYYY-MM
	 * @param string $date Selected day, or empty to choose the first available day
	 * @param int|null $now Test clock, otherwise system time
	 * @return array Selected date, its slots and anonymous daily availability
	 */
	public function calendar(array $profile, string $month, string $date = '', ?int $now = null): array
	{
		$now = $now ?? time();
		$zone = new \DateTimeZone($profile['config']['timezone']);
		$today = (new \DateTimeImmutable('@'.$now))->setTimezone($zone);
		if (!preg_match('/^\d{4}-\d{2}$/D', $month)) throw new BookingException('CDavBookingInvalid', 422);
		$first = \DateTimeImmutable::createFromFormat('!Y-m', $month, $zone);
		$last = (new \DateTimeImmutable('@'.BookingAvailability::horizonEnd($profile['config'], $now)))->setTimezone($zone);
		if (!$first || $first->format('Y-m') !== $month
			|| $month < $today->format('Y-m') || $month > $last->format('Y-m')) {
			throw new BookingException('CDavBookingInvalid', 422);
		}
		if ($date !== '') {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) throw new BookingException('CDavBookingInvalid', 422);
			$selected = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
			if (!$selected || $selected->format('Y-m-d') !== $date
				|| $selected->format('Y-m') !== $month) throw new BookingException('CDavBookingInvalid', 422);
		}
		$next = $first->modify('+1 month');
		$previous = $first->modify('-1 month');
		$days = array();
		$byDay = array();
		for ($day = $first; $day < $next; $day = $day->modify('+1 day')) $days[$day->format('Y-m-d')] = 0;
		$from = max($first->getTimestamp(), $now);
		$until = min($next->getTimestamp(), $last->getTimestamp());
		$slots = $until > $from ? $this->slots($profile, $from, $until, $now) : array();
		foreach ($slots as $slot) {
			$dayKey = (new \DateTimeImmutable('@'.$slot['start']))->setTimezone($zone)->format('Y-m-d');
			$days[$dayKey]++;
			$byDay[$dayKey][] = $slot;
		}
		if ($date === '') {
			$date = $month === $today->format('Y-m') ? $today->format('Y-m-d') : $first->format('Y-m-d');
			foreach ($days as $dayKey => $count) {
				if ($count > 0) { $date = $dayKey; break; }
			}
		}
		return array('date' => $date, 'slots' => $byDay[$date] ?? array(), 'calendar' => array(
			'month' => $month,
			'previous' => $previous->format('Y-m') >= $today->format('Y-m') ? $previous->format('Y-m') : null,
			'next' => $next->format('Y-m') <= $last->format('Y-m') ? $next->format('Y-m') : null,
			'days' => $days,
		));
	}

	/**
	 * Recheck a bounded selection against one fresh read per chosen local day.
	 *
	 * @param array $profile Validated public profile
	 * @param array $starts Selected unit starts
	 * @param int|null $now Current timestamp, supplied for deterministic checks
	 * @return array Canonical starts, available segments and total minutes
	 */
	public function previewSelection(array $profile, array $starts, ?int $now = null): array
	{
		global $langs;
		$now = $now ?? time();
		try {
			$selection = BookingAvailability::availableSelection($profile['config'], array(), $starts, $now);
		} catch (\OutOfBoundsException $e) {
			throw new BookingException('CDavBookingConflict', 409);
		} catch (\InvalidArgumentException $e) {
			throw new BookingException(in_array($e->getMessage(), self::SELECTION_ERROR_KEYS, true) ? $e->getMessage() : 'CDavBookingInvalid', 422);
		}
		$owner = $this->owner($profile['owner_id']);
		$reader = new BookingCalendarReader($this->db, $owner, $langs);
		$zone = new \DateTimeZone($profile['config']['timezone']);
		$padding = ($profile['config']['buffer_before'] + $profile['config']['buffer_after']) * 60;
		$days = array();
		$busy = array();
		foreach ($selection['starts'] as $start) {
			$day = (new \DateTimeImmutable('@'.$start))->setTimezone($zone)->setTime(0, 0);
			$key = $day->format('Y-m-d');
			if (isset($days[$key])) continue;
			$days[$key] = true;
			$busy = array_merge($busy, $reader->busy($day->getTimestamp() - $padding,
				$day->modify('+1 day')->getTimestamp() + $padding, $profile['config']['timezone']));
		}
		try {
			return BookingAvailability::availableSelection($profile['config'], $busy, $selection['starts'], $now);
		} catch (\OutOfBoundsException $e) {
			throw new BookingException('CDavBookingConflict', 409);
		} catch (\InvalidArgumentException $e) {
			throw new BookingException(in_array($e->getMessage(), self::SELECTION_ERROR_KEYS, true) ? $e->getMessage() : 'CDavBookingInvalid', 422);
		}
	}

	/**
	 * Keep the existing single-appointment response contract.
	 * @param string $key Publication key
	 * @param string $ticket Signed form ticket
	 * @param int $start Selected timestamp
	 * @param array $contact Visitor coordinates
	 * @return array Public single receipt
	 */
	public function reserve($key, $ticket, $start, array $contact)
	{
		$result = $this->reserveSelection($key, $ticket, array($start), $contact);
		unset($result['segments'], $result['duration_minutes']);
		return $result;
	}

	/**
	 * Reserve every selected segment atomically, using the native owner-row lock.
	 * @param string $key Publication key
	 * @param string $ticket Signed form ticket
	 * @param array $starts Selected unit start timestamps
	 * @param array $contact Visitor coordinates
	 * @return array Public group receipt with segment start/end pairs
	 */
	public function reserveMany($key, $ticket, array $starts, array $contact): array
	{
		return $this->reserveSelection($key, $ticket, $starts, $contact);
	}

	/**
	 * Build a receipt without exposing event identifiers or visitor coordinates.
	 * @param array $selection Canonical selection
	 * @param string $reference Shared public reference
	 * @param bool $replayed Whether an existing receipt is being returned
	 * @return array Public receipt
	 */
	private function selectionReceipt(array $selection, string $reference, bool $replayed): array
	{
		$segments = $selection['segments'];
		return array('reference' => $reference, 'start' => $segments[0]['start'], 'end' => $segments[count($segments) - 1]['end'],
			'replayed' => $replayed, 'segments' => $segments, 'duration_minutes' => $selection['duration_minutes']);
	}

	/**
	 * Require every original native event to remain in this profile's scope.
	 * @param object $receipt Stored technical receipt
	 * @param array $profile Current validated profile
	 * @param array $selection Canonical original selection
	 * @return void
	 */
	private function verifyReceipt($receipt, array $profile, array $selection): void
	{
		if (empty($receipt->fk_actioncomm)) throw new BookingException('CDavBookingNoLongerActive', 410);
		$rows = $this->query('SELECT segment_no, fk_actioncomm FROM '.MAIN_DB_PREFIX.'cdav_booking_request_event WHERE request_hash = '
			.$this->quote($receipt->request_hash).' ORDER BY segment_no');
		$ids = array();
		while ($row = $this->db->fetch_object($rows)) {
			if ((int) $row->segment_no !== count($ids) || empty($row->fk_actioncomm)) throw new BookingException('CDavBookingNoLongerActive', 410);
			$ids[] = (int) $row->fk_actioncomm;
		}
		// Receipts issued before group booking have only the original native FK.
		if (!$ids) $ids[] = (int) $receipt->fk_actioncomm;
		if (count($ids) !== count($selection['segments'])) throw new BookingException('CDavBookingNoLongerActive', 410);
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		foreach ($ids as $position => $id) {
			$event = new \ActionComm($this->db);
			$segment = $selection['segments'][$position];
			if ($event->fetch($id) <= 0 || (int) $event->entity !== $this->entity || (int) $event->userownerid !== $profile['owner_id']
				|| (int) $event->datep !== $segment['start'] || (int) $event->datef !== $segment['end']
				|| (int) $event->percentage !== -1
				|| (int) ($event->userassigned[$profile['owner_id']]['transparency'] ?? 0) !== 1) {
				throw new BookingException('CDavBookingNoLongerActive', 410);
			}
		}
	}

	/**
	 * Share validation, transaction, native writes and idempotence across both APIs.
	 * @param string $key Publication key
	 * @param string $ticket Signed form ticket
	 * @param array $starts Selected unit start timestamps
	 * @param array $contact Visitor coordinates
	 * @return array Public group receipt
	 */
	private function reserveSelection($key, $ticket, array $starts, array $contact): array
	{
		global $langs;
		$now = time();
		$initial = $this->publicProfile($key);
		list($requestHash, $validUntil) = $this->validateTicket($initial, $ticket, $now);
		try { $details = BookingDetails::normalize($contact); } catch (\InvalidArgumentException $e) {
			throw new BookingException('CDavBookingInvalid', 422);
		}
		$clean = $details['contact'];
		try { $selection = BookingAvailability::planSelection($initial['config'], $starts); } catch (\InvalidArgumentException $e) {
			throw new BookingException(in_array($e->getMessage(), self::SELECTION_ERROR_KEYS, true) ? $e->getMessage() : 'CDavBookingInvalid', 422);
		}
		$starts = $selection['starts'];
		// A legacy replay retains its historical contact shape and hash; new
		// requests include subject and address in the same canonical payload.
		$bodyHash = hash('sha256', json_encode(array(count($starts) === 1 ? $starts[0] : $starts, $clean)));
		if (!$this->db->begin()) throw new BookingException('CDavBookingUnavailable', 503);
		try {
			// No consistent/snapshot read may precede this native locking read.
			$locked = $this->db->fetch_object($this->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$initial['owner_id'].' AND statut = 1 AND fk_soc IS NULL AND entity IN (0, '.$this->entity.') FOR UPDATE'));
			if (!$locked) throw new BookingException('CDavBookingNotFound', 404);
			$profile = $this->publicProfile($key, true);
			$this->validateTicket($profile, $ticket, time());
			$previous = $this->db->fetch_object($this->query('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_booking_request WHERE request_hash = '.$this->quote($requestHash).' FOR UPDATE'));
			if ($previous) {
				if (!hash_equals((string) $previous->body_hash, $bodyHash)) throw new BookingException('CDavBookingConflict', 409);
				$this->verifyReceipt($previous, $profile, $selection);
				$result = $this->selectionReceipt($selection, (string) $previous->reference, true);
			} else {
				if ($details['legacy']) throw new BookingException('CDavBookingInvalid', 422);
				$selection = $this->previewSelection($profile, $starts, time());
				$owner = $this->owner($profile['owner_id']);
				require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
				$reference = bin2hex(random_bytes(16));
				$ids = array();
				foreach ($selection['segments'] as $segment) {
					$event = new \ActionComm($this->db);
					$event->type_code = 'AC_RDV';
					$event->label = $clean['subject'];
					$event->location = $clean['address'];
					$event->datep = $segment['start'];
					$event->datef = $segment['end'];
					$event->userownerid = $owner->id;
					$event->userassigned = array($owner->id => array('id' => $owner->id, 'transparency' => 1));
					$event->transparency = 1;
					$event->percentage = -1;
					$event->priority = 0;
					$event->note_private = 'CDav booking '.$reference."\n".$clean['name']."\n".$clean['email']."\n".$clean['phone']
						."\n".$langs->transnoentitiesnoconv('CDavBookingSubject').': '.$clean['subject']
						."\n".$langs->transnoentitiesnoconv('CDavBookingClientAddress').":\n".$clean['address'];
					if ($event->create($owner) <= 0) throw new BookingException('CDavBookingUnavailable', 503);
					$ids[] = (int) $event->id;
				}
				$result = $this->selectionReceipt($selection, $reference, false);
				$this->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_booking_request (request_hash, fk_profile, fk_actioncomm, body_hash, reference, start_at, end_at, expires_at) VALUES ('
					.$this->quote($requestHash).', '.$profile['id'].', '.$ids[0].', '.$this->quote($bodyHash).', '
					.$this->quote($reference).', '.$result['start'].', '.$result['end'].', '.($validUntil + self::RECEIPT_RETENTION).')');
				foreach ($ids as $position => $id) {
					$this->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_booking_request_event (request_hash, segment_no, fk_actioncomm) VALUES ('
						.$this->quote($requestHash).', '.$position.', '.$id.')');
				}
			}
			if (!$this->db->commit()) throw new BookingException('CDavBookingUnavailable', 503);
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}
}
