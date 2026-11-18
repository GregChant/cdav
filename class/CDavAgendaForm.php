<?php

namespace Dolibarr\CDav;

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

/** Bridge Dolibarr 24's per-event assignee session to its legacy native renderer. */
class AgendaForm extends \Form
{
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- Native Form override.
	/**
	 * Reuse the native widget without persisting a shared cross-tab working selection.
	 * The Agenda controller has already checked access and filled its per-event
	 * session by this point. Only the renderer's temporary legacy view is changed.
	 *
	 * @param mixed ...$arguments Native Form::select_dolusers_forevent arguments
	 * @return string Native assignee widget
	 */
	public function select_dolusers_forevent(...$arguments)
	{
		$key = $GLOBALS['sessionkeyassignedtouser'] ?? null;
		if (!is_string($key) || !str_starts_with($key, 'assignedtouser_') || !array_key_exists($key, $_SESSION)) {
			return parent::select_dolusers_forevent(...$arguments);
		}
		$hadLegacy = array_key_exists('assignedtouser', $_SESSION);
		$legacy = $_SESSION['assignedtouser'] ?? null;
		$_SESSION['assignedtouser'] = $_SESSION[$key];
		try {
			return parent::select_dolusers_forevent(...$arguments);
		} finally {
			if ($hadLegacy) $_SESSION['assignedtouser'] = $legacy;
			else unset($_SESSION['assignedtouser']);
		}
	}
	// phpcs:enable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
}
