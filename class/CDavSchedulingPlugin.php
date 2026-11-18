<?php

namespace Dolibarr\CDav;

/** RFC 6638 local delivery plus a bounded audit journal; deliberately no iMIP. */
class SchedulingPlugin extends \Sabre\CalDAV\Schedule\Plugin
{
	/** @var object */
	private $backend;

	/** @var array<array<string,mixed>> Protected moves waiting for a successful PUT. */
	private $deferredProtectedMoves = array();

	/** @var array<array<string,mixed>> Protected deletions awaiting native commit. */
	private $deferredProtectedDeletes = array();

	/** @param object $backend Native calendar backend */
	public function __construct($backend)
	{
		$this->backend = $backend;
	}

	/**
	 * Native protected writes must commit before their iTIP notifications are emitted.
	 *
	 * @param \Sabre\DAV\Server $server Native DAV server
	 * @return void
	 */
	public function initialize(\Sabre\DAV\Server $server)
	{
		parent::initialize($server);
		$server->on('afterMethod:PUT', array($this, 'notifyProtectedMoves'), 80);
		$server->on('afterMethod:DELETE', array($this, 'notifyProtectedDeletes'), 80);
	}

	/**
	 * Defer cancellation until deletion commits; a shared detachment is not a cancellation.
	 *
	 * @param string $path Calendar object path
	 * @return void
	 */
	public function beforeUnbind($path)
	{
		list($calendarPath, $objectUri) = \Sabre\Uri\split((string) $path);
		list(, $calendarUri) = \Sabre\Uri\split($calendarPath);
		if (preg_match('/^(\d+)-cal-/', $calendarUri, $match)) {
			$existing = $this->backend->getProtectedCalendarObject((int) $match[1], $objectUri);
			if ($existing !== null) {
				if ($this->scheduleReply($this->server->httpRequest)) {
					$node = $this->server->tree->getNodeForPath($path);
					$this->deferredProtectedDeletes[] = array('id' => (int) $existing['id'],
						'old' => (string) $existing['calendardata'], 'owner' => $node->getOwner());
				}
				return;
			}
		}
		parent::beforeUnbind($path);
	}

	/**
	 * Emit cancellation only after the last assignment and native event are deleted.
	 *
	 * @param \Sabre\HTTP\RequestInterface $request Completed delete request
	 * @param \Sabre\HTTP\ResponseInterface $response Final HTTP response
	 * @return void
	 */
	public function notifyProtectedDeletes(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response)
	{
		$pending = $this->deferredProtectedDeletes;
		$this->deferredProtectedDeletes = array();
		if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
			return;
		}
		foreach ($pending as $deleted) {
			try {
				if ($this->backend->calendarEventExists($deleted['id'])) {
					continue;
				}
				$addresses = $this->getAddressesForPrincipal((string) $deleted['owner']);
				$broker = new \Sabre\VObject\ITip\Broker();
				foreach ($broker->parseEvent(null, $addresses, $deleted['old']) as $message) {
					$this->deliver($message);
				}
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__.': appointment deleted but scheduling cancellation failed: '.$e->getMessage(), LOG_ERR);
			}
		}
	}

	/**
	 * Defer scheduling for moves that may still fail a locked ETag or business check.
	 *
	 * @param \Sabre\HTTP\RequestInterface $request Current request
	 * @param \Sabre\HTTP\ResponseInterface $response Current response
	 * @param \Sabre\VObject\Component\VCalendar $vCal Replacement calendar
	 * @param string $calendarPath Parent calendar path
	 * @param bool $modified Whether a preceding listener modified the representation
	 * @param bool $isNew Whether a new resource is requested
	 * @return void
	 */
	public function calendarObjectChange(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response, \Sabre\VObject\Component\VCalendar $vCal, $calendarPath, &$modified, $isNew)
	{
		list(, $calendarUri) = \Sabre\Uri\split((string) $calendarPath);
		list(, $objectUri) = \Sabre\Uri\split((string) $request->getPath());
		if (preg_match('/^(\d+)-cal-/', $calendarUri, $match)
			&& $this->backend->validateProtectedCalendarWrite((int) $match[1], $objectUri, $vCal->serialize(), (bool) $isNew) !== false) {
			if ($this->scheduleReply($request)) {
				$existing = $this->backend->getCalendarObject((int) $match[1], $objectUri);
				$calendarNode = $this->server->tree->getNodeForPath($calendarPath);
				$this->deferredProtectedMoves[] = array('calendar' => (int) $match[1], 'uri' => $objectUri,
					'old' => (string) $existing['calendardata'], 'owner' => $calendarNode->getOwner());
			}
			return;
		}
		parent::calendarObjectChange($request, $response, $vCal, $calendarPath, $modified, $isNew);
	}

	/**
	 * Publish the committed representation, never a stale client-side replacement.
	 *
	 * @param \Sabre\HTTP\RequestInterface $request Completed put request
	 * @param \Sabre\HTTP\ResponseInterface $response Final HTTP response
	 * @return void
	 */
	public function notifyProtectedMoves(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response)
	{
		$pending = $this->deferredProtectedMoves;
		$this->deferredProtectedMoves = array();
		if ($response->getStatus() < 200 || $response->getStatus() >= 300) {
			return;
		}
		foreach ($pending as $move) {
			try {
				$current = $this->backend->getCalendarObject($move['calendar'], $move['uri']);
				if ($current === null) {
					continue;
				}
				$old = \Sabre\VObject\Reader::read($move['old']);
				$new = \Sabre\VObject\Reader::read((string) $current['calendardata']);
				try {
					$this->notifyManagedAttachmentChange($old, $new, $move['owner']);
				} finally {
					$old->destroy();
					$new->destroy();
				}
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__.': protected appointment committed but scheduling notification failed: '.$e->getMessage(), LOG_ERR);
			}
		}
	}

	/**
	 * Journal and deliver one iTIP message.
	 *
	 * @param \Sabre\VObject\ITip\Message $message Scheduling message
	 * @return void
	 */
	public function deliver(\Sabre\VObject\ITip\Message $message)
	{
		try {
			$this->backend->recordSchedulingMessage($message);
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.': unable to journal scheduling message: '.$e->getMessage(), LOG_ERR);
		}
		parent::deliver($message);
	}

	/**
	 * Deliver to the persistent inbox without creating a second ActionComm.
	 *
	 * The regular Sabre local transport copies an invitation into the target
	 * calendar before the organizer's PUT has reached the backend. CDav maps
	 * internal ATTENDEE values to the same native ActionComm resource, so that
	 * eager copy would race the original PUT and duplicate its UID. The native
	 * participant assignment supplies the live calendar view; the inbox keeps
	 * the iTIP message independently for RFC 6638 clients.
	 *
	 * @param \Sabre\VObject\ITip\Message $message Scheduling message
	 * @return void
	 */
	public function scheduleLocalDelivery(\Sabre\VObject\ITip\Message $message)
	{
		$acl = $this->server->getPlugin('acl');
		$principal = $acl ? $acl->getPrincipalByUri((string) $message->recipient) : null;
		if (!$principal) {
			$message->scheduleStatus = '3.7;Could not find principal.';
			return;
		}
		try {
			$uri = 'sabredav-'.\Sabre\DAV\UUIDUtil::getUUID().'.ics';
			$this->backend->createSchedulingObject($principal, $uri, $message->message->serialize());
			$message->scheduleStatus = '1.2;Message delivered locally';
		} catch (\Throwable $e) {
			dol_syslog(__METHOD__.': local scheduling delivery failed: '.$e->getMessage(), LOG_ERR);
			$message->scheduleStatus = '5.2;Could not persist local scheduling message';
		}
	}

	/**
	 * Notify attendees after the RFC 8607 implicit calendar PUT succeeded.
	 *
	 * @param \Sabre\VObject\Component\VCalendar $oldCalendar Previous native representation
	 * @param \Sabre\VObject\Component\VCalendar $newCalendar Committed representation
	 * @param string $ownerPrincipal Calendar owner principal
	 * @return void
	 */
	public function notifyManagedAttachmentChange($oldCalendar, $newCalendar, $ownerPrincipal)
	{
		$modified = false;
		$addresses = $this->getAddressesForPrincipal((string) $ownerPrincipal);
		$this->processICalendarChange($oldCalendar, $newCalendar, $addresses, array(), $modified);
	}
}
