<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Visitor details validation without native SQL or an HTTP server. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
$conf = (object) array('global' => new stdClass());
require $root.'/core/lib/functions.lib.php';
require dirname(__DIR__).'/class/CDavBookingDetails.php';

use Dolibarr\CDav\BookingDetails;

/**
 * Assert an observable validated-details outcome.
 * @param bool $condition Expected true condition
 * @param string $message Failure description
 * @return void
 */
function detailsCheck($condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Require a validation rejection rather than accepting PHP diagnostics.
 * @param array $contact Submitted visitor details
 * @param string $message Failure description
 * @return void
 */
function detailsRejects(array $contact, string $message): void
{
	try { BookingDetails::normalize($contact); } catch (InvalidArgumentException $exception) { return; }
	throw new RuntimeException($message);
}

$legacy = array('name' => '  Jean Dupont  ', 'email' => ' client@example.test ', 'phone' => ' +41 21 555 01 01 ', 'website' => '');
$contact = array_replace($legacy, array('subject' => "  Visite de l'atelier  ", 'address' => "  Rue de l'Église 12\r\n1000 Lausanne  "));
$normalized = BookingDetails::normalize($contact);
detailsCheck($normalized['legacy'] === false
	&& $normalized['contact'] === array('name' => 'Jean Dupont', 'email' => 'client@example.test', 'phone' => '+41 21 555 01 01',
		'subject' => "Visite de l'atelier", 'address' => "Rue de l'Église 12\n1000 Lausanne"),
	'D01: names, apostrophes, accents and multiline visitor addresses are preserved and canonicalized');
$lineFeeds = BookingDetails::normalize(array_replace($contact, array('address' => "  Rue de l'Église 12\n1000 Lausanne  ")));
$carriageReturns = BookingDetails::normalize(array_replace($contact, array('address' => "  Rue de l'Église 12\r1000 Lausanne  ")));
detailsCheck($lineFeeds === $normalized && $carriageReturns === $normalized,
	'D01: native LF, pasted CRLF and old CR addresses have the same canonical request details');
detailsCheck(BookingDetails::normalize($normalized['contact']) === $normalized,
	'D01: normalized details are stable across identical replays');
fwrite(STDOUT, "[OK] D01 canonical plain-text subject and multiline client address\n");

foreach (array('subject' => 120, 'address' => 128) as $field => $limit) {
	foreach (array('a', 'é', '🏠') as $character) {
		$value = str_repeat($character, $limit);
		$boundary = BookingDetails::normalize(array_replace($contact, array($field => $value)));
		detailsCheck($boundary['contact'][$field] === $value && mb_strlen($boundary['contact'][$field], 'UTF-8') === $limit,
			'D02: exact native character limits retain every UTF-8 character without truncation');
		detailsCheck(dol_trunc($boundary['contact'][$field], 128) === $value,
			'D02: native ActionComm truncation leaves every accepted subject and address untouched');
		detailsRejects(array_replace($contact, array($field => $value.$character)), 'D02: one extra character must be rejected');
	}
}
fwrite(STDOUT, "[OK] D02 exact UTF-8 character bounds and native truncation compatibility\n");

foreach (array('subject', 'address') as $field) {
	foreach (array('', '   ', true, false, null, 123, array(), "\xc3\x28", '<script>bad</script>', 'a > b') as $invalid) {
		detailsRejects(array_replace($contact, array($field => $invalid)), 'D03: malformed or empty details must be rejected');
	}
}
for ($code = 0; $code <= 31; $code++) {
	$control = chr($code);
	detailsRejects(array_replace($contact, array('subject' => 'Subject'.$control.'suffix')), 'D03: subject control characters must be rejected');
	if (in_array($code, array(10, 13), true)) continue;
	detailsRejects(array_replace($contact, array('address' => 'Street'.$control.'Town')), 'D03: address controls other than normalized line breaks must be rejected');
}
foreach (array('subject', 'address') as $field) {
	detailsRejects(array_replace($contact, array($field => 'Text'.chr(127).'suffix')), 'D03: DEL characters must be rejected');
}
fwrite(STDOUT, "[OK] D03 required fields, UTF-8, types, HTML and control-character rejection\n");

$old = BookingDetails::normalize($legacy);
detailsCheck($old === array('contact' => array('name' => 'Jean Dupont', 'email' => 'client@example.test', 'phone' => '+41 21 555 01 01'), 'legacy' => true),
	'D04: an old receipt-only payload keeps exactly the historical contact field order and values');
foreach (array('subject', 'address') as $missing) {
	$partial = $contact;
	unset($partial[$missing]);
	detailsRejects($partial, 'D04: partially supplied appointment details cannot impersonate an old payload');
}
detailsRejects(array_replace($legacy, array('subject' => '', 'address' => '')),
	'D04: explicit blank fields are rejected instead of being treated as a historical replay');
detailsRejects(array_replace($contact, array('address' => '0')),
	'D04: the native ActionComm empty-address sentinel cannot silently discard an accepted address');
$ordered = array_reverse($contact, true);
detailsCheck(BookingDetails::normalize($ordered) === $normalized, 'D04: input field order cannot change canonical replay details');
$changedSubject = BookingDetails::normalize(array_replace($contact, array('subject' => 'Another appointment')));
$changedAddress = BookingDetails::normalize(array_replace($contact, array('address' => 'Another street')));
detailsCheck($changedSubject['contact'] !== $normalized['contact'] && $changedAddress['contact'] !== $normalized['contact'],
	'D04: changes in either subject or address remain visible to the canonical receipt body');
fwrite(STDOUT, "[OK] D04 legacy receipt shape and canonical current request details\n");

foreach (array(array('name' => ''), array('name' => array()), array('name' => str_repeat('é', 61)),
	array('email' => 'bad@'), array('email' => "a@example.test\nother@example.test"), array('phone' => str_repeat('1', 41)),
	array('phone' => array()), array('website' => 'spam')) as $invalid) {
	detailsRejects(array_replace($contact, $invalid), 'D05: existing contact and spam limits remain enforced');
}
fwrite(STDOUT, "[OK] D05 existing contact validation and anti-spam behavior\n");
fwrite(STDOUT, "All CDav appointment subject and client address tests passed.\n");
