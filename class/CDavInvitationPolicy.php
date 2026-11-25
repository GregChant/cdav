<?php

namespace Dolibarr\CDav;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\PreconditionFailed;
use Sabre\VObject;

require_once __DIR__.'/CDavCalendarUid.php';
require_once __DIR__.'/CDavCalendarWritePolicy.php';

/** Authorize invitation responses without trusting client copies of business data. */
class InvitationPolicy
{
	/**
	 * Merge an authenticated attendee response or an organizer change.
	 *
	 * @param string $current Authoritative current resource under the backend lock
	 * @param string $incoming Incoming full calendar resource, without an iTIP METHOD
	 * @param string $actorEmail Verified authenticated scheduling identity
	 * @param bool $protectBusiness Whether only organizer date moves are allowed
	 * @param bool $allowExternalImport Backend-verified private DAV copy of an external invitation
	 * @return array<string,mixed>|null Canonical plan, or null for a non-invitation
	 */
	public static function plan($current, $incoming, $actorEmail, $protectBusiness = true, $allowExternalImport = false)
	{
		$before = self::read($current);
		$after = null;
		try {
			$events = self::events($before);
			if (!$events) return null;
			$old = self::master($events);
			if (!isset($old->ORGANIZER) || !$old->select('ATTENDEE')) return null;
			$after = self::read($incoming);
			$newEvents = self::events($after);
			if (!$newEvents) throw new Forbidden('An invitation must remain a VEVENT.');
			$new = self::master($newEvents);
			foreach (array_merge($events, $newEvents) as $event) self::status($event);
			$organizer = self::organizer($old);
			if (CalendarUid::read($old) !== CalendarUid::read($new) || self::organizer($new) !== $organizer) {
				throw new Forbidden('The invitation UID and organizer cannot be changed.');
			}
			$actor = self::email($actorEmail);
			$oldAttendees = self::attendees($old);
			$newAttendees = self::attendees($new);
			$isOrganizer = $actor !== '' && $actor === $organizer;
			if (!$isOrganizer && ($actor === '' || !isset($oldAttendees[$actor]))) {
				throw new Forbidden('Only the organizer or an authenticated attendee may change an invitation.');
			}
			$sequence = self::sequence($old);
			$incomingSequence = self::sequence($new);
			if ((!$isOrganizer && ($incomingSequence < $sequence || ((!$allowExternalImport || $protectBusiness) && $incomingSequence > $sequence + 1)))
				|| ($isOrganizer && isset($new->SEQUENCE) && $incomingSequence < $sequence)) {
				throw new PreconditionFailed('This invitation response refers to an outdated or unknown organizer revision.', 'If-Match');
			}
			if (self::method($before) !== self::method($after)) {
				throw new Forbidden('Scheduling messages must be processed through the invitation endpoint, not stored as calendar resources.');
			}
			$currentData = $before->serialize();
			if (count($events) !== 1 || count($newEvents) !== 1 || isset($old->{'RECURRENCE-ID'}) || isset($new->{'RECURRENCE-ID'})) {
				if (self::sameScopedEcho($before, $after, $events, $newEvents)) {
					return self::result('noop', $currentData, false, $old, $organizer, $sequence, $isOrganizer, null, null, false);
				}
				throw new Forbidden('Changes and responses scoped to recurrence exceptions are not supported; use the full original invitation.');
			}
			if ($isOrganizer) {
				return self::organizerPlan($before, $after, $old, $new, $oldAttendees, $newAttendees, $organizer, $sequence, (bool) $protectBusiness);
			}
			if ($allowExternalImport && !$protectBusiness) {
				$personalDecline = isset($newAttendees[$actor]) && self::partstat($newAttendees[$actor]) === 'DECLINED'
					&& self::partstat($oldAttendees[$actor]) !== 'DECLINED';
				if (self::status($new) === 'CANCELLED' && self::status($old) !== 'CANCELLED' && !$personalDecline) {
					return self::externalImportPlan($before, $after, $old, $new, $newAttendees, $actor, $organizer, $incomingSequence);
				}
				try {
					$reply = self::replyPlan($before, $after, $old, $new, $oldAttendees, $newAttendees, $actor, $organizer, $sequence);
					if ($incomingSequence > $sequence + 1) {
						throw new PreconditionFailed('This personal response does not match the organizer revision.', 'If-Match');
					}
					return $reply;
				} catch (Forbidden $exception) {
					return self::externalImportPlan($before, $after, $old, $new, $newAttendees, $actor, $organizer, $incomingSequence);
				}
			}
			return self::replyPlan($before, $after, $old, $new, $oldAttendees, $newAttendees, $actor, $organizer, $sequence);
		} finally {
			$before->destroy();
			if ($after !== null) $after->destroy();
		}
	}

	/**
	 * Import Outlook's private copy of an external organizer update, never a native shared event.
	 * The caller must establish immutable DAV origin, sole ownership and an external organizer.
	 *
	 * @param VObject\Component $before Current calendar
	 * @param VObject\Component $after Imported calendar
	 * @param VObject\Component $old Current event
	 * @param VObject\Component $new Imported event
	 * @param array $attendees Imported attendees
	 * @param string $actor Verified private calendar owner
	 * @param string $organizer Stable external organizer
	 * @param int $sequence External organizer revision
	 * @return array<string,mixed> External transport plan without organizer authority
	 */
	private static function externalImportPlan($before, $after, $old, $new, array $attendees, $actor, $organizer, $sequence)
	{
		$cancel = self::status($new) === 'CANCELLED';
		if (self::status($old) === 'CANCELLED') {
			throw new Forbidden('A cancelled external invitation cannot be restored or changed through CalDAV.');
		}
		if (!isset($attendees[$actor]) && !$cancel) {
			throw new Forbidden('The owner of an imported external invitation must remain an attendee.');
		}
		if (isset($attendees[$actor])) {
			$partstat = self::partstat($attendees[$actor]);
			if (!in_array($partstat, array('ACCEPTED', 'TENTATIVE', 'DECLINED', 'NEEDS-ACTION'), true)) {
				throw new Forbidden('The external invitation contains an unsupported personal response.');
			}
			self::rsvp($attendees[$actor]);
		}
		$oldDates = CalendarWritePolicy::assertMoveOnly($before->serialize(), $before->serialize());
		$newDates = CalendarWritePolicy::assertMoveOnly($after->serialize(), $after->serialize());
		if ($cancel) {
			$old->STATUS = 'CANCELLED';
			$old->TRANSP = 'TRANSPARENT';
			$old->SEQUENCE = $sequence;
			$plan = self::result('cancel', $before->serialize(), true, $old, $organizer, $sequence, false, null, null, false);
		} else {
			$new->SEQUENCE = $sequence;
			$plan = self::result('organizer', $after->serialize(), true, $new, $organizer, $sequence, false, null, null, $oldDates !== $newDates);
		}
		$plan['external_import'] = true;
		return $plan;
	}

	/**
	 * Merge only the responding user's own state into the current invitation.
	 *
	 * @param VObject\Component $before Current calendar
	 * @param VObject\Component $after Incoming calendar
	 * @param VObject\Component $old Current master
	 * @param VObject\Component $new Incoming master
	 * @param array $oldAttendees Current attendee properties
	 * @param array $newAttendees Incoming attendee properties
	 * @param string $actor Verified actor
	 * @param string $organizer Organizer email
	 * @param int $sequence Authoritative organizer revision
	 * @return array<string,mixed> Canonical personal response
	 */
	private static function replyPlan($before, $after, $old, $new, array $oldAttendees, array $newAttendees, $actor, $organizer, $sequence)
	{
		if (array_keys($oldAttendees) !== array_keys($newAttendees)) {
			throw new Forbidden('An attendee cannot add, remove or replace invitation participants.');
		}
		$oldPartstat = self::partstat($oldAttendees[$actor]);
		$partstat = self::partstat($newAttendees[$actor]);
		if (!in_array($partstat, array('ACCEPTED', 'TENTATIVE', 'DECLINED', 'NEEDS-ACTION'), true) && $partstat !== $oldPartstat) {
			throw new Forbidden('This attendee response is not supported.');
		}
		$rsvp = self::rsvp($newAttendees[$actor]);
		$responseChanged = $partstat !== $oldPartstat || $rsvp !== self::rsvp($oldAttendees[$actor]);
		$changed = $responseChanged;
		$personal = $partstat === 'DECLINED' ? 0 : 1;
		$incomingTransparency = self::transparency($new);
		if ($incomingTransparency === $personal && self::transparency($old) !== $personal) $changed = true;
		$status = isset($new->STATUS) ? strtoupper(trim((string) $new->STATUS)) : '';
		if (!in_array($status, array('', 'CONFIRMED', 'TENTATIVE', 'CANCELLED'), true)
			|| ($status === 'CANCELLED' && $partstat !== 'DECLINED' && self::status($old) !== 'CANCELLED')) {
			throw new Forbidden('An attendee cannot cancel the shared invitation.');
		}
		if (self::status($old) === 'CANCELLED' && ($changed || $status !== 'CANCELLED')) {
			throw new Forbidden('A cancelled invitation cannot be accepted or restored by an attendee.');
		}
		$comparison = clone $after;
		$baseline = clone $before;
		try {
			$test = self::master(self::events($comparison));
			$base = self::master(self::events($baseline));
			self::preserveResponses($test, $oldAttendees, false);
			// Outlook may map its own display name or scheduling-agent hints differently.
			self::copyParameter($test->select('ATTENDEE'), $actor, $oldAttendees[$actor], 'CN');
			CalendarWritePolicy::normalizeCancelledSummary($old, $test);
			self::copyProperty($test, $old, 'STATUS');
			self::copyProperty($test, $old, 'TRANSP');
			self::copyAlarms($test, $old);
			// Invitation bodies sent by email/inbox need not contain the CRM envelope.
			// A real personal response never imports its description into native notes.
			if ($responseChanged) self::copyProperty($test, $old, 'DESCRIPTION');
			self::normalizeDefaults($test);
			self::normalizeDefaults($base);
			$dates = CalendarWritePolicy::assertMoveOnly($baseline->serialize(), $comparison->serialize());
			$oldDates = CalendarWritePolicy::assertMoveOnly($baseline->serialize(), $baseline->serialize());
			if ($dates !== $oldDates) throw new Forbidden('An attendee response cannot move or resize the invitation.');
		} finally {
			$comparison->destroy();
			$baseline->destroy();
		}
		if ($changed) {
			$oldAttendees[$actor]['PARTSTAT'] = $partstat;
			if (isset($newAttendees[$actor]['RSVP'])) $oldAttendees[$actor]['RSVP'] = $rsvp ? 'TRUE' : 'FALSE';
			else unset($oldAttendees[$actor]['RSVP']);
		}
		return self::result($changed ? 'reply' : 'noop', $before->serialize(), $changed, $old, $organizer, $sequence, false, $partstat, $personal, false);
	}

	/**
	 * Validate organizer authority and retain the actual responses of existing attendees.
	 *
	 * @param VObject\Component $before Current calendar
	 * @param VObject\Component $after Incoming calendar
	 * @param VObject\Component $old Current master
	 * @param VObject\Component $new Incoming master
	 * @param array $oldAttendees Current attendees
	 * @param array $newAttendees Incoming attendees
	 * @param string $organizer Organizer email
	 * @param int $sequence Authoritative revision
	 * @param bool $protectBusiness Whether native business fields are protected
	 * @return array<string,mixed> Canonical organizer operation
	 */
	private static function organizerPlan($before, $after, $old, $new, array $oldAttendees, array $newAttendees, $organizer, $sequence, $protectBusiness)
	{
		self::preserveResponses($new, $oldAttendees, true);
		$status = CalendarWritePolicy::normalizeAppointmentStatus($old, $new);
		$oldStatus = self::status($old);
		$statusChanged = $status !== $oldStatus;
		$cancel = $status === 'CANCELLED';
		$alreadyCancelled = $oldStatus === 'CANCELLED';
		if ($alreadyCancelled && !$cancel) throw new Forbidden('A cancelled invitation cannot be restored through CalDAV.');
		$baseline = clone $before;
		$comparison = clone $after;
		try {
			$base = self::master(self::events($baseline));
			$test = self::master(self::events($comparison));
			// Global state changes are allowed without opening native business fields.
			CalendarWritePolicy::normalizeCancelledSummary($old, $test);
			$base->STATUS = $oldStatus;
			$test->STATUS = $oldStatus;
			if ($cancel) {
				self::copyProperty($test, $old, 'TRANSP');
				self::copyAlarms($test, $old);
			}
			self::normalizeDefaults($base);
			self::normalizeDefaults($test);
			$oldDates = CalendarWritePolicy::assertMoveOnly($baseline->serialize(), $baseline->serialize());
			$newDates = CalendarWritePolicy::assertMoveOnly($comparison->serialize(), $comparison->serialize());
			$dateChanged = $oldDates !== $newDates;
			$protectedMatch = true;
			try {
				CalendarWritePolicy::assertMoveOnly($baseline->serialize(), $comparison->serialize());
			} catch (Forbidden $exception) {
				$protectedMatch = false;
			}
			$extensionChanged = self::extensions($base) !== self::extensions($test);
			if (($protectBusiness || $cancel || $alreadyCancelled) && (!$protectedMatch || ($cancel && $dateChanged))) {
				throw new Forbidden('Only the organizer dates or appointment status may change this protected invitation.');
			}
			$changed = $dateChanged || $statusChanged || !$protectedMatch || (!$protectBusiness && $extensionChanged);
			if ($alreadyCancelled && $changed) throw new Forbidden('A cancelled invitation cannot be modified through CalDAV.');
		} finally {
			$baseline->destroy();
			$comparison->destroy();
		}
		if (!$changed) return self::result('noop', $before->serialize(), false, $old, $organizer, $sequence, true, null, null, false);
		if ($sequence >= 2147483647) throw new Forbidden('The organizer revision cannot be advanced safely.');
		$sequence++;
		if ($cancel) {
			$old->STATUS = 'CANCELLED';
			$old->TRANSP = 'TRANSPARENT';
			$old->SEQUENCE = $sequence;
			return self::result('cancel', $before->serialize(), true, $old, $organizer, $sequence, true, null, null, false);
		}
		if ($protectBusiness) {
			self::copyProperty($old, $new, 'DTSTART');
			self::copyProperty($old, $new, 'DTEND');
			self::copyProperty($old, $new, 'DUE');
			self::copyProperty($old, $new, 'DURATION');
			$old->STATUS = $status;
			$old->SEQUENCE = $sequence;
			return self::result('organizer', $before->serialize(), true, $old, $organizer, $sequence, true, null, null, $dateChanged);
		}
		$new->STATUS = $status;
		$new->SEQUENCE = $sequence;
		return self::result('organizer', $after->serialize(), true, $new, $organizer, $sequence, true, null, null, $dateChanged);
	}

	/**
	 * Copy response parameters from current participants, never from stale client copies.
	 *
	 * @param VObject\Component $event Incoming event
	 * @param array $current Current attendee properties
	 * @param bool $allowNew Whether the organizer may introduce new participants
	 * @return void
	 */
	private static function preserveResponses($event, array $current, $allowNew)
	{
		foreach ($event->select('ATTENDEE') as $property) {
			$key = self::address((string) $property);
			if (!isset($current[$key])) {
				if (!$allowNew) throw new Forbidden('Invitation participants cannot be replaced by an attendee.');
				$property['PARTSTAT'] = 'NEEDS-ACTION';
				$property['RSVP'] = 'TRUE';
				continue;
			}
			foreach (array('PARTSTAT', 'RSVP', 'SCHEDULE-STATUS', 'SCHEDULE-FORCE-SEND', 'SCHEDULE-AGENT') as $name) {
				if (isset($current[$key][$name])) $property[$name] = (string) $current[$key][$name];
				else unset($property[$name]);
			}
		}
	}

	/**
	 * Retain a participant parameter whose presentation is not a business write.
	 *
	 * @param array $properties Incoming attendee properties
	 * @param string $actor Actor address
	 * @param object $source Current actor property
	 * @param string $parameter Parameter to retain
	 * @return void
	 */
	private static function copyParameter(array $properties, $actor, $source, $parameter)
	{
		foreach ($properties as $property) {
			if (self::address((string) $property) !== $actor) continue;
			if (isset($source[$parameter])) $property[$parameter] = (string) $source[$parameter];
			else unset($property[$parameter]);
		}
	}

	/**
	 * Normalize explicit RFC defaults before comparing immutable attendee data.
	 *
	 * @param VObject\Component $event Invitation master
	 * @return void
	 */
	private static function normalizeDefaults($event)
	{
		foreach ($event->select('ATTENDEE') as $property) {
			foreach (array('ROLE' => 'REQ-PARTICIPANT', 'CUTYPE' => 'INDIVIDUAL') as $name => $default) {
				if (isset($property[$name]) && strtoupper(trim((string) $property[$name])) === $default) unset($property[$name]);
			}
		}
	}

	/**
	 * Preserve every native alarm when a meeting response omits or changes reminders.
	 *
	 * @param VObject\Component $target Comparison event
	 * @param VObject\Component $source Current event
	 * @return void
	 */
	private static function copyAlarms($target, $source)
	{
		$target->remove('VALARM');
		foreach ($source->select('VALARM') as $alarm) $target->add(clone $alarm);
	}

	/**
	 * Copy one authoritative calendar property including its parameters.
	 *
	 * @param VObject\Component $target Target event
	 * @param VObject\Component $source Source event
	 * @param string $name Property name
	 * @return void
	 */
	private static function copyProperty($target, $source, $name)
	{
		$target->remove($name);
		foreach ($source->select($name) as $property) $target->add(clone $property);
	}

	/**
	 * Keep exact unchanged legacy recurrence resources usable without accepting scoped edits.
	 *
	 * @param VObject\Component $before Current calendar
	 * @param VObject\Component $after Incoming calendar
	 * @param array $oldEvents Current recurrence components
	 * @param array $newEvents Incoming recurrence components
	 * @return bool Whether every component is an unchanged semantic echo
	 */
	private static function sameScopedEcho($before, $after, array $oldEvents, array $newEvents)
	{
		if (count($oldEvents) !== count($newEvents)) return false;
		$incoming = array();
		foreach ($newEvents as $event) {
			$key = CalendarUid::read($event).'|'.(isset($event->{'RECURRENCE-ID'}) ? $event->{'RECURRENCE-ID'}->serialize() : '');
			if (isset($incoming[$key])) return false;
			$incoming[$key] = $event;
		}
		foreach ($oldEvents as $event) {
			$key = CalendarUid::read($event).'|'.(isset($event->{'RECURRENCE-ID'}) ? $event->{'RECURRENCE-ID'}->serialize() : '');
			if (!isset($incoming[$key])) return false;
			$left = new VObject\Component\VCalendar();
			$right = new VObject\Component\VCalendar();
			try {
				foreach ($before->select('VTIMEZONE') as $zone) $left->add(clone $zone);
				foreach ($after->select('VTIMEZONE') as $zone) $right->add(clone $zone);
				$left->add(clone $event);
				$test = clone $incoming[$key];
				CalendarWritePolicy::normalizeCancelledSummary($event, $test);
				$right->add($test);
				$dates = CalendarWritePolicy::assertMoveOnly($left->serialize(), $right->serialize());
				if ($dates !== CalendarWritePolicy::assertMoveOnly($left->serialize(), $left->serialize())
					|| self::extensions($event) !== self::extensions($incoming[$key])) return false;
			} catch (Forbidden $exception) {
				return false;
			} finally {
				$left->destroy();
				$right->destroy();
			}
		}
		return true;
	}

	/**
	 * Detect custom property changes ignored by the native date-only policy.
	 *
	 * @param VObject\Component $component Event or nested alarm
	 * @return array<string> Stable custom properties
	 */
	private static function extensions($component)
	{
		$values = array();
		foreach ($component->children() as $child) {
			if ($child instanceof VObject\Component) {
				foreach (self::extensions($child) as $value) $values[] = $child->name.':'.$value;
			} elseif (str_starts_with($child->name, 'X-')) {
				$values[] = $child->serialize();
			}
		}
		sort($values, SORT_STRING);
		return $values;
	}

	/**
	 * Parse bounded HTTP calendar content using the native Sabre reader.
	 *
	 * @param string $data Serialized calendar
	 * @return VObject\Component Calendar object
	 */
	private static function read($data)
	{
		try {
			$calendar = VObject\Reader::read((string) $data);
		} catch (\Throwable $exception) {
			throw new BadRequest('The invitation calendar cannot be parsed.');
		}
		if ($calendar->name !== 'VCALENDAR') {
			$calendar->destroy();
			throw new BadRequest('An invitation requires a VCALENDAR resource.');
		}
		return $calendar;
	}

	/**
	 * List events without accepting other mutable component types.
	 *
	 * @param VObject\Component $calendar Calendar resource
	 * @return array<VObject\Component> VEVENT components
	 */
	private static function events($calendar)
	{
		$events = array();
		foreach ($calendar->getComponents() as $component) {
			if ($component->name === 'VEVENT') $events[] = $component;
		}
		return $events;
	}

	/**
	 * Select the original master for identity and sequence checks.
	 *
	 * @param array $events Calendar events
	 * @return VObject\Component Original master or first scoped occurrence
	 */
	private static function master(array $events)
	{
		foreach ($events as $event) if (!isset($event->{'RECURRENCE-ID'})) return $event;
		return $events[0];
	}

	/**
	 * Read the verified organizer email.
	 *
	 * @param VObject\Component $event Invitation event
	 * @return string Organizer email
	 */
	private static function organizer($event)
	{
		if (count($event->select('ORGANIZER')) !== 1 || ($email = self::email((string) $event->ORGANIZER)) === '') {
			throw new BadRequest('The invitation organizer is missing or ambiguous.');
		}
		return $email;
	}

	/**
	 * Index participant identities and reject duplicates before merging responses.
	 *
	 * @param VObject\Component $event Invitation event
	 * @return array<string,object> Attendee properties
	 */
	private static function attendees($event)
	{
		$values = array();
		foreach ($event->select('ATTENDEE') as $property) {
			$key = self::address((string) $property);
			if ($key === '' || isset($values[$key])) throw new BadRequest('Invitation attendee identities are missing or duplicated.');
			$values[$key] = $property;
		}
		ksort($values, SORT_STRING);
		return $values;
	}

	/**
	 * Normalize mail addresses while preserving opaque non-email attendee identities.
	 *
	 * @param string $value Calendar address
	 * @return string Stable participant key
	 */
	private static function address($value)
	{
		$email = self::email($value);
		return $email !== '' ? $email : trim((string) $value);
	}

	/**
	 * Normalize a trusted email identity or a mailto calendar address.
	 *
	 * @param string $value Email or mailto URI
	 * @return string Normalized email, empty when invalid
	 */
	private static function email($value)
	{
		$value = strtolower(preg_replace('/^mailto:/i', '', trim((string) $value)));
		return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
	}

	/**
	 * Read a nonnegative bounded organizer sequence without trusting client increments.
	 *
	 * @param VObject\Component $event Invitation event
	 * @return int Sequence, absent means zero
	 */
	private static function sequence($event)
	{
		$properties = $event->select('SEQUENCE');
		if (count($properties) > 1) throw new BadRequest('The organizer revision is ambiguous.');
		$value = isset($event->SEQUENCE) ? (string) $event->SEQUENCE : '0';
		if (!preg_match('/^[0-9]+$/D', $value) || (float) $value > 2147483647) throw new BadRequest('The organizer revision is invalid.');
		return (int) $value;
	}

	/**
	 * Read the effective participant response.
	 *
	 * @param object $attendee Attendee property
	 * @return string Effective PARTSTAT
	 */
	private static function partstat($attendee)
	{
		return isset($attendee['PARTSTAT']) ? strtoupper(trim((string) $attendee['PARTSTAT'])) : 'NEEDS-ACTION';
	}

	/**
	 * Read the effective request-for-response flag.
	 *
	 * @param object $attendee Attendee property
	 * @return bool Effective RSVP, absent means false
	 */
	private static function rsvp($attendee)
	{
		if (!isset($attendee['RSVP'])) return false;
		$value = strtoupper(trim((string) $attendee['RSVP']));
		if (!in_array($value, array('TRUE', 'FALSE'), true)) throw new BadRequest('The invitation RSVP value is invalid.');
		return $value === 'TRUE';
	}

	/**
	 * Read a global event status.
	 *
	 * @param VObject\Component $event Event
	 * @return string Status
	 */
	private static function status($event)
	{
		return CalendarWritePolicy::eventStatus($event);
	}

	/**
	 * Read the participant calendar's exported occupancy.
	 *
	 * @param VObject\Component $event Event
	 * @return int Native occupancy
	 */
	private static function transparency($event)
	{
		if (!isset($event->TRANSP)) return 1;
		$value = strtoupper(trim((string) $event->TRANSP));
		if (!in_array($value, array('OPAQUE', 'TRANSPARENT'), true)) throw new BadRequest('The invitation transparency is invalid.');
		return $value === 'TRANSPARENT' ? 0 : 1;
	}

	/**
	 * Read a resource scheduling method without accepting a message as stored state.
	 *
	 * @param VObject\Component $calendar Calendar resource
	 * @return string Scheduling method
	 */
	private static function method($calendar)
	{
		if (count($calendar->select('METHOD')) > 1) throw new BadRequest('The scheduling method is ambiguous.');
		return isset($calendar->METHOD) ? strtoupper(trim((string) $calendar->METHOD)) : '';
	}

	/**
	 * Assemble the backend contract without performing a native or external write.
	 *
	 * @param string $kind Operation kind
	 * @param string $data Canonical calendar
	 * @param bool $changed Whether a meaningful state change exists
	 * @param VObject\Component $event Canonical master
	 * @param string $organizer Organizer email
	 * @param int $sequence Server-owned revision
	 * @param bool $isOrganizer Whether the authenticated actor organizes the invitation
	 * @param string|null $partstat Personal response
	 * @param int|null $personal Personal occupancy
	 * @param bool $dateChanged Whether the organizer moved the event
	 * @return array<string,mixed> Canonical backend plan
	 */
	private static function result($kind, $data, $changed, $event, $organizer, $sequence, $isOrganizer, $partstat, $personal, $dateChanged)
	{
		return array('kind' => $kind, 'calendar_data' => $data, 'changed' => $changed,
			'partstat' => $partstat, 'personal_transparency' => $personal, 'organizer_email' => $organizer,
			'sequence' => $sequence, 'uid' => CalendarUid::read($event), 'actor_is_organizer' => $isOrganizer,
			'date_changed' => $dateChanged, 'status' => self::status($event), 'external_import' => false);
	}
}
