<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Real Sabre scheduling, with the committed native store replaced at its API boundary. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavSchedulingPlugin.php';
$invitationLogs = array();

/**
 * Collect expected post-commit transport failures without native logging.
 * @param string $message Log entry
 * @param int $level Native severity
 * @return void
 */
function dol_syslog($message, $level = LOG_INFO)
{
	global $invitationLogs;
	$invitationLogs[] = $message;
}

/** Isolated principal directory and authenticated outbox permissions. */
class InvitationSchedulingAcl extends Sabre\DAVACL\Plugin
{
	public $actor = 'principals/alice';
	public $allow = true;
	public $privileges = array();
	/** @return string Authenticated isolated principal */
	public function getCurrentUserPrincipal()
	{
		return $this->actor;
	}
	/**
	 * Resolve local calendar-user addresses without accessing a native user table.
	 * @param string $uri Calendar-user address
	 * @return string|null Local principal
	 */
	public function getPrincipalByUri($uri)
	{
		foreach (array('owner', 'alice', 'bob') as $login) {
			if (strtolower(trim($uri, " \t\r\n<>")) === 'mailto:'.$login.'@example.test') return 'principals/'.$login;
		}
		return null;
	}
	/**
	 * Assert the actual scheduling-specific privilege requested by the plugin.
	 * @param string $uri Target resource
	 * @param string|array $privileges Required privileges
	 * @param int $recursion Native recursion mode
	 * @param bool $throwExceptions Native failure mode
	 * @return bool
	 */
	public function checkPrivileges($uri, $privileges, $recursion = self::R_PARENT, $throwExceptions = true)
	{
		$this->privileges[] = $privileges;
		if (!$this->allow) throw new Sabre\DAV\Exception\Forbidden('Isolated scheduling privilege denied');
		return true;
	}
}

/** Native business backend boundary; no ActionComm creation API is exposed. */
class InvitationSchedulingBackend
{
	public $current;
	public $present = true;
	public $committed = false;
	public $premature = false;
	public $protected = false;
	public $audit = array();
	public $inbox = array();
	public $inboxCalls = 0;
	public $replyCalls = 0;
	public $replyApplies = 0;
	public $replyCanonical = '';
	public $replyFailure = false;
	public $receipt = null;
	/**
	 * Keep business authorization at the backend boundary for this transport suite.
	 * @param int $calendarId Native calendar owner
	 * @param string $uri Resource URI
	 * @param string $data Incoming calendar
	 * @param bool $isNew Creation indicator
	 * @return bool
	 */
	public function validateProtectedCalendarWrite($calendarId, $uri, $data, $isNew)
	{
		return $this->protected;
	}
	/**
	 * Export only the current committed representation.
	 * @param int $calendarId Native calendar owner
	 * @param string $uri Resource URI
	 * @return array|null Native event
	 */
	public function getCalendarObject($calendarId, $uri)
	{
		return $this->current === null ? null : array('id' => 42, 'calendardata' => $this->current);
	}
	/**
	 * Reproduce native-protection lookup independently from ordinary DAV events.
	 * @param int $calendarId Native calendar owner
	 * @param string $uri Resource URI
	 * @return array|null Protected native event
	 */
	public function getProtectedCalendarObject($calendarId, $uri)
	{
		return $this->protected ? $this->getCalendarObject($calendarId, $uri) : null;
	}
	/**
	 * Distinguish deletion from shared-calendar detachment.
	 * @param int $id Native event ID
	 * @return bool
	 */
	public function calendarEventExists($id)
	{
		return $this->present;
	}
	/**
	 * Return the before/after state actually captured under the native row lock.
	 * @param int $calendarId Native calendar owner
	 * @param string $uri Resource URI
	 * @return array|null Committed write receipt
	 */
	public function getCommittedSchedulingChange($calendarId, $uri)
	{
		return $this->receipt;
	}
	/**
	 * Capture the real Broker's complete messages before local delivery.
	 * @param Sabre\VObject\ITip\Message $message Scheduling message
	 * @return void
	 */
	public function recordSchedulingMessage($message)
	{
		if (!$this->committed) $this->premature = true;
		$this->audit[] = array('method' => $message->method, 'recipient' => $message->recipient,
			'sender' => $message->sender, 'sequence' => $message->sequence, 'data' => $message->message->serialize());
	}
	/**
	 * Emulate the unique persistent inbox key without creating any event copy.
	 * @param string $principal Recipient principal
	 * @param string $uri Message URI
	 * @param string $data iTIP message
	 * @return void
	 */
	public function createSchedulingObject($principal, $uri, $data)
	{
		if (!$this->committed) $this->premature = true;
		$this->inboxCalls++;
		$this->inbox[$principal.'/'.$uri] = $data;
	}
	/**
	 * Model the backend's committed, existing-UID-only and idempotent REPLY API.
	 * @param string $principal Authenticated sender principal
	 * @param string $data Submitted REPLY
	 * @return array Committed response contract
	 */
	public function processSchedulingReply($principal, $data)
	{
		$this->replyCalls++;
		if ($this->replyFailure) throw new Sabre\DAV\Exception\Conflict('Unknown UID or stale sequence');
		$previous = $this->current;
		$changed = $previous !== $this->replyCanonical;
		if ($changed) $this->replyApplies++;
		$this->current = $this->replyCanonical;
		$this->committed = true;
		return array('previous_data' => $previous, 'canonical_data' => $this->current, 'changed' => $changed,
			'uid' => 'invitation-stable-uid', 'recipients' => array('principals/owner', 'principals/bob'),
			'sender_address' => 'mailto:alice@example.test', 'organizer_address' => 'mailto:owner@example.test');
	}
}

/** Resolve only the fixture's calendar and outbox owners. */
class InvitationSchedulingTree extends Sabre\DAV\Tree
{
	public $owner = 'principals/alice';
	/** Keep test collections independent from the native filesystem. */
	public function __construct()
	{
	}
	/**
	 * Return a real Sabre outbox or an inert owned calendar node.
	 * @param string $path Requested resource path
	 * @return object
	 */
	public function getNodeForPath($path)
	{
		if (str_ends_with(trim($path, '/'), '/outbox')) return new Sabre\CalDAV\Schedule\Outbox($this->owner);
		return new class($this->owner) {
			private $owner;
			/** @param string $owner Owner principal */
			public function __construct($owner)
			{
				$this->owner = $owner;
			}
			/** @return string Owner principal */
			public function getOwner()
			{
				return $this->owner;
			}
		};
	}
}

/** Keep address discovery and FREEBUSY external to the scheduling behavior tested. */
class InvitationSchedulingPlugin extends Dolibarr\CDav\SchedulingPlugin
{
	public $freeBusySeen = false;
	/**
	 * Resolve local users through the isolated principal directory.
	 * @param string $principal Owner principal
	 * @return string[] Calendar-user addresses
	 */
	protected function getAddressesForPrincipal($principal)
	{
		return array('mailto:'.basename($principal).'@example.test');
	}
	/**
	 * Prove that the native FREEBUSY path receives an intact parsed stream.
	 * @param Sabre\CalDAV\Schedule\IOutbox $outbox Native outbox
	 * @param Sabre\VObject\Component $vObject Parsed request
	 * @param Sabre\HTTP\RequestInterface $request Native request
	 * @param Sabre\HTTP\ResponseInterface $response Native response
	 * @return void
	 */
	protected function handleFreeBusyRequest(Sabre\CalDAV\Schedule\IOutbox $outbox, Sabre\VObject\Component $vObject, Sabre\HTTP\RequestInterface $request, Sabre\HTTP\ResponseInterface $response)
	{
		$this->freeBusySeen = isset($vObject->VFREEBUSY) && (string) $vObject->VFREEBUSY->UID === 'freebusy-fixture';
		$response->setStatus(200);
		$response->setBody('native-freebusy');
	}
}

/**
 * Assert observable protocol behavior.
 * @param bool $condition Required condition
 * @param string $message Regression description
 * @return void
 */
function invitationSchedulingAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Construct a real Sabre event pipeline with an isolated committed-store API.
 * @param string|null $current Initial event
 * @param string $actor Authenticated principal login
 * @return array Fixture components
 */
function invitationSchedulingFixture($current, $actor = 'alice')
{
	$backend = new InvitationSchedulingBackend();
	$backend->current = $current;
	$acl = new InvitationSchedulingAcl();
	$acl->actor = 'principals/'.$actor;
	$acl->allowUnauthenticatedAccess = false;
	$server = new Sabre\DAV\Server();
	$server->tree = new InvitationSchedulingTree();
	$server->tree->owner = $acl->actor;
	$server->addPlugin($acl);
	$plugin = new InvitationSchedulingPlugin($backend);
	$server->addPlugin($plugin);
	return array($backend, $acl, $server, $plugin);
}

/**
 * Drive the real PUT hook and after-method event around an explicit native commit.
 * @param array $fixture Isolated server components
 * @param string $incoming Client representation
 * @param string $canonical Committed server representation
 * @param int $status Native write HTTP status
 * @param bool $isNew Creation indicator
 * @param array $headers Optional request headers
 * @return void
 */
function invitationSchedulingPut($fixture, $incoming, $canonical, $status = 204, $isNew = false, $headers = array())
{
	list($backend, , $server, $plugin) = $fixture;
	$request = new Sabre\HTTP\Request('PUT', '/calendars/test/1-cal-invitation/42-ev-test', $headers, $incoming);
	$server->httpRequest = $request;
	$response = new Sabre\HTTP\Response($status);
	$modified = false;
	$calendar = Sabre\VObject\Reader::read($incoming);
	$before = count($backend->audit);
	$plugin->calendarObjectChange($request, $response, $calendar, 'calendars/test/1-cal-invitation', $modified, $isNew);
	$calendar->destroy();
	invitationSchedulingAssert(count($backend->audit) === $before && !$modified, 'Scheduling must wait for the native write without rewriting incoming attendee data');
	if ($status >= 200 && $status < 300) {
		$backend->current = $canonical;
		$backend->committed = true;
	}
	$server->emit('afterMethod:PUT', array($request, $response));
	invitationSchedulingAssert(!$backend->premature, 'Native commit must precede inbox delivery and its audit');
}

/**
 * Exercise the actual POST route with an optionally streamed iCalendar body.
 * @param array $fixture Isolated server components
 * @param string $data Scheduling payload
 * @param array $headers Extra native headers
	 * @param bool $stream Whether to provide a stream body
 * @return Sabre\HTTP\Response
 */
function invitationSchedulingPost($fixture, $data, $headers = array(), $stream = false)
{
	list(, , $server, $plugin) = $fixture;
	$body = $data;
	if ($stream) {
		$body = fopen('php://temp', 'w+');
		fwrite($body, $data);
		rewind($body);
	}
	try {
		$request = new Sabre\HTTP\Request('POST', '/calendars/alice/outbox', array_merge(array('Content-Type' => 'text/calendar'), $headers), $body);
		$server->httpRequest = $request;
		$response = new Sabre\HTTP\Response();
		invitationSchedulingAssert($plugin->httpPost($request, $response) === false, 'A scheduling POST must consume its native route');
		return $response;
	} finally {
		if (is_resource($body)) fclose($body);
	}
}

/**
 * Reject a forged or unauthorized reply before touching the native backend.
 * @param array $fixture Isolated server components
 * @param string $data Scheduling payload
 * @param array $headers Optional native headers
 * @return void
 */
function invitationSchedulingForbidden($fixture, $data, $headers = array())
{
	try {
		invitationSchedulingPost($fixture, $data, $headers);
		throw new RuntimeException('Unauthorized invitation reply was accepted');
	} catch (Sabre\DAV\Exception\Forbidden $expected) {
		invitationSchedulingAssert($fixture[0]->replyCalls === 0 && !$fixture[0]->audit && !$fixture[0]->inbox, 'Authorization failure must precede native mutation and notification');
	}
}

$base = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav scheduling tests//EN\r\nBEGIN:VEVENT\r\nUID:invitation-stable-uid\r\nDTSTAMP:20261118T120000Z\r\nDTSTART:20261120T090000Z\r\nDTEND:20261120T100000Z\r\nSEQUENCE:3\r\nSTATUS:CONFIRMED\r\nSUMMARY:Native appointment\r\nDESCRIPTION:Confidential project information\r\nX-ALT-DESC;FMTTYPE=text/html:Confidential project HTML\r\nORGANIZER:mailto:owner@example.test\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.test\r\nATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:bob@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$accepted = str_replace('PARTSTAT=NEEDS-ACTION:mailto:alice', 'PARTSTAT=ACCEPTED:mailto:alice', $base);
$reply = str_replace("BEGIN:VEVENT\r\n", "METHOD:REPLY\r\nBEGIN:VEVENT\r\n", str_replace("ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:bob@example.test\r\n", '', $accepted));

// Ordinary DAV-origin invitations have the same post-commit guarantees as protected events.
$fixture = invitationSchedulingFixture($base);
invitationSchedulingPut($fixture, $accepted, $accepted, 412);
invitationSchedulingAssert(!$fixture[0]->audit && !$fixture[0]->inbox, 'Failed native PUT must produce no reply or inbox entry');
invitationSchedulingPut($fixture, $accepted, $accepted);
invitationSchedulingAssert(array_column($fixture[0]->audit, 'method') === array('REPLY', 'REQUEST'), 'Implicit acceptance must reply to the organizer and update other attendees');
invitationSchedulingAssert(array_column($fixture[0]->audit, 'recipient') === array('mailto:owner@example.test', 'mailto:bob@example.test'), 'Only the organizer and other participant receive acceptance notifications');
invitationSchedulingAssert(str_contains($fixture[0]->audit[1]['data'], 'PARTSTAT=ACCEPTED:mailto:alice@example.test'), 'Peer REQUEST must carry the committed accepted state');
invitationSchedulingAssert($fixture[0]->replyCalls === 0, 'Implicit PUT must not apply the same native reply again');
invitationSchedulingPut($fixture, $accepted, $accepted);
invitationSchedulingAssert(count($fixture[0]->audit) === 2 && count($fixture[0]->inbox) === 2, 'Replaying an unchanged accepted PUT must not duplicate scheduling messages');
$fixture[2]->emit('afterMethod:PUT', array($fixture[2]->httpRequest, new Sabre\HTTP\Response(204)));
invitationSchedulingAssert(count($fixture[0]->audit) === 2, 'Repeated after-method dispatch must not deliver the same pending reply twice');
$fixture = invitationSchedulingFixture($base);
$fixture[0]->receipt = array('previous_data' => $accepted, 'canonical_data' => $accepted, 'changed' => false);
invitationSchedulingPut($fixture, $accepted, $accepted);
invitationSchedulingAssert(!$fixture[0]->audit && !$fixture[0]->inbox, 'Concurrent acceptance already committed under the native lock must not resend a reply from the stale preflight snapshot');

// The canonical result of the locked backend, including server sequence, is authoritative.
$staleClient = str_replace('T090000Z', 'T110000Z', $base);
$canonicalMove = str_replace(array('T090000Z', 'T100000Z', 'SEQUENCE:3'), array('T130000Z', 'T140000Z', 'SEQUENCE:8'), $base);
$fixture = invitationSchedulingFixture($base, 'owner');
invitationSchedulingPut($fixture, $staleClient, $canonicalMove);
invitationSchedulingAssert(count($fixture[0]->audit) === 2, 'Organizer move must update both attendees');
foreach ($fixture[0]->audit as $notice) {
	invitationSchedulingAssert($notice['method'] === 'REQUEST' && str_contains($notice['data'], 'DTSTART:20261120T130000Z') && $notice['sequence'] === 8, 'Invitation update must reflect the committed dates and server sequence');
}
$fixture = invitationSchedulingFixture(null, 'owner');
invitationSchedulingPut($fixture, $base, $base, 201, true);
invitationSchedulingAssert(array_column($fixture[0]->audit, 'method') === array('REQUEST', 'REQUEST'), 'Creation must invite attendees only after native insertion succeeds');
$fixture = invitationSchedulingFixture($base);
invitationSchedulingPut($fixture, $accepted, $accepted, 204, false, array('Schedule-Reply' => 'F'));
invitationSchedulingAssert(!$fixture[0]->audit, 'Schedule-Reply F must suppress implicit delivery');

// An organizer cancellation is CANCEL, while an attendee response never cancels others.
$cancelled = str_replace(array('STATUS:CONFIRMED', 'SEQUENCE:3'), array('STATUS:CANCELLED', 'SEQUENCE:9'), $base);
$fixture = invitationSchedulingFixture($base, 'owner');
invitationSchedulingPut($fixture, $cancelled, $cancelled);
invitationSchedulingAssert(array_column($fixture[0]->audit, 'method') === array('CANCEL', 'CANCEL'), 'Organizer STATUS:CANCELLED must deliver actual iTIP CANCEL messages');
foreach ($fixture[0]->audit as $notice) {
	invitationSchedulingAssert($notice['sequence'] === 9 && str_contains($notice['data'], 'SEQUENCE:9') && str_contains($notice['data'], 'UID:invitation-stable-uid'), 'Cancellation must retain the committed server sequence and existing appointment UID');
}
invitationSchedulingPut($fixture, $cancelled, $cancelled);
invitationSchedulingAssert(count($fixture[0]->audit) === 2, 'Cancellation replay must not deliver another global cancellation');
foreach (array('REQUEST' => $canonicalMove, 'CANCEL' => $cancelled) as $method => $canonical) {
	$fixture = invitationSchedulingFixture($base, 'alice');
	$fixture[0]->receipt = array('previous_data' => $base, 'canonical_data' => $canonical, 'changed' => true,
		'owner_principal' => 'principals/owner');
	invitationSchedulingPut($fixture, $canonical, $canonical);
	invitationSchedulingAssert(array_column($fixture[0]->audit, 'method') === array($method, $method)
		&& array_column($fixture[0]->audit, 'sender') === array('mailto:owner@example.test', 'mailto:owner@example.test'), 'Delegated native organizer changes must notify as the organizer proven by the committed receipt, without forging an attendee reply');
}

foreach (array('owner', 'alice') as $actor) {
	$fixture = invitationSchedulingFixture($base, $actor);
	$request = new Sabre\HTTP\Request('DELETE', '/calendars/test/1-cal-invitation/42-ev-test');
	$fixture[2]->httpRequest = $request;
	$fixture[3]->beforeUnbind($request->getPath());
	invitationSchedulingAssert(!$fixture[0]->audit, 'Deletion notifications must wait for the native transaction');
	$fixture[2]->emit('afterMethod:DELETE', array($request, new Sabre\HTTP\Response(500)));
	invitationSchedulingAssert(!$fixture[0]->audit, 'Rejected deletion must not decline or cancel invitations');
	$fixture[3]->beforeUnbind($request->getPath());
	$fixture[0]->committed = true;
	$fixture[0]->present = $actor !== 'owner';
	$fixture[2]->emit('afterMethod:DELETE', array($request, new Sabre\HTTP\Response(204)));
	$expected = $actor === 'owner' ? array('CANCEL', 'CANCEL') : array('REPLY');
	invitationSchedulingAssert(array_column($fixture[0]->audit, 'method') === $expected, 'Organizer deletion cancels; attendee deletion replies without global cancellation');
	if ($actor === 'alice') invitationSchedulingAssert(str_contains($fixture[0]->audit[0]['data'], 'PARTSTAT=DECLINED'), 'Attendee removal must reply DECLINED to the organizer');
}
$fixture = invitationSchedulingFixture($base, 'owner');
$fixture[0]->committed = true;
$request = new Sabre\HTTP\Request('DELETE', '/calendars/test/1-cal-invitation/42-ev-test');
$fixture[2]->httpRequest = $request;
$fixture[3]->beforeUnbind($request->getPath());
$fixture[2]->emit('afterMethod:DELETE', array($request, new Sabre\HTTP\Response(204)));
invitationSchedulingAssert(!$fixture[0]->audit, 'Detaching an organizer view while the shared event remains must not issue global CANCEL');
$fixture = invitationSchedulingFixture($base, 'owner');
$fixture[0]->receipt = array('id' => 42, 'previous_data' => $canonicalMove, 'canonical_data' => null, 'changed' => true);
$fixture[0]->committed = true;
$fixture[0]->present = false;
$fixture[2]->httpRequest = $request;
$fixture[3]->beforeUnbind($request->getPath());
$fixture[2]->emit('afterMethod:DELETE', array($request, new Sabre\HTTP\Response(204)));
invitationSchedulingAssert(count($fixture[0]->audit) === 2 && str_contains($fixture[0]->audit[0]['data'], 'DTSTART:20261120T130000Z'), 'Deletion cancellation must use the event version captured under the native lock');
$fixture = invitationSchedulingFixture($base, 'alice');
$fixture[0]->receipt = array('id' => 42, 'previous_data' => $base, 'canonical_data' => null, 'changed' => true,
	'owner_principal' => 'principals/owner');
$fixture[0]->committed = true;
$fixture[0]->present = false;
$fixture[2]->httpRequest = $request;
$fixture[3]->beforeUnbind($request->getPath());
$fixture[2]->emit('afterMethod:DELETE', array($request, new Sabre\HTTP\Response(204)));
invitationSchedulingAssert(array_column($fixture[0]->audit, 'method') === array('CANCEL', 'CANCEL')
	&& array_column($fixture[0]->audit, 'sender') === array('mailto:owner@example.test', 'mailto:owner@example.test'), 'Delegated native deletion must cancel as its proven organizer instead of replying DECLINED as the delegate');

// Explicit outbox replies apply exactly once to an existing native event, then notify peers.
$fixture = invitationSchedulingFixture($base);
$fixture[0]->replyCanonical = $accepted;
$response = invitationSchedulingPost($fixture, $reply, array('Originator' => '<mailto:ALICE@example.test>'), true);
invitationSchedulingAssert($response->getStatus() === 200 && $fixture[0]->replyApplies === 1 && !$fixture[0]->premature, 'Outbox REPLY must commit once before delivering its notifications');
invitationSchedulingAssert(array_column($fixture[0]->audit, 'recipient') === array('mailto:owner@example.test', 'mailto:bob@example.test'), 'Explicit REPLY must respect backend-authorized local recipient scope');
invitationSchedulingAssert(in_array('{urn:ietf:params:xml:ns:caldav}schedule-send-reply', $fixture[1]->privileges, true), 'Explicit reply requires its scheduling-specific ACL privilege');
$document = new DOMDocument();
invitationSchedulingAssert($document->loadXML($response->getBodyAsString()), 'Explicit reply must return valid native scheduling XML');
$xpath = new DOMXPath($document);
$xpath->registerNamespace('cal', 'urn:ietf:params:xml:ns:caldav');
$xpath->registerNamespace('d', 'DAV:');
invitationSchedulingAssert($xpath->evaluate('string(/cal:schedule-response/cal:response/cal:recipient/d:href)') === 'mailto:owner@example.test', 'Scheduling response must identify the actual local organizer');
invitationSchedulingAssert(str_starts_with($xpath->evaluate('string(/cal:schedule-response/cal:response/cal:request-status)'), '2.0;'), 'Successful outbox reply must report applied native state');
invitationSchedulingPost($fixture, $reply);
invitationSchedulingAssert($fixture[0]->replyCalls === 2 && $fixture[0]->replyApplies === 1 && count($fixture[0]->audit) === 2 && count($fixture[0]->inbox) === 2, 'Explicit REPLY replay must be a successful native no-op without duplicate inbox delivery');
$fixture = invitationSchedulingFixture($base);
$fixture[0]->replyCanonical = str_replace("END:VEVENT\r\n", "ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:external@example.net\r\nEND:VEVENT\r\n", $accepted);
invitationSchedulingPost($fixture, $reply);
invitationSchedulingAssert(array_column($fixture[0]->audit, 'recipient') === array('mailto:owner@example.test', 'mailto:bob@example.test'), 'Explicit native reply must not forward notifications beyond the backend-authorized local principals');

$fixture = invitationSchedulingFixture($base);
$fixture[2]->tree->owner = 'principals/bob';
invitationSchedulingForbidden($fixture, $reply);
$fixture = invitationSchedulingFixture($base);
$fixture[1]->allow = false;
invitationSchedulingForbidden($fixture, $reply);
$fixture = invitationSchedulingFixture($base);
invitationSchedulingForbidden($fixture, str_replace('mailto:alice@example.test', 'mailto:bob@example.test', $reply));
$fixture = invitationSchedulingFixture($base);
invitationSchedulingForbidden($fixture, str_replace('mailto:owner@example.test', 'mailto:external@example.net', $reply));
$fixture = invitationSchedulingFixture($base);
invitationSchedulingForbidden($fixture, $reply, array('Originator' => 'mailto:bob@example.test'));
$fixture = invitationSchedulingFixture($base);
$fixture[0]->replyFailure = true;
try {
	invitationSchedulingPost($fixture, $reply);
	throw new RuntimeException('Stale or unknown native invitation reply was accepted');
} catch (Sabre\DAV\Exception\Conflict $expected) {
	invitationSchedulingAssert(!$fixture[0]->audit && !$fixture[0]->inbox && $fixture[0]->replyApplies === 0, 'Backend UID/sequence failure must not deliver a successful reply');
}

// Exact iTIP retransmission retains a stable opaque inbox URI and no event copy.
$fixture = invitationSchedulingFixture($base, 'owner');
$fixture[0]->committed = true;
$messages = (new Sabre\VObject\ITip\Broker())->parseEvent($base, array('mailto:owner@example.test'), null);
$fixture[3]->scheduleLocalDelivery($messages[0]);
$fixture[3]->scheduleLocalDelivery($messages[0]);
invitationSchedulingAssert($fixture[0]->inboxCalls === 2 && count($fixture[0]->inbox) === 1 && $fixture[0]->current === $base, 'Repeated local delivery must deduplicate its inbox resource without creating or modifying an appointment');
invitationSchedulingAssert((bool) preg_match('~/itip-[a-f0-9]{64}\.ics$~', array_key_first($fixture[0]->inbox)), 'Inbox URI must contain only an opaque delivery hash');
invitationSchedulingAssert(!str_contains(current($fixture[0]->inbox), 'DESCRIPTION') && !str_contains(current($fixture[0]->inbox), 'X-ALT-DESC') && !str_contains(current($fixture[0]->inbox), 'Confidential project'), 'Inbox delivery must not leak descriptions rendered with the actor project permissions');
invitationSchedulingAssert(str_contains($messages[0]->message->serialize(), 'Confidential project') && $fixture[0]->current === $base, 'Recipient redaction must preserve the source message and committed native description');

// Existing Sabre FREEBUSY support must survive inspection of a streamed POST body.
$freeBusy = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VFREEBUSY\r\nUID:freebusy-fixture\r\nDTSTART:20261120T000000Z\r\nDTEND:20261121T000000Z\r\nEND:VFREEBUSY\r\nEND:VCALENDAR\r\n";
$fixture = invitationSchedulingFixture($base);
$response = invitationSchedulingPost($fixture, $freeBusy, array(), true);
invitationSchedulingAssert($fixture[3]->freeBusySeen && $response->getBodyAsString() === 'native-freebusy' && $fixture[0]->replyCalls === 0, 'FREEBUSY must continue through the native Sabre handler with its intact request');
invitationSchedulingAssert(!$invitationLogs, 'No scheduling failure may be swallowed in successful protocol scenarios');
fwrite(STDOUT, "Invitation scheduling: post-commit RSVP, authenticated outbox, updates, cancellation and replay regressions passed.\n");
