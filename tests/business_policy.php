<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** Native creation provenance, safe upgrade defaults and failure handling. */
$root = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($root);
require dirname(__DIR__).'/class/CDavBusinessPolicy.php';

use Dolibarr\CDav\BusinessPolicy;

define('MAIN_DB_PREFIX', 'policy_');
$policyConfiguration = array();

/**
 * Read the test configuration through the native helper signature.
 * @param string $name Constant name
 * @param int $default Default setting
 * @return int Configuration value
 */
function getDolGlobalInt($name, $default = 0)
{
	global $policyConfiguration;
	return (int) ($policyConfiguration[$name] ?? $default);
}

/**
 * Assert a provenance decision or upgrade outcome.
 * @param bool $condition Expected condition
 * @param string $message Failure detail
 * @return void
 */
function policyCheck(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

/** Minimal native database adapter, including a stateful legacy schema upgrade. */
class PolicyDatabase
{
	public bool $originColumn = true;
	public bool $describeFails = false;
	public bool $upgradeFails = false;
	public bool $readFails = false;
	public int $descriptions = 0;
	public int $reads = 0;
	public array $alterations = array();
	public array $origins = array(1 => 1, 2 => 0, 3 => 2);

	/**
	 * Describe the native DAV mapping through Dolibarr's DDL API.
	 * @param string $table Native table name
	 * @return ArrayIterator|false Field cursor or failure
	 */
	public function DDLDescTable($table)
	{
		policyCheck($table === 'policy_actioncomm_cdav', 'Only the technical DAV mapping may be upgraded');
		$this->descriptions++;
		if ($this->describeFails) return false;
		$fields = array((object) array('Field' => 'fk_object'), (object) array('Field' => 'sourceuid'));
		if ($this->originColumn) $fields[] = (object) array('Field' => 'dav_origin');
		return new ArrayIterator($fields);
	}

	/**
	 * Add the server-owned origin column through the native DDL API.
	 * @param string $table Table name
	 * @param string $name Column name
	 * @param array $definition Native field descriptor
	 * @return int Native DDL result
	 */
	public function DDLAddField($table, $name, $definition)
	{
		$this->alterations[] = array($table, $name, $definition);
		if ($this->upgradeFails) return -1;
		$this->originColumn = true;
		// Existing rows gain only the safe unknown/native default.
		foreach ($this->origins as &$origin) $origin = 0;
		unset($origin);
		return 1;
	}

	/**
	 * Read an explicit creation marker; no mutation is accepted by this adapter.
	 * @param string $sql Query
	 * @return ArrayIterator|false Row cursor or failure
	 */
	public function query($sql)
	{
		$this->reads++;
		policyCheck((bool) preg_match('/^SELECT dav_origin FROM policy_actioncomm_cdav WHERE fk_object = ([0-9]+)$/', $sql, $match),
			'An origin lookup never writes or promotes an existing appointment');
		if ($this->readFails) return false;
		$id = (int) $match[1];
		$rows = array_key_exists($id, $this->origins) ? array((object) array('dav_origin' => $this->origins[$id])) : array();
		return new ArrayIterator($rows);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- Mirror Dolibarr's native database API.
	/**
	 * Consume the native row cursor.
	 * @param ArrayIterator $result Cursor
	 * @return object|false Next row
	 */
	public function fetch_object($result)
	{
		if (!$result->valid()) return false;
		$row = $result->current();
		$result->next();
		return $row;
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}

policyCheck(BusinessPolicy::protectNativeEvents(), 'BP01: protection is enabled without a configured constant');
$policyConfiguration['CDAV_PROTECT_NATIVE_EVENTS'] = 0;
policyCheck(!BusinessPolicy::protectNativeEvents(), 'BP01: only the explicit administrator setting disables the date-only policy');
$policyConfiguration['CDAV_PROTECT_NATIVE_EVENTS'] = 1;
policyCheck(BusinessPolicy::protectNativeEvents(), 'BP01: enabling protection takes effect immediately');

$db = new PolicyDatabase();
policyCheck(BusinessPolicy::isDavCreated($db, 1), 'BP02: a positive server creation marker permits normal DAV editing');
foreach (array(0, -1, 2, 3, 400) as $id) {
	policyCheck(!BusinessPolicy::isDavCreated($db, $id), 'BP02: native, ambiguous, nonexistent and invalid events never acquire DAV provenance');
}
policyCheck($db->descriptions === 1, 'BP02: schema inspection is cached only for this live connection');
policyCheck($db->origins === array(1 => 1, 2 => 0, 3 => 2), 'BP02: reads never promote or replace origin markers');
policyCheck(BusinessPolicy::migrate($db) > 0 && !$db->alterations, 'BP03: current installations require no schema changes');
policyCheck($db->origins[1] === 1, 'BP03: repeated activation preserves explicit DAV provenance');

$legacy = new PolicyDatabase();
$legacy->originColumn = false;
policyCheck(!BusinessPolicy::isDavCreated($legacy, 1) && $legacy->reads === 0,
	'BP04: older schemas fail closed without sending a query for a nonexistent column');
policyCheck(BusinessPolicy::migrate($legacy) > 0 && count($legacy->alterations) === 1,
	'BP04: native DDL upgrades an older mapping once');
policyCheck($legacy->alterations[0] === array('policy_actioncomm_cdav', 'dav_origin',
	array('type' => 'tinyint', 'null' => 'NOT NULL', 'default' => '0')),
	'BP04: every historical mapping receives the protected default');
policyCheck($legacy->origins === array(1 => 0, 2 => 0, 3 => 0), 'BP04: migration never infers provenance from an old DAV mapping');
$legacy->origins[50] = 1;
policyCheck(BusinessPolicy::isDavCreated($legacy, 50), 'BP04: in-process activation refreshes the earlier missing-column capability');
policyCheck(BusinessPolicy::migrate($legacy) > 0 && count($legacy->alterations) === 1,
	'BP04: the upgrade is idempotent and does not reset newly created origins');

$failed = new PolicyDatabase();
$failed->originColumn = false;
$failed->upgradeFails = true;
policyCheck(BusinessPolicy::migrate($failed) < 0, 'BP05: a failed schema upgrade must prevent module activation');
policyCheck(!BusinessPolicy::isDavCreated($failed, 1), 'BP05: an unsuccessful migration cannot unlock old events');
$failed->describeFails = true;
policyCheck(BusinessPolicy::migrate($failed) < 0, 'BP05: an unreadable schema cannot be accepted as an installed upgrade');

$unreadable = new PolicyDatabase();
$unreadable->readFails = true;
try {
	BusinessPolicy::isDavCreated($unreadable, 1);
	throw new LogicException('BP06: a failed origin read was silently accepted');
} catch (RuntimeException $error) {
	policyCheck($error->getMessage() === 'Unable to verify the appointment creation origin',
		'BP06: origin storage errors stop the write with an explicit failure');
}
$uninspectable = new PolicyDatabase();
$uninspectable->describeFails = true;
try {
	BusinessPolicy::isDavCreated($uninspectable, 1);
	throw new LogicException('BP06: an unreadable schema was silently accepted');
} catch (RuntimeException $error) {
	policyCheck($error->getMessage() === 'Unable to inspect the appointment protection schema',
		'BP06: schema errors stop the write before any business mutation');
}
fwrite(STDOUT, "[OK] BP01-BP06 protected defaults, immutable reads, native idempotent upgrade and explicit storage failures\n");
