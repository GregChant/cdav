<?php

// Developer suites are never public HTTP endpoints.
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

/** CardDAV native relationship and identity protection; no database writes. */
date_default_timezone_set('UTC');
$dolibarrRoot = getenv('DOLIBARR_ROOT') ?: dirname(__DIR__, 3).'/core/dolibarr-23.0.4/htdocs';
require __DIR__.'/bootstrap.php';
cdavTestStrictErrors($dolibarrRoot);
define('DOL_DOCUMENT_ROOT', $dolibarrRoot);
define('MAIN_DB_PREFIX', 'llx_');
define('CDAV_URI_KEY', 'business-tests');
define('CDAV_ADDRESSBOOK_ID_SHIFT', 100000);
define('CDAV_CONTACT_TAG', 0);
require $dolibarrRoot.'/includes/sabre/autoload.php';
require $dolibarrRoot.'/core/lib/functions.lib.php';
require dirname(__DIR__).'/class/CardDAVDolibarr.php';

/**
 * Keep parser diagnostics inert for this isolated suite.
 * @param string $message Diagnostic
 * @return void
 */
function debug_log($message)
{
}

/**
 * Record the native permission API boundary; HTTP tests use the real API.
 * @param object $user Authenticated user
 * @param array $features Native features
 * @param mixed $object Native object
 * @param string $table Native sharing table
 * @return bool
 */
function checkUserAccessToObject($user, array $features, $object = 0, $table = '')
{
	$GLOBALS['business_access_calls'][] = array($features, $object, $table);
	return $GLOBALS['business_access_allowed'];
}

/** Minimal read-only database boundary for initial organization lookup. */
class BusinessContactDatabase
{
	public $queries = array();
	public $memberLinked = false;
	public $mappingAvailable = false;
	/**
	 * Keep technical mapping absent in SQL scope tests.
	 * @param string $table Native table
	 * @return bool
	 */
	public function DDLInfoTable($table)
	{
		return $this->mappingAvailable;
	}
	/**
	 * Escape test literals without a database driver.
	 * @param string $value Literal
	 * @return string
	 */
	public function escape($value)
	{
		return addslashes($value);
	}
	/**
	 * Return only the fixture required by the organization query.
	 * @param string $sql Native query
	 * @return object
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;
		$present = strpos($sql, 'FROM llx_societe') !== false || ($this->memberLinked && strpos($sql, 'FROM llx_adherent') !== false)
			|| ($this->mappingAvailable && strpos($sql, 'FROM llx_cdav_cardmap cm') !== false);
		return (object) array('row' => $present ? (object) array('rowid' => 22) : null);
	}
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Consume a single result with Dolibarr's native method name.
	 * @param object $result Query result
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
 * Exercise a private backend boundary without bootstrapping main.inc.php.
 * @param object $backend Backend
 * @param string $method Private method
 * @param array $arguments Arguments
 * @return mixed
 */
function businessInvoke($backend, $method, array $arguments)
{
	$reflection = new ReflectionMethod(Sabre\CardDAV\Backend\Dolibarr::class, $method);
	if (PHP_VERSION_ID < 80500) $reflection->setAccessible(true);
	return $reflection->invokeArgs($backend, $arguments);
}

/**
 * Assert exact native field preservation.
 * @param mixed $expected Expected value
 * @param mixed $actual Actual value
 * @param string $message Failure
 * @return void
 */
function businessSame($expected, $actual, $message)
{
	if ($expected !== $actual) throw new RuntimeException($message.'; expected '.var_export($expected, true).', got '.var_export($actual, true));
}

/**
 * Assert denial before any business write can start.
 * @param callable $callback Protected operation
 * @param string $message Failure
 * @return void
 */
function businessForbidden($callback, $message)
{
	try {
		$callback();
	} catch (Sabre\DAV\Exception\Forbidden $e) {
		return;
	}
	throw new RuntimeException($message);
}

/**
 * Build a strict minimal replacement vCard.
 * @param string $properties Serialized properties
 * @return string
 */
function businessCard($properties)
{
	return "BEGIN:VCARD\r\nVERSION:3.0\r\n".$properties."END:VCARD\r\n";
}

$conf = (object) array('entity' => 1, 'global' => (object) array(), 'modules' => array());
$db = new BusinessContactDatabase();
$user = new class {
	public $id = 7;
	public $companyRead = true;
	public $allCompanies = true;
	/**
	 * Expose all module capabilities; native object checks remain independent.
	 * @param string $module Module
	 * @param string $level Permission
	 * @param string $detail Optional permission detail
	 * @return bool
	 */
	public function hasRight($module, $level, $detail = '')
	{
		if ($module === 'societe' && $level === 'lire') return $this->companyRead;
		if ($module === 'societe' && $level === 'client' && $detail === 'voir') return $this->allCompanies;
		return true;
	}
};
$business_access_allowed = true;
$business_access_calls = array();
$backend = (new ReflectionClass(Sabre\CardDAV\Backend\Dolibarr::class))->newInstanceWithoutConstructor();
foreach (array('db' => $db, 'user' => $user, 'entity' => 1) as $property => $value) {
	$reflection = new ReflectionProperty(Sabre\CardDAV\Backend\Dolibarr::class, $property);
	$reflection->setValue($backend, $value);
}

$current = businessCard("UID:stable-person\r\nFN:Jane Doe\r\nN:Doe;Jane;;;\r\nORG:Example SA;\r\nCLASS:PRIVATE\r\nCATEGORIES:CRM,Project A\r\nTEL;TYPE=CELL:+41790000000\r\n");
$minimal = businessCard("UID:stable-person\r\nFN:Jane Updated\r\nN:Updated;Jane;;;\r\nTEL;TYPE=CELL:+41791111111\r\n");
$contact = (object) array('id' => 12, 'user_id' => 0, 'socid' => 22, 'fk_soc' => 22,
	'priv' => 1, 'status' => 0, 'statut' => 0, 'note_private' => 'Internal CRM note',
	'array_options' => array('options_project' => 54), 'user_creation_id' => 3);

businessSame(false, businessInvoke($backend, '_assertCardBusinessUpdate', array('ct', $contact, $current, $minimal)),
	'Omitted ORG, categories and visibility must permit editing personal details');
$reordered = str_replace('CATEGORIES:CRM,Project A', 'CATEGORIES:Project A,CRM', $current);
businessSame(false, businessInvoke($backend, '_assertCardBusinessUpdate', array('ct', $contact, $current, $reordered)),
	'Reordered unchanged business categories must round-trip');

foreach (array(
	str_replace('UID:stable-person', 'UID:different-person', $minimal),
	str_replace('UID:stable-person', "UID:stable-person\r\nUID:injected-person", $minimal),
	str_replace('ORG:Example SA;', 'ORG:Another Company;', $current),
	str_replace('ORG:Example SA;', "ORG:Example SA;\r\nORG:Another Company;", $current),
	str_replace('ORG:Example SA;', 'ORG:', $current),
	str_replace('CLASS:PRIVATE', 'CLASS:PUBLIC', $current),
	str_replace('CATEGORIES:CRM,Project A', 'CATEGORIES:CRM', $current),
	str_replace('CATEGORIES:CRM,Project A', 'CATEGORIES:', $current),
) as $changed) {
	businessForbidden(static fn() => businessInvoke($backend, '_assertCardBusinessUpdate', array('ct', $contact, $current, $changed)),
		'Protected contact identity or relationship change was accepted');
}

$data = businessInvoke($backend, '_parseDataContact', array($minimal, 'U'));
$data['_organization'] = 'Must never be resolved on an existing contact';
$db->queries = array();
businessInvoke($backend, '_applyContactData', array($contact, $data, true));
foreach (array('id' => 12, 'socid' => 22, 'fk_soc' => 22, 'priv' => 1, 'status' => 0, 'statut' => 0,
	'note_private' => 'Internal CRM note', 'array_options' => array('options_project' => 54), 'user_creation_id' => 3) as $field => $expected) {
	businessSame($expected, $contact->{$field}, 'A contact replacement changed protected '.$field);
}
businessSame('+41791111111', $contact->phone_mobile, 'Personal contact details must remain editable');
businessSame(array(), $db->queries, 'An existing contact must never resolve ORG into a new native relationship');

$member = (object) array('id' => 16, 'socid' => 22, 'fk_soc' => 22, 'statut' => -2, 'status' => -2,
	'typeid' => 4, 'user_id' => 55, 'note_private' => 'Membership policy');
$memberData = businessInvoke($backend, '_parseDataMember', array($minimal, 'U'));
businessInvoke($backend, '_applyMemberData', array($member, $memberData, true));
foreach (array('socid' => 22, 'fk_soc' => 22, 'statut' => -2, 'status' => -2, 'typeid' => 4, 'user_id' => 55,
	'note_private' => 'Membership policy') as $field => $expected) {
	businessSame($expected, $member->{$field}, 'A member replacement changed protected '.$field);
}
businessForbidden(static fn() => businessInvoke($backend, '_assertCardBusinessUpdate', array('mb', $member, $current,
	str_replace('ORG:Example SA;', 'ORG:Another Company;', $current))), 'A member was reassigned through CardDAV');

$company = (object) array('id' => 22, 'status' => 0, 'client' => 3, 'fournisseur' => 1,
	'code_client' => 'C-42', 'code_fournisseur' => 'S-14', 'tva_intra' => 'CHE-123', 'note_private' => 'Accounting note');
$companyData = businessInvoke($backend, '_parseDataThirdparty', array($minimal, 'U'));
businessInvoke($backend, '_applyThirdPartyData', array($company, $companyData, true));
foreach (array('id' => 22, 'status' => 0, 'client' => 3, 'fournisseur' => 1, 'code_client' => 'C-42',
	'code_fournisseur' => 'S-14', 'tva_intra' => 'CHE-123', 'note_private' => 'Accounting note') as $field => $expected) {
	businessSame($expected, $company->{$field}, 'A third-party replacement changed protected '.$field);
}
businessForbidden(static fn() => businessInvoke($backend, '_assertCardBusinessUpdate', array('th', $company, $current,
	str_replace('UID:stable-person', 'UID:new-company', $minimal))), 'A third-party UID was changed');

$linked = clone $contact;
$linked->user_id = 55;
businessSame(true, businessInvoke($backend, '_assertCardBusinessUpdate', array('ct', $linked, $current, $current)),
	'An exact linked-user echo must be a no-op');
$reorderedProperties = businessCard("TEL;TYPE=CELL:+41790000000\r\nCATEGORIES:CRM,Project A\r\nCLASS:PRIVATE\r\nORG:Example SA;\r\nN:Doe;Jane;;;\r\nFN:Jane Doe\r\nUID:stable-person\r\nREV:20261118T120000Z\r\n");
businessSame(true, businessInvoke($backend, '_assertCardBusinessUpdate', array('ct', $linked, $current, $reorderedProperties)),
	'Harmless property ordering and REV must not reject a linked-user echo');
businessForbidden(static fn() => businessInvoke($backend, '_assertCardBusinessUpdate', array('ct', $linked, $current,
	str_replace('+41790000000', '+41792222222', $current))), 'A CardDAV contact write would indirectly modify a linked user');

foreach (array('ct' => 'contact', 'mb' => 'adherent', 'th' => 'societe') as $type => $feature) {
	$business_access_allowed = true;
	businessInvoke($backend, '_assertCardObjectAccess', array($type, $company));
	$lastCall = end($business_access_calls);
	businessSame(array($feature), $lastCall[0], 'The wrong native access policy was used');
	$business_access_allowed = false;
	businessForbidden(static fn() => businessInvoke($backend, '_assertCardObjectAccess', array($type, $company)),
		'Native business access denial was bypassed');
}
$business_access_allowed = true;
businessSame(22, businessInvoke($backend, '_resolveThirdPartyId', array('Example SA')), 'Accessible initial ORG should link to the unique native third party');
$business_access_allowed = false;
businessForbidden(static fn() => businessInvoke($backend, '_resolveThirdPartyId', array('Example SA')),
	'An inaccessible initial ORG was linked to a third party');

try {
	businessInvoke($backend, '_assertCardUidAvailable', array('ct', '12-ct-business-tests', 0));
	throw new RuntimeException('A native contact UID was accepted for a new object');
} catch (Sabre\DAV\Exception\Conflict $e) {
	// Expected: a client may not move native identity onto another record.
}

businessInvoke($backend, '_assertInitialMemberThirdPartyAvailable', array(22));
$db->memberLinked = true;
businessForbidden(static fn() => businessInvoke($backend, '_assertInitialMemberThirdPartyAvailable', array(22)),
	'A new member was allowed to detach an existing member from its third party');

$conf->modules['societe'] = true;
$user->allCompanies = false;
$scopedSql = businessInvoke($backend, '_getSqlContacts', array());
businessSame(true, strpos($scopedSql, 'p.fk_soc IS NULL OR EXISTS') !== false
	&& strpos($scopedSql, 'sc.fk_user = 7') !== false, 'Commercial contact visibility must include only unlinked or assigned-company contacts');
businessSame(true, strpos($scopedSql, 's.rowid = p.fk_soc AND s.entity IN (1)') !== false,
	'Joined company details must stay within the native shared-company entities');
$user->companyRead = false;
$contactOnlySql = businessInvoke($backend, '_getSqlContacts', array());
businessSame(false, strpos($contactOnlySql, 'societe_commerciaux') !== false,
	'Contact-only readers must retain native public-contact visibility');
$user->companyRead = true;
$user->allCompanies = true;
$unrestrictedSql = businessInvoke($backend, '_getSqlContacts', array());
businessSame(false, strpos($unrestrictedSql, 'societe_commerciaux') !== false,
	'Users with native all-company visibility must not be limited to commercial assignments');

foreach (array(array(7, 'ct', 'socpeople'), array(100007, 'th', 'societe'), array(200007, 'mb', 'adherent')) as $scope) {
	list($bookId, $type, $table) = $scope;
	$db->queries = array();
	businessInvoke($backend, '_lockCardBusinessObject', array($bookId, '12-'.$type.'-business-tests'));
	businessSame(1, count($db->queries), 'Native vCard locking must use one direct primary-key lookup');
	businessSame(true, strpos($db->queries[0], 'FROM llx_'.$table.' obj WHERE obj.rowid = 12') !== false
		&& str_ends_with($db->queries[0], 'FOR UPDATE'), 'Shared business rows must be locked before their repeatable-read representation');
}
$mappingProperty = new ReflectionProperty(Sabre\CardDAV\Backend\Dolibarr::class, 'hasCardMappingTable');
$mappingProperty->setValue($backend, true);
$db->mappingAvailable = true;
$db->queries = array();
businessInvoke($backend, '_lockCardBusinessObject', array(7, 'mapped-contact.vcf'));
businessSame(1, count($db->queries), 'Mapped vCards must not fall back to scanning legacy external references');
businessSame(true, strpos($db->queries[0], 'FROM llx_cdav_cardmap cm INNER JOIN llx_socpeople obj') !== false
	&& strpos($db->queries[0], "cm.uuidhash = '".hash('sha256', 'mapped-contact.vcf')."'") !== false
	&& strpos($db->queries[0], ' OR ') === false && str_ends_with($db->queries[0], 'FOR UPDATE'),
	'A mapped vCard must lock its unique URI mapping and native row without scanning the directory');

fwrite(STDOUT, "CardDAV business identity, links, classification, visibility and linked-user protection passed.\n");
