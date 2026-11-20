<?php

// Developer helpers are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Capture native business values and assignments independently of DAV serialization.
 * @param DoliDB $db Native database
 * @param int $id Appointment identifier
 * @return array Native business projection
 */
function businessLiveEventState($db, int $id): array
{
	$row = liveRow($db, 'SELECT label, note, location, fk_soc, fk_project, fk_contact, fk_action, code, fulldayevent,
		percent, priority, transparency, fk_user_action, fk_element, elementtype FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
	liveAssert($row !== null, 'Business appointment disappeared during a protected update');
	$resources = $db->query('SELECT element_type, fk_element, mandatory, transparency, answer_status FROM '
		.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$id.' ORDER BY element_type, fk_element');
	liveAssert((bool) $resources, 'Unable to inspect protected appointment assignments');
	$assignments = array();
	while ($resource = $db->fetch_object($resources)) $assignments[] = (array) $resource;
	return array('event' => (array) $row, 'resources' => $assignments);
}

/**
 * Apply an iCalendar mutation while keeping the remaining server representation.
 * @param string $body Existing representation
 * @param callable $mutation Component mutation
 * @return string New representation
 */
function businessLiveCalendar(string $body, callable $mutation): string
{
	$calendar = \Sabre\VObject\Reader::read($body);
	$mutation($calendar->VEVENT);
	return $calendar->serialize();
}

/**
 * Hold a native row until the DAV worker is demonstrably waiting for that row.
 * The callback writes through Dolibarr while the native row lock is still held.
 * @param DoliDB $db Native database
 * @param array $context DAV credentials and server URL
 * @param string $table Native table without prefix
 * @param int $id Native row identifier
 * @param string $path DAV resource path
 * @param string $body Replacement representation
 * @param array $headers Request headers
 * @param callable $beforeRelease Native API mutation executed before lock release
 * @return array Completed HTTP response
 */
function businessLiveLockedWrite($db, array $context, string $table, int $id, string $path, string $body, array $headers, callable $beforeRelease): array
{
	global $dolibarr_main_db_name;
	liveAssert(in_array($table, array('actioncomm', 'socpeople'), true), 'Unsupported native row-lock fixture');
	$idColumn = $table === 'actioncomm' ? 'id' : 'rowid';
	liveAssert((bool) $db->begin(), 'Unable to open the native business race barrier');
	liveAssert(liveRow($db, 'SELECT '.$idColumn.' FROM '.MAIN_DB_PREFIX.$table.' WHERE '.$idColumn.' = '.$id.' FOR UPDATE') !== null,
		'Unable to hold the native business row');
	$multi = curl_multi_init();
	$responseHeaders = array();
	$handle = liveHttpHandle($context['base_url'], $context['login'], $context['password'], 'PUT', $path, $body, $headers, $responseHeaders);
	curl_multi_add_handle($multi, $handle);
	$released = false;
	try {
		$deadline = microtime(true) + 10;
		$waiting = false;
		do {
			liveAssert(curl_multi_exec($multi, $running) === CURLM_OK, 'Unable to dispatch the native business race request');
			$processes = $db->query('SHOW FULL PROCESSLIST');
			liveAssert((bool) $processes, 'Unable to inspect the native business race barrier');
			while ($process = $db->fetch_object($processes)) {
				$query = (string) ($process->Info ?? '');
				if (($process->db ?? '') === $dolibarr_main_db_name && strpos($query, MAIN_DB_PREFIX.$table) !== false
					&& stripos($query, 'FOR UPDATE') !== false && preg_match('/(?:a\.id|obj\.rowid)\s*=\s*'.$id.'\b/', $query)) {
					$waiting = true;
				}
			}
			if ($waiting) break;
			curl_multi_select($multi, 0.02);
		} while ($running && microtime(true) < $deadline);
		liveAssert($waiting, 'The DAV worker did not reach the native '.$table.' row lock before reading business values');
		$beforeRelease();
		liveAssert((bool) $db->commit(), 'Unable to release the native business race barrier');
		$released = true;
		do {
			liveAssert(curl_multi_exec($multi, $running) === CURLM_OK, 'The native business race response failed');
			if ($running) curl_multi_select($multi, 0.02);
		} while ($running);
		liveAssert(curl_errno($handle) === 0, 'The native business race HTTP transport failed');
		return array('status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
			'headers' => $responseHeaders, 'body' => curl_multi_getcontent($handle));
	} finally {
		if (!$released) $db->rollback();
		curl_multi_remove_handle($multi, $handle);
		unset($handle, $multi);
	}
}

/**
 * Exercise native appointments and contact business boundaries through real DAV HTTP.
 * Called only by the opt-in isolated integration suite, which owns all credentials.
 * @param DoliDB $db Native database
 * @param User $testUser Isolated DAV account
 * @param User $admin Native fixture administrator
 * @param array $context Existing suite URLs, credentials and fixture identifiers
 * @return void
 */
function businessLiveCases($db, $testUser, $admin, array $context): void
{
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
	$events = array();
	$contacts = array();
	$users = array();
	$members = array();
	$companies = array();
	$category = null;
	$memberType = null;
	$limitedAccount = null;
	$limitedReadRight = 0;
	$limitedCommercial = false;
	$project = null;
	$prefix = (string) $context['prefix'];
	$entity = (int) $context['entity'];
	$companyId = (int) $context['company_id'];
	$key = getDolGlobalString('CDAV_URI_KEY');
	$calendarPath = (string) $context['calendar_path'];
	$addressBookPath = (string) $context['addressbook_path'];
	$http = static function ($method, $path, $body = null, $headers = array()) use ($context) {
		return liveHttp($context['base_url'], $context['login'], $context['password'], $method, $path, $body, $headers);
	};
	$calendarHeaders = array('Content-Type: text/calendar; charset=utf-8');
	$cardHeaders = array('Content-Type: text/vcard; charset=utf-8');
	try {
		$contact = new Contact($db);
		$contact->lastname = $prefix.' Business contact';
		$contact->firstname = 'Native';
		$contact->email = 'business-'.$context['token'].'@example.test';
		$contact->phone_pro = '+41225550123';
		$contact->socid = $companyId;
		$contact->statut = 1;
		$contact->priv = 0;
		$contactId = (int) $contact->create($testUser);
		liveAssert($contactId > 0, 'Unable to create the native business contact: '.$contact->error);
		$contacts[] = $contactId;
		$contactPath = $addressBookPath.rawurlencode($contactId.'-ct-'.$key);
		$category = new Categorie($db);
		$category->label = $prefix.' Business tag';
		$category->type = Categorie::TYPE_CONTACT;
		liveAssert($category->create($admin) > 0 && $contact->setCategories(array((int) $category->id)) > 0,
			'Unable to attach a native business category to the contact');

		$project = new Project($db);
		$project->ref = 'DAVBUS-'.$context['token'];
		$project->title = $prefix.' Business project';
		$project->description = '<p>Project scope</p><p>Preserve <strong>native</strong> paragraphs</p>';
		$project->socid = $companyId;
		$project->public = 1;
		liveAssert($project->create($testUser) > 0, 'Unable to create the native business project: '.$project->error);
		$projectDescription = (string) liveRow($db, 'SELECT description FROM '.MAIN_DB_PREFIX.'projet WHERE rowid = '.((int) $project->id))->description;
		$event = new ActionComm($db);
		$event->type_code = 'AC_RDV';
		$event->code = 'AC_RDV';
		$event->label = $prefix.' Protected native appointment';
		$event->location = 'Route du Test 1' . "\n" . '1200 Genève';
		$event->note_private = '<p>Native <strong>business notes</strong></p>';
		$event->datep = strtotime('+12 days 10:00 UTC');
		$event->datef = $event->datep + 3600;
		$event->percentage = -1;
		$event->priority = 2;
		$event->transparency = 1;
		$event->socid = $companyId;
		$event->fk_project = (int) $project->id;
		$event->contact_id = $contactId;
		$event->socpeopleassigned = array($contactId => array('id' => $contactId));
		$event->userownerid = (int) $testUser->id;
		$event->userassigned = array((int) $testUser->id => array('id' => (int) $testUser->id, 'transparency' => 1));
		$eventId = (int) $event->create($testUser);
		liveAssert($eventId > 0, 'Unable to create the protected native appointment: '.$event->error);
		$events[] = $eventId;
		$reminder = new ActionCommReminder($db);
		$reminder->fk_actioncomm = $eventId;
		$reminder->fk_user = (int) $testUser->id;
		$reminder->typeremind = 'browser';
		$reminder->offsetvalue = 15;
		$reminder->offsetunit = 'i';
		$reminder->status = 0;
		$reminder->dateremind = (int) $event->datep - 900;
		$reminderId = (int) $reminder->create($testUser);
		liveAssert($reminderId > 0, 'Unable to create the native reminder fixture: '.$reminder->error);
		$reminderStateSql = 'SELECT rowid, entity, typeremind, fk_user, offsetvalue, offsetunit, status, fk_actioncomm, fk_email_template, lasterror FROM '
			.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.$reminderId;
		$reminderState = (array) liveRow($db, $reminderStateSql);
		// Native ActionComm cannot set contact PARTSTAT/mandatory metadata on
		// create/update. Seed that API gap so preserving it is a real regression.
		liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET mandatory = 1, transparency = 1,
			answer_status = \'ACCEPTED\' WHERE fk_actioncomm = '.$eventId), 'Unable to prepare native participant metadata');
		$eventPath = $calendarPath.rawurlencode($eventId.'-ev-'.$key);
		$state = businessLiveEventState($db, $eventId);
		liveAssert(liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$eventId) === null,
			'An appointment created through native ActionComm unexpectedly acquired DAV provenance');
		$fresh = $http('GET', $eventPath);
		liveHttpStatus($fresh, array(200), 'BL01 native protected appointment export');
		$description = (string) \Sabre\VObject\Reader::read($fresh['body'])->VEVENT->DESCRIPTION;
		$headings = str_starts_with($description, "Rendez-vous\n")
			? array('Rendez-vous', 'Client et contact', 'Projet') : array('Appointment', 'Client and contact', 'Project');
		$positions = array_map(static fn($heading) => strpos($description, $heading."\n"), $headings);
		liveAssert($positions[0] === 0 && $positions[1] !== false && $positions[2] !== false && $positions[1] < $positions[2]
			&& str_contains($description, "\n\n".$headings[1]."\n") && str_contains($description, "\n\n".$headings[2]."\n"),
			'The native calendar description is not organized into appointment, client/contact and project blocks');
		liveAssert(str_contains($description, 'Native business notes') && str_contains($description, $prefix.' LINK SA')
			&& str_contains($description, 'business-'.$context['token'].'@example.test')
			&& str_contains($description, '['.$project->ref.'] '.$project->title)
			&& preg_match('/Project scope\s*\n\s*Preserve native paragraphs/', $description)
			&& !str_contains($description, '<p>') && !str_contains($description, '<strong>'),
			'The native plain-text description lost appointment notes, contact details or project paragraphs');
		liveHttpStatus($http('PUT', $eventPath, $fresh['body'], $calendarHeaders), array(200, 204), 'BL02 protected GET/PUT echo');
		liveAssert(businessLiveEventState($db, $eventId) === $state, 'A protected GET/PUT echo changed native business values');
		liveOk('BL01-BL02 native appointment provenance, rich notes and native business GET/PUT preservation');

		$start = (int) $event->datep + 86400;
		$end = (int) $event->datef + 86400;
		$moved = businessLiveCalendar($fresh['body'], static function ($component) use ($start, $end) {
			$component->DTSTART->setDateTime(new DateTimeImmutable('@'.$start));
			$component->DTEND->setDateTime(new DateTimeImmutable('@'.$end));
			$component->SEQUENCE = 500;
			$component->add('X-CLIENT-UNTRUSTED', 'not-native-business-data');
		});
		liveHttpStatus($http('PUT', $eventPath, $moved, $calendarHeaders), array(200, 204), 'BL03 native appointment date move');
		$dates = liveRow($db, 'SELECT datep, datep2 FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId);
		liveAssert($db->jdate($dates->datep) === $start && $db->jdate($dates->datep2) === $end,
			'The protected move did not update the same native appointment dates');
		liveAssert(businessLiveEventState($db, $eventId) === $state, 'A date move altered native customer, project, contacts or other business fields');
		liveAssert((string) liveRow($db, 'SELECT description FROM '.MAIN_DB_PREFIX.'projet WHERE rowid = '.((int) $project->id))->description === $projectDescription,
			'Formatting or moving a calendar appointment changed the native project description');
		liveOk('BL25 native calendar descriptions group appointment, client/contact and project details without rewriting native rich text');
		liveAssert($db->jdate(liveRow($db, 'SELECT dateremind FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.$reminderId)->dateremind) === $start - 900,
			'Moving an appointment left its native reminder at the old date');
		liveAssert((array) liveRow($db, $reminderStateSql) === $reminderState,
			'Moving an appointment replaced a reminder or changed its recipient, offset, type or lifecycle');
		$origin = liveRow($db, 'SELECT dav_origin FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$eventId);
		liveAssert($origin === null || (int) $origin->dav_origin === 0, 'Moving a native appointment promoted it to DAV ownership');
		$fresh = $http('GET', $eventPath);
		liveAssert((int) (string) \Sabre\VObject\Reader::read($fresh['body'])->VEVENT->SEQUENCE === 1,
			'A protected date move trusted the client revision instead of advancing the server sequence');
		$equivalent = businessLiveCalendar($fresh['body'], static function ($component) use ($start, $end) {
			$timezone = new DateTimeZone('Europe/Zurich');
			$component->DTSTART->setDateTime((new DateTimeImmutable('@'.$start))->setTimezone($timezone));
			$component->DTEND->setDateTime((new DateTimeImmutable('@'.$end))->setTimezone($timezone));
		});
		liveHttpStatus($http('PUT', $eventPath, $equivalent, $calendarHeaders), array(200, 204), 'BL04 equivalent timezone serialization');
		liveAssert(businessLiveEventState($db, $eventId) === $state, 'Timezone normalization changed native business values');
		liveOk('BL03-BL04 date moves and timezone normalization retain the same native links and identity');

		$raceBody = businessLiveCalendar($fresh['body'], static function ($component) use ($start, $end) {
			$component->DTSTART->setDateTime(new DateTimeImmutable('@'.($start + 3600)));
			$component->DTEND->setDateTime(new DateTimeImmutable('@'.($end + 3600)));
		});
		$race = businessLiveLockedWrite($db, $context, 'actioncomm', $eventId, $eventPath, $raceBody, $calendarHeaders,
			static function () use ($db, $eventId, $testUser) {
				$native = new ActionComm($db);
				liveAssert($native->fetch($eventId) > 0, 'Unable to load the concurrent native appointment');
				$native->fk_project = 0;
				liveAssert($native->update($testUser) > 0, 'Unable to change the native project while a DAV write is waiting');
			});
		liveHttpStatus($race, array(200, 204, 403), 'BL20 concurrent native project change');
		liveAssert(empty(liveRow($db, 'SELECT fk_project FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId)->fk_project),
			'A waiting DAV write restored an obsolete native project link');
		liveAssert($event->fetch($eventId) > 0, 'Unable to restore the native project fixture');
		$event->fk_project = (int) $project->id;
		liveAssert($event->update($testUser) > 0, 'Unable to restore the native project after the race');
		liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET mandatory = 1, transparency = 1,
			answer_status = \'ACCEPTED\' WHERE fk_actioncomm = '.$eventId), 'Unable to restore native participant fixture metadata');
		liveOk('BL20 a protected DAV write waits for native row ownership and preserves a concurrent project change');

		$fresh = $http('GET', $eventPath);
		$trustedMetadata = liveRow($db, 'SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$eventId);
		liveAssert($trustedMetadata !== null, 'A real date move did not persist its server-owned scheduling revision');
		$trustedItem = \Sabre\VObject\Reader::read((string) $trustedMetadata->calendardata)->VEVENT;
		foreach ($trustedItem->children() as $property) {
			liveAssert(in_array($property->name, array('UID', 'DTSTART', 'DTEND', 'DUE', 'DURATION', 'SEQUENCE', 'DTSTAMP'), true),
				'Protected scheduling metadata retained untrusted client business properties');
		}
		$changes = array(
			'subject' => static function ($component) {
				$component->SUMMARY = 'Forbidden subject'; },
			'location' => static function ($component) {
				$component->LOCATION = 'Forbidden address'; },
			'notes' => static function ($component) {
				$component->DESCRIPTION = 'Forbidden notes'; },
			'participants' => static function ($component) {
				$component->add('ATTENDEE', 'mailto:outside@example.test'); },
			'identity' => static function ($component) {
				$component->UID = 'forbidden-identity'; },
			'busy state' => static function ($component) {
				$component->TRANSP = 'TRANSPARENT'; },
			'recurrence' => static function ($component) {
				$component->RRULE = 'FREQ=DAILY;COUNT=3'; },
			'attachment' => static function ($component) {
				$component->add('ATTACH', 'https://files.example.test/forbidden.pdf'); },
			'alarm' => static function ($component) {
				$alarm = $component->add('VALARM');
				$alarm->ACTION = 'DISPLAY';
				$alarm->TRIGGER = '-PT5M';
				$alarm->DESCRIPTION = 'Forbidden reminder';
			},
			'duration' => static function ($component) use ($end) {
				$component->DTEND->setDateTime(new DateTimeImmutable('@'.($end + 1800))); },
		);
		foreach ($changes as $name => $mutation) {
			$response = $http('PUT', $eventPath, businessLiveCalendar($fresh['body'], $mutation), $calendarHeaders);
			liveHttpStatus($response, array(403), 'BL05 forbidden protected '.$name);
			liveAssert(businessLiveEventState($db, $eventId) === $state, 'Rejected '.$name.' had a native business side effect');
		}
		$managedBefore = (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE fk_actioncomm = '.$eventId)->nb;
		$managed = $http('POST', $eventPath.'?action=attachment-add', 'Forbidden document', array(
			'Content-Type: text/plain', 'Content-Disposition: attachment; filename="forbidden.txt"',
			'If-Match: '.$fresh['headers']['etag'],
		));
		liveHttpStatus($managed, array(403), 'BL06 protected managed-attachment POST');
		liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_managed_attachment WHERE fk_actioncomm = '.$eventId)->nb === $managedBefore,
			'A refused managed-attachment request created technical attachment metadata');
		$alias = $http('PUT', $calendarPath.rawurlencode($prefix.'-native-alias.ics'), $fresh['body'], $calendarHeaders);
		liveHttpStatus($alias, array(403, 409), 'BL07 protected UID alias cannot recreate a native appointment');
		liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm WHERE label = \''.$db->escape($state['event']['label']).'\'')->nb === 1,
			'A protected UID alias created a duplicate native appointment');
		$stale = $http('PUT', $eventPath, $fresh['body'], array_merge($calendarHeaders, array('If-Match: "stale-business-etag"')));
		liveHttpStatus($stale, array(412), 'BL08 protected appointment stale ETag');
		liveAssert((string) liveRow($db, 'SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.$eventId)->calendardata
			=== (string) $trustedMetadata->calendardata, 'A refused protected write changed trusted scheduling metadata');
		liveOk('BL05-BL08 protected business changes, document POST, UID recreation and stale writes have no partial effects');
		$currentStart = $db->jdate(liveRow($db, 'SELECT datep FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId)->datep);
		liveAssert($db->jdate(liveRow($db, 'SELECT dateremind FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.$reminderId)->dateremind) === $currentStart - 900
			&& (array) liveRow($db, $reminderStateSql) === $reminderState,
			'Protected date changes, normalizations or rejected requests altered the native reminder policy');
		liveOk('BL22 native reminders follow the moved date while retaining their identity, recipient, offset and pending status');

		$card = $http('GET', $contactPath);
		liveHttpStatus($card, array(200), 'BL09 native contact export');
		$editable = \Sabre\VObject\Reader::read($card['body']);
		unset($editable->ORG);
		unset($editable->CLASS);
		unset($editable->CATEGORIES);
		unset($editable->TEL);
		$editable->add('TEL', '+41225550999', array('TYPE' => 'work,voice'));
		liveHttpStatus($http('PUT', $contactPath, $editable->serialize(), $cardHeaders), array(200, 204), 'BL09 contact phone with omitted business fields');
		$contactState = liveRow($db, 'SELECT fk_soc, priv, statut, phone, email FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId);
		liveAssert((int) $contactState->fk_soc === $companyId && (int) $contactState->priv === 0 && (int) $contactState->statut === 1
			&& (string) $contactState->phone === '+41225550999', 'Contact coordinate editing changed a native business relationship or status');
		liveAssert(liveRow($db, 'SELECT fk_categorie FROM '.MAIN_DB_PREFIX.'categorie_contact WHERE fk_socpeople = '.$contactId
			.' AND fk_categorie = '.((int) $category->id)) !== null, 'Omitting CATEGORIES removed a native business classification');
		$card = $http('GET', $contactPath);
		$cardChanges = array('organization' => 'ORG:Forbidden company', 'empty organization' => 'ORG:',
			'identity' => 'UID:forbidden-contact-uid', 'visibility' => 'CLASS:PRIVATE', 'categories' => 'CATEGORIES:Forbidden business tag');
		foreach ($cardChanges as $name => $line) {
			$changed = \Sabre\VObject\Reader::read($card['body']);
			list($property, $value) = explode(':', $line, 2);
			unset($changed->{$property});
			$changed->add($property, $value);
			liveHttpStatus($http('PUT', $contactPath, $changed->serialize(), $cardHeaders), array(403), 'BL10 protected contact '.$name);
			liveAssert((array) liveRow($db, 'SELECT fk_soc, priv, statut, phone, email FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId) === (array) $contactState,
				'Refused contact '.$name.' changed native data');
		}
		liveOk('BL09-BL10 contact coordinates remain editable while organization, identity, visibility and classification are protected');

		$otherCompany = new Societe($db);
		$otherCompany->name = $prefix.' Concurrent company';
		$otherCompany->nom = $otherCompany->name;
		$otherCompany->client = 0;
		$otherCompany->fournisseur = 0;
		$otherCompany->status = 1;
		$otherCompanyId = (int) $otherCompany->create($testUser);
		liveAssert($otherCompanyId > 0, 'Unable to create the concurrent native company fixture');
		$companies[] = $otherCompanyId;
		$contactRace = \Sabre\VObject\Reader::read($card['body']);
		unset($contactRace->ORG);
		unset($contactRace->TEL);
		$contactRace->add('TEL', '+41225550444', array('TYPE' => 'work,voice'));
		$race = businessLiveLockedWrite($db, $context, 'socpeople', $contactId, $contactPath, $contactRace->serialize(), $cardHeaders,
			static function () use ($db, $contactId, $otherCompanyId, $testUser) {
				$native = new Contact($db);
				liveAssert($native->fetch($contactId, $testUser) > 0, 'Unable to load the concurrent native contact');
				$native->socid = $otherCompanyId;
				liveAssert($native->update($contactId, $testUser) > 0, 'Unable to change the native contact company during a DAV write');
			});
		liveHttpStatus($race, array(200, 204), 'BL21 concurrent native contact company change');
		$contactAfterRace = liveRow($db, 'SELECT fk_soc, phone FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId);
		liveAssert((int) $contactAfterRace->fk_soc === $otherCompanyId && (string) $contactAfterRace->phone === '+41225550444',
			'A waiting CardDAV coordinate write restored an obsolete company link or lost its permitted phone update');
		liveAssert($contact->fetch($contactId, $testUser) > 0, 'Unable to restore the native contact fixture');
		$contact->socid = $companyId;
		liveAssert($contact->update($contactId, $testUser) > 0, 'Unable to restore the native contact company after the race');
		liveOk('BL21 a CardDAV write locks before reading and preserves a concurrent native company reassignment');

		$linkedContact = new Contact($db);
		$linkedContact->lastname = $prefix.' Linked user contact';
		$linkedContact->firstname = 'Account';
		$linkedContact->email = 'linked-'.$context['token'].'@example.test';
		$linkedContact->socid = $companyId;
		$linkedContact->statut = 1;
		$linkedContactId = (int) $linkedContact->create($testUser);
		liveAssert($linkedContactId > 0, 'Unable to create the native linked-user contact');
		$contacts[] = $linkedContactId;
		$linkedUser = new User($db);
		$linkedUserId = (int) $linkedUser->create_from_contact($linkedContact, 'davbusiness'.$context['token']);
		liveAssert($linkedUserId > 0, 'Unable to create the native account linked to the contact: '.$linkedUser->error);
		$users[] = $linkedUserId;
		$linkedPath = $addressBookPath.rawurlencode($linkedContactId.'-ct-'.$key);
		$linkedCard = $http('GET', $linkedPath);
		liveHttpStatus($linkedCard, array(200), 'BL11 linked-user contact export');
		$userBefore = (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$linkedUserId);
		liveHttpStatus($http('PUT', $linkedPath, $linkedCard['body'], $cardHeaders), array(200, 204), 'BL11 linked-user harmless GET/PUT echo');
		$linkedEdit = \Sabre\VObject\Reader::read($linkedCard['body']);
		$linkedEdit->add('TEL', '+41790000000', array('TYPE' => 'cell'));
		liveHttpStatus($http('PUT', $linkedPath, $linkedEdit->serialize(), $cardHeaders), array(403), 'BL12 linked-user contact update refused');
		liveHttpStatus($http('DELETE', $linkedPath), array(403), 'BL12 linked-user contact deletion refused');
		liveAssert((array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.$linkedUserId) === $userBefore,
			'A CardDAV echo, refused edit or deletion changed a linked native user');
		liveAssert((int) liveRow($db, 'SELECT statut FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$linkedContactId)->statut === 1,
			'CardDAV deactivated a contact that represents a native user');
		$reservedId = 2000000000 + hexdec(substr($context['token'], 0, 4));
		$reservedCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:reserved-business-".$context['token']."\r\nFN:Forbidden reserved identity\r\nEND:VCARD\r\n";
		liveHttpStatus($http('PUT', $addressBookPath.rawurlencode($reservedId.'-ct-'.$key), $reservedCard, $cardHeaders), array(403), 'BL13 reserved native contact URI creation refused');
		liveOk('BL11-BL13 linked native accounts and reserved contact identities cannot be altered through CardDAV');

		$protectionName = 'CDAV_PROTECT_NATIVE_EVENTS';
		$protectionSnapshot = liveSnapshotConstant($db, $protectionName, $entity);
		$linksSql = 'SELECT fk_soc, fk_project, fk_contact, fk_user_action FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId;
		$linksBefore = (array) liveRow($db, $linksSql);
		$noteBefore = (string) liveRow($db, 'SELECT note FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId)->note;
		$nativeReminderBefore = (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.$reminderId);
		liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE fk_actioncomm = '.$eventId)->nb === 1
			&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_reminder WHERE fk_actioncomm = '.$eventId)->nb === 0,
			'BL24 requires one existing native reminder with no DAV-owned provenance');
		try {
			liveAssert(dolibarr_set_const($db, $protectionName, '0', 'chaine', 0, '', $entity) > 0,
				'Unable to disable the native appointment policy explicitly for its configuration test');
			$editable = $http('GET', $eventPath);
			liveHttpStatus($editable, array(200), 'BL24 editable native appointment export');
			$edited = businessLiveCalendar($editable['body'], static function ($component) use ($prefix) {
				$component->SUMMARY = $prefix.' Explicitly editable native appointment';
			});
			liveHttpStatus($http('PUT', $eventPath, $edited, $calendarHeaders), array(200, 204), 'BL24 explicit administrator policy disable');
			liveAssert((string) liveRow($db, 'SELECT label FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId)->label
				=== $prefix.' Explicitly editable native appointment', 'Disabling the policy did not permit ordinary calendar editing');
			liveAssert((array) liveRow($db, $linksSql) === $linksBefore, 'Disabling date-only protection also unlocked native business relationships');
			liveAssert((string) liveRow($db, 'SELECT note FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId)->note === $noteBefore,
				'An ordinary subject edit copied generated calendar sections into native notes or stripped their original rich text');
			liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE fk_actioncomm = '.$eventId)->nb === 1
				&& (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE rowid = '.$reminderId) === $nativeReminderBefore,
				'An ordinary subject edit duplicated or replaced a native reminder, or changed its date, recipient, offset or lifecycle');
			liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'cdav_reminder WHERE fk_actioncomm = '.$eventId)->nb === 0,
				'An ordinary calendar echo adopted a native reminder into DAV-owned provenance');
			$origin = liveRow($db, 'SELECT dav_origin FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$eventId);
			liveAssert($origin !== null && (int) $origin->dav_origin === 0, 'An editable native appointment acquired DAV-created provenance');
		} finally {
			liveAssert(liveRestoreConstant($db, $protectionName, $entity, $protectionSnapshot) > 0,
				'Unable to restore the native appointment protection policy');
		}
		$protectedAgain = $http('GET', $eventPath);
		$forbiddenAgain = businessLiveCalendar($protectedAgain['body'], static function ($component) {
			$component->SUMMARY = 'A native event must be protected again';
		});
		liveHttpStatus($http('PUT', $eventPath, $forbiddenAgain, $calendarHeaders), array(403), 'BL24 policy re-enable immediately protects the same native appointment');
		liveOk('BL24 the administrator switch controls date-only editing without promoting native ownership or changing business links');

		liveAssert($event->fetch($eventId) > 0, 'Unable to reload the shared native appointment');
		$otherId = (int) $context['limited_user_id'];
		$event->userassigned[$otherId] = array('id' => $otherId, 'transparency' => 1);
		liveAssert($event->update($testUser) > 0, 'Unable to share the native appointment through ActionComm');
		liveAssert((bool) $db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET mandatory = 1, transparency = 1,
			answer_status = \'ACCEPTED\' WHERE fk_actioncomm = '.$eventId), 'Unable to prepare shared participant metadata');
		liveHttpStatus($http('DELETE', $eventPath), array(200, 204), 'BL14 shared native appointment detachment');
		$remaining = liveRow($db, 'SELECT id, fk_soc, fk_project FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId);
		liveAssert($remaining !== null && (int) $remaining->fk_soc === $companyId && (int) $remaining->fk_project === (int) $project->id,
			'Removing a shared calendar assignment deleted the native appointment or its links');
		liveAssert(liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$eventId
			.' AND element_type = \'user\' AND fk_element = '.((int) $testUser->id)) === null,
			'The deleted collaborator assignment remained on a shared appointment');
		$remainingResources = $db->query('SELECT mandatory, transparency, answer_status FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$eventId);
		liveAssert((bool) $remainingResources, 'Unable to inspect remaining shared participant metadata');
		while ($resource = $db->fetch_object($remainingResources)) {
			liveAssert((int) $resource->mandatory === 1 && (int) $resource->transparency === 1 && (string) $resource->answer_status === 'ACCEPTED',
				'Shared calendar detachment altered another native participant metadata');
		}
		$lastPath = $context['limited_calendar_path'].rawurlencode($eventId.'-ev-'.$key);
		$lastDelete = liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], 'DELETE', $lastPath);
		liveHttpStatus($lastDelete, array(200, 204), 'BL15 last native appointment deletion');
		liveAssert(liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$eventId) === null,
			'The last native calendar assignment was removed without deleting the appointment');
		liveAssert(liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'projet WHERE rowid = '.((int) $project->id)) !== null
			&& liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$companyId) !== null,
			'Appointment deletion removed its independent native project or customer');
		liveHttpStatus($http('DELETE', $contactPath), array(200, 204), 'BL16 ordinary contact soft deletion');
		$inactive = liveRow($db, 'SELECT fk_soc, statut FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.$contactId);
		liveAssert($inactive !== null && (int) $inactive->fk_soc === $companyId && (int) $inactive->statut === 0,
			'Contact deletion must preserve an inactive row with its original company link');
		liveOk('BL14-BL16 shared appointment removal, last-assignment native deletion and contact soft deletion preserve business objects');

		$memberType = new AdherentType($db);
		$memberType->label = 'DAV business '.$context['token'];
		$memberType->morphy = 'phy';
		$memberType->status = 0;
		$memberType->subscription = 0;
		$memberType->vote = 0;
		liveAssert($memberType->create($admin) > 0, 'Unable to create the native member type fixture: '.$memberType->error);
		$company = (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$companyId);
		$memberBook = liveDavPath(array('addressbooks', $context['login'], 'members'), true);
		$memberUri = $prefix.'-business-member.vcf';
		$memberCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:member-business-".$context['token']
			."\r\nFN:Business Member\r\nN:Member;Business;;MR;\r\nORG:".$company['nom']
			."\r\nEMAIL:member-".$context['token']."@example.test\r\nTEL;TYPE=work:+41225550222\r\nEND:VCARD\r\n";
		$memberPath = $memberBook.rawurlencode($memberUri);
		liveHttpStatus($http('PUT', $memberPath, $memberCard, $cardHeaders), array(201, 204), 'BL17 member creation with free company link');
		$memberMap = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'cdav_cardmap WHERE object_type = \'mb\' AND uuidext = \''.$db->escape($memberUri).'\'');
		liveAssert($memberMap !== null, 'The new member has no protocol mapping');
		$memberId = (int) $memberMap->fk_object;
		$members[] = $memberId;
		$memberState = liveRow($db, 'SELECT fk_soc, fk_adherent_type, statut FROM '.MAIN_DB_PREFIX.'adherent WHERE rowid = '.$memberId);
		liveAssert((int) $memberState->fk_soc === $companyId, 'Initial member company link was not established');
		$civilitySql = 'SELECT civility FROM '.MAIN_DB_PREFIX.'adherent WHERE rowid = '.$memberId;
		liveAssert(liveRow($db, $civilitySql)->civility === 'MR', 'CardDAV member creation did not save the initial civility');
		liveAssert((array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$companyId) === $company,
			'Creating a member overwrote the linked native company');
		$memberGet = $http('GET', $memberPath);
		liveHttpStatus($memberGet, array(200), 'BL17 member read-back');
		$memberEdit = \Sabre\VObject\Reader::read($memberGet['body']);
		unset($memberEdit->ORG);
		unset($memberEdit->TEL);
		$memberEdit->add('TEL', '+41225550333', array('TYPE' => 'work'));
		$memberEdit->N->setParts(array('Member', 'Business', '', 'MME', ''));
		liveHttpStatus($http('PUT', $memberPath, $memberEdit->serialize(), $cardHeaders), array(200, 204), 'BL17 member coordinate update');
		liveAssert(liveRow($db, $civilitySql)->civility === 'MME', 'CardDAV update did not replace the existing member civility');
		$memberEdit->N->setParts(array('Member', 'Business', '', '', ''));
		liveHttpStatus($http('PUT', $memberPath, $memberEdit->serialize(), $cardHeaders), array(200, 204), 'BL17 member civility removal');
		liveAssert(liveRow($db, $civilitySql)->civility === null, 'CardDAV update did not clear the existing member civility');
		liveAssert((array) liveRow($db, 'SELECT fk_soc, fk_adherent_type, statut FROM '.MAIN_DB_PREFIX.'adherent WHERE rowid = '.$memberId) === (array) $memberState,
			'Member coordinate editing changed lifecycle, membership type or company links');
		liveAssert((array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$companyId) === $company,
			'Updating a member overwrote the linked native company');
		$secondCard = str_replace('member-business-'.$context['token'], 'member-second-'.$context['token'], $memberCard);
		liveHttpStatus($http('PUT', $memberBook.rawurlencode($prefix.'-second-member.vcf'), $secondCard, $cardHeaders), array(403),
			'BL18 a second member cannot steal an existing company relationship');
		liveAssert((int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'adherent WHERE fk_soc = '.$companyId)->nb === 1
			&& (int) liveRow($db, 'SELECT fk_soc FROM '.MAIN_DB_PREFIX.'adherent WHERE rowid = '.$memberId)->fk_soc === $companyId,
			'Refusing a second member left the original membership detached or duplicated');
		liveAssert((array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$companyId) === $company,
			'A rejected membership creation altered the company');
		liveHttpStatus($http('DELETE', $memberPath), array(200, 204), 'BL18 member retirement through native CardDAV lifecycle');
		$retiredMember = liveRow($db, 'SELECT fk_soc, fk_adherent_type, statut FROM '.MAIN_DB_PREFIX.'adherent WHERE rowid = '.$memberId);
		liveAssert($retiredMember !== null && (int) $retiredMember->statut === Adherent::STATUS_RESILIATED
			&& (int) $retiredMember->fk_soc === (int) $memberState->fk_soc
			&& (int) $retiredMember->fk_adherent_type === (int) $memberState->fk_adherent_type,
			'CardDAV DELETE physically removed a member or changed its native business links');
		liveHttpStatus($http('GET', $memberPath), array(404), 'BL18 retired member hidden from CardDAV');
		liveAssert((array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.$companyId) === $company,
			'Retiring a member altered its linked native company');
		liveOk('BL17-BL18 member creation, updates and retirement preserve native company data and membership relationships');

		$limitedContactPath = liveDavPath(array('addressbooks', $context['limited_login'], 'default'), true)
			.rawurlencode($linkedContactId.'-ct-'.$key);
		$limitedRead = static function () use ($context, $limitedContactPath) {
			return liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], 'GET', $limitedContactPath);
		};
		liveHttpStatus($limitedRead(), array(200), 'BL19 native contact-only reader public access');
		$limitedAccount = new User($db);
		liveAssert($limitedAccount->fetch((int) $context['limited_user_id']) > 0, 'Unable to reload the restricted native account');
		$right = liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'rights_def WHERE module = \'societe\'
			AND perms = \'lire\' AND (subperms IS NULL OR subperms = \'\') LIMIT 1');
		liveAssert($right !== null, 'The native third-party read permission is unavailable');
		$limitedReadRight = (int) $right->id;
		liveAssert($limitedAccount->addrights($limitedReadRight, '', '', $entity) > 0,
			'Unable to grant the restricted native third-party reader role');
		liveHttpStatus($limitedRead(), array(404), 'BL19 a restricted salesperson cannot read another company contact');
		$participantUri = $prefix.'-hidden-attendee.ics';
		$participantPath = $context['limited_calendar_path'].rawurlencode($participantUri);
		$participantStart = strtotime('+17 days 14:00 UTC');
		$participantEmail = (string) $linkedContact->email;
		$participantBody = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:".$prefix."-hidden-attendee-uid\r\n"
			.'DTSTART:'.gmdate('Ymd\THis\Z', $participantStart)."\r\nDTEND:".gmdate('Ymd\THis\Z', $participantStart + 3600)
			."\r\nSUMMARY:".$prefix." Inaccessible native contact probe\r\nATTENDEE;ROLE=REQ-PARTICIPANT:mailto:".$participantEmail
			."\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$participantCreate = liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'],
			'PUT', $participantPath, $participantBody, $calendarHeaders);
		liveHttpStatus($participantCreate, array(201, 204), 'BL19 known private contact email stays a protocol-only attendee');
		$participantMapping = liveRow($db, 'SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE uuidext = \''.$db->escape($participantUri).'\'');
		liveAssert($participantMapping !== null, 'The isolated attendee probe appointment was not mapped');
		$participantId = (int) $participantMapping->fk_object;
		$events[] = $participantId;
		$participantNative = liveRow($db, 'SELECT fk_contact, fk_soc FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$participantId);
		liveAssert($participantNative !== null && empty($participantNative->fk_contact) && empty($participantNative->fk_soc)
			&& (int) liveRow($db, 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.$participantId
				.' AND element_type = \'socpeople\'')->nb === 0, 'A known email bypassed native contact access and created business relationships');
		$participantGet = liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], 'GET', $participantPath);
		liveHttpStatus($participantGet, array(200), 'BL19 external attendee read-back');
		liveAssert(str_contains(liveUnfold($participantGet['body']), $participantEmail), 'Native contact protection removed an ordinary external attendee');
		$companyObject = new Societe($db);
		liveAssert($companyObject->fetch($companyId) > 0 && $companyObject->add_commercial($admin, (int) $limitedAccount->id) > 0,
			'Unable to assign the native company salesperson');
		$limitedCommercial = true;
		liveHttpStatus($limitedRead(), array(200), 'BL19 a salesperson can read contacts of their assigned native company');
		liveAssert($companyObject->del_commercial($admin, (int) $limitedAccount->id) > 0, 'Unable to restore the native company salesperson list');
		$limitedCommercial = false;
		liveAssert($limitedAccount->delrights($limitedReadRight, '', '', $entity) > 0,
			'Unable to restore the restricted native role');
		$limitedReadRight = 0;
		liveOk('BL19 contact visibility follows native contact-reader and assigned-salesperson permissions');
		require_once __DIR__.'/business_project_acl_cases.php';
		businessLiveProjectAccess($db, $testUser, $admin, $context);
		require_once __DIR__.'/business_delete_rollback_cases.php';
		businessLiveDeleteRollback($db, $testUser, $admin, $context);
	} finally {
		if ($limitedCommercial && $limitedAccount) {
			$companyObject = new Societe($db);
			liveAssert($companyObject->fetch($companyId) > 0 && $companyObject->del_commercial($admin, (int) $limitedAccount->id) > 0,
				'Unable to clean the native salesperson fixture');
		}
		if ($limitedReadRight > 0 && $limitedAccount) {
			liveAssert($limitedAccount->delrights($limitedReadRight, '', '', $entity) > 0,
				'Unable to clean the native salesperson role fixture');
		}
		foreach ($events as $id) {
			$event = new ActionComm($db);
			if ($event->fetch($id) > 0) liveAssert($event->delete($admin) > 0, 'Unable to remove the native business appointment fixture');
		}
		foreach ($users as $id) {
			$user = new User($db);
			if ($user->fetch($id) > 0) liveAssert($user->delete($admin) > 0, 'Unable to remove the native linked-user fixture');
		}
		foreach ($members as $id) {
			$member = new Adherent($db);
			if ($member->fetch($id) > 0) liveAssert($member->delete($admin) > 0, 'Unable to remove the native member fixture');
			foreach (array('cdav_cardmap', 'cdav_vcard') as $table) {
				liveAssert((bool) $db->query('DELETE FROM '.MAIN_DB_PREFIX.$table.' WHERE object_type = \'mb\' AND fk_object = '.$id),
					'Unable to remove technical business-member test metadata');
			}
		}
		if ($memberType && (int) $memberType->id > 0) liveAssert($memberType->delete($admin) > 0, 'Unable to remove the native member type fixture');
		foreach ($contacts as $id) {
			$contact = new Contact($db);
			if ($contact->fetch($id, $admin) > 0) liveAssert($contact->delete($admin) > 0, 'Unable to remove the native business contact fixture');
			foreach (array('cdav_cardmap', 'cdav_vcard') as $table) {
				liveAssert((bool) $db->query('DELETE FROM '.MAIN_DB_PREFIX.$table.' WHERE object_type = \'ct\' AND fk_object = '.$id),
					'Unable to remove technical business-contact test metadata');
			}
		}
		if ($category && (int) $category->id > 0) liveAssert($category->delete($admin) > 0, 'Unable to remove the native business category fixture');
		if ($project && (int) $project->id > 0) liveAssert($project->delete($admin, 1) > 0, 'Unable to remove the native business project fixture');
		foreach ($companies as $id) {
			$companyEvents = $db->query('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE fk_soc = '.$id);
			liveAssert((bool) $companyEvents, 'Unable to inspect native concurrent-company fixture history');
			while ($row = $db->fetch_object($companyEvents)) {
				$history = new ActionComm($db);
				liveAssert($history->fetch((int) $row->id) > 0 && $history->delete($admin) > 0,
					'Unable to remove native concurrent-company fixture history');
			}
			$company = new Societe($db);
			if ($company->fetch($id) > 0) liveAssert($company->delete($id, $admin, 0) > 0, 'Unable to remove the concurrent native company fixture');
		}
	}
}
