<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Attachment parser and native download regressions without a Dolibarr DB. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavManagedAttachmentStore.php';
require dirname(__DIR__).'/class/CDavAgendaDocumentStore.php';
define('MAIN_DB_PREFIX', 'llx_');
$settings = array('CDAV_MANAGED_ATTACHMENTS' => 1, 'CDAV_MANAGED_ATTACHMENT_MAX_MB' => 1);
function getDolGlobalInt($key, $default = 0) { global $settings; return $settings[$key] ?? $default; }
function dol_sanitizeFileName($value) { return preg_replace('/[^a-zA-Z0-9.:_-]/', '_', $value); }
function getEntity($name) { return '1'; }
function dol_delete_file($path, ...$options) { return unlink($path); }
function attachmentAssert($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function attachmentRejects($callback, $class, $message) {
	try { $callback(); } catch (Throwable $e) { attachmentAssert($e instanceof $class, $message.': '.get_class($e)); return; }
	throw new RuntimeException($message.': no exception');
}
function inlineCalendar($attachment) {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:attachment-test\r\nDTSTART:20261105T120000Z\r\n"
		.$attachment."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}
$store = new Dolibarr\CDav\ManagedAttachmentStore(null, (object) array('id' => 1));
$valid = inlineCalendar('ATTACH;VALUE=BINARY;ENCODING=BASE64;FMTTYPE=text/plain;FILENAME="a:b.txt":SGVs'."\r\n bG8=");
attachmentAssert($store->validateInlineCalendarData($valid), 'Folded base64 must be accepted');
$component = Sabre\VObject\Reader::read($valid)->VEVENT;
$decoded = $store->decodeInlineAttachment($component->ATTACH);
attachmentAssert($decoded['bytes'] === 'Hello' && $decoded['filename'] === 'a:b.txt', 'Binary bytes and colon-bearing names must survive');
foreach (array('SGVsbG8=!', 'SGVsbG8', 'SGVsbG9=', 'SGVs bG8=', '') as $encoded) {
	attachmentRejects(function () use ($store, $encoded) { $store->validateInlineCalendarData(inlineCalendar('ATTACH;VALUE=BINARY;ENCODING=BASE64:'.$encoded)); },
		Sabre\DAV\Exception\BadRequest::class, 'Malformed or noncanonical base64 must be rejected');
}
attachmentRejects(function () use ($store) { $store->validateInlineCalendarData(inlineCalendar('ATTACH;VALUE=BINARY:SGVsbG8=')); },
	Sabre\DAV\Exception\BadRequest::class, 'BINARY requires explicit base64 encoding');
$large = base64_encode(str_repeat('x', 1024 * 1024 + 1));
attachmentRejects(function () use ($store, $large) { $store->validateInlineCalendarData(inlineCalendar('ATTACH;VALUE=BINARY;ENCODING=BASE64:'.$large)); },
	Sabre\DAV\Exception\BadRequest::class, 'Per-file limits must be enforced before file creation');
$active = Sabre\VObject\Reader::read(inlineCalendar('ATTACH;VALUE=BINARY;ENCODING=BASE64;FMTTYPE=text/html:SGVsbG8='))->VEVENT->ATTACH;
attachmentRejects(function () use ($store, $active) { $store->decodeInlineAttachment($active); },
	Sabre\DAV\Exception\Forbidden::class, 'Active content must be refused');
$settings['CDAV_MANAGED_ATTACHMENTS'] = 0;
attachmentRejects(function () use ($store, $valid) { $store->validateInlineCalendarData($valid); },
	Sabre\DAV\Exception\Forbidden::class, 'Disabled uploads must fail explicitly');
attachmentAssert(!$store->validateInlineCalendarData(inlineCalendar('ATTACH:https://example.test/file.pdf')), 'URL attachments remain available with uploads disabled');

$directory = sys_get_temp_dir().'/cdav-attachment-regression-'.bin2hex(random_bytes(6));
mkdir($directory.'/123', 0700, true);
file_put_contents($directory.'/123/agenda.txt', 'private appointment bytes');
file_put_contents($directory.'/secret.txt', 'outside event');
symlink($directory.'/secret.txt', $directory.'/123/linked.txt');
$conf = (object) array('agenda' => (object) array('dir_output' => $directory));
$db = new class {
	public $visible = true;
	public function query($sql) { return $this->visible; }
	public function fetch_object($result) { return (object) array('id' => 123, 'entity' => 1); }
};
$user = new class {
	public $id = 7;
	public $login = 'reader';
	public function hasRight($module, $group, $right) { return $group === 'myactions'; }
};
$documents = new Dolibarr\CDav\AgendaDocumentStore($db, $user);
try {
	attachmentAssert($documents->readable(123, 'agenda.txt')->path === $directory.'/123/agenda.txt', 'Visible native files must be readable');
	foreach (array('../secret.txt', '.htaccess', 'linked.txt', 'missing.txt', 'a\\b') as $filename) {
		attachmentRejects(function () use ($documents, $filename) { $documents->readable(123, $filename); },
			Sabre\DAV\Exception\NotFound::class, 'Unscoped or hidden file must not be exposed');
	}
	$db->visible = false;
	attachmentRejects(function () use ($documents) { $documents->readable(123, 'agenda.txt'); },
		Sabre\DAV\Exception\NotFound::class, 'An invisible appointment must hide its files');

	$managedId = str_repeat('a', 64);
	$managedPath = $directory.'/123/'.$managedId.'-agenda.txt';
	file_put_contents($managedPath, 'previous attachment bytes');
	$metadata = (object) array('managed_id' => $managedId, 'fk_actioncomm' => 123, 'fk_user' => 7,
		'filename' => $managedId.'-agenda.txt');
	$transactionalDb = new class($metadata) {
		public $row;
		private $snapshot;
		public function __construct($row) { $this->row = $row; }
		public function DDLInfoTable($table) { return true; }
		public function escape($value) { return $value; }
		public function begin() { $this->snapshot = $this->row; }
		public function rollback() { $this->row = $this->snapshot; }
		public function commit() { $this->snapshot = null; }
		public function query($sql) {
			if (str_starts_with($sql, 'DELETE')) { $this->row = null; return true; }
			return (object) array('pending' => $this->row);
		}
		public function fetch_object($result) { $row = $result->pending; $result->pending = null; return $row; }
	};
	$transactionalStore = new Dolibarr\CDav\ManagedAttachmentStore($transactionalDb, $user);
	$transactionalDb->begin();
	$pendingDeletes = $transactionalStore->synchronizeCalendarData(123, inlineCalendar('SUMMARY:replacement'), true, true);
	attachmentAssert(count($pendingDeletes) === 1 && is_file($managedPath), 'An uncommitted replacement must retain previous bytes');
	$transactionalDb->rollback();
	attachmentAssert($transactionalDb->row !== null && is_file($managedPath), 'Rollback must restore a readable previous attachment');
	$transactionalDb->begin();
	$pendingDeletes = $transactionalStore->synchronizeCalendarData(123, inlineCalendar('SUMMARY:replacement'), true, true);
	$transactionalDb->commit();
	$transactionalStore->discardCreatedFiles($pendingDeletes);
	attachmentAssert($transactionalDb->row === null && !is_file($managedPath), 'Committed removal must clear metadata and previous bytes');

	// A native DELETE that rolls back must retain the same readable document.
	$transactionalDb->row = $metadata;
	file_put_contents($managedPath, 'previous attachment bytes');
	$transactionalDb->begin();
	$pendingDeletes = $transactionalStore->removeAllForEvent(123, true);
	attachmentAssert(count($pendingDeletes) === 1 && is_file($managedPath), 'An uncommitted appointment deletion must retain attachment bytes');
	$transactionalDb->rollback();
	attachmentAssert($transactionalDb->row !== null && is_file($managedPath), 'A failed native appointment deletion must restore readable attachment metadata and bytes');
	$transactionalDb->begin();
	$pendingDeletes = $transactionalStore->removeAllForEvent(123, true);
	$transactionalDb->commit();
	$transactionalStore->discardCreatedFiles($pendingDeletes);
	attachmentAssert($transactionalDb->row === null && !is_file($managedPath), 'Only a committed appointment deletion may remove attachment bytes');
} finally {
	if (isset($managedPath) && is_file($managedPath)) unlink($managedPath);
	unlink($directory.'/123/linked.txt');
	unlink($directory.'/123/agenda.txt');
	unlink($directory.'/secret.txt');
	rmdir($directory.'/123');
	rmdir($directory);
}
echo "Attachment parser and scoped native download regressions passed.\n";
