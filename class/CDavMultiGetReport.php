<?php

namespace Dolibarr\CDav;

/** Shared multiget response handling around Sabre's native property and XML APIs. */
class MultiGetReport
{
	/**
	 * Retain requested href order and expose missing or ACL-filtered resources as 404.
	 *
	 * @param \Sabre\DAV\Server $server Native DAV server
	 * @param object $report Parsed CalDAV or CardDAV multiget request
	 * @return \Generator<string,array> Native property responses, one per requested href
	 */
	public static function properties(\Sabre\DAV\Server $server, $report)
	{
		$paths = array_map(array($server, 'calculateUri'), $report->hrefs);
		$propertiesByPath = $server->getPropertiesForMultiplePaths($paths, $report->properties);
		foreach ($paths as $path) {
			yield $path => $propertiesByPath[$path] ?? array(
				'href' => $path,
				404 => array_fill_keys($report->properties ?: array('{DAV:}getetag'), null),
			);
		}
	}

	/**
	 * Serialize a DAV multistatus using the native Prefer/Brief and XML behavior.
	 *
	 * @param \Sabre\DAV\Server $server Native DAV server
	 * @param array $properties Converted property responses
	 * @return void
	 */
	public static function respond(\Sabre\DAV\Server $server, array $properties)
	{
		$prefer = $server->getHTTPPrefer();
		$server->httpResponse->setStatus(207);
		$server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
		$server->httpResponse->setHeader('Vary', 'Brief,Prefer');
		$server->httpResponse->setBody($server->generateMultiStatus($properties, 'minimal' === $prefer['return']));
	}
}
