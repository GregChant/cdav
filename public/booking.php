<?php

/** Public appointment booking; the publication key grants this operation only. */
define('NOLOGIN', 1);
define('NOREQUIREMENU', 1);
define('NOBROWSERNOTIF', 1);
define('CSRFCHECK_WITH_TOKEN', 1);
$bookingWantsJson = ($_GET['format'] ?? $_POST['format'] ?? '') === 'json';

// Bound parsing even for anonymous requests, before Dolibarr's request helpers.
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192 || strlen((string) ($_SERVER['QUERY_STRING'] ?? '')) > 8192
	|| count($_POST) > 14 || count($_GET) > 8 || !empty($_FILES)) {
	http_response_code(413);
	header('Cache-Control: no-store');
	exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && strlen((string) file_get_contents('php://input', false, null, 0, 8193)) > 8192) {
	http_response_code(413);
	header('Cache-Control: no-store');
	exit;
}

$loaded = false;
$candidates = array();
foreach (array('CONTEXT_DOCUMENT_ROOT', 'DOCUMENT_ROOT') as $rootName) {
	if (!empty($_SERVER[$rootName])) $candidates[] = rtrim((string) $_SERVER[$rootName], '/').'/main.inc.php';
}
for ($depth = 2; $depth <= 5; $depth++) {
	if (!empty($_SERVER['SCRIPT_FILENAME'])) $candidates[] = dirname((string) $_SERVER['SCRIPT_FILENAME'], $depth).'/main.inc.php';
	$candidates[] = dirname(__DIR__, $depth - 1).'/main.inc.php';
}
foreach (array_unique($candidates) as $candidate) {
	if (is_file($candidate)) { require $candidate; $loaded = true; break; }
}
if (!$loaded) { http_response_code(503); exit; }

require_once DOL_DOCUMENT_ROOT.'/includes/sabre/autoload.php';
require_once dirname(__DIR__).'/class/CDavBookingService.php';
require_once dirname(__DIR__).'/class/CDavBookingIdentity.php';
require_once dirname(__DIR__).'/lib/cdav_transport.lib.php';

use Dolibarr\CDav\BookingException;
use Dolibarr\CDav\BookingService;

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; script-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$locale = GETPOST('lang', 'aZ09');
if (in_array($locale, array('fr', 'en'), true)) $langs->setDefaultLang($locale === 'fr' ? 'fr_FR' : 'en_US');
$langs->loadLangs(array('main', 'agenda', 'companies', 'cdav@cdav'));
$json = $bookingWantsJson;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$bookingProfile = null;
$bookingSlots = array();
$bookingCalendar = null;
$bookingIdentity = null;
$bookingKey = GETPOST('key', 'alphanohtml');
$bookingDate = GETPOST('date', 'alphanohtml');
$bookingMonth = GETPOST('month', 'alphanohtml');
$bookingRequest = '';
$bookingResult = null;
$bookingMessage = '';
$bookingError = '';
$bookingValues = array();
$bookingSelection = array();
$bookingSelectionSegments = array();
$bookingSelectionDuration = 0;
$bookingSelectionValid = false;
$status = 200;

$parseSelection = static function ($raw) {
	if ($raw === '') return array();
	if (!is_string($raw) || !preg_match('/^[0-9]{10}(?:,[0-9]{10}){0,23}$/D', $raw)) {
		throw new BookingException('CDavBookingInvalid', 422);
	}
	return array_map('intval', explode(',', $raw));
};

try {
	// Native v23 may clear POST and continue after rejecting the session token.
	if (($_GET['errorcode'] ?? '') === 'InvalidToken') throw new BookingException('CDavBookingForbidden', 403);
	if (!in_array($method, array('GET', 'POST'), true)) {
		header('Allow: GET, POST');
		throw new BookingException('CDavBookingInvalid', 405);
	}
	if (!cdav_is_secure_transport()
		&& !(getDolGlobalInt('CDAV_BOOKING_ALLOW_LOCAL_HTTP') && cdav_is_loopback_address($_SERVER['REMOTE_ADDR'] ?? ''))) {
		throw new BookingException('CDavBookingForbidden', 403);
	}
	if (!isModEnabled('cdav') || !isModEnabled('agenda') || !getDolGlobalInt('CDAV_BOOKING_ENABLED')) {
		throw new BookingException('CDavBookingNotFound', 404);
	}
	// The publication key must be scalar; never trust request-supplied owner/entity.
	if (!is_string($bookingKey)) throw new BookingException('CDavBookingNotFound', 404);
	$allowed = $method === 'POST'
		? array('key', 'date', 'lang', 'format', 'token', 'request', 'start', 'selection', 'name', 'email', 'phone', 'website', 'subject', 'address')
		: array('key', 'date', 'month', 'lang', 'format', 'selection', 'add', 'selection_action');
	foreach (array_keys(($_GET ?? array()) + ($_POST ?? array())) as $field) {
		if (!in_array($field, $allowed, true)) throw new BookingException('CDavBookingInvalid', 422);
	}
	if ($method === 'POST' && (array_key_exists('subject', $_GET) || array_key_exists('address', $_GET))) {
		throw new BookingException('CDavBookingInvalid', 422);
	}
	$service = new BookingService($db, $conf->entity);
	$remote = (string) getUserRemoteIP(1); // Native trusted IP; no client-supplied forwarding headers.
	$service->throttle('ip', $remote, 240, 300);
	$bookingProfile = $service->publicProfile($bookingKey);
	$service->throttle($method, $bookingProfile['id'].'|'.$remote, $method === 'POST' ? 20 : 120, 300);
	if (!$json) $bookingIdentity = \Dolibarr\CDav\BookingIdentity::build($bookingProfile['professional'] ?? array(), $mysoc ?? null, $conf);
	if ($method === 'POST') {
		if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/x-www-form-urlencoded') {
			throw new BookingException('CDavBookingInvalid', 415);
		}
		$service->throttle('profile-write', (string) $bookingProfile['id'], 200, 86400);
		// Force a native CSRF check regardless of the instance's configured level.
		$csrf = GETPOST('token', 'alphanohtml');
		if (!is_string($csrf) || $csrf === '' || !hash_equals((string) ($_SESSION['token'] ?? ''), $csrf)) {
			throw new BookingException('CDavBookingForbidden', 403);
		}
		$ticket = GETPOST('request', 'alphanohtml');
		if (!is_string($ticket) || strlen($ticket) > 160) throw new BookingException('CDavBookingInvalid', 422);
		$multiple = array_key_exists('selection', $_POST);
		if ($multiple) {
			if (isset($_POST['start']) || isset($_GET['start'])) throw new BookingException('CDavBookingInvalid', 422);
			$bookingSelection = $parseSelection($_POST['selection']);
			if (!$bookingSelection) throw new BookingException('CDavBookingInvalid', 422);
		} else {
			$rawStart = GETPOST('start', 'alphanohtml');
			if (!is_string($rawStart) || !preg_match('/^[0-9]{10}$/D', $rawStart)) throw new BookingException('CDavBookingInvalid', 422);
		}
		$contact = array();
		foreach (array('name', 'email', 'phone', 'website') as $field) {
			// The service validates originals; filtering must not silently accept hostile HTML.
			$contact[$field] = $_POST[$field] ?? '';
			if (is_string($contact[$field])) $bookingValues[$field] = mb_substr($contact[$field], 0, 254);
		}
		foreach (array('subject', 'address') as $field) {
			// Keep absent fields absent so historical receipts can still be replayed.
			if (array_key_exists($field, $_POST)) {
				$contact[$field] = $_POST[$field];
				if (is_string($contact[$field])) $bookingValues[$field] = mb_substr($contact[$field], 0, 512);
			}
		}
		// Release the browser session before entering the owner transaction.
		session_write_close();
		$bookingResult = $multiple ? $service->reserveMany($bookingKey, $ticket, $bookingSelection, $contact)
			: $service->reserve($bookingKey, $ticket, (int) $rawStart, $contact);
		$status = $bookingResult['replayed'] ? 200 : 201;
		$bookingMessage = $langs->transnoentitiesnoconv('CDavBookingConfirmed');
	} else {
		$timezone = new DateTimeZone($bookingProfile['config']['timezone']);
		if (!is_string($bookingDate) || !is_string($bookingMonth)) {
			throw new BookingException('CDavBookingInvalid', 422);
		}
		if (!$json || $bookingMonth !== '') {
			if ($bookingMonth === '' && $bookingDate !== '') $bookingMonth = substr($bookingDate, 0, 7);
			$availability = $service->calendar($bookingProfile, $bookingMonth, $bookingDate);
			$bookingMonth = $availability['calendar']['month'];
			$bookingDate = $availability['date'];
			$bookingSlots = $availability['slots'];
			$bookingCalendar = $availability['calendar'];
		} else {
			if ($bookingDate === '') $bookingDate = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $bookingDate)) throw new BookingException('CDavBookingInvalid', 422);
			$day = DateTimeImmutable::createFromFormat('!Y-m-d', $bookingDate, $timezone);
			if (!$day || $day->format('Y-m-d') !== $bookingDate) throw new BookingException('CDavBookingInvalid', 422);
			$bookingSlots = $service->slots($bookingProfile, $day->getTimestamp(), $day->modify('+1 day')->getTimestamp());
		}
		$bookingRequest = $service->requestTicket($bookingProfile);
		$bookingSelection = $parseSelection($_GET['selection'] ?? '');
		$selectionAction = $_GET['selection_action'] ?? '';
		if (!is_string($selectionAction) || !in_array($selectionAction, array('', 'add', 'set'), true)) throw new BookingException('CDavBookingInvalid', 422);
		if ($selectionAction === 'add' || $selectionAction === 'set') {
			$added = $_GET['add'] ?? array();
			if (!is_array($added) || (!$added && $selectionAction === 'add') || count($added) > 24
				|| ($added && array_keys($added) !== range(0, count($added) - 1))) {
				throw new BookingException('CDavBookingInvalid', 422);
			}
			if ($selectionAction === 'set') {
				// Replace only this local day's checkboxes, retaining other days.
				$bookingSelection = array_values(array_filter($bookingSelection, static function ($start) use ($timezone, $bookingDate) {
					return (new DateTimeImmutable('@'.$start))->setTimezone($timezone)->format('Y-m-d') !== $bookingDate;
				}));
			}
			foreach ($added as $addedStart) {
				if (!is_string($addedStart) || !preg_match('/^[0-9]{10}$/D', $addedStart)) throw new BookingException('CDavBookingInvalid', 422);
				if ($selectionAction === 'set' && (new DateTimeImmutable('@'.$addedStart))->setTimezone($timezone)->format('Y-m-d') !== $bookingDate) {
					throw new BookingException('CDavBookingInvalid', 422);
				}
				if (!in_array((int) $addedStart, $bookingSelection, true)) $bookingSelection[] = (int) $addedStart;
			}
		} elseif (isset($_GET['add'])) {
			throw new BookingException('CDavBookingInvalid', 422);
		}
		if ($bookingSelection) {
			$preview = $service->previewSelection($bookingProfile, $bookingSelection);
			$bookingSelection = $preview['starts'];
			$bookingSelectionSegments = $preview['segments'];
			$bookingSelectionDuration = $preview['duration_minutes'];
			$bookingSelectionValid = true;
		}
	}
} catch (BookingException $e) {
	$status = $e->getCode();
	$bookingError = $langs->transnoentitiesnoconv($e->getMessage());
	if ($status === 429) header('Retry-After: 300');
} catch (InvalidArgumentException $e) {
	$status = 422;
	$bookingError = $langs->transnoentitiesnoconv('CDavBookingInvalid');
} catch (Throwable $e) {
	$status = 503;
	$bookingError = $langs->transnoentitiesnoconv('CDavBookingUnavailable');
	dol_syslog('CDav public booking failed: '.get_class($e), LOG_ERR);
}

// A rejected HTML submission returns fresh choices and preserves only the
// visitor's own form values. No expired ticket is reused for another attempt.
if (!$json && $method === 'POST' && $bookingError !== '' && $bookingProfile !== null
	&& in_array($status, array(409, 410, 422), true)) {
	try {
		$zone = new DateTimeZone($bookingProfile['config']['timezone']);
		$retryDay = is_string($bookingDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $bookingDate, $zone) : false;
		if (!$retryDay || $retryDay->format('Y-m-d') !== $bookingDate) $retryDay = new DateTimeImmutable('today', $zone);
		$bookingDate = $retryDay->format('Y-m-d');
		$availability = $service->calendar($bookingProfile, $retryDay->format('Y-m'), $bookingDate);
		$bookingSlots = $availability['slots'];
		$bookingCalendar = $availability['calendar'];
		$bookingRequest = $service->requestTicket($bookingProfile);
	} catch (Throwable $e) {
		$bookingSlots = array();
	}
	if ($bookingSelection) {
		try {
			$preview = $service->previewSelection($bookingProfile, $bookingSelection);
			$bookingSelection = $preview['starts'];
			$bookingSelectionSegments = $preview['segments'];
			$bookingSelectionDuration = $preview['duration_minutes'];
			$bookingSelectionValid = true;
		} catch (Throwable $e) {
			$bookingSelectionValid = false;
		}
	}
}

http_response_code($status);
if ($json) {
	header('Content-Type: application/json; charset=utf-8');
	$response = $bookingError !== '' ? array('error' => $bookingError) : ($bookingResult !== null ? $bookingResult : array(
		'timezone' => $bookingProfile['config']['timezone'], 'duration' => $bookingProfile['config']['duration'],
		'date' => $bookingDate, 'slots' => $bookingSlots, 'request' => $bookingRequest, 'token' => newToken(),
	));
	if ($bookingError === '' && $bookingResult === null && $bookingCalendar !== null) $response['calendar'] = $bookingCalendar;
	if ($bookingError === '' && $bookingResult === null && $bookingSelectionValid) {
		$response['selection'] = array('starts' => $bookingSelection, 'segments' => $bookingSelectionSegments, 'duration_minutes' => $bookingSelectionDuration);
	}
	echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
	header('Content-Type: text/html; charset=utf-8');
	require dirname(__DIR__).'/tpl/booking.tpl.php';
}
$db->close();
