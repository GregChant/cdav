<?php

namespace Sabre\DAVACL\PrincipalBackend;

use Sabre\DAV;

/** Dolibarr-native principal discovery and read-only calendar delegation. */
class Dolibarr extends AbstractBackend
{
	/** @var object */
	public $user;
	/** @var object */
	protected $db;
	/** @var array<string,array<string,mixed>> */
	private $principals = array();
	/** @var bool */
	private $delegationEnabled;
	/** @var bool */
	private $directoryEnabled;
	/** @var array<int,array{read:bool,write:bool}> */
	private $agendaRights = array();

	public function __construct($user, $db)
	{
		$this->user = $user;
		$this->db = $db;
		$this->delegationEnabled = (bool) getDolGlobalInt('CDAV_DELEGATION');
		$this->directoryEnabled = $this->delegationEnabled || (bool) getDolGlobalInt('CDAV_SCHEDULING');
		$this->loadPrincipals();
	}

	private function loadPrincipals()
	{
		$sql = 'SELECT rowid, login, firstname, lastname, email FROM '.MAIN_DB_PREFIX.'user'
			.' WHERE statut > 0 AND fk_soc IS NULL AND entity IN ('.getEntity('user').')';
		if (!$this->directoryEnabled) {
			$sql .= ' AND rowid = '.((int) $this->user->id);
		}
		$sql .= ' ORDER BY login';
		$result = $this->db->query($sql);
		if (!$result) return;
		while ($row = $this->db->fetch_object($result)) {
			$uri = 'principals/'.(string) $row->login;
			$this->principals[$uri] = array(
				'id' => ((int) $row->rowid) * 10,
				'user_id' => (int) $row->rowid,
				'uri' => $uri,
				'{DAV:}displayname' => trim((string) $row->firstname.' '.(string) $row->lastname) ?: (string) $row->login,
				'{http://sabredav.org/ns}email-address' => trim((string) $row->email),
			);
			if ($this->delegationEnabled) {
				foreach (array('calendar-proxy-read' => 1, 'calendar-proxy-write' => 2) as $name => $suffix) {
					$proxyUri = $uri.'/'.$name;
					$this->principals[$proxyUri] = array(
						'id' => ((int) $row->rowid) * 10 + $suffix,
						'user_id' => (int) $row->rowid,
						'uri' => $proxyUri,
					);
				}
			}
		}
	}

	public function getPrincipalsByPrefix($prefixPath)
	{
		$result = array();
		foreach ($this->principals as $principal) {
			list($prefix) = \Sabre\Uri\split((string) $principal['uri']);
			if ($prefix === $prefixPath) $result[] = $this->publicPrincipal($principal);
		}
		return $result;
	}

	public function getPrincipalByPath($path)
	{
		return isset($this->principals[$path]) ? $this->publicPrincipal($this->principals[$path]) : null;
	}

	private function publicPrincipal(array $principal)
	{
		unset($principal['user_id']);
		foreach ($principal as $key => $value) {
			if ($value === '') unset($principal[$key]);
		}
		return $principal;
	}

	public function updatePrincipal($path, DAV\PropPatch $propPatch)
	{
		return false;
	}

	public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
	{
		if (!$this->directoryEnabled || $prefixPath !== 'principals' || !$searchProperties) return array();
		$supported = array('{DAV:}displayname', '{http://sabredav.org/ns}email-address',
			'{urn:ietf:params:xml:ns:caldav}calendar-user-address-set');
		foreach (array_keys($searchProperties) as $property) {
			if (!in_array($property, $supported, true)) return array();
		}
		$lower = static fn($value) => function_exists('dol_strtolower')
			? dol_strtolower((string) $value)
			: (function_exists('mb_strtolower') ? mb_strtolower((string) $value, 'UTF-8') : strtolower((string) $value));
		$matches = array();
		foreach ($this->principals as $principal) {
			if (str_contains((string) $principal['uri'], '/calendar-proxy-')) continue;
			$checks = array();
			foreach ($searchProperties as $property => $needle) {
				$field = $property === '{DAV:}displayname' ? '{DAV:}displayname' : '{http://sabredav.org/ns}email-address';
				$checks[] = str_contains($lower($principal[$field] ?? ''), $lower(preg_replace('/^mailto:/i', '', (string) $needle)));
			}
			$matched = $test === 'anyof' ? in_array(true, $checks, true) : !in_array(false, $checks, true);
			if ($matched) $matches[] = (string) $principal['uri'];
		}
		return $matches;
	}

	public function findByUri($uri, $principalPrefix)
	{
		if (!$this->directoryEnabled || $principalPrefix !== 'principals') return null;
		$value = strtolower(trim(preg_replace('/^mailto:/i', '', (string) $uri)));
		$found = null;
		foreach ($this->principals as $principal) {
			if (str_contains((string) $principal['uri'], '/calendar-proxy-')) continue;
			if (strtolower((string) ($principal['{http://sabredav.org/ns}email-address'] ?? '')) === $value
				|| strtolower((string) $principal['uri']) === strtolower(trim((string) $uri, '/'))) {
				if ($found !== null) return null; // ambiguous email: fail closed
				$found = (string) $principal['uri'];
			}
		}
		return $found;
	}

	private function rightsForUser($userId)
	{
		$userId = (int) $userId;
		if (isset($this->agendaRights[$userId])) return $this->agendaRights[$userId];
		$rights = array('read' => false, 'write' => false);
		$delegate = new \User($this->db);
		if ($delegate->fetch($userId) > 0) {
			$delegate->loadRights();
			$rights['read'] = (bool) $delegate->hasRight('agenda', 'allactions', 'read');
			$rights['write'] = (bool) $delegate->hasRight('agenda', 'allactions', 'write');
		}
		return $this->agendaRights[$userId] = $rights;
	}

	public function getGroupMemberSet($principal)
	{
		if (!$this->delegationEnabled || !preg_match('#^principals/[^/]+/calendar-proxy-(read|write)$#', $principal, $match)
			|| !isset($this->principals[$principal])) return array();
		$ownerId = (int) $this->principals[$principal]['user_id'];
		$members = array();
		foreach ($this->principals as $candidate) {
			if (str_contains((string) $candidate['uri'], '/calendar-proxy-') || (int) $candidate['user_id'] === $ownerId) continue;
			$rights = $this->rightsForUser((int) $candidate['user_id']);
			if (($match[1] === 'write' && $rights['write']) || ($match[1] === 'read' && $rights['read'])) {
				$members[] = (string) $candidate['uri'];
			}
		}
		return $members;
	}

	public function getGroupMembership($principal)
	{
		if (!$this->delegationEnabled || !isset($this->principals[$principal])
			|| str_contains($principal, '/calendar-proxy-')) return array();
		$userId = (int) $this->principals[$principal]['user_id'];
		$rights = $this->rightsForUser($userId);
		$groups = array();
		foreach ($this->principals as $owner) {
			if (str_contains((string) $owner['uri'], '/calendar-proxy-') || (int) $owner['user_id'] === $userId) continue;
			if ($rights['read']) $groups[] = $owner['uri'].'/calendar-proxy-read';
			if ($rights['write']) $groups[] = $owner['uri'].'/calendar-proxy-write';
		}
		return $groups;
	}

	public function setGroupMemberSet($principal, array $members)
	{
		throw new DAV\Exception\Forbidden('Calendar delegation is derived from Dolibarr rights');
	}

	public function createPrincipal($path, $mkCol)
	{
		throw new DAV\Exception\Forbidden('Principals are managed in Dolibarr');
	}
}
