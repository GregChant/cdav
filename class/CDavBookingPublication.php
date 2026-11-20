<?php

namespace Dolibarr\CDav;

/** Encrypt the administration copy of a booking key independently of public validation. */
class BookingPublication
{
	private const CIPHER = 'aes-256-gcm';
	private const VERSION = 'v1:';
	private const KEY_BYTES = 32;
	private const IV_BYTES = 12;
	private const TAG_BYTES = 16;

	/**
	 * Protect a publication key with its native row identity as associated data.
	 * @param string $key Public booking key
	 * @param int $entity Native entity
	 * @param int $ownerId Profile owner
	 * @param int $profileId Profile ID
	 * @param string $hash Current publication hash
	 * @return string Versioned encrypted key
	 */
	public static function seal(string $key, int $entity, int $ownerId, int $profileId, string $hash): string
	{
		if (!function_exists('openssl_encrypt')) throw new BookingException('CDavBookingLinkUnreadable', 503);
		$iv = random_bytes(self::IV_BYTES);
		$tag = '';
		$context = self::context($entity, $ownerId, $profileId, $hash);
		$encrypted = openssl_encrypt($key, self::CIPHER, self::storageKey(true), OPENSSL_RAW_DATA, $iv, $tag, $context, self::TAG_BYTES);
		if ($encrypted === false || strlen($tag) !== self::TAG_BYTES) throw new BookingException('CDavBookingLinkUnreadable', 503);
		return self::VERSION.base64_encode($iv.$tag.$encrypted);
	}

	/**
	 * Recover the current key if the ciphertext belongs to this native profile.
	 * @param string $stored Versioned encrypted key
	 * @param int $entity Native entity
	 * @param int $ownerId Profile owner
	 * @param int $profileId Profile ID
	 * @param string $hash Current publication hash
	 * @return string Verified public booking key
	 */
	public static function open(string $stored, int $entity, int $ownerId, int $profileId, string $hash): string
	{
		if (!function_exists('openssl_decrypt') || !str_starts_with($stored, self::VERSION)) {
			throw new BookingException('CDavBookingLinkUnreadable', 503);
		}
		$payload = base64_decode(substr($stored, strlen(self::VERSION)), true);
		$offset = self::IV_BYTES + self::TAG_BYTES;
		if ($payload === false || strlen($payload) !== $offset + 64) throw new BookingException('CDavBookingLinkUnreadable', 503);
		$context = self::context($entity, $ownerId, $profileId, $hash);
		$key = openssl_decrypt(substr($payload, $offset), self::CIPHER, self::storageKey(false), OPENSSL_RAW_DATA,
			substr($payload, 0, self::IV_BYTES), substr($payload, self::IV_BYTES, self::TAG_BYTES), $context);
		if ($key === false || !hash_equals($hash, hash('sha256', $key))) throw new BookingException('CDavBookingLinkUnreadable', 503);
		return $key;
	}

	/**
	 * Bind the authenticated copy to the profile and its current publication.
	 * @param int $entity Native entity
	 * @param int $ownerId Profile owner
	 * @param int $profileId Profile ID
	 * @param string $hash Current publication hash
	 * @return string Associated data
	 */
	private static function context(int $entity, int $ownerId, int $profileId, string $hash): string
	{
		return 'cdav-booking-publication-v1|'.$entity.'|'.$ownerId.'|'.$profileId.'|'.$hash;
	}

	/**
	 * Load the instance encryption key from outside registered document directories.
	 * @param bool $create Initialize the key only for an explicit write
	 * @return string A private 256-bit key
	 */
	private static function storageKey(bool $create): string
	{
		$dataRoot = realpath(DOL_DATA_ROOT);
		if ($dataRoot === false) throw new BookingException('CDavBookingLinkUnreadable', 503);
		foreach (array(DOL_DOCUMENT_ROOT, $_SERVER['DOCUMENT_ROOT'] ?? '', $_SERVER['CONTEXT_DOCUMENT_ROOT'] ?? '') as $webRoot) {
			$webRoot = $webRoot === '' ? false : realpath($webRoot);
			if ($webRoot !== false && str_starts_with($dataRoot.DIRECTORY_SEPARATOR, rtrim($webRoot, '/\\').DIRECTORY_SEPARATOR)) {
				throw new BookingException('CDavBookingLinkUnreadable', 503);
			}
		}
		$directory = $dataRoot.'/.cdav-private';
		if ($create && !is_dir($directory)) @mkdir($directory, 0700);
		if (is_link($directory) || !is_dir($directory) || !self::privatePermissions($directory)) {
			throw new BookingException('CDavBookingLinkUnreadable', 503);
		}
		$path = $directory.'/booking-publication.key';
		if ($create && !file_exists($path)) self::createStorageKey($path);
		if (is_link($path) || !is_file($path) || !self::privatePermissions($path)) throw new BookingException('CDavBookingLinkUnreadable', 503);
		$key = @file_get_contents($path, false, null, 0, self::KEY_BYTES + 1);
		if ($key === false || strlen($key) !== self::KEY_BYTES) throw new BookingException('CDavBookingLinkUnreadable', 503);
		return $key;
	}

	/**
	 * Check Unix ownership permissions. Windows uses ACLs for the private data root.
	 * @param string $path Private file or directory
	 * @return bool Group and other users have no access
	 */
	private static function privatePermissions(string $path): bool
	{
		if (DIRECTORY_SEPARATOR === '\\') return true;
		$mode = @fileperms($path);
		return $mode !== false && ($mode & 0077) === 0;
	}

	/**
	 * Install the complete key atomically under a lock shared by all PHP workers.
	 * @param string $path Private file to initialize once
	 * @return void
	 */
	private static function createStorageKey(string $path): void
	{
		$lockPath = $path.'.lock';
		if (is_link($lockPath)) throw new BookingException('CDavBookingLinkUnreadable', 503);
		$stream = @fopen($lockPath, 'c');
		if ($stream === false) throw new BookingException('CDavBookingLinkUnreadable', 503);
		try {
			if (!flock($stream, LOCK_EX)) throw new BookingException('CDavBookingLinkUnreadable', 503);
			clearstatcache(true, $path);
			if (is_link($path)) throw new BookingException('CDavBookingLinkUnreadable', 503);
			if (file_exists($path)) return;
			$directory = dirname($path);
			$temporary = @tempnam($directory, '.booking-publication-');
			if ($temporary === false) throw new BookingException('CDavBookingLinkUnreadable', 503);
			try {
				if (dirname($temporary) !== $directory || @file_put_contents($temporary, random_bytes(self::KEY_BYTES)) !== self::KEY_BYTES
					|| !@rename($temporary, $path)) {
					throw new BookingException('CDavBookingLinkUnreadable', 503);
				}
			} finally {
				if (is_file($temporary)) @unlink($temporary);
			}
		} finally {
			fclose($stream);
		}
	}
}
