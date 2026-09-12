<?php

namespace Dolibarr\CDav;

use Sabre\DAV;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** HTTP 413, which is not provided by the Sabre/DAV version bundled in Dolibarr. */
class RequestEntityTooLarge extends DAV\Exception
{
	public function getHTTPCode()
	{
		return 413;
	}
}

/**
 * Bound request memory/disk consumption before DAV XML or file handlers run.
 *
 * CardDAV and CalDAV backends retain their format-specific validation. This
	 * guard also covers REPORT/PROPFIND XML and calendar/card payloads.
 */
class RequestGuardPlugin extends DAV\ServerPlugin
{
	/** @var int */
	private $maxRequestBytes;
	/** @var DAV\Server */
	private $server;
	/** @var object|null */
	private $cardBackend;
	/** @var object|null */
	private $calendarBackend;
	/** @var object|null */
	private $user;

	public function __construct($maxRequestBytes, $cardBackend = null, $calendarBackend = null, $user = null)
	{
		$this->maxRequestBytes = max(1024, (int) $maxRequestBytes);
		$this->cardBackend = $cardBackend;
		$this->calendarBackend = $calendarBackend;
		$this->user = $user;
	}

	public function initialize(DAV\Server $server)
	{
		$this->server = $server;
		// Authentication runs at priority 10. Validate the authenticated request
		// before ACL resolution (priority 20) or any body parser is invoked.
		$server->on('beforeMethod:*', array($this, 'beforeMethod'), 15);
		$server->on('afterMethod:*', array($this, 'afterMethod'), 90);
	}

	public function getPluginName()
	{
		return 'cdav-request-guard';
	}

	public function beforeMethod(RequestInterface $request, ResponseInterface $response)
	{
		$this->validateRequestTarget($request);
		$this->captureExpectedVersion($request);
		$root = explode('/', trim($request->getPath(), '/'), 2)[0] ?? '';
		if (in_array($request->getMethod(), array('COPY', 'MOVE'), true)
			&& in_array($root, array('addressbooks', 'calendars'), true)) {
			// Copying a virtual business resource would otherwise duplicate the
			// Dolibarr object; moving it would additionally deactivate/delete its
			// source. CalDAV/CardDAV clients use PUT + conditional DELETE instead.
			throw new DAV\Exception\MethodNotAllowed('COPY and MOVE are disabled for Dolibarr business resources');
		}
		$depth = $request->getHeader('Depth');
		if ($depth !== null && !in_array(strtolower(trim($depth)), array('0', '1', 'infinity'), true)) {
			throw new DAV\Exception\BadRequest('Invalid DAV Depth header');
		}

		$contentLength = $request->getHeader('Content-Length');
		$transferEncoding = $request->getHeader('Transfer-Encoding');
		if ($contentLength !== null && $transferEncoding !== null) {
			throw new DAV\Exception\BadRequest('Content-Length and Transfer-Encoding cannot be combined');
		}
		if ($contentLength !== null) {
			$contentLength = trim($contentLength);
			if ($contentLength === '' || !ctype_digit($contentLength)) {
				throw new DAV\Exception\BadRequest('Invalid Content-Length header');
			}
			if ((int) $contentLength > $this->maxRequestBytes) {
				throw new RequestEntityTooLarge('The DAV request body is too large');
			}
		}

		// Buffer bodies even when their transport omitted or lied about a length.
		// Reading one byte above the limit keeps every downstream DAV parser bound.
		if (!in_array($request->getMethod(), array('GET', 'HEAD', 'OPTIONS'), true)) {
			$body = stream_get_contents($request->getBodyAsStream(), $this->maxRequestBytes + 1);
			if (strlen($body) > $this->maxRequestBytes) {
				throw new RequestEntityTooLarge('The DAV request body is too large');
			}
			$request->setBody($body);
		}

		if (in_array($request->getMethod(), array('PROPFIND', 'PROPPATCH', 'REPORT', 'LOCK', 'MKCOL', 'MKCALENDAR', 'ACL'), true)) {
			$xmlBody = $request->getBodyAsString();
			$request->setBody($xmlBody);
			// DAV request XML never needs a DTD. Reject both external entities and
			// internal expansion bombs instead of relying on libxml defaults.
			if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xmlBody)) {
				throw new DAV\Exception\BadRequest('DTD and entity declarations are not allowed in DAV XML');
			}
		}
	}

	/**
	 * Carry Sabre's exact conditional representation into the native DB lock.
	 * This closes the interval between HTTP precondition checking and the
	 * Contact/Societe/ActionComm mutation performed by a concurrent request.
	 */
	private function captureExpectedVersion(RequestInterface $request)
	{
		if (!in_array($request->getMethod(), array('PUT', 'DELETE'), true)) {
			return;
		}
		$ifMatch = trim((string) $request->getHeader('If-Match'));
		$ifUnmodifiedSince = trim((string) $request->getHeader('If-Unmodified-Since'));
		if (($ifMatch === '' || $ifMatch === '*') && $ifUnmodifiedSince === '') {
			return;
		}
		try {
			$node = $this->server->tree->getNodeForPath($request->getPath());
		} catch (DAV\Exception\NotFound $e) {
			return; // Sabre's regular precondition check will return HTTP 412.
		}
		if (!$node instanceof DAV\IFile) {
			return;
		}
		$etag = (string) $node->getETag();
		$segments = explode('/', trim((string) $request->getPath(), '/'));
		if ($node instanceof \Sabre\CardDAV\ICard && count($segments) === 4 && $this->cardBackend && $this->user) {
			$bookIds = array(
				'default' => (int) $this->user->id,
				'thirdparties' => (int) $this->user->id + CDAV_ADDRESSBOOK_ID_SHIFT,
				'members' => (int) $this->user->id + 2 * CDAV_ADDRESSBOOK_ID_SHIFT,
			);
			if ($segments[0] === 'addressbooks' && hash_equals((string) $this->user->login, $segments[1])
				&& isset($bookIds[$segments[2]])) {
				$this->cardBackend->expectCardVersion($bookIds[$segments[2]], $segments[3], $etag);
			}
			return;
		}
		if ($node instanceof \Sabre\CalDAV\ICalendarObject && count($segments) === 4 && $this->calendarBackend
			&& $segments[0] === 'calendars'
			&& preg_match('/^(\d+)-cal-/', $segments[2], $match)) {
			$this->calendarBackend->expectCalendarVersion((int) $match[1], $segments[3], $etag);
		}
	}

	public function afterMethod(RequestInterface $request, ResponseInterface $response)
	{
		$response->setHeader('Cache-Control', 'no-store');
		$response->setHeader('X-Content-Type-Options', 'nosniff');
		$response->setHeader('Referrer-Policy', 'no-referrer');
		if ($request->getHeader('X-Sabre-Original-Method') === 'HEAD') {
			// Sabre/DAV 4.6 implements HEAD as an empty internal GET. Its CardDAV
			// and CalDAV content-negotiation hooks otherwise try to parse that empty
			// body and turn a valid HEAD into HTTP 500.
			return false;
		}
	}

	/** Reject decoded dot segments, NULs and backslashes before FS traversal. */
	private function validateRequestTarget(RequestInterface $request)
	{
		$path = parse_url($request->getUrl(), PHP_URL_PATH);
		$decodedPath = rawurldecode(is_string($path) ? $path : '');
		if (strpos($decodedPath, "\0") !== false || strpos($decodedPath, '\\') !== false) {
			throw new DAV\Exception\BadRequest('Invalid DAV request target');
		}
		foreach (explode('/', $decodedPath) as $segment) {
			if ($segment === '.' || $segment === '..') {
				throw new DAV\Exception\BadRequest('DAV dot segments are not allowed');
			}
		}
	}
}
