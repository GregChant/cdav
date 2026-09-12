<?php

namespace Dolibarr\CDav;

/** RFC 6638 local delivery plus a bounded audit journal; deliberately no iMIP. */
class SchedulingPlugin extends \Sabre\CalDAV\Schedule\Plugin
{
	/** @var object */
	private $backend;

	public function __construct($backend)
	{
		$this->backend = $backend;
	}

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

	/** Notify attendees after the RFC 8607 implicit calendar PUT succeeded. */
	public function notifyManagedAttachmentChange($oldCalendar, $newCalendar, $ownerPrincipal)
	{
		$modified = false;
		$addresses = $this->getAddressesForPrincipal((string) $ownerPrincipal);
		$this->processICalendarChange($oldCalendar, $newCalendar, $addresses, array(), $modified);
	}
}
