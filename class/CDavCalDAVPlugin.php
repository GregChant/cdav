<?php

namespace Dolibarr\CDav;

require_once __DIR__.'/CDavMultiGetReport.php';
require_once __DIR__.'/CDavRequestGuardPlugin.php';
require_once __DIR__.'/CDavCalendarWritePolicy.php';

/** CalDAV compatibility fixes layered on Dolibarr's bundled Sabre plugin. */
class CalDAVPlugin extends \Sabre\CalDAV\Plugin
{
	/** @var bool */
	private $delegationEnabled;

	/** @var object|null Native backend used before implicit scheduling is dispatched. */
	private $backend;

	/**
	 * Keep the advertised CalDAV limit equal to the HTTP request guard.
	 *
	 * @param int $maxResourceSize Maximum calendar bytes
	 * @param bool $delegationEnabled Whether native delegation is enabled
	 * @param object|null $backend Native calendar backend
	 */
	public function __construct($maxResourceSize, $delegationEnabled = false, $backend = null)
	{
		$this->maxResourceSize = max(1, (int) $maxResourceSize);
		$this->delegationEnabled = (bool) $delegationEnabled;
		$this->backend = $backend;
	}

	/**
	 * Check protected fields before Sabre's scheduling plugin can produce effects.
	 *
	 * @param \Sabre\DAV\Server $server Native DAV server
	 * @return void
	 */
	public function initialize(\Sabre\DAV\Server $server)
	{
		parent::initialize($server);
		$server->on('calendarObjectChange', array($this, 'guardNativeAppointmentChange'), 10);
	}

	/**
	 * Validate an implicit write before scheduling listeners run.
	 *
	 * @param \Sabre\HTTP\RequestInterface $request Current request
	 * @param \Sabre\HTTP\ResponseInterface $response Current response
	 * @param \Sabre\VObject\Component\VCalendar $calendar Replacement calendar
	 * @param string $calendarPath Parent calendar path
	 * @param bool $modified Whether a preceding listener modified the representation
	 * @param bool $isNew Whether a new resource is requested
	 * @return void
	 */
	public function guardNativeAppointmentChange($request, $response, $calendar, $calendarPath, &$modified, $isNew)
	{
		if (!$this->backend) {
			return;
		}
		list(, $calendarUri) = \Sabre\Uri\split((string) $calendarPath);
		list(, $objectUri) = \Sabre\Uri\split((string) $request->getPath());
		if (preg_match('/^(\d+)-cal-/', $calendarUri, $match)) {
			$calendarId = (int) $match[1];
			$restored = !$isNew && $request->getMethod() === 'PUT'
				&& CalendarWritePolicy::isOutlookSynchronizer((string) $request->getHeader('User-Agent'))
				&& $this->restoreOutlookAttachments($calendar, $calendarId, $objectUri);
			$calendarData = $calendar->serialize();
			if ($restored) {
				if (strlen($calendarData) > $this->maxResourceSize) {
					throw new RequestEntityTooLarge('The calendar data exceeds the CalDAV resource size limit');
				}
				$modified = true;
			}
			$this->backend->validateProtectedCalendarWrite($calendarId, $objectUri, $calendarData, (bool) $isNew);
		}
	}

	/**
	 * Restore attachments omitted when the Outlook add-in rebuilds an existing event.
	 * @param \Sabre\VObject\Component\VCalendar $calendar Incoming Outlook calendar
	 * @param int $calendarId Native calendar owner
	 * @param string $objectUri Resource name
	 * @return bool Whether attachments were restored
	 */
	private function restoreOutlookAttachments($calendar, int $calendarId, string $objectUri): bool
	{
		$targets = array();
		foreach ($calendar->getComponents() as $component) {
			if (!in_array($component->name, array('VEVENT', 'VTODO'), true)) continue;
			if (isset($component->ATTACH)) return false;
			$targets[$this->attachmentComponentKey($component)] = $component;
		}
		if (!$targets) return false;
		$existing = $this->backend->getCalendarObject($calendarId, $objectUri);
		if ($existing === null) return false;
		$before = \Sabre\VObject\Reader::read((string) $existing['calendardata']);
		$restored = false;
		try {
			foreach ($before->getComponents() as $component) {
				if (!in_array($component->name, array('VEVENT', 'VTODO'), true) || !isset($component->ATTACH)) continue;
				$key = $this->attachmentComponentKey($component);
				if (!isset($targets[$key])) {
					throw new \Sabre\DAV\Exception\Conflict('An Outlook update would discard an appointment instance with attachments');
				}
				foreach ($component->select('ATTACH') as $attachment) $targets[$key]->add(clone $attachment);
				$restored = true;
			}
		} finally {
			$before->destroy();
		}
		if (!$restored) return false;
		$this->backend->expectCalendarVersion($calendarId, $objectUri, (string) $existing['etag'], true);
		return true;
	}

	/**
	 * Match attachments to the same event or recurrence exception.
	 * @param \Sabre\VObject\Component $component Calendar event or task
	 * @return string Component identity
	 */
	private function attachmentComponentKey($component): string
	{
		return $component->name.'|'.(string) $component->UID.'|'
			.(isset($component->{'RECURRENCE-ID'}) ? $component->{'RECURRENCE-ID'}->serialize() : '');
	}

	/**
	 * Advertise calendar-proxy only when backed by Dolibarr ACLs.
	 *
	 * @return array<string> Supported CalDAV features
	 */
	public function getFeatures()
	{
		return $this->delegationEnabled
			? array('calendar-access', 'calendar-proxy')
			: array('calendar-access');
	}

	/**
	 * Return an explicit status for every calendar-multiget href.
	 *
	 * @param object $report Parsed multiget request
	 * @return void
	 */
	public function calendarMultiGetReport($report)
	{
		$needsJson = 'application/calendar+json' === $report->contentType;
		$timeZones = array();
		$propertyList = array();

		foreach (MultiGetReport::properties($this->server, $report) as $path => $properties) {
			if (($needsJson || $report->expand) && isset($properties[200]['{'.self::NS_CALDAV.'}calendar-data'])) {
				$vObject = \Sabre\VObject\Reader::read($properties[200]['{'.self::NS_CALDAV.'}calendar-data']);
				if ($report->expand) {
					list($calendarPath) = \Sabre\Uri\split($path);
					if (!isset($timeZones[$calendarPath])) {
						$timezoneProperty = '{'.self::NS_CALDAV.'}calendar-timezone';
						$timezoneResult = $this->server->getProperties($calendarPath, array($timezoneProperty));
						if (isset($timezoneResult[$timezoneProperty])) {
							$timezoneObject = \Sabre\VObject\Reader::read($timezoneResult[$timezoneProperty]);
							$timeZones[$calendarPath] = $timezoneObject->VTIMEZONE->getTimeZone();
							$timezoneObject->destroy();
						} else {
							$timeZones[$calendarPath] = new \DateTimeZone('UTC');
						}
					}
					$vObject = $vObject->expand($report->expand['start'], $report->expand['end'], $timeZones[$calendarPath]);
				}
				$properties[200]['{'.self::NS_CALDAV.'}calendar-data'] = $needsJson
					? json_encode($vObject->jsonSerialize())
					: $vObject->serialize();
				$vObject->destroy();
			}
			$propertyList[] = $properties;
		}

		MultiGetReport::respond($this->server, $propertyList);
	}
}
