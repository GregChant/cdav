<?php

/******************************************************************
 * cdav is a Dolibarr module
 * It allows caldav and carddav clients to sync with Dolibarr
 * calendars and contacts.
 *
 * cdav is distributed under GNU/GPLv3 license
 * (see COPYING file)
 *
 * cdav uses Sabre/dav library http://sabre.io/dav/
 * Sabre/dav is distributed under use the three-clause BSD-license
 *
 * Author : Befox SARL http://www.befox.fr/
 * Author : Evert Pot (http://evertpot.com/)
 * copyright Copyright (C) 2007-2015 fruux GmbH (https://fruux.com/)
 * copyright Copyright (C) 2015 Befox SARL http://www.befox.fr/
 *
 ******************************************************************/

namespace Sabre\CalDAV\Backend;

use Sabre\VObject;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

class Dolibarr extends AbstractBackend implements SyncSupport {

	/**
	 * We need to specify a max date, because we need to stop *somewhere*
	 *
	 * On 32 bit system the maximum for a signed integer is 2147483647, so
	 * MAX_DATE cannot be higher than date('Y-m-d', 2147483647) which results
	 * in 2038-01-19 to avoid problems when the date is converted
	 * to a unix timestamp.
	 */
	const MAX_DATE = '2038-01-01';

	/**
	 * Dolibarr user object
	 *
	 * @var string
	 */
	public $user;

	/**
	 * DB connection
	 *
	 * @var db
	 */
	protected $db;

	/**
	 * Lang translation
	 *
	 * @var langs
	 */
	protected $langs;

	/**
	 * Library Class for reading Dolibarr events
	 *
	 * @var cdavLib
	 * */
	private $cdavLib;

	/** @var bool|null Whether the optional metadata table is installed. */
	private $hasSchedulingTable = null;

	/** @var bool|null Whether the RFC 6638 inbox journal is installed. */
	private $hasScheduleObjectTable = null;

	/** @var bool|null Whether CalDAV-owned native document links can be tracked. */
	private $hasAttachmentMappingTable = null;

	/** @var bool|null Whether CalDAV-owned native reminders can be tracked. */
	private $hasReminderMappingTable = null;

	/** @var bool|null Whether the native recurrence projection can be tracked. */
	private $hasRecurrenceMappingTable = null;

	/** @var array<int,bool> Valid internal calendar users, cached per request. */
	private $calendarUserCache = array();

	/** @var \Dolibarr\CDav\SyncStore */
	protected $syncStore;

	/** @var \Dolibarr\CDav\ManagedAttachmentStore */
	private $managedAttachmentStore;

	/** @var array<string,string> Conditional ETags consumed inside the native lock. */
	private $expectedCalendarEtags = array();

	/**
	 * List of CalDAV properties, and how they map to database fieldnames
	 * Add your own properties by simply adding on to this array.
	 *
	 * Note that only string-based properties are supported here.
	 *
	 * @var array
	 */
	public $propertyMap = [
		'{DAV:}displayname'                                   => 'displayname',
		'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
		'{urn:ietf:params:xml:ns:caldav}calendar-timezone'    => 'timezone',
		'{http://apple.com/ns/ical/}calendar-order'           => 'calendarorder',
		'{http://apple.com/ns/ical/}calendar-color'           => 'calendarcolor',
	];

	/**
	 * Creates the backend
	 *
	 * @param user
	 * @param db
	 * @param langs
	 */
	function __construct($user,$db,$langs, $cdavLib) {
		global $conf;

		$this->user = $user;
		$this->db = $db;
		$this->langs = $langs;
		$this->cdavLib = $cdavLib;
		$this->syncStore = new \Dolibarr\CDav\SyncStore($db, (int) ($conf->entity ?? 1));
		$this->managedAttachmentStore = new \Dolibarr\CDav\ManagedAttachmentStore($db, $user);
		$this->langs->load("users");
		$this->langs->load("companies");
		$this->langs->load("agenda");
		$this->langs->load("commercial");

	}

	/**
	 * Returns a list of calendars for a principal.
	 *
	 * Every project is an array with the following keys:
	 *  * id, a unique id that will be used by other functions to modify the
	 *    calendar. This can be the same as the uri or a database key.
	 *  * uri. This is just the 'base uri' or 'filename' of the calendar.
	 *  * principaluri. The owner of the calendar. Almost always the same as
	 *    principalUri passed to this method.
	 *
	 * Furthermore it can contain webdav properties in clark notation. A very
	 * common one is '{DAV:}displayname'.
	 *
	 * Many clients also require:
	 * {urn:ietf:params:xml:ns:caldav}supported-calendar-component-set
	 * For this property, you can just return an instance of
	 * Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet.
	 *
	 * If you return {http://sabredav.org/ns}read-only and set the value to 1,
	 * ACL will automatically be put in read-only mode.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getCalendarsForUser($principalUri) {

		global $conf;

		debug_log("getCalendarsForUser( $principalUri )");

		$calendars = [];

		if (!$this->_hasAgendaRight('myactions', 'read')
			|| !preg_match('#^principals/([^/]{1,128})$#', trim((string) $principalUri, '/'), $principalMatch))
			return $calendars;

		$components = [ 'VTODO', 'VEVENT' ];

		$sql = 'SELECT u.rowid, u.login, u.firstname, u.lastname, u.color
				FROM '.MAIN_DB_PREFIX.'user u
				WHERE u.statut > 0
				AND u.fk_soc IS NULL
				AND u.entity IN ('.getEntity('user').')
				AND u.login = \''.$this->db->escape($principalMatch[1]).'\'';

		$result = $this->db->query($sql);
		while($row = $this->db->fetch_array($result))
		{
			if ((int) $row['rowid'] !== (int) $this->user->id && !$this->_hasAgendaRight('allactions', 'read')) {
				continue;
			}
			$collectionTag = $this->cdavLib->getCalendarCollectionTag((int) $row['rowid']);

			$calendar = [
				'id'                                                                 => $row['rowid'],
				'uri'                                                                => $row['rowid'].'-cal-'.$row['login'],
				'principaluri'                                                       => 'principals/'.$row['login'],
				'{' . CalDAV\Plugin::NS_CALENDARSERVER . '}getctag'                  => $collectionTag,
				'{' . CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
				'{' . CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp'         => new CalDAV\Xml\Property\ScheduleCalendarTransp('opaque'),
				'{DAV:}displayname'                                                  => $row['login'],
				'{urn:ietf:params:xml:ns:caldav}calendar-description'                => trim($row['firstname'].' '.$row['lastname']),
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone'                   => date_default_timezone_get(),
				'{http://apple.com/ns/ical/}calendar-order'                          => $row['rowid']==$this->user->id?0:$row['rowid'],
				'{http://apple.com/ns/ical/}calendar-color'                          => ($row['color']=='')?'':('#'.$row['color']),

			];
			$objects = array();
			foreach ($this->getCalendarObjects((int) $row['rowid']) as $object) {
				$objects[(string) $object['uri']] = (string) ($object['etag'] ?? '');
			}
			$syncToken = $this->syncStore->synchronize('cal', (int) $row['rowid'], $objects);
			if ($syncToken !== null) {
				$calendar['{DAV:}sync-token'] = $syncToken;
			}
			$calendars[] = $calendar;
		}

		return $calendars;
	}

	/**
	 * Creates a new calendar for a principal.
	 *
	 * If the creation was a success, an id must be returned that can be used
	 * to reference this calendar in other methods, such as updateCalendar.
	 *
	 * @param string $principalUri
	 * @param string $calendarUri
	 * @param array $properties
	 * @return string
	 */
	function createCalendar($principalUri, $calendarUri, array $properties) {

		debug_log("createCalendar( $principalUri )");

		throw new Forbidden('Creating calendars is not supported');
	}

	/**
	 * Updates properties for a calendar.
	 *
	 * The list of mutations is stored in a Sabre\DAV\PropPatch object.
	 * To do the actual updates, you must tell this object which properties
	 * you're going to process with the handle() method.
	 *
	 * Calling the handle method is like telling the PropPatch object "I
	 * promise I can handle updating this property".
	 *
	 * Read the PropPatch documenation for more info and examples.
	 *
	 * @param string $calendarId
	 * @param \Sabre\DAV\PropPatch $propPatch
	 * @return void
	 */
	function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) {

		debug_log("updateCalendar( $calendarId )");



		// not supported
		return;
	}

	/**
	 * Delete a calendar and all it's objects
	 *
	 * @param string $calendarId
	 * @return void
	 */
	function deleteCalendar($calendarId) {

		debug_log("deleteCalendar( $calendarId )");

		throw new Forbidden('Deleting calendars is not supported');
	}

	/**
	 * Returns all calendar objects within a calendar.
	 *
	 * Every item contains an array with the following keys:
	 *   * calendardata - The iCalendar-compatible calendar data
	 *   * uri - a unique key which will be used to construct the uri. This can
	 *     be any arbitrary string, but making sure it ends with '.ics' is a
	 *     good idea. This is only the basename, or filename, not the full
	 *     path.
	 *   * lastmodified - a timestamp of the last modification time
	 *   * etag - An arbitrary string, surrounded by double-quotes. (e.g.:
	 *   '  "abcdef"')
	 *   * size - The size of the calendar objects, in bytes.
	 *   * component - optional, a string containing the type of object, such
	 *     as 'vevent' or 'vtodo'. If specified, this will be used to populate
	 *     the Content-Type header.
	 *
	 * Note that the etag is optional, but it's highly encouraged to return for
	 * speed reasons.
	 *
	 * The calendardata is also optional. If it's not returned
	 * 'getCalendarObject' will be called later, which *is* expected to return
	 * calendardata.
	 *
	 * If neither etag or size are specified, the calendardata will be
	 * used/fetched to determine these numbers. If both are specified the
	 * amount of times this is needed is reduced by a great degree.
	 *
	 * @param string $calendarId
	 * @return array
	 */
	function getCalendarObjects($calendarId) {

		debug_log("getCalendarObjects( $calendarId )");

		if (!$this->_calendarUserExists($calendarId)) {
			return array();
		}
		return $this->cdavLib->getFullCalendarObjects($calendarId, false);
	}

	/**
	 * Returns information from a single calendar object, based on it's object
	 * uri.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * The returned array must have the same keys as getCalendarObjects. The
	 * 'calendardata' object is required here though, while it's not required
	 * for getCalendarObjects.
	 *
	 * This method must return null if the object did not exist.
	 *
	 * @param string $calendarId
	 * @param string $objectUri
	 * @return array|null
	 */
	function getCalendarObject($calendarId, $objectUri) {

		debug_log("getCalendarObject( $calendarId , $objectUri )");

		$calid = intval($calendarId);
		$elem_source = 'ev';
		$internalObject = $this->_parseInternalObjectUri($objectUri);
		if ($internalObject !== null) {
			$elem_source = $internalObject['source'];
			$oid = (int) $internalObject['id'];
		} else {
			$oid = 0;
		}

		$calevent = null ;

		if (!$this->_hasAgendaRight('myactions', 'read'))
			return $calevent;

		if ($calid != $this->user->id && !$this->_hasAgendaRight('allactions', 'read'))
			return $calevent;
		if (!$this->_calendarUserExists($calid))
			return $calevent;

		if($elem_source=='ev') // Calendar Events
			$sql = $this->cdavLib->getSqlCalEvents($calid, $oid, $objectUri);
		elseif($elem_source=='fi') // Intervention card
			$sql = $this->cdavLib->getSqlIntervEvents($calid, $oid);
		else // Project Tasks
			$sql = $this->cdavLib->getSqlProjectTasks($calid, $oid, $elem_source);

		if($sql===false || $sql=='')
			return $calevent;

		$result = $this->db->query($sql);

		if ($result)
		{
			if ($obj = $this->db->fetch_object($result))
			{
				$calendardata = $this->cdavLib->toVCalendar($calid, $obj, true);

				$calevent = [
					'id' => $obj->id,
					'uri' => $this->cdavLib->getCalendarObjectUri($obj, $elem_source),
					'lastmodified' => strtotime($obj->lastupd),
					'etag' => '"'.md5($calendardata).'"',
					'calendarid'   => $calendarId,
					'size' => strlen($calendardata),
					'calendardata' => $calendardata,
					'component' => strpos($calendardata, 'BEGIN:VEVENT') !== false ? 'vevent' : 'vtodo',
				];
			}
		}

		debug_log($calevent === null
			? 'getCalendarObject: not found'
			: 'getCalendarObject: found id '.((int) $calevent['id']));

		return $calevent;
	}

	/**
	 * Returns a list of calendar objects.
	 *
	 * This method should work identical to getCalendarObject, but instead
	 * return all the calendar objects in the list as an array.
	 *
	 * If the backend supports this, it may allow for some speed-ups.
	 *
	 * @param mixed $calendarId
	 * @param array $uris
	 * @return array
	 */
	function getMultipleCalendarObjects($calendarId, array $uris) {

		debug_log("getMultipleCalendarObjects( $calendarId , ".count($uris)." uris )");

		$calevents = [];

		foreach($uris as $uri)
		{
			$calevent = $this->getCalendarObject($calendarId, $uri);
			if($calevent != null)
				$calevents[] = $calevent;
		}

		return $calevents;
	}


	/**
	 * Creates a new calendar object.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * It is possible return an etag from this function, which will be used in
	 * the response to this PUT request. Note that the ETag must be surrounded
	 * by double-quotes.
	 *
	 * However, you should only really return this ETag if you don't mangle the
	 * calendar-data. If the result of a subsequent GET to this object is not
	 * the exact same as this request body, you should omit the ETag.
	 *
	 * @param mixed $calendarId
	 * @param string $objectUri
	 * @param string $calendarData
	 * @return string|null
	 */
	function createCalendarObject($calendarId, $objectUri, $calendarData) {

		debug_log("createCalendarObject( $calendarId , $objectUri )");
		$this->_validateCalendarObjectUri($objectUri);

		// Check write rights, not merely the right to list/read the calendar.
		if (!$this->_canWriteCalendar($calendarId))
		{
			throw new Forbidden('Not allowed to create objects in this calendar');
		}
		$origCalendarData = $calendarData;
		$calendarData = $this->_parseData($calendarData, $calendarId);
		if (getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS') && $this->_containsManagedAttachment($origCalendarData)) {
			throw new \Sabre\DAV\Exception\Conflict('Managed attachments must first be created through RFC 8607 POST');
		}

		if (! $calendarData || empty($calendarData))
		{
			return;
		}
		$internalObject = $this->_parseInternalObjectUri($objectUri);

		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CalDAV transaction');
		}
		try {
			$this->_lockCalendarOwner((int) $calendarId);
			// One occurrence is stored. Recurrence rules remain attached to that DAV object.
			foreach($calendarData['occurences'] as $iOccur => $occurence)
			{
			$oid = false;
			$elem_source = 'ev';
			// check if it is existing event (if caldav client move it from a calendar to an other)
			// objectUri Dolibarr sinon utilisation de $objectUri en tant que ref externe
			if ($internalObject !== null && $internalObject['source'] === 'ev')
			{
				$oid = (int) $internalObject['id'];
				$sql = "SELECT id FROM ".MAIN_DB_PREFIX."actioncomm WHERE id = ".$oid." AND entity IN (".getEntity('agenda').")";
				$result = $this->db->query($sql);
				if ($result===false || !$this->db->fetch_object($result)) {
					throw new \Sabre\DAV\Exception\NotFound('Dolibarr event not found');
				}
			}
			elseif ($internalObject !== null && in_array($internalObject['source'], array('pe', 'pt'), true))
			{
				$oid = (int) $internalObject['id'];
				$elem_source = $internalObject['source'];
				$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."projet_task WHERE rowid = ".$oid." AND entity IN (".getEntity('project').")";
				$result = $this->db->query($sql);
				if ($result===false || !$this->db->fetch_object($result)) {
					throw new \Sabre\DAV\Exception\NotFound('Dolibarr project task not found');
				}
			}
			elseif ($internalObject !== null && $internalObject['source'] === 'fi')
			{
				$oid = (int) $internalObject['id'];
				$elem_source = 'fi';
				$sql = "SELECT fk_fichinter FROM ".MAIN_DB_PREFIX."fichinterdet WHERE rowid = ".$oid;
				$result = $this->db->query($sql);
				if ($result===false || !($row = $this->db->fetch_object($result)))
				{
					throw new \Sabre\DAV\Exception\NotFound('Dolibarr intervention line not found');
				}
				$fi_oid = intval($row->fk_fichinter);
			}
			else
			{
					$sql = "SELECT ac.fk_object
							FROM ".MAIN_DB_PREFIX."actioncomm_cdav ac
							INNER JOIN ".MAIN_DB_PREFIX."actioncomm a ON a.id = ac.fk_object
							WHERE ac.uuidext = '".$this->db->escape($objectUri)."'
							AND a.entity IN (".getEntity('agenda').")
							ORDER BY ac.fk_object LIMIT 1";
				$result = $this->db->query($sql);
				if ($result!==false && ($row=$this->db->fetch_object($result)))
					$oid = intval($row->fk_object??0);
			}
				if ($elem_source === 'ev') {
					$this->_assertCalendarUidAvailable((int) $calendarId, (string) $calendarData['uid'], (int) $oid, (string) $objectUri);
				}
				if ($oid && !$this->_canAttachExistingObject($calendarId, $objectUri, $elem_source, $oid)) {
				throw new Forbidden('Not allowed to attach this Dolibarr object to the calendar');
			}
			if (!$this->_canModifySource($elem_source)) {
				throw new Forbidden('Not allowed to modify this type of Dolibarr object');
			}

			if(!$oid)	// new event
			{
				debug_log("    creating event");
				$actionType = $this->_getActionType($calendarData['componentType']);
				require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
				$event = new \ActionComm($this->db);
				$this->_applyActionCommData($event, $calendarData, $occurence->start, $occurence->end, $calendarId, $actionType);
				list($userAssignments, $contactAssignments, $contactCompanies) = $this->_resolveParticipantResources(
					$calendarData['participants'],
					(int) $calendarId,
					$this->_hasAgendaRight('allactions', 'write')
				);
				foreach ($userAssignments as &$assignment) {
					$assignment['transparency'] = (int) $calendarData['transparency'];
				}
				unset($assignment);
				$userAssignments[(int) $calendarId]['answer_status'] = (string) $calendarData['answer_status'];
				$event->userassigned = $userAssignments;
				$event->socpeopleassigned = $contactAssignments;
				if (count($contactAssignments) === 1) {
					$contactIds = array_keys($contactAssignments);
					$event->contact_id = (int) $contactIds[0];
					$event->socid = (int) $contactCompanies[$event->contact_id];
				}
				$oid = $event->create($this->user);
					if ($oid <= 0) {
						throw new \Sabre\DAV\Exception('Unable to create the Dolibarr event: '.$event->error);
					}
					$this->_syncNativeRecurrence((int) $oid, $calendarData);
					$this->_syncNativeReminders((int) $oid, (int) $calendarId, $calendarData['native_reminders'] ?? array());
				// Dolibarr 23 casts RFC PARTSTAT strings to integers in ActionComm.
				$this->_restoreActionCommResourceMetadata((int) $oid, $userAssignments);
				debug_log("    event $oid created through ActionComm");
			}
			elseif ($elem_source === 'ev')
			{
				$this->_updateActionCommFromCalendarData((int) $oid, $calendarData, (int) $calendarId);
			}

			if($elem_source == 'ev')
			{
				$storedObjectUri = $iOccur === 0 ? $objectUri : $oid.'-ev-'.CDAV_URI_KEY;
				$this->_upsertCalendarMapping((int) $oid, $storedObjectUri, (string) $calendarData['uid']);
				debug_log("    add user $calendarId to event $oid");
				$this->_upsertEventResource(
					(int) $oid,
					(int) $calendarId,
					(int) $calendarData['transparency'],
					(string) $calendarData['answer_status']
				);
				$this->_storeCalendarMetadata((int) $oid, $origCalendarData);
				$this->_syncActionCommAttachmentLinks((int) $oid, $calendarData['attachment_links']);
			}
			elseif($elem_source == 'fi')
			{
				if ((int) CDAV_INTERV_USER_ROLE <= 0) {
					throw new \Sabre\DAV\Exception('The intervention user role is not configured');
				}
				debug_log("    add user $calendarId to fichinter $fi_oid (via fichinterdet $oid)");
				require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
				$intervention = new \Fichinter($this->db);
				if ($intervention->fetch((int) $fi_oid) <= 0
					|| $intervention->add_contact((int) $calendarId, (int) CDAV_INTERV_USER_ROLE, 'internal') < 0) {
					throw new \Sabre\DAV\Exception('Unable to assign the intervention through Dolibarr: '.$intervention->error);
				}
			}
			else
			{
				if ((int) CDAV_TASK_USER_ROLE <= 0) {
					throw new \Sabre\DAV\Exception('The project task user role is not configured');
				}
				debug_log("    add user $calendarId to project task $oid");
				require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
				$task = new \Task($this->db);
				if ($task->fetch((int) $oid) <= 0
					|| $task->add_contact((int) $calendarId, (int) CDAV_TASK_USER_ROLE, 'internal') < 0) {
					throw new \Sabre\DAV\Exception('Unable to assign the project task through Dolibarr: '.$task->error);
				}
			}
			} // loop occurrences
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CalDAV creation');
			}
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}

		return;
	}

	/**
	 * Updates an existing calendarobject, based on it's uri.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * It is possible return an etag from this function, which will be used in
	 * the response to this PUT request. Note that the ETag must be surrounded
	 * by double-quotes.
	 *
	 * However, you should only really return this ETag if you don't mangle the
	 * calendar-data. If the result of a subsequent GET to this object is not
	 * the exact same as this request body, you should omit the ETag.
	 *
	 * @param mixed $calendarId
	 * @param string $objectUri
	 * @param string $calendarData
	 * @return string|null
	 */
	function updateCalendarObject($calendarId, $objectUri, $calendarData) {

		debug_log("updateCalendarObject( $calendarId , $objectUri )");
		$this->_validateCalendarObjectUri($objectUri);

		// Check write rights, not merely the right to list/read the calendar.
		if (!$this->_canWriteCalendar($calendarId))
		{
            debug_log('User '.$this->user->id.' not authorized to update calendar '.$calendarId);
			throw new Forbidden('Not allowed to update objects in this calendar');
		}

		$origCalendarData = $calendarData;
		$calendarData = $this->_parseData($calendarData, $calendarId);

		if (! $calendarData || empty($calendarData))
		{
			return;
		}
		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CalDAV transaction');
		}
		try {
		$this->_lockCalendarOwner((int) $calendarId);
		$resolvedObject = $this->_resolveCalendarObject($calendarId, $objectUri);
		if ($resolvedObject === null) {
			throw new \Sabre\DAV\Exception\NotFound('Calendar object not found');
		}
		$calendarData['id'] = $resolvedObject['id'];
		$calendarData['elem_source'] = $resolvedObject['source'];
		if ($calendarData['elem_source'] === 'ev') {
			$this->_assertCalendarUidAvailable(
				(int) $calendarId,
				(string) $calendarData['uid'],
				(int) $calendarData['id'],
				(string) $objectUri
			);
		}
		$this->_assertCalendarVersion((int) $calendarId, (string) $objectUri);
		if (!$this->_canModifySource($calendarData['elem_source'])) {
			throw new Forbidden('Not allowed to modify this type of Dolibarr object');
		}
		if ($calendarData['elem_source'] === 'ev' && getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
			$this->managedAttachmentStore->synchronizeCalendarData((int) $calendarData['id'], $origCalendarData, false);
		}

		if($calendarData['elem_source']=='ev')
		{
			$this->_updateActionCommFromCalendarData((int) $calendarData['id'], $calendarData, (int) $calendarId);
		}
		elseif($calendarData['elem_source']=='pe')	// event
		{
			require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
			$task = new \Task($this->db);
			if ($task->fetch((int) $calendarData['id']) <= 0
				|| !in_array((int) $task->entity, array_map('intval', explode(',', getEntity('project'))), true)) {
				throw new \Sabre\DAV\Exception\NotFound('Dolibarr project task not found');
			}
			$task->label = (string) $calendarData['label'];
			$task->date_start = (int) $calendarData['start'];
			$task->date_end = !empty($calendarData['fullday'])
				? max($task->date_start, (int) $calendarData['end'] - 1)
				: max($task->date_start, (int) $calendarData['end']);
			$task->priority = (int) $calendarData['priority'];
			$task->description = (string) $calendarData['note'];
			if ($task->update($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to update the Dolibarr project task: '.$task->error);
			}
		}
		elseif($calendarData['elem_source']=='pt') // todo
		{
			require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
			$task = new \Task($this->db);
			if ($task->fetch((int) $calendarData['id']) <= 0
				|| !in_array((int) $task->entity, array_map('intval', explode(',', getEntity('project'))), true)) {
				throw new \Sabre\DAV\Exception\NotFound('Dolibarr project task not found');
			}
			$task->label = (string) $calendarData['label'];
			$task->date_start = (int) $calendarData['start'];
			$task->date_end = !empty($calendarData['fullday'])
				? max($task->date_start, (int) $calendarData['end'] - 1)
				: max($task->date_start, (int) $calendarData['end']);
			$task->priority = (int) $calendarData['priority'];
			$task->description = (string) $calendarData['note'];
			$task->progress = (int) $calendarData['percent'];
			if ($task->update($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to update the Dolibarr project task: '.$task->error);
			}
		}
		elseif($calendarData['elem_source']=='fi') // fichinter line (fichinterdet)
		{
			require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinterligne.class.php';
			$line = new \FichinterLigne($this->db);
			if ($line->fetch((int) $calendarData['id']) <= 0) {
				throw new \Sabre\DAV\Exception\NotFound('Dolibarr intervention line not found');
			}
			$line->date = (int) $calendarData['start'];
			$line->duration = max(0, (int) $calendarData['end'] - (int) $calendarData['start']);
			$line->desc = (string) $calendarData['note'];
			if ($line->update($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to update the Dolibarr intervention line: '.$line->error);
			}
		}
		else
		{
			throw new \Sabre\DAV\Exception\NotFound('Unsupported Dolibarr calendar object');
		}

		if ($calendarData['elem_source'] === 'ev') {
			$this->_upsertEventResource(
				(int) $calendarData['id'],
				(int) $calendarId,
				(int) $calendarData['transparency'],
				(string) $calendarData['answer_status']
			);
			$this->_upsertCalendarMapping(
				(int) $calendarData['id'],
				(string) $objectUri,
				(string) $calendarData['uid']
			);
			$this->_storeCalendarMetadata((int) $calendarData['id'], $origCalendarData);
			if (getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
				$this->managedAttachmentStore->synchronizeCalendarData((int) $calendarData['id'], $origCalendarData, true);
			}
			$this->_syncActionCommAttachmentLinks((int) $calendarData['id'], $calendarData['attachment_links']);
			$this->_removeAdditionalSeriesObjects(
				(int) $calendarId,
				(string) $calendarData['uid'],
				(int) $calendarData['id']
			);
		}
		if (!$this->db->commit()) {
			throw new \Sabre\DAV\Exception('Unable to commit the CalDAV update');
		}
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}

		return;
	}

	/**
	 * Parses some information from calendar objects, used for optimized
	 * calendar-queries.
	 *
	 * Returns an array with the following keys:
	 *   * etag - An md5 checksum of the object without the quotes.
	 *   * size - Size of the object in bytes
	 *   * componentType - VEVENT, VTODO or VJOURNAL
	 *   * firstOccurence
	 *   * lastOccurence
	 *   * uid - value of the UID property
	 *
	 * @param string $calendarData
	 * @return array
	 */
	private function _readCalendarData($calendarData)
	{
		$configuredMax = (function_exists('getDolGlobalInt')
			? min(max(1, \getDolGlobalInt('CDAV_MAX_REQUEST_MB', 16)), 64)
			: 16) * 1024 * 1024;
		if (strlen((string) $calendarData) > min($configuredMax, 16777215)) {
			throw new \Sabre\DAV\Exception\BadRequest('The calendar object is too large');
		}
		try {
			$vObject = VObject\Reader::read((string) $calendarData);
			if ($vObject->name !== 'VCALENDAR') {
				throw new \InvalidArgumentException('The payload is not an iCalendar object');
			}
			$vObject->validate(VObject\Node::REPAIR | VObject\Node::PROFILE_CALDAV);
			return $vObject;
		} catch (\Sabre\DAV\Exception\BadRequest $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new \Sabre\DAV\Exception\BadRequest('Invalid iCalendar data: '.$e->getMessage());
		}
	}

	protected function getDenormalizedData($calendarData) {

		debug_log("getDenormalizedData( ... )");

		$vObject = $this->_readCalendarData($calendarData);
		$componentType = null;
		$component = null;
		$firstOccurence = null;
		$lastOccurence = null;
		$uid = null;
		foreach ($vObject->getComponents() as $component) {
			if (in_array($component->name, array('VEVENT', 'VTODO'), true)) {
				$componentType = $component->name;
				$uid = isset($component->UID) ? trim((string) $component->UID) : '';
				break;
			}
		}
		if (!$componentType) {
			throw new \Sabre\DAV\Exception\BadRequest('Only VEVENT and VTODO calendar objects are supported');
		}
		if ($uid === '' || strlen($uid) > 255) {
			throw new \Sabre\DAV\Exception\BadRequest('The calendar UID is missing or too long');
		}
		if ($componentType === 'VEVENT') {
			if (!isset($component->DTSTART)) {
				throw new \Sabre\DAV\Exception\BadRequest('VEVENT objects must have a DTSTART property');
			}
			$firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
			// Finding the last occurence is a bit harder
			if (!isset($component->RRULE)) {
				if (isset($component->DTEND)) {
					$lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
				} elseif (isset($component->DURATION)) {
					$endDate = clone $component->DTSTART->getDateTime();
					$endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
					$lastOccurence = $endDate->getTimeStamp();
				} elseif (!$component->DTSTART->hasTime()) {
					$endDate = clone $component->DTSTART->getDateTime();
					$endDate->modify('+1 day');
					$lastOccurence = $endDate->getTimeStamp();
				} else {
					$lastOccurence = $firstOccurence;
				}
			} else {
				$it = new VObject\Recur\EventIterator($vObject, (string)$component->UID);
				$maxDate = new \DateTime(self::MAX_DATE);
				if ($it->isInfinite()) {
					$lastOccurence = $maxDate->getTimeStamp();
				} else {
					$end = $it->getDtEnd();
					$iterations = 0;
					while ($it->valid() && $end < $maxDate && $iterations < 10000) {
						$end = $it->getDtEnd();
						$it->next();
						$iterations++;
					}
					// A hostile or unusually dense finite rule must not monopolize a DAV
					// request. Over-including it in calendar queries is safe.
					$lastOccurence = $iterations >= 10000 ? $maxDate->getTimeStamp() : $end->getTimeStamp();
				}

			}
		}

		return [
			'etag'           => md5($calendarData),
			'size'           => strlen($calendarData),
			'componentType'  => $componentType,
			'firstOccurence' => $firstOccurence,
			'lastOccurence'  => $lastOccurence,
			'uid'            => $uid,
		];
	}

	/**
	 * Check an Agenda permission through Dolibarr's native User API.
	 *
	 * @return array
	 */
	private function _hasAgendaRight($scope, $action)
	{
		if (!is_object($this->user) || !method_exists($this->user, 'hasRight')) {
			return false;
		}
		return (bool) $this->user->hasRight('agenda', $scope, $action);
	}

	private function _calendarUserExists($calendarId)
	{
		$calendarId = (int) $calendarId;
		if ($calendarId <= 0) {
			return false;
		}
		if (array_key_exists($calendarId, $this->calendarUserCache)) {
			return $this->calendarUserCache[$calendarId];
		}
		$sql = 'SELECT u.rowid FROM '.MAIN_DB_PREFIX.'user u
			WHERE u.rowid = '.$calendarId.' AND u.statut > 0 AND u.fk_soc IS NULL
			AND u.entity IN ('.getEntity('user').')';
		$result = $this->db->query($sql);
		$this->calendarUserCache[$calendarId] = (bool) ($result && $this->db->fetch_object($result));
		return $this->calendarUserCache[$calendarId];
	}

	/** Return the active Dolibarr entity selected by main.inc.php. */
	private function _entity()
	{
		global $conf;
		return max(1, (int) ($conf->entity ?? 1));
	}

	private function _canWriteCalendar($calendarId)
	{
		if (!$this->_calendarUserExists($calendarId)) {
			return false;
		}
		if ((int) $calendarId === (int) $this->user->id) {
			return $this->_hasAgendaRight('myactions', 'write');
		}
		return $this->_hasAgendaRight('allactions', 'write');
	}

	private function _canDeleteFromCalendar($calendarId)
	{
		if (!$this->_calendarUserExists($calendarId)) {
			return false;
		}
		if ((int) $calendarId === (int) $this->user->id) {
			return $this->_hasAgendaRight('myactions', 'delete');
		}
		return $this->_hasAgendaRight('allactions', 'delete');
	}

	private function _canModifySource($source)
	{
		if ($source === 'pe' || $source === 'pt') {
			return isModEnabled('project') && (bool) $this->user->hasRight('projet', 'write');
		}
		if ($source === 'fi') {
			return isModEnabled('ficheinter') && (bool) $this->user->hasRight('ficheinter', 'write');
		}
		return $source === 'ev';
	}

	/**
	 * Users with only "my agenda" rights may re-PUT an object already visible
	 * in their own calendar, but cannot attach an arbitrary guessed object id.
	 */
	private function _canAttachExistingObject($calendarId, $objectUri, $source, $objectId = 0)
	{
		if (!$this->_canModifySource($source)) {
			return false;
		}
		if ($this->_hasAgendaRight('allactions', 'write')) {
			return true;
		}
		if ((int) $calendarId !== (int) $this->user->id) {
			return false;
		}
		if ($source === 'ev' && (int) $objectId > 0) {
			$result = $this->db->query('SELECT ar.fk_actioncomm
				FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar
				INNER JOIN '.MAIN_DB_PREFIX.'actioncomm a ON a.id = ar.fk_actioncomm
				WHERE ar.fk_actioncomm = '.((int) $objectId).'
				AND ar.element_type = \'user\' AND ar.fk_element = '.((int) $calendarId).'
				AND a.entity IN ('.getEntity('agenda').')');
			return (bool) ($result && $this->db->fetch_object($result));
		}
		return $this->getCalendarObject($calendarId, $objectUri) !== null;
	}

	private function _parseInternalObjectUri($objectUri)
	{
		$pattern = '/^(\d+)-(ev|pe|pt|fi)-'.preg_quote(CDAV_URI_KEY, '/').'(?:\.ics)?$/';
		if (preg_match($pattern, (string) $objectUri, $matches)) {
			return array('id' => (int) $matches[1], 'source' => $matches[2]);
		}
		return null;
	}

	private function _validateCalendarObjectUri($objectUri)
	{
		$length = strlen((string) $objectUri);
		if ($length === 0 || $length > 255) {
			throw new \Sabre\DAV\Exception\BadRequest('The calendar object URI is missing or too long');
		}
	}

	private function _containsManagedAttachment($calendarData)
	{
		$calendar = $this->_readCalendarData($calendarData);
		foreach ($calendar->getComponents() as $component) {
			if (in_array($component->name, array('VEVENT', 'VTODO'), true)) {
				foreach ($component->select('ATTACH') as $attachment) {
					if (isset($attachment['MANAGED-ID'])) return true;
				}
			}
		}
		return false;
	}

	/** Resolve a writable native appointment for the RFC 8607 HTTP plugin. */
	public function resolveManagedAttachmentTarget($calendarId, $objectUri)
	{
		if (!getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS') || !$this->_canWriteCalendar($calendarId)) {
			throw new Forbidden('Managed attachments are disabled or this calendar is read-only');
		}
		$resolved = $this->_resolveCalendarObject((int) $calendarId, (string) $objectUri);
		if ($resolved === null) throw new \Sabre\DAV\Exception\NotFound('Calendar object not found');
		if ($resolved['source'] !== 'ev') throw new Forbidden('Managed attachments are supported only for Dolibarr Agenda events');
		return (int) $resolved['id'];
	}

	private function _resolveCalendarObject($calendarId, $objectUri)
	{
		$existing = $this->getCalendarObject($calendarId, $objectUri);
		if ($existing === null) {
			return null;
		}

		$internal = $this->_parseInternalObjectUri($objectUri);
		if ($internal !== null) {
			return $internal;
		}
		return array('id' => (int) $existing['id'], 'source' => 'ev');
	}

	private function _upsertEventResource($eventId, $calendarId, $transparency, $answerStatus = '')
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'actioncomm_resources
				(fk_actioncomm, element_type, fk_element, mandatory, transparency, answer_status)
			VALUES ('.((int) $eventId).', \'user\', '.((int) $calendarId).', 0, '.((int) $transparency).', '.
				($answerStatus === '' ? 'NULL' : '\''.$this->db->escape($answerStatus).'\'').')
			ON DUPLICATE KEY UPDATE
				transparency = VALUES(transparency),
				answer_status = VALUES(answer_status)';
		if (!$this->db->query($sql)) {
			throw new \Sabre\DAV\Exception('Unable to assign the calendar object to its user');
		}
	}

	/** Persist the DAV identity that has no native ActionComm equivalent. */
	private function _upsertCalendarMapping($eventId, $objectUri, $sourceUid)
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'actioncomm_cdav (fk_object, uuidext, sourceuid)
			VALUES ('.((int) $eventId).', \''.$this->db->escape((string) $objectUri).'\', \''.$this->db->escape((string) $sourceUid).'\')
			ON DUPLICATE KEY UPDATE
				uuidext = VALUES(uuidext),
				sourceuid = VALUES(sourceuid)';
		if (!$this->db->query($sql)) {
			throw new \Sabre\DAV\Exception('Unable to save the CalDAV object mapping');
		}
	}

	/** Serialize and reject duplicate URI/UID identities in one calendar. */
	private function _assertCalendarUidAvailable($calendarId, $sourceUid, $eventId = 0, $objectUri = '')
	{
		$this->_lockCalendarOwner((int) $calendarId);
		$sql = 'SELECT ac.fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav ac'
			.' INNER JOIN '.MAIN_DB_PREFIX.'actioncomm a ON a.id = ac.fk_object'
			.' INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_resources ar ON ar.fk_actioncomm = a.id'
			.' AND ar.element_type = \'user\' AND ar.fk_element = '.((int) $calendarId)
			.' WHERE (ac.sourceuid = \''.$this->db->escape((string) $sourceUid).'\''
			.($objectUri !== '' ? ' OR ac.uuidext = \''.$this->db->escape((string) $objectUri).'\'' : '').')'
			.' AND a.entity IN ('.getEntity('agenda').')';
		if ($eventId > 0) {
			$sql .= ' AND ac.fk_object <> '.((int) $eventId);
		}
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to verify calendar UID uniqueness');
		}
		if ($this->db->fetch_object($result)) {
			throw new \Sabre\DAV\Exception\Conflict('A different calendar resource already uses this URI or UID');
		}
	}

	/** Serialize calendar mutations on a native Dolibarr user row. */
	private function _lockCalendarOwner($calendarId)
	{
		$lockResult = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $calendarId)
			.' AND statut > 0 AND fk_soc IS NULL AND entity IN ('.getEntity('user').') FOR UPDATE');
		if (!$lockResult || !$this->db->fetch_object($lockResult)) {
			throw new Forbidden('Calendar owner is not an active internal Dolibarr user');
		}
	}

	/**
	 * Couple an RFC 8607 POST to the exact calendar representation it modified.
	 * The native user-row lock makes this check and the following ActionComm update atomic.
	 */
	public function expectCalendarVersion($calendarId, $objectUri, $etag)
	{
		$this->expectedCalendarEtags[((int) $calendarId).'\n'.(string) $objectUri] = (string) $etag;
	}

	private function _assertCalendarVersion($calendarId, $objectUri)
	{
		$key = ((int) $calendarId).'\n'.(string) $objectUri;
		if (!array_key_exists($key, $this->expectedCalendarEtags)) {
			return;
		}
		$expected = $this->expectedCalendarEtags[$key];
		unset($this->expectedCalendarEtags[$key]);
		$current = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
		if ($current === null || !hash_equals((string) $expected, (string) $current['etag'])) {
			throw new \Sabre\DAV\Exception\PreconditionFailed(
				'The calendar object changed while its managed attachment was being updated',
				'If-Match'
			);
		}
	}

	/** Populate an ActionComm through fields supported by Dolibarr's native API. */
	private function _applyActionCommData($action, array $data, $start, $end, $calendarId, array $actionType)
	{
		$start = (int) $start;
		$end = (int) $end;
		$action->type_id = (int) $actionType['id'];
		$action->type_code = (string) $actionType['code'];
		$action->code = (string) $actionType['code'];
		$action->label = (string) $data['label'];
		$action->datep = $start;
		$action->datef = !empty($data['fullday']) ? max($start, $end - 1) : max($start, $end);
		$action->fulldayevent = (int) $data['fullday'];
		$action->location = trim(str_replace(array("\r", "\t", "\n"), ' ', (string) $data['location']));
		$action->priority = (int) $data['priority'];
		$action->transparency = (int) $data['transparency'];
		$action->note = (string) $data['note'];
		$action->note_private = (string) $data['note'];
		$action->percentage = (int) $data['percent'];
		if (empty($action->userownerid)) {
			$action->userownerid = (int) $calendarId;
		}
		$nativeRecurrence = $data['native_recurrence'] ?? null;
		$action->recurid = is_array($nativeRecurrence) ? (string) $nativeRecurrence['id'] : '';
		$action->recurrule = is_array($nativeRecurrence) ? (string) $nativeRecurrence['rule'] : '';
		$action->recurdateend = is_array($nativeRecurrence) ? (int) $nativeRecurrence['until'] : '';
	}

	/** Update an existing Dolibarr event through ActionComm and its resource API. */
	private function _updateActionCommFromCalendarData($eventId, array $data, $calendarId)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$event = new \ActionComm($this->db);
		if ($event->fetch((int) $eventId) <= 0
			|| !in_array((int) $event->entity, array_map('intval', explode(',', getEntity('agenda'))), true)) {
			throw new \Sabre\DAV\Exception\NotFound('Dolibarr event not found');
		}
		$actionType = $this->_getActionType($data['componentType']);
		$this->_applyActionCommData($event, $data, $data['start'], $data['end'], $calendarId, $actionType);
		if ($data['has_participants']) {
			$canManageAllUsers = $this->_hasAgendaRight('allactions', 'write');
			list($resolvedUsers, $resolvedContacts, $contactCompanies) = $this->_resolveParticipantResources(
				$data['participants'],
				(int) $calendarId,
				$canManageAllUsers
			);
			if ($canManageAllUsers) {
				$event->userassigned = $resolvedUsers;
			} else {
				// Personal-agenda rights must not remove other users' assignments.
				$event->userassigned[(int) $calendarId] = $resolvedUsers[(int) $calendarId];
			}
			$event->socpeopleassigned = $resolvedContacts;
			if (count($resolvedContacts) === 1) {
				$contactIds = array_keys($resolvedContacts);
				$event->contact_id = (int) $contactIds[0];
				$event->socid = (int) $contactCompanies[$event->contact_id];
			} else {
				$event->contact_id = 0;
				$event->socid = 0;
			}
		}
		if (empty($event->userassigned[(int) $calendarId])) {
			$event->userassigned[(int) $calendarId] = array('id' => (int) $calendarId);
		}
		foreach ($event->userassigned as &$assignment) {
			if (is_array($assignment)) {
				$assignment['transparency'] = (int) $data['transparency'];
			}
		}
		unset($assignment);
		$event->userassigned[(int) $calendarId]['answer_status'] = (string) $data['answer_status'];
		if (empty($event->userassigned[(int) $event->userownerid])) {
			$event->userownerid = (int) $calendarId;
		}
		if ($event->update($this->user) <= 0) {
			throw new \Sabre\DAV\Exception('Unable to update the Dolibarr event: '.$event->error);
		}
		$this->_syncNativeRecurrence((int) $event->id, $data);
		$this->_syncNativeReminders((int) $event->id, (int) $calendarId, $data['native_reminders'] ?? array());
		$this->_restoreActionCommResourceMetadata((int) $event->id, $event->userassigned);
		return $event;
	}

	/**
	 * Resolve exact, unambiguous participant email addresses to native Dolibarr
	 * user/contact resources. Ambiguous addresses are deliberately ignored.
	 */
	private function _resolveParticipantResources(array $participants, $calendarId, $manageAllUsers)
	{
		$userAssignments = array();
		$contactAssignments = array();
		$contactCompanies = array();
		$emails = array();
		foreach (array_slice($participants, 0, 100) as $participant) {
			$email = strtolower(trim((string) ($participant['email'] ?? '')));
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$emails[$email] = $participant;
			}
		}

		if ($manageAllUsers && $emails) {
			$quotedEmails = array();
			foreach (array_keys($emails) as $email) {
				$quotedEmails[] = '\''.$this->db->escape($email).'\'';
			}
			$result = $this->db->query('SELECT rowid, LOWER(email) AS email_key
				FROM '.MAIN_DB_PREFIX.'user
				WHERE statut > 0 AND fk_soc IS NULL
				AND entity IN ('.getEntity('user').')
				AND LOWER(email) IN ('.implode(',', $quotedEmails).')
				ORDER BY rowid');
			if (!$result) {
				throw new \Sabre\DAV\Exception('Unable to resolve the event participants');
			}
			$candidates = array();
			while ($row = $this->db->fetch_object($result)) {
				$candidates[(string) $row->email_key][] = (int) $row->rowid;
			}
			foreach ($candidates as $email => $ids) {
				if (count($ids) === 1) {
					$participant = $emails[$email];
					$userAssignments[$ids[0]] = array(
						'id' => $ids[0],
						'mandatory' => (($participant['role'] ?? '') === 'REQ-PARTICIPANT' ? 1 : 0),
						'transparency' => (int) ($participant['transparency'] ?? 0),
						'answer_status' => (string) ($participant['partstat'] ?? ''),
					);
				}
			}
		}

		$canReadContacts = is_object($this->user)
			&& method_exists($this->user, 'hasRight')
			&& $this->user->hasRight('societe', 'contact', 'read');
		if ($canReadContacts && $emails) {
			$quotedEmails = array();
			foreach (array_keys($emails) as $email) {
				$quotedEmails[] = '\''.$this->db->escape($email).'\'';
			}
			$result = $this->db->query('SELECT rowid, fk_soc, LOWER(email) AS email_key
				FROM '.MAIN_DB_PREFIX.'socpeople
				WHERE statut = 1 AND entity IN ('.getEntity('contact').')
				AND (priv = 0 OR (priv = 1 AND fk_user_creat = '.((int) $this->user->id).'))
				AND LOWER(email) IN ('.implode(',', $quotedEmails).')
				ORDER BY rowid');
			if (!$result) {
				throw new \Sabre\DAV\Exception('Unable to resolve the event contacts');
			}
			$candidates = array();
			while ($row = $this->db->fetch_object($result)) {
				$candidates[(string) $row->email_key][] = array('id' => (int) $row->rowid, 'socid' => (int) $row->fk_soc);
			}
			foreach ($candidates as $rows) {
				if (count($rows) === 1) {
					$id = $rows[0]['id'];
					$contactAssignments[$id] = array('id' => $id);
					$contactCompanies[$id] = $rows[0]['socid'];
				}
			}
		}

		$calendarId = (int) $calendarId;
		$userAssignments[$calendarId] = array(
			'id' => $calendarId,
			'mandatory' => 0,
			'transparency' => 0,
			'answer_status' => '',
		);
		return array($userAssignments, $contactAssignments, $contactCompanies);
	}

	/** Work around ActionComm's integer cast of RFC PARTSTAT values in Dolibarr 23. */
	private function _restoreActionCommResourceMetadata($eventId, array $userAssignments)
	{
		foreach ($userAssignments as $assignment) {
			if (!is_array($assignment) || empty($assignment['id'])) {
				continue;
			}
			$this->_upsertEventResource(
				(int) $eventId,
				(int) $assignment['id'],
				(int) ($assignment['transparency'] ?? 0),
				(string) ($assignment['answer_status'] ?? '')
			);
		}
	}

	/** Remove one calendar assignment and use ActionComm for the business write. */
	private function _detachActionCommFromCalendar($eventId, $calendarId)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$event = new \ActionComm($this->db);
		if ($event->fetch((int) $eventId) <= 0) {
			throw new \Sabre\DAV\Exception\NotFound('Dolibarr event not found');
		}
		$remainingAssignments = array();
		foreach ($event->userassigned as $key => $assignment) {
			$id = is_array($assignment) ? (int) ($assignment['id'] ?? $key) : (int) $assignment;
			if ($id > 0 && $id !== (int) $calendarId) {
				$remainingAssignments[$id] = is_array($assignment) ? $assignment : array('id' => $id);
			}
		}
		$result = $this->db->query('SELECT fk_object FROM '.MAIN_DB_PREFIX.'actioncomm_cdav
			WHERE fk_object = '.((int) $eventId));
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to inspect the CalDAV object mapping');
		}
		$moduleOwned = (bool) $this->db->fetch_object($result);
		$this->_deleteNativeReminders((int) $eventId, (int) $calendarId);
		if ($moduleOwned && !$remainingAssignments) {
			$this->managedAttachmentStore->removeAllForEvent((int) $eventId);
			$this->_deleteActionCommLinks((int) $eventId);
			$this->_deleteCalendarMetadata((int) $eventId);
			if ($event->delete($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to delete the Dolibarr event: '.$event->error);
			}
			// Older CDav installations may predate the actioncomm_cdav foreign
			// key. Keep the technical mapping tidy even on those upgraded schemas;
			// the business deletion itself has already gone through ActionComm.
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'actioncomm_cdav
				WHERE fk_object = '.((int) $eventId))) {
				throw new \Sabre\DAV\Exception('Unable to delete the CalDAV object mapping');
			}
			if ($this->_recurrenceMappingTableAvailable()
				&& !$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_recurrence WHERE fk_actioncomm = '.((int) $eventId))) {
				throw new \Sabre\DAV\Exception('Unable to delete native recurrence provenance');
			}
			return;
		}

		$event->userassigned = $remainingAssignments;
		if ($remainingAssignments) {
			$remainingIds = array_keys($remainingAssignments);
			$event->userownerid = (int) $remainingIds[0];
		} else {
			$event->userownerid = max(0, (int) $event->authorid);
		}
		if ($event->update($this->user) <= 0) {
			throw new \Sabre\DAV\Exception('Unable to detach the Dolibarr event: '.$event->error);
		}
		$this->_restoreActionCommResourceMetadata((int) $eventId, $remainingAssignments);
	}

	private function _getActionType($componentType)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/cactioncomm.class.php';
		// AC_TACHE exists on some long-lived installations but is not part of a
		// fresh Dolibarr 24 dictionary. AC_OTH is the native portable fallback;
		// the VTODO distinction remains faithfully carried by ActionComm percent.
		$candidates = $componentType === 'VTODO' ? array('AC_TACHE', 'AC_OTH') : array('AC_RDV');
		foreach ($candidates as $code) {
			$type = new \CActionComm($this->db);
			if ($type->fetch($code) > 0 && (int) $type->active === 1) {
				return array('id' => (int) $type->id, 'code' => (string) $type->code);
			}
		}
		throw new \Sabre\DAV\Exception('No active native Dolibarr action type is available for '.$componentType);
	}

	private function _schedulingTableAvailable()
	{
		if ($this->hasSchedulingTable !== null) {
			return $this->hasSchedulingTable;
		}
		$this->hasSchedulingTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_scheduling');
		return $this->hasSchedulingTable;
	}

	private function _scheduleObjectTableAvailable()
	{
		if ($this->hasScheduleObjectTable === null) {
			$this->hasScheduleObjectTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_schedule_object');
		}
		return $this->hasScheduleObjectTable;
	}

	private function _attachmentMappingTableAvailable()
	{
		if ($this->hasAttachmentMappingTable !== null) {
			return $this->hasAttachmentMappingTable;
		}
		$this->hasAttachmentMappingTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_attachment');
		return $this->hasAttachmentMappingTable;
	}

	private function _reminderMappingTableAvailable()
	{
		if ($this->hasReminderMappingTable === null) {
			$this->hasReminderMappingTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_reminder');
		}
		return $this->hasReminderMappingTable;
	}

	private function _recurrenceMappingTableAvailable()
	{
		if ($this->hasRecurrenceMappingTable === null) {
			$this->hasRecurrenceMappingTable = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_recurrence');
		}
		return $this->hasRecurrenceMappingTable;
	}

	/**
	 * Project the RFC subset supported by ActionComm into its native fields.
	 * ActionComm::update() does not write these three fields in Dolibarr 23/24,
	 * so the small SQL bridge below is intentionally limited to that API gap.
	 */
	private function _syncNativeRecurrence($eventId, array $data)
	{
		$recurrence = $data['native_recurrence'] ?? null;
		$recurId = is_array($recurrence) ? (string) $recurrence['id'] : '';
		$rule = is_array($recurrence) ? (string) $recurrence['rule'] : '';
		$until = is_array($recurrence) ? (int) $recurrence['until'] : 0;
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'actioncomm SET recurid = '
			.($recurId === '' ? 'NULL' : '\''.$this->db->escape($recurId).'\'')
			.', recurrule = '.($rule === '' ? 'NULL' : '\''.$this->db->escape($rule).'\'')
			.', recurdateend = '.($until <= 0 ? 'NULL' : '\''.$this->db->idate($until).'\'')
			.' WHERE id = '.((int) $eventId).' AND entity IN ('.getEntity('agenda').')';
		if (!$this->db->query($sql)) {
			throw new \Sabre\DAV\Exception('Unable to synchronize native Dolibarr recurrence fields');
		}
		if ($this->_recurrenceMappingTableAvailable()) {
			$hash = is_array($recurrence) && !empty($data['rfc_rrule']) ? hash('sha256', $data['rfc_rrule']) : '';
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_recurrence (fk_actioncomm, rfc_rrule_hash) VALUES ('
				.((int) $eventId).', '.($hash === '' ? 'NULL' : '\''.$hash.'\'').')'
				.' ON DUPLICATE KEY UPDATE rfc_rrule_hash = VALUES(rfc_rrule_hash)';
			if (!$this->db->query($sql)) {
				throw new \Sabre\DAV\Exception('Unable to track the native recurrence projection');
			}
		}
	}

	/** Replace only DAV-owned browser reminders through Dolibarr's native class. */
	private function _syncNativeReminders($eventId, $calendarId, array $reminders)
	{
		if (!getDolGlobalInt('CDAV_NATIVE_REMINDERS') || !getDolGlobalString('AGENDA_REMINDER_BROWSER')
			|| !$this->_reminderMappingTableAvailable()) {
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
		$this->_deleteNativeReminders((int) $eventId, (int) $calendarId);
		foreach (array_slice($reminders, 0, 10) as $data) {
			$reminder = new \ActionCommReminder($this->db);
			$reminder->entity = $this->_entity();
			$reminder->dateremind = dol_time_plus_duree((int) $data['start'], -1 * (int) $data['value'], (string) $data['unit']);
			$reminder->typeremind = 'browser';
			$reminder->offsetvalue = (int) $data['value'];
			$reminder->offsetunit = (string) $data['unit'];
			$reminder->status = \ActionCommReminder::STATUS_TODO;
			$reminder->fk_actioncomm = (int) $eventId;
			$reminder->fk_user = (int) $calendarId;
			$id = $reminder->create($this->user);
			if ($id <= 0) {
				throw new \Sabre\DAV\Exception('Unable to create a native Dolibarr reminder: '.$reminder->error);
			}
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_reminder (fk_actioncomm, fk_user, fk_reminder) VALUES ('
				.((int) $eventId).', '.((int) $calendarId).', '.((int) $id).')';
			if (!$this->db->query($sql)) {
				throw new \Sabre\DAV\Exception('Unable to track a native Dolibarr reminder');
			}
		}
	}

	/** Remove only reminders recorded as DAV-owned, even if projection is now disabled. */
	private function _deleteNativeReminders($eventId, $calendarId = 0)
	{
		if (!$this->_reminderMappingTableAvailable()) return;
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
		$where = ' WHERE fk_actioncomm = '.((int) $eventId);
		if ($calendarId > 0) $where .= ' AND fk_user = '.((int) $calendarId);
		$result = $this->db->query('SELECT fk_reminder FROM '.MAIN_DB_PREFIX.'cdav_reminder'.$where);
		if (!$result) throw new \Sabre\DAV\Exception('Unable to inspect DAV-owned reminders');
		while ($row = $this->db->fetch_object($result)) {
			$reminder = new \ActionCommReminder($this->db);
			if ($reminder->fetch((int) $row->fk_reminder) > 0 && $reminder->delete($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to delete a DAV-owned reminder: '.$reminder->error);
			}
		}
		if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_reminder'.$where)) {
			throw new \Sabre\DAV\Exception('Unable to delete DAV reminder provenance');
		}
	}

	/**
	 * Extract external attachments that can safely become native Dolibarr links.
	 *
	 * ATTACH URIs are never fetched by the server.  Only credential-free HTTPS
	 * URLs that fit the native llx_links column are mirrored into the Documents
	 * tab; inline BINARY, data:, file:, http: and other URI schemes remain in the
	 * lossless iCalendar metadata but never become clickable Dolibarr links.
	 */
	private function _extractSafeAttachmentLinks($component)
	{
		$attachments = array();
		$maximum = function_exists('getDolGlobalInt')
			? min(max(1, getDolGlobalInt('MAIN_SECURITY_MAX_ATTACHMENT_ON_FORMS', 10)), 50)
			: 10;
		foreach ($component->select('ATTACH') as $property) {
			if (isset($property['MANAGED-ID'])) {
				continue;
			}
			$valueType = isset($property['VALUE']) ? strtoupper(trim((string) $property['VALUE'])) : '';
			$encoding = isset($property['ENCODING']) ? strtoupper(trim((string) $property['ENCODING'])) : '';
			if ($valueType === 'BINARY' || $encoding === 'BASE64') {
				continue;
			}

			$url = trim((string) $property);
			if ($url === '' || strlen($url) > 255 || preg_match('/[\x00-\x20\x7f]/', $url)) {
				continue;
			}
			$parts = parse_url($url);
			if (!is_array($parts)
				|| strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
				|| empty($parts['host'])
				|| isset($parts['user'])
				|| isset($parts['pass'])
				|| filter_var($url, FILTER_VALIDATE_URL) === false) {
				continue;
			}
			if ($this->_isNativeAgendaDocumentUrl($url)) {
				continue;
			}

			$label = isset($property['FILENAME']) ? trim((string) $property['FILENAME']) : '';
			if ($label === '' && !empty($parts['path'])) {
				$label = rawurldecode(basename((string) $parts['path']));
			}
			$label = str_replace(array("\r", "\n"), ' ', $label);
			$label = trim(function_exists('dol_string_nohtmltag')
				? dol_string_nohtmltag($label, 0, 'UTF-8')
				: strip_tags($label));
			if ($label === '') {
				$label = 'Calendar attachment';
			}
			$label = function_exists('dol_trunc')
				? \dol_trunc($label, 240, 'right', 'UTF-8', 1)
				: (function_exists('mb_substr') ? mb_substr($label, 0, 240, 'UTF-8') : substr($label, 0, 240));
			$attachments[$url] = array('url' => $url, 'label' => $label);
			if (count($attachments) > $maximum) {
				throw new \Sabre\DAV\Exception\BadRequest('Too many external calendar attachments');
			}
		}
		return array_values($attachments);
	}

	/** Avoid turning a server-exported document.php URL into a duplicate Link. */
	private function _isNativeAgendaDocumentUrl($url)
	{
		if (!function_exists('dol_buildpath')) {
			return false;
		}
		$document = parse_url(\dol_buildpath('document.php', 3));
		$candidate = parse_url((string) $url);
		if (!is_array($document) || !is_array($candidate)) {
			return false;
		}
		foreach (array('scheme', 'host', 'port', 'path') as $part) {
			if (strtolower((string) ($document[$part] ?? '')) !== strtolower((string) ($candidate[$part] ?? ''))) {
				return false;
			}
		}
		parse_str((string) ($candidate['query'] ?? ''), $query);
		return isset($query['modulepart'], $query['file'])
			&& is_string($query['modulepart'])
			&& is_string($query['file'])
			&& $query['modulepart'] === 'actions'
			&& preg_match('#^\d+/[^/]+$#', (string) $query['file']);
	}

	/** Mirror safe ATTACH URIs through Dolibarr's native Link lifecycle. */
	private function _syncActionCommAttachmentLinks($eventId, array $attachments)
	{
		global $conf;

		if (!$this->_attachmentMappingTableAvailable()) {
			// A module re-enable/upgrade creates the provenance table.  Until then,
			// keep ATTACH losslessly in iCalendar without risking an untracked link.
			return;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';

		$wanted = array();
		foreach ($attachments as $attachment) {
			if (is_array($attachment) && !empty($attachment['url'])) {
				$wanted[(string) $attachment['url']] = $attachment;
			}
		}

		$sql = 'SELECT m.fk_link, l.url FROM '.MAIN_DB_PREFIX.'cdav_attachment m
			LEFT JOIN '.MAIN_DB_PREFIX.'links l ON l.rowid = m.fk_link
			WHERE m.fk_actioncomm = '.((int) $eventId);
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to inspect CalDAV attachment mappings');
		}
		while ($row = $this->db->fetch_object($result)) {
			$url = (string) ($row->url ?? '');
			if ($url !== '' && isset($wanted[$url])) {
				continue;
			}
			$link = new \Link($this->db);
			if ((int) $row->fk_link > 0 && $link->fetch((int) $row->fk_link) > 0
				&& $link->objecttype === 'action' && (int) $link->objectid === (int) $eventId
				&& $link->delete($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to remove a native Dolibarr appointment link');
			}
			// Also cleans a stale mapping when the linked row was already removed.
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_attachment
				WHERE fk_actioncomm = '.((int) $eventId).' AND fk_link = '.((int) $row->fk_link))) {
				throw new \Sabre\DAV\Exception('Unable to remove a CalDAV attachment mapping');
			}
		}

		$links = array();
		$linkReader = new \Link($this->db);
		if ($linkReader->fetchAll($links, 'action', (int) $eventId) < 0) {
			throw new \Sabre\DAV\Exception('Unable to read native Dolibarr appointment links');
		}
		$existingUrls = array();
		$existingLabels = array();
		foreach ($links as $link) {
			$existingUrls[(string) $link->url] = true;
			$existingLabels[function_exists('dol_strtolower') ? \dol_strtolower((string) $link->label) : strtolower((string) $link->label)] = true;
		}

		foreach ($wanted as $url => $attachment) {
			if (isset($existingUrls[$url])) {
				continue;
			}
			$label = (string) $attachment['label'];
			$labelKey = function_exists('dol_strtolower') ? \dol_strtolower($label) : strtolower($label);
			if (isset($existingLabels[$labelKey])) {
				$label = (function_exists('dol_trunc')
					? \dol_trunc($label, 230, 'right', 'UTF-8', 1)
					: (function_exists('mb_substr') ? mb_substr($label, 0, 230, 'UTF-8') : substr($label, 0, 230)))
					.'-'.substr(hash('sha256', $url), 0, 8);
				$labelKey = function_exists('dol_strtolower') ? \dol_strtolower($label) : strtolower($label);
			}

			$link = new \Link($this->db);
			$link->entity = (int) $conf->entity;
			$link->url = $url;
			$link->label = $label;
			$link->objecttype = 'action';
			$link->objectid = (int) $eventId;
			$linkId = $link->create($this->user);
			if ($linkId <= 0) {
				throw new \Sabre\DAV\Exception('Unable to create the native Dolibarr appointment link: '.$link->error);
			}
			$mappingSql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_attachment
				(fk_actioncomm, fk_link, source_uri_hash, datec) VALUES ('
				.((int) $eventId).', '.((int) $linkId).", '".hash('sha256', $url)."', '"
				.$this->db->idate(dol_now())."')";
			if (!$this->db->query($mappingSql)) {
				$link->delete($this->user);
				throw new \Sabre\DAV\Exception('Unable to track the native Dolibarr appointment link');
			}
			$existingUrls[$url] = true;
			$existingLabels[$labelKey] = true;
		}
	}

	/** Delete native link records before their appointment is definitively removed. */
	private function _deleteActionCommLinks($eventId)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
		$links = array();
		$linkReader = new \Link($this->db);
		if ($linkReader->fetchAll($links, 'action', (int) $eventId) < 0) {
			throw new \Sabre\DAV\Exception('Unable to inspect native appointment links');
		}
		foreach ($links as $link) {
			// Deleting a Link removes only Dolibarr's reference, never the remote
			// target. Keeping it after ActionComm deletion would create an orphan.
			if ($link->delete($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to delete a native Dolibarr appointment link');
			}
		}
	}

	private function _storeCalendarMetadata($eventId, $calendarData)
	{
		if (!$this->_schedulingTableAvailable()) {
			return;
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_scheduling (fk_actioncomm, calendardata)
			VALUES ('.((int) $eventId).', \''.$this->db->escape((string) $calendarData).'\')
			ON DUPLICATE KEY UPDATE calendardata = VALUES(calendardata)';
		if (!$this->db->query($sql)) {
			throw new \Sabre\DAV\Exception('Unable to preserve iCalendar metadata');
		}
	}

	private function _deleteCalendarMetadata($eventId)
	{
		if ($this->_schedulingTableAvailable()
			&& !$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.((int) $eventId))) {
			throw new \Sabre\DAV\Exception('Unable to delete the iCalendar metadata');
		}
	}

	/**
	 * Remove rows produced by older versions that expanded one RRULE into many
	 * events with the same UID.  Rows still assigned to another user are kept.
	 */
	private function _removeAdditionalSeriesObjects($calendarId, $sourceUid, $keepId)
	{
		if ($sourceUid === '') {
			return;
		}
		$sql = 'SELECT DISTINCT ac.fk_object
			FROM '.MAIN_DB_PREFIX.'actioncomm_cdav ac
			INNER JOIN '.MAIN_DB_PREFIX.'actioncomm a ON a.id = ac.fk_object
			INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_resources ar
				ON ar.fk_actioncomm = a.id AND ar.element_type = \'user\'
			WHERE ac.sourceuid = \''.$this->db->escape($sourceUid).'\'
			AND ac.fk_object <> '.((int) $keepId).'
			AND ar.fk_element = '.((int) $calendarId).'
			AND a.entity IN ('.getEntity('agenda').')';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to inspect legacy recurring events');
		}
		$ids = array();
		while ($row = $this->db->fetch_object($result)) {
			$ids[] = (int) $row->fk_object;
		}
		foreach ($ids as $eventId) {
			$this->_detachActionCommFromCalendar($eventId, (int) $calendarId);
		}
	}

	/** Translate the deliberately small RFC recurrence subset Dolibarr models. */
	private function _extractNativeRecurrence($component, $start, $uid)
	{
		if (getDolGlobalInt('MAIN_DISABLE_RECURRING_EVENTS') || count($component->select('RRULE')) !== 1) {
			return null;
		}
		$parts = $component->RRULE->getParts();
		$allowed = array('FREQ', 'INTERVAL', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH');
		foreach (array_keys($parts) as $name) {
			if (!in_array(strtoupper((string) $name), $allowed, true)) {
				return null;
			}
		}
		if ((int) ($parts['INTERVAL'] ?? 1) !== 1 || empty($parts['UNTIL'])) {
			return null;
		}
		try {
			$untilDate = VObject\DateTimeParser::parse(
				(string) $parts['UNTIL'],
				new \DateTimeZone(date_default_timezone_get())
			);
			if (!$untilDate instanceof \DateTimeInterface) {
				return null;
			}
			$until = $untilDate->getTimestamp();
		} catch (\Throwable $e) {
			return null;
		}
		if ($until < (int) $start || $until > strtotime(self::MAX_DATE.' 23:59:59')) {
			return null;
		}

		$freq = strtoupper((string) ($parts['FREQ'] ?? ''));
		$rule = '';
		if ($freq === 'DAILY' && !isset($parts['BYDAY']) && !isset($parts['BYMONTHDAY']) && !isset($parts['BYMONTH'])) {
			$rule = 'FREQ=DAILY';
		} elseif ($freq === 'WEEKLY' && !isset($parts['BYMONTHDAY']) && !isset($parts['BYMONTH'])) {
			$byDay = $parts['BYDAY'] ?? strtoupper(substr(date('D', (int) $start), 0, 2));
			$byDay = is_array($byDay) ? $byDay : explode(',', (string) $byDay);
			$expected = array('SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6);
			if (count($byDay) !== 1 || !isset($expected[strtoupper((string) $byDay[0])])
				|| $expected[strtoupper((string) $byDay[0])] !== (int) date('w', (int) $start)) {
				return null;
			}
			$rule = 'FREQ=WEEKLY_BYDAY'.((int) date('w', (int) $start));
		} elseif ($freq === 'MONTHLY' && !isset($parts['BYDAY']) && !isset($parts['BYMONTH'])) {
			$monthDays = $parts['BYMONTHDAY'] ?? date('j', (int) $start);
			if (is_array($monthDays) && count($monthDays) !== 1) return null;
			$day = (int) (is_array($monthDays) ? reset($monthDays) : $monthDays);
			if ($day !== (int) date('j', (int) $start)) return null;
			$rule = 'FREQ=MONTHLY_BYMONTHDAY'.$day;
		} elseif ($freq === 'YEARLY' && !isset($parts['BYDAY'])) {
			$months = $parts['BYMONTH'] ?? date('n', (int) $start);
			$monthDays = $parts['BYMONTHDAY'] ?? date('j', (int) $start);
			if ((is_array($months) && count($months) !== 1) || (is_array($monthDays) && count($monthDays) !== 1)) return null;
			$month = (int) (is_array($months) ? reset($months) : $months);
			$day = (int) (is_array($monthDays) ? reset($monthDays) : $monthDays);
			if ($month !== (int) date('n', (int) $start) || $day !== (int) date('j', (int) $start)) return null;
			$rule = 'FREQ=YEARLY_BYYEARMONTHDAY'.((int) date('md', (int) $start));
		}
		if ($rule === '') {
			return null;
		}
		return array('id' => substr(hash('sha256', (string) $uid), 0, 32), 'rule' => $rule, 'until' => $until);
	}

	/** Extract safe relative DISPLAY alarms for native browser reminders. */
	private function _extractNativeReminders($component, $start)
	{
		$reminders = array();
		foreach ($component->getComponents() as $alarm) {
			if ($alarm->name !== 'VALARM' || !isset($alarm->TRIGGER)
				|| strtoupper((string) ($alarm->ACTION ?? '')) !== 'DISPLAY'
				|| isset($alarm->REPEAT) || isset($alarm->DURATION)) {
				continue;
			}
			$valueType = strtoupper((string) ($alarm->TRIGGER['VALUE'] ?? 'DURATION'));
			$related = strtoupper((string) ($alarm->TRIGGER['RELATED'] ?? 'START'));
			if ($valueType === 'DATE-TIME' || $related !== 'START' || substr((string) $alarm->TRIGGER, 0, 1) !== '-') {
				continue;
			}
			try {
				$trigger = $alarm->getEffectiveTriggerTime()->getTimestamp();
			} catch (\Throwable $e) {
				continue;
			}
			$seconds = (int) $start - (int) $trigger;
			if ($seconds < 60 || $seconds > 10 * 365 * 86400) {
				continue;
			}
			$unit = '';
			$value = 0;
			foreach (array('w' => 604800, 'd' => 86400, 'h' => 3600, 'i' => 60) as $candidate => $divisor) {
				if ($seconds % $divisor === 0) {
					$unit = $candidate;
					$value = (int) ($seconds / $divisor);
					break;
				}
			}
			if ($unit !== '' && $value > 0) {
				$reminders[$value.$unit] = array('start' => (int) $start, 'value' => $value, 'unit' => $unit);
			}
			if (count($reminders) >= 10) break;
		}
		return array_values($reminders);
	}

	/**
	 * Parses all information from calendar object
	 *
	 * Returns an array with the following keys:
	 *   * etag - An md5 checksum of the object without the quotes.
	 *   * size - Size of the object in bytes
	 *   * componentType - VEVENT, VTODO or VJOURNAL
	 *   * firstOccurence
	 *   * lastOccurence
	 *   * uid - value of the UID property
	 *   * id
	 *   * label
	 *   * start
	 *   * end
	 *   * fullday
	 *   * location
	 *   * priority
	 *   * transparency
	 *   * note
	 *   * percent
	 *   * status
	 * @param string $calendarData
	 * @return array
	 */
	protected function _parseData($calendarData, $calendarId = null) {

		debug_log('_parseData('.strlen((string) $calendarData).' bytes)');
		$vObject = $this->_readCalendarData($calendarData);
		$component = null;
		foreach ($vObject->getComponents() as $candidate) {
			if (in_array($candidate->name, array('VEVENT', 'VTODO'), true)) {
				$component = $candidate;
				break;
			}
		}
		if ($component === null) {
			throw new \Sabre\DAV\Exception\BadRequest('Only VEVENT and VTODO calendar objects are supported');
		}

		$componentType = $component->name;
		$uid = isset($component->UID) ? trim((string) $component->UID) : '';
		if ($uid === '' || strlen($uid) > 255) {
			throw new \Sabre\DAV\Exception\BadRequest('The calendar UID is missing or too long');
		}
		$id = null;
		$elemSource = 'ev';
		$internalUid = $this->_parseInternalObjectUri($uid);
		if ($internalUid !== null) {
			$id = (int) $internalUid['id'];
			$elemSource = $internalUid['source'];
		}
		if ($componentType === 'VEVENT' && !isset($component->DTSTART)) {
			throw new \Sabre\DAV\Exception\BadRequest('VEVENT objects must have a DTSTART property');
		}

		try {
			$dateProperty = isset($component->DTSTART) ? $component->DTSTART
				: (isset($component->DUE) ? $component->DUE : (isset($component->DTEND) ? $component->DTEND : null));
			if ($dateProperty !== null) {
				$start = $dateProperty->getDateTime()->getTimestamp();
			} elseif (isset($component->DTSTAMP)) {
				$start = $component->DTSTAMP->getDateTime()->getTimestamp();
			} else {
				$start = time();
			}
			$fullday = !(
				(isset($component->DTSTART) && $component->DTSTART->hasTime())
				|| (isset($component->DTEND) && $component->DTEND->hasTime())
				|| (isset($component->DUE) && $component->DUE->hasTime())
			);
			if (isset($component->DTEND)) {
				$end = $component->DTEND->getDateTime()->getTimestamp();
			} elseif (isset($component->DUE)) {
				$end = $component->DUE->getDateTime()->getTimestamp();
			} elseif (isset($component->DURATION) && isset($component->DTSTART)) {
				$endDate = clone $component->DTSTART->getDateTime();
				$endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
				$end = $endDate->getTimestamp();
			} else {
				$end = $start + ($fullday ? 86400 : 3600);
			}
		} catch (\Throwable $e) {
			throw new \Sabre\DAV\Exception\BadRequest('Invalid calendar date: '.$e->getMessage());
		}
		if ($end <= $start) {
			$end = $start + ($fullday ? 86400 : 3600);
		}

		$label = isset($component->SUMMARY) ? trim((string) $component->SUMMARY) : '';
		if (substr($label, 0, 1) === '[' && strpos($label, ']') !== false) {
			$label = trim(substr($label, strpos($label, ']') + 1));
		}
		$location = isset($component->LOCATION)
			? trim(str_replace(array("\r", "\t", "\n"), ' ', (string) $component->LOCATION))
			: '';
		$priority = max(0, min(9, (int) (isset($component->PRIORITY) ? (string) $component->PRIORITY : 5)));
		$icalTransparency = isset($component->TRANSP) ? strtoupper(trim((string) $component->TRANSP)) : 'OPAQUE';
		$transparency = ($componentType === 'VEVENT' && $icalTransparency !== 'TRANSPARENT') ? 1 : 0;
		$status = isset($component->STATUS) ? strtoupper(trim((string) $component->STATUS)) : '';

		$participantsByEmail = array();
		foreach (array('ORGANIZER', 'ATTENDEE') as $propertyName) {
			foreach ($component->select($propertyName) as $property) {
				$email = strtolower(preg_replace('/^mailto:/i', '', trim((string) $property)));
				if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
					continue;
				}
				$partstat = isset($property['PARTSTAT']) ? strtoupper(trim((string) $property['PARTSTAT'])) : '';
				if ($propertyName === 'ATTENDEE' && !in_array($partstat, array('NEEDS-ACTION', 'ACCEPTED', 'DECLINED', 'TENTATIVE', 'DELEGATED', 'COMPLETED', 'IN-PROCESS'), true)) {
					$partstat = 'NEEDS-ACTION';
				}
				$participantsByEmail[$email] = array(
					'email' => $email,
					'partstat' => $partstat,
					'role' => isset($property['ROLE']) ? strtoupper(trim((string) $property['ROLE'])) : '',
					'organizer' => $propertyName === 'ORGANIZER',
					'transparency' => $transparency,
				);
			}
		}
		$hasParticipants = isset($component->ORGANIZER) || isset($component->ATTENDEE);
		$answerStatus = '';
		if ($calendarId !== null && $participantsByEmail) {
			$targetEmail = '';
			if ((int) $calendarId === (int) $this->user->id) {
				$targetEmail = strtolower(trim((string) $this->user->email));
			} else {
				$result = $this->db->query('SELECT email FROM '.MAIN_DB_PREFIX.'user
					WHERE rowid = '.((int) $calendarId).' AND statut > 0 AND fk_soc IS NULL
					AND entity IN ('.getEntity('user').')');
				if (!$result) {
					throw new \Sabre\DAV\Exception('Unable to resolve the target calendar user');
				}
				if ($row = $this->db->fetch_object($result)) {
					$targetEmail = strtolower(trim((string) $row->email));
				}
			}
			if ($targetEmail !== '' && isset($participantsByEmail[$targetEmail])) {
				$answerStatus = (string) $participantsByEmail[$targetEmail]['partstat'];
			}
		}
		if ($status === 'CANCELLED' || $answerStatus === 'DECLINED') {
			$transparency = 0;
		}

		$description = isset($component->DESCRIPTION) ? (string) $component->DESCRIPTION : '';
		$noteLines = array();
		foreach (explode("\n", $description) as $line) {
			if (mb_strpos($line, '💼', 0, 'UTF-8') === 0
				|| mb_strpos($line, '??', 0, 'UTF-8') === 0
				|| mb_strpos($line, '*DOLIBARR-', 0, 'UTF-8') !== false) {
				continue;
			}
			$line = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $line);
			$noteLines[] = strtr($line, array('- [' => '[', '[x] [ ]' => '[x]', '[ ] [x]' => '[x]', '[x] [x]' => '[x]', '[ ] [ ]' => '[ ]'));
		}
		$note = trim(implode("\n", $noteLines));
		$percent = -1;
		if ($componentType === 'VTODO') {
			$percent = $status === 'COMPLETED' ? 100
				: (isset($component->{'PERCENT-COMPLETE'}) ? (int) $component->{'PERCENT-COMPLETE'}->getValue() : 0);
			$percent = max(0, min(100, $percent));
		}
		$attachmentLinks = $this->_extractSafeAttachmentLinks($component);
		$rfcRrule = isset($component->RRULE) ? (string) $component->RRULE : '';
		$nativeRecurrence = $this->_extractNativeRecurrence($component, $start, $uid);
		$nativeReminders = $this->_extractNativeReminders($component, $start);

		$occur = new \stdClass();
		$occur->start = $start;
		$occur->end = $end;
		$ret = array(
			'etag' => md5($calendarData),
			'size' => strlen($calendarData),
			'componentType' => $componentType,
			'firstOccurence' => $start,
			'lastOccurence' => $end,
			'occurences' => array($occur),
			'uid' => $uid,
			'id' => $id,
			'label' => $label,
			'start' => $start,
			'end' => $end,
			'fullday' => (int) $fullday,
			'location' => $location,
			'priority' => $priority,
			'transparency' => $transparency,
			'note' => $note,
			'percent' => $percent,
			'status' => $status,
			'answer_status' => $answerStatus,
			'invitation' => $hasParticipants,
			'has_participants' => $hasParticipants,
			'participants' => array_values($participantsByEmail),
			'attachment_links' => $attachmentLinks,
			'rfc_rrule' => $rfcRrule,
			'native_recurrence' => $nativeRecurrence,
			'native_reminders' => $nativeReminders,
			'elem_source' => $elemSource,
		);
		debug_log('   parsed '.$componentType.' UID '.$uid);
		return $ret;
	}

	/**
	 * Deletes an existing calendar object.
	 *
	 * The object uri is only the basename, or filename and not a full path.
	 *
	 * @param string $calendarId
	 * @param string $objectUri
	 * @return void
	 */
	function deleteCalendarObject($calendarId, $objectUri) {

		debug_log("deleteCalendarObject( $calendarId , $objectUri) ");
		$this->_validateCalendarObjectUri($objectUri);

		if (!$this->_canDeleteFromCalendar($calendarId)) {
			throw new Forbidden('Not allowed to delete from this calendar');
		}

		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CalDAV delete transaction');
		}
		try {
			$this->_lockCalendarOwner((int) $calendarId);
			$resolvedObject = $this->_resolveCalendarObject($calendarId, $objectUri);
			if ($resolvedObject === null) {
				throw new \Sabre\DAV\Exception\NotFound('Calendar object not found');
			}
			$oid = (int) $resolvedObject['id'];
			$source = (string) $resolvedObject['source'];
			$this->_assertCalendarVersion((int) $calendarId, (string) $objectUri);
			if (!$this->_canModifySource($source)) {
				throw new Forbidden('Not allowed to modify this type of Dolibarr object');
			}
		if ($source === 'ev') {
			$this->_detachActionCommFromCalendar($oid, (int) $calendarId);
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CalDAV event deletion');
			}
			return;
		}

		if ($source === 'pe' || $source === 'pt') {
			require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
			$task = new \Task($this->db);
			if ($task->fetch($oid) <= 0) {
				throw new \Sabre\DAV\Exception\NotFound('Dolibarr project task not found');
			}
			$result = $this->db->query('SELECT ec.rowid FROM '.MAIN_DB_PREFIX.'element_contact ec
				INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact tc ON tc.rowid = ec.fk_c_type_contact
				WHERE ec.element_id = '.$oid.' AND ec.fk_socpeople = '.((int) $calendarId).'
				AND tc.element = \'project_task\' AND tc.source = \'internal\'
				AND tc.rowid = '.((int) CDAV_TASK_USER_ROLE).' ORDER BY ec.rowid');
			if (!$result) {
				throw new \Sabre\DAV\Exception('Unable to find the project task assignment');
			}
			while ($row = $this->db->fetch_object($result)) {
				if ($task->delete_contact((int) $row->rowid) <= 0) {
					throw new \Sabre\DAV\Exception('Unable to remove the project task through Dolibarr: '.$task->error);
				}
			}
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the project task deletion');
			}
			return;
		}

		if ($source === 'fi') {
			$result = $this->db->query('SELECT fd.fk_fichinter
				FROM '.MAIN_DB_PREFIX.'fichinterdet fd
				INNER JOIN '.MAIN_DB_PREFIX.'fichinter f ON f.rowid = fd.fk_fichinter
				WHERE fd.rowid = '.$oid.' AND f.entity IN ('.getEntity('intervention').')');
			$row = $result ? $this->db->fetch_object($result) : null;
			if (!$row) {
				throw new \Sabre\DAV\Exception\NotFound('Intervention line not found');
			}
			$interventionId = (int) $row->fk_fichinter;
			require_once DOL_DOCUMENT_ROOT.'/fichinter/class/fichinter.class.php';
			$intervention = new \Fichinter($this->db);
			if ($intervention->fetch($interventionId) <= 0) {
				throw new \Sabre\DAV\Exception\NotFound('Dolibarr intervention not found');
			}
			$result = $this->db->query('SELECT ec.rowid FROM '.MAIN_DB_PREFIX.'element_contact ec
				INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact tc ON tc.rowid = ec.fk_c_type_contact
				WHERE ec.element_id = '.$interventionId.' AND ec.fk_socpeople = '.((int) $calendarId).'
				AND tc.element = \'fichinter\' AND tc.source = \'internal\'
				AND tc.rowid = '.((int) CDAV_INTERV_USER_ROLE).' ORDER BY ec.rowid');
			if (!$result) {
				throw new \Sabre\DAV\Exception('Unable to find the intervention assignment');
			}
			while ($row = $this->db->fetch_object($result)) {
				if ($intervention->delete_contact((int) $row->rowid) <= 0) {
					throw new \Sabre\DAV\Exception('Unable to remove the intervention through Dolibarr: '.$intervention->error);
				}
			}
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the intervention deletion');
			}
			return;
		}

		throw new \Sabre\DAV\Exception\NotFound('Unsupported calendar object');
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Performs a calendar-query on the contents of this calendar.
	 *
	 * The calendar-query is defined in RFC4791 : CalDAV. Using the
	 * calendar-query it is possible for a client to request a specific set of
	 * object, based on contents of iCalendar properties, date-ranges and
	 * iCalendar component types (VTODO, VEVENT).
	 *
	 * This method should just return a list of (relative) urls that match this
	 * query.
	 *
	 * The list of filters are specified as an array. The exact array is
	 * documented by \Sabre\CalDAV\CalendarQueryParser.
	 *
	 * Note that it is extremely likely that getCalendarObject for every path
	 * returned from this method will be called almost immediately after. You
	 * may want to anticipate this to speed up these requests.
	 *
	 * This method provides a default implementation, which parses *all* the
	 * iCalendar objects in the specified calendar.
	 *
	 * This default may well be good enough for personal use, and calendars
	 * that aren't very large. But if you anticipate high usage, big calendars
	 * or high loads, you are strongly adviced to optimize certain paths.
	 *
	 * The best way to do so is override this method and to optimize
	 * specifically for 'common filters'.
	 *
	 * Requests that are extremely common are:
	 *   * requests for just VEVENTS
	 *   * requests for just VTODO
	 *   * requests with a time-range-filter on a VEVENT.
	 *
	 * ..and combinations of these requests. It may not be worth it to try to
	 * handle every possible situation and just rely on the (relatively
	 * easy to use) CalendarQueryValidator to handle the rest.
	 *
	 * Note that especially time-range-filters may be difficult to parse. A
	 * time-range filter specified on a VEVENT must for instance also handle
	 * recurrence rules correctly.
	 * A good example of how to interprete all these filters can also simply
	 * be found in \Sabre\CalDAV\CalendarQueryFilter. This class is as correct
	 * as possible, so it gives you a good idea on what type of stuff you need
	 * to think of.
	 *
	 * This specific implementation (for the PDO) backend optimizes filters on
	 * specific components, and VEVENT time-ranges.
	 *
	 * @param string $calendarId
	 * @param array $filters
	 * @return array
	 */
	function calendarQuery($calendarId, array $filters) {

		debug_log("calendarQuery($calendarId, ".print_r($filters, true)." ) ");

		$result = [];
		$objects = $this->getCalendarObjects($calendarId);

		foreach ($objects as $object) {

			if ($this->validateFilterForObject($object, $filters)) {
				$result[] = $object['uri'];
			}

		}

		debug_log("calendarQuery return x ".count($result));

		return $result;
	}

	/**
	 * Searches through all of a users calendars and calendar objects to find
	 * an object with a specific UID.
	 *
	 * This method should return the path to this object, relative to the
	 * calendar home, so this path usually only contains two parts:
	 *
	 * calendarpath/objectpath.ics
	 *
	 * If the uid is not found, return null.
	 *
	 * This method should only consider * objects that the principal owns, so
	 * any calendars owned by other principals that also appear in this
	 * collection should be ignored.
	 *
	 * @param string $principalUri
	 * @param string $uid
	 * @return string|null
	 */
	function getCalendarObjectByUID($principalUri, $uid) {

		debug_log("getCalendarObjectByUID( $principalUri , $uid)");

		// Scheduling asks on behalf of the recipient principal while the HTTP
		// session still belongs to the organizer. Resolve that explicit native
		// principal instead of accidentally searching the organizer's calendar.
		if (!preg_match('#^principals/([^/]{1,128})$#', trim((string) $principalUri, '/'), $principalMatch)) {
			return null;
		}
		$principalResult = $this->db->query('SELECT rowid, login FROM '.MAIN_DB_PREFIX.'user WHERE login = \''
			.$this->db->escape($principalMatch[1]).'\' AND statut > 0 AND fk_soc IS NULL AND entity IN ('.getEntity('user').')');
		$principal = $principalResult ? $this->db->fetch_object($principalResult) : null;
		if (!$principal) return null;
		$calendarId = (int) $principal->rowid;
		$calendarUri = $calendarId.'-cal-'.(string) $principal->login;
		$internal = $this->_parseInternalObjectUri($uid);
		if ($internal !== null) {
			$objectUri = $internal['id'].'-'.$internal['source'].'-'.CDAV_URI_KEY;
			return $this->getCalendarObject($calendarId, $objectUri) === null
				? null
				: $calendarUri.'/'.$objectUri;
		}

		$sql = 'SELECT ac.uuidext
			FROM '.MAIN_DB_PREFIX.'actioncomm_cdav ac
			INNER JOIN '.MAIN_DB_PREFIX.'actioncomm a ON a.id = ac.fk_object
			INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_resources ar
				ON ar.fk_actioncomm = a.id AND ar.element_type = \'user\'
			WHERE ac.sourceuid = \''.$this->db->escape((string) $uid).'\'
			AND ar.fk_element = '.$calendarId.'
			AND a.entity IN ('.getEntity('agenda').')
			ORDER BY a.id LIMIT 1';
		$result = $this->db->query($sql);
		if ($result && ($row = $this->db->fetch_object($result))) {
			$object = $this->getCalendarObject($calendarId, (string) $row->uuidext);
			if ($object !== null) {
				return $calendarUri.'/'.$object['uri'];
			}
		}
		return null;
	}

	/**
	 * The getChanges method returns all the changes that have happened, since
	 * the specified syncToken in the specified calendar.
	 *
	 * This function should return an array, such as the following:
	 *
	 * [
	 *   'syncToken' => 'The current synctoken',
	 *   'added'   => [
	 *      'new.txt',
	 *   ],
	 *   'modified'   => [
	 *      'modified.txt',
	 *   ],
	 *   'deleted' => [
	 *      'foo.php.bak',
	 *      'old.txt'
	 *   ]
	 * ];
	 *
	 * The returned syncToken property should reflect the *current* syncToken
	 * of the calendar, as reported in the {http://sabredav.org/ns}sync-token
	 * property this is needed here too, to ensure the operation is atomic.
	 *
	 * If the $syncToken argument is specified as null, this is an initial
	 * sync, and all members should be reported.
	 *
	 * The modified property is an array of nodenames that have changed since
	 * the last token.
	 *
	 * The deleted property is an array with nodenames, that have been deleted
	 * from collection.
	 *
	 * The $syncLevel argument is basically the 'depth' of the report. If it's
	 * 1, you only have to report changes that happened only directly in
	 * immediate descendants. If it's 2, it should also include changes from
	 * the nodes below the child collections. (grandchildren)
	 *
	 * The $limit argument allows a client to specify how many results should
	 * be returned at most. If the limit is not specified, it should be treated
	 * as infinite.
	 *
	 * If the limit (infinite or not) is higher than you're willing to return,
	 * you should throw a Sabre\DAV\Exception\TooMuchMatches() exception.
	 *
	 * If the syncToken is expired (due to data cleanup) or unknown, you must
	 * return null.
	 *
	 * The limit is 'suggestive'. You are free to ignore it.
	 *
	 * @param string $calendarId
	 * @param string $syncToken
	 * @param int $syncLevel
	 * @param int $limit
	 * @return array
	 */
	function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) {

		debug_log("getChangesForCalendar( $calendarId , $syncToken , $syncLevel , $limit )");

		if ((int) $syncLevel !== 1 || !$this->_calendarUserExists($calendarId)) {
			return null;
		}
		$objects = array();
		foreach ($this->getCalendarObjects((int) $calendarId) as $object) {
			$objects[(string) $object['uri']] = (string) ($object['etag'] ?? '');
		}
		return $this->syncStore->getChanges('cal', (int) $calendarId, $syncToken, $limit, $objects);
	}

	/**
	 * Returns a single scheduling object.
	 *
	 * The returned array should contain the following elements:
	 *   * uri - A unique basename for the object. This will be used to
	 *           construct a full uri.
	 *   * calendardata - The iCalendar object
	 *   * lastmodified - The last modification date. Can be an int for a unix
	 *                    timestamp, or a PHP DateTime object.
	 *   * etag - A unique token that must change if the object changed.
	 *   * size - The size of the object, in bytes.
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 * @return array
	 */
	function getSchedulingObject($principalUri, $objectUri) {

		debug_log("getSchedulingObject( $principalUri , $objectUri )");

		if (!getDolGlobalInt('CDAV_SCHEDULING') || !$this->_scheduleObjectTableAvailable()
			|| !$this->_canAccessSchedulingPrincipal($principalUri)) return null;
		$this->_purgeExpiredSchedulingObjects();
		$principalId = $this->_getSchedulingPrincipalId($principalUri);
		if ($principalId <= 0 || !$this->_validSchedulingUri($objectUri)) return null;
		$result = $this->db->query('SELECT uri, calendardata, etag, tms FROM '.MAIN_DB_PREFIX.'cdav_schedule_object'
			.' WHERE entity = '.$this->_entity().' AND fk_principal = '.$principalId
			." AND direction = 'I' AND uri_hash = '".hash('sha256', (string) $objectUri)."'");
		$row = $result ? $this->db->fetch_object($result) : null;
		return $row ? $this->_formatSchedulingRow($row, true, (string) $principalUri) : null;
	}

	/**
	 * Returns all scheduling objects for the inbox collection.
	 *
	 * These objects should be returned as an array. Every item in the array
	 * should follow the same structure as returned from getSchedulingObject.
	 *
	 * The main difference is that 'calendardata' is optional.
	 *
	 * @param string $principalUri
	 * @return array
	 */
	function getSchedulingObjects($principalUri) {

		debug_log("getSchedulingObjects( $principalUri )");

		if (!getDolGlobalInt('CDAV_SCHEDULING') || !$this->_scheduleObjectTableAvailable()
			|| !$this->_canAccessSchedulingPrincipal($principalUri)) return array();
		$this->_purgeExpiredSchedulingObjects();
		$principalId = $this->_getSchedulingPrincipalId($principalUri);
		if ($principalId <= 0) return array();
		$result = $this->db->query('SELECT uri, calendardata, etag, tms FROM '.MAIN_DB_PREFIX.'cdav_schedule_object'
			.' WHERE entity = '.$this->_entity().' AND fk_principal = '.$principalId
			." AND direction = 'I' ORDER BY tms DESC LIMIT 500");
		$objects = array();
		if ($result) while ($row = $this->db->fetch_object($result)) $objects[] = $this->_formatSchedulingRow($row, false, (string) $principalUri);
		return $objects;
	}

	/**
	 * Deletes a scheduling object
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 * @return void
	 */
	function deleteSchedulingObject($principalUri, $objectUri) {

		debug_log("deleteSchedulingObject( $principalUri , $objectUri )");

		if (!getDolGlobalInt('CDAV_SCHEDULING') || !$this->_scheduleObjectTableAvailable()
			|| !$this->_canAccessSchedulingPrincipal($principalUri) || !$this->_validSchedulingUri($objectUri)) {
			throw new Forbidden('Not allowed to delete this scheduling object');
		}
		$principalId = $this->_getSchedulingPrincipalId($principalUri);
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE entity = '
			.$this->_entity().' AND fk_principal = '.$principalId
			." AND direction = 'I' AND uri_hash = '".hash('sha256', (string) $objectUri)."'";
		if (!$this->db->query($sql)) throw new \Sabre\DAV\Exception('Unable to delete the scheduling object');
	}

	/**
	 * Creates a new scheduling object. This should land in a users' inbox.
	 *
	 * @param string $principalUri
	 * @param string $objectUri
	 * @param string $objectData
	 * @return void
	 */
	function createSchedulingObject($principalUri, $objectUri, $objectData) {

		debug_log("createSchedulingObject( $principalUri , $objectUri)");

		if (!getDolGlobalInt('CDAV_SCHEDULING') || !$this->_scheduleObjectTableAvailable()) {
			throw new Forbidden('CalDAV scheduling is disabled');
		}
		$principalId = $this->_getSchedulingPrincipalId($principalUri);
		if ($principalId <= 0) throw new Forbidden('Scheduling recipient is not an active internal Dolibarr user');
		$this->_storeSchedulingObject($principalId, 'I', $objectUri, $objectData);
	}

	private function _canAccessSchedulingPrincipal($principalUri)
	{
		return hash_equals('principals/'.(string) $this->user->login, trim((string) $principalUri, '/'));
	}

	private function _getSchedulingPrincipalId($principalUri)
	{
		if (!preg_match('#^principals/([^/]{1,128})$#', trim((string) $principalUri, '/'), $matches)) return 0;
		$result = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE login = \''
			.$this->db->escape($matches[1]).'\' AND statut > 0 AND fk_soc IS NULL AND entity IN ('.getEntity('user').')');
		if (!$result) return 0;
		$row = $this->db->fetch_object($result);
		return $row ? (int) $row->rowid : 0;
	}

	private function _validSchedulingUri($objectUri)
	{
		return strlen((string) $objectUri) > 0 && strlen((string) $objectUri) <= 255
			&& !preg_match('#[/\\\\\x00-\x1f\x7f]#', (string) $objectUri);
	}

	private function _readSchedulingData($objectData)
	{
		$max = min(max(1, getDolGlobalInt('CDAV_MAX_REQUEST_MB', 16)), 16) * 1024 * 1024;
		if (is_resource($objectData)) {
			$data = stream_get_contents($objectData, $max + 1);
		} else {
			$data = (string) $objectData;
		}
		if ($data === false || strlen($data) === 0 || strlen($data) > $max) {
			throw new \Sabre\DAV\Exception\BadRequest('Invalid or oversized scheduling message');
		}
		try {
			$calendar = VObject\Reader::read($data);
			if ($calendar->name !== 'VCALENDAR' || !isset($calendar->METHOD)) throw new \UnexpectedValueException('METHOD is required');
			$count = 0;
			foreach ($calendar->getComponents() as $component) {
				if ($component->name !== 'VTIMEZONE') {
					if (!in_array($component->name, array('VEVENT', 'VTODO', 'VFREEBUSY'), true)) {
						throw new \UnexpectedValueException('Unsupported scheduling component');
					}
					$count++;
				}
			}
			if ($count !== 1) throw new \UnexpectedValueException('Exactly one scheduling component is required');
			$data = $calendar->serialize();
		} catch (\Throwable $e) {
			throw new \Sabre\DAV\Exception\BadRequest('Invalid scheduling message: '.$e->getMessage());
		}
		return $data;
	}

	private function _storeSchedulingObject($principalId, $direction, $objectUri, $objectData, $messageSalt = '')
	{
		if (!$this->_validSchedulingUri($objectUri) || !in_array($direction, array('I', 'O'), true)) {
			throw new \Sabre\DAV\Exception\BadRequest('Invalid scheduling object name');
		}
		$data = $this->_readSchedulingData($objectData);
		$entity = $this->_entity();
		$ttlDays = min(max(1, getDolGlobalInt('CDAV_SCHEDULING_RETENTION_DAYS', 30)), 90);
		$now = dol_now();
		$expires = $now + $ttlDays * 86400;
		$messageHash = hash('sha256', $messageSalt."\0".$data);
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_schedule_object'
			.' (entity, fk_principal, direction, uri, uri_hash, message_hash, calendardata, etag, datec, expires_at) VALUES ('
			.$entity.', '.((int) $principalId).", '".$direction."', '".$this->db->escape((string) $objectUri)."', '"
			.hash('sha256', (string) $objectUri)."', '".$messageHash."', '".$this->db->escape($data)."', '"
			.hash('sha256', $data)."', '".$this->db->idate($now)."', '".$this->db->idate($expires)."')"
			.' ON DUPLICATE KEY UPDATE uri = VALUES(uri), uri_hash = VALUES(uri_hash), calendardata = VALUES(calendardata),'
			.' etag = VALUES(etag), expires_at = VALUES(expires_at)';
		if (!$this->db->query($sql)) throw new \Sabre\DAV\Exception('Unable to persist the scheduling object');
		$this->_purgeExpiredSchedulingObjects();
	}

	private function _purgeExpiredSchedulingObjects()
	{
		if (!$this->_scheduleObjectTableAvailable()) return;
		$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_schedule_object WHERE entity = '
			.$this->_entity().' AND expires_at < \''.$this->db->idate(dol_now()).'\'');
	}

	private function _formatSchedulingRow($row, $withData, $principalUri = '')
	{
		$data = (string) $row->calendardata;
		$object = array(
			'uri' => (string) $row->uri,
			'principaluri' => (string) $principalUri,
			'lastmodified' => strtotime((string) $row->tms),
			'etag' => '"'.(string) $row->etag.'"',
			'size' => strlen($data),
		);
		if ($withData) $object['calendardata'] = $data;
		return $object;
	}

	/** Persist a bounded local audit copy; no iMIP/email transport is installed. */
	public function recordSchedulingMessage($message)
	{
		if (!getDolGlobalInt('CDAV_SCHEDULING') || !$this->_scheduleObjectTableAvailable() || !is_object($message->message ?? null)) return;
		$data = $message->message->serialize();
		$salt = (string) ($message->sender ?? '').'|'.(string) ($message->recipient ?? '');
		$uri = 'out-'.substr(hash('sha256', $salt."\0".$data), 0, 40).'.ics';
		$this->_storeSchedulingObject((int) $this->user->id, 'O', $uri, $data, $salt);
	}

}

/** Conditional backend used only when the administrator enables RFC 6638. */
class DolibarrScheduling extends Dolibarr implements SchedulingSupport
{
}
