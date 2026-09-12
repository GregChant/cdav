<?php

/** Lightweight parser regression tests; no Dolibarr database is required. */

date_default_timezone_set('UTC');
// Dolibarr 23's bundled Sabre release emits PHP 8.5 vendor deprecations.
error_reporting(E_ALL & ~E_DEPRECATED);

$dolibarrRoot = getenv('DOLIBARR_ROOT');
if (!$dolibarrRoot) {
	$dolibarrRoot = dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
}
$sabreAutoload = $dolibarrRoot.'/includes/sabre/autoload.php';
if (!is_file($sabreAutoload)) {
	fwrite(STDERR, "Set DOLIBARR_ROOT to a Dolibarr htdocs directory.\n");
	exit(2);
}

define('DOL_DOCUMENT_ROOT', $dolibarrRoot);
define('CDAV_URI_KEY', 'parser-test-key');
require $sabreAutoload;

function debug_log($message)
{
}

// Parser tests intentionally do not bootstrap main.inc.php. Mirror the native
// Dolibarr configuration helpers with inert defaults for pure parsing paths.
if (!function_exists('getDolGlobalInt')) {
	function getDolGlobalInt($name, $default = 0)
	{
		return (int) $default;
	}
}

if (!function_exists('getDolGlobalString')) {
	function getDolGlobalString($name, $default = '')
	{
		return (string) $default;
	}
}

require dirname(__DIR__).'/class/CardDAVDolibarr.php';
require dirname(__DIR__).'/class/CalDAVDolibarr.php';

function invokeParser($object, $method, array $arguments)
{
	$reflection = new ReflectionMethod($object, $method);
	if (PHP_VERSION_ID < 80500) {
		$reflection->setAccessible(true);
	}
	return $reflection->invokeArgs($object, $arguments);
}

function sameValue($expected, $actual, $message)
{
	if ($expected !== $actual) {
		throw new RuntimeException($message.'; expected '.var_export($expected, true).', got '.var_export($actual, true));
	}
}

function truthy($actual, $message)
{
	if (!$actual) {
		throw new RuntimeException($message);
	}
}

$cardBackend = (new ReflectionClass('Sabre\\CardDAV\\Backend\\Dolibarr'))->newInstanceWithoutConstructor();
$calendarBackend = (new ReflectionClass('Sabre\\CalDAV\\Backend\\Dolibarr'))->newInstanceWithoutConstructor();

$contactCard = "BEGIN:VCARD\r\n"
	."VERSION:4.0\r\n"
	."UID:phone-contact-1\r\n"
	."FN:Jane Doe\r\n"
	."N:Doe;Jane;;;\r\n"
	."ORG:Example SA\r\n"
	."TEL;TYPE=work,voice:+41225550100\r\n"
	."TEL;TYPE=cell:+41795550100\r\n"
	."EMAIL:jane@example.test\r\n"
	."URL:https://example.test/jane\r\n"
	."X-SKYPE:jane.doe\r\n"
	."BDAY:19801231\r\n"
	."NOTE:First line\\nSecond line\r\n"
	."END:VCARD\r\n";
$contact = invokeParser($cardBackend, '_parseDataContact', array($contactCard, 'U'));
sameValue('Doe', $contact['lastname'], 'Contact last name must be parsed');
sameValue('Jane', $contact['firstname'], 'Contact first name must be parsed');
sameValue('+41225550100', $contact['phone'], 'Work phone must be parsed');
sameValue('+41795550100', $contact['phone_mobile'], 'Mobile phone must be parsed');
sameValue('jane@example.test', $contact['email'], 'First non-preferred email must be retained');
sameValue('https://example.test/jane', $contact['url'], 'Contact URL must be parsed');
sameValue('1980-12-31', $contact['birthday'], 'Compact birthdays must be parsed');
sameValue("First line\nSecond line", $contact['note_public'], 'Escaped note lines must be decoded once');
sameValue('Example SA', $contact['_organization'], 'Organization must be available for native linking');
truthy(strpos($contact['socialnetworks'], 'jane.doe') !== false, 'Social network must be parsed');

$minimalCard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:phone-contact-1\r\nFN:Jane Doe\r\nEND:VCARD\r\n";
$minimal = invokeParser($cardBackend, '_parseDataContact', array($minimalCard, 'U'));
foreach (array('phone', 'phone_perso', 'phone_mobile', 'fax', 'email', 'url', 'address', 'socialnetworks', 'note_public', 'photo') as $field) {
	sameValue('', $minimal[$field], 'A replacement vCard must clear '.$field);
}
sameValue(null, $minimal['birthday'], 'A replacement vCard must clear birthdays');
sameValue('', $minimal['_organization'], 'A replacement vCard must clear third-party links');

$member = invokeParser($cardBackend, '_parseDataMember', array(
	"BEGIN:VCARD\r\nVERSION:3.0\r\nUID:member-1\r\nFN:Member One\r\nEMAIL:member@example.test\r\nURL:https://example.test/member\r\nX-LINKEDIN:member-one\r\nEND:VCARD\r\n",
	'U',
));
sameValue('member@example.test', $member['email'], 'Member email must be parsed');
sameValue('https://example.test/member', $member['url'], 'Member URL must be parsed');
truthy(strpos($member['socialnetworks'], 'member-one') !== false, 'Member social network must be parsed');

$thirdParty = invokeParser($cardBackend, '_parseDataThirdparty', array(
	"BEGIN:VCARD\r\nVERSION:4.0\r\nUID:company-1\r\nFN:Example Company\r\nEMAIL:office@example.test\r\nEND:VCARD\r\n",
	'U',
));
sameValue('Example Company', $thirdParty['nom'], 'An FN-only company card must be accepted');
sameValue('office@example.test', $thirdParty['email'], 'Company email must be parsed');

$rejected = false;
try {
	invokeParser($cardBackend, '_parseDataContact', array(
		"BEGIN:VCARD\r\nVERSION:3.0\r\nUID:".str_repeat('x', 1025)."\r\nFN:Oversized UID\r\nEND:VCARD\r\n",
		'C',
	));
} catch (Sabre\DAV\Exception\BadRequest $e) {
	$rejected = true;
}
truthy($rejected, 'Oversized vCard UIDs must be rejected before database storage');

$tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$photoContact = invokeParser($cardBackend, '_parseDataContact', array(
	"BEGIN:VCARD\r\nVERSION:4.0\r\nUID:photo-contact\r\nFN:Photo Contact\r\n"
		."PHOTO:data:image/png;base64,".base64_encode($tinyPng)."\r\nEND:VCARD\r\n",
	'C',
));
sameValue($tinyPng, $photoContact['_photo_bin'], 'An RFC 6350 data-URI photo must be decoded after vCard 4 to 3 normalization');
if (function_exists('imagecreatefromstring')) {
	$decodedPhoto = invokeParser($cardBackend, '_decodeVCardPhoto', array($photoContact['_photo_bin']));
	truthy($decodedPhoto !== false, 'A bounded valid vCard photo must be accepted');
	imagedestroy($decodedPhoto);
}

// A tiny compressed header must never be allowed to reserve an enormous GD bitmap.
$ihdr = pack('NNCCCCC', 100000, 100000, 8, 2, 0, 0, 0);
$ihdrChunk = 'IHDR'.$ihdr;
$iendChunk = 'IEND';
$oversizedPixelPhoto = "\x89PNG\r\n\x1a\n"
	.pack('N', strlen($ihdr)).$ihdrChunk.pack('N', crc32($ihdrChunk))
	.pack('N', 0).$iendChunk.pack('N', crc32($iendChunk));
$rejected = false;
try {
	invokeParser($cardBackend, '_decodeVCardPhoto', array($oversizedPixelPhoto));
} catch (Sabre\DAV\Exception\BadRequest $e) {
	$rejected = true;
}
truthy($rejected, 'A vCard photo decompression bomb must be rejected before GD decoding');

$eventData = "BEGIN:VCALENDAR\r\n"
	."VERSION:2.0\r\n"
	."PRODID:-//CDav tests//EN\r\n"
	."BEGIN:VEVENT\r\n"
	."UID:outlook-event-1\r\n"
	."DTSTART:20261010T100000Z\r\n"
	."DTEND:20261010T113000Z\r\n"
	."SUMMARY:External meeting\r\n"
	."PRIORITY:99\r\n"
	."ATTACH;FILENAME=agenda.pdf:https://files.example.test/agenda.pdf\r\n"
	."ATTACH:file:///etc/passwd\r\n"
	."ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:jane@example.test\r\n"
	."END:VEVENT\r\n"
	."END:VCALENDAR\r\n";
$event = invokeParser($calendarBackend, '_parseData', array($eventData, null));
sameValue('VEVENT', $event['componentType'], 'VEVENT must be parsed');
sameValue(5400, $event['end'] - $event['start'], 'Timed event duration must be retained');
sameValue(9, $event['priority'], 'Priorities must be clamped to RFC limits');
sameValue('jane@example.test', $event['participants'][0]['email'], 'Attendee email must be normalized');
sameValue('ACCEPTED', $event['participants'][0]['partstat'], 'Attendee PARTSTAT must be retained');
sameValue(1, count($event['attachment_links']), 'Only safe HTTPS appointment attachments may become Dolibarr links');
sameValue('agenda.pdf', $event['attachment_links'][0]['label'], 'The standard attachment filename must be retained');

$compatibleEventData = str_replace(
	"ATTACH;FILENAME=agenda.pdf:https://files.example.test/agenda.pdf\r\n",
	"RRULE:FREQ=DAILY;UNTIL=20261012T100000Z\r\n"
		."BEGIN:VALARM\r\nTRIGGER:-PT15M\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nEND:VALARM\r\n"
		."ATTACH;MANAGED-ID=".str_repeat('a', 64).";FILENAME=managed.pdf:https://dav.example.test/attachments/".str_repeat('a', 64)."\r\n"
		."ATTACH;FILENAME=agenda.pdf:https://files.example.test/agenda.pdf\r\n",
	$eventData
);
$compatibleEvent = invokeParser($calendarBackend, '_parseData', array($compatibleEventData, null));
sameValue('FREQ=DAILY', $compatibleEvent['native_recurrence']['rule'], 'A faithful daily recurrence must be projected natively');
sameValue(15, $compatibleEvent['native_reminders'][0]['value'], 'A safe DISPLAY alarm must be projected as a native reminder');
sameValue('i', $compatibleEvent['native_reminders'][0]['unit'], 'A minute alarm must use Dolibarr minute units');
sameValue(1, count($compatibleEvent['attachment_links']), 'Managed attachments must never be mirrored as native URL links');

$managedPluginSource = file_get_contents(__DIR__.'/../class/CDavManagedAttachmentPlugin.php');
$calendarBackendSource = file_get_contents(__DIR__.'/../class/CalDAVDolibarr.php');
truthy(str_contains($managedPluginSource, 'assertIfMatch') && str_contains($managedPluginSource, 'expectCalendarVersion'),
	'RFC 8607 POST must enforce client and server-side version checks');
truthy(str_contains($calendarBackendSource, '_assertCalendarVersion') && str_contains($calendarBackendSource, 'FOR UPDATE'),
	'Managed attachment version checks must run under a database lock');
$requestGuardSource = file_get_contents(__DIR__.'/../class/CDavRequestGuardPlugin.php');
truthy(str_contains($requestGuardSource, 'expectCardVersion') && str_contains($requestGuardSource, 'expectCalendarVersion'),
	'Conditional DAV writes must carry their checked representation into the native transaction');

$advancedEvent = str_replace(
	"RRULE:FREQ=DAILY;UNTIL=20261012T100000Z",
	"RRULE:FREQ=DAILY;COUNT=3",
	$compatibleEventData
);
$advanced = invokeParser($calendarBackend, '_parseData', array($advancedEvent, null));
sameValue(null, $advanced['native_recurrence'], 'An advanced recurrence must remain sidecar-only instead of being approximated');

$allDay = invokeParser($calendarBackend, '_parseData', array(
	"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav tests//EN\r\nBEGIN:VEVENT\r\nUID:all-day-1\r\nDTSTART;VALUE=DATE:20261011\r\nSUMMARY:All day\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
	null,
));
sameValue(1, $allDay['fullday'], 'Date-only events must stay all-day');
sameValue(86400, $allDay['end'] - $allDay['start'], 'A date-only event without DTEND lasts one day');

$todo = invokeParser($calendarBackend, '_parseData', array(
	"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav tests//EN\r\nBEGIN:VTODO\r\nUID:todo-1\r\nDUE;VALUE=DATE:20261012\r\nSUMMARY:Task\r\nPERCENT-COMPLETE:150\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
	null,
));
sameValue(100, $todo['percent'], 'Task completion must be clamped');

$rejected = false;
try {
	invokeParser($calendarBackend, '_parseData', array(
		"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CDav tests//EN\r\nBEGIN:VJOURNAL\r\nUID:journal-1\r\nEND:VJOURNAL\r\nEND:VCALENDAR\r\n",
		null,
	));
} catch (Sabre\DAV\Exception\BadRequest $e) {
	$rejected = true;
}
truthy($rejected, 'Unsupported VJOURNAL objects must be rejected as client errors');

// Security invariant: a CardDAV DELETE only deactivates business records.
// Definitive deletion must remain an explicit action performed in Dolibarr.
$cardBackendSource = file_get_contents(dirname(__DIR__).'/class/CardDAVDolibarr.php');
$deleteStart = strpos($cardBackendSource, 'function deleteCard(');
$deleteEnd = strpos($cardBackendSource, 'function getChangesForAddressBook(', $deleteStart);
truthy($deleteStart !== false && $deleteEnd !== false, 'The CardDAV deletion handler must remain testable');
$deleteSource = substr($cardBackendSource, $deleteStart, $deleteEnd - $deleteStart);
truthy(strpos($deleteSource, '$contact->setstatus(0)') !== false, 'A remote contact deletion must deactivate the contact');
truthy(strpos($deleteSource, '$thirdParty->status = 0') !== false, 'A remote company deletion must deactivate the company');
truthy(strpos($deleteSource, '$member->resiliate($this->user)') !== false, 'A remote member deletion must use Dolibarr member resiliation');
truthy(!preg_match('/->delete\s*\(/i', $deleteSource), 'CardDAV DELETE must never call a Dolibarr physical delete method');
truthy(!preg_match('/DELETE\s+FROM/i', $deleteSource), 'CardDAV DELETE must never issue a physical SQL deletion');

$cardMapSchema = file_get_contents(dirname(__DIR__).'/sql/llx_cdav_cardmap.sql');
truthy(strpos($cardMapSchema, 'entity integer NOT NULL') !== false,
	'CardDAV mappings must record their Dolibarr entity');
truthy(strpos($cardMapSchema, 'UNIQUE KEY uk_cdav_cardmap_uri (entity, object_type, uuidhash)') !== false,
	'CardDAV URI conflicts must be enforced atomically');
truthy(strpos($cardMapSchema, 'UNIQUE KEY uk_cdav_cardmap_uid (entity, object_type, sourceuid_hash)') !== false,
	'CardDAV UID conflicts must be enforced atomically');

fwrite(STDOUT, "All CDav regression tests passed.\n");
