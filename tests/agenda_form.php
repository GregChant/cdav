<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Exercise the real native Form renderer with fictitious users and two independent tabs. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
define('DOL_DOCUMENT_ROOT', $root);
require $root.'/version.inc.php';
require dirname(__DIR__).'/class/CDavAgendaForm.php';
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
$form = new AgendaForm($db);
$first = array(42 => array('id' => 42, 'transparency' => 1));
$second = array(73 => array('id' => 73, 'transparency' => 0));
$_SESSION = array('assignedtouser' => json_encode(array(99 => array('id' => 99, 'transparency' => 0))),
	'assignedtouser_10' => json_encode($first), 'assignedtouser_11' => json_encode($second));
$original = $_SESSION;
foreach (array('assignedtouser_10', 'assignedtouser_11', 'assignedtouser_10') as $sessionkeyassignedtouser) {
	$selected = $sessionkeyassignedtouser === 'assignedtouser_10' ? $first : $second;
	$html = $form->select_dolusers_forevent('view', 'assignedtouser', 1, array(), 0, '', array(), '0', 0, 0, '', 1, $selected);
	$owner = key($selected);
	agendaFormAssert(str_contains($html, 'Collaborator '.$owner) && !str_contains($html, 'Collaborator 99'), 'The native renderer used another tab or the legacy selection');
	agendaFormAssert(str_contains($html, 'name="transparency"') && str_contains($html, 'name="transparency" checked') === (bool) $selected[$owner]['transparency'], 'The native availability checkbox lost the selected tab occupancy');
	agendaFormAssert($_SESSION === $original, 'Rendering changed a per-event selection or leaked its temporary legacy view');
}
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
unset($GLOBALS['sessionkeyassignedtouser']);
$native = new Form($db);
agendaFormAssert($form->select_dolusers_forevent('view') === $native->select_dolusers_forevent('view'), 'Without a native per-event key the original renderer must remain authoritative');

$form = new Form($db);
$form->error = 'preserved native property';
$hooks = new ActionsCDav();
$object = null;
$action = 'edit';
$hooks->llxHeader(array('currentcontext' => 'actioncard'), $object, $action, null);
agendaFormAssert(($form instanceof AgendaForm) === ((int) DOL_VERSION === 24) && $form->error === 'preserved native property', 'Header integration changed Dolibarr 23 or discarded existing native Form properties');

echo "[OK] Native Agenda renderer, independent tab selections, availability and restoration on failure.\n";
