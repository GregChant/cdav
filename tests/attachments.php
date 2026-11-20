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
require dirname(__DIR__).'/class/CDavCalDAVPlugin.php';
define('MAIN_DB_PREFIX', 'llx_');
$settings = array('CDAV_MANAGED_ATTACHMENTS' => 1, 'CDAV_MANAGED_ATTACHMENT_MAX_MB' => 1);
function getDolGlobalInt($key, $default = 0) { global $settings; return $settings[$key] ?? $default; }
function dol_sanitizeFileName($value) { return preg_replace('/[^a-zA-Z0-9.:_-]/', '_', $value); }
function getEntity($name) { return '1'; }
function dol_delete_file($path, ...$options) { return unlink($path); }
function dol_buildpath($path, $type = 0) {
	// Dolibarr identifies the alternate root from the real script, not its PATH_INFO.
	return 'https://dolibarr.example.test'.($path === 'cdav/server.php' ? '/custom/' : '/').$path;
}
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

$outlookBackend = new class(inlineCalendar('ATTACH;FILENAME=agenda.pdf:https://example.test/agenda.pdf')) {
	public $stored;
	public $validated;
	public $expected;
	public function __construct($stored) { $this->stored = $stored; }
	public function getCalendarObject($calendarId, $uri) { return array('calendardata' => $this->stored, 'etag' => '"existing"'); }
	public function validateProtectedCalendarWrite($calendarId, $uri, $replacement, $creating) { $this->validated = $replacement; }
	public function expectCalendarVersion($calendarId, $uri, $etag, $unlessSet) { $this->expected = array($calendarId, $uri, $etag, $unlessSet); }
};
$outlookPlugin = new Dolibarr\CDav\CalDAVPlugin(4096, false, $outlookBackend);
$path = '/calendars/alice/42-cal-alice/appointment.ics';
$oldClient = array('User-Agent' => 'CalDavSynchronizer/4.5');
foreach (array(
	array('PUT', $oldClient, false, 'SUMMARY:Moved from Outlook', true),
	array('PUT', array('User-Agent' => 'Another CalDAV client'), false, 'SUMMARY:Other client', false),
	array('POST', $oldClient, false, 'SUMMARY:Managed attachment operation', false),
	array('PUT', $oldClient, true, 'SUMMARY:New appointment', false),
	array('PUT', $oldClient, false, 'ATTACH:https://example.test/explicit.pdf', false),
) as list($method, $headers, $creating, $replacement, $shouldRestore)) {
	$request = new Sabre\HTTP\Request($method, $path, $headers);
	$calendar = Sabre\VObject\Reader::read(inlineCalendar($replacement));
	$modified = false;
	$outlookBackend->expected = null;
	$outlookPlugin->guardNativeAppointmentChange($request, new Sabre\HTTP\Response(), $calendar, 'calendars/alice/42-cal-alice', $modified, $creating);
	attachmentAssert($modified === $shouldRestore && str_contains($outlookBackend->validated, 'https://example.test/agenda.pdf') === $shouldRestore,
		'Attachment omission compatibility must be limited to existing Outlook PUTs');
	attachmentAssert($outlookBackend->expected === ($shouldRestore ? array(42, 'appointment.ics', '"existing"', true) : null),
		'Outlook restoration must bind the native write without replacing an earlier precondition');
	if (str_starts_with($replacement, 'ATTACH:')) attachmentAssert(str_contains($outlookBackend->validated, $replacement), 'An explicit ATTACH must remain authoritative');
	$calendar->destroy();
}
$overrideBody = "BEGIN:VEVENT\r\nUID:attachment-test\r\nRECURRENCE-ID:20261106T120000Z\r\nDTSTART:20261106T130000Z\r\n";
$outlookBackend->stored = str_replace("END:VCALENDAR\r\n", $overrideBody."ATTACH:https://example.test/occurrence.pdf\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n", $outlookBackend->stored);
$request = new Sabre\HTTP\Request('PUT', $path, $oldClient);
$replacement = str_replace("END:VCALENDAR\r\n", $overrideBody."END:VEVENT\r\nEND:VCALENDAR\r\n", inlineCalendar('SUMMARY:Edited series'));
$calendar = Sabre\VObject\Reader::read($replacement);
$modified = false;
$outlookPlugin->guardNativeAppointmentChange($request, new Sabre\HTTP\Response(), $calendar, 'calendars/alice/42-cal-alice', $modified, false);
$components = $calendar->getComponents();
attachmentAssert($modified && (string) $components[0]->ATTACH === 'https://example.test/agenda.pdf'
	&& (string) $components[1]->ATTACH === 'https://example.test/occurrence.pdf', 'Outlook replacement must retain the attachment on its original recurrence component');
$calendar->destroy();
$calendar = Sabre\VObject\Reader::read(inlineCalendar('SUMMARY:Missing occurrence'));
attachmentRejects(function () use ($outlookPlugin, $request, $calendar) {
	$modified = false;
	$outlookPlugin->guardNativeAppointmentChange($request, new Sabre\HTTP\Response(), $calendar, 'calendars/alice/42-cal-alice', $modified, false);
}, Sabre\DAV\Exception\Conflict::class, 'An Outlook write cannot silently remove an attached recurrence component');
$calendar->destroy();

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
	$attachmentBase = 'https://dolibarr.example.test';
	$canonicalUrl = $attachmentBase.'/custom/cdav/server.php/attachments/'.$managedId;
	$legacyUrl = $attachmentBase.'/cdav/server.php/attachments/'.$managedId;
	attachmentAssert($transactionalStore->attachmentUrl($managedId) === $canonicalUrl, 'Managed attachments must use the installed script URL');
	foreach (array($canonicalUrl, $legacyUrl) as $url) {
		$calendarData = inlineCalendar('ATTACH;MANAGED-ID='.$managedId.':'.$url);
		attachmentAssert($transactionalStore->synchronizeCalendarData(123, $calendarData, false) === array(),
			'Current and historical managed attachment URLs must survive PUT');
	}
	$unrelatedUrl = str_replace('dolibarr.example.test', 'files.example.test', $canonicalUrl);
	attachmentRejects(function () use ($transactionalStore, $managedId, $unrelatedUrl) {
		$transactionalStore->synchronizeCalendarData(123, inlineCalendar('ATTACH;MANAGED-ID='.$managedId.':'.$unrelatedUrl), false);
	}, Sabre\DAV\Exception\Conflict::class, 'Only known managed attachment URLs may retain a managed ID');
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
