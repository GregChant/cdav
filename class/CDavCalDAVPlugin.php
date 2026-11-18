<?php

namespace Dolibarr\CDav;

require_once __DIR__.'/CDavMultiGetReport.php';

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
			$this->backend->validateProtectedCalendarWrite((int) $match[1], $objectUri, $calendar->serialize(), (bool) $isNew);
		}
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
