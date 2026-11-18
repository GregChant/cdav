<?php

namespace Dolibarr\CDav;

require_once __DIR__.'/CDavMultiGetReport.php';

/**
 * CardDAV compatibility fixes layered on Dolibarr's bundled Sabre plugin.
 */
class CardDAVPlugin extends \Sabre\CardDAV\Plugin
{
	/**
	 * Keep the advertised CardDAV limit equal to the HTTP request guard.
	 * @param int $maxResourceSize Maximum contact bytes
	 */
	public function __construct($maxResourceSize)
	{
		$this->maxResourceSize = max(1, (int) $maxResourceSize);
	}

	/**
	 * Sabre/DAV 4.6 silently omits missing hrefs from multiget. RFC 6352
	 * requires one DAV:response per requested href, including error statuses.
	 * @param object $report Parsed multiget request
	 * @return void
	 */
	public function addressbookMultiGetReport($report)
	{
		$contentType = $report->contentType;
		$version = $report->version;
		if ($version) {
			$contentType .= '; version='.$version;
		}
		$vcardType = $this->negotiateVCard($contentType);
		$propertyList = array();
		foreach (MultiGetReport::properties($this->server, $report) as $properties) {
			if (isset($properties[200]['{'.self::NS_CARDDAV.'}address-data'])) {
				$properties[200]['{'.self::NS_CARDDAV.'}address-data'] = $this->convertVCard(
					$properties[200]['{'.self::NS_CARDDAV.'}address-data'],
					$vcardType
				);
			}
			$propertyList[] = $properties;
		}

		MultiGetReport::respond($this->server, $propertyList);
	}
}
