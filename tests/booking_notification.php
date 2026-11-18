<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Deterministic confirmation content and failure boundaries; no email is sent. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
define('DOL_URL_ROOT', '/dolibarr');
define('MAIN_DB_PREFIX', 'llx_');
$notificationLangRoot = sys_get_temp_dir().'/cdav-booking-mail-test-'.bin2hex(random_bytes(8));
if (!mkdir($notificationLangRoot, 0700)) throw new RuntimeException('Unable to create the private native module layout');
register_shutdown_function(static function () use ($notificationLangRoot) {
	if (is_link($notificationLangRoot.'/cdav')) unlink($notificationLangRoot.'/cdav');
	if (is_dir($notificationLangRoot)) rmdir($notificationLangRoot);
});
// Native module language lookup uses cdav@cdav, regardless of checkout name.
if (!symlink(dirname(__DIR__), $notificationLangRoot.'/cdav')) throw new RuntimeException('Unable to link the native module language directory');
$conf = (object) array('global' => new stdClass(),
	'file' => (object) array('dol_document_root' => array($root, $notificationLangRoot), 'character_set_client' => 'UTF-8'), 'cache' => array());
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
$mysoc = (object) array('name' => 'Atelier & fils', 'email' => 'company@example.test');
$db = null;
require $root.'/core/lib/functions.lib.php';
require $root.'/core/class/translate.class.php';
require dirname(__DIR__).'/class/CDavBookingNotification.php';

use Dolibarr\CDav\BookingNotification;

/**
 * Fail a delivery regression without opening a network connection.
 * @param bool $condition Expected condition
 * @param string $message Useful failure description
 * @return void
 */
function notificationCheck(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

$langs = new Translate('', $conf);
$langs->setDefaultLang('fr_FR');
$owner = (object) array('id' => 17, 'email' => 'private-collaborator@example.test', 'lang' => 'en_US');
$profile = array('id' => 7, 'owner_id' => 17, 'label' => 'Entretien chauffage', 'config' => array('timezone' => 'Europe/Zurich'),
	'professional' => array('name' => 'Camille Collaborateur', 'email' => ''), 'token_hash' => str_repeat('b', 64));
$contact = array('name' => 'Client Émile', 'email' => 'client@example.test', 'phone' => '+41 21 555 01 01',
	'subject' => 'Entretien de chaudière — visite', 'address' => "Rue de l’Église 12\n1000 Lausanne");
$periods = array();
foreach (array('2026-03-28 09:00', '2026-03-29 09:00') as $local) {
	$start = (new DateTimeImmutable($local, new DateTimeZone('Europe/Zurich')))->getTimestamp();
	$periods[] = array('start' => $start, 'end' => $start + 1800);
}
$selection = array('segments' => $periods, 'duration_minutes' => 60);
$reference = str_repeat('a', 32);
$notification = new BookingNotification(null, 2);
$conf->global->MAIN_MAIL_EMAIL_FROM = 'Atelier chauffage <agenda@example.test>';
$messages = $notification->messages($profile, $contact, $selection, $reference, $owner);
notificationCheck(array_keys($messages) === array('customer', 'owner')
	&& $messages['customer']['to'] === $contact['email'] && $messages['owner']['to'] === $owner->email,
	'N01: visitor and collaborator receive separate native envelopes');
foreach ($messages as $message) {
	notificationCheck($message['from'] === $conf->global->MAIN_MAIL_EMAIL_FROM && !isset($message['cc'], $message['bcc']),
		'N01: native sender is preserved and recipients are not copied to one another');
	notificationCheck(strpos($message['body'], $owner->email) === false && strpos($message['body'], $profile['token_hash']) === false,
		'N01: hidden collaborator mail and public-link capability do not appear in confirmation bodies');
}
fwrite(STDOUT, "[OK] N01 separate recipients, native sender and private collaborator address\n");

notificationCheck(strpos($messages['customer']['subject'], 'Confirmation de rendez-vous') !== false
	&& strpos($messages['customer']['body'], 'Votre rendez-vous est confirmé.') !== false
	&& strpos($messages['owner']['subject'], 'Appointment confirmation') !== false
	&& strpos($messages['owner']['body'], 'A booking has been confirmed in your calendar.') !== false,
	'N02: visitor and collaborator use their own native translation language');
foreach ($messages as $message) {
	foreach (array($mysoc->name, $profile['label'], $profile['professional']['name'], $reference, $contact['name'],
		$contact['subject'], $contact['address'], $contact['email'], $contact['phone'], 'Europe/Zurich',
		'2026-03-28 09:00 +01:00', '2026-03-29 09:00 +02:00') as $detail) {
		notificationCheck(strpos($message['body'], $detail) !== false, 'N02: complete booking details, actual periods and DST offsets survive in plain text');
	}
}
notificationCheck($langs->defaultlang === 'fr_FR', 'N02: preparing another recipient language does not mutate the public page language');
fwrite(STDOUT, "[OK] N02 localized native text, multiline address and every real booked period\n");

$owner->lang = '';
$inherited = $notification->messages($profile, $contact, $selection, $reference, $owner);
notificationCheck($inherited['owner']['language'] === 'fr_FR', 'N03: an unset collaborator language inherits the active page language');
$owner->lang = '../../private';
$invalidLocale = $notification->messages($profile, $contact, $selection, $reference, $owner);
notificationCheck($invalidLocale['owner']['language'] === 'fr_FR', 'N03: malformed language values cannot select arbitrary translation directories');
$conf->global->MAIN_MAIL_EMAIL_FROM = '';
$fallback = $notification->messages($profile, $contact, $selection, $reference, $owner);
notificationCheck($fallback['customer']['from'] === $mysoc->email, 'N03: the configured company email is the native sender fallback');
$conf->global->MAIN_MAIL_EMAIL_FROM = "sender@example.test\r\nBcc: outsider@example.test";
$unsafe = $notification->messages($profile, $contact, $selection, $reference, $owner);
notificationCheck($unsafe['customer']['from'] === '', 'N03: a malformed sender cannot inject mail headers');
fwrite(STDOUT, "[OK] N03 native sender fallback and safe language/header boundaries\n");

foreach (array(
	array($contact, array('segments' => array()), $reference),
	array($contact, array('segments' => array(array('start' => '1', 'end' => 2))), $reference),
	array($contact, $selection, "bad\r\nreference"),
	array(array_replace($contact, array('subject' => "subject\r\nBcc: outsider@example.test")), $selection, $reference)
) as $invalid) {
	$rejected = false;
	try {
		$notification->messages($profile, $invalid[0], $invalid[1], $invalid[2], $owner);
	} catch (InvalidArgumentException $e) {
		$rejected = true;
	}
	notificationCheck($rejected, 'N04: malformed periods, references and header text cannot prepare a mail');
}
fwrite(STDOUT, "[OK] N04 malformed confirmation input rejected before delivery\n");

/** A closed transaction boundary that must prevent every database/mail operation. */
class BookingNotificationGuardDb
{
	public $transaction_opened = 1;
	public $queries = 0;

	/**
	 * Fail any attempted database operation during an open Agenda transaction.
	 * @param string $sql Unused query
	 * @return false No operation is permitted
	 */
	public function query($sql)
	{
		$this->queries++;
		return false;
	}

	/**
	 * Preserve native driver shape for a database failure boundary.
	 * @param string $value Value
	 * @return string Quoted technical value
	 */
	public function escape($value)
	{
		return addslashes($value);
	}
}
$guardDb = new BookingNotificationGuardDb();
$conf->global->MAIN_MAIL_EMAIL_FROM = 'agenda@example.test';
$guard = new BookingNotification($guardDb, 2);
$result = $guard->notify(str_repeat('c', 64), $profile, $contact, $selection, $reference, $owner);
notificationCheck($result['notification_warning'] === true && $guardDb->queries === 0,
	'N05: SMTP is never attempted inside the Agenda transaction');
$guardDb->transaction_opened = 0;
$result = $guard->notify(str_repeat('c', 64), $profile, $contact, $selection, $reference, $owner);
notificationCheck($result['notification_warning'] === true && $guardDb->queries === 2
	&& $result['customer'] === 'failed' && $result['owner'] === 'failed',
	'N05: an unavailable durable delivery claim causes a generic warning rather than a mail or exception');
notificationCheck(strpos(json_encode($result), 'example.test') === false && strpos(json_encode($result), 'SMTP') === false,
	'N05: notification outcomes contain no addresses, credentials or transport errors');
fwrite(STDOUT, "[OK] N05 post-commit guard and database failure isolation without sending mail\n");
fwrite(STDOUT, "All CDav native booking confirmation tests passed.\n");
