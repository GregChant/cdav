<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Exercise the real publication cipher and its file lifecycle without a database. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
$temporary = sys_get_temp_dir().'/cdav-booking-publication-'.bin2hex(random_bytes(8));
define('DOL_DOCUMENT_ROOT', $root);
define('DOL_DATA_ROOT', $temporary);
require dirname(__DIR__).'/class/CDavBookingService.php';

use Dolibarr\CDav\BookingException;
use Dolibarr\CDav\BookingPublication;

/**
 * Assert an observable outcome without printing cryptographic material.
 * @param bool $condition Expected true value
 * @param string $message Failure explanation
 * @return void
 */
function publicationCheck(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Require the same generic administrator error for every unreadable copy.
 * @param callable $read Attempt to open a stored copy
 * @return void
 */
function publicationUnreadable(callable $read): void
{
	try {
		$read();
	} catch (BookingException $exception) {
		publicationCheck($exception->getCode() === 503 && $exception->getMessage() === 'CDavBookingLinkUnreadable', 'Recovery must report a generic error');
		return;
	}
	throw new RuntimeException('An unreadable publication copy was unexpectedly recovered');
}

mkdir($temporary, 0700);
$privateDirectory = $temporary.'/.cdav-private';
$secretFile = $privateDirectory.'/booking-publication.key';
$backupFile = $temporary.'/saved-key';
$key = str_repeat('a', 64);
$hash = hash('sha256', $key);
$context = array(1, 2, 3, $hash);
try {
	$encrypted = BookingPublication::seal($key, ...$context);
	$repeated = BookingPublication::seal($key, ...$context);
	publicationCheck($encrypted !== $repeated && !str_contains($encrypted, $key), 'Independent encrypted copies must hide the key and use distinct nonces');
	publicationCheck(is_file($secretFile) && filesize($secretFile) === 32, 'The encryption key must persist as 32 bytes');
	if (DIRECTORY_SEPARATOR !== '\\') {
		publicationCheck((fileperms($secretFile) & 0077) === 0 && (fileperms($privateDirectory) & 0077) === 0,
			'The installation encryption key must use private filesystem permissions');
	}
	publicationCheck(BookingPublication::open($encrypted, ...$context) === $key && BookingPublication::open($repeated, ...$context) === $key,
		'The stored copies must both recover the original publication key');
	foreach (array(0 => 10, 1 => 20, 2 => 30, 3 => hash('sha256', str_repeat('b', 64))) as $index => $replacement) {
		$other = array_replace($context, array($index => $replacement));
		publicationUnreadable(static fn () => BookingPublication::open($encrypted, ...$other));
	}
	$altered = $encrypted;
	$offset = intdiv(strlen($altered), 2);
	$altered[$offset] = $altered[$offset] === 'A' ? 'B' : 'A';
	publicationUnreadable(static fn () => BookingPublication::open($altered, ...$context));

	publicationCheck(rename($secretFile, $backupFile), 'Cannot simulate a missing private encryption file');
	publicationUnreadable(static fn () => BookingPublication::open($encrypted, ...$context));
	publicationCheck(!file_exists($secretFile), 'Reading an old publication must not create a replacement encryption key');
	publicationCheck(rename($backupFile, $secretFile), 'Cannot restore the test encryption file');
	publicationCheck(BookingPublication::open($encrypted, ...$context) === $key, 'Restoring the encryption file must recover existing publications');

	$_SERVER['CONTEXT_DOCUMENT_ROOT'] = $temporary;
	publicationUnreadable(static fn () => BookingPublication::open($encrypted, ...$context));
	publicationUnreadable(static fn () => BookingPublication::seal($key, ...$context));
} finally {
	foreach (array($secretFile, $secretFile.'.lock', $backupFile) as $file) {
		if (is_file($file)) unlink($file);
	}
	if (is_dir($privateDirectory)) rmdir($privateDirectory);
	rmdir($temporary);
}

fwrite(STDOUT, "[OK] Publication encryption, private key restoration, authenticated row scope and rejection under the web root.\n");
