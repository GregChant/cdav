<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/**
 * Round-trip a protected Agenda task through its historical Outlook resource URI.
 * @param DoliDB $db Isolated database
 * @param User $owner Native owner
 * @param User $admin Cleanup administrator
 * @param array $context Live suite credentials and calendar paths
 * @param Project $project Linked project
 * @param Task $task Linked project task
 * @return void
 */
function agendaTodoLiveCases($db, $owner, $admin, array $context, $project, $task): void
{
	$todo = new ActionComm($db);
	$todo->type_code = 'AC_OTH';
	$todo->label = $context['prefix'].' Completed Agenda task';
	$todo->datep = strtotime('-31 days 09:00');
	$todo->datef = strtotime('-30 days 16:00');
	$todo->percentage = 100;
	$todo->priority = 5;
	$todo->fk_project = (int) $project->id;
	$todo->socid = (int) $context['company_id'];
	$todo->elementtype = 'project_task';
	$todo->elementid = (int) $task->id;
	$todo->userownerid = (int) $owner->id;
	$todo->userassigned = array($owner->id => array('id' => $owner->id), $context['limited_user_id'] => array('id' => $context['limited_user_id']));
	$id = (int) $todo->create($owner);
	liveAssert($id > 0, 'Unable to create the native completed Agenda task');
	try {
		$uid = 'agenda-todo-'.$context['token'];
		$uri = $uid.'.ics';
		liveAssert((bool) $db->query('INSERT INTO '.MAIN_DB_PREFIX.'actioncomm_cdav (fk_object,uuidext,sourceuid,dav_origin) VALUES ('
			.$id.", '".$db->escape($uri)."', '".$db->escape($uid)."', 0)"), 'Unable to seed a historical calendar resource mapping');
		$http = static function ($method, $body = null, $etag = null, $ownerCalendar = true, $agent = 'CalDavSynchronizer/4.7') use ($context, $uri) {
			$headers = array('Content-Type: text/calendar; charset=utf-8', 'User-Agent: '.$agent);
			if ($etag !== null) $headers[] = 'If-Match: '.$etag;
			$prefix = $ownerCalendar ? '' : 'limited_';
			return liveHttp($context['base_url'], $context[$prefix.'login'], $context[$prefix.'password'], $method,
				$context[$prefix.'calendar_path'].rawurlencode($uri), $body, $headers);
		};
		$businessState = static function () use ($db, $id, $task) {
			$state = businessLiveEventState($db, $id);
			unset($state['event']['label'], $state['event']['percent']);
			// ActionComm::update stores an empty location as NULL on some Dolibarr versions.
			$state['event']['location'] = (string) $state['event']['location'];
			$state['dates'] = (array) liveRow($db, 'SELECT datep,datep2 FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
			$state['mapping'] = (array) liveRow($db, 'SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_cdav WHERE fk_object = '.$id);
			$state['task'] = (array) liveRow($db, 'SELECT label,progress FROM '.MAIN_DB_PREFIX.'projet_task WHERE rowid = '.((int) $task->id));
			return $state;
		};
		$before = $businessState();
		liveAssert((int) $before['event']['fk_project'] === (int) $project->id && $before['event']['elementtype'] === 'project_task'
			&& (int) $before['event']['fk_element'] === (int) $task->id, 'Missing native Agenda task links in fixture');
		$get = $http('GET');
		liveHttpStatus($get, array(200), 'Historical completed Agenda VTODO');
		$calendar = \Sabre\VObject\Reader::read($get['body']);
		$item = $calendar->VTODO;
		liveAssert((string) $item->UID === $uid && (string) $item->STATUS === 'COMPLETED'
			&& (string) $item->{'PERCENT-COMPLETE'} === '100' && (string) $item->PRIORITY === '5' && !$item->select('VALARM')
			&& str_contains((string) $item->DESCRIPTION, (string) $task->ref) && empty($before['event']['note']),
			'Completed Agenda task must expose generated context without a native free note or reminder');
		$completed = gmdate('Ymd\THis\Z', $todo->datef + 3600);
		$item->COMPLETED = $completed;
		foreach (array('URL', 'LOCATION', 'TRANSP') as $name) $item->remove($name);
		$item->DTSTART->setDateTime($item->DTSTART->getDateTime()->setTime(0, 0));
		$item->DUE->setDateTime($item->DUE->getDateTime()->setTime(23, 59, 59));
		liveHttpStatus($http('PUT', $calendar->serialize(), $get['headers']['etag']), array(200, 204), 'Outlook completed task replay with date-only UI and synthesized completion');
		$calendar->destroy();
		$echo = $http('GET');
		liveAssert($echo['headers']['etag'] === $get['headers']['etag'] && $businessState() === $before,
			'Replaying the completed task changed its native business data or ETag');

		$newTitle = $context['prefix'].' Reviewed Agenda task';
		$calendar = \Sabre\VObject\Reader::read($echo['body']);
		$calendar->VTODO->SUMMARY = $newTitle;
		$calendar->VTODO->STATUS = 'IN-PROCESS';
		$calendar->VTODO->{'PERCENT-COMPLETE'} = 40;
		$calendar->VTODO->remove('COMPLETED');
		$edit = $calendar->serialize();
		$calendar->destroy();
		liveHttpStatus($http('PUT', $edit, null, false), array(403), 'A secondary assignee cannot change global task progress');
		liveHttpStatus($http('PUT', $edit, $echo['headers']['etag']), array(200, 204), 'Owner reopens and renames the protected Agenda task');
		$fresh = $http('GET');
		$calendar = \Sabre\VObject\Reader::read($fresh['body']);
		liveAssert((string) $calendar->VTODO->SUMMARY === $newTitle && (string) $calendar->VTODO->STATUS === 'IN-PROCESS'
			&& (int) $calendar->VTODO->{'PERCENT-COMPLETE'}->getValue() === 40 && !$calendar->VTODO->select('COMPLETED')
			&& $fresh['headers']['etag'] !== $echo['headers']['etag'] && $businessState() === $before,
			'Owner edits must reopen the Agenda task without rewriting dates, the linked project task, or its legacy mapping');
		$native = liveRow($db, 'SELECT label,percent FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id);
		liveAssert($native->label === $newTitle && (int) $native->percent === 40, 'Reopened native Agenda values disagree with GET');
		liveHttpStatus($http('PUT', $edit, $echo['headers']['etag']), array(412), 'Stale Agenda task revision');
		foreach (array('UID' => 'other-'.$uid, 'ATTENDEE' => 'mailto:other@example.test') as $field => $value) {
			$invalid = clone $calendar;
			$invalid->VTODO->{$field} = $value;
			liveHttpStatus($http('PUT', $invalid->serialize(), $fresh['headers']['etag']), array(403, 409), 'Protected Agenda task '.$field);
			$invalid->destroy();
		}
		$calendar->VTODO->STATUS = 'COMPLETED';
		$calendar->VTODO->{'PERCENT-COMPLETE'} = 100;
		$calendar->VTODO->COMPLETED = $completed;
		liveHttpStatus($http('PUT', $calendar->serialize(), $fresh['headers']['etag']), array(200, 204), 'Owner completes the renamed Agenda task');
		$calendar->destroy();
		$done = $http('GET');
		$calendar = \Sabre\VObject\Reader::read($done['body']);
		liveAssert((string) $calendar->VTODO->UID === $uid && (string) $calendar->VTODO->STATUS === 'COMPLETED'
			&& (string) $calendar->VTODO->COMPLETED === $completed && (string) $calendar->VTODO->{'PERCENT-COMPLETE'} === '100'
			&& (int) liveRow($db, 'SELECT percent FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.$id)->percent === 100
			&& $businessState() === $before, 'Completing the task lost its completion time, identity or protected relationships');
		$calendar->VTODO->STATUS = 'IN-PROCESS';
		$calendar->VTODO->{'PERCENT-COMPLETE'} = 40;
		$calendar->VTODO->remove('COMPLETED');
		liveHttpStatus($http('PUT', $calendar->serialize(), $done['headers']['etag']), array(200, 204), 'Owner reopens a timestamped task');
		$calendar->destroy();
		$reopened = $http('GET');
		$calendar = \Sabre\VObject\Reader::read($reopened['body']);
		liveAssert(!$calendar->VTODO->select('COMPLETED') && $businessState() === $before, 'Reopening left a stale completion or rewrote protected task data');
		$calendar->VTODO->STATUS = 'COMPLETED';
		$calendar->VTODO->{'PERCENT-COMPLETE'} = 100;
		$calendar->VTODO->COMPLETED = $completed;
		$calendar->VTODO->PRIORITY = 3;
		liveHttpStatus($http('PUT', $calendar->serialize(), $reopened['headers']['etag'], true, 'Standard task client'), array(200, 204), 'RFC client completes the Agenda task');
		$calendar->destroy();
		liveAssert($todo->fetch($id) > 0, 'Unable to reload the Agenda task for a native reopen');
		$todo->percentage = 35;
		liveAssert($todo->update($owner) > 0, 'Unable to reopen the completed task in Dolibarr');
		$todo->percentage = 100;
		liveAssert($todo->update($owner) > 0, 'Unable to complete the reopened task natively');
		$nativeDone = $http('GET');
		$calendar = \Sabre\VObject\Reader::read($nativeDone['body']);
		$item = $calendar->VTODO;
		liveAssert((string) $item->STATUS === 'COMPLETED' && !$item->select('COMPLETED') && (string) $item->PRIORITY === '3',
			'Completing again in Dolibarr must not resurrect the previous DAV completion date');
		foreach (array('URL', 'LOCATION', 'TRANSP') as $name) $item->remove($name);
		$item->PRIORITY = 1;
		$item->DTSTART->setDateTime($item->DTSTART->getDateTime()->setTime(0, 0));
		$item->DUE->setDateTime($item->DUE->getDateTime()->setTime(23, 59, 59));
		$sparse = $calendar->serialize();
		$calendar->destroy();
		liveHttpStatus($http('PUT', $sparse, $nativeDone['headers']['etag'], true, 'Standard task client'), array(200, 204), 'RFC client writes exact day-end and priority without an Agenda URL');
		$literal = $http('GET');
		$calendar = \Sabre\VObject\Reader::read($literal['body']);
		$item = $calendar->VTODO;
		liveAssert((string) $item->PRIORITY === '1' && $item->DTSTART->getDateTime()->format('His') === '000000'
			&& $item->DUE->getDateTime()->format('His') === '235959' && (string) $item->UID === $uid
			&& str_contains((string) $item->DESCRIPTION, (string) $task->ref) && (string) $item->{'PERCENT-COMPLETE'} === '100',
			'RFC task values, progress or project-task relationship changed on export');
		$calendar->destroy();
		foreach (array('CalDavSynchronizer/4.7', 'Standard task client') as $agent) {
			liveHttpStatus($http('PUT', $sparse, $literal['headers']['etag'], true, $agent), array(200, 204), 'Subsequent task echo with '.$agent);
			liveAssert($http('GET')['headers']['etag'] === $literal['headers']['etag'], 'Alternating task clients changed an untouched Agenda task');
		}
		liveOk('Historical protected Agenda VTODO supports Outlook completion replay, owner edits and reopening without changing project-task links');
	} finally {
		liveAssert($todo->fetch($id) > 0 && $todo->delete($admin) > 0, 'Unable to clean up the Agenda task and its historical mapping');
	}
}
