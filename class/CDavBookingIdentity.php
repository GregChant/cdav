<?php

namespace Dolibarr\CDav;

/** Present native company and professional identity after a booking key is checked. */
class BookingIdentity
{
	private const MAX_PHOTO_BYTES = 1048576;

	/**
	 * Keep only the explicitly public booking identity, using native logo URLs.
	 *
	 * @param array $professional Native professional fields approved for publication
	 * @param object|null $company Native company object
	 * @param object $conf Native instance configuration
	 * @return array Public identity without paths, login or broader public-card tokens
	 */
	public static function build(array $professional, $company, $conf): array
	{
		$identity = array('company_name' => (string) ($company->name ?? ''), 'company_logo' => '', 'company_favicon' => '',
			'professional_name' => (string) ($professional['name'] ?? ''),
			'professional_photo' => self::photo($professional, $conf),
			'email' => '', 'email_href' => '', 'phone' => '', 'phone_href' => '', 'mobile' => '', 'mobile_href' => '');
		$email = (string) ($professional['email'] ?? '');
		if (!preg_match('/[\x00-\x20\x7f<>]/u', $email) && isValidEmail($email)) {
			$identity['email'] = $email;
			$identity['email_href'] = 'mailto:'.rawurlencode($email);
		}
		foreach (array('phone', 'mobile') as $field) {
			$value = (string) ($professional[$field] ?? '');
			if (preg_match('/[\x00-\x1f\x7f<>]/u', $value)) continue;
			$identity[$field] = $value;
			$number = preg_replace('/[ ().-]/', '', $value);
			if (preg_match('/^\+?[0-9]{3,20}$/D', $number)) $identity[$field.'_href'] = 'tel:'.$number;
		}
		$logo = (string) ($company->logo ?? '');
		$small = (string) ($company->logo_small ?? '');
		$directory = (string) ($conf->mycompany->dir_output ?? '');
		foreach (array('logos/thumbs/' => $small, 'logos/' => $logo) as $prefix => $file) {
			if (!self::filename($file) || $directory === '' || !is_readable($directory.'/'.$prefix.$file)) continue;
			$identity['company_logo'] = DOL_URL_ROOT.'/viewimage.php?modulepart=mycompany&entity='.((int) $conf->entity)
				.'&file='.rawurlencode($prefix.$file);
			break;
		}
		$identity['company_favicon'] = $identity['company_logo'];
		// Native Dolibarr uses the square company thumbnail for its tab icon.
		foreach (array('logo_squarred_mini' => 'logos/thumbs/', 'logo_squarred_small' => 'logos/thumbs/', 'logo_squarred' => 'logos/') as $field => $prefix) {
			$file = (string) ($company->{$field} ?? '');
			if (!self::filename($file) || $directory === '' || !is_file($directory.'/'.$prefix.$file) || !is_readable($directory.'/'.$prefix.$file)) continue;
			$identity['company_favicon'] = DOL_URL_ROOT.'/viewimage.php?modulepart=mycompany&entity='.((int) $conf->entity)
				.'&file='.rawurlencode($prefix.$file);
			break;
		}
		return $identity;
	}

	/**
	 * Reject file names that could escape a native photo directory.
	 * @param string $file Native stored filename
	 * @return bool Whether the value is a single safe filename
	 */
	private static function filename(string $file): bool
	{
		return $file !== '' && $file !== '.' && $file !== '..' && !preg_match('/[\x00-\x1f\x7f\/\\\\]/', $file);
	}

	/**
	 * Embed a bounded native photo without publishing a separate user-card key.
	 * @param array $professional Validated native professional metadata
	 * @param object $conf Native instance configuration
	 * @return string Bounded raster data URI, or empty when no usable photo exists
	 */
	private static function photo(array $professional, $conf): string
	{
		$file = (string) ($professional['photo'] ?? '');
		$relative = (string) ($professional['photo_directory'] ?? '');
		$nativeRoot = (string) ($conf->user->dir_output ?? '');
		if ($nativeRoot === '') return '';
		$root = realpath($nativeRoot);
		if (!self::filename($file) || !$root || $relative === '' || strpos($relative, '\\') !== false
			|| strpos($relative, "\0") !== false || in_array('..', explode('/', $relative), true)) return '';
		$expected = $root.'/'.trim($relative, '/');
		$directory = realpath($expected);
		if ($directory !== $expected || strpos($directory, $root.'/') !== 0) return '';
		foreach (array(getImageFileNameForSize($file, '_small'), $file) as $candidate) {
			$path = $directory.'/'.$candidate;
			if (realpath($path) !== $path || !is_file($path) || is_link($path) || !is_readable($path)
				|| filesize($path) > self::MAX_PHOTO_BYTES) continue;
			$bytes = @file_get_contents($path, false, null, 0, self::MAX_PHOTO_BYTES + 1);
			if (!is_string($bytes) || strlen($bytes) > self::MAX_PHOTO_BYTES) continue;
			$image = @getimagesizefromstring($bytes);
			if (!$image || !in_array($image['mime'] ?? '', array('image/png', 'image/jpeg', 'image/gif', 'image/webp'), true)
				|| $image[0] < 1 || $image[1] < 1 || $image[0] * $image[1] > 16777216) continue;
			return 'data:'.$image['mime'].';base64,'.base64_encode($bytes);
		}
		return '';
	}
}
