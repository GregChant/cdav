<?php

/**
 * Recognize local-only test transports.
 * @param string $address Connecting address
 * @return bool Local loopback address
 */
function cdav_is_loopback_address($address)
{
	$address = strtolower(trim((string) $address));
	if ($address === '::1' || $address === '0:0:0:0:0:0:0:1') return true;
	if (str_starts_with($address, '::ffff:')) $address = substr($address, 7);
	return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
		&& str_starts_with($address, '127.');
}

/**
 * Accept forwarded HTTPS only from the explicitly configured connecting proxy.
 * @return bool Secure transport
 */
function cdav_is_secure_transport()
{
	if (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) === 'on') return true;
	$trusted = preg_split('/[\s,;]+/', getDolGlobalString('CDAV_TRUSTED_PROXY_IPS'), -1, PREG_SPLIT_NO_EMPTY);
	if (!$trusted || !in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)) return false;
	$proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
	return $proto === 'https' || strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''))) === 'on';
}

/**
 * Resolve the installed DAV script first, then append its attachment collection.
 * The previous PATH_INFO-based URL remains recognizable in stored calendars.
 * @param string $collection DAV attachment collection name
 * @return string[] Current and legacy collection URLs, with a trailing slash
 */
function cdav_attachment_base_urls($collection)
{
	$script = 'cdav/server.php';
	return array_unique(array(
		rtrim(dol_buildpath($script, 3), '/').'/'.$collection.'/',
		rtrim(dol_buildpath($script.'/'.$collection.'/', 3), '/').'/',
	));
}
