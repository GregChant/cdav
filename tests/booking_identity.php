<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Native identity presentation and file isolation regressions without a DB. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
define('DOL_URL_ROOT', '/dolibarr');
require $root.'/core/lib/functions.lib.php';
require dirname(__DIR__).'/class/CDavBookingIdentity.php';

/**
 * Assert a public identity outcome.
 * @param bool $condition Expected true condition
 * @param string $message Failure explanation
 * @return void
 */
function identityCheck($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

$temporary = sys_get_temp_dir().'/cdav-booking-identity-'.bin2hex(random_bytes(8));
mkdir($temporary, 0700);
mkdir($temporary.'/users/17/photos/thumbs', 0700, true);
mkdir($temporary.'/users/18/photos', 0700, true);
mkdir($temporary.'/company/logos/thumbs', 0700, true);
$conf = (object) array('entity' => 2, 'global' => new stdClass(),
	'user' => (object) array('dir_output' => $temporary.'/users'),
	'mycompany' => (object) array('dir_output' => $temporary.'/company'));
$company = (object) array('name' => 'Company & partners', 'logo' => 'company logo.png', 'logo_small' => 'small.png');
$professional = array('name' => 'Selected Professional', 'email' => 'pro+booking@example.test',
	'phone' => '+41 (22) 555-01-01', 'mobile' => '+41 79 555 01 02', 'photo' => 'portrait.png', 'photo_directory' => '17/photos/');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
file_put_contents($temporary.'/users/17/photos/portrait.png', $png);
file_put_contents($temporary.'/company/logos/company logo.png', $png);
$files = array($temporary.'/users/17/photos/portrait.png', $temporary.'/company/logos/company logo.png');
$links = array();
try {
	$identity = \Dolibarr\CDav\BookingIdentity::build($professional, $company, $conf);
	identityCheck($identity['company_name'] === $company->name && $identity['professional_name'] === $professional['name'],
		'Identity belongs to the configured company and selected professional');
	identityCheck($identity['professional_photo'] === 'data:image/png;base64,'.base64_encode($png),
		'The native professional photo is embedded as bounded image bytes');
	identityCheck($identity['company_logo'] === '/dolibarr/viewimage.php?modulepart=mycompany&entity=2&file=logos%2Fcompany%20logo.png',
		'Company logos reuse the public native endpoint and installation prefix');
	identityCheck($identity['email_href'] === 'mailto:pro%2Bbooking%40example.test'
		&& $identity['phone_href'] === 'tel:+41225550101' && $identity['mobile_href'] === 'tel:+41795550102',
		'Public contact links use only safe mail and telephone schemes');
	identityCheck(strpos(json_encode($identity), $temporary) === false && !isset($identity['photo_directory'], $identity['login']),
		'Public identity does not expose filesystem paths, user login or public-card capabilities');
	file_put_contents($temporary.'/company/logos/thumbs/small.png', $png);
	$files[] = $temporary.'/company/logos/thumbs/small.png';
	identityCheck(strpos(\Dolibarr\CDav\BookingIdentity::build($professional, $company, $conf)['company_logo'], 'logos%2Fthumbs%2Fsmall.png') !== false,
		'Native company thumbnails take priority when available');
	$thumbnail = $temporary.'/users/17/photos/'.getImageFileNameForSize('portrait.png', '_small');
	file_put_contents($thumbnail, $gif);
	$files[] = $thumbnail;
	identityCheck(\Dolibarr\CDav\BookingIdentity::build($professional, $company, $conf)['professional_photo'] === 'data:image/gif;base64,'.base64_encode($gif),
		'Native thumbnail bytes take priority and determine their real media type');
	unlink($thumbnail);
	array_pop($files);
	foreach (array('../portrait.png', '..', 'nested/photo.png', 'a\\b.png', "portrait.png\0") as $filename) {
		$invalid = array_replace($professional, array('photo' => $filename));
		identityCheck(\Dolibarr\CDav\BookingIdentity::build($invalid, $company, $conf)['professional_photo'] === '',
			'Photo filenames cannot escape the selected native directory');
	}
	foreach (array('../users/17/photos', '17/../18/photos', "17/photos\0") as $directory) {
		$invalid = array_replace($professional, array('photo_directory' => $directory));
		identityCheck(\Dolibarr\CDav\BookingIdentity::build($invalid, $company, $conf)['professional_photo'] === '',
			'Photo directories cannot cross native user boundaries');
	}
	$outside = $temporary.'/users/18/photos/other.png';
	file_put_contents($outside, $png);
	$files[] = $outside;
	$link = $temporary.'/users/17/photos/linked.png';
	symlink($outside, $link);
	$links[] = $link;
	identityCheck(\Dolibarr\CDav\BookingIdentity::build(array_replace($professional, array('photo' => 'linked.png')), $company, $conf)['professional_photo'] === '',
		'A symbolic photo link must not publish another native user photo');
	$link = $temporary.'/users/17/linked';
	symlink($temporary.'/users/18/photos', $link);
	$links[] = $link;
	identityCheck(\Dolibarr\CDav\BookingIdentity::build(array_replace($professional, array('photo' => 'other.png', 'photo_directory' => '17/linked')), $company, $conf)['professional_photo'] === '',
		'A linked directory must not expose another native user photo');
	$path = $temporary.'/users/17/photos/portrait.png';
	$header = 'IHDR'.pack('NNCCCCC', 100000, 100000, 8, 2, 0, 0, 0);
	$pixelBomb = "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header))
		.pack('N', 0).'IEND'.pack('N', crc32('IEND'));
	$dimensions = getimagesizefromstring($pixelBomb);
	identityCheck($dimensions[0] === 100000 && $dimensions[1] === 100000, 'The pixel-limit fixture has a readable compressed image header');
	foreach (array('not an image', '<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>', str_repeat('x', 1048577), $pixelBomb) as $invalid) {
		file_put_contents($path, $invalid);
		clearstatcache(true, $path);
		identityCheck(\Dolibarr\CDav\BookingIdentity::build($professional, $company, $conf)['professional_photo'] === '',
			'Invalid, active or oversized native files are not embedded in the public page');
	}
	file_put_contents($path, $png);
	$badContact = array_replace($professional, array('email' => "client@example.test\r\nBcc:other@example.test", 'phone' => 'javascript:alert(1)', 'mobile' => '<script>bad</script>'));
	$invalid = \Dolibarr\CDav\BookingIdentity::build($badContact, $company, $conf);
	identityCheck($invalid['email_href'] === '' && $invalid['phone_href'] === '' && $invalid['mobile'] === '',
		'Contact values cannot create injected mail headers, HTML or executable links');
	$empty = \Dolibarr\CDav\BookingIdentity::build(array(), null, (object) array('entity' => 1));
	identityCheck($empty['professional_photo'] === '' && $empty['company_logo'] === '' && $empty['email'] === '',
		'Missing native identity fields degrade to plain booking controls');
} finally {
	foreach ($links as $link) unlink($link);
	foreach ($files as $file) if (is_file($file)) unlink($file);
	foreach (array('/users/17/photos/thumbs', '/users/17/photos', '/users/18/photos', '/users/17', '/users/18', '/users', '/company/logos/thumbs', '/company/logos', '/company', '') as $directory) rmdir($temporary.$directory);
}
fwrite(STDOUT, "All CDav public identity and photo isolation tests passed.\n");
