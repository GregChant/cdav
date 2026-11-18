<?php

namespace Dolibarr\CDav;

/** Send separate native confirmations only after the Agenda transaction commits. */
class BookingNotification
{
	private $db;
	private $entity;

	/**
	 * Bind the same native connection and entity used for the committed receipt.
	 * @param \DoliDB $db Native database connection
	 * @param int $entity Current entity
	 */
	public function __construct($db, int $entity)
	{
		$this->db = $db;
		$this->entity = max(1, $entity);
	}

	/**
	 * Prepare plain-text messages without exposing the collaborator's private mail.
	 * @param array $profile Validated server-owned booking profile
	 * @param array $contact Canonical visitor details
	 * @param array $selection Canonical booked periods
	 * @param string $reference Public booking reference
	 * @param \User $owner Native collaborator, including private email and language
	 * @return array Separate customer and owner envelopes for native CMailFile
	 */
	public function messages(array $profile, array $contact, array $selection, string $reference, $owner): array
	{
		global $conf, $langs, $mysoc;
		require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		if (!preg_match('/^[a-f0-9]{32}$/D', $reference)
			|| !is_string($contact['subject'] ?? null) || !mb_check_encoding($contact['subject'], 'UTF-8')
			|| preg_match('/[\x00-\x1f\x7f]/u', $contact['subject'])
			|| !is_array($selection['segments'] ?? null) || !$selection['segments'] || count($selection['segments']) > 24) {
			throw new \InvalidArgumentException('Invalid booking confirmation content');
		}
		$timezone = new \DateTimeZone($profile['config']['timezone']);
		$periods = array();
		foreach ($selection['segments'] as $segment) {
			if (!is_int($segment['start'] ?? null) || !is_int($segment['end'] ?? null) || $segment['end'] <= $segment['start']) {
				throw new \InvalidArgumentException('Invalid booking confirmation period');
			}
			$periods[] = (new \DateTimeImmutable('@'.$segment['start']))->setTimezone($timezone)->format('Y-m-d H:i P')
				.' – '.(new \DateTimeImmutable('@'.$segment['end']))->setTimezone($timezone)->format('Y-m-d H:i P');
		}
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		if ($from === '') $from = (string) ($mysoc->email ?? getDolGlobalString('MAIN_INFO_SOCIETE_MAIL'));
		if (preg_match('/[\x00-\x1f\x7f]/', $from) || !isValidEmail(\CMailFile::getValidAddress($from, 2))) $from = '';
		$defaultLanguage = (string) ($langs->defaultlang ?? 'en_US');
		$messages = array();
		foreach (array('customer' => $defaultLanguage, 'owner' => (string) ($owner->lang ?? $defaultLanguage)) as $role => $locale) {
			$language = new \Translate('', $conf);
			$language->setDefaultLang(preg_match('/^[a-z]{2,3}(?:_[A-Za-z0-9]{2,8})?$/D', $locale) ? $locale : $defaultLanguage);
			// Native lookup first, with the current module parent also available for CLI tests.
			$language->dir = array_values(array_unique(array_merge($language->dir, array(dirname(__DIR__, 2)))));
			$language->loadLangs(array('main', 'cdav@cdav'));
			$lines = array((string) ($mysoc->name ?? ''),
				$language->transnoentitiesnoconv($role === 'customer' ? 'CDavBookingMailCustomerIntro' : 'CDavBookingMailOwnerIntro'), '',
				$language->transnoentitiesnoconv('CDavBookingMailReference').': '.$reference,
				$language->transnoentitiesnoconv('CDavBookingMailProfile').': '.((string) ($profile['label'] ?? '')),
				$language->transnoentitiesnoconv('CDavBookingMailCollaborator').': '.((string) ($profile['professional']['name'] ?? '')),
				$language->transnoentitiesnoconv('CDavBookingSubject').': '.$contact['subject'],
				$language->transnoentitiesnoconv('CDavBookingClientAddress').":\n".((string) ($contact['address'] ?? '')),
				$language->transnoentitiesnoconv('CDavBookingTimezone').': '.$timezone->getName(),
				$language->transnoentitiesnoconv('CDavBookingMailPeriods').":\n".implode("\n", $periods), '',
				$language->transnoentitiesnoconv('CDavBookingMailCustomer').': '.((string) ($contact['name'] ?? '')),
				$language->transnoentitiesnoconv('Email').': '.((string) ($contact['email'] ?? '')),
				$language->transnoentitiesnoconv('Phone').': '.((string) ($contact['phone'] ?? '')));
			$messages[$role] = array('to' => $role === 'customer' ? (string) ($contact['email'] ?? '') : (string) ($owner->email ?? ''),
				'from' => $from, 'subject' => $language->transnoentitiesnoconv('CDavBookingMailSubject', $contact['subject']),
				'body' => implode("\n", $lines), 'language' => $language->defaultlang);
		}
		return $messages;
	}

	/**
	 * Claim each delivery once, separately from the already committed reservation.
	 * States: 0 pending, 1 claimed, 2 accepted by native mailer, 3 failed. A claim
	 * is committed before SMTP, so a replay or an ambiguous transport failure
	 * cannot send a duplicate. Delivery failure never rolls back Agenda events.
	 * @param string $requestHash Committed request hash
	 * @param array $profile Validated server-owned booking profile
	 * @param array $contact Canonical visitor details
	 * @param array $selection Canonical booked periods
	 * @param string $reference Public booking reference
	 * @param \User $owner Native collaborator including private email
	 * @return array Generic warning and technical role statuses without addresses
	 */
	public function notify(string $requestHash, array $profile, array $contact, array $selection, string $reference, $owner): array
	{
		$result = array('notification_warning' => false, 'customer' => 'failed', 'owner' => 'failed');
		if (!$this->db || !empty($this->db->transaction_opened) || !preg_match('/^[a-f0-9]{64}$/D', $requestHash)
			|| !preg_match('/^[a-f0-9]{32}$/D', $reference) || empty($profile['id']) || empty($owner->id)
			|| (int) ($profile['owner_id'] ?? 0) !== (int) $owner->id) {
			$result['notification_warning'] = true;
			return $result;
		}
		$messages = array();
		try {
			$messages = $this->messages($profile, $contact, $selection, $reference, $owner);
		} catch (\Throwable $e) {
			dol_syslog('CDav booking confirmation preparation failed: '.get_class($e), LOG_ERR);
		}
		$scope = "request_hash = '".$this->db->escape($requestHash)."' AND fk_profile = ".((int) $profile['id'])
			." AND reference = '".$this->db->escape($reference)."' AND fk_profile IN (SELECT rowid FROM ".MAIN_DB_PREFIX
			.'cdav_booking_profile WHERE entity = '.$this->entity.' AND fk_user = '.((int) $owner->id).')';
		foreach (array('customer' => 'mail_customer', 'owner' => 'mail_owner') as $role => $column) {
			try {
				$claim = $this->db->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_request SET '.$column.' = 1 WHERE '.$scope.' AND '.$column.' = 0');
				if (!$claim) throw new \RuntimeException('Cannot claim booking confirmation');
				if ((int) $this->db->affected_rows($claim) !== 1) {
					$read = $this->db->query('SELECT '.$column.' AS mail_state FROM '.MAIN_DB_PREFIX.'cdav_booking_request WHERE '.$scope);
					$state = $read ? $this->db->fetch_object($read) : false;
					$result[$role] = $state && (int) $state->mail_state === 2 ? 'sent' : 'unconfirmed';
					if ($result[$role] !== 'sent') $result['notification_warning'] = true;
					continue;
				}
				$sent = false;
				$message = $messages[$role] ?? array();
				try {
					if (!getDolGlobalInt('MAIN_DISABLE_ALL_MAILS') && ($message['from'] ?? '') !== '' && isValidEmail($message['to'] ?? '')) {
						$mail = new \CMailFile($message['subject'], $message['to'], $message['from'], $message['body'],
							array(), array(), array(), '', '', 0, 0, getDolGlobalString('MAIN_MAIL_ERRORS_TO'), '',
							'cdavbooking_'.$reference.'_'.$role);
						$sent = $mail->error === '' && $mail->sendfile() === true;
					}
				} catch (\Throwable $e) {
					dol_syslog('CDav booking confirmation transport failed: '.get_class($e), LOG_ERR);
				}
				$stored = $this->db->query('UPDATE '.MAIN_DB_PREFIX.'cdav_booking_request SET '.$column.' = '.($sent ? 2 : 3)
					.' WHERE '.$scope.' AND '.$column.' = 1');
				$result[$role] = $sent && $stored ? 'sent' : 'failed';
				if ($result[$role] !== 'sent') $result['notification_warning'] = true;
			} catch (\Throwable $e) {
				$result['notification_warning'] = true;
				dol_syslog('CDav booking confirmation state failed: '.get_class($e), LOG_ERR);
			}
		}
		return $result;
	}
}
