<?php

namespace Dolibarr\CDav;

/** Validate visitor details before any native Agenda mutation. */
class BookingDetails
{
	/**
	 * Normalize current details while recognizing an old receipt-only payload.
	 *
	 * @param array $contact Visitor-provided contact, subject and address
	 * @return array Canonical contact fields and legacy-receipt flag
	 */
	public static function normalize(array $contact): array
	{
		$clean = array();
		// Keep these field order, byte limits and trimming rules for old receipts.
		foreach (array('name' => 120, 'email' => 254, 'phone' => 40) as $field => $limit) {
			$value = $contact[$field] ?? '';
			if (!is_string($value) || strlen($value) > $limit || !mb_check_encoding($value, 'UTF-8')
				|| preg_match('/[\x00-\x1f\x7f<>]/u', $value)) {
				throw new \InvalidArgumentException('Invalid booking contact details');
			}
			$clean[$field] = trim($value);
		}
		if ($clean['name'] === '' || !isValidEmail($clean['email']) || !empty($contact['website'])) {
			throw new \InvalidArgumentException('Invalid booking contact details');
		}
		$hasSubject = array_key_exists('subject', $contact);
		$hasAddress = array_key_exists('address', $contact);
		if (!$hasSubject && !$hasAddress) return array('contact' => $clean, 'legacy' => true);
		if (!$hasSubject || !$hasAddress) throw new \InvalidArgumentException('Incomplete booking details');
		foreach (array('subject' => 120, 'address' => 128) as $field => $limit) {
			$value = $contact[$field];
			if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
				throw new \InvalidArgumentException('Invalid booking details');
			}
			if ($field === 'address') $value = str_replace(array("\r\n", "\r"), "\n", $value);
			$invalid = $field === 'address' ? '/[\x00-\x09\x0b-\x1f\x7f<>]/u' : '/[\x00-\x1f\x7f<>]/u';
			if (preg_match($invalid, $value)) throw new \InvalidArgumentException('Invalid booking details');
			$value = trim($value);
			// ActionComm treats the location string "0" as empty during creation.
			if ($value === '' || ($field === 'address' && $value === '0') || mb_strlen($value, 'UTF-8') > $limit) {
				throw new \InvalidArgumentException('Invalid booking details length');
			}
			$clean[$field] = $value;
		}
		return array('contact' => $clean, 'legacy' => false);
	}
}
