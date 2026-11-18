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

require $root.'/core/lib/functions.lib.php';
require $root.'/core/db/mysqli.class.php';
require $root.'/core/class/html.formprojet.class.php';

/** Native picker API fixture; sanitization comes from the real Dolibarr driver. */
class BookingProjectPickerDatabase
{
	public $nativeDriver;
	public $sql = '';
	/** Prepare a driver without opening any connection. */
	public function __construct()
	{
		$this->nativeDriver = (new ReflectionClass('DoliDBMysqli'))->newInstanceWithoutConstructor();
	}
	/** @return string Native prefix */
	public function prefix()
	{
		return MAIN_DB_PREFIX;
	}
	/**
	 * Delegate to the exact native sanitization used by select_projects_list.
	 * @param string $value SQL fragment
	 * @param int $quotes Allow quotes
	 * @param int $equals Allow equals
	 * @return string Sanitized fragment
	 */
	public function sanitize($value, $quotes = 0, $equals = 0)
	{
		return $this->nativeDriver->sanitize($value, $quotes, $equals);
	}
	/**
	 * Supply native rows already scoped to their requested company.
	 * @param string $sql Actual native query
	 * @return object Cursor
	 */
	public function query($sql)
	{
		$this->sql = $sql;
		$rows = array();
		foreach (array(1 => Project::STATUS_VALIDATED, 2 => Project::STATUS_DRAFT, 3 => Project::STATUS_CLOSED) as $id => $status) {
			$rows[] = (object) array('rowid' => $id, 'ref' => 'PROJECT-'.$id, 'title' => 'Project '.$id, 'fk_soc' => 7,
				'fk_statut' => $status, 'public' => 1, 'name' => 'Client', 'name_alias' => '');
		}
		return (object) array('rows' => $rows);
	}
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Native cursor length.
	 * @param object $result Cursor
	 * @return int Length
	 */
	public function num_rows($result)
	{
		return count($result->rows);
	}
	/**
	 * Consume one native row.
	 * @param object $result Cursor
	 * @return object|null Row
	 */
	public function fetch_object($result)
	{
		return array_shift($result->rows);
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Discard a consumed test cursor.
	 * @param object $result Cursor
	 * @return void
	 */
	public function free($result)
	{
	}
}

$db = new BookingProjectPickerDatabase();
$conf = (object) array('entity' => 1, 'global' => new stdClass(), 'use_javascript_ajax' => 0);
$user = new class {
	/**
	 * Grant native picker read access for this status-boundary test.
	 * @param string ...$rights Permission path
	 * @return bool Authorized
	 */
	public function hasRight(...$rights)
	{
		return true;
	}
};
$langs = new class {
	/**
	 * Retain native labels without translation fixtures.
	 * @param string $key Translation
	 * @return string Label
	 */
	public function trans($key)
	{
		return $key;
	}
};
projectBookingAssert($db->sanitize('p.fk_soc=7', 0, 1) === 'p.fk_soc=7', 'The native picker filter must survive real DoliDB sanitization');
projectBookingAssert($db->sanitize('(p.fk_soc=7)AND(p.fk_statut=1)', 0, 1) === 'p.fk_soc=7ANDp.fk_statut=1',
	'Parentheses and SQL keyword boundaries cannot be passed through native morefilter sanitization');
$nativeOptions = (new FormProjets($db))->select_projects_list(7, 0, 'project_id', 0, 0, 1, 1, 0, 0, 1, '', 1, 0,
	'project_id', 'minwidth200 maxwidth400', 'p.fk_soc=7');
projectBookingAssert(str_contains($db->sql, ' AND (p.fk_soc=7) ORDER BY'), 'The native query must retain an exact single-company predicate');
$options = array();
foreach ($nativeOptions as $option) {
	if (empty($option['disabled'])) $options[$option['key']] = $option['labelx'];
}
projectBookingAssert(array_keys($options) === array(1), 'Native closed exclusion and draft disabled flags must leave only open projects');
$html = Form::selectarray('project_id', $options, 1, 1);
projectBookingAssert(str_contains($html, 'name="project_id"') && str_contains($html, '<option value="1" selected>')
	&& !str_contains($html, 'PROJECT-2') && !str_contains($html, 'PROJECT-3'), 'Native form rendering must expose only the selected open project');
fwrite(STDOUT, "Native project status, picker sanitization/rendering and explicit detachment regressions passed.\n");
