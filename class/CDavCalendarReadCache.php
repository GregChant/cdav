<?php

namespace Dolibarr\CDav;

/** Bounded positive calendar snapshots, owned by one authenticated HTTP request. */
class CalendarReadCache
{
	public const MAX_BYTES = 8388608;
	public const MAX_ENTRIES = 2048;

	private $enabled;
	private $objects = array();
	private $collections = array();
	private $costs = array();
	private $bytes = 0;
	private $entries = 0;

	/**
	 * @param bool $enabled Whether this request permits read reuse
	 */
	public function __construct($enabled = false)
	{
		$this->enabled = (bool) $enabled;
	}

	/** @return bool Whether this request still permits cached reads */
	public function isEnabled()
	{
		return $this->enabled;
	}

	/**
	 * Permanently abandon all read snapshots before any native mutation.
	 * @return void
	 */
	public function disable()
	{
		$this->enabled = false;
		$this->objects = $this->collections = $this->costs = array();
		$this->bytes = $this->entries = 0;
	}

	/**
	 * Return only a positively observed representation, never an inferred absence.
	 * @param string $context Authenticated calendar context
	 * @param string $uri Actual resource URI
	 * @return array|null Complete serialized object
	 */
	public function getObject($context, $uri)
	{
		return $this->enabled ? ($this->objects[$context][$uri] ?? null) : null;
	}

	/**
	 * Charge serialized bytes and metadata before storing one complete representation.
	 * @param string $context Authenticated calendar context
	 * @param array $object Complete native representation, without VObject instances
	 * @return bool Whether this exact representation was retained
	 */
	public function putObject($context, array $object)
	{
		if (!$this->enabled) return false;
		$uri = (string) $object['uri'];
		$current = $this->objects[$context][$uri] ?? null;
		if ($current !== null && (string) $current['id'] !== (string) $object['id']) {
			// Legacy duplicate URI mappings must keep their native ORDER BY lookup.
			$this->disable();
			return false;
		}
		$oldCost = $this->costs[$context]['objects'][$uri] ?? 0;
		if ($oldCost === 0 && $this->entries >= self::MAX_ENTRIES) return false;
		$cost = strlen(serialize($object)) + strlen($context) + strlen($uri) + 256;
		if ($this->bytes - $oldCost + $cost > self::MAX_BYTES || ($oldCost === 0 && $this->entries >= self::MAX_ENTRIES)) {
			if ($oldCost > 0) {
				unset($this->objects[$context][$uri], $this->costs[$context]['objects'][$uri]);
				$this->bytes -= $oldCost;
				$this->entries--;
			}
			return false;
		}
		$this->objects[$context][$uri] = $object;
		$this->costs[$context]['objects'][$uri] = $cost;
		$this->bytes += $cost - $oldCost;
		if ($oldCost === 0) $this->entries++;
		return true;
	}

	/**
	 * Reuse a listing only if every member of its completed snapshot remains present.
	 * @param string $context Authenticated calendar context
	 * @return array|null Complete positive listing, null when it must be read again
	 */
	public function getCollection($context)
	{
		if (!$this->enabled || !isset($this->collections[$context])) return null;
		$objects = array();
		foreach ($this->collections[$context] as $uri) {
			if (!isset($this->objects[$context][$uri])) return null;
			$objects[] = $this->objects[$context][$uri];
		}
		return $objects;
	}

	/**
	 * Publish membership only after the entire native enumeration succeeded.
	 * @param string $context Authenticated calendar context
	 * @param array $uris Observed members in native order
	 * @return bool Whether the complete listing fits the same bounded cache
	 */
	public function completeCollection($context, array $uris)
	{
		if (!$this->enabled || count($uris) > self::MAX_ENTRIES || count($uris) !== count(array_unique($uris))) return false;
		foreach ($uris as $uri) {
			if (!isset($this->objects[$context][$uri])) return false;
		}
		$oldCost = $this->costs[$context]['collection'] ?? 0;
		$cost = strlen(serialize($uris)) + strlen($context) + 128;
		if ($this->bytes - $oldCost + $cost > self::MAX_BYTES || ($oldCost === 0 && $this->entries >= self::MAX_ENTRIES)) return false;
		$this->collections[$context] = $uris;
		$this->costs[$context]['collection'] = $cost;
		$this->bytes += $cost - $oldCost;
		if ($oldCost === 0) $this->entries++;
		return true;
	}

	/**
	 * Discard partial enumeration results after a failed native read.
	 * @param string $context Authenticated calendar context
	 * @return void
	 */
	public function forgetContext($context)
	{
		foreach ($this->costs[$context]['objects'] ?? array() as $cost) {
			$this->bytes -= $cost;
			$this->entries--;
		}
		if (isset($this->costs[$context]['collection'])) {
			$this->bytes -= $this->costs[$context]['collection'];
			$this->entries--;
		}
		unset($this->objects[$context], $this->collections[$context], $this->costs[$context]);
	}
}
