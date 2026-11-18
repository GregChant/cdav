<?php

// Developer helpers are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Reject a native deletion after managed metadata removal, then verify rollback.
 * The trigger is installed only in an explicitly opted-in disposable /tmp core.
 * @param DoliDB $db Native database
 * @param User $testUser Isolated DAV account
 * @param User $admin Fixture cleanup administrator
 * @param array $context Existing suite URL, credentials and unique prefix
 * @return void
 */
function businessLiveDeleteRollback($db, $testUser, $admin, array $context): void
{
	global $dolibarr_main_db_name;
	if (getenv('CDAV_BOOKING_TEST_FIXTURES') !== '1') {
		fwrite(STDOUT, "[INFO] BL23 native deletion fault injection requires CDAV_BOOKING_TEST_FIXTURES=1 and a disposable /tmp core.\n");
		return;
	}
	$nativeRoot = realpath(DOL_DOCUMENT_ROOT);
	$tempRoot = realpath(sys_get_temp_dir());
	$triggerDirectory = realpath(DOL_DOCUMENT_ROOT.'/core/triggers');
	$host = parse_url($context['base_url'], PHP_URL_HOST);
	liveAssert(getenv('CDAV_LIVE_TEST') === '1' && strpos(strtolower($dolibarr_main_db_name), 'test') !== false
		&& in_array($host, array('127.0.0.1', 'localhost', '[::1]', '::1'), true)
		&& $nativeRoot !== false && $tempRoot !== false && str_starts_with($nativeRoot, $tempRoot.DIRECTORY_SEPARATOR)
		&& $triggerDirectory !== false && str_starts_with($triggerDirectory, $nativeRoot.DIRECTORY_SEPARATOR),
		'BL23 requires a loopback test database and private disposable native core, including its trigger directory');
	require_once dirname(__DIR__).'/class/CDavManagedAttachmentStore.php';
	$store = new \Dolibarr\CDav\ManagedAttachmentStore($db, $testUser);
	$triggerPath = '';
	$triggerMarker = '';
	$eventId = 0;
	$attachmentRows = array();
	$prefix = (string) $context['prefix'];
	$label = $prefix.' Deletion rollback fixture';
	$uri = $prefix.'-rollback-file.ics';
	$eventPath = $context['calendar_path'].rawurlencode($uri);
	$http = static function ($method, $path, $body = null, $headers = array()) use ($context) {
		return liveHttp($context['base_url'], $context['login'], $context['password'], $method, $path, $body, $headers);
	};
	try {
		$bytes = 'Native deletion rollback must preserve these managed bytes.';
		$start = strtotime('+15 days 10:00 UTC');
		$calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:".$prefix."-rollback-file-uid\r\n"
			.'DTSTART:'.gmdate('Ymd\THis\Z', $start)."\r\nDTEND:".gmdate('Ymd\THis\Z', $start + 3600)
			."\r\nSUMMARY:".$label."\r\nATTACH;VALUE=BINARY;ENCODING=BASE64;FMTTYPE=text/plain:".base64_encode($bytes)
			."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		liveHttpStatus($http('PUT', $eventPath, $calendar, array('Content-Type: text/calendar')), array(201, 204), 'BL23 managed-byte event fixture');
		$mapping = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE uuidext = \''.$db->escape($uri).'\'');
		liveAssert($mapping !== null, 'BL23 rollback appointment mapping was not created');
		$eventId = (int) $mapping->fk_object;
		// Simulate a protected historical mapping with an existing managed file.
		liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_cdav SET dav_origin = 0 WHERE fk_object = '.$eventId),
			'Unable to prepare the protected legacy deletion fixture');
		$attachment = liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE fk_actioncomm = '.$eventId);
		liveAssert($attachment !== null, 'BL23 inline bytes were not imported through the native document pipeline');
		$attachmentRows[] = $attachment;
		$filePath = $store->filePath($attachment);
		liveAssert(is_file($filePath) && hash_file('sha256', $filePath) === hash('sha256', $bytes), 'BL23 native managed file is missing');
		$attachmentPath = liveDavPath(array('attachments', $attachment->managed_id));
		$triggerClass = 'InterfaceCdavBusinessQa'.substr(hash('sha256', $prefix), 0, 12);
		$triggerPath = $triggerDirectory.'/interface_99_modCDav_'.substr($triggerClass, 9).'.class.php';
		$triggerMarker = tempnam(sys_get_temp_dir(), 'cdav-business-delete-');
		liveAssert(!file_exists($triggerPath) && is_writable($triggerDirectory) && $triggerMarker !== false,
			'BL23 requires a writable private native trigger directory');
		$fixture = '<?php require_once DOL_DOCUMENT_ROOT."/core/triggers/dolibarrtriggers.class.php";class '
			.$triggerClass.' extends DolibarrTriggers {public function runTrigger($action,$object,$user,$langs,$conf) {if($action === "ACTION_DELETE" && ($object->label ?? "") === '
			.var_export($label, true).') {file_put_contents('.var_export($triggerMarker, true)
			.', "native delete rollback reached");$this->error="Deliberate isolated DAV deletion rollback";return -1;}return 0;}}';
		liveAssert(file_put_contents($triggerPath, $fixture) !== false, 'Unable to install the private native deletion rejection trigger');
		liveHttpStatus($http('DELETE', $eventPath), array(500), 'BL23 native ACTION_DELETE rejection');
		liveAssert(file_get_contents($triggerMarker) === 'native delete rollback reached', 'BL23 native rejection trigger was not reached');
		liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId) !== null,
			'A rejected native deletion removed its appointment');
		liveAssert((array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE fk_actioncomm = '.$eventId) === (array) $attachment,
			'A rejected native deletion changed its managed document metadata');
		liveAssert(is_file($filePath) && hash_file('sha256', $filePath) === hash('sha256', $bytes),
			'A rejected native deletion destroyed managed file bytes before transaction commit');
		$download = $http('GET', $attachmentPath);
		liveHttpStatus($download, array(200), 'BL23 managed bytes remain downloadable after rollback');
		liveAssert($download['body'] === $bytes, 'BL23 rolled-back managed bytes changed');
		liveAssert(unlink($triggerPath), 'Unable to remove the private deletion rejection trigger');
		$triggerPath = '';
		liveHttpStatus($http('DELETE', $eventPath), array(200, 204), 'BL23 native deletion can be retried after rollback');
		clearstatcache(true, $filePath);
		liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId) === null
			&& liveRow($db, 'SELECT managed_id FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE fk_actioncomm = '.$eventId) === null
			&& !is_file($filePath), 'A committed retry did not remove its native appointment, managed metadata and physical bytes');
		liveOk('BL23 a native deletion rejection preserves managed bytes and metadata; a committed retry removes them');
	} finally {
		if ($triggerPath !== '' && is_file($triggerPath)) unlink($triggerPath);
		if ($triggerMarker !== '' && is_file($triggerMarker)) unlink($triggerMarker);
		if ($eventId === 0) {
			$row = liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE label = \''.$db->escape($label)
				.'\' AND fk_user_author = '.((int) $testUser->id).' LIMIT 1');
			$eventId = $row ? (int) $row->id : 0;
		}
		$event = new ActionComm($db);
		if ($eventId > 0 && $event->fetch($eventId) > 0) {
			liveAssert((bool) $db->begin(), 'Unable to start native rollback-fixture cleanup');
			try {
				$rows = $store->removeAllForEvent($eventId, true);
				liveAssert($event->delete($admin) > 0 && $db->commit(), 'Unable to remove the native rollback-fixture appointment');
				$store->discardCreatedFiles($rows);
			} catch (Throwable $error) {
				$db->rollback();
				throw $error;
			}
		}
		$store->discardCreatedFiles($attachmentRows);
	}
}
