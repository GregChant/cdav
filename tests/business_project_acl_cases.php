<?php

// Developer helpers are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Verify native project scope independently from an Agenda assignment.
 * @param DoliDB $db Native database
 * @param User $testUser Fixture creator with explicit project rights
 * @param User $admin Native cleanup administrator
 * @param array $context Existing suite URLs, isolated credentials and entity
 * @return void
 */
function businessLiveProjectAccess($db, $testUser, $admin, array $context): void
{
	require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
	$entity = (int) $context['entity'];
	$prefix = (string) $context['prefix'];
	$limitedId = (int) $context['limited_user_id'];
	$project = null;
	$event = null;
	$task = null;
	$projectContact = 0;
	$grantedRights = array();
	$settingSnapshots = array();
	$limited = new User($db);
	liveAssert($limited->fetch($limitedId) > 0 && $limited->loadRights('', 1) >= 0, 'Unable to load the native project ACL reader');
	liveAssert(!$limited->hasRight('projet', 'lire') && !$limited->hasRight('projet', 'all', 'lire'),
		'BL26 requires an Agenda reader without pre-existing project rights');
	$readRight = liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'rights_def WHERE module = \'projet\'
		AND perms = \'lire\' AND (subperms IS NULL OR subperms = \'\') LIMIT 1');
	$allRight = liveRow($db, 'SELECT id FROM '.MAIN_DB_PREFIX.'rights_def WHERE module = \'projet\'
		AND perms = \'all\' AND subperms = \'lire\' LIMIT 1');
	liveAssert($readRight !== null && $allRight !== null, 'Native project read permissions are unavailable');
	$readId = (int) $readRight->id;
	$allId = (int) $allRight->id;
	$http = static function ($method, $path, $body = null, $headers = array()) use ($context) {
		return liveHttp($context['base_url'], $context['limited_login'], $context['limited_password'], $method, $path, $body, $headers);
	};
	$toggleRight = static function (int $rightId, bool $enabled) use ($limited, $entity, &$grantedRights) {
		$result = $enabled ? $limited->addrights($rightId, '', '', $entity) : $limited->delrights($rightId, '', '', $entity);
		liveAssert($result > 0, 'Unable to change the isolated native project reader role');
		if ($enabled) $grantedRights[$rightId] = true;
		else unset($grantedRights[$rightId]);
		liveAssert($limited->loadRights('', 1) >= 0, 'Unable to refresh the isolated native project role');
	};
	$multiget = static function (array $paths) use ($context, $http) {
		$body = '<?xml version="1.0" encoding="utf-8"?><c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop>';
		foreach ($paths as $path) {
			$href = parse_url($context['base_url'], PHP_URL_PATH).ltrim($path, '/');
			$body .= '<d:href>'.htmlspecialchars($href, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</d:href>';
		}
		return $http('REPORT', $context['limited_calendar_path'], $body.'</c:calendar-multiget>',
			array('Depth: 0', 'Content-Type: application/xml; charset=utf-8'));
	};
	try {
		$role = liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_type_contact WHERE element = \'project\'
			AND source = \'internal\' AND code = \'PROJECTCONTRIBUTOR\' AND active = 1 ORDER BY rowid LIMIT 1');
		$taskRole = liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_type_contact WHERE element = \'project_task\'
			AND source = \'internal\' AND code = \'TASKCONTRIBUTOR\' AND active = 1 ORDER BY rowid LIMIT 1');
		liveAssert($role !== null && $taskRole !== null, 'Native project and task membership roles are unavailable');
		foreach (array('CDAV_TASK_SYNC' => '0', 'CDAV_TASK_USER_ROLE' => (string) $taskRole->rowid) as $name => $value) {
			$settingSnapshots[$name] = liveSnapshotConstant($db, $name, $entity);
			liveAssert(dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $entity) > 0, 'Unable to configure isolated project/task ACL fixtures');
		}
		$secret = 'PRIVATE-PROJECT-NOTE-'.$context['token'];
		$project = new Project($db);
		$project->ref = 'DAVACL-'.$context['token'];
		$project->title = $prefix.' Private ACL project';
		$project->description = '<p>'.$secret.'</p><p>Private project paragraph</p>';
		$project->socid = (int) $context['company_id'];
		$project->public = 0;
		$project->usage_task = 1;
		liveAssert($project->create($testUser) > 0, 'Unable to create the private native project fixture');
		$projectId = (int) $project->id;
		$event = new ActionComm($db);
		$event->type_code = 'AC_RDV';
		$event->label = $prefix.' Project ACL appointment';
		$event->note_private = '<p>Readable <strong>Agenda</strong> notes</p>';
		$event->datep = strtotime('+16 days 10:00 UTC');
		$event->datef = $event->datep + 3600;
		$event->percentage = -1;
		$event->transparency = 1;
		$event->socid = (int) $context['company_id'];
		$event->fk_project = $projectId;
		$event->userownerid = $limitedId;
		$event->userassigned = array($limitedId => array('id' => $limitedId, 'transparency' => 1));
		$taskSecret = 'PRIVATE-TASK-NOTE-'.$context['token'];
		$task = new Task($db);
		$task->ref = 'DAVTASK-'.$context['token'];
		$task->label = $prefix.' Private assigned task';
		$task->description = '<p>'.$taskSecret.'</p>';
		$task->fk_project = $projectId;
		$task->date_start = $event->datep;
		$task->date_end = $event->datef;
		$task->progress = 0;
		$task->planned_workload = 3600;
		liveAssert($task->create($testUser) > 0 && $task->add_contact($limitedId, (int) $taskRole->rowid, 'internal') > 0,
			'Unable to create and assign the native task ACL fixture');
		$event->elementtype = 'project_task';
		$event->elementid = (int) $task->id;
		liveAssert($event->create($testUser) > 0, 'Unable to create the assigned native Agenda ACL fixture');
		$nativeProject = (array) liveRow($db, 'SELECT description, tms FROM '.MAIN_DB_PREFIX.'projet WHERE rowid = '.$projectId);
		$nativeNote = (string) liveRow($db, 'SELECT note FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $event->id))->note;
		$key = getDolGlobalString('CDAV_URI_KEY');
		$eventPath = $context['limited_calendar_path'].rawurlencode($event->id.'-ev-'.$key);
		$view = static function (bool $visible, string $phase) use ($db, $event, $nativeNote, $nativeProject, $project, $projectId, $secret, $eventPath, $http, $multiget, $context, $task) {
			$get = $http('GET', $eventPath);
			liveHttpStatus($get, array(200), 'BL26 assigned Agenda export '.$phase);
			$data = liveUnfold($get['body']);
			liveAssert(str_contains($data, $secret) === $visible, 'Project description access did not follow native rights: '.$phase);
			liveAssert(str_contains($data, $project->ref) && str_contains($data, $project->title),
				'Agenda export lost its native project reference/title: '.$phase);
			$description = (string) \Sabre\VObject\Reader::read($get['body'])->VEVENT->DESCRIPTION;
			foreach (array($task->ref, $task->label, '/projet/tasks/task.php?id='.$task->id) as $taskDetail) {
				liveAssert(str_contains($description, $taskDetail) === $visible, 'Linked Agenda task visibility is incorrect: '.$phase);
			}
			liveAssert(str_contains($description, 'Readable Agenda notes') && !str_contains($description, '<p>') && !str_contains($description, '<strong>'),
				'Project redaction changed readable Agenda notes or exposed native HTML: '.$phase);
			$report = $multiget(array($eventPath));
			liveHttpStatus($report, array(207), 'BL26 project ACL calendar-multiget '.$phase);
			$reportData = liveUnfold(html_entity_decode($report['body'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
			liveAssert(str_contains($reportData, 'HTTP/1.1 200') && str_contains($reportData, $secret) === $visible
				&& str_contains($reportData, $task->ref) === $visible,
				'Calendar-multiget bypassed the native project description ACL: '.$phase);
			$propfind = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"><d:prop><cs:getctag/></d:prop></d:propfind>';
			$collection = $http('PROPFIND', $context['limited_calendar_path'], $propfind, array('Depth: 0', 'Content-Type: application/xml'));
			liveHttpStatus($collection, array(207), 'BL26 project ACL collection tag '.$phase);
			$tag = liveCollectionTag($collection['body']);
			liveAssert($tag !== '' && !empty($get['headers']['etag']), 'Project ACL export lacks its representation or collection tag');
			liveAssert((string) liveRow($db, 'SELECT note FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $event->id))->note === $nativeNote
				&& (array) liveRow($db, 'SELECT description, tms FROM '.MAIN_DB_PREFIX.'projet WHERE rowid = '.$projectId) === $nativeProject,
				'Project visibility checks changed native notes, project data or its modification time: '.$phase);
			return array('etag' => (string) $get['headers']['etag'], 'ctag' => $tag);
		};
		$changeMember = static function (bool $enabled) use ($db, $project, $limitedId, $role, &$projectContact) {
			if ($enabled) {
				liveAssert($project->add_contact($limitedId, (int) $role->rowid, 'internal') > 0, 'Unable to add native project membership');
				$link = liveRow($db, 'SELECT rowid FROM '.MAIN_DB_PREFIX.'element_contact WHERE element_id = '.((int) $project->id)
					.' AND fk_socpeople = '.$limitedId.' AND fk_c_type_contact = '.((int) $role->rowid));
				liveAssert($link !== null, 'Native project membership was not persisted');
				$projectContact = (int) $link->rowid;
			} else {
				liveAssert($projectContact > 0 && $project->delete_contact($projectContact) > 0, 'Unable to revoke native project membership');
				$projectContact = 0;
			}
		};
		$view(false, 'without project rights');
		$toggleRight($readId, true);
		$nonMember = $view(false, 'private project nonmember');
		$changeMember(true);
		$member = $view(true, 'authorized internal member');
		liveAssert($member['etag'] !== $nonMember['etag'] && $member['ctag'] !== $nonMember['ctag'],
			'Granting native project membership did not refresh ETag and ctag without a project timestamp change');
		$changeMember(false);
		$revoked = $view(false, 'membership revoked');
		liveAssert($revoked['etag'] !== $member['etag'] && $revoked['ctag'] !== $member['ctag'],
			'Revoking native project membership did not invalidate the prior visible representation');
		$toggleRight($allId, true);
		$all = $view(true, 'native all-project reader');
		liveAssert($all['etag'] !== $revoked['etag'] && $all['ctag'] !== $revoked['ctag'], 'Granting native all-project access did not refresh calendar visibility');
		$toggleRight($allId, false);
		$hiddenAgain = $view(false, 'all-project access revoked');
		liveAssert($hiddenAgain['etag'] !== $all['etag'] && $hiddenAgain['ctag'] !== $all['ctag'], 'Revoking native all-project access did not refresh calendar visibility');
		$toggleRight($readId, false);
		liveOk('BL26 Agenda project descriptions respect native project rights and membership, including GET/multiget/ETag/ctag after grant and revoke');

		$nativeTaskNote = (string) liveRow($db, 'SELECT description FROM '.MAIN_DB_PREFIX.'projet_task WHERE rowid = '.((int) $task->id))->description;
		liveAssert(dolibarr_set_const($db, 'CDAV_TASK_SYNC', '3', 'chaine', 0, '', $entity) > 0, 'Unable to expose both native task calendar representations');
		$taskPaths = array();
		foreach (array('pe', 'pt') as $source) $taskPaths[] = $context['limited_calendar_path'].rawurlencode($task->id.'-'.$source.'-'.$key);
		$taskView = static function (bool $visible, string $phase) use ($db, $taskPaths, $http, $multiget, $taskSecret, $secret, $task, $nativeTaskNote, $context) {
			foreach ($taskPaths as $path) {
				$get = $http('GET', $path);
				liveHttpStatus($get, $visible ? array(200) : array(404), 'BL27 native task calendar export '.$phase);
				$getData = liveUnfold($get['body']);
				liveAssert(str_contains($getData, $taskSecret) === $visible && str_contains($getData, $task->label) === $visible,
					'Native task GET exposed private metadata or lost authorized task notes');
			}
			$report = $multiget($taskPaths);
			liveHttpStatus($report, array(207), 'BL27 native task calendar-multiget '.$phase);
			$data = liveUnfold(html_entity_decode($report['body'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
			liveAssert(str_contains($data, $taskSecret) === $visible && str_contains($data, $secret) === $visible
				&& str_contains($data, $task->label) === $visible, 'Task calendar-multiget leaked or hid private project/task data: '.$phase);
			foreach (array('VEVENT', 'VTODO') as $component) {
				$query = '<?xml version="1.0" encoding="utf-8"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="'
					.$component.'"/></c:comp-filter></c:filter></c:calendar-query>';
				$listing = $http('REPORT', $context['limited_calendar_path'], $query, array('Depth: 1', 'Content-Type: application/xml'));
				liveHttpStatus($listing, array(207), 'BL27 native task calendar-query '.$phase);
				$listData = liveUnfold(html_entity_decode($listing['body'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
				liveAssert(str_contains($listData, $taskSecret) === $visible && str_contains($listData, $task->label) === $visible,
					'Calendar-query listed an inaccessible native task or hid an authorized one: '.$phase);
			}
			liveAssert((string) liveRow($db, 'SELECT description FROM '.MAIN_DB_PREFIX.'projet_task WHERE rowid = '.((int) $task->id))->description === $nativeTaskNote,
				'Task ACL checks or calendar formatting changed native project-task notes');
		};
		$taskView(false, 'without project rights despite task assignment');
		$toggleRight($readId, true);
		$taskView(false, 'private project nonmember despite task assignment');
		$changeMember(true);
		$taskView(true, 'authorized parent-project member');
		$changeMember(false);
		$taskView(false, 'parent-project membership revoked');
		$toggleRight($allId, true);
		$taskView(true, 'native all-project reader');
		liveOk('BL27 task assignments cannot bypass native project read scope in EVENT/TODO GET or calendar-multiget');

		$taskOwnerHttp = static function ($method, $body = null, $headers = array()) use ($context, $taskPaths) {
			return liveHttp($context['base_url'], $context['login'], $context['password'], $method, $taskPaths[1], $body, $headers);
		};
		$taskViewForOwner = $taskOwnerHttp('GET');
		liveHttpStatus($taskViewForOwner, array(200), 'Project task title read');
		$taskCalendar = Sabre\VObject\Reader::read($taskViewForOwner['body']);
		$newTitle = '[Client] '.$prefix.' Renamed task';
		$taskCalendar->VTODO->SUMMARY = $newTitle;
		$taskHeaders = array('Content-Type: text/calendar; charset=utf-8');
		liveHttpStatus($taskOwnerHttp('PUT', $taskCalendar->serialize(), $taskHeaders), array(200, 204), 'Project task bracketed title write');
		$taskCalendar->destroy();
		$taskViewForOwner = $taskOwnerHttp('GET');
		liveHttpStatus($taskViewForOwner, array(200), 'Project task decorated title read');
		$taskCalendar = Sabre\VObject\Reader::read($taskViewForOwner['body']);
		liveAssert((string) $taskCalendar->VTODO->SUMMARY === '['.$project->title.'] '.$newTitle, 'Task export lost the native prefix or the user-supplied bracketed title');
		$taskCalendar->destroy();
		liveHttpStatus($taskOwnerHttp('PUT', $taskViewForOwner['body'], $taskHeaders), array(200, 204), 'Project task decorated title echo');
		liveAssert((string) liveRow($db, 'SELECT label FROM '.MAIN_DB_PREFIX.'projet_task WHERE rowid = '.((int) $task->id))->label === $newTitle,
			'Project task echo stored the generated prefix in the native title');
		liveOk('Project task title preserves brackets and strips only the generated project prefix');
	} finally {
		foreach (array_keys($grantedRights) as $rightId) {
			liveAssert($limited->delrights((int) $rightId, '', '', $entity) > 0, 'Unable to restore native project reader permissions');
		}
		if ($projectContact > 0 && $project) liveAssert($project->delete_contact($projectContact) > 0, 'Unable to remove native project membership fixture');
		if ($event && (int) $event->id > 0 && $event->fetch((int) $event->id) > 0) liveAssert($event->delete($admin) > 0, 'Unable to remove native Agenda ACL fixture');
		if ($task && (int) $task->id > 0) liveAssert($task->delete($admin) > 0, 'Unable to remove native task ACL fixture');
		if ($project && (int) $project->id > 0) liveAssert($project->delete($admin, 1) > 0, 'Unable to remove private native project ACL fixture');
		foreach ($settingSnapshots as $name => $snapshot) {
			liveAssert(liveRestoreConstant($db, $name, $entity, $snapshot) > 0, 'Unable to restore native task synchronization settings');
		}
	}
}
