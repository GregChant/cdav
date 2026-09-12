<?php

namespace Sabre\CardDAV\Backend;

use Sabre\VObject;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

/**
 * Dolibarr CardDAV backend
 *
 * This CardDAV backend uses Dolibarr to store addressbooks
 *
 * @copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/).
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Dolibarr extends AbstractBackend implements SyncSupport {
	/** Change this value whenever the generated CardDAV representation changes. */
	private const CARD_SERIALIZATION_VERSION = '2026-09-native-sync-v3';
	private const MAX_CARD_URI_LENGTH = 1024;
	private const MAX_CARD_UID_LENGTH = 1024;
	private const MAX_VCARD_PHOTO_BYTES = 8 * 1024 * 1024;
	private const MAX_VCARD_PHOTO_DIMENSION = 8192;
	private const MAX_VCARD_PHOTO_PIXELS = 32000000;

	/**
	 * Dolibarr user object
	 *
	 * @var string
	 */
	public $user;

	/**
	 * DB connection
	 *
	 * @var db
	 */
	protected $db;

	/**
	 * Lang translation
	 *
	 * @var langs
	 */
	protected $langs;

	/** @var array<string,string>|null Localized civility/title lookup. */
	private $civilityCodeByLabel = null;

	/** @var bool|null Whether the lossless CardDAV identity table is installed. */
	private $hasCardMappingTable = null;

	/** @var \Dolibarr\CDav\SyncStore */
	private $syncStore;

	/** @var \Dolibarr\CDav\VCardStore */
	private $vcardStore;

	/** @var int Active Dolibarr entity selected by main.inc.php. */
	private $entity;

	/** @var bool Protocol rows orphaned by native deletion were checked. */
	private $metadataPruned = false;

	/** @var array<string,string> Conditional ETags consumed inside the native lock. */
	private $expectedCardEtags = array();

	/**
	 * Sets up the object
	 *
	 * @param user
	 * @param db
	 * @param langs
	 */
	function __construct($user, $db, $langs) {
		global $conf;

		$this->user = $user;
		$this->db = $db;
		$this->langs = $langs;
		$this->entity = max(1, (int) ($conf->entity ?? 1));
		$this->syncStore = new \Dolibarr\CDav\SyncStore($db, $this->entity);
		$this->vcardStore = new \Dolibarr\CDav\VCardStore($db, $this->entity);
		if (!empty($this->user->lang)) {
			// The bootstrap language may already be loaded before HTTP Basic auth.
			// A dedicated translator guarantees the authenticated user's language.
			$this->langs = new \Translate('', $conf);
			$this->langs->setDefaultLang($this->user->lang);
		}
		$this->langs->load("companies");
		$this->langs->load("suppliers");
		$this->langs->load("dict");
		$this->langs->load("cdav@cdav");
	}

	/** Check a permission through Dolibarr's native User API. */
	private function _hasRight($module, $level1, $level2 = '')
	{
		if (!is_object($this->user) || !method_exists($this->user, 'hasRight')) {
			return false;
		}
		return (bool) $this->user->hasRight($module, $level1, $level2);
	}

	/** Normalize nullable SQL fields before passing them to PHP string APIs. */
	private function _normalizeDatabaseRow($row)
	{
		foreach (get_object_vars($row) as $key => $value) {
			if ($value === null) {
				$row->{$key} = '';
			}
		}
		return $row;
	}

	/** Return the native Dolibarr translation of a civility dictionary code. */
	private function _getLocalizedCivility($code, $preferShort = true)
	{
		$code = trim((string) $code);
		if ($code === '') {
			return '';
		}
		if ($preferShort) {
			$key = 'Civility'.$code.'Short';
			// Check the loaded dictionary explicitly: Translate's generic fallback
			// may turn a missing CivilityDRShort key into the bogus "DRShort".
			if (isset($this->langs->tab_translate[$key])) {
				return $this->langs->transnoentitiesnoconv($key);
			}
		}
		$label = $this->langs->getLabelFromKey($this->db, 'Civility'.$code, 'c_civility', 'code', 'label', $code);
		return is_string($label) && $label !== 'Civility'.$code ? $label : '';
	}

	/** Map a translated vCard honorific back to its Dolibarr dictionary code. */
	private function _getCivilityCode($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if ($this->civilityCodeByLabel === null) {
			$this->civilityCodeByLabel = array();
			$result = $this->db->query('SELECT code, label FROM '.MAIN_DB_PREFIX.'c_civility WHERE active = 1');
			while ($result && ($row = $this->db->fetch_object($result))) {
				$code = (string) $row->code;
				$labels = array($code, (string) $row->label, $this->_getLocalizedCivility($code, false), $this->_getLocalizedCivility($code, true));
				foreach ($labels as $label) {
					$label = trim($label);
					if ($label !== '') {
						$this->civilityCodeByLabel[mb_strtolower($label, 'UTF-8')] = $code;
					}
				}
			}
		}
		$key = mb_strtolower($value, 'UTF-8');
		return $this->civilityCodeByLabel[$key] ?? $value;
	}

	private function _getVCardLanguageParameter()
	{
		$language = str_replace('_', '-', (string) $this->langs->getDefaultLang());
		return preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) ? ';LANGUAGE='.$language : '';
	}

	/** Convert Dolibarr rich text to readable plain text. */
	private function _cleanDolibarrText($value)
	{
		$value = (string) $value;
		if ($value === '') {
			return '';
		}
		$value = preg_replace('/<\s*li\b[^>]*>/i', '- ', $value);
		$value = preg_replace('/<\s*\/\s*(?:p|div|li|ul|ol|h[1-6]|blockquote|tr)\s*>/i', "\n", $value);
		$value = \dol_string_nohtmltag($value, 0, 'UTF-8');
		$value = str_replace("\xC2\xA0", ' ', $value);
		$value = str_replace(array("\r\n", "\r"), "\n", $value);
		$value = preg_replace('/[ \t]+\n/', "\n", $value);
		$value = preg_replace('/\n{3,}/', "\n\n", $value);
		return trim($value);
	}

	/** RFC 6350 text escaping for NOTE values. */
	private function _escapeVCardText($value)
	{
		return strtr((string) $value, array(
			'\\' => '\\\\',
			';' => '\\;',
			',' => '\\,',
			"\r\n" => '\\n',
			"\r" => '\\n',
			"\n" => '\\n',
		));
	}

	/** Normalize escaping, CRLF and RFC line folding with Sabre/VObject. */
	private function _normalizeVCardData($cardData)
	{
		try {
			return VObject\Reader::read($cardData)->serialize();
		} catch (\Throwable $e) {
			\dol_syslog(__METHOD__.': invalid generated vCard: '.$e->getMessage(), LOG_ERR);
			return $cardData;
		}
	}

	/** Parse and normalize one vCard, returning a client error for malformed PUTs. */
	private function _readVCard($cardData)
	{
		$maxBytes = (function_exists('getDolGlobalInt')
			? min(max(1, \getDolGlobalInt('CDAV_MAX_REQUEST_MB', 16)), 64)
			: 16) * 1024 * 1024;
		if (strlen((string) $cardData) > $maxBytes) {
			throw new \Sabre\DAV\Exception\BadRequest('The vCard is too large');
		}
		try {
			$vCard = VObject\Reader::read((string) $cardData);
			if ($vCard->name !== 'VCARD') {
				throw new \InvalidArgumentException('The payload is not a vCard');
			}
			$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
			// convert() returns a new document; it does not mutate the vCard in place.
			// Normalizing to 3.0 also decodes RFC 6350 data: PHOTO values safely.
			$vCard = $vCard->convert(VObject\Document::VCARD30);
			return $vCard;
		} catch (\Sabre\DAV\Exception\BadRequest $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new \Sabre\DAV\Exception\BadRequest('Invalid vCard data: '.$e->getMessage());
		}
	}

	/** Decode a bounded raster photo without allowing a compressed image bomb. */
	private function _decodeVCardPhoto($binary)
	{
		if ($binary === false || $binary === '') {
			return false;
		}
		if (!is_string($binary) || strlen($binary) > self::MAX_VCARD_PHOTO_BYTES) {
			throw new \Sabre\DAV\Exception\BadRequest('The vCard photo is too large');
		}
		$info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($binary) : false;
		$width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
		$height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
		if (
			$width <= 0 || $height <= 0
			|| $width > self::MAX_VCARD_PHOTO_DIMENSION
			|| $height > self::MAX_VCARD_PHOTO_DIMENSION
			|| $width > intdiv(self::MAX_VCARD_PHOTO_PIXELS, $height)
		) {
			throw new \Sabre\DAV\Exception\BadRequest('The vCard photo dimensions are invalid or unsafe');
		}
		if (!function_exists('imagecreatefromstring')) {
			throw new \Sabre\DAV\Exception\BadRequest('Photo import is unavailable on this server');
		}
		$image = @imagecreatefromstring($binary);
		if ($image === false) {
			throw new \Sabre\DAV\Exception\BadRequest('The vCard photo is invalid or unsupported');
		}
		return $image;
	}

	/** Save an imported photo with Dolibarr's document directory and thumbnails. */
	private function _saveVCardPhoto($image, $directory, $filename, $object)
	{
		if ($image === false) {
			return;
		}
		try {
			if (\dol_mkdir($directory) < 0 || !@imagejpeg($image, $directory.'/'.$filename)) {
				throw new \Sabre\DAV\Exception('Unable to save the Dolibarr photo');
			}
			$object->addThumbs($directory.'/'.$filename);
		} finally {
			if (is_resource($image) || $image instanceof \GdImage) {
				imagedestroy($image);
			}
		}
	}

	/** Export a bounded, verified raster file as a folded vCard PHOTO property. */
	private function _photoToVCard($path)
	{
		if (!is_file($path)) {
			return '';
		}
		$size = @filesize($path);
		if ($size === false || $size <= 0 || $size > self::MAX_VCARD_PHOTO_BYTES) {
			return '';
		}
		$info = @getimagesize($path);
		$types = array(
			'image/jpeg' => 'JPEG',
			'image/png' => 'PNG',
			'image/gif' => 'GIF',
			'image/bmp' => 'BMP',
			'image/x-ms-bmp' => 'BMP',
			'image/tiff' => 'TIFF',
		);
		$type = is_array($info) ? ($types[strtolower((string) ($info['mime'] ?? ''))] ?? '') : '';
		if ($type === '') {
			return '';
		}
		$binary = @file_get_contents($path);
		if (!is_string($binary) || strlen($binary) !== $size) {
			return '';
		}
		$property = wordwrap('PHOTO;ENCODING=b;TYPE='.$type.':'.base64_encode($binary), 72, "\n", true);
		return trim(str_replace("\n", "\n ", $property))."\n";
	}

	/** Return the entity-aware native Dolibarr output directory. */
	private function _outputDirectory($module)
	{
		global $conf;
		$config = $conf->{$module} ?? null;
		if (!is_object($config)) {
			return '';
		}
		if (!empty($config->multidir_output[$this->entity])) {
			return rtrim((string) $config->multidir_output[$this->entity], '/');
		}
		return rtrim((string) ($config->dir_output ?? ''), '/');
	}

	/** Build a contact/member photo directory in Dolibarr's native document tree. */
	private function _photoDirectory($objectType, $objectId)
	{
		$module = $objectType === 'member' ? 'adherent' : 'societe';
		$segment = $objectType === 'member' ? 'member' : 'contact';
		$root = $this->_outputDirectory($module);
		return $root === '' ? '' : $root.'/'.$segment.'/'.((int) $objectId).'/photos';
	}

	/** Build a sanitized native photo path. */
	private function _photoFilePath($objectType, $objectId, $filename)
	{
		$directory = $this->_photoDirectory($objectType, $objectId);
		$filename = \dol_sanitizeFileName(basename((string) $filename));
		return $directory === '' || $filename === '' ? '' : $directory.'/'.$filename;
	}

	/** Remove a replaced native photo and its Dolibarr-generated thumbnails. */
	private function _removeVCardPhoto($path, $object)
	{
		if ($path === '') {
			return;
		}
		try {
			$object->delThumbs($path);
			if (is_file($path) && !\dol_delete_file($path)) {
				throw new \RuntimeException('Dolibarr rejected photo deletion');
			}
		} catch (\Throwable $e) {
			\dol_syslog(__METHOD__.': unable to remove obsolete photo: '.$e->getMessage(), LOG_ERR);
		}
	}

	/**
	 * Returns the list of addressbooks for a specific user.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getAddressBooksForUser($principalUri) {
		debug_log("getAddressBooksForUser( $principalUri )");
		if (!hash_equals('principals/'.(string) $this->user->login, trim((string) $principalUri, '/'))) {
			return array();
		}
		$companyName = getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr');

		$addressBooks = [];

		if ($this->_hasRight('societe', 'contact', 'read')) {
			$addressBooks[] = [
				'id'														  => $this->user->id,
				'uri'														  => 'default',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => $this->langs->transnoentitiesnoconv('CDavContactsAddressBookName', $companyName),
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->langs->transnoentitiesnoconv('CDavContactsAddressBookDescription', $companyName, $this->user->login),
				'{http://calendarserver.org/ns/}getctag'					  => $this->_getAddressBookCollectionTag('contact'),
			];
			$this->_addAddressBookSyncToken($addressBooks[count($addressBooks) - 1]);
		}

		if (CDAV_THIRD_SYNC > 0 && $this->_hasRight('societe', 'read'))
		{
			$addressBooks[] = [
				'id'														  => $this->user->id + CDAV_ADDRESSBOOK_ID_SHIFT,
				'uri'														  => 'thirdparties',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => $this->langs->transnoentitiesnoconv('CDavThirdPartiesAddressBookName', $companyName),
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->langs->transnoentitiesnoconv('CDavThirdPartiesAddressBookDescription', $companyName, $this->user->login),
				'{http://calendarserver.org/ns/}getctag'					  => $this->_getAddressBookCollectionTag('thirdparty'),
			];
			$this->_addAddressBookSyncToken($addressBooks[count($addressBooks) - 1]);
		}

		if (CDAV_MEMBER_SYNC > 0 && $this->_hasRight('adherent', 'read'))
		{
			$addressBooks[] = [
				'id'														  => $this->user->id + 2*CDAV_ADDRESSBOOK_ID_SHIFT,
				'uri'														  => 'members',
				'principaluri'												  => $principalUri,
				'{DAV:}displayname'											  => $this->langs->transnoentitiesnoconv('CDavMembersAddressBookName', $companyName),
				'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->langs->transnoentitiesnoconv('CDavMembersAddressBookDescription', $companyName, $this->user->login),
				'{http://calendarserver.org/ns/}getctag'					  => $this->_getAddressBookCollectionTag('member'),
			];
			$this->_addAddressBookSyncToken($addressBooks[count($addressBooks) - 1]);
		}

		return $addressBooks;

	}

	/** Add a sync token only when all RFC 6578 technical tables are present. */
	private function _addAddressBookSyncToken(array &$addressBook)
	{
		$objects = array();
		foreach ($this->getCards((int) $addressBook['id']) as $card) {
			$objects[(string) $card['uri']] = (string) ($card['etag'] ?? '');
		}
		$token = $this->syncStore->synchronize('card', (int) $addressBook['id'], $objects);
		if ($token !== null) {
			$addressBook['{DAV:}sync-token'] = $token;
		}
	}

	/**
	 * A collection tag must also change when a card is removed or when its
	 * categories change. MAX(tms) alone misses both cases.
	 */
	private function _getAddressBookCollectionTag($type)
	{
		if ($type === 'contact') {
			$sql = $this->_getSqlContacts();
		} elseif ($type === 'thirdparty') {
			$sql = $this->_getSqlThirdparties();
		} else {
			$sql = $this->_getSqlMembers();
		}
		$result = $this->db->query($sql);
		$tokens = array();
		if ($result) {
			while ($row = $this->db->fetch_object($result)) {
				$tokens[] = ((int) $row->rowid).':'.((string) $row->lastupd).':'.((string) $row->category_ids)
					.':'.((string) $row->ref_ext).':'.((string) $row->cdav_uuidext).':'.((string) $row->cdav_sourceuid);
			}
		}
		sort($tokens, SORT_STRING);
		return sha1(CDAV_URI_KEY.'|'.self::CARD_SERIALIZATION_VERSION.'|'.$type.'|'.implode('|', $tokens));
	}

	private function _getCardObjectUri($obj, $type)
	{
		if (!empty($obj->cdav_uuidext)) {
			return (string) $obj->cdav_uuidext;
		}
		$external = $this->_decodeCardExternalRef((string) ($obj->ref_ext ?? ''));
		if ($external !== null) {
			return $external['uri'];
		}
		return ((int) $obj->rowid).'-'.$type.'-'.CDAV_URI_KEY;
	}

	private function _getCardSourceUid($obj, $type)
	{
		if (!empty($obj->cdav_sourceuid)) {
			return (string) $obj->cdav_sourceuid;
		}
		$external = $this->_decodeCardExternalRef((string) ($obj->ref_ext ?? ''));
		if ($external !== null && $external['uid'] !== '') {
			return $external['uid'];
		}
		return ((int) $obj->rowid).'-'.$type.'-'.CDAV_URI_KEY;
	}

	private function _decodeCardExternalRef($refExt)
	{
		if (str_starts_with($refExt, 'cdav2:')) {
			$parts = explode('.', substr($refExt, 6), 2);
			if (count($parts) === 2) {
				$uri = base64_decode(strtr($parts[0], '-_', '+/'), true);
				$uid = base64_decode(strtr($parts[1], '-_', '+/'), true);
				if ($uri !== false && $uid !== false) {
					return array('uri' => $uri, 'uid' => $uid);
				}
			}
		}
		if (str_starts_with($refExt, 'cdav:')) {
			return array('uri' => substr($refExt, 5), 'uid' => '');
		}
		return null;
	}

	private function _encodeCardExternalRef($cardUri, $sourceUid, $maxLength)
	{
		$encode = static fn($value) => rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
		$ref = 'cdav2:'.$encode($cardUri).'.'.$encode($sourceUid);
		if (strlen($ref) <= $maxLength) {
			return $ref;
		}
		// Keep the URI stable even if a non-standard UID is too large for ref_ext.
		return substr('cdav:'.(string) $cardUri, 0, $maxLength);
	}

	private function _getCardSqlWhere($alias, $type, $cardUri)
	{
		$pattern = '/^(\d+)-'.preg_quote($type, '/').'-'.preg_quote(CDAV_URI_KEY, '/').'(?:\.vcf)?$/';
		if (preg_match($pattern, (string) $cardUri, $matches)) {
			return ' AND '.$alias.'.rowid = '.((int) $matches[1]);
		}
		$encodedUri = rtrim(strtr(base64_encode((string) $cardUri), '+/', '-_'), '=');
		$conditions = array(
			$alias.'.ref_ext = \'cdav:'.$this->db->escape((string) $cardUri).'\'',
			$alias.'.ref_ext LIKE \'cdav2:'.$this->db->escape($encodedUri).'.%\'',
		);
		if ($this->_cardMappingTableAvailable()) {
			array_unshift($conditions, 'cm.entity = '.$this->entity.' AND cm.uuidhash = \''.hash('sha256', (string) $cardUri).'\'');
		}
		return ' AND ('.implode(' OR ', $conditions).')';
	}

	private function _cardMappingTableAvailable()
	{
		if ($this->hasCardMappingTable !== null) {
			return $this->hasCardMappingTable;
		}
		// Use the database-agnostic Dolibarr schema helper.
		$this->hasCardMappingTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_cardmap');
		return $this->hasCardMappingTable;
	}

	private function _validateCardUri($cardUri)
	{
		$length = strlen((string) $cardUri);
		if ($length === 0 || $length > self::MAX_CARD_URI_LENGTH) {
			throw new \Sabre\DAV\Exception\BadRequest('The CardDAV resource name is missing or too long');
		}
	}

	private function _validateCardUid($uid)
	{
		$length = strlen((string) $uid);
		if ($length === 0 || $length > self::MAX_CARD_UID_LENGTH) {
			throw new \Sabre\DAV\Exception\BadRequest('The vCard UID is missing or too long');
		}
	}

	/** Validate the virtual address-book ID and serialize its DAV mutations. */
	private function _lockAddressBookOwner($addressbookId)
	{
		$addressbookId = (int) $addressbookId;
		$validIds = array(
			(int) $this->user->id,
			(int) $this->user->id + CDAV_ADDRESSBOOK_ID_SHIFT,
			(int) $this->user->id + 2 * CDAV_ADDRESSBOOK_ID_SHIFT,
		);
		if (!in_array($addressbookId, $validIds, true)) {
			throw new Forbidden('The address book does not belong to the authenticated Dolibarr user');
		}
		$result = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $this->user->id)
			.' AND statut > 0 AND fk_soc IS NULL AND entity IN ('.getEntity('user').') FOR UPDATE');
		if (!$result || !$this->db->fetch_object($result)) {
			throw new Forbidden('Address-book owner is not an active internal Dolibarr user');
		}
	}

	public function expectCardVersion($addressbookId, $cardUri, $etag)
	{
		$this->expectedCardEtags[((int) $addressbookId).'\n'.(string) $cardUri] = (string) $etag;
	}

	private function _assertCardVersion($addressbookId, $cardUri)
	{
		$key = ((int) $addressbookId).'\n'.(string) $cardUri;
		if (!array_key_exists($key, $this->expectedCardEtags)) {
			return;
		}
		$expected = $this->expectedCardEtags[$key];
		unset($this->expectedCardEtags[$key]);
		$current = $this->getCard((int) $addressbookId, (string) $cardUri);
		if ($current === false || !hash_equals((string) $expected, (string) $current['etag'])) {
			throw new \Sabre\DAV\Exception\PreconditionFailed(
				'The vCard changed while the conditional request was waiting for its Dolibarr lock',
				'If-Match'
			);
		}
	}

	/** Store full CardDAV href/UID values outside size-limited native ref_ext. */
	private function _storeCardMapping($type, $objectId, $cardUri, $sourceUid)
	{
		if (!$this->_cardMappingTableAvailable()) {
			return;
		}
		$type = (string) $type;
		$objectId = (int) $objectId;
		$cardUri = (string) $cardUri;
		$sourceUid = (string) $sourceUid;
		$uriHash = hash('sha256', $cardUri);
		$uidHash = hash('sha256', $sourceUid);
		$where = 'entity = '.$this->entity.' AND object_type = \''.$this->db->escape($type)
			.'\' AND fk_object = '.$objectId;
		$values = "uuidext = '".$this->db->escape($cardUri)."', uuidhash = '".$uriHash
			."', sourceuid = '".$this->db->escape($sourceUid)."', sourceuid_hash = '".$uidHash."'";
		if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX.'cdav_cardmap SET '.$values.' WHERE '.$where)) {
			$this->_throwCardMappingError($type, $objectId, $uriHash, $uidHash);
		}
		$result = $this->db->query('SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE '.$where);
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to verify the CardDAV object mapping');
		}
		if ($this->db->fetch_object($result)) return;

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_cardmap
			(entity, object_type, fk_object, uuidext, uuidhash, sourceuid, sourceuid_hash) VALUES ('
			.$this->entity.', \''.$this->db->escape($type).'\', '.$objectId.', \''.$this->db->escape($cardUri)
			.'\', \''.$uriHash.'\', \''.$this->db->escape($sourceUid).'\', \''.$uidHash.'\')';
		if (!$this->db->query($sql)) {
			$this->_throwCardMappingError($type, $objectId, $uriHash, $uidHash);
		}
	}

	/** Turn concurrent URI/UID uniqueness violations into an explicit HTTP 409. */
	private function _throwCardMappingError($type, $objectId, $uriHash, $uidHash)
	{
		if ($this->db->lasterrno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
			throw new \Sabre\DAV\Exception\Conflict('A different CardDAV resource already uses this URI or UID');
		}
		$sql = 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE entity = '.$this->entity
			.' AND object_type = \''.$this->db->escape((string) $type).'\' AND fk_object <> '.((int) $objectId)
			." AND (uuidhash = '".$this->db->escape((string) $uriHash)."' OR sourceuid_hash = '"
			.$this->db->escape((string) $uidHash)."') LIMIT 1";
		$result = $this->db->query($sql);
		if ($result && $this->db->fetch_object($result)) {
			throw new \Sabre\DAV\Exception\Conflict('A different CardDAV resource already uses this URI or UID');
		}
		throw new \Sabre\DAV\Exception('Unable to save the CardDAV object mapping');
	}

	/** Refuse two DAV resources that claim the same vCard UID. */
	private function _assertCardUidAvailable($type, $sourceUid, $objectId = 0)
	{
		$this->_pruneOrphanCardMetadata();
		if (!$this->_cardMappingTableAvailable()) {
			return;
		}
		$sql = 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap'
			.' WHERE entity = '.$this->entity
			.' AND object_type = \''.$this->db->escape((string) $type).'\''
			.' AND sourceuid_hash = \''.hash('sha256', (string) $sourceUid).'\''
			.' AND sourceuid = \''.$this->db->escape((string) $sourceUid).'\'';
		if ($objectId > 0) {
			$sql .= ' AND fk_object <> '.((int) $objectId);
		}
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to verify vCard UID uniqueness');
		}
		if ($this->db->fetch_object($result)) {
			throw new \Sabre\DAV\Exception\Conflict('A different CardDAV resource already uses this vCard UID');
		}
	}

	/** Remove only protocol metadata whose native Dolibarr object was deleted. */
	private function _pruneOrphanCardMetadata()
	{
		if ($this->metadataPruned) return;
		$this->metadataPruned = true;
		foreach (array('cdav_cardmap', 'cdav_vcard') as $table) {
			if (!$this->db->DDLInfoTable(MAIN_DB_PREFIX.$table)) continue;
			$sql = 'DELETE meta FROM '.MAIN_DB_PREFIX.$table.' meta'
				.' LEFT JOIN '.MAIN_DB_PREFIX.'socpeople native_contact ON meta.object_type = \'ct\''
				.' AND native_contact.rowid = meta.fk_object AND native_contact.entity = meta.entity'
				.' LEFT JOIN '.MAIN_DB_PREFIX.'societe native_thirdparty ON meta.object_type = \'th\''
				.' AND native_thirdparty.rowid = meta.fk_object AND native_thirdparty.entity = meta.entity'
				.' LEFT JOIN '.MAIN_DB_PREFIX.'adherent native_member ON meta.object_type = \'mb\''
				.' AND native_member.rowid = meta.fk_object AND native_member.entity = meta.entity'
				.' WHERE meta.entity = '.$this->entity.' AND ('
				."meta.object_type NOT IN ('ct','th','mb')"
				." OR (meta.object_type = 'ct' AND native_contact.rowid IS NULL)"
				." OR (meta.object_type = 'th' AND native_thirdparty.rowid IS NULL)"
				." OR (meta.object_type = 'mb' AND native_member.rowid IS NULL))";
			if (!$this->db->query($sql)) {
				throw new \Sabre\DAV\Exception('Unable to prune orphaned CardDAV metadata');
			}
		}
	}

	private function _decodeSocialNetworks($value)
	{
		if (is_array($value)) {
			return $value;
		}
		$decoded = json_decode((string) $value, true);
		return is_array($decoded) ? $decoded : array();
	}

	private function _extractCategoryLabels($vCard)
	{
		$labels = array();
		foreach ($vCard->select('CATEGORIES') as $categories) {
			foreach ($categories->getParts() as $label) {
				$label = trim((string) $label);
				if ($label !== '') {
					$labels[$label] = $label;
				}
			}
		}
		return array_values($labels);
	}

	private function _storeSocialNetworks(array &$data, array $networks)
	{
		$data['_socialnetworks_patch'] = $networks;
		$nonEmpty = array_filter($networks, static fn($value) => trim((string) $value) !== '');
		if ($nonEmpty) {
			$data['socialnetworks'] = json_encode($nonEmpty, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
	}

	/** Merge a client patch without erasing networks that its platform ignores. */
	private function _mergeSocialNetworks($current, array $patch)
	{
		$merged = $this->_decodeSocialNetworks($current);
		foreach ($patch as $code => $value) {
			$value = trim((string) $value);
			if ($value === '') {
				unset($merged[$code]);
			} else {
				$merged[$code] = $value;
			}
		}
		return $merged;
	}

	private function _isPreferredProperty($property)
	{
		if (isset($property['PREF'])) {
			return true;
		}
		$propertyTypes = isset($property['TYPE']) ? $property['TYPE'] : array();
		foreach ($propertyTypes as $type) {
			if (strtoupper((string) $type) === 'PREF') {
				return true;
			}
		}
		return false;
	}

	/** Return normalized TYPE parameters (vCard 3 and 4 are both accepted). */
	private function _getPropertyTypes($property)
	{
		$types = array();
		$propertyTypes = isset($property['TYPE']) ? $property['TYPE'] : array();
		foreach ($propertyTypes as $type) {
			foreach (explode(',', (string) $type) as $part) {
				$part = strtoupper(trim($part));
				if ($part !== '') {
					$types[$part] = true;
				}
			}
		}
		return $types;
	}

	/** Return the native Dolibarr social-network dictionary, including inactive rows. */
	private function _socialNetworkDictionary()
	{
		return function_exists('getArrayOfSocialNetworks') ? getArrayOfSocialNetworks() : array();
	}

	private function _socialNetworkCode($value)
	{
		$value = strtolower(trim((string) $value));
		$aliases = array(
			'x' => 'twitter', 'x.com' => 'twitter', 'twitter.com' => 'twitter',
			'linked-in' => 'linkedin', 'skype-username' => 'skype', 'xmpp' => 'jabber',
		);
		$value = $aliases[$value] ?? $value;
		$dictionary = $this->_socialNetworkDictionary();
		if (isset($dictionary[$value])) return $value;
		if ($value === 'twitter' && isset($dictionary['x'])) return 'x';
		if (preg_match('/^[a-z0-9_-]{1,32}$/', $value)) return $value;
		return '';
	}

	/** Repair the unquoted X-USER form produced by some iOS releases. */
	private function _repairSocialProfile($property)
	{
		$xuser = isset($property['X-USER']) ? (string) $property['X-USER'] : '';
		$value = trim((string) $property);
		if ($xuser !== '' && preg_match('/^[a-z][a-z0-9+.-]*$/i', $xuser) && str_starts_with($value, '//')) {
			$position = strpos($value, ':');
			$xuser .= ':'.($position === false ? $value : substr($value, 0, $position));
			$value = $position === false ? '' : substr($value, $position + 1);
		}
		return array(trim($xuser), trim($value));
	}

	/** Reduce a profile URL to the identifier expected by Dolibarr's dictionary. */
	private function _normalizeSocialId($code, $value)
	{
		$value = trim(str_replace(array('%22', '"'), '', (string) $value));
		$value = trim(str_replace('%20', ' ', $value));
		$dictionary = $this->_socialNetworkDictionary();
		$template = (string) ($dictionary[$code]['url'] ?? '');
		$base = preg_replace('/\/?\{socialid\}.*$/', '', $template);
		$base = preg_replace('#^https?://#i', '', (string) $base);
		$base = preg_replace('#^www\.#i', '', (string) $base);
		if ($base === '' || strpos($base, '{') !== false) return $value;
		$host = preg_replace('#/.*$#', '', $base);
		$path = substr($base, strlen($host));
		$pattern = '#^((https?:)?//)?(www\.)?'.preg_quote($host, '#')
			.($path !== '' ? '('.preg_quote($path, '#').')?' : '').'/*#i';
		do {
			$before = $value;
			$value = preg_replace($pattern, '', $value);
		} while ($value !== $before);
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) && ($position = strpos($value, ':')) !== false) {
			$value = substr($value, 0, $position);
		}
		return trim($value, " \t/");
	}

	/** Extract social-network values emitted by the main desktop/mobile clients. */
	private function _extractSocialNetworks($vCard)
	{
		$networks = array();
		$dictionary = $this->_socialNetworkDictionary();
		$legacyCodes = array_unique(array_merge(array_keys($dictionary), array(
			'jabber', 'skype', 'whatsapp', 'snapchat', 'linkedin', 'instagram',
			'facebook', 'twitter', 'x', 'mastodon', 'github', 'youtube',
		)));
		foreach ($legacyCodes as $network) {
			$propertyName = 'X-'.strtoupper((string) $network);
			foreach ($vCard->select($propertyName) as $property) {
				$code = $this->_socialNetworkCode($network);
				if ($code !== '') $networks[$code] = $this->_normalizeSocialId($code, (string) $property);
			}
		}
		foreach ($vCard->select('X-SKYPE-USERNAME') as $property) {
			$code = $this->_socialNetworkCode('skype');
			if ($code !== '') $networks[$code] = $this->_normalizeSocialId($code, (string) $property);
		}
		foreach ($vCard->select('IMPP') as $impp) {
			$service = isset($impp['X-SERVICE-TYPE']) ? trim((string) $impp['X-SERVICE-TYPE']) : '';
			$value = trim((string) $impp);
			$scheme = (string) parse_url($value, PHP_URL_SCHEME);
			$code = $this->_socialNetworkCode($service !== '' ? $service : $scheme);
			if ($code === '') continue;
			$value = preg_replace('/^[a-z][a-z0-9+.-]*:/i', '', $value);
			$networks[$code] = $this->_normalizeSocialId($code, $value);
		}
		foreach ($vCard->select('X-SOCIALPROFILE') as $property) {
			$type = isset($property['TYPE']) ? (string) $property['TYPE'] : '';
			$code = $this->_socialNetworkCode($type);
			if ($code === '') continue;
			list($xuser, $url) = $this->_repairSocialProfile($property);
			$value = $xuser !== '' ? $xuser : $url;
			if (!preg_match('/^x-apple:/i', $value)) {
				$networks[$code] = $this->_normalizeSocialId($code, $value);
			}
		}
		return $networks;
	}

	private function _quoteVCardParameter($value)
	{
		$value = str_replace('"', '', (string) $value);
		return strpbrk($value, ':;,') === false ? $value : '"'.$value.'"';
	}

	/** Export every native Dolibarr social-network value in a client-safe form. */
	private function _socialNetworksToVCard(array $networks)
	{
		$dictionary = $this->_socialNetworkDictionary();
		$instantMessaging = array('skype' => 'skype', 'whatsapp' => 'whatsapp', 'jabber' => 'xmpp');
		$lines = '';
		foreach ($networks as $rawCode => $rawValue) {
			$code = strtolower((string) $rawCode);
			$value = trim((string) $rawValue);
			if ($value === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $code)) continue;
			if (isset($instantMessaging[$code])) {
				$label = ucfirst($code === 'jabber' ? 'Jabber' : $code);
				$lines .= 'IMPP;X-SERVICE-TYPE='.$label.':'.$instantMessaging[$code].':'
					.str_replace(';', '\\;', $value)."\n";
				continue;
			}
			$template = (string) ($dictionary[$code]['url'] ?? '');
			if (preg_match('#^https?://#i', $value)) {
				$url = $value;
			} elseif ($template !== '') {
				// Dictionary templates may accept paths or federated identifiers.
				$encoded = str_replace(array('%2F', '%40', '%3A'), array('/', '@', ':'), rawurlencode($value));
				$url = str_replace('{socialid}', $encoded, $template);
			} else {
				$url = 'x-apple:'.rawurlencode($value);
			}
			$lines .= 'X-SOCIALPROFILE;TYPE='.$code.';X-USER='.$this->_quoteVCardParameter($value).':'
				.$url."\n";
		}
		return $lines;
	}

	/** Resolve a country label with Dolibarr's native dictionary helper. */
	private function _getCountryIdFromLabel($label)
	{
		$label = trim((string) $label);
		if ($label === '') {
			return 0;
		}
		if (!function_exists('getCountry')) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
		}
		$id = \getCountry('', '3', $this->db, $this->langs, 0, $label);
		return is_numeric($id) ? (int) $id : 0;
	}

	/**
	 * Link an externally-created person to an existing Dolibarr third party only
	 * when the vCard ORG value identifies exactly one active company.
	 */
	private function _resolveThirdPartyId($organization)
	{
		$organization = trim((string) $organization, " ;\r\n\t");
		if ($organization === '') {
			return 0;
		}
		$sql = 'SELECT s.rowid FROM '.MAIN_DB_PREFIX.'societe s
			WHERE s.entity IN ('.getEntity('societe').') AND s.status = 1
			AND (s.nom = \''.$this->db->escape($organization).'\'
				OR CONCAT(s.nom, \' (\', COALESCE(s.name_alias, \'\'), \')\') = \''.$this->db->escape($organization).'\')
			ORDER BY s.rowid LIMIT 2';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to resolve the Dolibarr third-party link');
		}
		$ids = array();
		while ($row = $this->db->fetch_object($result)) {
			$ids[] = (int) $row->rowid;
		}
		return count($ids) === 1 ? $ids[0] : 0;
	}

	private function _syncCategories($type, $objectId, array $labels)
	{
		if (!isModEnabled('categorie') || !$this->_hasRight('categorie', 'read')) {
			return;
		}

		if ($type === 'contact') {
			$categoryTypes = array(4);
		} elseif ($type === 'member') {
			$categoryTypes = array(3);
		} else {
			$categoryTypes = array(1, 2);
		}

		$quotedLabels = array();
		foreach (array_unique($labels) as $label) {
			if (trim((string) $label) !== '') {
				$quotedLabels[] = '\''.$this->db->escape(trim((string) $label)).'\'';
			}
		}
		$sql = 'SELECT rowid, type FROM '.MAIN_DB_PREFIX.'categorie
			WHERE type IN ('.implode(',', $categoryTypes).')
			AND entity IN ('.getEntity('category').')';
		if ($quotedLabels) {
			$sql .= ' AND label IN ('.implode(',', $quotedLabels).')';
		} else {
			$sql .= ' AND 1 = 0';
		}
		$result = $this->db->query($sql);
		$categoryIds = array();
		$categoryIdsByType = array(1 => array(), 2 => array());
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to resolve Dolibarr categories');
		}
		while ($row = $this->db->fetch_object($result)) {
			$categoryIds[] = (int) $row->rowid;
			if ($type === 'thirdparty' && isset($categoryIdsByType[(int) $row->type])) {
				$categoryIdsByType[(int) $row->type][] = (int) $row->rowid;
			}
		}
		if ($type === 'contact' && (int) CDAV_CONTACT_TAG > 0) {
			$categoryIds[] = (int) CDAV_CONTACT_TAG;
		}

		// Use Dolibarr's business APIs so category hooks and validation stay active.
		if ($type === 'contact') {
			require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
			$object = new \Contact($this->db);
			$object->id = (int) $objectId;
			$result = $object->setCategories(array_values(array_unique($categoryIds)), true);
		} elseif ($type === 'member') {
			require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
			$object = new \Adherent($this->db);
			$object->id = (int) $objectId;
			$result = $object->setCategories(array_values(array_unique($categoryIds)));
		} else {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
			$object = new \Societe($this->db);
			$object->id = (int) $objectId;
			$result = $object->setCategories(array_values(array_unique($categoryIdsByType[1])), \Categorie::TYPE_SUPPLIER, true);
			if ($result >= 0) {
				$result = $object->setCategories(array_values(array_unique($categoryIdsByType[2])), \Categorie::TYPE_CUSTOMER, true);
			}
		}
		if ($result < 0) {
			throw new \Sabre\DAV\Exception('Unable to update Dolibarr categories');
		}
	}

	private function _getDefaultMemberType()
	{
		require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
		$memberType = new \AdherentType($this->db);
		$activeTypes = $memberType->liste_array(0);
		if ($activeTypes) {
			$typeId = (int) array_key_first($activeTypes);
			if ($memberType->fetch($typeId) > 0 && (int) $memberType->status === 0) {
				$morphy = in_array((string) $memberType->morphy, array('phy', 'mor'), true)
					? (string) $memberType->morphy
					: 'phy';
				return array('id' => $typeId, 'morphy' => $morphy);
			}
		}
		throw new \Sabre\DAV\Exception('No active Dolibarr member type is available');
	}

	/**
	 * Updates properties for an address book.
	 *
	 * The list of mutations is stored in a Sabre\DAV\PropPatch object.
	 * To do the actual updates, you must tell this object which properties
	 * you're going to process with the handle() method.
	 *
	 * Calling the handle method is like telling the PropPatch object "I
	 * promise I can handle updating this property".
	 *
	 * Read the PropPatch documenation for more info and examples.
	 *
	 * @param string $addressBookId
	 * @param \Sabre\DAV\PropPatch $propPatch
	 * @return void
	 */
	function updateAddressBook($addressbookId, \Sabre\DAV\PropPatch $propPatch) {

		// not supported
		return;

	}

	/**
	 * Creates a new address book
	 *
	 * @param string $principalUri
	 * @param string $url Just the 'basename' of the url.
	 * @param array $properties
	 * @return void
	 */
	function createAddressBook($principalUri, $url, array $properties) {

		throw new Forbidden('Creating address books is not supported');

	}

	/**
	 * Deletes an entire addressbook and all its contents
	 *
	 * @param int $addressBookId
	 * @return void
	 */
	function deleteAddressBook($addressbookId) {

		throw new Forbidden('Deleting address books is not supported');

	}

	/**
	 * Base sql request for contacts
	 *
	 * @return string
	 */
	protected function _getSqlContacts($sqlWhere='')
	{
		$hasMappingTable = $this->_cardMappingTableAvailable();
		$mappingSelect = $hasMappingTable
			? ', cm.uuidext AS cdav_uuidext, cm.sourceuid AS cdav_sourceuid, cm.tms AS cdav_mapping_tms'
			: ', NULL AS cdav_uuidext, NULL AS cdav_sourceuid, NULL AS cdav_mapping_tms';
		$mappingJoin = $hasMappingTable
			? ' LEFT JOIN '.MAIN_DB_PREFIX.'cdav_cardmap AS cm ON cm.entity = p.entity AND cm.object_type = \'ct\' AND cm.fk_object = p.rowid'
			: '';
		$lastUpdatedSql = $hasMappingTable ? 'GREATEST(COALESCE(s.tms, p.tms), p.tms, COALESCE(cm.tms, p.tms))' : 'GREATEST(COALESCE(s.tms, p.tms), p.tms)';
		$sql = 'SELECT p.*, co.label country_label, '.$lastUpdatedSql.' lastupd, s.code_client soc_code_client, s.code_fournisseur soc_code_fournisseur,
					s.nom soc_nom, s.name_alias soc_name_alias, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.fax soc_fax,
					s.email soc_email, s.url soc_url, s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cc.fk_categorie ORDER BY cc.fk_categorie ASC SEPARATOR \',\') category_ids,
					s.logo'.$mappingSelect.'
				FROM '.MAIN_DB_PREFIX.'socpeople as p
				'.$mappingJoin.'
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = p.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_contact as cc ON cc.fk_socpeople = p.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cc.fk_categorie
				WHERE p.entity IN ('.getEntity('contact').')
				AND p.statut=1
				AND (p.priv=0 OR (p.priv=1 AND p.fk_user_creat='.$this->user->id.'))
				'.$sqlWhere.'
				GROUP BY p.rowid';

		if(intval(CDAV_CONTACT_TAG)>0)
			$sql.= " HAVING CONCAT(',',category_ids,',') LIKE '%,".$this->db->escape(CDAV_CONTACT_TAG).",%'";

		return $sql;
	}

	/**
	 * Base sql request for adherents
	 *
	 * @return string
	 */
	protected function _getSqlMembers($sqlWhere='')
	{
		$hasMappingTable = $this->_cardMappingTableAvailable();
		$mappingSelect = $hasMappingTable
			? ', cm.uuidext AS cdav_uuidext, cm.sourceuid AS cdav_sourceuid, cm.tms AS cdav_mapping_tms'
			: ', NULL AS cdav_uuidext, NULL AS cdav_sourceuid, NULL AS cdav_mapping_tms';
		$mappingJoin = $hasMappingTable
			? ' LEFT JOIN '.MAIN_DB_PREFIX.'cdav_cardmap AS cm ON cm.entity = p.entity AND cm.object_type = \'mb\' AND cm.fk_object = p.rowid'
			: '';
		$lastUpdatedSql = $hasMappingTable ? 'GREATEST(COALESCE(s.tms, p.tms), p.tms, COALESCE(cm.tms, p.tms))' : 'GREATEST(COALESCE(s.tms, p.tms), p.tms)';
		$sql = 'SELECT p.*, co.label country_label, '.$lastUpdatedSql.' lastupd, s.code_client soc_code_client, s.code_fournisseur soc_code_fournisseur,
					COALESCE(s.nom, p.societe) soc_nom, s.name_alias soc_name_alias, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.fax soc_fax,
					s.email soc_email, s.url soc_url, s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cc.fk_categorie ORDER BY cc.fk_categorie ASC SEPARATOR \',\') category_ids'.$mappingSelect.'
				FROM '.MAIN_DB_PREFIX.'adherent as p
				'.$mappingJoin.'
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = p.country
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_member as cc ON cc.fk_member = p.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cc.fk_categorie
				WHERE p.entity IN ('.getEntity('adherent').')
				AND p.statut=1
				'.$sqlWhere.'
				GROUP BY p.rowid';
		return $sql;
	}

	/**
	 * Base sql request for thirdparties
	 *
	 * @return string
	 */
	protected function _getSqlThirdparties($sqlWhere='')
	{
		$hasMappingTable = $this->_cardMappingTableAvailable();
		$mappingSelect = $hasMappingTable
			? ', cm.uuidext AS cdav_uuidext, cm.sourceuid AS cdav_sourceuid, cm.tms AS cdav_mapping_tms'
			: ', NULL AS cdav_uuidext, NULL AS cdav_sourceuid, NULL AS cdav_mapping_tms';
		$mappingJoin = $hasMappingTable
			? ' LEFT JOIN '.MAIN_DB_PREFIX.'cdav_cardmap AS cm ON cm.entity = s.entity AND cm.object_type = \'th\' AND cm.fk_object = s.rowid'
			: '';
		$lastUpdatedSql = $hasMappingTable ? 'GREATEST(s.tms, COALESCE(cm.tms, s.tms))' : 's.tms';
		$sql = 'SELECT s.*, co.label country_label, '.$lastUpdatedSql.' lastupd, cfj.libelle as forme_juridique,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cs.fk_categorie ORDER BY cs.fk_categorie ASC SEPARATOR \',\') category_ids'.$mappingSelect.'
				FROM '.MAIN_DB_PREFIX.'societe as s
				'.$mappingJoin.'
				LEFT JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = s.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'c_forme_juridique as cfj ON cfj.rowid = s.fk_forme_juridique
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_societe as cs ON cs.fk_soc = s.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cs.fk_categorie
				WHERE s.entity IN ('.getEntity('societe').')
				AND s.status=1';
		if (!$this->_hasRight('societe', 'client', 'voir'))
			$sql.= ' AND s.rowid = sc.fk_soc AND sc.fk_user = '.((int) $this->user->id);
		if (!$this->_hasRight('fournisseur', 'read'))
			$sql .= ' AND (s.fournisseur <> 1 OR s.client <> 0)'; // client=0, fournisseur=0 must be visible
		if (CDAV_THIRD_SYNC==1) // without contact
			$sql .= ' AND (SELECT count(sp.rowid) FROM '.MAIN_DB_PREFIX.'socpeople sp WHERE sp.fk_soc=s.rowid)=0';
		$sql .= $sqlWhere.' GROUP BY s.rowid';

		return $sql;
	}

	/**
	 * Convert contact row to VCard string
	 *
	 * @param row object
	 * @return string
	 */
	protected function _contactToVCard($obj)
	{
		$obj = $this->_normalizeDatabaseRow($obj);
		$socialNetworks = $this->_decodeSocialNetworks($obj->socialnetworks ?? '');
		$notePublic = $this->_cleanDolibarrText($obj->note_public ?? '');
		$civility = $this->_getLocalizedCivility($obj->civility ?? '');
		$nameParameters = ';CHARSET=UTF-8'.$this->_getVCardLanguageParameter();
		$nick = [];
		$categ = [];
		if($obj->soc_client)
		{
			$nick[] = $obj->soc_code_client;
			$categ[] = $this->langs->transnoentitiesnoconv('Customer');
		}
		if($obj->soc_fournisseur)
		{
			$nick[] = $obj->soc_code_fournisseur;
			$categ[] = $this->langs->transnoentitiesnoconv('Supplier');
		}
		if($obj->priv)
			$categ[] = $this->langs->transnoentitiesnoconv('ContactPrivate');
		else
			$categ[] = $this->langs->transnoentitiesnoconv('ContactPublic');
		if (isModEnabled('categorie') && $this->_hasRight('categorie', 'read'))
			if(trim($obj->category_label)!='')
				$categ[] = trim($obj->category_label);

		$soc_address=explode("\n",$obj->soc_address,2);
		foreach($soc_address as $kAddr => $vAddr)
			$soc_address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		$soc_address[]='';
		$soc_address[]='';

		$address=explode("\n",$obj->address,2);
		foreach($address as $kAddr => $vAddr)
		{
			$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		}
		$address[]='';
		$address[]='';

		// remove carriage return in data
		$objvars = get_object_vars($obj);
		foreach ($objvars as $key => $value)
		{
			if(is_string($value))
				$obj->$key = strtr(trim($value), array("\n"=>"\\n", "\r"=>""));
		}

		$carddata ="BEGIN:VCARD\n";
		$carddata.="VERSION:3.0\n";
		$carddata.="PRODID:-//Dolibarr CDav//FR\n";
		$carddata.="UID:".$this->_getCardSourceUid($obj, 'ct')."\n";
		if(!empty($obj->soc_nom) && getDolGlobalInt('CDAV_CONCAT_SOCNAME_FOR_PHONE'))
		{
			$carddata.="N".$nameParameters.":".str_replace(';','\;',$obj->lastname).";".str_replace(';','\;',$obj->firstname).";;".str_replace(';','\;',$civility).";\n";
			$carddata.="FN".$nameParameters.":".str_replace(';','\;',"(".$obj->soc_nom.") ".$obj->lastname." ".$obj->firstname)."\n";
		}
		else
		{
			$carddata.="N".$nameParameters.":".str_replace(';','\;',$obj->lastname).";".str_replace(';','\;',$obj->firstname).";;".str_replace(';','\;',$civility).";\n";
			$carddata.="FN".$nameParameters.":".str_replace(';','\;',$obj->lastname." ".$obj->firstname)."\n";
		}

		if(!empty($obj->soc_nom) && !empty($obj->soc_name_alias))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom." (".$obj->soc_name_alias.")").";\n";
		elseif(!empty($obj->soc_nom))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom).";\n";
		if(!empty($obj->poste))
			$carddata.="TITLE;CHARSET=UTF-8:".str_replace(';','\;',$obj->poste)."\n";
		if(count($categ)>0)
			$carddata.="CATEGORIES;CHARSET=UTF-8:".str_replace(';','\;',implode(',',$categ))."\n";
		$carddata.="CLASS:".($obj->priv?'PRIVATE':'PUBLIC')."\n";
		$carddata.="ADR;TYPE=HOME;CHARSET=UTF-8:;".str_replace(';','\;',$address[1]).";".str_replace(';','\;',$address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->town).";;".str_replace(';','\;',$obj->zip).";".str_replace(';','\;',$obj->country_label)."\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".str_replace(';','\;',$soc_address[1]).";".str_replace(';','\;',$soc_address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->soc_town).";;".str_replace(';','\;',$obj->soc_zip).";".str_replace(';','\;',$obj->soc_country_label)."\n";
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',(trim($obj->phone)==''?$obj->soc_phone:$obj->phone))."\n";
		if(!empty($obj->phone_perso))
			$carddata.="TEL;TYPE=HOME,VOICE:".str_replace(';','\;',$obj->phone_perso)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL,VOICE:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->soc_fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->soc_fax)."\n";
		if(!empty($obj->fax))
			$carddata.="TEL;TYPE=HOME,FAX:".str_replace(';','\;',$obj->fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;TYPE=PREF,INTERNET:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->soc_email) && $obj->soc_email!=$obj->email)
			$carddata.="EMAIL:".str_replace(';','\;',$obj->soc_email)."\n";
		$contactUrl = !empty($obj->url) ? $obj->url : $obj->soc_url;
		if(!empty($contactUrl))
		{
			if(strpos($contactUrl,'://')===false)
				$carddata.="URL:https://".trim($contactUrl)."\n";
			else
				$carddata.="URL:".trim($contactUrl)."\n";
		}
		$carddata .= $this->_socialNetworksToVCard($socialNetworks);
		if(!empty($obj->birthday))
			$carddata.="BDAY:".str_replace(';','\;',$obj->birthday)."\n";
		if($notePublic !== '')
			$carddata.="NOTE;CHARSET=UTF-8:".$this->_escapeVCardText($notePublic)."\n";
		if(!empty($obj->photo))
		{
			$photofile = $this->_photoFilePath('contact', $obj->rowid, $obj->photo);
			if(!file_exists($photofile) && !empty($obj->logo) && !empty($obj->fk_soc))
			{
				// fallback image search thirdparty if possible
				$societeOutput = $this->_outputDirectory('societe');
				$photofile = $societeOutput === '' ? '' : $societeOutput.'/'.((int) $obj->fk_soc).'/logos/'
					.getImageFileNameForSize($obj->logo, '');
			}

			$carddata .= $this->_photoToVCard($photofile);
		}
   		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";
		return $this->vcardStore->merge('ct', (int) $obj->rowid, $this->_normalizeVCardData($carddata));
	}

	/**
	 * Convert member row to VCard string
	 *
	 * @param row object
	 * @return string
	 */
	protected function _memberToVCard($obj)
	{
		$obj = $this->_normalizeDatabaseRow($obj);
		$socialNetworks = $this->_decodeSocialNetworks($obj->socialnetworks ?? '');
		$notePublic = $this->_cleanDolibarrText($obj->note_public ?? '');
		$civility = $this->_getLocalizedCivility($obj->civility ?? '');
		$nameParameters = ';CHARSET=UTF-8'.$this->_getVCardLanguageParameter();
		$nick = [];
		$categ = [];
		if($obj->soc_client)
		{
			$nick[] = $obj->soc_code_client;
			$categ[] = $this->langs->transnoentitiesnoconv('Customer');
		}
		if($obj->soc_fournisseur)
		{
			$nick[] = $obj->soc_code_fournisseur;
			$categ[] = $this->langs->transnoentitiesnoconv('Supplier');
		}
		if (isModEnabled('categorie') && $this->_hasRight('categorie', 'read'))
			if(trim($obj->category_label)!='')
				$categ[] = trim($obj->category_label);

		$soc_address=explode("\n",$obj->soc_address,2);
		foreach($soc_address as $kAddr => $vAddr)
			$soc_address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		$soc_address[]='';
		$soc_address[]='';

		$address=explode("\n",$obj->address,2);
		foreach($address as $kAddr => $vAddr)
		{
			$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		}
		$address[]='';
		$address[]='';

		// remove carriage return in data
		$objvars = get_object_vars($obj);
		foreach ($objvars as $key => $value)
		{
			if(is_string($value))
				$obj->$key = strtr(trim($value), array("\n"=>"\\n", "\r"=>""));
		}

		$carddata ="BEGIN:VCARD\n";
		$carddata.="VERSION:3.0\n";
		$carddata.="PRODID:-//Dolibarr CDav//FR\n";
		$carddata.="UID:".$this->_getCardSourceUid($obj, 'mb')."\n";
		$carddata.="N".$nameParameters.":".str_replace(';','\;',$obj->lastname).";".str_replace(';','\;',$obj->firstname).";;".str_replace(';','\;',$civility).";\n";
		$carddata.="FN".$nameParameters.":".str_replace(';','\;',$obj->lastname." ".$obj->firstname)."\n";
		if(!empty($obj->soc_nom) && !empty($obj->soc_name_alias))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom." (".$obj->soc_name_alias.")").";\n";
		elseif(!empty($obj->soc_nom))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->soc_nom).";\n";
		/*if(!empty($obj->poste))
			$carddata.="TITLE;CHARSET=UTF-8:".str_replace(';','\;',$obj->poste)."\n";*/
		if(count($categ)>0)
			$carddata.="CATEGORIES;CHARSET=UTF-8:".str_replace(';','\;',implode(',',$categ))."\n";
		$carddata.="CLASS:PUBLIC\n";
		$carddata.="ADR;TYPE=HOME;CHARSET=UTF-8:;".str_replace(';','\;',$address[1]).";".str_replace(';','\;',$address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->town).";;".str_replace(';','\;',$obj->zip).";".str_replace(';','\;',$obj->country_label)."\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".str_replace(';','\;',$soc_address[1]).";".str_replace(';','\;',$soc_address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->soc_town).";;".str_replace(';','\;',$obj->soc_zip).";".str_replace(';','\;',$obj->soc_country_label)."\n";
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',(trim($obj->phone)==''?$obj->soc_phone:$obj->phone))."\n";
		if(!empty($obj->phone_perso))
			$carddata.="TEL;TYPE=HOME,VOICE:".str_replace(';','\;',$obj->phone_perso)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL,VOICE:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->soc_fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->soc_fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;TYPE=PREF,INTERNET:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->soc_email) && $obj->soc_email!=$obj->email)
			$carddata.="EMAIL:".str_replace(';','\;',$obj->soc_email)."\n";
		$memberUrl = !empty($obj->url) ? $obj->url : $obj->soc_url;
		if(!empty($memberUrl))
		{
			if(strpos($memberUrl,'://')===false)
				$carddata.="URL:https://".trim($memberUrl)."\n";
			else
				$carddata.="URL:".trim($memberUrl)."\n";
		}
		$carddata .= $this->_socialNetworksToVCard($socialNetworks);
		if(!empty($obj->birth))
			$carddata.="BDAY;VALUE=DATE:".str_replace(';','\;',date('Ymd',strtotime($obj->birth)))."\n";
		if($notePublic !== '')
			$carddata.="NOTE;CHARSET=UTF-8:".$this->_escapeVCardText($notePublic)."\n";
		if(!empty($obj->photo))
		{
			$photofile = $this->_photoFilePath('member', $obj->rowid, $obj->photo);
			$carddata .= $this->_photoToVCard($photofile);
		}
		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";
		return $this->vcardStore->merge('mb', (int) $obj->rowid, $this->_normalizeVCardData($carddata));
	}


	/**
	 * Convert thirdparty row to VCard string
	 *
	 * @param row object
	 * @return string
	 */
	protected function _thirdpartyToVCard($obj)
	{
		global $conf;
		$obj = $this->_normalizeDatabaseRow($obj);
		$socialNetworks = $this->_decodeSocialNetworks($obj->socialnetworks ?? '');
		$notePublic = $this->_cleanDolibarrText($obj->note_public ?? '');
		$doliinfo = [];
		$categ = [];
		if($obj->client)
		{
			$doliinfo[] = "💼👑".$obj->code_client;
			$categ[] = $this->langs->transnoentitiesnoconv('Customer');
		}
		if($obj->fournisseur)
		{
			$doliinfo[] = "💼🏭".$obj->code_fournisseur;
			$categ[] = $this->langs->transnoentitiesnoconv('Supplier');
		}
		if (isModEnabled('categorie') && $this->_hasRight('categorie', 'read'))
			if(trim($obj->category_label)!='')
				$categ[] = trim($obj->category_label);

		$address=explode("\n",$obj->address,2);
		foreach($address as $kAddr => $vAddr)
		{
			$address[$kAddr] = trim(str_replace(array("\r","\t"),' ', str_replace("\n",' | ', trim($vAddr))));
		}
		$address[]='';
		$address[]='';

		// remove carriage return in data
		$objvars = get_object_vars($obj);
		foreach ($objvars as $key => $value)
		{
			if(is_string($value))
				$obj->$key = strtr(trim($value), array("\n"=>"\\n", "\r"=>""));
		}

		$carddata ="BEGIN:VCARD\n";
		$carddata.="VERSION:3.0\n";
		$carddata.="PRODID:-//Dolibarr CDav//FR\n";
		$carddata.="UID:".$this->_getCardSourceUid($obj, 'th')."\n";
		$carddata.="N;CHARSET=UTF-8:".str_replace(';','\;',$obj->nom).";;;;\n";
		$carddata.="FN;CHARSET=UTF-8:".str_replace(';','\;',$obj->nom)."\n";
		if(!empty($obj->nom))
			$carddata.="ORG;CHARSET=UTF-8:".str_replace(';','\;',$obj->nom).";\n";
		if(!empty($obj->name_alias))
			$carddata.="NICKNAME;CHARSET=UTF-8:".str_replace(';','\;',$obj->name_alias).";\n";
		if(!empty($obj->forme_juridique))
			$carddata.="TITLE;CHARSET=UTF-8:".str_replace(';','\;',$obj->forme_juridique)."\n";
		if(count($categ)>0)
			$carddata.="CATEGORIES;CHARSET=UTF-8:".str_replace(';','\;',implode(',',$categ))."\n";
		// $carddata.="CLASS:".($obj->priv?'PRIVATE':'PUBLIC')."\n";
		$carddata.="CLASS:PRIVATE\n";
		$carddata.="ADR;TYPE=WORK;CHARSET=UTF-8:;".str_replace(';','\;',$address[1]).";".str_replace(';','\;',$address[0]).";";
		$carddata.=	 str_replace(';','\;',$obj->town).";;".str_replace(';','\;',$obj->zip).";".str_replace(';','\;',$obj->country_label)."\n";
		$carddata.="TEL;TYPE=WORK,VOICE:".str_replace(';','\;',$obj->phone)."\n";
		if(!empty($obj->phone_mobile))
			$carddata.="TEL;TYPE=CELL,VOICE:".str_replace(';','\;',$obj->phone_mobile)."\n";
		if(!empty($obj->fax))
			$carddata.="TEL;TYPE=WORK,FAX:".str_replace(';','\;',$obj->fax)."\n";
		if(!empty($obj->email))
			$carddata.="EMAIL;TYPE=PREF,INTERNET:".str_replace(';','\;',$obj->email)."\n";
		if(!empty($obj->url))
		{
			if(strpos($obj->url,'://')===false)
				$carddata.="URL:https://".trim($obj->url)."\n";
			else
				$carddata.="URL:".trim($obj->url)."\n";
		}
		$carddata .= $this->_socialNetworksToVCard($socialNetworks);
		$noteParts = $doliinfo;
		if($notePublic !== '')
			$noteParts[] = $notePublic;
		$carddata.="NOTE;CHARSET=UTF-8:".$this->_escapeVCardText(implode("\n", $noteParts))."\n";
		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";

		return $this->vcardStore->merge('th', (int) $obj->rowid, $this->_normalizeVCardData($carddata));
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataContact($cardData, $mode) {

		debug_log('_parseDataContact('.strlen((string) $cardData).' bytes)');

		// A CardDAV PUT replaces the complete vCard. Initializing every mapped
		// field is essential: removing a phone or email on a client must clear it
		// in Dolibarr instead of resurrecting the old value on the next sync.
		$rdata = array(
			'lastname' => '', 'firstname' => '', 'civility' => '', 'poste' => '', 'priv' => 0,
			'phone' => '', 'phone_perso' => '', 'phone_mobile' => '', 'fax' => '',
			'email' => '', 'url' => '', 'address' => '', 'town' => '', 'zip' => '',
			'fk_pays' => 0, 'socialnetworks' => '', 'birthday' => null,
			'note_public' => '', 'photo' => '',
		);

		$vCard = $this->_readVCard($cardData);
		$rdata['_category_labels'] = $this->_extractCategoryLabels($vCard);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = isset($vCard->UID) ? trim((string) $vCard->UID) : '';
		$this->_validateCardUid($rdata['_uid']);
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = isset($vCard->N) ? $vCard->N->getParts() : array();
		if(isset($names[0]) && trim((string)$names[0])!='')
			$rdata['lastname'] = (string)$names[0];
		if($rdata['lastname']=='' && isset($vCard->FN) && trim((string)$vCard->FN)!='')
			$rdata['lastname'] = (string)$vCard->FN;
		if($rdata['lastname']=='' && isset($names[1]) && trim((string)$names[1])!='')
			$rdata['lastname'] = (string)$names[1];
		if($rdata['lastname']=='')
			$rdata['lastname'] = "Contact ".date('Y-m-d H:i:s');

		if(isset($names[1]))
			$rdata['firstname'] = (string)$names[1];

		if(isset($names[3]))
			$rdata['civility'] = $this->_getCivilityCode((string)$names[3]);

		if(isset($vCard->TITLE))
			$rdata['poste'] = (string)$vCard->TITLE;

		if(isset($vCard->CLASS) && ((string)strtoupper($vCard->CLASS))=='PRIVATE')
			$rdata['priv'] = 1;
		else
			$rdata['priv'] = 0;

		if(isset($vCard->TEL))
		{
			foreach($vCard->TEL as $tel)
			{
				$teltype = $this->_getPropertyTypes($tel);

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;

				if(isset($teltype['HOME']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone_perso'] = (string)$tel;

				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;

				if(isset($teltype['HOME']) && isset($teltype['FAX']))
					$rdata['fax'] = (string)$tel;
				elseif(isset($teltype['FAX']) && $rdata['fax'] === '')
					$rdata['fax'] = (string)$tel;
				elseif (!$teltype && $rdata['phone'] === '')
					$rdata['phone'] = (string) $tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if($rdata['email'] === '')
					$rdata['email'] = (string)$email;
				if($this->_isPreferredProperty($email))
					$rdata['email'] = (string)$email;
			}
		}

		if (isset($vCard->URL))
			$rdata['url'] = trim((string) $vCard->URL);

		if(isset($vCard->ADR))
		{
			$hasHomeAddress = false;
			foreach ($vCard->ADR as $candidateAddress) {
				if (isset($this->_getPropertyTypes($candidateAddress)['HOME'])) {
					$hasHomeAddress = true;
					break;
				}
			}
			foreach($vCard->ADR as $adr)
			{
				$adrtype = $this->_getPropertyTypes($adr);
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['HOME']) || (!$hasHomeAddress && $rdata['address'] === ''))
				{
					$rdata['address'] = '';
					$rdata['town'] = '';
					$rdata['zip'] = '';
					$rdata['_country_label'] = '';
					if(isset($adrparts[2]) && !empty($adrparts[2]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[2]))."\n";
					if(isset($adrparts[0]) && !empty($adrparts[0]))
						$rdata['address'].= $adrparts[0]."\n";
					if(isset($adrparts[1]) && !empty($adrparts[1]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[1]))."\n";
					$rdata['address'] = trim($rdata['address']);
					if(isset($adrparts[3]))
						$rdata['town'] = $adrparts[3];
					if(isset($adrparts[5]))
						$rdata['zip'] = $adrparts[5];
					if(isset($adrparts[6]))
						$rdata['_country_label'] = $adrparts[6];
				}
			}
		}

		$socialNetworks = $this->_extractSocialNetworks($vCard);
		$this->_storeSocialNetworks($rdata, $socialNetworks);
		$rdata['_organization'] = isset($vCard->ORG) ? trim((string) $vCard->ORG, " ;\r\n\t") : '';

		$bday = '';
		if( isset($vCard->BDAY))
			$bday = trim((string)$vCard->BDAY);
		if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $bday, $birthdayParts)
			&& checkdate((int) $birthdayParts[2], (int) $birthdayParts[3], (int) $birthdayParts[1]))
			$rdata['birthday'] = $birthdayParts[1].'-'.$birthdayParts[2].'-'.$birthdayParts[3];

		if(isset($vCard->NOTE))
			$rdata['note_public'] = trim((string)$vCard->NOTE);

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$rdata['fk_pays'] = $this->_getCountryIdFromLabel($rdata['_country_label']);
		}

		debug_log('parsed contact UID '.$rdata['_uid']);

		return $rdata;
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataMember($cardData, $mode) {

		debug_log('_parseDataMember('.strlen((string) $cardData).' bytes)');

		$rdata = array(
			'lastname' => '', 'firstname' => '', 'civility' => '', 'phone' => '', 'phone_perso' => '',
			'phone_mobile' => '', 'email' => '', 'url' => '', 'socialnetworks' => '',
			'address' => '', 'town' => '', 'zip' => '', 'country' => 0,
			'birth' => null, 'note_public' => '', 'photo' => '',
		);

		$vCard = $this->_readVCard($cardData);
		$rdata['_category_labels'] = $this->_extractCategoryLabels($vCard);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = isset($vCard->UID) ? trim((string) $vCard->UID) : '';
		$this->_validateCardUid($rdata['_uid']);
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = isset($vCard->N) ? $vCard->N->getParts() : array();
		if(isset($names[0]) && trim((string)$names[0])!='')
			$rdata['lastname'] = (string)$names[0];
		if($rdata['lastname']=='' && isset($vCard->FN) && trim((string)$vCard->FN)!='')
			$rdata['lastname'] = (string)$vCard->FN;
		if($rdata['lastname']=='' && isset($names[1]) && trim((string)$names[1])!='')
			$rdata['lastname'] = (string)$names[1];
		if($rdata['lastname']=='')
			$rdata['lastname'] = "Member ".date('Y-m-d H:i:s');

		if(isset($names[1]))
			$rdata['firstname'] = (string)$names[1];

		if(isset($names[3]))
			$rdata['civility'] = $this->_getCivilityCode((string)$names[3]);

		/*if(isset($vCard->TITLE))
			$rdata['poste'] = (string)$vCard->TITLE;*/

		if(isset($vCard->TEL))
		{
			foreach($vCard->TEL as $tel)
			{
				$teltype = $this->_getPropertyTypes($tel);

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;

				if(isset($teltype['HOME']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone_perso'] = (string)$tel;

				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;
				elseif (!$teltype && $rdata['phone'] === '')
					$rdata['phone'] = (string) $tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if($rdata['email'] === '')
					$rdata['email'] = (string)$email;
				if($this->_isPreferredProperty($email))
					$rdata['email'] = (string)$email;
			}
		}

		if (isset($vCard->URL))
			$rdata['url'] = trim((string) $vCard->URL);

		if(isset($vCard->ADR))
		{
			$hasHomeAddress = false;
			foreach ($vCard->ADR as $candidateAddress) {
				if (isset($this->_getPropertyTypes($candidateAddress)['HOME'])) {
					$hasHomeAddress = true;
					break;
				}
			}
			foreach($vCard->ADR as $adr)
			{
				$adrtype = $this->_getPropertyTypes($adr);
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['HOME']) || (!$hasHomeAddress && $rdata['address'] === ''))
				{
					$rdata['address'] = '';
					$rdata['town'] = '';
					$rdata['zip'] = '';
					$rdata['_country_label'] = '';
					if(isset($adrparts[2]) && !empty($adrparts[2]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[2]))."\n";
					if(isset($adrparts[0]) && !empty($adrparts[0]))
						$rdata['address'].= $adrparts[0]."\n";
					if(isset($adrparts[1]) && !empty($adrparts[1]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[1]))."\n";
					$rdata['address'] = trim($rdata['address']);
					if(isset($adrparts[3]))
						$rdata['town'] = $adrparts[3];
					if(isset($adrparts[5]))
						$rdata['zip'] = $adrparts[5];
					if(isset($adrparts[6]))
						$rdata['_country_label'] = $adrparts[6];
				}
			}
		}

		$this->_storeSocialNetworks($rdata, $this->_extractSocialNetworks($vCard));
		$rdata['_organization'] = isset($vCard->ORG) ? trim((string) $vCard->ORG, " ;\r\n\t") : '';

		$bday = '';
		if( isset($vCard->BDAY))
			$bday = trim((string)$vCard->BDAY);
		if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $bday, $birthdayParts)
			&& checkdate((int) $birthdayParts[2], (int) $birthdayParts[3], (int) $birthdayParts[1]))
			$rdata['birth'] = $birthdayParts[1].'-'.$birthdayParts[2].'-'.$birthdayParts[3];

		if(isset($vCard->NOTE))
			$rdata['note_public'] = trim((string)$vCard->NOTE);

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$rdata['country'] = $this->_getCountryIdFromLabel($rdata['_country_label']);
		}

		debug_log('parsed member UID '.$rdata['_uid']);

		return $rdata;
	}


	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataThirdparty($cardData, $mode) {

		debug_log('_parseDataThirdparty('.strlen((string) $cardData).' bytes)');

		$rdata = array(
			'name_alias' => '', 'phone' => '', 'phone_mobile' => '', 'fax' => '',
			'email' => '', 'url' => '', 'address' => '', 'town' => '', 'zip' => '',
			'fk_pays' => 0, 'socialnetworks' => '', 'note_public' => '',
		);

		$vCard = $this->_readVCard($cardData);
		$rdata['_category_labels'] = $this->_extractCategoryLabels($vCard);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = isset($vCard->UID) ? trim((string) $vCard->UID) : '';
		$this->_validateCardUid($rdata['_uid']);

		if($mode=='C')
			$rdata['status']=1;

		if(isset($vCard->FN) && !empty((string)$vCard->FN))
			$rdata['nom'] = (string)$vCard->FN;
		else
		{
			$rdata['nom']='';
			$names = isset($vCard->N) ? $vCard->N->getParts() : array();
			if(!empty($names[0]))
				$rdata['nom'].= (string)$names[0];
			if(!empty($names[1]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[1]);
			if(!empty($names[2]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[2]);
			if(!empty($names[3]))
				$rdata['nom'] = trim((string)$names[3]." ".$rdata['nom']);
			if(empty($rdata['nom']))
				$rdata['nom'] = "New ".date('Y-m-d H:i:s');
		}

		if(isset($vCard->{'NICKNAME'}))
			$rdata['name_alias'] = (string)$vCard->{'NICKNAME'};

		if(isset($vCard->TEL))
		{
			foreach($vCard->TEL as $tel)
			{
				$teltype = $this->_getPropertyTypes($tel);

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;
				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;

				if(isset($teltype['FAX']))
					$rdata['fax'] = (string)$tel;
				elseif (!$teltype && $rdata['phone'] === '')
					$rdata['phone'] = (string) $tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if($rdata['email'] === '')
					$rdata['email'] = (string)$email;
				if($this->_isPreferredProperty($email))
					$rdata['email'] = (string)$email;
			}
		}

		if(isset($vCard->URL))
			$rdata['url'] = (string)$vCard->URL;

		if(isset($vCard->ADR))
		{
			foreach($vCard->ADR as $adr)
			{
				$adrtype = $this->_getPropertyTypes($adr);
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['WORK']) || $rdata['address'] === '')
				{
					$rdata['address'] = '';
					$rdata['town'] = '';
					$rdata['zip'] = '';
					$rdata['_country_label'] = '';
					if(isset($adrparts[2]) && !empty($adrparts[2]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[2]))."\n";
					if(isset($adrparts[0]) && !empty($adrparts[0]))
						$rdata['address'].= $adrparts[0]."\n";
					if(isset($adrparts[1]) && !empty($adrparts[1]))
						$rdata['address'].= str_replace(' | ',"\n",trim($adrparts[1]))."\n";
					$rdata['address'] = trim($rdata['address']);
					if(isset($adrparts[3]))
						$rdata['town'] = $adrparts[3];
					if(isset($adrparts[5]))
						$rdata['zip'] = $adrparts[5];
					if(isset($adrparts[6]))
						$rdata['_country_label'] = $adrparts[6];
				}
			}
		}

		$this->_storeSocialNetworks($rdata, $this->_extractSocialNetworks($vCard));


		if(isset($vCard->NOTE))
		{
			$tmp 	= (string)$vCard->NOTE;
			$arrNote = array();
			$arrTmp = explode("\n", $tmp);
			foreach($arrTmp as $line)
			{
				if (mb_strpos($line, '💼', 0, 'UTF-8')!==0
					&& mb_strpos($line, '??', 0, 'UTF-8')!==0				// 💼 could be converted in ?? if utf8 char is truncated on 2 VCal lines
					&& mb_strpos($line, '*DOLIBARR-', 0, 'UTF-8')===false)
				{
					$noteline = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD",$line); // remove utf8mb4 chars
					$arrNote[] = $noteline;
				}
			}
			$rdata['note_public'] = trim(implode("\n", $arrNote));
		}

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$rdata['fk_pays'] = $this->_getCountryIdFromLabel($rdata['_country_label']);
		}

		debug_log('parsed third-party UID '.$rdata['_uid']);

		return $rdata;
	}

	/** Populate Dolibarr's native Contact object from normalized vCard data. */
	private function _applyContactData($contact, array $data)
	{
		$contact->lastname = (string) $data['lastname'];
		$contact->firstname = (string) $data['firstname'];
		$contact->civility_code = (string) $data['civility'];
		$contact->civility_id = (string) $data['civility'];
		$contact->poste = (string) $data['poste'];
		$contact->priv = (int) $data['priv'];
		$contact->phone_pro = (string) $data['phone'];
		$contact->phone_perso = (string) $data['phone_perso'];
		$contact->phone_mobile = (string) $data['phone_mobile'];
		$contact->fax = (string) $data['fax'];
		$contact->email = (string) $data['email'];
		$contact->url = (string) $data['url'];
		$contact->address = (string) $data['address'];
		$contact->town = (string) $data['town'];
		$contact->zip = (string) $data['zip'];
		$contact->country_id = (int) $data['fk_pays'];
		$contact->socialnetworks = $this->_decodeSocialNetworks($data['socialnetworks']);
		$contact->birthday = $data['birthday'] ? strtotime((string) $data['birthday']) : null;
		$contact->note_public = (string) $data['note_public'];
		$contact->photo = (string) $data['photo'];
		$contact->status = 1;
		$contact->statut = 1;
		// -1 is the native Contact sentinel for removing a third-party link.
		$contact->socid = -1;
		$contact->fk_soc = null;
		if (!empty($data['_organization'])) {
			$thirdPartyId = $this->_resolveThirdPartyId($data['_organization']);
			if ($thirdPartyId > 0) {
				$contact->socid = $thirdPartyId;
				$contact->fk_soc = $thirdPartyId;
			}
		}
	}

	/** Populate Dolibarr's native third-party object without touching accounting fields. */
	private function _applyThirdPartyData($thirdParty, array $data)
	{
		$thirdParty->name = (string) $data['nom'];
		$thirdParty->nom = (string) $data['nom'];
		$thirdParty->name_alias = (string) $data['name_alias'];
		$thirdParty->phone = (string) $data['phone'];
		$thirdParty->phone_mobile = (string) $data['phone_mobile'];
		$thirdParty->fax = (string) $data['fax'];
		$thirdParty->email = (string) $data['email'];
		$thirdParty->url = (string) $data['url'];
		$thirdParty->address = (string) $data['address'];
		$thirdParty->town = (string) $data['town'];
		$thirdParty->zip = (string) $data['zip'];
		$thirdParty->country_id = (int) $data['fk_pays'];
		$thirdParty->socialnetworks = $this->_decodeSocialNetworks($data['socialnetworks']);
		$thirdParty->note_public = (string) $data['note_public'];
		$thirdParty->status = 1;
	}

	/** Populate Dolibarr's native member object from normalized vCard data. */
	private function _applyMemberData($member, array $data)
	{
		$member->lastname = (string) $data['lastname'];
		$member->firstname = (string) $data['firstname'];
		$member->civility_id = (string) $data['civility'];
		$member->phone = (string) $data['phone'];
		$member->phone_perso = (string) $data['phone_perso'];
		$member->phone_mobile = (string) $data['phone_mobile'];
		$member->email = (string) $data['email'];
		$member->url = (string) $data['url'];
		$member->socialnetworks = $this->_decodeSocialNetworks($data['socialnetworks']);
		$member->address = (string) $data['address'];
		$member->town = (string) $data['town'];
		$member->zip = (string) $data['zip'];
		$member->country_id = (int) $data['country'];
		$member->birth = $data['birth'] ? strtotime((string) $data['birth']) : null;
		$member->note_public = (string) $data['note_public'];
		$member->photo = (string) $data['photo'];
		$member->statut = 1;
		$member->status = 1;
		$member->socid = 0;
		if (!empty($data['_organization'])) {
			$thirdPartyId = $this->_resolveThirdPartyId($data['_organization']);
			if ($thirdPartyId > 0) {
				$member->socid = $thirdPartyId;
			}
		}
	}

	/**
	 * Returns all cards for a specific addressbook id.
	 *
	 * This method should return the following properties for each card:
	 *   * carddata - raw vcard data
	 *   * uri - Some unique url
	 *   * lastmodified - A unix timestamp
	 *
	 * It's recommended to also return the following properties:
	 *   * etag - A unique etag. This must change every time the card changes.
	 *   * size - The size of the card in bytes.
	 *
	 * If these last two properties are provided, less time will be spent
	 * calculating them. If they are specified, you can also ommit carddata.
	 * This may speed up certain requests, especially with large cards.
	 *
	 * @param mixed $addressbookId
	 * @return array
	 */
	function getCards($addressbookId) {

		debug_log("getCards( $addressbookId )");

		$cards = [] ;

		if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'read'))
		{
			$sql = $this->_getSqlContacts();
			$result = $this->db->query($sql);
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$carddata = $this->_contactToVCard($obj);

					$cards[] = [
						// 'carddata' => $carddata,  not necessary because etag+size are present
						'uri' => $this->_getCardObjectUri($obj, 'ct'),
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}

		if (CDAV_THIRD_SYNC > 0 && intval($addressbookId) >= CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId) < (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('societe', 'read'))
		{
			$sql = $this->_getSqlThirdparties();
			$result = $this->db->query($sql);
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$carddata = $this->_thirdpartyToVCard($obj);

					$cards[] = [
						// 'carddata' => $carddata,  not necessary because etag+size are present
						'uri' => $this->_getCardObjectUri($obj, 'th'),
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}

		if (CDAV_MEMBER_SYNC > 0 && intval($addressbookId) >= (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId) < (3 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('adherent', 'read'))
		{
			$sql = $this->_getSqlMembers();
			$result = $this->db->query($sql);
			if ($result)
			{
				while ($obj = $this->db->fetch_object($result))
				{
					$carddata = $this->_memberToVCard($obj);

					$cards[] = [
						// 'carddata' => $carddata,  not necessary because etag+size are present
						'uri' => $this->_getCardObjectUri($obj, 'mb'),
						'lastmodified' => strtotime($obj->lastupd),
						'etag' => '"'.md5($carddata).'"',
						'size' => strlen($carddata)
					];
				}
			}
		}
		return $cards;
	}

	/**
	 * Returns a specfic card.
	 *
	 * The same set of properties must be returned as with getCards. The only
	 * exception is that 'carddata' is absolutely required.
	 *
	 * If the card does not exist, you must return false.
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @return array
	 */
	function getCard($addressbookId, $cardUri) {

		debug_log("getCard( $addressbookId , $cardUri )");

		if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'read'))
		{
			$sqlWhere = $this->_getCardSqlWhere('p', 'ct', $cardUri);

			$sql = $this->_getSqlContacts($sqlWhere);

			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_contactToVCard($obj);

				$card = [
					'id' => (int) $obj->rowid,
					'carddata' => $carddata,
					'uri' => $this->_getCardObjectUri($obj, 'ct'),
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}

		if (CDAV_THIRD_SYNC > 0 && intval($addressbookId) >= CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId) < (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('societe', 'read'))
		{
			$sqlWhere = $this->_getCardSqlWhere('s', 'th', $cardUri);

			$sql = $this->_getSqlThirdparties($sqlWhere);
			debug_log($sql);
			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_thirdpartyToVCard($obj);

				$card = [
					'id' => (int) $obj->rowid,
					'carddata' => $carddata,
					'uri' => $this->_getCardObjectUri($obj, 'th'),
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}

		if (CDAV_MEMBER_SYNC > 0 && intval($addressbookId) >= (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId) < (3 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('adherent', 'read'))
		{
			$sqlWhere = $this->_getCardSqlWhere('p', 'mb', $cardUri);

			$sql = $this->_getSqlMembers($sqlWhere);
			debug_log($sql);
			$result = $this->db->query($sql);
			if ($result && $obj = $this->db->fetch_object($result))
			{
				$carddata = $this->_memberToVCard($obj);

				$card = [
					'id' => (int) $obj->rowid,
					'carddata' => $carddata,
					'uri' => $this->_getCardObjectUri($obj, 'mb'),
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($carddata).'"',
					'size' => strlen($carddata)
				];

				return $card;
			}
		}
		return false;
	}

	/**
	 * Returns a list of cards.
	 *
	 * This method should work identical to getCard, but instead return all the
	 * cards in the list as an array.
	 *
	 * If the backend supports this, it may allow for some speed-ups.
	 *
	 * @param mixed $addressBookId
	 * @param array $uris
	 * @return array
	 */
	function getMultipleCards($addressbookId, array $uris) {

		debug_log("getMultipleCards( $addressbookId , ".implode('; ',$uris)." )");

		$cards = [];
		foreach ($uris as $cardUri) {
			$card = $this->getCard($addressbookId, $cardUri);
			if ($card !== false) {
				$cards[] = $card;
			}
		}
		return $cards;
	}

	/**
	 * Creates a new card.
	 *
	 * The addressbook id will be passed as the first argument. This is the
	 * same id as it is returned from the getAddressBooksForUser method.
	 *
	 * The cardUri is a base uri, and doesn't include the full path. The
	 * cardData argument is the vcard body, and is passed as a string.
	 *
	 * It is possible to return an ETag from this method. This ETag is for the
	 * newly created resource, and must be enclosed with double quotes (that
	 * is, the string itself must contain the double quotes).
	 *
	 * You should only return the ETag if you store the carddata as-is. If a
	 * subsequent GET request on the same card does not have the same body,
	 * byte-by-byte and you did return an ETag here, clients tend to get
	 * confused.
	 *
	 * If you don't return an ETag, you can just return null.
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @param string $cardData
	 * @return string|null
	 */
	function createCard($addressbookId, $cardUri, $cardData) {
		debug_log("createContactObject( $addressbookId , $cardUri )");
		$this->_validateCardUri($cardUri);
		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CardDAV transaction');
		}
			try {
				$this->_lockAddressBookOwner($addressbookId);

				if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'write'))
			{
				$rdata = $this->_parseDataContact($cardData, 'C');
				$this->_assertCardUidAvailable('ct', $rdata['_uid']);
				$rdata['ref_ext'] = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 255);

			$gdim = $this->_decodeVCardPhoto($rdata['_photo_bin']);
			if ($gdim !== false) {
				$rdata['photo'] = 'cdavimage.jpg';
			}

				require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
				$contact = new \Contact($this->db);
				$this->_applyContactData($contact, $rdata);
				$contact->ref_ext = $rdata['ref_ext'];
				$id = $contact->create($this->user);
				if ($id <= 0) {
					throw new \Sabre\DAV\Exception('Unable to create the Dolibarr contact: '.$contact->error);
				}
				// Contact::create()/update() do not persist url in Dolibarr 23.
				// Keep this field on the native CommonObject write path as well.
				if ($contact->setValueFrom('url', $rdata['url'], '', null, 'text', '', $this->user) < 0) {
					throw new \Sabre\DAV\Exception('Unable to save the Dolibarr contact URL: '.$contact->error);
				}
				$this->_storeCardMapping('ct', $id, $cardUri, $rdata['_uid']);
				$this->vcardStore->save('ct', $id, $cardData);
				$this->_syncCategories('contact', $id, $rdata['_category_labels'] ?? array());

			// save photo with jpeg format
			$this->_saveVCardPhoto($gdim, $this->_photoDirectory('contact', $id), $rdata['photo'], $contact);
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV contact');
			}
			return null;
		}

			if (CDAV_THIRD_SYNC > 0 && intval($addressbookId) >= CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId) < (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('societe', 'write'))
			{
				$rdata = $this->_parseDataThirdparty($cardData, 'C');
				$this->_assertCardUidAvailable('th', $rdata['_uid']);
				$rdata['ref_ext'] = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 255);

				require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
				$thirdParty = new \Societe($this->db);
				$this->_applyThirdPartyData($thirdParty, $rdata);
				$thirdParty->ref_ext = $rdata['ref_ext'];
				$id = $thirdParty->create($this->user);
				if ($id <= 0) {
					throw new \Sabre\DAV\Exception('Unable to create the Dolibarr third party: '.$thirdParty->error);
				}
				if ($thirdParty->add_commercial($this->user, (int) $this->user->id) <= 0) {
					throw new \Sabre\DAV\Exception('Unable to assign the Dolibarr third party to its user');
				}
				$this->_storeCardMapping('th', $id, $cardUri, $rdata['_uid']);
				$this->vcardStore->save('th', $id, $cardData);
				$this->_syncCategories('thirdparty', $id, $rdata['_category_labels'] ?? array());

			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV third party');
			}
			return null;
		}

			if (CDAV_MEMBER_SYNC > 0 && intval($addressbookId) >= (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId) < (3 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('adherent', 'write'))
			{
				$rdata = $this->_parseDataMember($cardData, 'C');
				$this->_assertCardUidAvailable('mb', $rdata['_uid']);
				$gdim = $this->_decodeVCardPhoto($rdata['_photo_bin']);
				if ($gdim !== false) {
					$rdata['photo'] = 'cdavimage.jpg';
				}
				$memberType = $this->_getDefaultMemberType();
				$rdata['ref_ext'] = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 128);

				require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
				$member = new \Adherent($this->db);
				$this->_applyMemberData($member, $rdata);
				$member->typeid = $memberType['id'];
				$member->morphy = $memberType['morphy'];
				$member->ref_ext = $rdata['ref_ext'];
				if (!getDolGlobalString('ADHERENT_LOGIN_NOT_REQUIRED')) {
					$member->login = 'cdav-'.substr(hash('sha256', $cardUri.'|'.$rdata['_uid']), 0, 20);
				}
				$id = $member->create($this->user);
				if ($id <= 0) {
					throw new \Sabre\DAV\Exception('Unable to create the Dolibarr member: '.$member->error);
				}
				if ($member->setThirdPartyId((int) $member->socid) < 0) {
					throw new \Sabre\DAV\Exception('Unable to save the Dolibarr member third-party link: '.$member->error);
				}
				$this->_storeCardMapping('mb', $id, $cardUri, $rdata['_uid']);
				$this->vcardStore->save('mb', $id, $cardData);
				$this->_syncCategories('member', $id, $rdata['_category_labels'] ?? array());
				$this->_saveVCardPhoto($gdim, $this->_photoDirectory('member', $id), $rdata['photo'], $member);

			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV member');
			}
			return null;
		}

		throw new Forbidden('Not allowed to create cards in this address book');
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Updates a card.
	 *
	 * The addressbook id will be passed as the first argument. This is the
	 * same id as it is returned from the getAddressBooksForUser method.
	 *
	 * The cardUri is a base uri, and doesn't include the full path. The
	 * cardData argument is the vcard body, and is passed as a string.
	 *
	 * It is possible to return an ETag from this method. This ETag should
	 * match that of the updated resource, and must be enclosed with double
	 * quotes (that is: the string itself must contain the actual quotes).
	 *
	 * You should only return the ETag if you store the carddata as-is. If a
	 * subsequent GET request on the same card does not have the same body,
	 * byte-by-byte and you did return an ETag here, clients tend to get
	 * confused.
	 *
	 * If you don't return an ETag, you can just return null.
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @param string $cardData
	 * @return string|null
	 */
	function updateCard($addressbookId, $cardUri, $cardData) {
		debug_log("updateContactObject( $addressbookId , $cardUri )");
		$this->_validateCardUri($cardUri);
		$obsoletePhoto = null;
		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CardDAV transaction');
		}
			try {
			$this->_lockAddressBookOwner($addressbookId);
			$this->_assertCardVersion($addressbookId, $cardUri);

			if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'write'))
		{
			$rdata = $this->_parseDataContact($cardData, 'U');
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Contact not found');
			}
				$contactid = (int) $existing['id'];

			$gdim = $this->_decodeVCardPhoto($rdata['_photo_bin']);
			if ($gdim !== false) {
				$rdata['photo'] = 'cdavimage.jpg';
			}

				require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
				$contact = new \Contact($this->db);
				if ($contact->fetch($contactid, $this->user) <= 0) {
					throw new \Sabre\DAV\Exception\NotFound('Contact not found');
				}
				if ($gdim === false && !empty($contact->photo)) {
					$obsoletePhoto = array($this->_photoFilePath('contact', $contactid, $contact->photo), $contact);
				}
				$this->_assertCardUidAvailable('ct', $rdata['_uid'], $contactid);
				$rdata['socialnetworks'] = json_encode(
					$this->_mergeSocialNetworks($contact->socialnetworks, $rdata['_socialnetworks_patch'] ?? array()),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
				$this->_applyContactData($contact, $rdata);
				if ($this->_decodeCardExternalRef((string) $contact->ref_ext) !== null) {
					$contact->ref_ext = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 255);
				}
				$res = $contact->update($contactid, $this->user);
				if ($res <= 0) {
					throw new \Sabre\DAV\Exception('Unable to update the Dolibarr contact: '.$contact->error);
				}
				if ($contact->setValueFrom('url', $rdata['url'], '', null, 'text', '', $this->user) < 0) {
					throw new \Sabre\DAV\Exception('Unable to update the Dolibarr contact URL: '.$contact->error);
				}
				$this->_storeCardMapping('ct', $contactid, $cardUri, $rdata['_uid']);
				$this->vcardStore->save('ct', $contactid, $cardData);
				$this->_syncCategories('contact', $contactid, $rdata['_category_labels'] ?? array());

			// save photo with jpeg format
			$this->_saveVCardPhoto($gdim, $this->_photoDirectory('contact', $contactid), $rdata['photo'], $contact);
		}

		if (CDAV_THIRD_SYNC > 0 && intval($addressbookId) >= CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId) < (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('societe', 'write'))
		{
			$rdata = $this->_parseDataThirdparty($cardData, 'U');
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Third party not found');
			}
				$socid = (int) $existing['id'];

				require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
				$thirdParty = new \Societe($this->db);
				if ($thirdParty->fetch($socid) <= 0) {
					throw new \Sabre\DAV\Exception\NotFound('Third party not found');
				}
				$this->_assertCardUidAvailable('th', $rdata['_uid'], $socid);
				$rdata['socialnetworks'] = json_encode(
					$this->_mergeSocialNetworks($thirdParty->socialnetworks, $rdata['_socialnetworks_patch'] ?? array()),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
				$this->_applyThirdPartyData($thirdParty, $rdata);
				if ($this->_decodeCardExternalRef((string) $thirdParty->ref_ext) !== null) {
					$thirdParty->ref_ext = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 255);
				}
				$res = $thirdParty->update($socid, $this->user);
				if ($res < 0) {
					throw new \Sabre\DAV\Exception('Unable to update the Dolibarr third party: '.$thirdParty->error);
				}
				$this->_storeCardMapping('th', $socid, $cardUri, $rdata['_uid']);
				$this->vcardStore->save('th', $socid, $cardData);
				$this->_syncCategories('thirdparty', $socid, $rdata['_category_labels'] ?? array());
		}

			if (CDAV_MEMBER_SYNC > 0 && intval($addressbookId) >= (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId) < (3 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('adherent', 'write'))
			{
				$rdata = $this->_parseDataMember($cardData, 'U');
				$gdim = $this->_decodeVCardPhoto($rdata['_photo_bin']);
				if ($gdim !== false) {
					$rdata['photo'] = 'cdavimage.jpg';
				}
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Member not found');
			}
				$adhid = (int) $existing['id'];

				require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
				$member = new \Adherent($this->db);
				if ($member->fetch($adhid) <= 0) {
					throw new \Sabre\DAV\Exception\NotFound('Member not found');
				}
				if ($gdim === false && !empty($member->photo)) {
					$obsoletePhoto = array($this->_photoFilePath('member', $adhid, $member->photo), $member);
				}
				$this->_assertCardUidAvailable('mb', $rdata['_uid'], $adhid);
				$rdata['socialnetworks'] = json_encode(
					$this->_mergeSocialNetworks($member->socialnetworks, $rdata['_socialnetworks_patch'] ?? array()),
					JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);
				$this->_applyMemberData($member, $rdata);
				if ($this->_decodeCardExternalRef((string) $member->ref_ext) !== null) {
					$member->ref_ext = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 128);
				}
				$res = $member->update($this->user, 0, 1, 1, 1);
				if ($res < 0) {
					throw new \Sabre\DAV\Exception('Unable to update the Dolibarr member: '.$member->error);
				}
				if ($member->setThirdPartyId((int) $member->socid) < 0) {
					throw new \Sabre\DAV\Exception('Unable to update the Dolibarr member third-party link: '.$member->error);
				}
				$this->_storeCardMapping('mb', $adhid, $cardUri, $rdata['_uid']);
				$this->vcardStore->save('mb', $adhid, $cardData);
				$this->_syncCategories('member', $adhid, $rdata['_category_labels'] ?? array());
				$this->_saveVCardPhoto($gdim, $this->_photoDirectory('member', $adhid), $rdata['photo'], $member);
		}

		if (isset($res)) {
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV update');
			}
			if ($obsoletePhoto !== null) {
				$this->_removeVCardPhoto($obsoletePhoto[0], $obsoletePhoto[1]);
			}
			return null;
		}
		throw new Forbidden('Not allowed to update cards in this address book');
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Deletes a card
	 *
	 * @param mixed $addressBookId
	 * @param string $cardUri
	 * @return bool
	 */
	function deleteCard($addressbookId, $cardUri) {

		debug_log("deleteContactObject( $addressbookId , $cardUri )");
		$this->_validateCardUri($cardUri);
		// Security policy: DAV deletion only changes the native Dolibarr status.
		// Physical deletion of business records is deliberately unavailable here.
		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CardDAV delete transaction');
		}
			try {
			$this->_lockAddressBookOwner($addressbookId);
			$this->_assertCardVersion($addressbookId, $cardUri);

			if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'delete'))
		{
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Contact not found');
			}
			$contactid = (int) $existing['id'];

			require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
			$contact = new \Contact($this->db);
			if ($contact->fetch($contactid, $this->user) <= 0 || $contact->setstatus(0) < 0) {
				throw new \Sabre\DAV\Exception('Unable to deactivate the Dolibarr contact: '.$contact->error);
			}
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV contact deletion');
			}
			return true;
		}

		if (CDAV_THIRD_SYNC > 0 && intval($addressbookId) >= CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId) < (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('societe', 'delete'))
		{

			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Third party not found');
			}
			$socid = (int) $existing['id'];

			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$thirdParty = new \Societe($this->db);
			if ($thirdParty->fetch($socid) <= 0) {
				throw new \Sabre\DAV\Exception\NotFound('Third party not found');
			}
			$thirdParty->status = 0;
			if ($thirdParty->update($socid, $this->user) < 0) {
				throw new \Sabre\DAV\Exception('Unable to deactivate the Dolibarr third party: '.$thirdParty->error);
			}
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV third-party deletion');
			}
			return true;
		}

		if (CDAV_MEMBER_SYNC > 0 && intval($addressbookId) >= (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId) < (3 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('adherent', 'delete'))
		{

			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Member not found');
			}
			$adhid = (int) $existing['id'];

			require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
			$member = new \Adherent($this->db);
			if ($member->fetch($adhid) <= 0) {
				throw new \Sabre\DAV\Exception\NotFound('Member not found');
			}
			if ($member->resiliate($this->user) < 0) {
				throw new \Sabre\DAV\Exception('Unable to deactivate the Dolibarr member: '.$member->error);
			}
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV member deletion');
			}
			return true;
		}

		throw new Forbidden('Not allowed to delete cards from this address book');
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * The getChanges method returns all the changes that have happened, since
	 * the specified syncToken in the specified address book.
	 *
	 * This function should return an array, such as the following:
	 *
	 * [
	 *   'syncToken' => 'The current synctoken',
	 *   'added'   => [
	 *	  'new.txt',
	 *   ],
	 *   'modified'   => [
	 *	  'updated.txt',
	 *   ],
	 *   'deleted' => [
	 *	  'foo.php.bak',
	 *	  'old.txt'
	 *   ]
	 * ];
	 *
	 * The returned syncToken property should reflect the *current* syncToken
	 * of the addressbook, as reported in the {http://sabredav.org/ns}sync-token
	 * property. This is needed here too, to ensure the operation is atomic.
	 *
	 * If the $syncToken argument is specified as null, this is an initial
	 * sync, and all members should be reported.
	 *
	 * The modified property is an array of nodenames that have changed since
	 * the last token.
	 *
	 * The deleted property is an array with nodenames, that have been deleted
	 * from collection.
	 *
	 * The $syncLevel argument is basically the 'depth' of the report. If it's
	 * 1, you only have to report changes that happened only directly in
	 * immediate descendants. If it's 2, it should also include changes from
	 * the nodes below the child collections. (grandchildren)
	 *
	 * The $limit argument allows a client to specify how many results should
	 * be returned at most. If the limit is not specified, it should be treated
	 * as infinite.
	 *
	 * If the limit (infinite or not) is higher than you're willing to return,
	 * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
	 *
	 * If the syncToken is expired (due to data cleanup) or unknown, you must
	 * return null.
	 *
	 * The limit is 'suggestive'. You are free to ignore it.
	 *
	 * @param string $addressBookId
	 * @param string $syncToken
	 * @param int $syncLevel
	 * @param int $limit
	 * @return array
	 */
	function getChangesForAddressBook($addressbookId, $syncToken, $syncLevel, $limit = null) {
		if ((int) $syncLevel !== 1) {
			return null;
		}
		$objects = array();
		foreach ($this->getCards((int) $addressbookId) as $card) {
			$objects[(string) $card['uri']] = (string) ($card['etag'] ?? '');
		}
		return $this->syncStore->getChanges('card', (int) $addressbookId, $syncToken, $limit, $objects);
	}

}
