<?php

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once dirname(__DIR__, 2).'/class/CDavAppointmentStatus.php';

/** Synchronize native Agenda appointment states within their business transaction. */
class InterfaceCDavAppointmentStatus extends DolibarrTriggers
{
	/**
	 * Set native trigger metadata.
	 *
	 * @param DoliDB $db Native connection
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = 'CDavAppointmentStatus';
		$this->family = 'agenda';
		$this->description = 'Keep native appointment state and CDav scheduling metadata consistent';
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'action';
	}

	/**
	 * Validate and synchronize an actual native Agenda write.
	 *
	 * @param string $action Trigger code
	 * @param CommonObject $object Native business object
	 * @param User $user Authenticated actor
	 * @param Translate $langs Native translations
	 * @param Conf $conf Native configuration
	 * @return int Positive on synchronization, zero when unrelated, negative on rejection
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('cdav') || !in_array($action, array('ACTION_CREATE', 'ACTION_MODIFY'), true)
			|| $object->table_element !== 'actioncomm') return 0;
		try {
			\Dolibarr\CDav\AppointmentStatus::synchronizeNative($this->db, $object, $user, $action);
			return 1;
		} catch (\Throwable $error) {
			$langs->load('cdav@cdav');
			$this->error = $langs->trans('CDavAppointmentStatusWriteFailed');
			$this->errors[] = $this->error;
			$object->error = $this->error;
			$object->errors[] = $this->error;
			dol_syslog(__METHOD__.': '.$error->getMessage(), LOG_ERR);
			return -1;
		}
	}
}
