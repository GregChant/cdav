<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** CardDAV photo regression tests; temporary files only, no database required. */
date_default_timezone_set('UTC');
$dolibarrRoot = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
if (!is_file($dolibarrRoot.'/includes/sabre/autoload.php')) {
	fwrite(STDERR, "Set DOLIBARR_ROOT to a Dolibarr htdocs directory.\n");
	exit(2);
}
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($dolibarrRoot);
define('DOL_DOCUMENT_ROOT', $dolibarrRoot);
define('MAIN_DB_PREFIX', 'llx_');
define('CDAV_URI_KEY', 'photo-tests');
define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000);
define('CDAV_THIRD_SYNC', 2);
define('CDAV_MEMBER_SYNC', 1);
require $dolibarrRoot.'/includes/sabre/autoload.php';
// Use the actual filename sanitizer and module/configuration helpers.
require $dolibarrRoot.'/core/lib/functions.lib.php';
require dirname(__DIR__).'/class/CardDAVDolibarr.php';
require dirname(__DIR__).'/class/CDavVCardStore.php';

function debug_log($message) {}

class PhotoTestDatabase
{
	public $row;
	public function DDLInfoTable($table) { return false; }
	public function escape($value) { return addslashes($value); }
	public function query($sql) {
		return (object) array('row' => strpos($sql, 'c_socialnetworks') !== false ? null : clone $this->row);
	}
	public function fetch_object($result) {
		$row = $result->row;
		$result->row = null;
		return $row;
	}
}

class PhotoTestBackend extends Sabre\CardDAV\Backend\Dolibarr
{
	protected function _getSqlContacts($where = '') { return 'SELECT contacts'; }
	protected function _getSqlThirdparties($where = '') { return 'SELECT companies'; }
	protected function _getSqlMembers($where = '') { return 'SELECT members'; }
}

function photoAssert($expected, $actual, $message)
{
	if ($expected !== $actual) throw new RuntimeException($message);
}

function photoInvoke($backend, $method, array $arguments)
{
	$reflection = new ReflectionMethod(Sabre\CardDAV\Backend\Dolibarr::class, $method);
	if (PHP_VERSION_ID < 80500) $reflection->setAccessible(true);
	return $reflection->invokeArgs($backend, $arguments);
}

function cardPhoto($card)
{
	$parsed = Sabre\VObject\Reader::read($card['carddata']);
	return isset($parsed->PHOTO) ? (string) $parsed->PHOTO : '';
}

$temporaryRoot = sys_get_temp_dir().'/cdav-photo-'.bin2hex(random_bytes(8));
mkdir($temporaryRoot, 0700);
$conf = (object) array('entity' => 1, 'global' => (object) array(), 'modules' => array(),
	'societe' => (object) array('dir_output' => $temporaryRoot.'/one',
		'multidir_output' => array(1 => $temporaryRoot.'/one', 2 => $temporaryRoot.'/two')));
$db = new PhotoTestDatabase();
$user = new class {
	public $id = 1;
	public $allowed = true;
	public function hasRight($module, $level, $detail = '') { return $this->allowed; }
};
$langs = new class {
	public function getDefaultLang() { return 'fr_FR'; }
	public function transnoentitiesnoconv($key) { return $key; }
};
$backend = (new ReflectionClass(PhotoTestBackend::class))->newInstanceWithoutConstructor();
foreach (array('entity' => 1, 'db' => $db, 'langs' => $langs,
	'vcardStore' => new Dolibarr\CDav\VCardStore($db, 1)) as $property => $value) {
	$reflection = new ReflectionProperty(Sabre\CardDAV\Backend\Dolibarr::class, $property);
	if (PHP_VERSION_ID < 80500) $reflection->setAccessible(true);
	$reflection->setValue($backend, $value);
}
$backend->user = $user;
// Fill optional native fields with empty SQL values, as the real SELECT does.
preg_match_all('/\$obj->([a-z_]+)/i', file_get_contents(dirname(__DIR__).'/class/CardDAVDolibarr.php'), $fields);
$db->row = (object) array_fill_keys(array_unique($fields[1]), '');
foreach (array('rowid' => 1, 'entity' => 1, 'soc_entity' => 1, 'fk_soc' => 7,
	'lastname' => 'Dupont', 'firstname' => 'Jeanne', 'nom' => 'Example SA',
	'lastupd' => '2026-11-05 09:00:00', 'category_ids' => '', 'logo' => 'company.png') as $property => $value) {
	$db->row->{$property} = $value;
}
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
$contactDir = $temporaryRoot.'/one/contact/1/photos';
$companyDir = $temporaryRoot.'/one/7/logos';
mkdir($contactDir, 0700, true);
mkdir($companyDir, 0700, true);
file_put_contents($companyDir.'/company.png', $png);

try {
	$inherited = $backend->getCard(1, '1-ct-photo-tests');
	photoAssert($png, cardPhoto($inherited), 'A contact without a photo must embed the company logo bytes');
	$nativeContact = (object) array('id' => 1, 'entity' => 1, 'photo' => '');
	photoAssert(false, photoInvoke($backend, '_contactPhotoForImport', array($nativeContact, $png, $inherited['carddata'])),
		'Echoing an inherited company logo must not create a personal contact photo');
	photoAssert($gif, photoInvoke($backend, '_contactPhotoForImport', array($nativeContact, $gif, $inherited['carddata'])),
		'A different image sent by the client must remain eligible for native photo import');
	$beforeTag = photoInvoke($backend, '_getAddressBookCollectionTag', array('contact'));
	file_put_contents($companyDir.'/company.png', $gif);
	clearstatcache();
	$changed = $backend->getCard(1, '1-ct-photo-tests');
	photoAssert($gif, cardPhoto($changed), 'The image media type must follow its actual file contents');
	photoAssert(false, $changed['etag'] === $inherited['etag'], 'Replacing a company logo must change the contact ETag');
	photoAssert(false, $beforeTag === photoInvoke($backend, '_getAddressBookCollectionTag', array('contact')),
		'Replacing a logo without changing SQL timestamps must change the collection tag');
	photoAssert($changed['etag'], $backend->getCards(1)[0]['etag'], 'Collection and individual card ETags must agree');

	$db->row->photo = 'portrait.png';
	file_put_contents($contactDir.'/portrait.png', $png);
	photoAssert($png, cardPhoto($backend->getCard(1, '1-ct-photo-tests')), 'A personal photo must take priority over the company logo');
	$nativeContact->photo = 'portrait.png';
	photoAssert($png, photoInvoke($backend, '_contactPhotoForImport', array($nativeContact, $png, $inherited['carddata'])),
		'An actual personal photo must remain personal when echoed by the client');
	file_put_contents($contactDir.'/portrait.png', 'not an image');
	clearstatcache();
	photoAssert($gif, cardPhoto($backend->getCard(1, '1-ct-photo-tests')), 'An unusable personal photo must fall back to the company logo');
	unlink($contactDir.'/portrait.png');
	photoAssert($gif, cardPhoto($backend->getCard(1, '1-ct-photo-tests')), 'A missing personal file must fall back to the company logo');

	// A company row represents its own ID, rather than the contact's company ID.
	$db->row->rowid = 7;
	photoAssert($gif, cardPhoto($backend->getCard(CDAV_ADDRESSBOOK_ID_SHIFT + 1, '7-th-photo-tests')),
		'The company address book must embed its native logo as PHOTO too');
	$db->row->rowid = 1;
	$db->row->photo = '';
	$db->row->soc_entity = 2;
	mkdir($temporaryRoot.'/two/7/logos', 0700, true);
	file_put_contents($temporaryRoot.'/two/7/logos/company.png', $png);
	photoAssert($png, cardPhoto($backend->getCard(1, '1-ct-photo-tests')), 'Shared company photos must use the owning entity directory');
	$db->row->soc_entity = 3;
	photoAssert('', cardPhoto($backend->getCard(1, '1-ct-photo-tests')), 'An unavailable entity directory must never fall back to another entity');
	$db->row->soc_entity = 1;
	$db->row->logo = '../../outside.png';
	file_put_contents($temporaryRoot.'/one/outside.png', $png);
	photoAssert('', cardPhoto($backend->getCard(1, '1-ct-photo-tests')), 'A database filename must not escape the native logo directory');
	$db->row->logo = 'linked.png';
	symlink($temporaryRoot.'/one/outside.png', $companyDir.'/linked.png');
	photoAssert('', cardPhoto($backend->getCard(1, '1-ct-photo-tests')), 'A logo symlink must not disclose an unrelated document');
	$user->allowed = false;
	photoAssert(array(), $backend->getCards(1), 'A user without contact read permission must receive no images or cards');
	foreach (explode("\r\n", $changed['carddata']) as $line) {
		photoAssert(true, strlen($line) <= 75, 'Embedded image properties must retain RFC line folding');
	}
	fwrite(STDOUT, "All CDav contact photo tests passed.\n");
} finally {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) {
		if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
		else unlink($file->getPathname());
	}
	rmdir($temporaryRoot);
}
