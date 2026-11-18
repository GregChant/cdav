<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Explicit project detachment when a native open-only selector hides its old value. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
define('MAIN_DB_PREFIX', 'llx_');
require $root.'/projet/class/project.class.php';
require dirname(__DIR__).'/class/CDavBookingService.php';

/** Native project status boundary; no profile or business object is mutated. */
class BookingProjectDatabase
{
	public $status;
	public $entity = 1;
	public $present = true;
	public $failed = false;
	public $queries = array();
	/**
	 * Return the current project status as a native locking query would.
	 * @param string $sql Native status read
	 * @return object|false
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;
		if ($this->failed) return false;
		return (object) array('row' => $this->present && $this->entity === 1
			? (object) array('rowid' => 7, 'fk_statut' => $this->status) : null);
	}
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Consume the native result cursor.
	 * @param object $result Native cursor
	 * @return object|null
	 */
	public function fetch_object($result)
	{
		$row = $result->row;
		$result->row = null;
		return $row;
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}

/**
 * Assert the intended business outcome.
 * @param bool $condition Expected invariant
 * @param string $message Failure detail
 * @return void
 */
function projectBookingAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/**
 * Exercise the confirmation rule independently of native owner/config fixtures.
 * @param object $service Real booking service
 * @param int $existing Saved native project
 * @param int $replacement New native project choice
 * @param bool $confirmed Explicit detachment confirmation
 * @return void
 */
function projectBookingGuard($service, $existing, $replacement, $confirmed)
{
	$method = new ReflectionMethod($service, 'assertProjectRemovalConfirmed');
	if (PHP_VERSION_ID < 80500) $method->setAccessible(true);
	$method->invoke($service, $existing, $replacement, $confirmed);
}

/**
 * Require an explicit, translatable service rejection.
 * @param callable $operation Operation
 * @param string $key Expected translation key
 * @param int $status Expected status
 * @return void
 */
function projectBookingRejects($operation, $key, $status = 422)
{
	try {
		$operation();
	} catch (Dolibarr\CDav\BookingException $error) {
		projectBookingAssert($error->getMessage() === $key && $error->getCode() === $status, 'Unexpected booking project rejection');
		return;
	}
	throw new RuntimeException('An unavailable project was silently detached');
}

$db = new BookingProjectDatabase();
$service = new Dolibarr\CDav\BookingService($db, 1);
$user = (object) array('admin' => 1, 'socid' => 0);
$db->status = Project::STATUS_VALIDATED;
projectBookingGuard($service, 7, 0, false);
projectBookingAssert(count($db->queries) === 1 && str_ends_with($db->queries[0], 'FOR UPDATE'),
	'The current native status must be checked under the profile-save transaction lock');

foreach (array(Project::STATUS_DRAFT, Project::STATUS_CLOSED, -1) as $status) {
	$db->status = $status;
	projectBookingRejects(static fn() => projectBookingGuard($service, 7, 0, false), 'CDavBookingProjectClearRequired');
	projectBookingGuard($service, 7, 0, true);
	projectBookingGuard($service, 7, 12, false);
}
$db->status = Project::STATUS_CLOSED;
$db->present = false;
projectBookingRejects(static fn() => projectBookingGuard($service, 7, 0, false), 'CDavBookingProjectClearRequired');
$db->present = true;
$db->entity = 2;
projectBookingRejects(static fn() => projectBookingGuard($service, 7, 0, false), 'CDavBookingProjectClearRequired');
$db->entity = 1;
$db->status = Project::STATUS_VALIDATED;
projectBookingGuard($service, 7, 0, false);
projectBookingGuard($service, 0, 0, false);
$db->failed = true;
projectBookingRejects(static fn() => projectBookingGuard($service, 7, 0, false), 'CDavBookingUnavailable', 503);
$db->failed = false;

foreach (array(0, 1, '0', '1', null, array(), new stdClass()) as $invalid) {
	$db->queries = array();
	projectBookingRejects(static fn() => $service->saveProfile(1, array(), true, 4, array('clear_project_link' => $invalid)), 'CDavBookingInvalid');
	projectBookingAssert($db->queries === array(), 'Explicit unlink confirmation must be a real boolean before owner or profile queries');
}
fwrite(STDOUT, "Native project status, unavailable selector values and explicit detachment regressions passed.\n");
