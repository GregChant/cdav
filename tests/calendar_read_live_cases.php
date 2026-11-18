<?php

// Developer helpers are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Read a fictitious native calendar through real HTTP with the normal PHP limit.
 * All appointments are created and removed through the existing native fixtures.
 *
 * @param DoliDB $db Isolated native database
 * @param User $actor Isolated calendar owner
 * @param string $baseUrl Local DAV endpoint
 * @param string $login Fixture account
 * @param string $password Fixture password
 * @param string $calendarPath Calendar collection path
 * @param string $prefix Unique fixture prefix
 * @param array $createdEvents Native event identifiers owned by the caller
 * @return void
 */
function calendarReadLiveChecks($db, $actor, $baseUrl, $login, $password, $calendarPath, $prefix, array &$createdEvents)
{
	global $conf;
	$quantity = 200;
	$start = strtotime('+23 days 09:00 UTC');
	$ids = array();
	for ($index = 0; $index < $quantity; $index++) {
		$event = new ActionComm($db);
		$event->type_code = $event->code = 'AC_RDV';
		$event->label = $prefix.' Read fixture '.($index + 1);
		$event->note_private = '<p>Fictitious read fixture</p><p>Native notes remain authoritative.</p>';
		$event->datep = $start + $index * 60;
		$event->datef = $event->datep + 1800;
		$event->percentage = -1;
		$event->priority = 5;
		$event->transparency = 1;
		$event->userownerid = (int) $actor->id;
		$event->userassigned = array((int) $actor->id => array('id' => (int) $actor->id, 'transparency' => 1));
		$id = (int) $event->create($actor);
		liveAssert($id > 0, 'Unable to create an isolated native calendar read fixture');
		$createdEvents[] = $id;
		$ids[] = $id;
	}
	$body = '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
	$body .= '<d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR">';
	$body .= '<c:comp-filter name="VEVENT"><c:time-range start="'.gmdate('Ymd\THis\Z', $start).'" end="'
		.gmdate('Ymd\THis\Z', $start + $quantity * 60 + 1800).'"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
	$before = microtime(true);
	$response = liveHttp($baseUrl, $login, $password, 'REPORT', $calendarPath, $body,
		array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'));
	$elapsed = microtime(true) - $before;
	liveHttpStatus($response, array(207), 'Native calendar read fixture REPORT');
	$xml = new DOMDocument();
	liveAssert($xml->loadXML($response['body']), 'Native read fixture REPORT did not return valid XML');
	$xpath = new DOMXPath($xml);
	$xpath->registerNamespace('d', 'DAV:');
	$xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
	$expected = array_fill_keys($ids, true);
	$seen = array();
	$first = null;
	foreach ($xpath->query('/d:multistatus/d:response') as $item) {
		$data = $xpath->evaluate('string(d:propstat/d:prop/c:calendar-data)', $item);
		if ($data === '') continue;
		// XML parsers normalize line endings; restore iCalendar CRLF for byte checks.
		$data = str_replace("\n", "\r\n", str_replace(array("\r\n", "\r"), "\n", $data));
		$calendar = Sabre\VObject\Reader::read($data);
		try {
			if (!str_starts_with((string) $calendar->VEVENT->SUMMARY, $prefix.' Read fixture ')) continue;
			$uri = basename(rawurldecode($xpath->evaluate('string(d:href)', $item)));
			liveAssert((bool) preg_match('/^([1-9][0-9]*)-ev-/', $uri, $matches), 'Native read fixture lost its resource identity');
			$id = (int) $matches[1];
			liveAssert(isset($expected[$id]) && !isset($seen[$id]), 'Native read fixture was duplicated or read outside its scope');
			$seen[$id] = true;
			$etag = $xpath->evaluate('string(d:propstat/d:prop/d:getetag)', $item);
			liveAssert($etag === '"'.md5($data).'"', 'Read fixture REPORT ETag does not match its canonical bytes');
			if ($first === null) $first = array('uri' => $uri, 'body' => $data, 'etag' => $etag);
		} finally {
			$calendar->destroy();
		}
	}
	liveAssert(count($seen) === $quantity && $first !== null, 'Native calendar REPORT omitted read fixtures');
	$single = liveHttp($baseUrl, $login, $password, 'GET', $calendarPath.rawurlencode($first['uri']));
	liveHttpStatus($single, array(200), 'Read fixture fresh GET');
	liveAssert($single['body'] === $first['body'] && $single['headers']['etag'] === $first['etag'],
		'A separate GET changed the REPORT canonical representation or ETag');
	$state = liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE entity = '.((int) $conf->entity)
		.' AND id IN ('.implode(',', $ids).')');
	liveAssert($state !== null && (int) $state->nb === $quantity, 'Reading a calendar modified native appointments');
	$proof = getenv('CDAV_CALENDAR_READ_PROOF');
	if ($proof) {
		file_put_contents($proof, json_encode(array('result' => 'PASS', 'native_events' => $quantity,
			'report_events' => count($seen), 'report_seconds' => round($elapsed, 6),
			'canonical_report_get_parity' => true, 'read_only' => true), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
	}
	liveOk('Native calendar read fixture: 200 appointments, canonical REPORT/GET parity and no mutation');
}
