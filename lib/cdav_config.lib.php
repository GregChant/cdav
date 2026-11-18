<?php

if (!defined('DOL_DOCUMENT_ROOT')) {
	http_response_code(404);
	exit;
}

/**
 * Initialize the historical sync constants identically for DAV and ICS feeds.
 * Existing definitions keep precedence over native entity-specific settings.
 *
 * @return void
 */
function cdav_initialize_sync_constants()
{
	$integerDefaults = array(
		'CDAV_CONTACT_TAG' => 0,
		'CDAV_TASK_USER_ROLE' => 0,
		'CDAV_SYNC_PAST' => 31,
		'CDAV_SYNC_FUTURE' => 365,
		'CDAV_TASK_SYNC' => 0,
		'CDAV_INTERV_SYNC' => 0,
		'CDAV_INTERV_USER_ROLE' => 0,
		'CDAV_THIRD_SYNC' => 0,
		'CDAV_MEMBER_SYNC' => 0,
	);
	foreach ($integerDefaults as $name => $default) {
		if (!defined($name)) {
			define($name, getDolGlobalInt($name, $default));
		}
	}
	if (!defined('CDAV_URI_KEY')) {
		define('CDAV_URI_KEY', getDolGlobalString('CDAV_URI_KEY', substr(md5($_SERVER['HTTP_HOST'] ?? 'localhost'), 0, 8)));
	}
	// Separate native contact, third-party and member addressbook ID ranges.
	if (!defined('CDAV_ADDRESSBOOK_ID_SHIFT')) {
		define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000);
	}
}
