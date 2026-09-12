<?php

namespace Dolibarr\CDav;

/**
 * CardDAV compatibility fixes layered on Dolibarr's bundled Sabre plugin.
 */
class CardDAVPlugin extends \Sabre\CardDAV\Plugin
{
	/** Keep the advertised CardDAV limit equal to the HTTP request guard. */
	public function __construct($maxResourceSize)
	{
		$this->maxResourceSize = max(1, (int) $maxResourceSize);
	}

	/**
	 * Sabre/DAV 4.6 silently omits missing hrefs from multiget. RFC 6352
	 * requires one DAV:response per requested href, including error statuses.
	 */
	public function addressbookMultiGetReport($report)
	{
		$contentType = $report->contentType;
		$version = $report->version;
		if ($version) {
			$contentType .= '; version='.$version;
		}
		$vcardType = $this->negotiateVCard($contentType);
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
			if (isset($properties[200]['{'.self::NS_CARDDAV.'}address-data'])) {
				$properties[200]['{'.self::NS_CARDDAV.'}address-data'] = $this->convertVCard(
					$properties[200]['{'.self::NS_CARDDAV.'}address-data'],
					$vcardType
				);
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
