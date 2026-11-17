<?php

namespace Dolibarr\CDav;

/** Read native Agenda files using the same authenticated user's event rights. */
class AgendaDocumentStore
{
	private $db;
	private $user;
	public function __construct($db, $user) { $this->db = $db; $this->user = $user; }

	public function visibleEvent($eventId)
	{
		if (!preg_match('/^[1-9][0-9]*$/D', (string) $eventId)
			|| !$this->user->hasRight('agenda', 'myactions', 'read')) {
			throw new \Sabre\DAV\Exception\NotFound('Agenda document not found');
		}
		$sql = 'SELECT a.id, a.entity FROM '.MAIN_DB_PREFIX.'actioncomm a'
			.' WHERE a.id = '.((int) $eventId).' AND a.entity IN ('.getEntity('agenda').')';
		if (!$this->user->hasRight('agenda', 'allactions', 'read')) {
			$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'actioncomm_resources ar'
				." WHERE ar.fk_actioncomm = a.id AND ar.element_type = 'user' AND ar.fk_element = ".((int) $this->user->id).')';
		}
		$result = $this->db->query($sql);
		$row = $result ? $this->db->fetch_object($result) : null;
		if (!$row) throw new \Sabre\DAV\Exception\NotFound('Agenda document not found');
		return $row;
	}

	public function readable($eventId, $filename)
	{
		global $conf;
		$event = $this->visibleEvent($eventId);
		$filename = (string) $filename;
		if ($filename === '' || $filename === '.' || $filename === '..'
			|| $filename[0] === '.' || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename)
			|| preg_match('/(?:\.meta|_preview.*\.png)$/i', $filename)) {
			throw new \Sabre\DAV\Exception\NotFound('Agenda document not found');
		}
		$root = $conf->agenda->multidir_output[(int) $event->entity] ?? ($conf->agenda->dir_output ?? '');
		$rootPath = $root !== '' ? realpath($root) : false;
		$directory = $rootPath !== false ? $rootPath.'/'.dol_sanitizeFileName((string) $event->id) : '';
		$path = $directory.'/'.$filename;
		$realPath = realpath($path);
		if ($rootPath === false || $directory === '' || is_link($directory) || is_link($path)
			|| $realPath === false || dirname($realPath) !== $directory || !is_file($realPath) || !is_readable($realPath)) {
			throw new \Sabre\DAV\Exception\NotFound('Agenda document not found');
		}
		return (object) array('path' => $realPath, 'filename' => $filename,
			'principal' => 'principals/'.(string) $this->user->login);
	}
}

/** These read-only collections deliberately do not enumerate event IDs/files. */
class AgendaDocumentCollection extends \Sabre\DAV\Collection implements \Sabre\DAVACL\IACL
{
	use \Sabre\DAVACL\ACLTrait;
	private $store;
	private $user;
	private $eventId;
	public function __construct($store, $user, $eventId = '') { $this->store = $store; $this->user = $user; $this->eventId = (string) $eventId; }
	public function getName() { return $this->eventId === '' ? 'agenda-documents' : $this->eventId; }
	public function getOwner() { return 'principals/'.(string) $this->user->login; }
	public function getACL() { return array(array('privilege' => '{DAV:}read', 'principal' => $this->getOwner(), 'protected' => true)); }
	public function getChildren() { return array(); }
	public function getChild($name) {
		if ($this->eventId !== '') return new AgendaDocumentFile($this->store->readable($this->eventId, $name));
		$this->store->visibleEvent($name);
		return new self($this->store, $this->user, $name);
	}
	public function childExists($name) { try { $this->getChild($name); return true; } catch (\Throwable $e) { return false; } }
}

class AgendaDocumentFile extends \Sabre\DAV\File implements \Sabre\DAVACL\IACL
{
	use \Sabre\DAVACL\ACLTrait;
	private $row;
	public function __construct($row) { $this->row = $row; }
	public function getName() { return $this->row->filename; }
	public function get() { return fopen($this->row->path, 'rb'); }
	public function getSize() { return filesize($this->row->path); }
	public function getETag() { return '"'.hash_file('sha256', $this->row->path).'"'; }
	public function getContentType() { return dol_mimetype($this->row->filename); }
	public function getLastModified() { return filemtime($this->row->path); }
	public function getOwner() { return $this->row->principal; }
	public function getACL() { return array(array('privilege' => '{DAV:}read', 'principal' => $this->row->principal, 'protected' => true)); }
	public function getContentDisposition() {
		$fallback = preg_replace('/[^ -~]/', '_', $this->row->filename);
		return 'attachment; filename="'.addcslashes($fallback, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($this->row->filename);
	}
}

/** Download headers also apply when managed uploads are disabled. */
class AgendaDocumentPlugin extends \Sabre\DAV\ServerPlugin
{
	private $server;
	public function initialize(\Sabre\DAV\Server $server) {
		$this->server = $server;
		$server->on('afterMethod:GET', array($this, 'httpAfterGet'), 250);
	}
	public function getPluginName() { return 'cdav-agenda-documents'; }
	public function httpAfterGet($request, $response) {
		try { $node = $this->server->tree->getNodeForPath($request->getPath()); } catch (\Throwable $e) { return; }
		if ($node instanceof AgendaDocumentFile) $response->setHeader('Content-Disposition', $node->getContentDisposition());
	}
}
