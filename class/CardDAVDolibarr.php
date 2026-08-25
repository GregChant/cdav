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
class Dolibarr extends AbstractBackend {
	/** Change this value whenever the generated CardDAV representation changes. */
	private const CARD_SERIALIZATION_VERSION = '2026-08-html-text-civility-v2';

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

	/**
	 * Returns the list of addressbooks for a specific user.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getAddressBooksForUser($principalUri) {
		debug_log("getAddressBooksForUser( $principalUri )");
		$companyName = getDolGlobalString('MAIN_INFO_SOCIETE_NOM', 'Dolibarr');

		$addressBooks = [];

		$addressBooks[] = [
			'id'														  => $this->user->id,
			'uri'														  => 'default',
			'principaluri'												  => $principalUri,
			'{DAV:}displayname'											  => $this->langs->transnoentitiesnoconv('CDavContactsAddressBookName', $companyName),
			'{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->langs->transnoentitiesnoconv('CDavContactsAddressBookDescription', $companyName, $this->user->login),
			'{http://calendarserver.org/ns/}getctag'					  => $this->_getAddressBookCollectionTag('contact'),
		];
		if (!$this->_hasRight('societe', 'contact', 'read')) {
			array_pop($addressBooks);
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
		}

		return $addressBooks;

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
				$tokens[] = ((int) $row->rowid).':'.((string) $row->lastupd).':'.((string) $row->category_ids).':'.((string) $row->ref_ext);
			}
		}
		sort($tokens, SORT_STRING);
		return sha1(CDAV_URI_KEY.'|'.self::CARD_SERIALIZATION_VERSION.'|'.$type.'|'.implode('|', $tokens));
	}

	private function _getCardObjectUri($obj, $type)
	{
		$external = $this->_decodeCardExternalRef((string) ($obj->ref_ext ?? ''));
		if ($external !== null) {
			return $external['uri'];
		}
		return ((int) $obj->rowid).'-'.$type.'-'.CDAV_URI_KEY;
	}

	private function _getCardSourceUid($obj, $type)
	{
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
		return ' AND ('.$alias.'.ref_ext = \'cdav:'.$this->db->escape((string) $cardUri).'\'
			OR '.$alias.'.ref_ext LIKE \'cdav2:'.$this->db->escape($encodedUri).'.%\')';
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
		$networks = array_filter($networks, static fn($value) => trim((string) $value) !== '');
		if ($networks) {
			$data['socialnetworks'] = json_encode($networks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}
	}

	private function _isPreferredProperty($property)
	{
		if (isset($property['PREF'])) {
			return true;
		}
		foreach ($property['TYPE'] as $type) {
			if (strtoupper((string) $type) === 'PREF') {
				return true;
			}
		}
		return false;
	}

	private function _syncCategories($type, $objectId, array $labels)
	{
		if (!isModEnabled('categorie') || !$this->_hasRight('categorie', 'read')) {
			return;
		}

		if ($type === 'contact') {
			$table = 'categorie_contact';
			$objectField = 'fk_socpeople';
			$categoryTypes = array(4);
		} elseif ($type === 'member') {
			$table = 'categorie_member';
			$objectField = 'fk_member';
			$categoryTypes = array(3);
		} else {
			$table = 'categorie_societe';
			$objectField = 'fk_soc';
			$categoryTypes = array(1, 2);
		}

		$quotedLabels = array();
		foreach (array_unique($labels) as $label) {
			if (trim((string) $label) !== '') {
				$quotedLabels[] = '\''.$this->db->escape(trim((string) $label)).'\'';
			}
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'categorie
			WHERE type IN ('.implode(',', $categoryTypes).')
			AND entity IN ('.getEntity('category').')';
		if ($quotedLabels) {
			$sql .= ' AND label IN ('.implode(',', $quotedLabels).')';
		} else {
			$sql .= ' AND 1 = 0';
		}
		$result = $this->db->query($sql);
		$categoryIds = array();
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to resolve Dolibarr categories');
		}
		while ($row = $this->db->fetch_object($result)) {
			$categoryIds[] = (int) $row->rowid;
		}
		if ($type === 'contact' && (int) CDAV_CONTACT_TAG > 0) {
			$categoryIds[] = (int) CDAV_CONTACT_TAG;
		}

		if (!$this->db->query('DELETE rel FROM '.MAIN_DB_PREFIX.$table.' rel
			INNER JOIN '.MAIN_DB_PREFIX.'categorie cat ON cat.rowid = rel.fk_categorie
			WHERE rel.'.$objectField.' = '.((int) $objectId).'
			AND cat.type IN ('.implode(',', $categoryTypes).')')) {
			throw new \Sabre\DAV\Exception('Unable to update Dolibarr categories');
		}
		foreach (array_unique($categoryIds) as $categoryId) {
			if (!$this->db->query('INSERT IGNORE INTO '.MAIN_DB_PREFIX.$table.' (fk_categorie, '.$objectField.')
				VALUES ('.((int) $categoryId).', '.((int) $objectId).')')) {
				throw new \Sabre\DAV\Exception('Unable to assign a Dolibarr category');
			}
		}
	}

	private function _getDefaultMemberType()
	{
		$sql = 'SELECT rowid, morphy FROM '.MAIN_DB_PREFIX.'adherent_type
			WHERE statut = 0 AND entity IN ('.getEntity('adherent').')
			ORDER BY rowid LIMIT 1';
		$result = $this->db->query($sql);
		if ($result && ($row = $this->db->fetch_object($result))) {
			$morphy = in_array((string) $row->morphy, array('phy', 'mor'), true) ? (string) $row->morphy : 'phy';
			return array('id' => (int) $row->rowid, 'morphy' => $morphy);
		}
		throw new \Sabre\DAV\Exception('No active Dolibarr member type is available');
	}

	private function _updateCardExternalRef($table, $objectId, $cardUri, $sourceUid, $maxLength)
	{
		$result = $this->db->query('SELECT ref_ext FROM '.MAIN_DB_PREFIX.$table.' WHERE rowid = '.((int) $objectId));
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to read the external CardDAV identifier');
		}
		$row = $result ? $this->db->fetch_object($result) : null;
		if ($row && $this->_decodeCardExternalRef((string) $row->ref_ext) !== null) {
			$refExt = $this->_encodeCardExternalRef($cardUri, $sourceUid, $maxLength);
			if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX.$table.' SET ref_ext = \''.$this->db->escape($refExt).'\'
				WHERE rowid = '.((int) $objectId))) {
				throw new \Sabre\DAV\Exception('Unable to preserve the external CardDAV identifier');
			}
		}
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
		$sql = 'SELECT p.*, co.label country_label, GREATEST(COALESCE(s.tms, p.tms), p.tms) lastupd, s.code_client soc_code_client, s.code_fournisseur soc_code_fournisseur,
					s.nom soc_nom, s.name_alias soc_name_alias, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.fax soc_fax,
					s.email soc_email, s.url soc_url, s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cc.fk_categorie ORDER BY cc.fk_categorie ASC SEPARATOR \',\') category_ids,
					s.logo
				FROM '.MAIN_DB_PREFIX.'socpeople as p
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as co ON co.rowid = p.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = p.fk_soc
				LEFT JOIN '.MAIN_DB_PREFIX.'c_country as cos ON cos.rowid = s.fk_pays
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie_contact as cc ON cc.fk_socpeople = p.rowid
				LEFT JOIN '.MAIN_DB_PREFIX.'categorie as cat ON cat.rowid = cc.fk_categorie
				WHERE p.entity IN ('.getEntity('societe').')
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
		$sql = 'SELECT p.*, co.label country_label, GREATEST(COALESCE(s.tms, p.tms), p.tms) lastupd, s.code_client soc_code_client, s.code_fournisseur soc_code_fournisseur,
					COALESCE(s.nom, p.societe) soc_nom, s.name_alias soc_name_alias, s.address soc_address, s.zip soc_zip, s.town soc_town, cos.label soc_country_label, s.phone soc_phone, s.fax soc_fax,
					s.email soc_email, s.url soc_url, s.client soc_client, s.fournisseur soc_fournisseur, s.note_private soc_note_private, s.note_public soc_note_public,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cc.fk_categorie ORDER BY cc.fk_categorie ASC SEPARATOR \',\') category_ids
				FROM '.MAIN_DB_PREFIX.'adherent as p
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
		$sql = 'SELECT s.*, co.label country_label, s.tms lastupd, cfj.libelle as forme_juridique,
					GROUP_CONCAT(DISTINCT cat.label ORDER BY cat.label ASC SEPARATOR \',\') category_label,
					GROUP_CONCAT(DISTINCT cs.fk_categorie ORDER BY cs.fk_categorie ASC SEPARATOR \',\') category_ids
				FROM '.MAIN_DB_PREFIX.'societe as s
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
		global $conf;
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
		if(!empty($obj->soc_url))
		{
			if(strpos($obj->soc_url,'://')===false)
				$carddata.="URL:http://".trim($obj->soc_url)."\n";
			else
				$carddata.="URL:".trim($obj->soc_url)."\n";
		}
		if(!empty($socialNetworks['jabber']))
			$carddata.="X-JABBER:".str_replace(';','\;',$socialNetworks['jabber'])."\n";
		if(!empty($socialNetworks['skype']))
			$carddata.="X-SKYPE:".str_replace(';','\;',$socialNetworks['skype'])."\n";
		if(!empty($obj->birthday))
			$carddata.="BDAY:".str_replace(';','\;',$obj->birthday)."\n";
		if($notePublic !== '')
			$carddata.="NOTE;CHARSET=UTF-8:".$this->_escapeVCardText($notePublic)."\n";
		if(!empty($obj->photo))
		{
			$photofile = $conf->societe->dir_output."/contact/".$obj->rowid."/photos/".$obj->photo;
			if(!file_exists($photofile) && !empty($obj->logo) && !empty($obj->fk_soc))
			{
				// fallback image search thirdparty if possible
				$photofile = $conf->societe->dir_output . '/' . $obj->fk_soc . '/logos/' . getImageFileNameForSize($obj->logo,''); //, '_mini' getImageFileNameForSize include the thumbs
			}

			if(file_exists($photofile))
			{
				if(function_exists('exif_imagetype'))
				{
					$image_type = image_type_to_mime_type(exif_imagetype($photofile));
					$image_type = strtoupper(substr($image_type, strpos($image_type, '/')+1));
				}
				else
				{
					$image_type='';
					switch(strtolower(substr($obj->photo,-4)))
					{
						case '.jpg':
						case 'jpeg':
							$image_type='JPEG';
							break;
						case '.gif':
							$image_type='GIF';
							break;
						case '.png':
							$image_type='PNG';
							break;
						case '.bmp':
							$image_type='BMP';
							break;
						case '.tif':
						case 'tiff':
							$image_type='TIFF';
							break;
					}
				}
				if(!empty($image_type))
				{
					$photodata = wordwrap("PHOTO;ENCODING=b;TYPE=".$image_type.":".base64_encode(file_get_contents($photofile)),72,"\n",true);
					$photodata = trim(str_replace("\n", "\n ", $photodata));
					$carddata .= $photodata."\n";
				}
			}
		}
   		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";
		return $this->_normalizeVCardData($carddata);
	}

	/**
	 * Convert member row to VCard string
	 *
	 * @param row object
	 * @return string
	 */
	protected function _memberToVCard($obj)
	{
		global $conf;
		$obj = $this->_normalizeDatabaseRow($obj);
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
		if(!empty($obj->soc_url))
		{
			if(strpos($obj->soc_url,'://')===false)
				$carddata.="URL:https://".trim($obj->soc_url)."\n";
			else
				$carddata.="URL:".trim($obj->soc_url)."\n";
		}
		if(!empty($obj->birth))
			$carddata.="BDAY;VALUE=DATE:".str_replace(';','\;',date('Ymd',strtotime($obj->birth)))."\n";
		if($notePublic !== '')
			$carddata.="NOTE;CHARSET=UTF-8:".$this->_escapeVCardText($notePublic)."\n";
		if(!empty($obj->photo))
		{
			$photofile = $conf->adherent->dir_output."/member/".$obj->rowid."/photos/".$obj->photo;
			if(file_exists($photofile))
			{
				if(function_exists('exif_imagetype'))
				{
					$image_type = image_type_to_mime_type(exif_imagetype($photofile));
					$image_type = strtoupper(substr($image_type, strpos($image_type, '/')+1));
				}
				else
				{
					$image_type='';
					switch(strtolower(substr($obj->photo,-4)))
					{
						case '.jpg':
						case 'jpeg':
							$image_type='JPEG';
							break;
						case '.gif':
							$image_type='GIF';
							break;
						case '.png':
							$image_type='PNG';
							break;
						case '.bmp':
							$image_type='BMP';
							break;
						case '.tif':
						case 'tiff':
							$image_type='TIFF';
							break;
					}
				}
				if(!empty($image_type))
				{
					$photodata = wordwrap("PHOTO;ENCODING=b;TYPE=".$image_type.":".base64_encode(file_get_contents($photofile)),72,"\n",true);
					$photodata = trim(str_replace("\n", "\n ", $photodata));
					$carddata .= $photodata."\n";
				}
			}
		}
		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";
		return $this->_normalizeVCardData($carddata);
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
		foreach (array('whatsapp', 'snapchat', 'linkedin', 'instagram', 'skype') as $network) {
			if (!empty($socialNetworks[$network])) {
				$carddata.='X-'.strtoupper($network).':'.str_replace(';','\;', $socialNetworks[$network])."\n";
			}
		}
		$noteParts = $doliinfo;
		if($notePublic !== '')
			$noteParts[] = $notePublic;
		$carddata.="NOTE;CHARSET=UTF-8:".$this->_escapeVCardText(implode("\n", $noteParts))."\n";
		$carddata.="REV;TZID=".date_default_timezone_get().":".strtr($obj->lastupd,array(" "=>"T", ":"=>"", "-"=>""))."\n";
		$carddata.="END:VCARD\n";

		return $this->_normalizeVCardData($carddata);
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataContact($cardData, $mode) {

		debug_log("_parseDataContact( $cardData )");

		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard->convert(VObject\Document::VCARD30);
		$rdata['_category_labels'] = $this->_extractCategoryLabels($vCard);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = (string)$vCard->UID;
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = $vCard->N->getParts();
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
				$teltype = [];
				$types = $tel['TYPE'];
				foreach($types as $type)
				{
					$teltype[strtoupper($type)]=true;
				}

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;

				if(isset($teltype['HOME']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone_perso'] = (string)$tel;

				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;

				if(isset($teltype['HOME']) && isset($teltype['FAX']))
					$rdata['fax'] = (string)$tel;
				elseif(isset($teltype['FAX']) && !isset($rdata['fax']))
					$rdata['fax'] = (string)$tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if(!isset($rdata['email']))
					$rdata['email'] = (string)$email;
				if($this->_isPreferredProperty($email))
					$rdata['email'] = (string)$email;
			}
		}

		if(isset($vCard->ADR))
		{
			foreach($vCard->ADR as $adr)
			{
				$types = $adr['TYPE'];
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['HOME']) || !isset($rdata['address']))
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
					if($mode=='C' && isset($vCard->ORG))	// keep ORG info in address
						$rdata['address'] = trim((string)$vCard->ORG," ;\n\r\t") . "\n" . $rdata['address'];
				}
			}
		}

		$socialNetworks = array();
		if(isset($vCard->{'X-JABBER'}))
			$socialNetworks['jabber'] = (string)$vCard->{'X-JABBER'};

		if(isset($vCard->{'X-SKYPE'}))
			$socialNetworks['skype'] = (string)$vCard->{'X-SKYPE'};
		elseif(isset($vCard->{'X-SKYPE-USERNAME'}))
			$socialNetworks['skype'] = (string)$vCard->{'X-SKYPE-USERNAME'};
		$this->_storeSocialNetworks($rdata, $socialNetworks);

		$bday = '';
		if( isset($vCard->BDAY))
			$bday = trim((string)$vCard->BDAY);
		if( isset($vCard->BDAY) &&
			!empty($bday) &&
			date("Y-m-d", strtotime(trim($bday))) == trim($bday) )
			$rdata['birthday'] = trim($bday);

		if(isset($vCard->NOTE))
			$rdata['note_public'] = strtr(trim((string)$vCard->NOTE),"\\n", "\n");

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if ($result !== false && ($row = $this->db->fetch_array($result)))
				$rdata['fk_pays'] = $row['rowid'];
		}

		debug_log("parsed:\n".print_r($rdata, true));

		return $rdata;
	}

	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataMember($cardData, $mode) {

		debug_log("_parseDataMember( $cardData )");

		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard->convert(VObject\Document::VCARD30);
		$rdata['_category_labels'] = $this->_extractCategoryLabels($vCard);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = (string)$vCard->UID;
		if(isset($vCard->PHOTO) && strpos(substr($vCard->PHOTO,0,10),'://')===false) // exist and not uri
		{
			$rdata['_photo_bin'] = (string)$vCard->PHOTO;
		}
		else
			$rdata['_photo_bin'] = false;

		$names = $vCard->N->getParts();
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
				$teltype = [];
				$types = $tel['TYPE'];
				foreach($types as $type)
				{
					$teltype[strtoupper($type)]=true;
				}

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;

				if(isset($teltype['HOME']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone_perso'] = (string)$tel;

				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if(!isset($rdata['email']))
					$rdata['email'] = (string)$email;
				if($this->_isPreferredProperty($email))
					$rdata['email'] = (string)$email;
			}
		}

		if(isset($vCard->ADR))
		{
			foreach($vCard->ADR as $adr)
			{
				$types = $adr['TYPE'];
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['HOME']) || !isset($rdata['address']))
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
					if($mode=='C' && isset($vCard->ORG))	// keep ORG info in address
						$rdata['address'] = trim((string)$vCard->ORG," ;\n\r\t") . "\n" . $rdata['address'];
				}
			}
		}

		$bday = '';
		if( isset($vCard->BDAY))
			$bday = trim((string)$vCard->BDAY);
		if( isset($vCard->BDAY) &&
			!empty($bday) &&
			date("Y-m-d", strtotime(trim($bday))) == trim($bday) )
			$rdata['birth'] = trim($bday);

		if(isset($vCard->NOTE))
			$rdata['note_public'] = strtr(trim((string)$vCard->NOTE),"\\n", "\n");

		if(isset($rdata['_country_label']) && $rdata['_country_label']!='')
		{
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if ($result !== false && ($row = $this->db->fetch_array($result)))
				$rdata['country'] = $row['rowid'];
		}

		debug_log("parsed:\n".print_r($rdata, true));

		return $rdata;
	}


	/*
	 * parse vcard data to dolibarr table fields
	 * @param cardData : string vcard
	 * @param mode : C=create / U=update
	 */
	protected function _parseDataThirdparty($cardData, $mode) {

		debug_log("_parseDataThirdparty( $cardData )");

		$rdata = [] ;

		$vCard = VObject\Reader::read($cardData);
		$vCard->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CARDDAV);
		$vCard->convert(VObject\Document::VCARD30);
		$rdata['_category_labels'] = $this->_extractCategoryLabels($vCard);

		// debug_log("_parseData__converted( ".$vCard->PHOTO." )");

		$rdata['_uid'] = (string)$vCard->UID;

		if($mode=='C')
			$rdata['status']=1;

		if(!empty((string)$vCard->FN))
			$rdata['nom'] = (string)$vCard->FN;
		else
		{
			$rdata['nom']='';
			$names = $vCard->N->getParts();
			if(!empty((string)$names[0]))
				$rdata['nom'].= (string)$names[0];
			if(!empty((string)$names[1]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[1]);
			if(!empty((string)$names[2]))
				$rdata['nom'] = trim($rdata['nom']." ".(string)$names[2]);
			if(!empty((string)$names[3]))
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
				$teltype = [];
				$types = $tel['TYPE'];
				foreach($types as $type)
				{
					$teltype[strtoupper($type)]=true;
				}

				if(isset($teltype['WORK']) && (isset($teltype['VOICE']) || count($teltype)==1))
					$rdata['phone'] = (string)$tel;
				if(isset($teltype['CELL']))
					$rdata['phone_mobile'] = (string)$tel;

				if(isset($teltype['FAX']))
					$rdata['fax'] = (string)$tel;
			}
		}

		if(isset($vCard->EMAIL))
		{
			foreach($vCard->EMAIL as $email)
			{
				if(!isset($rdata['email']))
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
				$types = $adr['TYPE'];
				$adrtype = [];
				foreach($types as $type)
				{
					$adrtype[strtoupper($type)]=true;
				}
				$adrparts = $adr->getParts();
				// debug_log("adrparts:\n".print_r($adrtype, true).print_r($adrparts, true));
				if(isset($adrtype['WORK']) || !isset($rdata['address']))
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

		$socialNetworks = array();
		if(isset($vCard->{'X-WHATSAPP'}))
			$socialNetworks['whatsapp'] = (string)$vCard->{'X-WHATSAPP'};
		if(isset($vCard->{'X-SNAPCHAT'}))
			$socialNetworks['snapchat'] = (string)$vCard->{'X-SNAPCHAT'};
		if(isset($vCard->{'X-LINKEDIN'}))
			$socialNetworks['linkedin'] = (string)$vCard->{'X-LINKEDIN'};
		if(isset($vCard->{'X-INSTAGRAM'}))
			$socialNetworks['instagram'] = (string)$vCard->{'X-INSTAGRAM'};
		if(isset($vCard->{'X-SKYPE'}))
			$socialNetworks['skype'] = (string)$vCard->{'X-SKYPE'};
		elseif(isset($vCard->{'X-SKYPE-USERNAME'}))
			$socialNetworks['skype'] = (string)$vCard->{'X-SKYPE-USERNAME'};

		/**
			IMPP;X-SERVICE-TYPE=GOOGLETALK:xmpp:goog
			IMPP;X-SERVICE-TYPE=JABBER:xmpp:jabjab
			IMPP;X-SERVICE-TYPE=YAHOO:ymsgr:yahoo
			IMPP;X-SERVICE-TYPE=QQ:x-apple:qq
			IMPP;X-SERVICE-TYPE=AIM:aim:aim
			IMPP;X-SERVICE-TYPE=MSN:msnim:msn
			IMPP;X-SERVICE-TYPE=SKYPE:skype:skyp
			IMPP;X-SERVICE-TYPE=ICQ:aim:icqq
			IMPP;X-SERVICE-TYPE=IRC:irc:irc
		**/
		if(isset($vCard->IMPP))
		{
			foreach($vCard->IMPP as $impp)
			{
				$type = strtoupper((string)$impp['X-SERVICE-TYPE']);
				$pseudo = (string)$impp;
				if(mb_strpos($pseudo,':',0,'UTF-8')!==false)
					$pseudo = mb_substr($pseudo, mb_strpos($pseudo,':',0,'UTF-8')+1, null, 'UTF-8');

				switch($type)
				{
					case "WHATSAPP":
						$socialNetworks['whatsapp'] = $pseudo;
						break;
					case "SNAPCHAT":
						$socialNetworks['snapchat'] = $pseudo;
						break;
					case "LINKEDIN":
						$socialNetworks['linkedin'] = $pseudo;
						break;
					case "INSTAGRAM":
						$socialNetworks['instagram'] = $pseudo;
						break;
					case "SKYPE":
						$socialNetworks['skype'] = $pseudo;
						break;
				}
			}
		}
		$this->_storeSocialNetworks($rdata, $socialNetworks);


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
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_country
					WHERE label LIKE "'.$this->db->escape($rdata['_country_label']).'"
					AND active = 1';
			$result = $this->db->query($sql);
			if ($result !== false && ($row = $this->db->fetch_array($result)))
				$rdata['fk_pays'] = $row['rowid'];
		}

		debug_log("parsed:\n".print_r($rdata, true));

		return $rdata;
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

		global $conf;

		debug_log("createContactObject( $addressbookId , $cardUri )");
		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CardDAV transaction');
		}
		try {

		if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'write'))
		{
			$rdata = $this->_parseDataContact($cardData, 'C');
			$rdata['ref_ext'] = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 255);

			if($rdata['_photo_bin']!==false)
			{
				$gdim = @imagecreatefromstring($rdata['_photo_bin']);
				if($gdim!==false)
					$rdata['photo'] = 'cdavimage.jpg';
			}

			$sql = "INSERT INTO ".MAIN_DB_PREFIX."socpeople (";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."`,";
			}
			$sql.= "entity,datec,tms,fk_user_creat,fk_user_modif) VALUES(";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="'".$this->db->escape($val)."',";
			}
			$sql.= ((int) $conf->entity).",NOW(),NOW(),".$this->user->id.",".$this->user->id.")";

			$res = $this->db->query($sql);
			if ( ! $res)
			{
				throw new \Sabre\DAV\Exception('Unable to create the Dolibarr contact');
			}

			//Récupérer l'ID de l'event créer et faire une insertion dans actioncomm_resources
			$id = $this->db->last_insert_id(MAIN_DB_PREFIX.'socpeople');
			if ( ! $id)
			{
				throw new \Sabre\DAV\Exception('Unable to retrieve the new Dolibarr contact');
			}

			if (isModEnabled('categorie') && intval(CDAV_CONTACT_TAG)>0)
			{
				$tagid = intval(CDAV_CONTACT_TAG);
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."categorie_contact (`fk_categorie`, `fk_socpeople`)
						VALUES ( ".$tagid.", ".$id.")";
				if (!$this->db->query($sql)) {
					throw new \Sabre\DAV\Exception('Unable to assign the mandatory contact category');
				}
			}
			$this->_syncCategories('contact', $id, $rdata['_category_labels'] ?? array());

			// save photo with jpeg format
			if(isset($rdata['photo']))
			{
				$dir = $conf->societe->dir_output."/contact/".$id."/photos";
				@mkdir($dir, 0777, true);
				if(@imagejpeg($gdim, $dir.'/'.$rdata['photo']))
				{
					$object = new \Contact($this->db);
					if($object->fetch($id)>0)
						$object->addThumbs($dir.'/'.$rdata['photo']);
				}
			}
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV contact');
			}
			return null;
		}

		if (CDAV_THIRD_SYNC > 0 && intval($addressbookId) >= CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId) < (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('societe', 'write'))
		{
			$rdata = $this->_parseDataThirdparty($cardData, 'C');
			$rdata['ref_ext'] = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 255);


			$sql = "INSERT INTO ".MAIN_DB_PREFIX."societe (";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."`,";
			}
			$sql.= "entity,datec,tms,fk_user_creat,fk_user_modif) VALUES(";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="'".$this->db->escape($val)."',";
			}
			$sql.= ((int) $conf->entity).",NOW(),NOW(),".$this->user->id.",".$this->user->id.")";

			$res = $this->db->query($sql);
			if ( ! $res)
			{
				throw new \Sabre\DAV\Exception('Unable to create the Dolibarr third party');
			}

			//Récupérer l'ID de l'event créer et faire une insertion dans actioncomm_resources
			$id = $this->db->last_insert_id(MAIN_DB_PREFIX.'societe');
			if ( ! $id)
			{
				throw new \Sabre\DAV\Exception('Unable to retrieve the new Dolibarr third party');
			}

			//Insérer association user/thirdpartie
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."societe_commerciaux (`fk_soc`, `fk_user`)
					VALUES (".$id.",".$this->user->id.")";
			if (!$this->db->query($sql)) {
				throw new \Sabre\DAV\Exception('Unable to assign the Dolibarr third party to its user');
			}
			$this->_syncCategories('thirdparty', $id, $rdata['_category_labels'] ?? array());

			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV third party');
			}
			return null;
		}

		if (CDAV_MEMBER_SYNC > 0 && intval($addressbookId) >= (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId) < (3 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('adherent', 'write'))
		{
			$rdata = $this->_parseDataMember($cardData, 'C');
			$memberType = $this->_getDefaultMemberType();
			$rdata['ref'] = substr('CDAV-'.date('YmdHis').'-'.bin2hex(random_bytes(3)), 0, 30);
			$rdata['ref_ext'] = $this->_encodeCardExternalRef($cardUri, $rdata['_uid'], 128);
			$rdata['fk_adherent_type'] = $memberType['id'];
			$rdata['morphy'] = $memberType['morphy'];
			$rdata['statut'] = 1;


			$sql = "INSERT INTO ".MAIN_DB_PREFIX."adherent (";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."`,";
			}
			$sql.= "entity,datec,tms,fk_user_author,fk_user_mod) VALUES(";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="'".$this->db->escape($val)."',";
			}
			$sql.= ((int) $conf->entity).",NOW(),NOW(),".$this->user->id.",".$this->user->id.")";

			$res = $this->db->query($sql);
			if ( ! $res)
			{
				throw new \Sabre\DAV\Exception('Unable to create the Dolibarr member');
			}
			$id = $this->db->last_insert_id(MAIN_DB_PREFIX.'adherent');
			if (!$id) {
				throw new \Sabre\DAV\Exception('Unable to retrieve the new Dolibarr member');
			}
			$this->_syncCategories('member', $id, $rdata['_category_labels'] ?? array());

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

		global $conf;

		debug_log("updateContactObject( $addressbookId , $cardUri )");
		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CardDAV transaction');
		}
		try {

		if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'write'))
		{
			$rdata = $this->_parseDataContact($cardData, 'U');
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Contact not found');
			}
			$contactid = (int) $existing['id'];

			if($rdata['_photo_bin']!==false)
			{
				$gdim = @imagecreatefromstring($rdata['_photo_bin']);
				if($gdim!==false)
					$rdata['photo'] = 'cdavimage.jpg';
			}

			$sql = "UPDATE ".MAIN_DB_PREFIX."socpeople SET ";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."` = '".$this->db->escape($val)."', ";
			}
			$sql.= " tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$contactid." AND entity IN (".getEntity('societe').")";
			$res = $this->db->query($sql);
			if (!$res) {
				throw new \Sabre\DAV\Exception('Unable to update the Dolibarr contact');
			}
			$this->_updateCardExternalRef('socpeople', $contactid, $cardUri, $rdata['_uid'], 255);
			$this->_syncCategories('contact', $contactid, $rdata['_category_labels'] ?? array());

			// save photo with jpeg format
			if(isset($rdata['photo']))
			{
				$dir = $conf->societe->dir_output."/contact/".$contactid."/photos";
				@mkdir($dir, 0777, true);
				if(@imagejpeg($gdim, $dir.'/'.$rdata['photo']))
				{
					$object = new \Contact($this->db);
					if($object->fetch($contactid)>0)
						$object->addThumbs($dir.'/'.$rdata['photo']);
				}
			}
		}

		if (CDAV_THIRD_SYNC > 0 && intval($addressbookId) >= CDAV_ADDRESSBOOK_ID_SHIFT && intval($addressbookId) < (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('societe', 'write'))
		{
			$rdata = $this->_parseDataThirdparty($cardData, 'U');
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Third party not found');
			}
			$socid = (int) $existing['id'];

			$sql = "UPDATE ".MAIN_DB_PREFIX."societe SET ";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."` = '".$this->db->escape($val)."', ";
			}
			$sql.= " tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$socid." AND entity IN (".getEntity('societe').")";
			$res = $this->db->query($sql);
			if (!$res) {
				throw new \Sabre\DAV\Exception('Unable to update the Dolibarr third party');
			}
			$this->_updateCardExternalRef('societe', $socid, $cardUri, $rdata['_uid'], 255);
			$this->_syncCategories('thirdparty', $socid, $rdata['_category_labels'] ?? array());
		}

		if (CDAV_MEMBER_SYNC > 0 && intval($addressbookId) >= (2 * CDAV_ADDRESSBOOK_ID_SHIFT) && intval($addressbookId) < (3 * CDAV_ADDRESSBOOK_ID_SHIFT) && $this->_hasRight('adherent', 'write'))
		{
			$rdata = $this->_parseDataMember($cardData, 'U');
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Member not found');
			}
			$adhid = (int) $existing['id'];

			$sql = "UPDATE ".MAIN_DB_PREFIX."adherent SET ";
			foreach($rdata as $fld => $val)
			{
				if(substr($fld,0,1)!='_')
					$sql.="`".$fld."` = '".$this->db->escape($val)."', ";
			}
			$sql.= " tms = NOW(), fk_user_mod = ".$this->user->id;
			$sql.= " WHERE rowid = ".$adhid." AND entity IN (".getEntity('adherent').")";
			$res = $this->db->query($sql);
			if (!$res) {
				throw new \Sabre\DAV\Exception('Unable to update the Dolibarr member');
			}
			$this->_updateCardExternalRef('adherent', $adhid, $cardUri, $rdata['_uid'], 128);
			$this->_syncCategories('member', $adhid, $rdata['_category_labels'] ?? array());
		}

		if (isset($res)) {
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CardDAV update');
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

		if (intval($addressbookId) < CDAV_ADDRESSBOOK_ID_SHIFT && $this->_hasRight('societe', 'contact', 'delete'))
		{
			$existing = $this->getCard($addressbookId, $cardUri);
			if ($existing === false) {
				throw new \Sabre\DAV\Exception\NotFound('Contact not found');
			}
			$contactid = (int) $existing['id'];

			$sql = "UPDATE ".MAIN_DB_PREFIX."socpeople SET ";
			$sql.= " statut = 0, tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$contactid." AND entity IN (".getEntity('societe').")";
			$res = $this->db->query($sql);
			if (!$res) {
				throw new \Sabre\DAV\Exception('Unable to deactivate the Dolibarr contact');
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

			$sql = "UPDATE ".MAIN_DB_PREFIX."societe SET ";
			$sql.= " status = 0, tms = NOW(), fk_user_modif = ".$this->user->id;
			$sql.= " WHERE rowid = ".$socid." AND entity IN (".getEntity('societe').")";
			$res = $this->db->query($sql);
			if (!$res) {
				throw new \Sabre\DAV\Exception('Unable to deactivate the Dolibarr third party');
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

			$sql = "UPDATE ".MAIN_DB_PREFIX."adherent SET ";
			$sql.= " statut = 0, tms = NOW(), fk_user_mod = ".$this->user->id;
			$sql.= " WHERE rowid = ".$adhid." AND entity IN (".getEntity('adherent').")";
			$res = $this->db->query($sql);
			if (!$res) {
				throw new \Sabre\DAV\Exception('Unable to deactivate the Dolibarr member');
			}
			return true;
		}

		throw new Forbidden('Not allowed to delete cards from this address book');
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

		// TODO
		return null;
	}

}
