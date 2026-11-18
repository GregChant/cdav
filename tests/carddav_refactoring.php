<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Native vCard dialect, replacement and soft-deletion boundaries; no database writes. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
date_default_timezone_set('UTC');
define('DOL_DOCUMENT_ROOT', $root);
define('MAIN_DB_PREFIX', 'llx_');
define('CDAV_URI_KEY', 'refactoring-tests');
define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000);
define('CDAV_THIRD_SYNC', 2);
define('CDAV_MEMBER_SYNC', 1);
require $root.'/includes/sabre/autoload.php';
require $root.'/core/lib/functions.lib.php';
require dirname(__DIR__).'/class/CardDAVDolibarr.php';
require dirname(__DIR__).'/class/CDavVCardStore.php';

/**
 * Disable backend diagnostics in this pure suite.
 * @param string $message Diagnostic
 * @return void
 */
function debug_log($message)
{
}

/**
 * Isolate native country lookup from the parser's address selection.
 * @param mixed ...$arguments Native dictionary arguments
 * @return int Country identifier
 */
function getCountry(...$arguments)
{
	return 41;
}

/**
 * Assert a domain outcome without depending on the helper implementation.
 * @param bool $condition Invariant
 * @param string $message Failure
 * @return void
 */
function cardRefactorAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/** Read-only native locks and a transactional lifecycle state for deletion probes. */
class CardRefactorDatabase
{
	public $queries = array();
	public $status = 1;
	public $savedStatus = 1;
	public $commitAllowed = true;
	public $rollbacks = 0;
	/** @return int Native transaction result */
	public function begin()
	{
		$this->savedStatus = $this->status;
		return 1;
	}
	/** @return int Native commit result */
	public function commit()
	{
		return $this->commitAllowed ? 1 : 0;
	}
	/** @return int Native rollback result */
	public function rollback()
	{
		$this->status = $this->savedStatus;
		$this->rollbacks++;
		return 1;
	}
	/**
	 * Keep sidecar storage absent while exercising generated native properties.
	 * @param string $table Table
	 * @return bool Table availability
	 */
	public function DDLInfoTable($table)
	{
		return false;
	}
	/**
	 * Supply only a lock target; parser dictionary reads remain empty.
	 * @param string $sql Native query
	 * @return object Result cursor
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;
		return (object) array('row' => str_contains($sql, 'FOR UPDATE') ? (object) array('rowid' => 9) : null);
	}
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Consume a native cursor.
	 * @param object $result Cursor
	 * @return object|null Native row
	 */
	public function fetch_object($result)
	{
		$row = $result->row;
		$result->row = null;
		return $row;
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}

/** Native API boundary probe; physical deletion is deliberately unavailable. */
class CardRefactorNativeObject
{
	public $user_id = 0;
	public $status = 1;
	public $error = '';
	public $calls = array();
	public $db;
	/**
	 * Deactivate a native contact through its dedicated API.
	 * @param int $status Native status
	 * @return int Result
	 */
	public function setstatus($status)
	{
		$this->calls[] = array('setstatus', $status);
		$this->db->status = $status;
		return 1;
	}
	/**
	 * Update only the third party status through its native signature.
	 * @param int $id Native identifier
	 * @param object $user Native actor
	 * @return int Result
	 */
	public function update($id, $user)
	{
		$this->calls[] = array('update', $id, $user->id, $this->status);
		$this->db->status = $this->status;
		return 1;
	}
	/**
	 * Resiliate a member through its dedicated lifecycle API.
	 * @param object $user Native actor
	 * @return int Result
	 */
	public function resiliate($user)
	{
		$this->calls[] = array('resiliate', $user->id);
		$this->db->status = 0;
		return 1;
	}
}

/** Stub only native fetching; production locking, authorization and dispatch remain real. */
class CardRefactorBackend extends Sabre\CardDAV\Backend\Dolibarr
{
	public $nativeObject;
	/**
	 * Supply an already fetched native fixture to the actual deletion controller.
	 * @param int $addressbookId Native address book
	 * @param string $uri Native URI
	 * @param string $type Native object type
	 * @return array Native representation and object
	 */
	protected function loadCardForMutation($addressbookId, $uri, $type)
	{
		return array(array('id' => 9), $this->nativeObject);
	}
}

$conf = (object) array('entity' => 1, 'global' => (object) array('CDAV_CONCAT_SOCNAME_FOR_PHONE' => 1), 'modules' => array());
$db = new CardRefactorDatabase();
$user = new class {
	public $id = 1;
	public $allowed = true;
	/**
	 * Control real controller authorization.
	 * @param string ...$rights Native permission path
	 * @return bool Native permission
	 */
	public function hasRight(...$rights)
	{
		return $this->allowed;
	}
};
$langs = new class {
	/** @return string Native language */
	public function getDefaultLang()
	{
		return 'fr_FR';
	}
	/**
	 * Retain native labels in this dialect fixture.
	 * @param string $key Native label
	 * @return string Translation
	 */
	public function transnoentitiesnoconv($key)
	{
		return $key;
	}
};
$backend = (new ReflectionClass(CardRefactorBackend::class))->newInstanceWithoutConstructor();
$backend->user = $user;
foreach (array('db' => $db, 'langs' => $langs, 'entity' => 1, 'vcardStore' => new Dolibarr\CDav\VCardStore($db, 1)) as $name => $value) {
	$property = new ReflectionProperty(Sabre\CardDAV\Backend\Dolibarr::class, $name);
	if (PHP_VERSION_ID < 80500) $property->setAccessible(true);
	$property->setValue($backend, $value);
}
$invoke = static function ($method, array $arguments) use ($backend) {
	$reflection = new ReflectionMethod($backend, $method);
	if (PHP_VERSION_ID < 80500) $reflection->setAccessible(true);
	return $reflection->invokeArgs($backend, $arguments);
};

foreach (array('3.0', '4.0') as $version) {
	$preferredEmail = $version === '4.0' ? 'EMAIL;PREF=1:' : 'EMAIL;TYPE=PREF,INTERNET:';
	$card = "BEGIN:VCARD\r\nVERSION:".$version."\r\nUID:native-refactor\r\nN:Doe;Jane;;;\r\nFN:Jane Doe\r\nTITLE:Job\r\nCLASS:PRIVATE\r\n";
	$card .= "TEL:+4100\r\nTEL;TYPE=WORK,VOICE:+4111\r\nTEL;TYPE=HOME,VOICE:+4122\r\nTEL;TYPE=CELL:+4133\r\n";
	$card .= "TEL;TYPE=WORK,FAX:+4144\r\nTEL;TYPE=HOME,FAX:+4155\r\nTEL;TYPE=WORK,FAX:+4166\r\nEMAIL:first@example.test\r\n";
	$card .= $preferredEmail."preferred@example.test\r\nEMAIL:last@example.test\r\nADR;TYPE=HOME:;;Home | Lane;Village;;1000;CH\r\n";
	$card .= "ADR;TYPE=WORK:;;Office;City;;2000;CH\r\nBDAY:19800229\r\nNOTE:User note\r\nEND:VCARD\r\n";
	foreach (array('Contact', 'Member', 'Thirdparty') as $type) {
		$data = $invoke('_parseData'.$type, array($card, 'U'));
		cardRefactorAssert($data['phone'] === '+4111' && $data['phone_mobile'] === '+4133' && $data['email'] === 'preferred@example.test',
			$version.' '.$type.' lost work/mobile/preferred email priority');
		cardRefactorAssert($data['address'] === ($type === 'Thirdparty' ? 'Office' : "Home\nLane"), $type.' lost its native address preference');
		if ($type === 'Contact') cardRefactorAssert($data['fax'] === '+4155' && $data['priv'] === 1 && $data['birthday'] === '1980-02-29', 'Contact-only privacy, home fax or birthday changed');
		if ($type === 'Member') cardRefactorAssert(!array_key_exists('fax', $data) && !array_key_exists('priv', $data)
			&& !array_key_exists('poste', $data) && $data['birth'] === '1980-02-29', 'Member imported contact-only fields');
		if ($type === 'Thirdparty') cardRefactorAssert($data['fax'] === '+4166' && !array_key_exists('status', $data), 'Existing company lifecycle or last-fax priority changed');
		$empty = $invoke('_parseData'.$type, array("BEGIN:VCARD\r\nVERSION:".$version."\r\nUID:native-refactor\r\nFN:Fallback Name\r\nEND:VCARD\r\n", 'U'));
		foreach (array('phone', 'phone_mobile', 'email', 'url', 'address', 'socialnetworks', 'note_public') as $field) {
			cardRefactorAssert($empty[$field] === '', $type.' resurrected a removed '.$field);
		}
		cardRefactorAssert(($empty['lastname'] ?? $empty['nom']) === 'Fallback Name', $type.' lost FN-only name fallback');
	}
}

preg_match_all('/\$obj->([a-z_]+)/i', file_get_contents(dirname(__DIR__).'/class/CardDAVDolibarr.php'), $matches);
$row = (object) array_fill_keys(array_unique($matches[1]), '');
foreach (array('rowid' => 9, 'entity' => 1, 'lastupd' => '2026-11-18 09:00:00', 'lastname' => 'Doe', 'firstname' => 'Jane',
	'nom' => 'Company', 'soc_nom' => 'Linked company', 'priv' => 1, 'soc_phone' => '+4177', 'soc_fax' => '+4188', 'fax' => '+4199',
	'address' => "House\nFloor\nEntrance", 'soc_address' => 'Office', 'birthday' => '1980-02-29', 'birth' => '1980-02-29') as $field => $value) $row->{$field} = $value;
foreach (array('contact' => 'PRIVATE', 'member' => 'PUBLIC', 'thirdparty' => 'PRIVATE') as $type => $privacy) {
	$export = $invoke('_'.$type.'ToVCard', array(clone $row));
	$parsed = Sabre\VObject\Reader::read($export);
	cardRefactorAssert((string) $parsed->CLASS === $privacy, $type.' changed native exported privacy');
	cardRefactorAssert((string) $parsed->FN === ($type === 'contact' ? '(Linked company) Doe Jane' : ($type === 'member' ? 'Doe Jane' : 'Company')),
		$type.' changed its company-prefix/name dialect');
	if ($type !== 'thirdparty') cardRefactorAssert(str_contains($export, '+4177') && str_contains($export, 'BDAY'), $type.' lost company phone fallback or birthday');
	if ($type === 'member') cardRefactorAssert(!str_contains($export, 'TYPE=HOME,FAX'), 'Members unexpectedly exported a personal fax');
	foreach (array(Sabre\VObject\Document::VCARD30, Sabre\VObject\Document::VCARD40) as $dialect) {
		$reimport = $invoke('_parseData'.ucfirst($type), array($parsed->convert($dialect)->serialize(), 'U'));
		cardRefactorAssert($reimport['_uid'] === '9-'.array('contact' => 'ct', 'member' => 'mb', 'thirdparty' => 'th')[$type].'-'.CDAV_URI_KEY,
			$type.' vCard round-trip changed native identity');
	}
}

foreach (array('ct' => 1, 'th' => 100001, 'mb' => 200001) as $type => $book) {
	$native = new CardRefactorNativeObject();
	$native->db = $db;
	$backend->nativeObject = $native;
	$db->status = 1;
	cardRefactorAssert($backend->deleteCard($book, '9-'.$type.'-'.CDAV_URI_KEY) && $db->status === 0, $type.' was not softly deactivated');
	$expected = array('ct' => array('setstatus', 0), 'th' => array('update', 9, 1, 0), 'mb' => array('resiliate', 1));
	cardRefactorAssert($native->calls === array($expected[$type]), $type.' did not use its distinct native lifecycle API');
	$db->status = 1;
	$db->commitAllowed = false;
	try {
		$backend->deleteCard($book, '9-'.$type.'-'.CDAV_URI_KEY);
		throw new RuntimeException('A rejected native commit was accepted');
	} catch (Sabre\DAV\Exception $error) {
		cardRefactorAssert($db->status === 1, 'A failed native commit did not roll back deactivation');
	}
	$db->commitAllowed = true;
}
$native = new CardRefactorNativeObject();
$native->db = $db;
$native->user_id = 12;
$backend->nativeObject = $native;
$db->status = 1;
try {
	$backend->deleteCard(1, '9-ct-'.CDAV_URI_KEY);
	throw new RuntimeException('A linked user contact was remotely deactivated');
} catch (Sabre\DAV\Exception\Forbidden $error) {
	cardRefactorAssert($native->calls === array() && $db->status === 1, 'Linked-user protection did not precede the native write');
}
$user->allowed = false;
try {
	$backend->deleteCard(100001, '9-th-'.CDAV_URI_KEY);
	throw new RuntimeException('A read-only actor deactivated a company');
} catch (Sabre\DAV\Exception\Forbidden $error) {
	cardRefactorAssert($db->status === 1, 'Missing native delete rights changed lifecycle state');
}
fwrite(STDOUT, "vCard3/4 native type distinctions, replacement preferences and transactional soft deletion passed.\n");
