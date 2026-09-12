<?php

namespace Dolibarr\CDav;

/**
 * Small RFC 6578 journal shared by the CalDAV and CardDAV backends.
 *
 * Dolibarr remains authoritative. Reconciliation compares the visible native
 * collection with a technical snapshot, which also captures changes made by
 * the web UI, imports, APIs and third-party modules without duplicating every
 * Dolibarr trigger. Removed URI values remain in the change journal as
 * tombstones until the configured retention period expires.
 */
class SyncStore
{
	private const SCOPES = array('cal', 'card');
	private const MAX_PAGE_SIZE = 1000;

	/** @var object */
	private $db;
	/** @var int */
	private $entity;
	/** @var bool|null */
	private $available = null;
	/** @var array<string,string> */
	private $tokenCache = array();
	/** @var bool */
	private $orphansPruned = false;

	public function __construct($db, $entity)
	{
		$this->db = $db;
		$this->entity = max(1, (int) $entity);
	}

	public function isAvailable()
	{
		if ($this->available === null) {
			$this->available = (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_sync_collection')
				&& (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_sync_state')
				&& (bool) $this->db->DDLInfoTable(MAIN_DB_PREFIX.'cdav_sync_change');
		}
		return $this->available;
	}

	/**
	 * Reconcile and return the collection token expected by Sabre's Sync plugin.
	 *
	 * @param string $scope cal|card
	 * @param int $collectionId
	 * @param array<string,string> $objects URI => ETag
	 * @return string|null
	 */
	public function synchronize($scope, $collectionId, array $objects)
	{
		$scope = $this->validateScope($scope);
		$collectionId = (int) $collectionId;
		$cacheKey = $scope.':'.$collectionId.':'.hash('sha256', serialize($objects));
		if (isset($this->tokenCache[$cacheKey])) {
			return $this->tokenCache[$cacheKey];
		}
		if (!$this->isAvailable()) {
			return null;
		}
		$this->pruneOrphanCollections();

		$normalized = array();
		foreach ($objects as $uri => $etag) {
			$uri = (string) $uri;
			if ($uri === '' || strlen($uri) > 1024) {
				continue;
			}
			$normalized[hash('sha256', $uri)] = array(
				'uri' => $uri,
				'etag' => hash('sha256', (string) $etag),
			);
		}

		// INSERT IGNORE inside two concurrent transactions can take shared locks
		// on the same unique key and deadlock when both then request FOR UPDATE.
		// Initialize in autocommit mode, then serialize reconciliation explicitly.
		$this->ensureCollection($scope, $collectionId);
		if (!$this->db->begin()) {
			throw new \RuntimeException('Unable to start the DAV sync transaction');
		}
		try {
			$latestToken = $this->readCollectionToken($scope, $collectionId, true);
			// Lock before taking the snapshot so concurrent REPORT requests cannot
			// journal the same native transition twice from a stale transaction view.
			$state = $this->readState($scope, $collectionId);

			foreach ($normalized as $hash => $object) {
				$operation = null;
				if (!isset($state[$hash])) {
					$operation = 'A';
				} elseif (!hash_equals((string) $state[$hash]['etag'], (string) $object['etag'])
					|| (string) $state[$hash]['uri'] !== (string) $object['uri']) {
					$operation = 'M';
				}
				if ($operation !== null) {
					$latestToken = $this->appendChange($scope, $collectionId, $hash, $object['uri'], $operation);
					$this->upsertState($scope, $collectionId, $hash, $object['uri'], $object['etag']);
				}
			}

			foreach ($state as $hash => $object) {
				if (!isset($normalized[$hash])) {
					$latestToken = $this->appendChange($scope, $collectionId, $hash, $object['uri'], 'D');
					$this->deleteState($scope, $collectionId, $hash);
				}
			}

			$sql = 'UPDATE '.MAIN_DB_PREFIX.'cdav_sync_collection SET initialized = 1, synctoken = '.((int) $latestToken)
				.' WHERE entity = '.$this->entity.' AND scope = \''.$this->db->escape($scope).'\''
				.' AND collection_id = '.$collectionId;
			if (!$this->db->query($sql)) {
				throw new \RuntimeException('Unable to update the DAV sync token');
			}
			$this->prune($scope, $collectionId, (int) $latestToken);
			if (!$this->db->commit()) {
				throw new \RuntimeException('Unable to commit the DAV sync transaction');
			}
		} catch (\Throwable $e) {
			$this->db->rollback();
			throw $e;
		}

		$token = $this->formatToken($scope, $collectionId, (int) $latestToken);
		$this->tokenCache[$cacheKey] = $token;
		return $token;
	}

	/**
	 * Remove journals whose virtual calendar/address-book owner was deleted.
	 *
	 * CardDAV collection IDs encode the native user with the historical
	 * 100000 offset. A foreign key therefore cannot express this lifecycle;
	 * the collection foreign keys still cascade its states and tombstones.
	 */
	public function pruneOrphanCollections()
	{
		if ($this->orphansPruned || !$this->isAvailable()) {
			return;
		}
		$shift = defined('CDAV_ADDRESSBOOK_ID_SHIFT') ? max(1, (int) CDAV_ADDRESSBOOK_ID_SHIFT) : 100000;
		$sql = 'DELETE collection FROM '.MAIN_DB_PREFIX.'cdav_sync_collection collection'
			.' LEFT JOIN '.MAIN_DB_PREFIX.'user owner ON owner.rowid = CASE'
			." WHEN collection.scope = 'card' THEN MOD(collection.collection_id, ".$shift.')'
			.' ELSE collection.collection_id END'
			.' WHERE collection.entity = '.$this->entity
			." AND collection.scope IN ('cal', 'card') AND owner.rowid IS NULL";
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to prune orphan DAV sync collections');
		}
		$this->orphansPruned = true;
	}

	/**
	 * Return a paginated RFC 6578 change set after synchronize() was called.
	 *
	 * @param string|null $syncToken Token without Sabre's URL prefix
	 * @param array<string,string> $objects Current URI => ETag map
	 * @return array<string,mixed>|null
	 */
	public function getChanges($scope, $collectionId, $syncToken, $limit, array $objects)
	{
		$scope = $this->validateScope($scope);
		$collectionId = (int) $collectionId;
		$currentTokenString = $this->synchronize($scope, $collectionId, $objects);
		if ($currentTokenString === null) {
			return null;
		}
		$currentToken = $this->parseToken($currentTokenString, $scope, $collectionId);
		if ($syncToken === null || $syncToken === '') {
			$uris = array_keys($objects);
			sort($uris, SORT_STRING);
			return array(
				'syncToken' => $currentTokenString,
				'added' => $uris,
				'modified' => array(),
				'deleted' => array(),
				'result_truncated' => false,
			);
		}

		$fromToken = $this->parseToken((string) $syncToken, $scope, $collectionId);
		if ($fromToken === null || $fromToken > $currentToken) {
			return null;
		}
		$minimum = $this->readMinimumToken($scope, $collectionId);
		if ($fromToken < $minimum) {
			return null;
		}

		$pageSize = $limit === null ? self::MAX_PAGE_SIZE : max(1, min((int) $limit, self::MAX_PAGE_SIZE));
		$sql = 'SELECT rowid, uri, operation FROM '.MAIN_DB_PREFIX.'cdav_sync_change'
			.' WHERE entity = '.$this->entity.' AND scope = \''.$this->db->escape($scope).'\''
			.' AND collection_id = '.$collectionId.' AND rowid > '.((int) $fromToken)
			.' AND rowid <= '.((int) $currentToken).' ORDER BY rowid ASC';
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \RuntimeException('Unable to read DAV sync changes');
		}

		$changes = array();
		$cutoff = $fromToken;
		$truncated = false;
		while ($row = $this->db->fetch_object($result)) {
			$uri = (string) $row->uri;
			if (!isset($changes[$uri]) && count($changes) >= $pageSize) {
				$truncated = true;
				break;
			}
			if (!isset($changes[$uri])) {
				$changes[$uri] = array('first' => (string) $row->operation, 'last' => (string) $row->operation);
			} else {
				$changes[$uri]['last'] = (string) $row->operation;
			}
			$cutoff = (int) $row->rowid;
		}

		$added = array();
		$modified = array();
		$deleted = array();
		foreach ($changes as $uri => $operations) {
			if ($operations['last'] === 'D') {
				$deleted[] = $uri;
			} elseif ($operations['first'] === 'A') {
				$added[] = $uri;
			} else {
				$modified[] = $uri;
			}
		}
		$nextToken = $truncated ? $cutoff : $currentToken;
		return array(
			'syncToken' => $this->formatToken($scope, $collectionId, $nextToken),
			'added' => $added,
			'modified' => $modified,
			'deleted' => $deleted,
			'result_truncated' => $truncated,
		);
	}

	private function validateScope($scope)
	{
		$scope = (string) $scope;
		if (!in_array($scope, self::SCOPES, true)) {
			throw new \InvalidArgumentException('Invalid DAV sync scope');
		}
		return $scope;
	}

	private function formatToken($scope, $collectionId, $token)
	{
		return $this->entity.'-'.$scope.'-'.((int) $collectionId).'-'.((int) $token);
	}

	private function parseToken($token, $scope, $collectionId)
	{
		$pattern = '/^'.preg_quote((string) $this->entity, '/').'-'.preg_quote($scope, '/').'-'
			.preg_quote((string) ((int) $collectionId), '/').'-(\d+)$/';
		return preg_match($pattern, (string) $token, $matches) ? (int) $matches[1] : null;
	}

	private function ensureCollection($scope, $collectionId)
	{
		$sql = 'INSERT IGNORE INTO '.MAIN_DB_PREFIX.'cdav_sync_collection'
			.' (entity, scope, collection_id, synctoken, min_token, initialized) VALUES ('
			.$this->entity.', \''.$this->db->escape($scope).'\', '.((int) $collectionId).', 0, 0, 0)';
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to initialize the DAV sync collection');
		}
	}

	private function readCollectionToken($scope, $collectionId, $lock = false)
	{
		$sql = 'SELECT synctoken FROM '.MAIN_DB_PREFIX.'cdav_sync_collection WHERE entity = '.$this->entity
			.' AND scope = \''.$this->db->escape($scope).'\' AND collection_id = '.((int) $collectionId)
			.($lock ? ' FOR UPDATE' : '');
		$result = $this->db->query($sql);
		$row = $result ? $this->db->fetch_object($result) : null;
		if (!$row) {
			throw new \RuntimeException('Unable to read the DAV sync collection');
		}
		return (int) $row->synctoken;
	}

	private function readMinimumToken($scope, $collectionId)
	{
		$sql = 'SELECT min_token FROM '.MAIN_DB_PREFIX.'cdav_sync_collection WHERE entity = '.$this->entity
			.' AND scope = \''.$this->db->escape($scope).'\' AND collection_id = '.((int) $collectionId);
		$result = $this->db->query($sql);
		$row = $result ? $this->db->fetch_object($result) : null;
		return $row ? (int) $row->min_token : PHP_INT_MAX;
	}

	/** @return array<string,array{uri:string,etag:string}> */
	private function readState($scope, $collectionId)
	{
		$sql = 'SELECT uri_hash, uri, etag_hash FROM '.MAIN_DB_PREFIX.'cdav_sync_state WHERE entity = '.$this->entity
			.' AND scope = \''.$this->db->escape($scope).'\' AND collection_id = '.((int) $collectionId);
		$result = $this->db->query($sql);
		if (!$result) {
			throw new \RuntimeException('Unable to read the DAV sync state');
		}
		$state = array();
		while ($row = $this->db->fetch_object($result)) {
			$state[(string) $row->uri_hash] = array('uri' => (string) $row->uri, 'etag' => (string) $row->etag_hash);
		}
		return $state;
	}

	private function appendChange($scope, $collectionId, $hash, $uri, $operation)
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_sync_change'
			.' (entity, scope, collection_id, uri_hash, uri, operation, datec) VALUES ('
			.$this->entity.', \''.$this->db->escape($scope).'\', '.((int) $collectionId).', \''
			.$this->db->escape($hash).'\', \''.$this->db->escape($uri).'\', \''.$this->db->escape($operation)
			.'\', \''.$this->db->idate(dol_now()).'\')';
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to append the DAV sync change');
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'cdav_sync_change');
	}

	private function upsertState($scope, $collectionId, $hash, $uri, $etag)
	{
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'cdav_sync_state'
			.' (entity, scope, collection_id, uri_hash, uri, etag_hash) VALUES ('
			.$this->entity.', \''.$this->db->escape($scope).'\', '.((int) $collectionId).', \''
			.$this->db->escape($hash).'\', \''.$this->db->escape($uri).'\', \''.$this->db->escape($etag).'\')'
			.' ON DUPLICATE KEY UPDATE uri = VALUES(uri), etag_hash = VALUES(etag_hash)';
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to update the DAV sync state');
		}
	}

	private function deleteState($scope, $collectionId, $hash)
	{
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'cdav_sync_state WHERE entity = '.$this->entity
			.' AND scope = \''.$this->db->escape($scope).'\' AND collection_id = '.((int) $collectionId)
			.' AND uri_hash = \''.$this->db->escape($hash).'\'';
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to remove the DAV sync state');
		}
	}

	private function prune($scope, $collectionId, $currentToken)
	{
		$retentionDays = function_exists('getDolGlobalInt') ? getDolGlobalInt('CDAV_SYNC_RETENTION_DAYS', 180) : 180;
		$retentionDays = max(7, min(3650, (int) $retentionDays));
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'cdav_sync_change WHERE entity = '.$this->entity
			.' AND scope = \''.$this->db->escape($scope).'\' AND collection_id = '.((int) $collectionId)
			.' AND datec < DATE_SUB(NOW(), INTERVAL '.$retentionDays.' DAY)';
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to prune the DAV sync journal');
		}
		$sql = 'SELECT MIN(rowid) AS first_token FROM '.MAIN_DB_PREFIX.'cdav_sync_change WHERE entity = '.$this->entity
			.' AND scope = \''.$this->db->escape($scope).'\' AND collection_id = '.((int) $collectionId);
		$result = $this->db->query($sql);
		$row = $result ? $this->db->fetch_object($result) : null;
		$minimum = $row && $row->first_token !== null ? max(0, (int) $row->first_token - 1) : (int) $currentToken;
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'cdav_sync_collection SET min_token = '.$minimum
			.' WHERE entity = '.$this->entity.' AND scope = \''.$this->db->escape($scope).'\''
			.' AND collection_id = '.((int) $collectionId);
		if (!$this->db->query($sql)) {
			throw new \RuntimeException('Unable to update the DAV sync retention boundary');
		}
	}
}
