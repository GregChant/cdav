<?php

namespace Dolibarr\CDav;

use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject;

/** Secure, no-per-recurrence implementation of RFC 8607 managed attachments. */
class ManagedAttachmentPlugin extends DAV\ServerPlugin
{
	private const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';
	/** @var DAV\Server */
	private $server;
	/** @var object */
	private $backend;
	/** @var ManagedAttachmentStore */
	private $store;
	/** @var object */
	private $user;

	public function __construct($backend, ManagedAttachmentStore $store, $user)
	{
		$this->backend = $backend;
		$this->store = $store;
		$this->user = $user;
	}

	public function initialize(DAV\Server $server)
	{
		$this->server = $server;
		$server->on('method:POST', array($this, 'httpPost'), 200);
		$server->on('afterMethod:GET', array($this, 'httpAfterGet'), 250);
		$server->on('propFind', array($this, 'propFind'));
	}

	public function getPluginName() { return 'cdav-managed-attachments'; }
	public function getFeatures() { return array('calendar-managed-attachments', 'calendar-managed-attachments-no-recurrence'); }

	public function propFind(DAV\PropFind $propFind, DAV\INode $node)
	{
		if ($node instanceof CalDAV\ICalendarObjectContainer) {
			$propFind->handle('{'.self::NS_CALDAV.'}max-attachment-size', $this->store->maxBytes());
			$propFind->handle('{'.self::NS_CALDAV.'}max-attachments-per-resource', $this->store->maxPerResource());
		}
	}

	/** Give managed bytes a safe download name without exposing their storage name. */
	public function httpAfterGet(RequestInterface $request, ResponseInterface $response)
	{
		try {
			$node = $this->server->tree->getNodeForPath($request->getPath());
		} catch (\Throwable $e) {
			return;
		}
		if ($node instanceof ManagedAttachmentFile) {
			$response->setHeader('Content-Disposition', $node->getContentDisposition());
		}
	}

	public function httpPost(RequestInterface $request, ResponseInterface $response)
	{
		$params = $request->getQueryParameters();
		if (!isset($params['action'])) return;
		$action = is_array($params['action']) ? '' : (string) $params['action'];
		if (!in_array($action, array('attachment-add', 'attachment-update', 'attachment-remove'), true)) return;
		if (isset($params['rid'])) {
			throw new DAV\Exception\BadRequest('Per-recurrence managed attachments are not supported');
		}
		$path = $request->getPath();
		$putSucceeded = false;
		try {
			$node = $this->server->tree->getNodeForPath($path);
		} catch (DAV\Exception\NotFound $e) {
			return;
		}
		if (!$node instanceof CalDAV\ICalendarObject) return;
		$acl = $this->server->getPlugin('acl');
		if ($acl) $acl->checkPrivileges($path, '{DAV:}write-content');

		list($calendarPath, $objectUri) = \Sabre\Uri\split($path);
		list(, $calendarUri) = \Sabre\Uri\split($calendarPath);
		if (!preg_match('/^(\d+)-cal-/', $calendarUri, $match)) throw new DAV\Exception\BadRequest('Invalid Dolibarr calendar path');
		$calendarId = (int) $match[1];
		$eventId = $this->backend->resolveManagedAttachmentTarget($calendarId, $objectUri);
		$oldData = (string) $node->get();
		$expectedEtag = '"'.md5($oldData).'"';
		$this->assertIfMatch((string) $request->getHeader('If-Match'), $expectedEtag);
		$calendar = VObject\Reader::read($oldData);
		$components = array();
		foreach ($calendar->getComponents() as $component) {
			if (in_array($component->name, array('VEVENT', 'VTODO'), true)) $components[] = $component;
		}
		if (!$components) throw new DAV\Exception\BadRequest('Managed attachments require a calendar component');
		$this->assertOrganizer($node, $components[0]);

		$managedId = isset($params['managed-id']) && !is_array($params['managed-id']) ? (string) $params['managed-id'] : '';
		if (($action === 'attachment-add' && $managedId !== '') || ($action !== 'attachment-add' && $managedId === '')) {
			throw new DAV\Exception\BadRequest('Invalid managed-id parameter for this attachment action');
		}
		$newRow = null;
		try {
			if ($action === 'attachment-add' || $action === 'attachment-update') {
				$contentType = (string) $request->getHeader('Content-Type');
				$filename = $this->contentDispositionFilename((string) $request->getHeader('Content-Disposition'));
				if ($filename === '') $filename = 'attachment.bin';
				$newRow = $this->store->create($eventId, $request->getBody(), $contentType, $filename,
					$action === 'attachment-update' ? $managedId : '');
				$newId = (string) $newRow->managed_id;
				$url = $this->store->attachmentUrl($newId);
				$foundOld = $action === 'attachment-add';
				foreach ($components as $component) {
					if ($action === 'attachment-update') {
						foreach ($component->select('ATTACH') as $attachment) {
							if (isset($attachment['MANAGED-ID']) && hash_equals($managedId, (string) $attachment['MANAGED-ID'])) {
								$component->remove($attachment);
								$foundOld = true;
							}
						}
					}
					$component->add('ATTACH', $url, array(
						'MANAGED-ID' => $newId,
						'FILENAME' => $this->store->originalFilename($newRow),
						'FMTTYPE' => (string) $newRow->content_type,
						'SIZE' => (string) $newRow->file_size,
					));
				}
				if (!$foundOld) throw new DAV\Exception\Conflict('The managed-id is not referenced by this calendar object');
				$managedId = $newId;
			} else {
				$this->store->getById($managedId, $eventId, true);
				$removed = false;
				foreach ($components as $component) {
					foreach ($component->select('ATTACH') as $attachment) {
						if (isset($attachment['MANAGED-ID']) && hash_equals($managedId, (string) $attachment['MANAGED-ID'])) {
							$component->remove($attachment);
							$removed = true;
						}
					}
				}
				if (!$removed) throw new DAV\Exception\Conflict('The managed-id is not referenced by this calendar object');
			}

			$newData = $calendar->serialize();
			$this->backend->expectCalendarVersion($calendarId, $objectUri, $expectedEtag);
			$node->put($newData);
			$putSucceeded = true;
		} catch (\Throwable $e) {
			if ($newRow && !$putSucceeded) {
				try { $this->store->remove((string) $newRow->managed_id, $eventId, true); } catch (\Throwable $cleanupError) { }
			}
			throw $e;
		}
		$storedObject = $this->backend->getCalendarObject($calendarId, $objectUri);
		if ($storedObject === null) {
			throw new DAV\Exception\NotFound('Calendar object disappeared after its attachment update');
		}
		$storedData = (string) $storedObject['calendardata'];
		$schedule = $this->server->getPlugin('caldav-schedule');
		if ($schedule instanceof SchedulingPlugin) {
			try {
				$schedule->notifyManagedAttachmentChange(VObject\Reader::read($oldData), $calendar, $node->getOwner());
			} catch (\Throwable $e) {
				dol_syslog(__METHOD__.': unable to notify attendees after managed attachment change: '.$e->getMessage(), LOG_ERR);
			}
		}

		$prefer = strtolower((string) $request->getHeader('Prefer'));
		$returnRepresentation = str_contains($prefer, 'return=representation');
		$response->setStatus($action === 'attachment-add' ? 201 : ($returnRepresentation ? 200 : 204));
		$response->setHeader('ETag', (string) $storedObject['etag']);
		$response->setHeader('Content-Location', (string) $request->getUrl());
		if ($action !== 'attachment-remove') $response->setHeader('Cal-Managed-ID', $managedId);
		if ($returnRepresentation) {
			$response->setHeader('Content-Type', 'text/calendar; charset=utf-8');
			$response->setBody($storedData);
		}
		return false;
	}

	/** If-Match uses strong ETag comparison; a missing header remains supported. */
	private function assertIfMatch($header, $currentEtag)
	{
		if (trim((string) $header) === '') {
			return;
		}
		foreach (explode(',', (string) $header) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '*' || (!str_starts_with($candidate, 'W/') && hash_equals((string) $currentEtag, $candidate))) {
				return;
			}
		}
		throw new DAV\Exception\PreconditionFailed('If-Match does not match the calendar object', 'If-Match');
	}

	private function assertOrganizer($node, $component)
	{
		$principal = 'principals/'.(string) $this->user->login;
		if (!hash_equals($principal, (string) $node->getOwner())) {
			throw new DAV\Exception\Forbidden('Only the calendar owner may manage attachment bytes');
		}
		if (isset($component->ORGANIZER)) {
			$email = strtolower(preg_replace('/^mailto:/i', '', trim((string) $component->ORGANIZER)));
			if ($email === '' || !hash_equals(strtolower((string) $this->user->email), $email)) {
				throw new DAV\Exception\Forbidden('Only the scheduled event organizer may manage attachments');
			}
		}
	}

	private function contentDispositionFilename($header)
	{
		if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $header, $match)) return rawurldecode(trim($match[1], " \t\""));
		if (preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/i', $header, $match)) return stripcslashes($match[1]);
		if (preg_match('/filename\s*=\s*([^;]+)/i', $header, $match)) return trim($match[1], " \t\"");
		return '';
	}
}
