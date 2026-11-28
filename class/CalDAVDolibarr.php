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

use Dolibarr\CDav\CalendarUid;
use Sabre\VObject;
use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAV\Exception\Forbidden;

require_once __DIR__.'/CDavBusinessPolicy.php';
require_once __DIR__.'/CDavCalendarUid.php';
require_once __DIR__.'/CDavCalendarWritePolicy.php';
require_once __DIR__.'/CDavInvitationPolicy.php';
require_once __DIR__.'/CDavCalendarReadCache.php';
require_once __DIR__.'/CDavAppointmentStatus.php';
require_once dirname(__DIR__).'/lib/cdav_transport.lib.php';

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

	/** @var bool Whether this request uses the Outlook CalDavSynchronizer mapping */
	private $outlookSynchronizer;

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

	/** @var array<string,array> Scheduling receipts captured under the native lock. */
	private $committedSchedulingChanges = array();

	/** @var \Dolibarr\CDav\CalendarReadCache|null One request's bounded read representations. */
	private $calendarReadCache = null;

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
	 * @param object $user Authenticated native user
	 * @param object $db Native database connection
	 * @param object $langs Native translations
	 * @param object $cdavLib Native calendar exporter
	 * @param bool $cacheReads Whether this HTTP request permits read reuse
	 * @param string $userAgent HTTP User-Agent for client-specific mapping
	 */
	public function __construct($user, $db, $langs, $cdavLib, bool $cacheReads = false, string $userAgent = '')
	{
		global $conf;

		$this->user = $user;
		$this->db = $db;
		$this->langs = $langs;
		$this->cdavLib = $cdavLib;
		$this->outlookSynchronizer = \Dolibarr\CDav\CalendarWritePolicy::isOutlookSynchronizer($userAgent);
		$this->calendarReadCache = new \Dolibarr\CDav\CalendarReadCache((bool) $cacheReads);
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
			$objects = array();
			foreach ($this->getCalendarObjects((int) $row['rowid']) as $object) {
				$objects[(string) $object['uri']] = (string) ($object['etag'] ?? '');
			}
			ksort($objects, SORT_STRING);
			$collectionTag = hash('sha256', CDAV_URI_KEY.'|'.serialize($objects));

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
		$this->_disableCalendarReadCache();

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
		$this->_disableCalendarReadCache();

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
		$this->_disableCalendarReadCache();

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

		if (!$this->_hasAgendaRight('myactions', 'read')
			|| ((int) $calendarId !== (int) $this->user->id && !$this->_hasAgendaRight('allactions', 'read'))
			|| !$this->_calendarUserExists($calendarId)) {
			return array();
		}
		$context = $this->_calendarReadContext($calendarId);
		if ($context === null) return $this->cdavLib->getFullCalendarObjects($calendarId, false);
		$cached = $this->calendarReadCache->getCollection($context);
		if ($cached !== null) return $cached;
		// A new enumeration must not revive membership from an incomplete older scan.
		$this->calendarReadCache->forgetContext($context);
		try {
			$objects = $this->cdavLib->getFullCalendarObjects($calendarId, false, function (array $object, $source) use ($context) {
				$this->_cacheCalendarReadObject($context, $object, $source);
			});
			$uris = array();
			$canComplete = count($objects) < \Dolibarr\CDav\CalendarReadCache::MAX_ENTRIES;
			foreach ($objects as &$object) {
				if ($canComplete) $uris[] = (string) $object['uri'];
				$full = $this->calendarReadCache->getObject($context, (string) $object['uri']);
				if ($full !== null && (string) $full['etag'] === (string) $object['etag']) $object = $full;
			}
			unset($object);
			if ($canComplete) $this->calendarReadCache->completeCollection($context, $uris);
			return $objects;
		} catch (\Throwable $error) {
			$this->calendarReadCache->forgetContext($context);
			throw $error;
		}
	}

	/**
	 * Keep read snapshots separate by authenticated actor, entity, locale and calendar.
	 * @param int $calendarId Native calendar owner
	 * @return string|null Request-local context, null when fresh reads are mandatory
	 */
	private function _calendarReadContext($calendarId)
	{
		if ($this->calendarReadCache === null || !$this->calendarReadCache->isEnabled()) return null;
		return hash('sha256', serialize(array((int) $this->user->id, $this->_entity(),
			(string) $this->langs->getDefaultLang(), date_default_timezone_get(), (int) $calendarId)));
	}

	/**
	 * Admit only representations whose canonical URI preserves native routing.
	 * Legacy external mappings can resemble another object's internal source/ID.
	 *
	 * @param string $context Authenticated calendar context
	 * @param array $object Full native calendar representation
	 * @param string $source Native SQL source, independent of the mapped URI
	 * @return void
	 */
	private function _cacheCalendarReadObject($context, array $object, $source)
	{
		if (!$this->calendarReadCache->isEnabled()) return;
		$internal = $this->_parseInternalObjectUri((string) $object['uri']);
		if ($internal !== null && ((int) $internal['id'] !== (int) $object['id'] || $internal['source'] !== $source)) {
			$this->_disableCalendarReadCache();
			return;
		}
		$this->calendarReadCache->putObject($context, $object);
	}

	/**
	 * Drop all snapshots permanently before preflight or any native write.
	 * @return void
	 */
	private function _disableCalendarReadCache()
	{
		if ($this->calendarReadCache !== null) $this->calendarReadCache->disable();
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
		$context = $this->_calendarReadContext($calid);
		if ($context !== null) {
			$cached = $this->calendarReadCache->getObject($context, (string) $objectUri);
			if ($cached !== null) {
				$cached['calendarid'] = $calendarId;
				return $cached;
			}
		}

		if($elem_source=='ev') // Calendar Events
			$sql = $this->cdavLib->getSqlCalEvents($calid, $oid, $internalObject !== null ? false : $objectUri).' ORDER BY a.id LIMIT 1';
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
				if (isset($obj->_cdav_description) && is_array($obj->_cdav_description)) {
					$calevent['_cdav_description'] = $obj->_cdav_description;
				}
			}
		}

		debug_log($calevent === null
			? 'getCalendarObject: not found'
			: 'getCalendarObject: found id '.((int) $calevent['id']));

		if ($context !== null && $calevent !== null) $this->_cacheCalendarReadObject($context, $calevent, $elem_source);
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
		$this->_disableCalendarReadCache();

		debug_log("createCalendarObject( $calendarId , $objectUri )");
		$this->_validateCalendarObjectUri($objectUri);

		// Check write rights, not merely the right to list/read the calendar.
		if (!$this->_canWriteCalendar($calendarId))
		{
			throw new Forbidden('Not allowed to create objects in this calendar');
		}
		$origCalendarData = $calendarData;
		$this->managedAttachmentStore->validateInlineCalendarData($origCalendarData);
		$calendarData = $this->_parseData($calendarData, $calendarId, true);
		$origCalendarData = $calendarData['normalized_invitation'] ?? $origCalendarData;
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
		$createdAttachments = array();
		try {
			$this->_lockCalendarOwner((int) $calendarId);
			$this->_findActionCommByUri((string) $objectUri, true);
			$previousSchedulingObject = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
			// One occurrence is stored. Recurrence rules remain attached to that DAV object.
			foreach($calendarData['occurences'] as $iOccur => $occurence)
			{
			$oid = false;
				$davCreated = false;
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
						$davCreated = true;
					$this->_syncNativeRecurrence((int) $oid, $calendarData);
					$this->_syncNativeReminders((int) $oid, (int) $calendarId, $calendarData['native_reminders'] ?? array());
				// Dolibarr 23 casts RFC PARTSTAT strings to integers in ActionComm.
						$this->_restoreActionCommResourceMetadata((int) $oid, $userAssignments, $contactAssignments);
				debug_log("    event $oid created through ActionComm");
			}
			elseif ($elem_source === 'ev')
			{
					if ($this->_isProtectedEvent((int) $oid)) {
						throw new Forbidden('A protected Dolibarr appointment cannot be assigned to another calendar through CalDAV.');
					}
				$this->_updateActionCommFromCalendarData((int) $oid, $calendarData, (int) $calendarId);
			}

			if($elem_source == 'ev')
			{
				$storedObjectUri = $iOccur === 0 ? $objectUri : $oid.'-ev-'.CDAV_URI_KEY;
					$this->_upsertCalendarMapping((int) $oid, $storedObjectUri, (string) $calendarData['uid'], $davCreated);
				debug_log("    add user $calendarId to event $oid");
				$this->_upsertEventResource(
					(int) $oid,
					(int) $calendarId,
					(int) $calendarData['transparency'],
					(string) $calendarData['answer_status']
				);
				$origCalendarData = $this->managedAttachmentStore->importInlineCalendarData((int) $oid, $origCalendarData, $createdAttachments);
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
			$committedSchedulingObject = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
			if (!$this->db->commit()) {
				throw new \Sabre\DAV\Exception('Unable to commit the CalDAV creation');
			}
			$this->committedSchedulingChanges[((int) $calendarId).'\n'.(string) $objectUri] = array(
				'id' => (int) $oid, 'previous_data' => $previousSchedulingObject['calendardata'] ?? null,
				'canonical_data' => $committedSchedulingObject['calendardata'] ?? null,
				'changed' => ($previousSchedulingObject['calendardata'] ?? null) !== ($committedSchedulingObject['calendardata'] ?? null));
		} catch (\Throwable $e) {
			$this->db->rollback();
			$this->managedAttachmentStore->discardCreatedFiles($createdAttachments);
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
		$this->_disableCalendarReadCache();

		debug_log("updateCalendarObject( $calendarId , $objectUri )");
		$this->_validateCalendarObjectUri($objectUri);

		// Check write rights, not merely the right to list/read the calendar.
		if (!$this->_canWriteCalendar($calendarId))
		{
            debug_log('User '.$this->user->id.' not authorized to update calendar '.$calendarId);
			throw new Forbidden('Not allowed to update objects in this calendar');
		}

		$origCalendarData = $calendarData;
		$this->managedAttachmentStore->validateInlineCalendarData($origCalendarData);
		$calendarData = $this->_parseData($calendarData, $calendarId, true);
		$origCalendarData = $calendarData['normalized_invitation'] ?? $origCalendarData;

		if (! $calendarData || empty($calendarData))
		{
			return;
		}
		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CalDAV transaction');
		}
		$createdAttachments = array();
		$removedAttachments = array();
		try {
			$resolvedObject = $this->_lockAndResolveCalendarObject((int) $calendarId, (string) $objectUri);
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
			$invitationChange = $calendarData['elem_source'] === 'ev'
				? $this->validateProtectedCalendarWrite((int) $calendarId, (string) $objectUri, $origCalendarData) : false;
			if (isset($invitationChange['_personal_reminders'])) {
				// Private alarm changes share the transaction but do not revise or notify the meeting.
				$this->_syncNativeReminders((int) $calendarData['id'], (int) $calendarId, $invitationChange['_personal_reminders']);
			}
			if (is_array($invitationChange) && isset($invitationChange['_invitation_plan'])) {
				$previous = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
				$this->_applyInvitationPlan((int) $calendarData['id'], (int) $calendarId, $invitationChange['_invitation_plan'], $createdAttachments, $removedAttachments);
				$committed = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
				if (!$this->db->commit()) {
					throw new \Sabre\DAV\Exception('Unable to commit the meeting response or organizer update.');
				}
				$this->committedSchedulingChanges[((int) $calendarId).'\n'.(string) $objectUri] = array(
					'id' => (int) $calendarData['id'], 'previous_data' => (string) $previous['calendardata'],
					'canonical_data' => (string) $committed['calendardata'], 'changed' => $invitationChange['_invitation_plan']['changed']
						&& $invitationChange['_invitation_plan']['kind'] !== 'availability',
					'omit_location' => ($committed['_cdav_description']['note_prefix_fields']['original_location'] ?? '') === '',
					'owner_principal' => $invitationChange['_invitation_plan']['delegated_principal'] ?? 'principals/'.(string) $this->user->login);
				$this->managedAttachmentStore->discardCreatedFiles($removedAttachments);
				return;
			}
			$currentObject = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
			if (isset($invitationChange['_appointment_status_plan']) || isset($invitationChange['_agenda_task_plan'])) {
				$plan = $invitationChange['_agenda_task_plan'] ?? $invitationChange['_appointment_status_plan'];
				$this->_applyAppointmentStatusPlan((int) $calendarData['id'], (int) $calendarId, $plan);
				$committed = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
				if (!$this->db->commit()) throw new \Sabre\DAV\Exception('Unable to commit the appointment state.');
				$this->committedSchedulingChanges[((int) $calendarId).'\n'.(string) $objectUri] = array(
					'id' => (int) $calendarData['id'], 'previous_data' => (string) $currentObject['calendardata'],
					'canonical_data' => (string) $committed['calendardata'], 'changed' => $plan['changed'] && !isset($invitationChange['_agenda_task_plan']));
				return;
			}
			// A DAV-created standalone event also keeps its state when an editor omits STATUS.
			// Explicit transitions already went through the locked appointment plan above.
			if ($calendarData['elem_source'] === 'ev' && $calendarData['componentType'] === 'VEVENT'
				&& !$calendarData['has_explicit_status'] && isset($currentObject['calendardata'])) {
				$calendarData['status'] = \Dolibarr\CDav\AppointmentStatus::statusFromCalendar($currentObject['calendardata']) ?? $calendarData['status'];
			}
			if (isset($currentObject['_cdav_description'])) {
				$summaryPrefix = (string) ($currentObject['_cdav_description']['summary_prefix'] ?? '');
				if ($summaryPrefix !== '' && ($calendarData['label'] === $summaryPrefix || str_starts_with($calendarData['label'], $summaryPrefix.' '))) {
					$calendarData['label'] = ltrim(substr($calendarData['label'], strlen($summaryPrefix)));
				}
				$replacementPrefix = $this->cdavLib->getCalendarDescriptionPrefixForFields($currentObject['_cdav_description'], $calendarData['label'], $calendarData['location']);
				$calendarData['note'] = \Dolibarr\CDav\CalendarWritePolicy::nativeNoteForReplacement($currentObject['_cdav_description'], $calendarData['description'], $replacementPrefix);
				$calendarData['_native_note_resolved'] = true;
			}
			$protectedMove = $invitationChange;
			if ($protectedMove !== false) {
				$calendarData = array_replace($calendarData, $protectedMove);
				$this->_advanceProtectedCalendarSequence((int) $calendarData['id'], (int) $calendarId, (string) $objectUri, $protectedMove);
			}
			if ($calendarData['elem_source'] === 'ev' && $protectedMove === false && getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
				$this->managedAttachmentStore->synchronizeCalendarData((int) $calendarData['id'], $origCalendarData, false);
			}

		if($calendarData['elem_source']=='ev')
		{
				$this->_updateActionCommFromCalendarData((int) $calendarData['id'], $calendarData, (int) $calendarId, $protectedMove !== false);
		}
			elseif (in_array($calendarData['elem_source'], array('pe', 'pt'), true)) {
				require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
				$task = new \Task($this->db);
				if ($task->fetch((int) $calendarData['id']) <= 0
					|| !in_array((int) $task->entity, array_map('intval', explode(',', getEntity('project'))), true)) {
					throw new \Sabre\DAV\Exception\NotFound('Dolibarr project task not found');
				}
				$this->_applyProjectTaskData($task, $calendarData);
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

			if ($calendarData['elem_source'] === 'ev' && $protectedMove === false) {
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
			$origCalendarData = $this->managedAttachmentStore->importInlineCalendarData((int) $calendarData['id'], $origCalendarData, $createdAttachments);
			$this->_storeCalendarMetadata((int) $calendarData['id'], $origCalendarData);
			if (getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
				$removedAttachments = $this->managedAttachmentStore->synchronizeCalendarData((int) $calendarData['id'], $origCalendarData, true, true);
			}
			$this->_syncActionCommAttachmentLinks((int) $calendarData['id'], $calendarData['attachment_links']);
			$this->_removeAdditionalSeriesObjects(
				(int) $calendarId,
				(string) $calendarData['uid'],
				(int) $calendarData['id'],
				$removedAttachments
			);
			}
			$committedSchedulingObject = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
		if (!$this->db->commit()) {
			throw new \Sabre\DAV\Exception('Unable to commit the CalDAV update');
		}
			$this->committedSchedulingChanges[((int) $calendarId).'\n'.(string) $objectUri] = array(
				'id' => (int) $calendarData['id'], 'previous_data' => $currentObject['calendardata'] ?? null,
				'canonical_data' => $committedSchedulingObject['calendardata'] ?? null,
				'changed' => ($currentObject['calendardata'] ?? null) !== ($committedSchedulingObject['calendardata'] ?? null));
		} catch (\Throwable $e) {
			$this->db->rollback();
			$this->managedAttachmentStore->discardCreatedFiles($createdAttachments);
			throw $e;
		}
		// SQL metadata rolls back with the event; remove old bytes only once its
		// replacement and all native link updates have committed successfully.
		$this->managedAttachmentStore->discardCreatedFiles($removedAttachments);

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
		foreach ($vObject->getComponents() as $component) {
			if (in_array($component->name, array('VEVENT', 'VTODO'), true)) {
				$componentType = $component->name;
				break;
			}
		}
		if (!$componentType) {
			throw new \Sabre\DAV\Exception\BadRequest('Only VEVENT and VTODO calendar objects are supported');
		}
		$uid = CalendarUid::read($component);
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
		$this->_disableCalendarReadCache();
		if (!getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS') || !$this->_canWriteCalendar($calendarId)) {
			throw new Forbidden('Managed attachments are disabled or this calendar is read-only');
		}
		$resolved = $this->_resolveCalendarObject((int) $calendarId, (string) $objectUri);
		if ($resolved === null) throw new \Sabre\DAV\Exception\NotFound('Calendar object not found');
		if ($resolved['source'] !== 'ev') throw new Forbidden('Managed attachments are supported only for Dolibarr Agenda events');
		if ($this->_isProtectedEvent((int) $resolved['id'])) {
			throw new Forbidden('Attachments of a protected appointment must be managed in Dolibarr.');
		}
		return (int) $resolved['id'];
	}

	/**
	 * Validate before scheduling effects and again under the native transaction lock.
	 *
	 * @param int $calendarId Native calendar owner
	 * @param string $objectUri Calendar resource basename
	 * @param string $calendarData Client replacement calendar
	 * @param bool $creating Whether this request would attach an existing object
	 * @return array<string,int>|false Validated dates, or false for an editable object
	 */
	public function validateProtectedCalendarWrite($calendarId, $objectUri, $calendarData, $creating = false)
	{
		$this->_disableCalendarReadCache();
		$existing = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
		$internal = $this->_parseInternalObjectUri((string) $objectUri);
		$descriptionContext = $existing['_cdav_description'] ?? array();
		if ($descriptionContext) $descriptionContext['prefix_for_fields'] = array($this->cdavLib, 'getCalendarDescriptionPrefixForFields');
		$documentsUrl = (string) ($existing['_cdav_description']['documents_url'] ?? '');
		if ($documentsUrl !== '') {
			$existing['calendardata'] = \Dolibarr\CDav\CalendarWritePolicy::calendarWithoutDocumentsLink((string) $existing['calendardata'], $documentsUrl);
			$calendarData = \Dolibarr\CDav\CalendarWritePolicy::calendarWithoutDocumentsLink((string) $calendarData, $documentsUrl);
		}
		if (!$creating && $existing !== null && ($internal === null || $internal['source'] === 'ev')) {
			$protected = $this->_isProtectedEvent((int) $existing['id']);
			$externalImport = $this->_canImportExternalInvitation((int) $existing['id'], (int) $calendarId, (string) $existing['calendardata']);
			$reminders = null;
			$personalRemindersEnabled = !$externalImport && (int) $calendarId === (int) $this->user->id && getDolGlobalInt('CDAV_NATIVE_REMINDERS')
				&& getDolGlobalString('AGENDA_REMINDER_BROWSER') && $this->_reminderMappingTableAvailable();
			if ($protected && $personalRemindersEnabled) {
				list($calendarData, $reminders) = \Dolibarr\CDav\CalendarWritePolicy::personalReminderChange($existing['calendardata'], $calendarData);
			}
			try {
				$invitation = \Dolibarr\CDav\InvitationPolicy::plan((string) $existing['calendardata'], (string) $calendarData,
					(string) $this->user->email, $protected, $externalImport, $descriptionContext, true);
			} catch (Forbidden $exception) {
				$organizer = $this->_hasAgendaRight('allactions', 'write')
					? $this->_nativeOrganizerIdentity((int) $existing['id'], (string) $existing['calendardata']) : null;
				if ($organizer === null) throw $exception;
				$invitation = \Dolibarr\CDav\InvitationPolicy::plan((string) $existing['calendardata'], (string) $calendarData,
					$organizer['email'], $protected, false, $descriptionContext);
				if ($invitation !== null) $invitation['delegated_principal'] = $organizer['principal'];
			}
			if ($invitation !== null) {
				if (!$protected && $personalRemindersEnabled && !$invitation['actor_is_organizer']) {
					// Attendee comparisons ignore personal alarms even when shared business fields are editable.
					list(, $reminders) = \Dolibarr\CDav\CalendarWritePolicy::personalReminderChange($existing['calendardata'], $calendarData);
				}
				// RSVP clients often omit all personal alarms from their reply.
				if ($invitation['kind'] === 'reply' && $reminders === array()) $reminders = null;
				if ($reminders !== null) $this->_planNativeReminders((int) $existing['id'], (int) $calendarId, $reminders);
				if (in_array($invitation['kind'], array('reply', 'availability'), true) && (int) $calendarId !== (int) $this->user->id) {
					throw new Forbidden('A personal meeting change must be written in the authenticated participant calendar.');
				}
				if (!empty($invitation['changed']) && in_array($invitation['kind'], array('organizer', 'cancel'), true)) {
					$this->_assertAppointmentStatusAuthority((int) $existing['id']);
				}
				$data = $this->_parseData($invitation['calendar_data'], (int) $calendarId);
				return array('start' => (int) $data['start'], 'end' => (int) $data['end'], 'fullday' => (int) $data['fullday'],
					'_invitation_plan' => $invitation, '_personal_reminders' => $reminders);
			}
		}
		if (!$creating && $existing !== null && ($internal === null || $internal['source'] === 'ev')) {
			$before = VObject\Reader::read((string) $existing['calendardata']);
			$after = null;
			try {
				if ($protected && isset($before->VTODO)) {
					$plan = \Dolibarr\CDav\CalendarWritePolicy::planTaskChange((string) $existing['calendardata'], (string) $calendarData,
						$descriptionContext, $this->outlookSynchronizer);
					if ($plan['resized'] || array_diff_key($plan['fields'], array('transparency' => true))) {
						$this->_assertAppointmentStatusAuthority((int) $existing['id']);
					}
					return $plan['dates'] + array('_agenda_task_plan' => $plan);
				}
				if (isset($before->VEVENT)) {
					$after = VObject\Reader::read((string) $calendarData);
					if (!isset($after->VEVENT)) throw new Forbidden('An appointment must remain a VEVENT.');
					$oldStatus = \Dolibarr\CDav\CalendarWritePolicy::eventStatus($before->VEVENT);
					$newStatus = \Dolibarr\CDav\CalendarWritePolicy::normalizeAppointmentStatus($before->VEVENT, $after->VEVENT);
					if ($oldStatus !== $newStatus || $oldStatus === 'CANCELLED' || $protected) {
						$plan = \Dolibarr\CDav\CalendarWritePolicy::planStatusChange((string) $existing['calendardata'], (string) $calendarData, $descriptionContext);
						if ($oldStatus !== $newStatus || $plan['resized'] || array_diff_key($plan['fields'], array('transparency' => true))) {
							$this->_assertAppointmentStatusAuthority((int) $existing['id']);
						}
						if ($reminders !== null) $this->_planNativeReminders((int) $existing['id'], (int) $calendarId, $reminders);
						return $plan['dates'] + array('_appointment_status_plan' => $plan, '_personal_reminders' => $reminders);
					}
				}
			} finally {
				$before->destroy();
				if ($after !== null) $after->destroy();
			}
		}
		$incoming = null;
		if (isset($existing['_cdav_description'])) {
			$incoming = $this->_parseData((string) $calendarData, (int) $calendarId);
			$replacementPrefix = $this->cdavLib->getCalendarDescriptionPrefixForFields($existing['_cdav_description'], $incoming['label'], $incoming['location']);
			\Dolibarr\CDav\CalendarWritePolicy::nativeNoteForReplacement($existing['_cdav_description'], $incoming['description'], $replacementPrefix);
		}
		if ($existing !== null && ($internal === null || $internal['source'] === 'ev')) {
			if ($incoming === null) $incoming = $this->_parseData((string) $calendarData, (int) $calendarId);
			$this->_planNativeReminders((int) $existing['id'], (int) $calendarId, $incoming['native_reminders'] ?? array());
		}
		if ($creating && $existing === null) {
			$targetId = $this->_findActionCommByUri((string) $objectUri);
			if ($targetId > 0 && (!$this->_canAttachExistingObject((int) $calendarId, (string) $objectUri, 'ev', $targetId)
				|| $this->_isProtectedEvent($targetId))) {
				throw new Forbidden('A protected or inaccessible Dolibarr appointment cannot be assigned to another calendar through CalDAV.');
			}
		}
		if ($creating) {
			if ($incoming === null) $incoming = $this->_parseData((string) $calendarData, (int) $calendarId);
			$this->_assertCalendarUidAvailable((int) $calendarId, (string) $incoming['uid'], (int) ($existing['id'] ?? 0), (string) $objectUri);
		}
		if ($existing === null) {
			return false;
		}
		$internal = $this->_parseInternalObjectUri($objectUri);
		if (($internal !== null && $internal['source'] !== 'ev') || !$this->_isProtectedEvent((int) $existing['id'])) {
			return false;
		}
		if ($creating) {
			throw new Forbidden('A protected Dolibarr appointment cannot be assigned to another calendar through CalDAV.');
		}
		return \Dolibarr\CDav\CalendarWritePolicy::assertMoveOnly((string) $existing['calendardata'], (string) $calendarData);
	}

	/**
	 * Require native organizer authority before accepting a global state change.
	 * @param int $eventId Native Agenda identifier
	 * @return void
	 */
	private function _assertAppointmentStatusAuthority($eventId)
	{
		$result = $this->db->query('SELECT fk_user_action FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $eventId)
			.' AND entity IN ('.getEntity('agenda').')');
		if (!$result || !($row = $this->db->fetch_object($result))) throw new \Sabre\DAV\Exception\NotFound('Appointment not found.');
		if ((int) $row->fk_user_action !== (int) $this->user->id && !$this->_hasAgendaRight('allactions', 'write')) {
			throw new Forbidden('Only the native appointment owner or an authorized delegate may change its shared fields.');
		}
	}

	/**
	 * Apply a canonical standalone state/move plan under the existing native lock.
	 * @param int $eventId Native Agenda identifier
	 * @param int $calendarId Calendar owner
	 * @param array $plan Validated state and date changes
	 * @return void
	 */
	private function _applyAppointmentStatusPlan($eventId, $calendarId, array $plan)
	{
		if (empty($plan['changed'])) return;
		$data = array_replace($this->_parseData((string) $plan['calendar_data'], (int) $calendarId), $plan['dates']);
		$data['_preserve_native_dates'] = empty($plan['date_changed']);
		$data['_protected_fields'] = $plan['fields'];
		if (!$this->_schedulingTableAvailable()) throw new Forbidden('Reactivate CDav before updating protected appointments.');
		$result = $this->db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.((int) $eventId));
		if (!$result) throw new \Sabre\DAV\Exception('Unable to read the appointment scheduling state.');
		$row = $this->db->fetch_object($result);
		$metadata = \Dolibarr\CDav\CalendarWritePolicy::metadataAfterStatusChange($row ? (string) $row->calendardata : '', $plan);
		$this->_updateActionCommFromCalendarData((int) $eventId, $data, (int) $calendarId, true);
		$this->_storeCalendarMetadata((int) $eventId, $metadata);
	}

	/**
	 * Native and ambiguous historical objects are protected independently of URI mappings.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @return bool Whether native appointment protection applies
	 */
	private function _isProtectedEvent($eventId)
	{
		return \Dolibarr\CDav\BusinessPolicy::protectNativeEvents()
			&& !\Dolibarr\CDav\BusinessPolicy::isDavCreated($this->db, (int) $eventId);
	}

	/**
	 * External email imports may update only their DAV-created personal calendar.
	 * A native/shared appointment or a local organizer never inherits that exception.
	 *
	 * @param int $eventId Existing native appointment
	 * @param int $calendarId Calendar receiving the import
	 * @param string $calendarData Trusted current server representation
	 * @return bool Whether the authenticated owner can import organizer email updates
	 */
	private function _canImportExternalInvitation($eventId, $calendarId, $calendarData)
	{
		if ((int) $calendarId !== (int) $this->user->id || !\Dolibarr\CDav\BusinessPolicy::isDavCreated($this->db, (int) $eventId)) return false;
		$calendar = VObject\Reader::read((string) $calendarData);
		try {
			if (!isset($calendar->VEVENT->ORGANIZER)) return false;
			$email = strtolower(preg_replace('/^mailto:/i', '', trim((string) $calendar->VEVENT->ORGANIZER)));
			if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $email === strtolower(trim((string) $this->user->email))) return false;
		} finally {
			$calendar->destroy();
		}
		$result = $this->db->query('SELECT fk_user_action FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $eventId).' AND entity IN ('.getEntity('agenda').')');
		if (!$result || !($event = $this->db->fetch_object($result)) || (int) $event->fk_user_action !== (int) $this->user->id) return false;
		$result = $this->db->query('SELECT fk_element FROM '.MAIN_DB_PREFIX.'actioncomm_resources WHERE fk_actioncomm = '.((int) $eventId)." AND element_type = 'user'");
		if (!$result) throw new \Sabre\DAV\Exception('Unable to inspect the external invitation calendar scope.');
		$count = 0;
		while ($row = $this->db->fetch_object($result)) {
			if ((int) $row->fk_element !== (int) $this->user->id) return false;
			$count++;
		}
		if ($count !== 1) return false;
		$result = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE LOWER(email) = \''.$this->db->escape($email)
			.'\' AND statut > 0 AND fk_soc IS NULL AND entity IN ('.getEntity('user').') LIMIT 1');
		if (!$result) throw new \Sabre\DAV\Exception('Unable to distinguish the external invitation organizer.');
		return !$this->db->fetch_object($result);
	}

	/**
	 * Resolve an invitation organizer only when it is also the native Agenda owner.
	 * Native all-agenda rights may delegate this authority, never a client claim alone.
	 *
	 * @param int $eventId Readable existing appointment
	 * @param string $calendarData Trusted current calendar
	 * @return array|null Native owner identity bound to the organizer address
	 */
	private function _nativeOrganizerIdentity($eventId, $calendarData)
	{
		$calendar = VObject\Reader::read((string) $calendarData);
		try {
			if (!isset($calendar->VEVENT->ORGANIZER)) return null;
			$email = strtolower(preg_replace('/^mailto:/i', '', trim((string) $calendar->VEVENT->ORGANIZER)));
		} finally {
			$calendar->destroy();
		}
		$result = $this->db->query('SELECT u.rowid, u.login, u.email FROM '.MAIN_DB_PREFIX.'actioncomm a
			INNER JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = a.fk_user_action
			WHERE a.id = '.((int) $eventId).' AND a.entity IN ('.getEntity('agenda').') AND u.entity IN ('.getEntity('user').')
			AND u.statut > 0 AND u.fk_soc IS NULL');
		if (!$result) throw new \Sabre\DAV\Exception('Unable to resolve native organizer delegation.');
		$row = $this->db->fetch_object($result);
		return $row && $email !== '' && strtolower(trim((string) $row->email)) === $email
			? array('id' => (int) $row->rowid, 'email' => $email, 'principal' => 'principals/'.(string) $row->login) : null;
	}

	/**
	 * Read only the state actually committed by this request, avoiding stale notifications.
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $objectUri Resource basename
	 * @return array|null Locked before/after state, or null for unsupported sources
	 */
	public function getCommittedSchedulingChange($calendarId, $objectUri)
	{
		return $this->committedSchedulingChanges[((int) $calendarId).'\n'.(string) $objectUri] ?? null;
	}

	/**
	 * Persist a trusted scheduling revision in the same transaction as the date move.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param int $calendarId Calendar owner
	 * @param string $objectUri Resource basename
	 * @param array<string,int> $dates Validated new endpoints
	 * @return void
	 */
	private function _advanceProtectedCalendarSequence($eventId, $calendarId, $objectUri, array $dates)
	{
		$current = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
		$stored = '';
		if ($this->_schedulingTableAvailable()) {
			$result = $this->db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.((int) $eventId));
			if (!$result) {
				throw new \Sabre\DAV\Exception('Unable to read the original appointment metadata');
			}
			$row = $this->db->fetch_object($result);
			$stored = $row ? (string) $row->calendardata : '';
		}
		$updated = \Dolibarr\CDav\CalendarWritePolicy::metadataAfterMove($stored, (string) $current['calendardata'], $dates);
		if ($updated !== null) {
			if (!$this->_schedulingTableAvailable()) {
				throw new Forbidden('Reactivate CDav in Dolibarr before moving protected appointments.');
			}
			$this->_storeCalendarMetadata((int) $eventId, $updated);
		}
	}

	/**
	 * Resolve a protected calendar item before deferring its scheduling effects.
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $objectUri Resource basename
	 * @return array<string,mixed>|null Visible protected native event, otherwise null
	 */
	public function getProtectedCalendarObject($calendarId, $objectUri)
	{
		$internal = $this->_parseInternalObjectUri((string) $objectUri);
		if ($internal !== null && $internal['source'] !== 'ev') {
			return null;
		}
		$existing = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
		return $existing !== null && $this->_isProtectedEvent((int) $existing['id']) ? $existing : null;
	}

	/**
	 * Distinguish an actual event deletion from a shared-calendar detachment.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @return bool Whether the native business record still exists
	 */
	public function calendarEventExists($eventId)
	{
		$result = $this->db->query('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $eventId)
			.' AND entity IN ('.getEntity('agenda').')');
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to verify the committed appointment deletion');
		}
		return (bool) $this->db->fetch_object($result);
	}

	private function _resolveCalendarObject($calendarId, $objectUri)
	{
		$existing = $this->getCalendarObject($calendarId, $objectUri);
		if ($existing === null) {
			return null;
		}

		$internal = $this->_parseInternalObjectUri($objectUri);
		if ($internal !== null) {
			return (int) $existing['id'] === (int) $internal['id'] ? $internal : null;
		}
		return array('id' => (int) $existing['id'], 'source' => 'ev');
	}

	/**
	 * Resolve and optionally lock the native row before a transaction snapshot exists.
	 * The Agenda row is shared by every calendar owner and by Dolibarr's native UI;
	 * its lock prevents fetched business fields from overwriting concurrent edits.
	 *
	 * @param string $objectUri Native or external DAV resource basename
	 * @param bool $lock Whether to acquire the native row lock
	 * @param int $calendarId Optional owner scope for existing-resource writes
	 * @return int Existing native Agenda identifier, otherwise zero
	 */
	private function _findActionCommByUri($objectUri, $lock = false, $calendarId = 0)
	{
		$internal = $this->_parseInternalObjectUri((string) $objectUri);
		if ($internal !== null && $internal['source'] !== 'ev') {
			return 0;
		}
		$sql = 'SELECT a.id FROM '.MAIN_DB_PREFIX.'actioncomm a';
		if ($internal === null) {
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_cdav ac ON ac.fk_object = a.id';
		}
		if ((int) $calendarId > 0) {
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_resources ar ON ar.fk_actioncomm = a.id AND ar.element_type = \'user\' AND ar.fk_element = '.((int) $calendarId);
		}
		$uriMatch = 'ac.uuidext = \''.$this->db->escape((string) $objectUri).'\'';
		if ((int) $calendarId > 0 && $internal === null) {
			$uriMatch = '('.$uriMatch.' OR ac.sourceuid = \''.$this->db->escape((string) $objectUri).'\')';
		}
		$sql .= ' WHERE '.($internal !== null ? 'a.id = '.((int) $internal['id']) : $uriMatch)
			.' AND a.entity IN ('.getEntity('agenda').')';
		$sql .= ' ORDER BY a.id LIMIT 1'.($lock ? ' FOR UPDATE' : '');
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to resolve or lock the native appointment');
		}
		$row = $this->db->fetch_object($result);
		return $row ? (int) $row->id : 0;
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

	/**
	 * Persist the DAV identity that has no native ActionComm equivalent.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param string $objectUri Resource basename
	 * @param string $sourceUid Stable calendar UID
	 * @param bool $davCreated Whether this transaction created the native object
	 * @return void
	 */
	private function _upsertCalendarMapping($eventId, $objectUri, $sourceUid, $davCreated = false)
	{
		$uidLength = strlen($sourceUid);
		if ($uidLength > 255 && CalendarUid::storageCapacity($this->db) < $uidLength) {
			throw new \Sabre\DAV\Exception('Reactivate CDav in Dolibarr to upgrade storage for long calendar UIDs.');
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'actioncomm_cdav (fk_object, uuidext, sourceuid, dav_origin)
			VALUES ('.((int) $eventId).', \''.$this->db->escape((string) $objectUri).'\', \''.$this->db->escape((string) $sourceUid).'\', '.($davCreated ? 1 : 0).')
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
		$nativeUid = $this->_parseInternalObjectUri((string) $sourceUid);
		if ($nativeUid !== null && $nativeUid['source'] === 'ev' && (int) $nativeUid['id'] !== (int) $eventId) {
			$result = $this->db->query('SELECT id FROM '.MAIN_DB_PREFIX.'actioncomm WHERE id = '.((int) $nativeUid['id'])
				.' AND entity IN ('.getEntity('agenda').')');
			if (!$result) {
				throw new \Sabre\DAV\Exception('Unable to verify the native calendar identity');
			}
			if ($this->db->fetch_object($result)) {
				throw new \Sabre\DAV\Exception\Conflict('This UID already identifies a native Dolibarr appointment');
			}
		}
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
		$this->_disableCalendarReadCache();
		$lockResult = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE rowid = '.((int) $calendarId)
			.' AND statut > 0 AND fk_soc IS NULL AND entity IN ('.getEntity('user').') FOR UPDATE');
		if (!$lockResult || !$this->db->fetch_object($lockResult)) {
			throw new Forbidden('Calendar owner is not an active internal Dolibarr user');
		}
	}

	/**
	 * Lock the owner and native row before any repeatable-read object lookup.
	 * Both PUT and DELETE retain this transaction ordering independently of preflight.
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $objectUri Resource basename
	 * @return array<string,mixed> Resolved object protected by its native row lock
	 */
	private function _lockAndResolveCalendarObject($calendarId, $objectUri)
	{
		$this->_lockCalendarOwner((int) $calendarId);
		$lockedEventId = $this->_findActionCommByUri((string) $objectUri, true, (int) $calendarId);
		$resolved = $this->_resolveCalendarObject($calendarId, $objectUri);
		if ($resolved === null) throw new \Sabre\DAV\Exception\NotFound('Calendar object not found');
		if ($resolved['source'] === 'ev' && (int) $resolved['id'] !== $lockedEventId) {
			throw new \Sabre\DAV\Exception\PreconditionFailed('The native appointment identity changed while its row was being locked', 'If-Match');
		}
		return $resolved;
	}

	/**
	 * Couple a conditional or attachment-preserving write to the calendar version it read.
	 * The native user-row lock makes this check and the following ActionComm update atomic.
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $objectUri Resource basename
	 * @param string $etag Expected calendar representation hash
	 * @param bool $unlessSet Keep a pre-existing client precondition
	 * @return void
	 */
	public function expectCalendarVersion($calendarId, $objectUri, $etag, $unlessSet = false)
	{
		$this->_disableCalendarReadCache();
		$key = ((int) $calendarId).'\n'.(string) $objectUri;
		if (!$unlessSet || !isset($this->expectedCalendarEtags[$key])) $this->expectedCalendarEtags[$key] = (string) $etag;
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
				'The calendar object changed while its write was being prepared',
				'If-Match'
			);
		}
	}

	/**
	 * Map calendar fields before the existing native Task::update call.
	 * Only the VTODO representation owns progress; VEVENT preserves native progress.
	 *
	 * @param object $task Fetched native project task
	 * @param array<string,mixed> $data Validated calendar values
	 * @return void
	 */
	private function _applyProjectTaskData($task, array $data)
	{
		$task->label = (string) $data['label'];
		$task->date_start = (int) $data['start'];
		$task->date_end = max($task->date_start, (int) $data['end'] - (!empty($data['fullday']) ? 1 : 0));
		$task->priority = (int) $data['priority'];
		$task->description = (string) $data['note'];
		if ($data['elem_source'] === 'pt') $task->progress = (int) $data['percent'];
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
		$action->context['cdav_status_managed'] = 1;
		if ($data['componentType'] === 'VEVENT') {
			$action->array_options['options_cdav_status'] = \Dolibarr\CDav\AppointmentStatus::normalize($data['status']);
		}
		if (empty($action->userownerid)) {
			$action->userownerid = (int) $calendarId;
		}
		$nativeRecurrence = $data['native_recurrence'] ?? null;
		$action->recurid = is_array($nativeRecurrence) ? (string) $nativeRecurrence['id'] : '';
		$action->recurrule = is_array($nativeRecurrence) ? (string) $nativeRecurrence['rule'] : '';
		$action->recurdateend = is_array($nativeRecurrence) ? (int) $nativeRecurrence['until'] : '';
	}

	/**
	 * Apply a validated invitation change while retaining the locked native relations.
	 *
	 * @param int $eventId Locked native appointment
	 * @param int $calendarId Authenticated calendar owner
	 * @param array $plan Canonical invitation update
	 * @param array $createdFiles New attachment files rolled back by the caller
	 * @param array $removedFiles Attachment files removed after the caller commits
	 * @return void
	 */
	private function _applyInvitationPlan($eventId, $calendarId, array $plan, array &$createdFiles = array(), array &$removedFiles = array())
	{
		if (empty($plan['changed'])) return;
		if (!$this->_schedulingTableAvailable()) {
			throw new Forbidden('Reactivate CDav before recording invitation responses.');
		}
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$event = new \ActionComm($this->db);
		if ($event->fetch((int) $eventId) <= 0
			|| !in_array((int) $event->entity, array_map('intval', explode(',', getEntity('agenda'))), true)) {
			throw new \Sabre\DAV\Exception\NotFound('Meeting not found.');
		}
		$kind = (string) $plan['kind'];
		if ($kind === 'availability') {
			if ((int) $calendarId !== (int) $this->user->id || empty($event->userassigned[$calendarId])) {
				throw new Forbidden('Only the assigned participant can change their personal availability.');
			}
			// The native full event update deletes and recreates all assignments. Keep their identities and RSVP state.
			$result = $this->db->query('UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET transparency = '.((int) $plan['personal_transparency'])
				.' WHERE fk_actioncomm = '.((int) $eventId)." AND element_type = 'user' AND fk_element = ".((int) $calendarId));
			if (!$result) throw new \Sabre\DAV\Exception('Unable to update the participant availability.');
			return;
		}
		$canonical = (string) $plan['calendar_data'];
		$protected = $this->_isProtectedEvent((int) $eventId);
		$event->context['cdav_status_managed'] = 1;
		$event->array_options['options_cdav_status'] = \Dolibarr\CDav\AppointmentStatus::statusFromCalendar($canonical);
		if (in_array($kind, array('organizer', 'cancel'), true) && (int) $event->userownerid !== (int) $this->user->id
			&& !$this->_hasAgendaRight('allactions', 'write')) {
			throw new Forbidden('Only the native meeting owner may apply organizer updates or cancellations.');
		}
		if ($kind === 'reply') {
			if ((int) $calendarId !== (int) $this->user->id || empty($event->userassigned[(int) $calendarId])) {
				throw new Forbidden('Only an assigned participant can respond to this invitation.');
			}
			$event->userassigned[(int) $calendarId]['answer_status'] = (string) $plan['partstat'];
			$event->userassigned[(int) $calendarId]['transparency'] = (int) $plan['personal_transparency'];
			if ($event->update($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to record the native meeting response: '.$event->error);
			}
			$this->_restoreActionCommResourceMetadata((int) $eventId, $event->userassigned, $event->socpeopleassigned);
		} elseif ($kind === 'cancel') {
			\Dolibarr\CDav\AppointmentStatus::clearOccupancy($event);
			if ($event->update($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to cancel the native meeting: '.$event->error);
			}
			$this->_restoreActionCommResourceMetadata((int) $eventId, $event->userassigned, $event->socpeopleassigned);
			$this->_cancelPendingMeetingReminders((int) $eventId);
		} elseif ($kind === 'organizer') {
			$data = $this->_parseData($canonical, (int) $calendarId);
			$data['_preserve_native_dates'] = empty($plan['date_changed']);
			if ($protected) {
				$data['_protected_fields'] = $plan['fields'] ?? array();
				$data = array_replace($data, $plan['dates'] ?? array());
			} else {
				$current = $this->getCalendarObject((int) $calendarId, ((int) $eventId).'-ev-'.CDAV_URI_KEY);
				if (isset($current['_cdav_description'])) {
					$prefix = $this->cdavLib->getCalendarDescriptionPrefixForFields($current['_cdav_description'], $data['label'], $data['location']);
					$data['note'] = \Dolibarr\CDav\CalendarWritePolicy::nativeNoteForReplacement($current['_cdav_description'], $data['description'], $prefix);
					$data['_native_note_resolved'] = true;
				}
			}
			if (!$protected && getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
				$this->managedAttachmentStore->synchronizeCalendarData((int) $eventId, $canonical, false);
			}
			$event = $this->_updateActionCommFromCalendarData((int) $eventId, $data, (int) $calendarId, $protected, true);
			if (!$protected) {
				$canonical = $this->managedAttachmentStore->importInlineCalendarData((int) $eventId, $canonical, $createdFiles);
				if (getDolGlobalInt('CDAV_MANAGED_ATTACHMENTS')) {
					$removedFiles = array_merge($removedFiles, $this->managedAttachmentStore->synchronizeCalendarData((int) $eventId, $canonical, true, true));
				}
				$this->_syncActionCommAttachmentLinks((int) $eventId, $data['attachment_links']);
			}
		} else {
			throw new Forbidden('Unsupported invitation change.');
		}
		if ($protected) {
			$result = $this->db->query('SELECT calendardata FROM '.MAIN_DB_PREFIX.'cdav_scheduling WHERE fk_actioncomm = '.((int) $eventId));
			if (!$result) throw new \Sabre\DAV\Exception('Unable to read shared meeting metadata.');
			$row = $this->db->fetch_object($result);
			$canonical = \Dolibarr\CDav\InvitationPolicy::nativeMetadata($plan, $row ? (string) $row->calendardata : '');
		}
		$this->_storeCalendarMetadata((int) $eventId, $canonical);
	}

	/**
	 * Remove pending reminders for a cancelled meeting through their native API.
	 * Historical sent and failed rows are retained and never rearmed.
	 *
	 * @param int $eventId Cancelled native appointment
	 * @return void
	 */
	private function _cancelPendingMeetingReminders($eventId)
	{
		\Dolibarr\CDav\AppointmentStatus::cancelPendingReminders($this->db, (int) $eventId, $this->user);
	}

	/**
	 * Update an existing Dolibarr event through ActionComm and its resource API.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param array $data Parsed calendar values
	 * @param int $calendarId Calendar owner
	 * @param bool $protectedMove Whether the validated protected-field plan governs the native update
	 * @param bool $preserveInvitationResponses Whether existing personal resources remain authoritative
	 * @return \ActionComm Updated native appointment
	 */
	private function _updateActionCommFromCalendarData($eventId, array $data, $calendarId, $protectedMove = false, $preserveInvitationResponses = false)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$event = new \ActionComm($this->db);
		if ($event->fetch((int) $eventId) <= 0
			|| !in_array((int) $event->entity, array_map('intval', explode(',', getEntity('agenda'))), true)) {
			throw new \Sabre\DAV\Exception\NotFound('Dolibarr event not found');
		}
		if (!empty($data['_preserve_native_dates'])) {
			// A state change must not reinterpret native all-day dates as UTC or
			// expand a zero-duration native event through the import defaults.
			$data['start'] = (int) $event->datep;
			$data['end'] = (int) $event->datef + (!empty($event->fulldayevent) ? 1 : 0);
			$data['fullday'] = (int) $event->fulldayevent;
		}
		if ($protectedMove) {
			// Only apply validated calendar fields to the freshly loaded native relationships.
			$fields = $data['_protected_fields'] ?? array();
			foreach (array('label', 'location', 'priority', 'percentage') as $field) {
				if (isset($fields[$field])) $event->{$field} = $fields[$field];
			}
			if (isset($fields['note'])) $event->note = $event->note_private = $fields['note'];
			if (isset($fields['transparency'])) {
				if (empty($event->userassigned[$calendarId])) throw new Forbidden('This calendar is no longer assigned to the appointment.');
				if ($fields['transparency'] && strtoupper((string) ($event->userassigned[$calendarId]['answer_status'] ?? '')) === 'DECLINED') {
					throw new Forbidden('A declined invitation must remain free on the participant calendar.');
				}
				$event->userassigned[$calendarId]['transparency'] = $fields['transparency'];
				if ((int) $event->userownerid === (int) $calendarId) $event->transparency = $fields['transparency'];
			}
			$previousStart = (int) $event->datep;
			$event->context['cdav_status_managed'] = 1;
			if ($data['componentType'] === 'VEVENT') {
				$event->array_options['options_cdav_status'] = \Dolibarr\CDav\AppointmentStatus::normalize($data['status']);
				if ($data['status'] === 'CANCELLED') \Dolibarr\CDav\AppointmentStatus::clearOccupancy($event);
			}
			if (empty($data['_preserve_native_dates'])) {
				$event->datep = (int) $data['start'];
				$event->datef = !empty($data['fullday']) && $data['componentType'] === 'VEVENT' ? (int) $data['end'] - 1 : (int) $data['end'];
				if (!empty($data['task_without_due'])) $event->datef = null;
				$event->fulldayevent = (int) $data['fullday'];
			}
			if ($event->update($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to update the protected Dolibarr appointment: '.$event->error);
			}
			if ($previousStart !== (int) $event->datep) {
				$this->_moveNativeReminders($event, (int) $event->datep);
			}
			$this->_restoreActionCommResourceMetadata((int) $event->id, $event->userassigned, $event->socpeopleassigned);
			if ($data['status'] === 'CANCELLED') $this->_cancelPendingMeetingReminders((int) $event->id);
			return $event;
		}
		$nativeAssignments = $event->userassigned;
		$previousTransparency = (int) $event->transparency;
		$previousStart = (int) $event->datep;
		$reminderPlan = $this->_planNativeReminders((int) $eventId, (int) $calendarId, $data['native_reminders'] ?? array(), true);
		if (empty($data['_native_note_resolved'])) {
			// CREATE may attach an existing DAV-created event to a different calendar.
			// Its source calendar still supplies the trusted description envelope.
			$sourceCalendar = !empty($event->userassigned[(int) $calendarId]) ? (int) $calendarId : (int) $event->userownerid;
			$current = $this->getCalendarObject($sourceCalendar, ((int) $eventId).'-ev-'.CDAV_URI_KEY);
			if (isset($current['_cdav_description'])) {
				$replacementPrefix = $this->cdavLib->getCalendarDescriptionPrefixForFields($current['_cdav_description'], $data['label'], $data['location']);
				$data['note'] = \Dolibarr\CDav\CalendarWritePolicy::nativeNoteForReplacement($current['_cdav_description'], $data['description'], $replacementPrefix);
			}
		}
		$actionType = $this->_getActionType($data['componentType']);
		$this->_applyActionCommData($event, $data, $data['start'], $data['end'], $calendarId, $actionType);
		if ($data['has_participants']) {
			$canManageAllUsers = $this->_hasAgendaRight('allactions', 'write');
			list($resolvedUsers) = $this->_resolveParticipantResources(
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
			// ATTENDEE is protocol metadata, never authority to reassign a native
			// customer or replace contact relationships of an existing appointment.
		}
		if (empty($event->userassigned[(int) $calendarId])) {
			$event->userassigned[(int) $calendarId] = array('id' => (int) $calendarId);
		}
		$ownerTransparency = isset($nativeAssignments[(int) $calendarId]['transparency'])
			? (int) $nativeAssignments[(int) $calendarId]['transparency'] : $previousTransparency;
		$transparencyChanged = $ownerTransparency !== (int) $data['transparency'];
		if ($preserveInvitationResponses && !$transparencyChanged) $event->transparency = $previousTransparency;
		foreach ($event->userassigned as $id => &$assignment) {
			if (is_array($assignment)) {
				$previous = $nativeAssignments[$id] ?? null;
				if ($preserveInvitationResponses && is_array($previous)) {
					$assignment['mandatory'] = (int) ($previous['mandatory'] ?? 0);
					if ((int) $id !== (int) $calendarId) $assignment['answer_status'] = (string) ($previous['answer_status'] ?? '');
				}
				$assignment['transparency'] = $preserveInvitationResponses && !$transparencyChanged && is_array($previous)
					? (int) ($previous['transparency'] ?? $previousTransparency) : (int) $data['transparency'];
				if (strtoupper((string) ($assignment['answer_status'] ?? '')) === 'DECLINED') $assignment['transparency'] = 0;
			}
		}
		unset($assignment);
		$event->userassigned[(int) $calendarId]['answer_status'] = (string) $data['answer_status'];
		if (empty($event->userassigned[(int) $event->userownerid])) {
			$ownerId = (int) $event->userownerid;
			if ($ownerId > 0) {
				$event->userassigned[$ownerId] = $nativeAssignments[$ownerId] ?? array('id' => $ownerId);
			}
		}
		if ($event->update($this->user) <= 0) {
			throw new \Sabre\DAV\Exception('Unable to update the Dolibarr event: '.$event->error);
		}
		if ($previousStart !== (int) $event->datep) {
			$this->_moveNativeReminders($event, (int) $event->datep);
		}
		$this->_syncNativeRecurrence((int) $event->id, $data);
		$this->_syncNativeReminders((int) $event->id, (int) $calendarId, $data['native_reminders'] ?? array(), $reminderPlan);
		$this->_restoreActionCommResourceMetadata((int) $event->id, $event->userassigned, $event->socpeopleassigned);
		return $event;
	}

	/**
	 * Move existing pending relative reminders through their native business API.
	 * loadReminders() omits disabled reminder types, so the bounded ID query bridges
	 * that API gap. Disabled reminders retain their type/status; sent or failed
	 * reminders and absolute iCalendar alarms remain untouched.
	 *
	 * @param \ActionComm $event Fetched and updated native appointment
	 * @param int $start Committed-in-transaction new appointment start
	 * @return void
	 */
	private function _moveNativeReminders($event, $start)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
		$result = $this->db->query('SELECT * FROM '.MAIN_DB_PREFIX.'actioncomm_reminder WHERE fk_actioncomm = '.((int) $event->id)
			.' AND status = 0 AND offsetunit IN (\'y\', \'m\', \'w\', \'d\', \'h\', \'i\') ORDER BY rowid FOR UPDATE');
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to lock the existing appointment reminders');
		}
		$rows = array();
		while ($row = $this->db->fetch_object($result)) {
			$rows[] = $row;
		}
		foreach ($rows as $row) {
			$reminder = new \ActionCommReminder($this->db);
			if ($reminder->fetch((int) $row->rowid) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to load the existing appointment reminder');
			}
			// fetchCommon() uses a snapshot read. Retain the current locked fields
			// if a native reminder editor changed them after this transaction began.
			foreach (array('entity', 'typeremind', 'fk_user', 'offsetvalue', 'offsetunit', 'status', 'lasterror', 'fk_actioncomm', 'fk_email_template') as $field) {
				$reminder->{$field} = $row->{$field};
			}
			if ($row->fk_user === null) {
				// Dolibarr 24 allows contact/company recipients while the bundled
				// reminder class still declares fk_user mandatory. Preserve SQL NULL.
				$reminder->fields['fk_user']['notnull'] = 0;
			}
			$reminder->dateremind = dol_time_plus_duree((int) $start, -1 * (int) $reminder->offsetvalue, (string) $reminder->offsetunit);
			if ($reminder->update($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to move the existing appointment reminder: '.$reminder->error);
			}
		}
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
					if (!function_exists('checkUserAccessToObject')) {
						require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
					}
					if (!\checkUserAccessToObject($this->user, array('contact'), $id, 'socpeople&contact')) {
						// Keep the external ATTENDEE, but never derive inaccessible
						// native contact/company relationships from a known email.
						continue;
					}
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

	/**
	 * Bridge an ActionComm API gap: 23/24 cast user PARTSTAT to integers and
	 * recreate contact resources with zeroed metadata. Restore only the native
	 * resource rows already persisted through the business API.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param array $userAssignments Native user assignment metadata
	 * @param array $contactAssignments Native contact assignment metadata
	 * @return void
	 */
	private function _restoreActionCommResourceMetadata($eventId, array $userAssignments, array $contactAssignments = array())
	{
		foreach (array('user' => $userAssignments, 'socpeople' => $contactAssignments) as $type => $assignments) {
			foreach ($assignments as $assignment) {
				if (!is_array($assignment) || empty($assignment['id'])) {
					continue;
				}
				$status = isset($assignment['answer_status']) ? '\''.$this->db->escape((string) $assignment['answer_status']).'\'' : 'NULL';
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'actioncomm_resources SET mandatory = '.((int) ($assignment['mandatory'] ?? 0))
					.', transparency = '.((int) ($assignment['transparency'] ?? 0)).', answer_status = '.$status
					.' WHERE fk_actioncomm = '.((int) $eventId).' AND element_type = \''.$type.'\' AND fk_element = '.((int) $assignment['id']);
				if (!$this->db->query($sql)) {
					throw new \Sabre\DAV\Exception('Unable to preserve native appointment resource metadata');
				}
			}
		}
	}

	/**
	 * Translate attendee removal into a decline, keeping organizer cancellation distinct.
	 *
	 * @param int $eventId Locked native appointment
	 * @param int $calendarId Calendar being removed
	 * @param string $currentData Locked original calendar representation
	 * @return bool Whether the authenticated organizer is cancelling the whole meeting
	 */
	private function _prepareInvitationRemoval($eventId, $calendarId, $currentData)
	{
		if ((int) $calendarId !== (int) $this->user->id) {
			$organizer = $this->_hasAgendaRight('allactions', 'delete') ? $this->_nativeOrganizerIdentity((int) $eventId, $currentData) : null;
			return $organizer !== null && (int) $organizer['id'] === (int) $calendarId;
		}
		$calendar = VObject\Reader::read((string) $currentData);
		try {
			$item = $calendar->VEVENT;
			if ($item === null || !isset($item->ORGANIZER) || count($item->select('ATTENDEE')) === 0) return false;
			$email = strtolower(trim((string) $this->user->email));
			$organizer = strtolower(preg_replace('/^mailto:/i', '', trim((string) $item->ORGANIZER)));
			if ($email !== '' && hash_equals($organizer, $email)) return true;
			if (isset($item->STATUS) && strtoupper((string) $item->STATUS) === 'CANCELLED') return false;
			$responding = false;
			foreach ($item->select('ATTENDEE') as $attendee) {
				if (strtolower(preg_replace('/^mailto:/i', '', trim((string) $attendee))) === $email) {
					$attendee['PARTSTAT'] = 'DECLINED';
					$attendee['RSVP'] = 'FALSE';
					$responding = true;
				}
			}
			if (!$responding) return false;
			$plan = \Dolibarr\CDav\InvitationPolicy::plan((string) $currentData, $calendar->serialize(), $email, $this->_isProtectedEvent((int) $eventId));
			if ($plan !== null) $this->_applyInvitationPlan((int) $eventId, (int) $calendarId, $plan);
			return false;
		} finally {
			$calendar->destroy();
		}
	}

	/**
	 * Remove one calendar assignment and use ActionComm for the business write.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param int $calendarId Calendar owner
	 * @param array $deferredFiles Physical files to remove only after transaction commit
	 * @param bool $cancelAsOrganizer Whether the authenticated native owner cancels the shared invitation
	 * @return void
	 */
	private function _detachActionCommFromCalendar($eventId, $calendarId, array &$deferredFiles = array(), $cancelAsOrganizer = false)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$event = new \ActionComm($this->db);
		if ($event->fetch((int) $eventId) <= 0) {
			throw new \Sabre\DAV\Exception\NotFound('Dolibarr event not found');
		}
		if ($cancelAsOrganizer && (int) $event->userownerid !== (int) $this->user->id
			&& !$this->_hasAgendaRight('allactions', 'delete')) {
			throw new Forbidden('Only the native meeting owner may cancel it for every participant.');
		}
		$remainingAssignments = array();
		foreach ($event->userassigned as $key => $assignment) {
			$id = is_array($assignment) ? (int) ($assignment['id'] ?? $key) : (int) $assignment;
			if (!$cancelAsOrganizer && $id > 0 && $id !== (int) $calendarId) {
				$remainingAssignments[$id] = is_array($assignment) ? $assignment : array('id' => $id);
			}
		}
		$this->_deleteNativeReminders((int) $eventId, $cancelAsOrganizer ? 0 : (int) $calendarId);
		if (!$remainingAssignments) {
			$deferredFiles = array_merge($deferredFiles, $this->managedAttachmentStore->removeAllForEvent((int) $eventId, true));
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
		if ($remainingAssignments && empty($remainingAssignments[(int) $event->userownerid])) {
			$remainingIds = array_keys($remainingAssignments);
			$event->userownerid = (int) $remainingIds[0];
		} elseif (!$remainingAssignments) {
			$event->userownerid = max(0, (int) $event->authorid);
		}
		if ($event->update($this->user) <= 0) {
			throw new \Sabre\DAV\Exception('Unable to detach the Dolibarr event: '.$event->error);
		}
		$this->_restoreActionCommResourceMetadata((int) $eventId, $remainingAssignments, $event->socpeopleassigned);
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

	/**
	 * Read the reminder plan before scheduling and recheck it under the native lock.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param int $calendarId Browser-reminder recipient
	 * @param array $reminders Requested supported relative DISPLAY alarms
	 * @param bool $lock Whether the enclosing native transaction acquires row locks
	 * @return array<string,array> Native IDs to delete/preserve and alarms to create
	 */
	private function _planNativeReminders($eventId, $calendarId, array $reminders, $lock = false)
	{
		if (!getDolGlobalInt('CDAV_NATIVE_REMINDERS') || !getDolGlobalString('AGENDA_REMINDER_BROWSER')
			|| !$this->_reminderMappingTableAvailable()) {
			return array('delete' => array(), 'preserve' => array(), 'create' => array());
		}
		$result = $this->db->query('SELECT r.*, mapping.fk_reminder AS cdav_reminder_id FROM '.MAIN_DB_PREFIX.'actioncomm_reminder r LEFT JOIN '.MAIN_DB_PREFIX.'cdav_reminder mapping ON mapping.fk_reminder = r.rowid AND mapping.fk_actioncomm = r.fk_actioncomm AND mapping.fk_user = r.fk_user WHERE r.fk_actioncomm = '.((int) $eventId).' AND r.fk_user = '.((int) $calendarId)
			.' AND r.typeremind = \'browser\' ORDER BY r.rowid'.($lock ? ' FOR UPDATE' : ''));
		if (!$result) {
			throw new \Sabre\DAV\Exception('Unable to inspect native appointment reminder ownership');
		}
		$rows = array();
		while ($row = $this->db->fetch_object($result)) {
			$rows[] = (array) $row;
		}
		return \Dolibarr\CDav\CalendarWritePolicy::planReminders($rows, array_slice($reminders, 0, 10), (int) $calendarId);
	}

	/**
	 * Preserve native and unchanged DAV alarms; create only genuinely new reminders.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param int $calendarId Browser-reminder recipient
	 * @param array $reminders Requested supported relative DISPLAY alarms
	 * @param array|null $plan Already locked reminder plan for an existing appointment
	 * @return void
	 */
	private function _syncNativeReminders($eventId, $calendarId, array $reminders, $plan = null)
	{
		if ($plan === null) $plan = $this->_planNativeReminders((int) $eventId, (int) $calendarId, $reminders, true);
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
		foreach ($plan['delete'] as $id) {
			$reminder = new \ActionCommReminder($this->db);
			if ($reminder->fetch((int) $id) <= 0 || $reminder->delete($this->user) <= 0) {
				throw new \Sabre\DAV\Exception('Unable to remove a DAV-owned native reminder');
			}
			if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'cdav_reminder WHERE fk_actioncomm = '.((int) $eventId)
				.' AND fk_user = '.((int) $calendarId).' AND fk_reminder = '.((int) $id))) {
				throw new \Sabre\DAV\Exception('Unable to remove DAV reminder provenance');
			}
		}
		foreach ($plan['create'] as $data) {
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

	/**
	 * Remove only scoped DAV-owned browser reminders, even if projection is disabled.
	 *
	 * @param int $eventId Native Agenda identifier
	 * @param int $calendarId Optional recipient scope
	 * @return void
	 */
	private function _deleteNativeReminders($eventId, $calendarId = 0)
	{
		if (!$this->_reminderMappingTableAvailable()) return;
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncommreminder.class.php';
		$where = ' WHERE fk_actioncomm = '.((int) $eventId);
		if ($calendarId > 0) $where .= ' AND fk_user = '.((int) $calendarId);
		$trackedWhere = ' WHERE tracked.fk_actioncomm = '.((int) $eventId);
		if ($calendarId > 0) $trackedWhere .= ' AND tracked.fk_user = '.((int) $calendarId);
		$result = $this->db->query('SELECT reminder.* FROM '.MAIN_DB_PREFIX.'cdav_reminder tracked INNER JOIN '.MAIN_DB_PREFIX.'actioncomm_reminder reminder ON reminder.rowid = tracked.fk_reminder AND reminder.fk_actioncomm = tracked.fk_actioncomm AND reminder.fk_user = tracked.fk_user'.$trackedWhere.' FOR UPDATE');
		if (!$result) throw new \Sabre\DAV\Exception('Unable to inspect DAV-owned reminders');
		while ($row = $this->db->fetch_object($result)) {
			if ((string) $row->typeremind !== 'browser' || !empty($row->fk_soc) || !empty($row->fk_contact)) continue;
			$reminder = new \ActionCommReminder($this->db);
			if ($reminder->fetch((int) $row->rowid) > 0 && $reminder->delete($this->user) <= 0) {
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
		foreach (\cdav_attachment_base_urls('agenda-documents') as $davBase) {
			if (str_starts_with((string) $url, $davBase) && preg_match('#^\d+/[^/]+$#D', substr((string) $url, strlen($davBase)))) {
				return true;
			}
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
		$calendarData = \Dolibarr\CDav\CalendarWritePolicy::calendarWithoutDocumentsLink((string) $calendarData, $this->cdavLib->actionCommDocumentsUrl((int) $eventId));
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
	 *
	 * @param int $calendarId Calendar owner
	 * @param string $sourceUid Stable calendar UID
	 * @param int $keepId Native appointment to retain
	 * @param array $deferredFiles Physical files retained until transaction commit
	 * @return void
	 */
	private function _removeAdditionalSeriesObjects($calendarId, $sourceUid, $keepId, array &$deferredFiles = array())
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
			if ($this->_isProtectedEvent($eventId)) {
				throw new Forbidden('A legacy series cleanup cannot remove protected Dolibarr appointments.');
			}
			$this->_detachActionCommFromCalendar($eventId, (int) $calendarId, $deferredFiles);
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
		foreach ($component->select('VALARM') as $alarm) {
			$reminder = \Dolibarr\CDav\CalendarWritePolicy::nativeReminder($alarm, (int) $start);
			if ($reminder === null) continue;
			$reminders[$reminder['value'].$reminder['unit']] = $reminder;
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
	 * @param int|null $calendarId Calendar used to resolve native identities
	 * @param bool $submitted Validate and normalize invitation attendees for a client write
	 * @return array
	 */
	protected function _parseData($calendarData, $calendarId = null, bool $submitted = false) {

		debug_log('_parseData('.strlen((string) $calendarData).' bytes)');
		$vObject = $this->_readCalendarData($calendarData);
		$normalizedInvitation = false;
		if ($submitted) {
			foreach ($vObject->select('VEVENT') as $event) {
				if (!isset($event->ORGANIZER)) continue;
				$count = count($event->select('ATTENDEE'));
				if (count(\Dolibarr\CDav\InvitationPolicy::indexAttendees($event)) < $count) $normalizedInvitation = true;
			}
		}
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
		$uid = CalendarUid::read($component);
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
				$endDate = $endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
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
		$location = isset($component->LOCATION)
			? trim(str_replace(array("\r", "\t", "\n"), ' ', (string) $component->LOCATION))
			: '';
		$priority = max(0, min(9, (int) (isset($component->PRIORITY) ? (string) $component->PRIORITY : 5)));
		$icalTransparency = isset($component->TRANSP) ? strtoupper(trim((string) $component->TRANSP)) : 'OPAQUE';
		$transparency = ($componentType === 'VEVENT' && $icalTransparency !== 'TRANSPARENT') ? 1 : 0;
		$status = $componentType === 'VEVENT' ? \Dolibarr\CDav\AppointmentStatus::componentStatus($component)
			: (isset($component->STATUS) ? strtoupper(trim((string) $component->STATUS)) : '');

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
			'description' => $description,
			'percent' => $percent,
			'status' => $status,
			'has_explicit_status' => isset($component->STATUS),
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
		if ($normalizedInvitation) $ret['normalized_invitation'] = $vObject->serialize();
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
		$this->_disableCalendarReadCache();

		debug_log("deleteCalendarObject( $calendarId , $objectUri) ");
		$this->_validateCalendarObjectUri($objectUri);

		if (!$this->_canDeleteFromCalendar($calendarId)) {
			throw new Forbidden('Not allowed to delete from this calendar');
		}

		if (!$this->db->begin()) {
			throw new \Sabre\DAV\Exception('Unable to start the CalDAV delete transaction');
		}
		$removedAttachments = array();
		try {
			$resolvedObject = $this->_lockAndResolveCalendarObject((int) $calendarId, (string) $objectUri);
			$oid = (int) $resolvedObject['id'];
			$source = (string) $resolvedObject['source'];
			$this->_assertCalendarVersion((int) $calendarId, (string) $objectUri);
			if (!$this->_canModifySource($source)) {
				throw new Forbidden('Not allowed to modify this type of Dolibarr object');
			}
			if ($source === 'ev') {
				$current = $this->getCalendarObject((int) $calendarId, (string) $objectUri);
				$cancelAsOrganizer = $this->_prepareInvitationRemoval($oid, (int) $calendarId, (string) $current['calendardata']);
				$organizerIdentity = $cancelAsOrganizer ? $this->_nativeOrganizerIdentity($oid, (string) $current['calendardata']) : null;
				$this->_detachActionCommFromCalendar($oid, (int) $calendarId, $removedAttachments, $cancelAsOrganizer);
				if (!$this->db->commit()) {
					throw new \Sabre\DAV\Exception('Unable to commit the CalDAV event deletion');
				}
				$this->committedSchedulingChanges[((int) $calendarId).'\n'.(string) $objectUri] = array(
					'id' => $oid, 'previous_data' => (string) $current['calendardata'], 'canonical_data' => null, 'changed' => true,
					'owner_principal' => $organizerIdentity['principal'] ?? 'principals/'.(string) $this->user->login);
				$this->managedAttachmentStore->discardCreatedFiles($removedAttachments);
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
		ksort($objects, SORT_STRING);
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

	/**
	 * Apply a local authenticated iTIP REPLY to an existing assigned appointment.
	 * The caller emits notifications only after this transaction has committed.
	 *
	 * @param string $senderPrincipal Authenticated scheduling outbox owner
	 * @param string $calendarData Single VEVENT REPLY, possibly with partial fields
	 * @return array Committed canonical data and local notification recipients
	 */
	public function processSchedulingReply($senderPrincipal, $calendarData)
	{
		$this->_disableCalendarReadCache();
		if (!getDolGlobalInt('CDAV_SCHEDULING') || !$this->_canAccessSchedulingPrincipal($senderPrincipal)
			|| !$this->_canWriteCalendar((int) $this->user->id)) {
			throw new Forbidden('Not allowed to answer from this scheduling outbox.');
		}
		$reply = VObject\Reader::read($this->_readSchedulingData($calendarData));
		try {
			$item = $reply->VEVENT;
			if (strtoupper((string) $reply->METHOD) !== 'REPLY' || count($reply->select('METHOD')) !== 1
				|| $item === null || isset($item->{'RECURRENCE-ID'}) || count($item->select('ATTENDEE')) !== 1
				|| count($item->select('UID')) !== 1 || count($item->select('ORGANIZER')) !== 1) {
				throw new \Sabre\DAV\Exception\BadRequest('A reply must identify one meeting, organizer and authenticated participant.');
			}
			foreach (array('SEQUENCE', 'DTSTART', 'DTEND') as $field) {
				if (count($item->select($field)) > 1) throw new \Sabre\DAV\Exception\BadRequest('A reply contains ambiguous revision or date fields.');
			}
			$email = strtolower(trim((string) $this->user->email));
			$attendee = $item->select('ATTENDEE')[0];
			$sender = strtolower(preg_replace('/^mailto:/i', '', trim((string) $attendee)));
			$organizer = strtolower(preg_replace('/^mailto:/i', '', trim((string) $item->ORGANIZER)));
			if ($email === '' || !hash_equals($email, $sender) || !filter_var($organizer, FILTER_VALIDATE_EMAIL)) {
				throw new Forbidden('The reply participant does not match the authenticated user.');
			}
			$path = $this->getCalendarObjectByUID($senderPrincipal, (string) $item->UID);
			if ($path === null) throw new \Sabre\DAV\Exception\NotFound('No assigned meeting has this invitation identity.');
			list(, $uri) = \Sabre\Uri\split($path);
			if (!$this->db->begin()) throw new \Sabre\DAV\Exception('Unable to start the invitation response transaction.');
			try {
				$this->_lockCalendarOwner((int) $this->user->id);
				$id = $this->_findActionCommByUri($uri, true, (int) $this->user->id);
				$current = $this->getCalendarObject((int) $this->user->id, $uri);
				if ($id <= 0 || $current === null || (int) $current['id'] !== $id) {
					throw new \Sabre\DAV\Exception\NotFound('The assigned meeting no longer exists.');
				}
				$calendar = VObject\Reader::read((string) $current['calendardata']);
				try {
					$existing = $calendar->VEVENT;
					if ($existing === null || count($calendar->select('VEVENT')) !== 1 || isset($existing->{'RECURRENCE-ID'})
						|| !isset($existing->ORGANIZER) || (string) $existing->UID !== (string) $item->UID
						|| strtolower(preg_replace('/^mailto:/i', '', trim((string) $existing->ORGANIZER))) !== $organizer) {
						throw new Forbidden('The reply does not match this meeting organizer and identity.');
					}
					$sequence = isset($existing->SEQUENCE) ? (int) (string) $existing->SEQUENCE : 0;
					$replySequence = isset($item->SEQUENCE) ? (string) $item->SEQUENCE : '0';
					if (!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $replySequence) || (int) $replySequence !== $sequence) {
						throw new \Sabre\DAV\Exception\PreconditionFailed('The invitation revision changed before this reply.');
					}
					foreach (array('DTSTART', 'DTEND') as $date) {
						if (isset($item->{$date}) && (!isset($existing->{$date})
							|| $item->{$date}->getDateTime()->getTimestamp() !== $existing->{$date}->getDateTime()->getTimestamp())) {
							throw new \Sabre\DAV\Exception\PreconditionFailed('The reply refers to a different meeting date.');
						}
					}
					$matched = false;
					foreach ($existing->select('ATTENDEE') as $participant) {
						if (strtolower(preg_replace('/^mailto:/i', '', trim((string) $participant))) !== $email) continue;
						$participant['PARTSTAT'] = isset($attendee['PARTSTAT']) ? (string) $attendee['PARTSTAT'] : 'NEEDS-ACTION';
						if (isset($attendee['RSVP'])) $participant['RSVP'] = (string) $attendee['RSVP'];
						$matched = true;
					}
					if (!$matched) throw new Forbidden('The authenticated participant is absent from the invitation.');
					$plan = \Dolibarr\CDav\InvitationPolicy::plan((string) $current['calendardata'], $calendar->serialize(), $email, $this->_isProtectedEvent($id));
					if ($plan === null || !in_array($plan['kind'], array('reply', 'noop'), true)) {
						throw new Forbidden('This message is not a participant response.');
					}
					$result = $this->db->query('SELECT u.rowid, u.login, u.email, a.fk_user_action FROM '.MAIN_DB_PREFIX.'actioncomm_resources r
						INNER JOIN '.MAIN_DB_PREFIX.'actioncomm a ON a.id = r.fk_actioncomm
						INNER JOIN '.MAIN_DB_PREFIX.'user u ON u.rowid = r.fk_element
						WHERE r.fk_actioncomm = '.$id." AND r.element_type = 'user' AND u.statut > 0 AND u.fk_soc IS NULL"
						.' AND u.entity IN ('.getEntity('user').') ORDER BY u.rowid');
					if (!$result) throw new \Sabre\DAV\Exception('Unable to resolve local meeting recipients.');
					$recipients = array();
					$localOrganizer = false;
					while ($row = $this->db->fetch_object($result)) {
						if (strtolower(trim((string) $row->email)) === $organizer && (int) $row->rowid === (int) $row->fk_user_action) $localOrganizer = true;
						if ((string) $row->login !== (string) $this->user->login) $recipients[] = 'principals/'.(string) $row->login;
					}
					if (!$localOrganizer) throw new \Sabre\DAV\Exception\NotImplemented('Email scheduling to an external organizer is not configured.');
					$this->_applyInvitationPlan($id, (int) $this->user->id, $plan);
					$committed = $this->getCalendarObject((int) $this->user->id, $uri);
					$receipt = array('uid' => (string) $existing->UID, 'changed' => (bool) $plan['changed'],
						'previous_data' => (string) $current['calendardata'], 'canonical_data' => (string) $committed['calendardata'],
						'omit_location' => ($committed['_cdav_description']['note_prefix_fields']['original_location'] ?? '') === '',
						'recipients' => $recipients, 'sender_address' => 'mailto:'.$email, 'organizer_address' => 'mailto:'.$organizer);
				} finally {
					$calendar->destroy();
				}
				if (!$this->db->commit()) throw new \Sabre\DAV\Exception('Unable to commit the invitation response.');
				return $receipt;
			} catch (\Throwable $e) {
				$this->db->rollback();
				throw $e;
			}
		} finally {
			$reply->destroy();
		}
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
