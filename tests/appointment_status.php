<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Native state precedence, Outlook compatibility and separation from personal responses. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
define('MAIN_DB_PREFIX', 'state_');
require $root.'/includes/sabre/autoload.php';
require dirname(__DIR__).'/class/CDavAppointmentStatus.php';

use Dolibarr\CDav\AppointmentStatus;
use Sabre\DAV\Exception\BadRequest;

/**
 * Check an externally observable appointment-state invariant.
 * @param bool $condition Expected behavior
 * @param string $message Failure detail
 * @return void
 */
function appointmentStateAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Build a fictitious appointment carrying actual protocol properties.
 * @param string $properties iCalendar lines
 * @param string $type Component type
 * @return string Calendar resource
 */
function appointmentStateCalendar($properties, $type = 'VEVENT')
{
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:".$type."\r\nUID:state-fixture@example.test\r\nDTSTART:20261120T090000Z\r\nDTEND:20261120T100000Z\r\n".$properties."END:".$type."\r\nEND:VCALENDAR\r\n";
}

/** Read-only metadata cursor; mutations are deliberately unsupported. */
class AppointmentStateDatabase
{
	public string $metadata;
	public int $reads = 0;

	/**
	 * Only a legacy metadata read is permitted for the native UI fallback.
	 * @param string $sql Native query
	 * @return ArrayIterator Metadata cursor
	 */
	public function query($sql)
	{
		appointmentStateAssert($sql === 'SELECT calendardata FROM state_cdav_scheduling WHERE fk_actioncomm = 42', 'Reading a legacy state must not rewrite or reclassify an appointment');
		$this->reads++;
		return new ArrayIterator(array((object) array('calendardata' => $this->metadata)));
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- Native DoliDB interface.
	/**
	 * Consume a native-style result cursor.
	 * @param ArrayIterator $cursor Result set
	 * @return object|false Metadata row
	 */
	public function fetch_object($cursor)
	{
		if (!$cursor->valid()) return false;
		$row = $cursor->current();
		$cursor->next();
		return $row;
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}

$tentative = appointmentStateCalendar("STATUS:TENTATIVE\r\nX-MICROSOFT-CDO-BUSYSTATUS:BUSY\r\n");
$completedTask = appointmentStateCalendar("COMPLETED:20261120T120000Z\r\nSTATUS:COMPLETED\r\nPERCENT-COMPLETE:100\r\nSEQUENCE:8\r\n", 'VTODO');
$exception = "BEGIN:VTODO\r\nUID:state-fixture@example.test\r\nRECURRENCE-ID:20261119T090000Z\r\nSTATUS:COMPLETED\r\nCOMPLETED:20261119T120000Z\r\nEND:VTODO\r\n";
$completedTask = str_replace("END:VCALENDAR\r\n", $exception."END:VCALENDAR\r\n", $completedTask);
$reopened = AppointmentStatus::metadataForReopenedTask($completedTask);
$state = Sabre\VObject\Reader::read($reopened);
$items = array_values($state->select('VTODO'));
appointmentStateAssert(!isset($items[0]->COMPLETED) && !isset($items[0]->STATUS) && !isset($items[0]->{'PERCENT-COMPLETE'})
	&& (string) $items[0]->SEQUENCE === '8' && (string) $items[1]->COMPLETED === '20261119T120000Z',
	'A native reopen clears the master completion while retaining the identity, revision and independent completed occurrence.');
$state->destroy();
appointmentStateAssert(AppointmentStatus::metadataForReopenedTask($reopened) === null && AppointmentStatus::metadataForReopenedTask($tentative) === null,
	'A later native update and unrelated appointments must not write task metadata.');
appointmentStateAssert(AppointmentStatus::statusFromCalendar('') === null, 'Missing legacy metadata must retain the native default');
appointmentStateAssert(AppointmentStatus::statusFromCalendar($tentative) === 'TENTATIVE', 'An explicit global RFC state must take precedence over a preserved Outlook hint');
appointmentStateAssert(AppointmentStatus::statusFromCalendar(appointmentStateCalendar("X-MICROSOFT-CDO-BUSYSTATUS:TENTATIVE\r\n")) === 'TENTATIVE', 'An initial Outlook-only provisional appointment must be recognized');
appointmentStateAssert(AppointmentStatus::statusFromCalendar(appointmentStateCalendar("STATUS:CANCELLED\r\nX-MICROSOFT-CDO-BUSYSTATUS:TENTATIVE\r\n")) === 'CANCELLED', 'Cancellation must take precedence over an Outlook availability hint');
appointmentStateAssert(AppointmentStatus::statusFromCalendar(appointmentStateCalendar("STATUS:CONFIRMED\r\nATTENDEE;PARTSTAT=TENTATIVE:mailto:person@example.test\r\n")) === 'CONFIRMED', 'A tentative participant must not make the shared appointment tentative');
appointmentStateAssert(AppointmentStatus::statusFromCalendar(appointmentStateCalendar("STATUS:IN-PROCESS\r\nPERCENT-COMPLETE:40\r\n", 'VTODO')) === null, 'Native task progress must stay independent of appointment state');
foreach (array("STATUS:RESERVED\r\n", "STATUS:CONFIRMED\r\nSTATUS:TENTATIVE\r\n", "STATUS:CONFIRMED\r\nX-MICROSOFT-CDO-BUSYSTATUS:BUSY\r\nX-MICROSOFT-CDO-BUSYSTATUS:TENTATIVE\r\n") as $invalid) {
	$rejected = false;
	try {
		AppointmentStatus::statusFromCalendar(appointmentStateCalendar($invalid));
	} catch (BadRequest $error) {
		$rejected = true;
	}
	appointmentStateAssert($rejected, 'Ambiguous or unsupported global metadata must fail explicitly');
}

$db = new AppointmentStateDatabase();
$db->metadata = $tentative;
$event = (object) array('id' => 42, 'array_options' => array());
appointmentStateAssert(AppointmentStatus::nativeStatus($db, $event) === 'TENTATIVE' && $db->reads === 1, 'An empty migrated field must display its existing tentative state without a destructive backfill');
$event->array_options['options_cdav_status'] = 'CONFIRMED';
appointmentStateAssert(AppointmentStatus::nativeStatus($db, $event) === 'CONFIRMED' && $db->reads === 1, 'An explicit native field must override historical metadata without another metadata read');
$event->id = 0;
$event->array_options = array();
appointmentStateAssert(AppointmentStatus::nativeStatus($db, $event) === 'CONFIRMED' && $db->reads === 1, 'A new native appointment must display confirmed without querying an identifier that does not exist');

$event->transparency = 1;
$event->fk_project = 17;
$event->socid = 23;
$event->userassigned = array(2 => array('id' => 2, 'transparency' => 1, 'answer_status' => 'TENTATIVE', 'mandatory' => 1));
$event->socpeopleassigned = array(3 => array('id' => 3, 'transparency' => 1, 'answer_status' => 'ACCEPTED'));
AppointmentStatus::clearOccupancy($event);
appointmentStateAssert($event->transparency === 0 && $event->userassigned[2]['transparency'] === 0 && $event->socpeopleassigned[3]['transparency'] === 0, 'Cancellation must release every native participant');
appointmentStateAssert($event->fk_project === 17 && $event->socid === 23 && $event->userassigned[2]['answer_status'] === 'TENTATIVE' && $event->userassigned[2]['mandatory'] === 1 && $event->socpeopleassigned[3]['answer_status'] === 'ACCEPTED', 'Freeing a cancelled event must preserve its native business links and individual responses');

echo "[OK] Native appointment state, legacy precedence and participant separation.\n";
