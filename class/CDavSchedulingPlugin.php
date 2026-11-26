<?php

namespace Dolibarr\CDav;

/** RFC 6638 local delivery plus a bounded audit journal; deliberately no iMIP. */
class SchedulingPlugin extends \Sabre\CalDAV\Schedule\Plugin
{
	/** @var object */
	private $backend;

	/** @var array<array<string,mixed>> Calendar changes waiting for a successful PUT. */
	private $deferredProtectedMoves = array();

	/** @var array<array<string,mixed>> Calendar deletions awaiting native commit. */
	private $deferredProtectedDeletes = array();

	/** @param object $backend Native calendar backend */
	public function __construct($backend)
	{
		$this->backend = $backend;
	}

	/**
	 * Native writes must commit before their iTIP notifications are emitted.
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
		if ($this->server->httpRequest->getMethod() === 'MOVE') return;
		list($calendarPath, $objectUri) = \Sabre\Uri\split((string) $path);
		list(, $calendarUri) = \Sabre\Uri\split($calendarPath);
		if (preg_match('/^(\d+)-cal-/', $calendarUri, $match)) {
			$existing = $this->backend->getProtectedCalendarObject((int) $match[1], $objectUri);
			if ($existing === null) $existing = $this->backend->getCalendarObject((int) $match[1], $objectUri);
			if ($existing !== null) {
				if ($this->scheduleReply($this->server->httpRequest)) {
					$node = $this->server->tree->getNodeForPath($path);
					$this->deferredProtectedDeletes[] = array('id' => (int) $existing['id'],
						'calendar' => (int) $match[1], 'uri' => $objectUri,
						'old' => (string) $existing['calendardata'], 'owner' => $node->getOwner());
				}
				return;
			}
		}
		parent::beforeUnbind($path);
	}

	/**
	 * Send organizer cancellations or attendee declines after the native delete.
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
				$receipt = method_exists($this->backend, 'getCommittedSchedulingChange')
					? $this->backend->getCommittedSchedulingChange($deleted['calendar'], $deleted['uri']) : null;
				if ($receipt !== null) {
					if (empty($receipt['changed']) || $receipt['previous_data'] === null) continue;
					$deleted['old'] = (string) $receipt['previous_data'];
					$deleted['id'] = (int) ($receipt['id'] ?? $deleted['id']);
					$deleted['owner'] = $receipt['owner_principal'] ?? $deleted['owner'];
				}
				$stillPresent = $this->backend->calendarEventExists($deleted['id']);
				$addresses = $this->getAddressesForPrincipal((string) $deleted['owner']);
				$broker = new \Sabre\VObject\ITip\Broker();
				foreach ($broker->parseEvent(null, $addresses, $deleted['old']) as $message) {
					// Removing one shared calendar is never an organizer cancellation.
					// An attendee removal can still reply DECLINED to the organizer.
					if ($stillPresent && $message->method === 'CANCEL') continue;
					$this->deliver($message);
				}
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__.': appointment deleted but scheduling cancellation failed: '.$e->getMessage(), LOG_ERR);
			}
		}
	}

	/**
	 * Defer all scheduling until locked ETag and native business checks commit.
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
		if (preg_match('/^(\d+)-cal-/', $calendarUri, $match)) {
			$this->backend->validateProtectedCalendarWrite((int) $match[1], $objectUri, $vCal->serialize(), (bool) $isNew);
			if ($this->scheduleReply($request)) {
				$existing = $this->backend->getCalendarObject((int) $match[1], $objectUri);
				$calendarNode = $this->server->tree->getNodeForPath($calendarPath);
				$this->deferredProtectedMoves[] = array('calendar' => (int) $match[1], 'uri' => $objectUri,
					'old' => $existing === null ? null : (string) $existing['calendardata'], 'owner' => $calendarNode->getOwner());
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
				$receipt = method_exists($this->backend, 'getCommittedSchedulingChange')
					? $this->backend->getCommittedSchedulingChange($move['calendar'], $move['uri']) : null;
				if ($receipt !== null) {
					if (empty($receipt['changed'])) continue;
					$move['old'] = $receipt['previous_data'];
					$move['owner'] = $receipt['owner_principal'] ?? $move['owner'];
					$current = $receipt['canonical_data'] === null ? null : array('calendardata' => $receipt['canonical_data']);
				} else {
					$current = $this->backend->getCalendarObject($move['calendar'], $move['uri']);
				}
				if ($current === null) {
					continue;
				}
				$old = $move['old'] === null ? null : \Sabre\VObject\Reader::read($move['old']);
				$new = \Sabre\VObject\Reader::read((string) $current['calendardata']);
				try {
					if (!empty($receipt['omit_location'])) unset($new->VEVENT->LOCATION);
					$this->notifyManagedAttachmentChange($old, $new, $move['owner']);
				} finally {
					if ($old !== null) $old->destroy();
					$new->destroy();
				}
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__.': appointment committed but scheduling notification failed: '.$e->getMessage(), LOG_ERR);
			}
		}
	}

	/**
	 * Apply an authenticated attendee REPLY to the existing native appointment.
	 * FREEBUSY and other methods retain Sabre's existing endpoint behavior.
	 *
	 * @param \Sabre\CalDAV\Schedule\IOutbox $outboxNode Scheduling outbox
	 * @param \Sabre\HTTP\RequestInterface $request Current request
	 * @param \Sabre\HTTP\ResponseInterface $response Current response
	 * @return void
	 */
	public function outboxRequest(\Sabre\CalDAV\Schedule\IOutbox $outboxNode, \Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response)
	{
		$data = $request->getBodyAsString();
		try {
			$calendar = \Sabre\VObject\Reader::read($data);
		} catch (\Throwable $error) {
			throw new \Sabre\DAV\Exception\BadRequest('A valid iCalendar scheduling message is required');
		}
		try {
			if ($calendar->name !== 'VCALENDAR') throw new \Sabre\DAV\Exception\BadRequest('A scheduling message must be a VCALENDAR');
			if (!isset($calendar->METHOD) || strtoupper((string) $calendar->METHOD) !== 'REPLY') {
				$request->setBody($data);
				parent::outboxRequest($outboxNode, $request, $response);
				return;
			}
			$acl = $this->server->getPlugin('acl');
			$principal = $acl ? $acl->getCurrentUserPrincipal() : null;
			if (!$principal || trim((string) $outboxNode->getOwner(), '/') !== trim((string) $principal, '/')) {
				throw new \Sabre\DAV\Exception\Forbidden('An invitation reply must use the authenticated user outbox');
			}
			$acl->checkPrivileges($request->getPath(), '{'.self::NS_CALDAV.'}schedule-send-reply');
			$addresses = array_map(array($this, 'normalizeSchedulingAddress'), $this->getAddressesForPrincipal($principal));
			if (!$addresses) throw new \Sabre\DAV\Exception\Forbidden('The replying user has no calendar address');
			$events = 0;
			foreach ($calendar->getComponents() as $component) {
				if ($component->name === 'VTIMEZONE') continue;
				if ($component->name !== 'VEVENT') throw new \Sabre\DAV\Exception\BadRequest('Invitation replies must contain only VEVENT components');
				$events++;
				$ownAttendees = 0;
				foreach ($component->select('ATTENDEE') as $attendee) {
					if (in_array($this->normalizeSchedulingAddress((string) $attendee), $addresses, true)) $ownAttendees++;
				}
				if ($ownAttendees !== 1) throw new \Sabre\DAV\Exception\Forbidden('The replying attendee must match the authenticated calendar user');
				if (count($component->select('ORGANIZER')) !== 1 || !$acl->getPrincipalByUri((string) $component->ORGANIZER)) {
					throw new \Sabre\DAV\Exception\Forbidden('Invitation replies require an existing local organizer');
				}
			}
			if (!$events || count($calendar->select('METHOD')) !== 1) throw new \Sabre\DAV\Exception\BadRequest('An unambiguous invitation reply is required');
			$originator = $request->getHeader('Originator');
			if ($originator && !in_array($this->normalizeSchedulingAddress($originator), $addresses, true)) {
				throw new \Sabre\DAV\Exception\Forbidden('The scheduling originator must match the authenticated calendar user');
			}
			// This API verifies the UID, organizer, sequence and attendee under the
			// native lock, commits once and returns the authoritative shared event.
			$result = $this->backend->processSchedulingReply(trim((string) $principal, '/'), $data);
			if (!empty($result['changed'])) {
				$old = \Sabre\VObject\Reader::read((string) $result['previous_data']);
				$new = \Sabre\VObject\Reader::read((string) $result['canonical_data']);
				try {
					if (!empty($result['omit_location'])) unset($new->VEVENT->LOCATION);
					$this->deliverCommittedChange($old, $new, $principal, $result['recipients']);
				} catch (\Throwable $error) {
					dol_syslog(__METHOD__.': reply committed but scheduling notification failed: '.$error->getMessage(), LOG_ERR);
				} finally {
					$old->destroy();
					$new->destroy();
				}
			}
			$organizerAddress = (string) ($result['organizer_address'] ?? $calendar->VEVENT->ORGANIZER);
			$document = new \DOMDocument('1.0', 'utf-8');
			$root = $document->createElementNS(self::NS_CALDAV, 'cal:schedule-response');
			$document->appendChild($root);
			$item = $document->createElementNS(self::NS_CALDAV, 'cal:response');
			$recipient = $document->createElementNS(self::NS_CALDAV, 'cal:recipient');
			$href = $document->createElementNS('DAV:', 'd:href');
			$href->appendChild($document->createTextNode($organizerAddress));
			$recipient->appendChild($href);
			$item->appendChild($recipient);
			$status = $document->createElementNS(self::NS_CALDAV, 'cal:request-status');
			$status->appendChild($document->createTextNode('2.0;Reply applied to the existing appointment'));
			$item->appendChild($status);
			$root->appendChild($item);
			$response->setStatus(200);
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
			$response->setBody($document->saveXML());
		} finally {
			$calendar->destroy();
		}
	}

	/**
	 * Normalize a calendar-user address without treating it as a business identity.
	 *
	 * @param string $address Calendar address
	 * @return string Normalized address
	 */
	private function normalizeSchedulingAddress($address)
	{
		return strtolower(trim((string) $address, " \t\r\n<>"));
	}

	/**
	 * Journal and deliver one iTIP message.
	 *
	 * @param \Sabre\VObject\ITip\Message $message Scheduling message
	 * @return void
	 */
	public function deliver(\Sabre\VObject\ITip\Message $message)
	{
		$source = $message->message;
		$message->message = clone $source;
		try {
			$this->removePrivateNotificationData($message->message);
			try {
				$this->backend->recordSchedulingMessage($message);
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__.': unable to journal scheduling message: '.$e->getMessage(), LOG_ERR);
			}
			parent::deliver($message);
		} finally {
			$message->message->destroy();
			$message->message = $source;
		}
	}

	/**
	 * Participants retrieve their own calendar-scoped notes and reminders with a GET.
	 * @param \Sabre\VObject\Component\VCalendar $notification Outgoing message copy
	 * @return void
	 */
	private function removePrivateNotificationData($notification)
	{
		foreach ($notification->getComponents() as $component) {
			if ($component->name === 'VEVENT' || $component->name === 'VTODO') {
				unset($component->DESCRIPTION, $component->{'X-ALT-DESC'}, $component->VALARM);
			}
		}
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
			$notification = clone $message->message;
			try {
				$this->removePrivateNotificationData($notification);
				$data = $notification->serialize();
			} finally {
				$notification->destroy();
			}
			$uri = 'itip-'.hash('sha256', (string) $message->sender."\0".(string) $message->recipient."\0".$data).'.ics';
			$this->backend->createSchedulingObject($principal, $uri, $data);
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
		$this->deliverCommittedChange($oldCalendar, $newCalendar, $ownerPrincipal);
	}

	/**
	 * Use Sabre's broker for committed changes and relay attendee replies to peers.
	 *
	 * @param \Sabre\VObject\Component\VCalendar|null $oldCalendar Previous state
	 * @param \Sabre\VObject\Component\VCalendar $newCalendar Committed state
	 * @param string $ownerPrincipal Principal making the calendar change
	 * @param string[]|null $allowedPrincipals Optional explicit-reply recipient scope
	 * @return void
	 */
	private function deliverCommittedChange($oldCalendar, $newCalendar, $ownerPrincipal, $allowedPrincipals = null)
	{
		$addresses = $this->getAddressesForPrincipal((string) $ownerPrincipal);
		$broker = new \Sabre\VObject\ITip\Broker();
		$organizer = isset($newCalendar->VEVENT->ORGANIZER) ? $this->normalizeSchedulingAddress((string) $newCalendar->VEVENT->ORGANIZER) : '';
		$isOrganizer = in_array($organizer, array_map(array($this, 'normalizeSchedulingAddress'), $addresses), true);
		$isCancelled = isset($newCalendar->VEVENT->STATUS) && strtoupper((string) $newCalendar->VEVENT->STATUS) === 'CANCELLED';
		$wasCancelled = $oldCalendar !== null && isset($oldCalendar->VEVENT->STATUS) && strtoupper((string) $oldCalendar->VEVENT->STATUS) === 'CANCELLED';
		if ($isOrganizer && $isCancelled) {
			// Sabre treats STATUS:CANCELLED PUTs as REQUEST. Generate its native
			// CANCEL shape while retaining the sequence committed by our backend.
			$messages = $oldCalendar === null || $wasCancelled ? array() : $broker->parseEvent(null, $addresses, $oldCalendar);
			foreach ($messages as $message) {
				$message->sequence = isset($newCalendar->VEVENT->SEQUENCE) ? (int) (string) $newCalendar->VEVENT->SEQUENCE : 0;
				foreach ($message->message->select('VEVENT') as $event) $event->SEQUENCE = $message->sequence;
			}
		} else {
			$messages = $broker->parseEvent($newCalendar, $addresses, $oldCalendar);
		}
		$replies = array_filter($messages, static function ($message) {
			return $message->method === 'REPLY';
		});
		foreach ($replies as $reply) {
			// This is Sabre's normal local-REPLY redistribution, without applying
			// the same reply a second time to our shared native appointment.
			foreach ($broker->parseEvent($newCalendar, array($reply->recipient), $oldCalendar) as $notice) {
				if ($notice->recipient !== $reply->sender) $messages[] = $notice;
			}
		}
		$sent = array();
		foreach ($messages as $message) {
			if ($allowedPrincipals !== null) {
				$acl = $this->server->getPlugin('acl');
				$principal = $acl ? $acl->getPrincipalByUri((string) $message->recipient) : null;
				if (!$principal || !in_array(trim($principal, '/'), $allowedPrincipals, true)) continue;
			}
			$key = hash('sha256', $message->method."\0".$message->sender."\0".$message->recipient."\0".$message->message->serialize());
			if (isset($sent[$key])) continue;
			$sent[$key] = true;
			$this->deliver($message);
		}
	}
}
