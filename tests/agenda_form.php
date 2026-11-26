<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Exercise native Agenda form renderers with fictitious assignees and project tasks. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
require $root.'/version.inc.php';
require $root.'/core/class/html.form.class.php';
require dirname(__DIR__).'/class/actions_cdav.class.php';

use Dolibarr\CDav\AgendaForm;

/**
 * Assert a renderer/session boundary independently of its implementation.
 * @param bool $condition Expected behavior
 * @param string $message Failure detail
 * @return void
 */
function agendaFormAssert($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

/** Only native user lookup is substituted; native Form rendering remains unchanged. */
class User
{
	public static bool $fail = false;
	public int $id = 0;

	/**
	 * Accept the native lookup dependency without opening a database.
	 * @param object $db Fictitious connection
	 */
	public function __construct($db)
	{
	}

	/**
	 * Select a fictitious collaborator or simulate a failure inside the parent renderer.
	 * @param int $id Fictitious identifier
	 * @return int Positive lookup result
	 */
	public function fetch($id)
	{
		if (self::$fail) throw new RuntimeException('Fictitious native renderer failure');
		$this->id = (int) $id;
		return 1;
	}

	/**
	 * Return a harmless name for checking which tab's native selection was rendered.
	 * @param int $mode Native link mode
	 * @return string Fictitious name
	 */
	public function getNomUrl($mode)
	{
		return 'Collaborator '.$this->id;
	}
}

/** Native labels are sufficient for exercising the actual checkbox markup. */
class AgendaFormTranslations
{
	/**
	 * Return a stable label independent of an installed language catalog.
	 * @param string $key Native translation key
	 * @return string Label
	 */
	public function trans($key)
	{
		return $key;
	}
}

$db = new stdClass();
$langs = new AgendaFormTranslations();
$form = new Form($db);
$native = $form;
$form->error = 'preserved native property';
$hooks = new ActionsCDav();
$object = null;
$action = 'edit';
$hooks->llxHeader(array('currentcontext' => 'actioncard'), $object, $action, null);
$usesPerEventSession = (int) DOL_VERSION === 24;
$needsBridge = $usesPerEventSession && version_compare(explode('-', DOL_VERSION, 2)[0], '24.0.2', '<');
agendaFormAssert($needsBridge ? $form instanceof AgendaForm : $form === $native, 'The header should replace the native Form only in Dolibarr 24.0.0 and 24.0.1');
agendaFormAssert($form->error === 'preserved native property', 'Header integration discarded existing native Form properties');

$first = array(42 => array('id' => 42, 'transparency' => 1));
$second = array(73 => array('id' => 73, 'transparency' => 0));
$_SESSION = array('assignedtouser' => json_encode(array(99 => array('id' => 99, 'transparency' => 0))),
	'assignedtouser_10' => json_encode($first), 'assignedtouser_11' => json_encode($second));
$original = $_SESSION;
if ($usesPerEventSession) {
	foreach (array('assignedtouser_10', 'assignedtouser_11', 'assignedtouser_10') as $sessionkeyassignedtouser) {
		$selected = $sessionkeyassignedtouser === 'assignedtouser_10' ? $first : $second;
		$html = $form->select_dolusers_forevent('view', 'assignedtouser', 1, array(), 0, '', array(), '0', 0, 0, '', 1, $selected);
		$owner = key($selected);
		agendaFormAssert(str_contains($html, 'Collaborator '.$owner) && !str_contains($html, 'Collaborator 99'), 'The native renderer used another tab or the legacy selection');
		agendaFormAssert(str_contains($html, 'name="transparency"') && str_contains($html, 'name="transparency" checked') === (bool) $selected[$owner]['transparency'], 'The native availability checkbox lost the selected tab occupancy');
		agendaFormAssert($_SESSION === $original, 'Rendering changed a per-event selection or leaked its temporary legacy view');
	}
}

if ($needsBridge) {
	User::$fail = true;
	$failed = false;
	try {
		$form->select_dolusers_forevent('view');
	} catch (RuntimeException $error) {
		$failed = $error->getMessage() === 'Fictitious native renderer failure';
	} finally {
		User::$fail = false;
	}
	agendaFormAssert($failed && $_SESSION === $original, 'A parent renderer failure did not restore the legacy selection');
	unset($_SESSION['assignedtouser']);
	$form->select_dolusers_forevent('view');
	agendaFormAssert(!array_key_exists('assignedtouser', $_SESSION), 'The bridge created a persistent legacy selection when none existed');
	$_SESSION = $original;
}
unset($GLOBALS['sessionkeyassignedtouser']);
$html = $form->select_dolusers_forevent('view');
agendaFormAssert(str_contains($html, 'Collaborator 99'), 'Without a native per-event list the original renderer must remain authoritative');

require_once $root.'/core/lib/functions.lib.php';
require_once $root.'/core/class/html.formprojet.class.php';

/** Fictitious task rows for the native project selector. */
class AgendaProjectDatabase
{
	/**
	 * Return the test table prefix.
	 * @return string Prefix
	 */
	public function prefix()
	{
		return 'llx_';
	}

	/**
	 * Accept the native project ID list filter.
	 * @param string $value Project IDs
	 * @return string Numeric list
	 */
	public function sanitize($value)
	{
		return preg_replace('/[^0-9,]/', '', $value);
	}

	/**
	 * Return two distinct tasks on a project with a long title.
	 * @param string $sql Native task list query
	 * @return ArrayIterator Task rows
	 */
	public function query($sql)
	{
		$first = (object) array('rowid' => 7, 'tref' => 'TA-7', 'tlabel' => 'Site visit', 'fk_statut' => 1, 'fk_soc' => 2,
			'ref' => 'PR-3', 'title' => str_repeat('Long project title ', 5), 'name' => 'Customer', 'usage_task' => 1);
		$second = clone $first;
		$second->rowid = 8;
		$second->tref = 'TA-8';
		$second->tlabel = 'Follow up';
		return new ArrayIterator(array($first, $second));
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- Native database API.
	/**
	 * Count the available fixture rows.
	 * @param ArrayIterator $result Cursor
	 * @return int Row count
	 */
	public function num_rows($result)
	{
		return $result->count();
	}

	/**
	 * Read the next fixture row.
	 * @param ArrayIterator $result Cursor
	 * @return object Task
	 */
	public function fetch_object($result)
	{
		$row = $result->current();
		$result->next();
		return $row;
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps

	/**
	 * Release an in-memory fixture cursor.
	 * @param ArrayIterator $result Cursor
	 * @return void
	 */
	public function free($result)
	{
	}
}

$conf = (object) array('entity' => 1, 'modules' => array(), 'global' => new stdClass());
$db = new AgendaProjectDatabase();
$user = new User($db);
$formproject = new FormProjets($db);
$formproject->error = 'preserved project property';
$hooks->llxHeader(array('currentcontext' => 'actioncard'), $object, $action, null);
agendaFormAssert($formproject->error === 'preserved project property', 'The header discarded native project renderer state');

foreach (array('taskid' => 7, 'fk_element' => 8) as $field => $selected) {
	$html = $formproject->selectTasks(-1, $selected, $field, 64, 0, $field === 'taskid' ? '1' : '', 1, 0, 0, 'maxwidth500', '3', 'all', null, 1);
	$document = new DOMDocument();
	$document->loadHTML($html);
	$xpath = new DOMXPath($document);
	$option = $xpath->query('//select[@name="'.$field.'"]/option[@selected]')->item(0);
	$label = $field === 'taskid' ? 'Site visit' : 'Follow up';
	agendaFormAssert($option && $option->getAttribute('value') === (string) $selected, 'The native task ID was lost from '.$field);
	agendaFormAssert($option->textContent === 'TA-'.$selected.' '.$label && $option->getAttribute('data-html') === 'TA-'.$selected.' - '.$label,
		'The Agenda task reference and label must be visible in both the standard field and Select2');
	agendaFormAssert($xpath->query('//option[@value="0"]')->length === ($field === 'taskid' ? 1 : 0), 'The task selector changed its empty option');
}
$html = $formproject->selectTasks(selected: 7, projectsListId: '3,4', nooutput: 1);
agendaFormAssert(str_contains($html, 'PR-3') && str_contains($html, 'TA-7'), 'A multi-project picker still needs its project labels');

echo "[OK] Native Agenda task selection, labels and assignee compatibility.\n";
