<?php

namespace Dolibarr\CDav;

/** CalDAV compatibility fixes layered on Dolibarr's bundled Sabre plugin. */
class CalDAVPlugin extends \Sabre\CalDAV\Plugin
{
	/** @var bool */
	private $delegationEnabled;

	/** Keep the advertised CalDAV limit equal to the HTTP request guard. */
	public function __construct($maxResourceSize, $delegationEnabled = false)
	{
		$this->maxResourceSize = max(1, (int) $maxResourceSize);
		$this->delegationEnabled = (bool) $delegationEnabled;
	}

	/** Advertise calendar-proxy only when backed by Dolibarr ACLs. */
	public function getFeatures()
	{
		return $this->delegationEnabled
			? array('calendar-access', 'calendar-proxy')
			: array('calendar-access');
	}

	/** Return an explicit status for every calendar-multiget href. */
	public function calendarMultiGetReport($report)
	{
		$needsJson = 'application/calendar+json' === $report->contentType;
		$timeZones = array();
		$paths = array_map(array($this->server, 'calculateUri'), $report->hrefs);
		$propertiesByPath = $this->server->getPropertiesForMultiplePaths($paths, $report->properties);
		$propertyList = array();

		foreach ($paths as $path) {
			if (!isset($propertiesByPath[$path])) {
				$missingProperties = $report->properties ?: array('{DAV:}getetag');
				$propertyList[] = array(
					'href' => $path,
					404 => array_fill_keys($missingProperties, null),
				);
				continue;
			}
			$properties = $propertiesByPath[$path];
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

		$prefer = $this->server->getHTTPPrefer();
		$this->server->httpResponse->setStatus(207);
		$this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
		$this->server->httpResponse->setHeader('Vary', 'Brief,Prefer');
		$this->server->httpResponse->setBody($this->server->generateMultiStatus($propertyList, 'minimal' === $prefer['return']));
	}
}
