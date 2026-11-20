<?php

namespace Dolibarr\CDav;

require_once __DIR__.'/CDavBookingAvailability.php';
require_once __DIR__.'/CDavBookingCalendarReader.php';
require_once __DIR__.'/CDavBookingDetails.php';
require_once __DIR__.'/CDavBookingPublication.php';

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
			'label' => (string) ($row->label ?? ''), 'thirdparty_id' => (int) ($row->fk_soc ?? 0),
			'project_id' => (int) ($row->fk_project ?? 0),
			'enabled' => (bool) $row->enabled, 'config' => BookingAvailability::validateConfig($config),
			'token_hash' => (string) $row->token_hash);
	}

	/**
	 * List this collaborator's calendars in the administration context.
	 * @param int $ownerId Owner identifier
	 * @return array Profiles ordered by their stable identifiers
	 */
	public function profilesForOwner($ownerId): array
	{
		$this->manager();
		$rows = $this->query('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_booking_profile WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId).' ORDER BY rowid');
		$profiles = array();
		while ($row = $this->db->fetch_object($rows)) $profiles[] = $this->profile($row);
		return $profiles;
	}

	/**
	 * Read one profile within the requested collaborator and native entity.
	 * @param int $ownerId Owner identifier
	 * @param int|null $profileId Specific profile, or null for the first legacy profile
	 * @return object|null Stored profile
	 */
	private function profileRowForOwner($ownerId, $profileId)
	{
		$this->manager();
		if ($profileId !== null && (!is_int($profileId) || $profileId <= 0 || $profileId > 2147483647)) throw new BookingException('CDavBookingInvalid', 422);
		$row = $this->db->fetch_object($this->query('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_booking_profile WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId)
			.($profileId !== null ? ' AND rowid = '.$profileId : '').' ORDER BY rowid LIMIT 1'));
		return $row ?: null;
	}

	/**
	 * Read the profile settings for administration.
	 * @param int $ownerId Owner identifier
	 * @param int|null $profileId Specific profile, or null for the first legacy profile
	 * @return array|null Profile
	 */
	public function profileForOwner($ownerId, $profileId = null)
	{
		$row = $this->profileRowForOwner($ownerId, $profileId);
		return $row ? $this->profile($row) : null;
	}

	/**
	 * Validate native optional business links without publishing private names.
	 * @param int $thirdpartyId Native third-party identifier, or zero
	 * @param int $projectId Native project identifier, or zero
	 * @param \User $owner Native collaborator whose business access is required
	 * @param bool $lock Lock native parent rows in the current mutation transaction
	 * @return void
	 */
	private function validateBusinessLinks(int $thirdpartyId, int $projectId, \User $owner, bool $lock = false): void
	{
		if ($projectId && !$thirdpartyId) throw new BookingException('CDavBookingInvalid', 422);
		if ($thirdpartyId) {
			if (!isModEnabled('societe')) throw new BookingException('CDavBookingInvalid', 422);
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
			if ($lock) {
				$row = $this->db->fetch_object($this->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$thirdpartyId.' AND entity = '.$this->entity.' FOR UPDATE'));
				if (!$row) throw new BookingException('CDavBookingInvalid', 422);
			}
			$thirdparty = new \Societe($this->db);
			if ($thirdparty->fetch($thirdpartyId) <= 0 || (int) $thirdparty->entity !== $this->entity
				|| !checkUserAccessToObject($owner, array('societe'), $thirdparty, 'societe')) throw new BookingException('CDavBookingInvalid', 422);
		}
		if ($projectId) {
			if (!isModEnabled('project')) throw new BookingException('CDavBookingInvalid', 422);
			require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
			if ($lock) {
				$row = $this->db->fetch_object($this->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'projet WHERE rowid = '.$projectId.' AND entity = '.$this->entity.' AND fk_soc = '.$thirdpartyId.' AND fk_statut = '.\Project::STATUS_VALIDATED.' FOR UPDATE'));
				if (!$row) throw new BookingException('CDavBookingProjectNotOpen', 422);
			}
			$project = new \Project($this->db);
			if ($project->fetch($projectId) <= 0 || (int) $project->entity !== $this->entity || (int) $project->socid !== $thirdpartyId
				|| $project->restrictedProjectArea($owner, 'read') <= 0) {
				throw new BookingException('CDavBookingInvalid', 422);
			}
			if ((int) $project->status !== \Project::STATUS_VALIDATED) throw new BookingException('CDavBookingProjectNotOpen', 422);
		}
	}

	/**
	 * An unavailable selected project disappears from native pickers, not from the profile.
	 * @param int $existingProjectId Previously saved project
	 * @param int $replacementProjectId Explicit new choice, or zero
	 * @param bool $confirmed Whether the administrator explicitly confirms detachment
	 * @return void
	 */
	private function assertProjectRemovalConfirmed(int $existingProjectId, int $replacementProjectId, bool $confirmed): void
	{
		if ($existingProjectId <= 0 || $replacementProjectId > 0 || $confirmed) return;
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		// The native fetch API has no locking variant. Read the current status under
		// the same transaction that will save the profile, before accepting its blank.
		$row = $this->db->fetch_object($this->query('SELECT rowid, fk_statut FROM '.MAIN_DB_PREFIX.'projet WHERE rowid = '.$existingProjectId.' AND entity = '.$this->entity.' FOR UPDATE'));
		if (!$row || (int) $row->fk_statut !== \Project::STATUS_VALIDATED) throw new BookingException('CDavBookingProjectClearRequired', 422);
	}

	/**
	 * Save hours and optional native context, preserving every publication key.
	 * @param int $ownerId Owner identifier
	 * @param array $config Settings
	 * @param bool $enabled Profile activation
	 * @param int|null $profileId Null updates the first legacy profile, zero creates, positive targets one profile
	 * @param array $metadata Optional label, native links and explicit clear_project_link confirmation
	 * @return array Profile
	 */
	public function saveProfile($ownerId, array $config, $enabled, $profileId = null, array $metadata = array())
	{
		$this->manager();
		if (array_key_exists('clear_project_link', $metadata) && !is_bool($metadata['clear_project_link'])) throw new BookingException('CDavBookingInvalid', 422);
		$nativeOwner = $this->owner($ownerId);
		if ($profileId !== null && (!is_int($profileId) || $profileId < 0 || $profileId > 2147483647)) throw new BookingException('CDavBookingInvalid', 422);
		$config = BookingAvailability::validateConfig($config);
		$json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		if (!$this->db->begin()) throw new BookingException('CDavBookingUnavailable', 503);
		try {
			// Serialize both old default-profile callers and explicit profile changes.
			$lockedOwner = $this->db->fetch_object($this->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $ownerId)
				.' AND statut = 1 AND fk_soc IS NULL AND entity IN (0, '.$this->entity.') FOR UPDATE'));
			if (!$lockedOwner) throw new BookingException('CDavBookingNotFound', 404);
			$existing = $profileId === 0 ? null : $this->profileForOwner($ownerId, $profileId);
			if ($profileId !== null && $profileId > 0 && !$existing) throw new BookingException('CDavBookingNotFound', 404);
			$label = array_key_exists('label', $metadata) ? $metadata['label'] : ($existing['label'] ?? '');
			if (!is_string($label) || !mb_check_encoding($label, 'UTF-8') || preg_match('/[\x00-\x1f\x7f<>]/u', $label)) {
				throw new BookingException('CDavBookingInvalid', 422);
			}
			$label = trim($label);
			if (mb_strlen($label, 'UTF-8') > 120 || ($profileId === 0 && $label === '')) throw new BookingException('CDavBookingInvalid', 422);
			$links = array();
			foreach (array('thirdparty_id', 'project_id') as $field) {
				$value = array_key_exists($field, $metadata) ? $metadata[$field] : ($existing[$field] ?? 0);
				if (!is_int($value) || $value < 0 || $value > 2147483647) throw new BookingException('CDavBookingInvalid', 422);
				$links[$field] = $value;
			}
			$this->assertProjectRemovalConfirmed((int) ($existing['project_id'] ?? 0), $links['project_id'], $metadata['clear_project_link'] ?? false);
			$this->validateBusinessLinks($links['thirdparty_id'], $links['project_id'], $nativeOwner, true);
			if ($existing) {
				$id = $existing['id'];
				$this->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_profile SET enabled = '.((int) (bool) $enabled).', config = '.$this->quote($json)
					.', label = '.$this->quote($label).', fk_soc = '.($links['thirdparty_id'] ?: 'NULL').', fk_project = '.($links['project_id'] ?: 'NULL')
					.' WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId).' AND rowid = '.$id);
			} else {
				$this->query('INSERT INTO '.MAIN_DB_PREFIX.'cdav_booking_profile (entity, fk_user, enabled, config, label, fk_soc, fk_project) VALUES ('
					.$this->entity.', '.((int) $ownerId).', '.((int) (bool) $enabled).', '.$this->quote($json).', '.$this->quote($label).', '
					.($links['thirdparty_id'] ?: 'NULL').', '.($links['project_id'] ?: 'NULL').')');
				$id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'cdav_booking_profile');
			}
			$result = $this->profileForOwner($ownerId, $id);
			if (!$result || !$this->db->commit()) throw new BookingException('CDavBookingUnavailable', 503);
			return $result;
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Return the current publication key for an administrator of this entity.
	 * @param int $ownerId Owner identifier
	 * @param int|null $profileId Specific profile, or null for the first legacy profile
	 * @return string|null Recovered key, or null for unpublished and hash-only profiles
	 */
	public function publicationKeyForOwner($ownerId, $profileId = null): ?string
	{
		$row = $this->profileRowForOwner($ownerId, $profileId);
		if (!$row || empty($row->token_hash) || empty($row->token_ciphertext)) return null;
		return BookingPublication::open((string) $row->token_ciphertext, $this->entity, (int) $row->fk_user, (int) $row->rowid, (string) $row->token_hash);
	}

	/**
	 * Store a recoverable copy of a known existing publication without changing its address.
	 * @param int $ownerId Owner identifier
	 * @param int|null $profileId Specific profile, or null for the first legacy profile
	 * @param string $key Key supplied by the administrator
	 * @return void
	 */
	public function reattachToken($ownerId, $profileId, string $key): void
	{
		$row = $this->profileRowForOwner($ownerId, $profileId);
		$hash = hash('sha256', $key);
		if (!$row || !preg_match('/^[a-f0-9]{64}$/D', $key) || !hash_equals((string) $row->token_hash, $hash)) {
			throw new BookingException('CDavBookingLinkMismatch', 422);
		}
		$ciphertext = BookingPublication::seal($key, $this->entity, (int) $row->fk_user, (int) $row->rowid, $hash);
		$result = $this->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_profile SET token_ciphertext = '.$this->quote($ciphertext)
			.' WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId).' AND rowid = '.((int) $row->rowid)
			.' AND token_hash = '.$this->quote($hash));
		// A concurrent rotation or revocation invalidates the expected hash.
		if ((int) $this->db->affected_rows($result) !== 1) throw new BookingException('CDavBookingLinkMismatch', 422);
	}

	/**
	 * Publish a fresh random key with its hash and encrypted administration copy.
	 * @param int $ownerId Owner identifier
	 * @param int|null $profileId Specific profile, or null for the legacy first profile
	 * @return string New publication key
	 */
	public function rotateToken($ownerId, $profileId = null)
	{
		$this->manager();
		$this->owner($ownerId);
		$profile = $this->profileForOwner($ownerId, $profileId);
		if (!$profile) throw new BookingException('CDavBookingNotFound', 404);
		$key = bin2hex(random_bytes(32));
		$hash = hash('sha256', $key);
		$ciphertext = BookingPublication::seal($key, $this->entity, $profile['owner_id'], $profile['id'], $hash);
		$result = $this->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_profile SET token_hash = '.$this->quote($hash)
			.', token_ciphertext = '.$this->quote($ciphertext)
			.' WHERE entity = '.$this->entity.' AND fk_user = '.((int) $ownerId).' AND rowid = '.$profile['id']);
		if ((int) $this->db->affected_rows($result) !== 1) throw new BookingException('CDavBookingNotFound', 404);
		return $key;
	}

	/**
	 * Disable the profile and permanently revoke its current key.
	 * @param int $ownerId Owner identifier
	 * @param int|null $profileId Specific profile, or null for the legacy first profile
	 * @return void
	 */
	public function disableProfile($ownerId, $profileId = null)
	{
		$this->manager();
		$profile = $this->profileForOwner($ownerId, $profileId);
		if (!$profile) throw new BookingException('CDavBookingNotFound', 404);
		$this->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_profile SET enabled = 0, token_hash = NULL, token_ciphertext = NULL WHERE entity = '
			.$this->entity.' AND fk_user = '.((int) $ownerId).' AND rowid = '.$profile['id']);
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
		try {
			$this->validateBusinessLinks($profile['thirdparty_id'], $profile['project_id'], $owner, $lock);
		} catch (BookingException $e) {
			if ($e->getCode() !== 422) throw $e;
			throw new BookingException('CDavBookingNotFound', 404);
		}
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
		$settings = $profile['config'];
		// Preserve the exact signature of profiles published before named calendars.
		if (($profile['label'] ?? '') !== '' || !empty($profile['thirdparty_id']) || !empty($profile['project_id'])) {
			$settings['profile_context'] = array((string) ($profile['label'] ?? ''),
				(int) ($profile['thirdparty_id'] ?? 0), (int) ($profile['project_id'] ?? 0));
		}
		return hash_hmac('sha256', 'cdav-booking|'.$profile['id'].'|'.$profile['token_hash'].'|'
			.hash('sha256', json_encode($settings)).'|'.$payload, $this->secret());
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
		$validity = BookingAvailability::validityRange($profile['config']);
		$rangeStart = max($now, $validity['start'] ?? $now);
		$horizon = BookingAvailability::horizonEnd($profile['config'], $now);
		$rangeEnd = min($horizon, $validity['end'] ?? $horizon);
		$hasRange = $rangeStart < $rangeEnd;
		$firstDay = $hasRange ? (new \DateTimeImmutable('@'.$rangeStart))->setTimezone($zone) : $today;
		$lastDay = $hasRange ? (new \DateTimeImmutable('@'.($rangeEnd - 1)))->setTimezone($zone) : $today;
		if ($month === '') $month = $firstDay->format('Y-m');
		if (!preg_match('/^\d{4}-\d{2}$/D', $month)) throw new BookingException('CDavBookingInvalid', 422);
		$first = \DateTimeImmutable::createFromFormat('!Y-m', $month, $zone);
		if (!$first || $first->format('Y-m') !== $month
			|| $month < $firstDay->format('Y-m') || $month > $lastDay->format('Y-m')) {
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
		$from = max($first->getTimestamp(), $rangeStart);
		$until = min($next->getTimestamp(), $rangeEnd);
		$slots = $until > $from ? $this->slots($profile, $from, $until, $now) : array();
		foreach ($slots as $slot) {
			$dayKey = (new \DateTimeImmutable('@'.$slot['start']))->setTimezone($zone)->format('Y-m-d');
			$days[$dayKey]++;
			$byDay[$dayKey][] = $slot;
		}
		if ($date === '') {
			$date = $month === $firstDay->format('Y-m') ? $firstDay->format('Y-m-d') : $first->format('Y-m-d');
			foreach ($days as $dayKey => $count) {
				if ($count > 0) { $date = $dayKey; break; }
			}
		}
		return array('date' => $date, 'slots' => $byDay[$date] ?? array(), 'calendar' => array(
			'month' => $month,
			'previous' => $hasRange && $previous->format('Y-m') >= $firstDay->format('Y-m') ? $previous->format('Y-m') : null,
			'next' => $hasRange && $next->format('Y-m') <= $lastDay->format('Y-m') ? $next->format('Y-m') : null,
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
				|| (int) $event->socid !== (int) ($profile['thirdparty_id'] ?? 0) || (int) $event->fk_project !== (int) ($profile['project_id'] ?? 0)
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
				if (in_array((int) ($previous->mail_customer ?? 0), array(1, 3), true)
					|| in_array((int) ($previous->mail_owner ?? 0), array(1, 3), true)) {
					$result['notification_warning'] = true;
				}
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
					$event->socid = $profile['thirdparty_id'];
					$event->fk_project = $profile['project_id'];
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
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
		if (!$result['replayed']) {
			// SMTP cannot roll back a committed appointment or create a second one.
			try {
				require_once __DIR__.'/CDavBookingNotification.php';
				$notification = (new BookingNotification($this->db, $this->entity))->notify(
					$requestHash, $profile, $clean, $selection, $result['reference'], $owner
				);
				if ($notification['notification_warning']) $result['notification_warning'] = true;
			} catch (\Throwable $e) {
				$result['notification_warning'] = true;
				dol_syslog('CDav booking confirmation failed: '.get_class($e), LOG_ERR);
			}
		}
		return $result;
	}
}
