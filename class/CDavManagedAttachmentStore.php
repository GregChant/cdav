<?php

namespace Dolibarr\CDav;

/** RFC 8607 metadata backed by Dolibarr's native Agenda document directory. */
class ManagedAttachmentStore
{
	/** @var object */
	private $db;
	/** @var object */
	private $user;
	/** @var bool|null */
	private $available = null;

	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
	}

	public function isAvailable()
	{
		if ($this->available === null) {
			$this->available = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_managed_attachment');
		}
		return $this->available;
	}

	public function maxBytes()
	{
		return min(max(1, getDolGlobalInt('CDAV_MANAGED_ATTACHMENT_MAX_MB', 8)), 64) * 1024 * 1024;
	}

	public function maxPerResource()
	{
		return min(max(1, getDolGlobalInt('CDAV_MANAGED_ATTACHMENT_MAX_COUNT', 10)), 50);
	}

	private function entity()
	{
		global $conf;
		return max(1, (int) ($conf->entity ?? 1));
	}

	private function agendaDirectory($eventId)
	{
		global $conf;
		$root = !empty($conf->agenda->multidir_output[$this->entity()])
			? $conf->agenda->multidir_output[$this->entity()]
			: ($conf->agenda->dir_output ?? '');
		if ($root === '') throw new \RuntimeException('Dolibarr Agenda document directory is unavailable');
		return rtrim($root, '/').'/'.dol_sanitizeFileName((string) ((int) $eventId));
	}

	private function normalizeContentType($contentType)
	{
		$contentType = strtolower(trim(explode(';', (string) $contentType, 2)[0]));
		if (!preg_match('@^[!#$%&\'*+.^_`|~0-9a-z-]{1,64}/[!#$%&\'*+.^_`|~0-9a-z-]{1,64}$@', $contentType)) {
			throw new \Sabre\DAV\Exception\BadRequest('A valid Content-Type is required for a managed attachment');
		}
		$blocked = array('text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript',
			'text/javascript', 'application/x-httpd-php', 'application/x-php');
		if (in_array($contentType, $blocked, true)) {
			throw new \Sabre\DAV\Exception\Forbidden('This active content type is not accepted as a managed attachment');
		}
		return $contentType;
	}

	private function normalizeFilename($filename)
	{
		$filename = trim(str_replace(array("\0", "\r", "\n", '/', '\\'), '_', (string) $filename));
		$filename = dol_sanitizeFileName(basename($filename));
		if ($filename === '' || $filename === '.' || $filename === '..') $filename = 'attachment.bin';
		return function_exists('dol_trunc') ? dol_trunc($filename, 180, 'middle', 'UTF-8', 1) : substr($filename, 0, 180);
	}

	/** Validate raw base64 before Sabre's permissive BINARY decoder normalizes it. */
	public function validateInlineCalendarData($calendarData)
	{
		$unfolded = preg_replace('/\r?\n[ \t]/', '', (string) $calendarData);
		$hasInline = false;
		foreach (preg_split('/\r\n|\n|\r/', $unfolded) as $line) {
			if (!preg_match('/^ATTACH[;:]/i', $line)) continue;
			$tokens = array();
			$start = 0;
			$quoted = false;
			$colon = false;
			for ($i = 0, $length = strlen($line); $i < $length; $i++) {
				if ($line[$i] === '"') $quoted = !$quoted;
				if (!$quoted && ($line[$i] === ';' || $line[$i] === ':')) {
					$tokens[] = substr($line, $start, $i - $start);
					$start = $i + 1;
					if ($line[$i] === ':') { $colon = $i; break; }
				}
			}
			if ($colon === false) continue; // The normal iCalendar parser reports malformed properties.
			$params = array();
			foreach (array_slice($tokens, 1) as $token) {
				$pair = explode('=', $token, 2);
				if (count($pair) === 2) $params[strtoupper($pair[0])] = strtoupper(trim($pair[1], '"'));
			}
			if (($params['VALUE'] ?? '') !== 'BINARY' && ($params['ENCODING'] ?? '') !== 'BASE64') continue;
			$hasInline = true;
			if (($params['VALUE'] ?? '') !== 'BINARY' || ($params['ENCODING'] ?? '') !== 'BASE64' || isset($params['MANAGED-ID'])) {
				throw new \Sabre\DAV\Exception\BadRequest('Inline attachments require VALUE=BINARY and ENCODING=BASE64');
			}
			$encoded = substr($line, $colon + 1);
			if ($encoded === '' || strlen($encoded) > 4 * (int) ceil($this->maxBytes() / 3)
				|| strlen($encoded) % 4 !== 0 || preg_match('/[^A-Za-z0-9+\/=]/', $encoded)) {
				throw new \Sabre\DAV\Exception\BadRequest('Inline attachment base64 is invalid or exceeds the configured size');
			}
			$bytes = base64_decode($encoded, true);
			if ($bytes === false || strlen($bytes) > $this->maxBytes() || base64_encode($bytes) !== $encoded) {
				throw new \Sabre\DAV\Exception\BadRequest('Inline attachment base64 is invalid or exceeds the configured size');
			}
		}
		if ($hasInline && !getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
			throw new \Sabre\DAV\Exception\Forbidden('Enable managed attachments in CDav setup to upload appointment files');
		}
		return $hasInline;
	}

	/** Validate an inline RFC 5545 attachment before allocating native files. */
	public function decodeInlineAttachment($attachment)
	{
		$valueType = strtoupper(trim((string) ($attachment['VALUE'] ?? '')));
		$encoding = strtoupper(trim((string) ($attachment['ENCODING'] ?? '')));
		if ($valueType !== 'BINARY' && $encoding !== 'BASE64') return null;
		if ($valueType !== 'BINARY' || $encoding !== 'BASE64' || isset($attachment['MANAGED-ID'])) {
			throw new \Sabre\DAV\Exception\BadRequest('Inline attachments require VALUE=BINARY and ENCODING=BASE64');
		}
		// The raw calendar must pass validateInlineCalendarData() first. Binary
		// values from Sabre are already decoded; filename parameters may contain ':' .
		$bytes = $attachment->getValue();
		if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $this->maxBytes()) {
			throw new \Sabre\DAV\Exception\BadRequest('Inline attachment is empty or exceeds the configured size');
		}
		$contentType = $this->normalizeContentType((string) ($attachment['FMTTYPE'] ?? 'application/octet-stream'));
		$filename = $this->normalizeFilename((string) ($attachment['FILENAME'] ?? 'attachment.bin'));
		return array('bytes' => $bytes, 'content_type' => $contentType, 'filename' => $filename);
	}

	/** Materialize inline bytes, reusing identical native documents on replayed PUTs. */
	public function importInlineCalendarData($eventId, $calendarData, array &$createdRows)
	{
		if (!$this->validateInlineCalendarData($calendarData)) return (string) $calendarData;
		if (!$this->isAvailable()) throw new \Sabre\DAV\Exception\Conflict('Re-enable CDav to install managed attachment metadata before uploading files');
		$calendar = \Sabre\VObject\Reader::read((string) $calendarData);
		$preservedIds = array();
		$replacementIds = array();
		foreach ($calendar->getComponents() as $component) {
			if (!in_array($component->name, array('VEVENT', 'VTODO'), true)) continue;
			foreach ($component->select('ATTACH') as $attachment) {
				if (isset($attachment['MANAGED-ID'])) $preservedIds[(string) $attachment['MANAGED-ID']] = true;
			}
		}
		foreach ($calendar->getComponents() as $component) {
			if (!in_array($component->name, array('VEVENT', 'VTODO'), true)) continue;
			foreach ($component->select('ATTACH') as $attachment) {
				$inline = $this->decodeInlineAttachment($attachment);
				if ($inline === null) continue;
				if (!getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
					throw new \Sabre\DAV\Exception\Forbidden('Enable managed attachments in CDav setup to upload appointment files');
				}
				$hash = hash('sha256', $inline['bytes']);
				$result = $this->db->query('SELECT * FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment'
					.' WHERE entity = '.$this->entity().' AND fk_actioncomm = '.((int) $eventId)
					.' AND fk_user = '.((int) $this->user->id));
				if (!$result) throw new \RuntimeException('Unable to inspect existing inline attachments');
				$row = null;
				$replacingId = '';
				while ($candidate = $this->db->fetch_object($result)) {
					$sameName = $this->originalFilename($candidate) === $inline['filename'];
					if ($sameName && !isset($preservedIds[$candidate->managed_id]) && !isset($replacementIds[$candidate->managed_id])) {
						$replacingId = (string) $candidate->managed_id;
					}
					if ($sameName && (string) $candidate->file_hash === $hash
						&& (string) $candidate->content_type === $inline['content_type']
						&& is_file($this->filePath($candidate))
						&& hash_equals($hash, (string) hash_file('sha256', $this->filePath($candidate)))) {
						$row = $candidate;
						break;
					}
				}
				if ($row === null) {
					$row = $this->create((int) $eventId, $inline['bytes'], $inline['content_type'], $inline['filename'], $replacingId);
					if ($replacingId !== '') $replacementIds[$replacingId] = true;
					$createdRows[] = $row;
				}
				$preservedIds[(string) $row->managed_id] = true;
				$component->remove($attachment);
				$component->add('ATTACH', $this->attachmentUrl($row->managed_id), array(
					'VALUE' => 'URI', 'MANAGED-ID' => (string) $row->managed_id,
					'FILENAME' => $this->originalFilename($row), 'FMTTYPE' => (string) $row->content_type,
					'SIZE' => (string) $row->file_size,
				));
			}
		}
		return $calendar->serialize();
	}

	/** Filesystem cleanup for an enclosing native transaction that rolled back. */
	public function discardCreatedFiles(array $rows)
	{
		foreach ($rows as $row) {
			$path = $this->filePath($row);
			if (is_file($path)) dol_delete_file($path, 1, 1, 1, null, false, 1, 1);
		}
	}

	/** Store bytes through dol_move(), including native filename and antivirus checks. */
	public function create($eventId, $content, $contentType, $filename, $replacingManagedId = '')
	{
		if (!$this->isAvailable()) throw new \RuntimeException('Managed attachment table is unavailable');
		$eventId = (int) $eventId;
		$contentType = $this->normalizeContentType($contentType);
		$filename = $this->normalizeFilename($filename);
		$tmp = '';
		$destination = '';
		if (!$this->db->begin()) throw new \RuntimeException('Unable to start the managed attachment transaction');
		try {
		$userLock = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $this->user->id).' FOR UPDATE');
		$eventLock = $this->db->query('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId
			.' AND entity = '.$this->entity().' FOR UPDATE');
		if (!$userLock || !$this->db->fetch_object($userLock) || !$eventLock || !$this->db->fetch_object($eventLock)) {
			throw new \Sabre\DAV\Exception\NotFound('Managed attachment owner or event no longer exists');
		}
		$countSql = 'SELECT COUNT(*) AS count_items FROM '
			.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE entity = '.$this->entity().' AND fk_actioncomm = '.$eventId;
		$countResult = $this->db->query($countSql);
		$countRow = $countResult ? $this->db->fetch_object($countResult) : null;
		if (!$countRow) throw new \RuntimeException('Unable to inspect managed attachment quota');
		$replaceSize = 0;
		if ($replacingManagedId !== '') {
			$old = $this->getById($replacingManagedId, $eventId, true);
			$replaceSize = (int) $old->file_size;
		} elseif ((int) $countRow->count_items >= $this->maxPerResource()) {
			throw new \Sabre\DAV\Exception\Conflict('Maximum managed attachments per calendar resource exceeded');
		}

		$directory = $this->agendaDirectory($eventId);
		$tmpDirectory = dirname($directory).'/.cdav-upload';
		if (is_link($directory) || is_link($tmpDirectory)) {
			throw new \Sabre\DAV\Exception\Forbidden('Agenda upload directories must not be symbolic links');
		}
		if (dol_mkdir($tmpDirectory) < 0 || dol_mkdir($directory) < 0) {
			throw new \RuntimeException('Unable to create the native Agenda document directory');
		}
		$tmp = tempnam($tmpDirectory, 'dav-');
		if ($tmp === false) throw new \RuntimeException('Unable to allocate a managed attachment upload');
		$output = fopen($tmp, 'wb');
		if (!$output) {
			dol_delete_file($tmp, 1, 1, 1, null, false, 0, 1);
			throw new \RuntimeException('Unable to open the managed attachment upload');
		}
		$input = is_resource($content) ? $content : fopen('php://temp', 'w+b');
		if (!is_resource($content)) {
			fwrite($input, (string) $content);
			rewind($input);
		}
		$copied = stream_copy_to_stream($input, $output, $this->maxBytes() + 1);
		fclose($output);
		if (!is_resource($content)) fclose($input);
		if ($copied === false || $copied <= 0 || $copied > $this->maxBytes()) {
			dol_delete_file($tmp, 1, 1, 1, null, false, 0, 1);
			throw new \Sabre\DAV\Exception\BadRequest('Managed attachment is empty or exceeds the configured size');
		}
		$quotaResult = $this->db->query('SELECT COALESCE(SUM(file_size),0) AS total_size FROM '
			.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE entity = '.$this->entity().' AND fk_user = '.((int) $this->user->id));
		$quotaRow = $quotaResult ? $this->db->fetch_object($quotaResult) : null;
		if (!$quotaRow) {
			dol_delete_file($tmp, 1, 1, 1, null, false, 0, 1);
			throw new \RuntimeException('Unable to inspect managed attachment owner quota');
		}
		$quota = min(max(1, getDolGlobalInt('CDAV_MANAGED_ATTACHMENT_QUOTA_MB', 256)), 4096) * 1024 * 1024;
		if ((int) $quotaRow->total_size - $replaceSize + $copied > $quota) {
			dol_delete_file($tmp, 1, 1, 1, null, false, 0, 1);
			throw new \Sabre\DAV\Exception\InsufficientStorage('Managed attachment quota exceeded');
		}

		$managedId = bin2hex(random_bytes(32));
		$storedFilename = $managedId.'-'.$filename;
		$destination = $directory.'/'.$storedFilename;
		if (!dol_move($tmp, $destination, '0', 0, 1, 1, array('gen_or_uploaded' => 'uploaded'), $this->entity())) {
			dol_delete_file($tmp, 1, 1, 1, null, false, 0, 1);
			throw new \Sabre\DAV\Exception\Forbidden('Dolibarr rejected the managed attachment');
		}
		$fileHash = hash_file('sha256', $destination);
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_managed_attachment'
			.' (managed_id, entity, fk_actioncomm, fk_user, filename, content_type, file_size, file_hash, datec) VALUES ('
			."'".$managedId."', ".$this->entity().', '.$eventId.', '.((int) $this->user->id).", '"
			.$this->db->escape($storedFilename)."', '".$this->db->escape($contentType)."', ".((int) $copied).", '"
			.$fileHash."', '".$this->db->idate(dol_now())."')";
		if (!$this->db->query($sql)) {
			dol_delete_file($destination, 1, 1, 1, null, false, 1, 1);
			throw new \RuntimeException('Unable to save managed attachment metadata');
		}
		$row = $this->getById($managedId, $eventId, true);
		if (!$this->db->commit()) throw new \RuntimeException('Unable to commit the managed attachment transaction');
		return $row;
		} catch (\Throwable $e) {
			$this->db->rollback();
			if ($tmp !== '' && is_file($tmp)) dol_delete_file($tmp, 1, 1, 1, null, false, 0, 1);
			if ($destination !== '' && is_file($destination)) dol_delete_file($destination, 1, 1, 1, null, false, 1, 1);
			throw $e;
		}
	}

	public function getById($managedId, $eventId = 0, $mustOwn = false)
	{
		if (!preg_match('/^[a-f0-9]{64}$/', (string) $managedId) || !$this->isAvailable()) {
			throw new \Sabre\DAV\Exception\NotFound('Managed attachment not found');
		}
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE managed_id = \''
			.$this->db->escape((string) $managedId).'\' AND entity = '.$this->entity();
		if ($eventId > 0) $sql .= ' AND fk_actioncomm = '.((int) $eventId);
		if ($mustOwn) $sql .= ' AND fk_user = '.((int) $this->user->id);
		$result = $this->db->query($sql);
		$row = $result ? $this->db->fetch_object($result) : null;
		if (!$row) throw new \Sabre\DAV\Exception\NotFound('Managed attachment not found');
		return $row;
	}

	public function attachmentUrl($managedId)
	{
		return dol_buildpath('/cdav/server.php/attachments/'.rawurlencode((string) $managedId), 3);
	}

	public function originalFilename($row)
	{
		return preg_replace('/^[a-f0-9]{64}-/', '', (string) $row->filename);
	}

	public function filePath($row)
	{
		$filename = basename((string) $row->filename);
		if ($filename !== (string) $row->filename) throw new \RuntimeException('Invalid managed attachment filename');
		return $this->agendaDirectory((int) $row->fk_actioncomm).'/'.$filename;
	}

	public function remove($managedId, $eventId = 0, $mustOwn = true, $deleteBytes = true)
	{
		$row = $this->getById($managedId, $eventId, $mustOwn);
		$path = $this->filePath($row);
		if ($deleteBytes && is_file($path) && !dol_delete_file($path, 1, 1, 1, null, false, 1, 1)) {
			throw new \RuntimeException('Unable to delete the native Agenda attachment');
		}
		if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE managed_id = \''
			.$this->db->escape((string) $managedId).'\' AND entity = '.$this->entity())) {
			throw new \RuntimeException('Unable to delete managed attachment metadata');
		}
	}

	/** Validate every MANAGED-ID and remove bytes no longer referenced after PUT. */
	public function synchronizeCalendarData($eventId, $calendarData, $removeMissing = true, $deferFileRemoval = false)
	{
		$calendar = \Sabre\VObject\Reader::read((string) $calendarData);
		$referenced = array();
		foreach ($calendar->getComponents() as $component) {
			if (in_array($component->name, array('VEVENT', 'VTODO'), true)) {
				foreach ($component->select('ATTACH') as $attachment) {
					$id = isset($attachment['MANAGED-ID']) ? trim((string) $attachment['MANAGED-ID']) : '';
					if ($id === '') continue;
					// An authorized editor may preserve another uploader's references;
					// removal and byte replacement still require upload ownership.
					$this->getById($id, (int) $eventId, false);
					if (!hash_equals($this->attachmentUrl($id), trim((string) $attachment))) {
						throw new \Sabre\DAV\Exception\Conflict('Managed attachment URL does not match its MANAGED-ID');
					}
					$referenced[$id] = true;
				}
			}
		}
		if (!$removeMissing || !$this->isAvailable()) return array();
		$result = $this->db->query('SELECT managed_id FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment'
			.' WHERE entity = '.$this->entity().' AND fk_actioncomm = '.((int) $eventId).' AND fk_user = '.((int) $this->user->id));
		if (!$result) throw new \RuntimeException('Unable to inspect managed attachment references');
		$remove = array();
		while ($row = $this->db->fetch_object($result)) if (!isset($referenced[$row->managed_id])) $remove[] = (string) $row->managed_id;
		$deferredRows = array();
		foreach ($remove as $id) {
			if ($deferFileRemoval) $deferredRows[] = $this->getById($id, (int) $eventId, true);
			$this->remove($id, (int) $eventId, true, !$deferFileRemoval);
		}
		return $deferredRows;
	}

	public function removeAllForEvent($eventId)
	{
		if (!$this->isAvailable()) return;
		$result = $this->db->query('SELECT managed_id FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment'
			.' WHERE entity = '.$this->entity().' AND fk_actioncomm = '.((int) $eventId));
		if (!$result) throw new \RuntimeException('Unable to inspect event managed attachments');
		$ids = array();
		while ($row = $this->db->fetch_object($result)) $ids[] = (string) $row->managed_id;
		foreach ($ids as $id) $this->remove($id, (int) $eventId, false);
	}

	public function readable($managedId)
	{
		$row = $this->getById($managedId);
		if (!$this->user->hasRight('agenda', 'myactions', 'read')) throw new \Sabre\DAV\Exception\Forbidden('Agenda read permission required');
		$canReadAll = $this->user->hasRight('agenda', 'allactions', 'read');
		$sql = 'SELECT u.login FROM '.MAIN_DB_PREFIX.'actioncomm a'
			.' INNER JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = '.((int) $row->fk_user)
			.' WHERE a.id = '.((int) $row->fk_actioncomm).' AND a.entity IN ('.getEntity('agenda').')';
		if (!$canReadAll) {
			$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar WHERE ar.fk_actioncomm = a.id'
				." AND ar.element_type = 'user' AND ar.fk_element = ".((int) $this->user->id).')';
		}
		$result = $this->db->query($sql);
		$event = $result ? $this->db->fetch_object($result) : null;
		if (!$event) throw new \Sabre\DAV\Exception\Forbidden('Managed attachment is not visible in this agenda');
		$row->owner_principal = 'principals/'.((string) ($event->login ?: $this->user->login));
		$row->reader_principal = 'principals/'.(string) $this->user->login;
		$path = $this->filePath($row);
		if (!is_file($path) || is_link($path) || is_link(dirname($path))) throw new \Sabre\DAV\Exception\Gone('Managed attachment data is no longer available');
		$row->file_size = filesize($path);
		$row->file_hash = hash_file('sha256', $path);
		$row->tms = gmdate('Y-m-d H:i:s', filemtime($path));
		return $row;
	}
}

/** Non-enumerable attachment collection; IDs are unguessable 256-bit values. */
class ManagedAttachmentCollection extends \Sabre\DAV\Collection implements \Sabre\DAVACL\IACL
{
	use \Sabre\DAVACL\ACLTrait;
	private $store;
	private $owner;
	public function __construct($store, $user) { $this->store = $store; $this->owner = 'principals/'.(string) $user->login; }
	public function getName() { return 'attachments'; }
	public function getChildren() { return array(); }
	public function getChild($name) { return new ManagedAttachmentFile($this->store, $this->store->readable($name)); }
	public function childExists($name) { try { $this->store->readable($name); return true; } catch (\Throwable $e) { return false; } }
	public function getOwner() { return $this->owner; }
}

/** Read-only RFC 8607 bytes: direct PUT and DELETE remain forbidden. */
class ManagedAttachmentFile extends \Sabre\DAV\File implements \Sabre\DAVACL\IACL
{
	use \Sabre\DAVACL\ACLTrait;
	private $store;
	private $row;
	public function __construct($store, $row) { $this->store = $store; $this->row = $row; }
	public function getName() { return (string) $this->row->managed_id; }
	public function get() { return fopen($this->store->filePath($this->row), 'rb'); }
	public function getSize() { return (int) $this->row->file_size; }
	public function getETag() { return '"'.(string) $this->row->file_hash.'"'; }
	public function getContentType() { return (string) $this->row->content_type; }
	public function getLastModified() { return strtotime((string) $this->row->tms); }
	public function getOwner() { return (string) $this->row->owner_principal; }
	public function getACL() { return array(array('privilege' => '{DAV:}read', 'principal' => (string) $this->row->reader_principal, 'protected' => true)); }
	public function getContentDisposition() {
		$filename = $this->store->originalFilename($this->row);
		$fallback = preg_replace('/[^ -~]/', '_', $filename);
		return 'attachment; filename="'.addcslashes($fallback, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename);
	}
}
