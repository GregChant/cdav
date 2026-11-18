<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Real Sabre multiget parsing, conversion and XML around an isolated property boundary. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavCalDAVPlugin.php';
require dirname(__DIR__).'/class/CDavCardDAVPlugin.php';

/** Only native property retrieval is isolated; URI, XML and format APIs remain real. */
class MultiGetBoundaryServer extends Sabre\DAV\Server
{
	public $available = array();
	public $lookups = array();
	public $timezoneLookups = 0;
	/**
	 * Emulate a native batch with missing and ACL-filtered entries omitted.
	 * @param array $paths Normalized requested paths
	 * @param array $propertyNames Requested Clark property names
	 * @return array Native property results, deliberately independent of request order
	 */
	public function getPropertiesForMultiplePaths(array $paths, array $propertyNames = array())
	{
		$this->lookups[] = array('paths' => $paths, 'properties' => $propertyNames);
		return $this->available;
	}
	/**
	 * Exercise the native calendar expansion fallback timezone without a database.
	 * @param string $path Calendar collection path
	 * @param array $propertyNames Requested calendar properties
	 * @return array Empty calendar timezone, requesting UTC fallback
	 */
	public function getProperties($path, $propertyNames)
	{
		$this->timezoneLookups++;
		return array();
	}
}

/**
 * Require observable native protocol behavior.
 * @param bool $condition Required condition
 * @param string $message Regression description
 * @return void
 */
function multiGetAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Execute a real protocol plugin through Sabre's parsed report event.
 * @param string $protocol calendar or addressbook
 * @param array $hrefs Requested DAV hrefs, including repeats or absent resources
 * @param array $available Native property results
 * @param string $dataFilter Optional native calendar-data/address-data attributes
 * @param array $headers Optional Prefer or Brief headers
 * @param bool $emptyProperties Whether to request no explicit properties
 * @return array Server, XML XPath and protocol data property
 */
function multiGetReport($protocol, array $hrefs, array $available, $dataFilter = '', array $headers = array(), $emptyProperties = false)
{
	$isCalendar = $protocol === 'calendar';
	$namespace = $isCalendar ? 'urn:ietf:params:xml:ns:caldav' : 'urn:ietf:params:xml:ns:carddav';
	$dataName = $isCalendar ? 'calendar-data' : 'address-data';
	$server = new MultiGetBoundaryServer();
	$server->setBaseUri('/dav/');
	$server->httpRequest = new Sabre\HTTP\Request('REPORT', '/dav/book/', $headers);
	$server->httpResponse = new Sabre\HTTP\Response();
	$server->available = $available;
	$server->addPlugin($isCalendar ? new Dolibarr\CDav\CalDAVPlugin(1024) : new Dolibarr\CDav\CardDAVPlugin(1024));
	$body = '<p:'.$protocol.'-multiget xmlns:d="DAV:" xmlns:p="'.$namespace.'">';
	$body .= $emptyProperties ? '<d:prop/>' : '<d:prop><d:getetag/><p:'.$dataName.($dataFilter ?: '>').'</p:'.$dataName.'></d:prop>';
	foreach ($hrefs as $href) $body .= '<d:href>'.htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</d:href>';
	$body .= '</p:'.$protocol.'-multiget>';
	$report = $server->xml->parse($body, 'https://calendar.example.test/dav/book/');
	$server->emit('report', array('{'.$namespace.'}'.$protocol.'-multiget', $report, 'book'));
	multiGetAssert($server->httpResponse->getStatus() === 207, 'Both multiget protocols require a 207 response');
	multiGetAssert($server->httpResponse->getHeader('Content-Type') === 'application/xml; charset=utf-8'
		&& $server->httpResponse->getHeader('Vary') === 'Brief,Prefer', 'Native multistatus headers must remain unchanged');
	$xml = new DOMDocument();
	multiGetAssert($xml->loadXML($server->httpResponse->getBodyAsString()), 'Multiget must return well-formed native XML');
	$xpath = new DOMXPath($xml);
	$xpath->registerNamespace('d', 'DAV:');
	$xpath->registerNamespace('p', $namespace);
	return array($server, $xpath, $dataName);
}

$calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav multiget tests//EN\r\nBEGIN:VEVENT\r\nUID:multiget-calendar\r\nDTSTAMP:20261118T120000Z\r\nDTSTART:20261120T090000Z\r\nDTEND:20261120T100000Z\r\nSUMMARY:Calendar & native XML\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$card = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:multiget-contact\r\nFN:Client & native XML\r\nN:Client;;;;\r\nEMAIL:client@example.test\r\nEND:VCARD\r\n";
foreach (array('calendar' => $calendar, 'addressbook' => $card) as $protocol => $data) {
	$isCalendar = $protocol === 'calendar';
	$property = $isCalendar ? '{urn:ietf:params:xml:ns:caldav}calendar-data' : '{urn:ietf:params:xml:ns:carddav}address-data';
	$available = array(
		'book/forbidden' => array('href' => 'book/forbidden', 403 => array('{DAV:}getetag' => null)),
		'book/existing item' => array('href' => 'book/existing item', 200 => array('{DAV:}getetag' => '"native-etag"', $property => $data)),
	);
	$hrefs = array('/dav/book/existing%20item', '/dav/book/missing', '/dav/book/forbidden',
		'https://calendar.example.test/dav/book/existing%20item', '/dav/book/hidden');
	list($server, $xpath, $dataName) = multiGetReport($protocol, $hrefs, $available);
	multiGetAssert(count($server->lookups) === 1 && $server->lookups[0]['paths'] === array('book/existing item', 'book/missing', 'book/forbidden', 'book/existing item', 'book/hidden'), 'Native URI normalization and a single batch lookup must preserve all requested hrefs');
	multiGetAssert((int) $xpath->evaluate('count(/d:multistatus/d:response)') === 5, 'Each requested href, including duplicates, requires its own response');
	$expected = array('/dav/book/existing%20item', '/dav/book/missing', '/dav/book/forbidden', '/dav/book/existing%20item', '/dav/book/hidden');
	foreach ($expected as $index => $href) {
		multiGetAssert($xpath->evaluate('string(/d:multistatus/d:response['.($index + 1).']/d:href)') === $href, 'Multiget response order and native href encoding must match the request');
	}
	multiGetAssert($xpath->evaluate('string(/d:multistatus/d:response[2]/d:propstat/d:status)') === 'HTTP/1.1 404 Not Found'
		&& $xpath->evaluate('string(/d:multistatus/d:response[5]/d:propstat/d:status)') === 'HTTP/1.1 404 Not Found', 'Missing and ACL-filtered resources must remain indistinguishable 404 responses');
	multiGetAssert($xpath->evaluate('string(/d:multistatus/d:response[3]/d:propstat/d:status)') === 'HTTP/1.1 403 Forbidden', 'Native property-level denial must remain intact');
	$exported = $xpath->evaluate('string(/d:multistatus/d:response[1]/d:propstat/d:prop/p:'.$dataName.')');
	multiGetAssert(str_contains($exported, $isCalendar ? 'Calendar & native XML' : 'Client & native XML'), 'Protocol data and native XML escaping must survive shared response handling');

	list(, $empty) = multiGetReport($protocol, array('/dav/book/missing'), array(), '', array(), true);
	multiGetAssert($empty->evaluate('string(/d:multistatus/d:response/d:propstat/d:status)') === 'HTTP/1.1 404 Not Found'
		&& (int) $empty->evaluate('count(/d:multistatus/d:response/d:propstat/d:prop/d:getetag)') === 1, 'Empty property requests must retain the missing-resource getetag fallback');
	foreach (array(array('Prefer' => 'return=minimal'), array('Brief' => 't')) as $headers) {
		list(, $minimal) = multiGetReport($protocol, array('/dav/book/existing%20item', '/dav/book/missing'), $available, '', $headers);
		multiGetAssert((int) $minimal->evaluate('count(/d:multistatus/d:response)') === 2
			&& (int) $minimal->evaluate('count(/d:multistatus/d:response[2]/d:propstat/d:prop/*)') === 0, 'Native Prefer/Brief stripping of 404 properties must retain the requested href response');
	}
	try {
		multiGetReport($protocol, array('/outside-dav/secret'), $available);
		throw new RuntimeException('A multiget href outside the native base URI was accepted');
	} catch (Sabre\DAV\Exception\Forbidden $expected) {
		// Native calculateUri enforces the boundary before any property lookup.
	}
}

$calendarProperty = '{urn:ietf:params:xml:ns:caldav}calendar-data';
$events = array('book/event' => array('href' => 'book/event', 200 => array($calendarProperty => $calendar)));
list(, $jcal) = multiGetReport('calendar', array('/dav/book/event'), $events, ' content-type="application/calendar+json">');
$json = json_decode($jcal->evaluate('string(//p:calendar-data)'), true, 512, JSON_THROW_ON_ERROR);
multiGetAssert($json[0] === 'vcalendar' && str_contains(json_encode($json), 'multiget-calendar'), 'CalDAV must retain native jCal conversion');
list($expandedServer, $expanded) = multiGetReport('calendar', array('/dav/book/event', '/dav/book/event'), $events,
	'><p:expand start="20261120T000000Z" end="20261122T000000Z"/>');
$expandedData = $expanded->evaluate('string(/d:multistatus/d:response[1]/d:propstat/d:prop/p:calendar-data)');
multiGetAssert(substr_count($expandedData, 'BEGIN:VEVENT') === 2 && !str_contains($expandedData, 'RRULE:')
	&& $expandedServer->timezoneLookups === 1, 'CalDAV recurrence expansion and per-calendar timezone caching must remain native');
$cardProperty = '{urn:ietf:params:xml:ns:carddav}address-data';
$contacts = array('book/contact' => array('href' => 'book/contact', 200 => array($cardProperty => $card)));
list(, $vcard4) = multiGetReport('addressbook', array('/dav/book/contact'), $contacts, ' content-type="text/vcard" version="4.0">');
multiGetAssert(str_contains($vcard4->evaluate('string(//p:address-data)'), 'VERSION:4.0'), 'CardDAV must retain native vCard version negotiation and conversion');
list(, $jcard) = multiGetReport('addressbook', array('/dav/book/contact'), $contacts, ' content-type="application/vcard+json">');
$json = json_decode($jcard->evaluate('string(//p:address-data)'), true, 512, JSON_THROW_ON_ERROR);
multiGetAssert($json[0] === 'vcard' && str_contains(json_encode($json), 'client@example.test'), 'CardDAV must retain native jCard negotiation');
fwrite(STDOUT, "CalDAV/CardDAV multiget order, missing/ACL responses, native XML, Prefer and format conversions passed.\n");
