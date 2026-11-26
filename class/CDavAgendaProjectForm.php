<?php

namespace Dolibarr\CDav;

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formprojet.class.php';

/** Display the task reference and label in the Agenda card's project-scoped task picker. */
class AgendaProjectForm extends \FormProjets
{
	/**
	 * Render the native task selector for the selected project.
	 *
	 * @param int $socid Third-party filter
	 * @param int|string $selected Selected task ID
	 * @param string $htmlname Form field name
	 * @param int $maxlength Maximum task label length
	 * @param int $option_only Return only option elements
	 * @param string $show_empty Include an empty option
	 * @param int $discard_closed Closed project handling
	 * @param int $forcefocus Initial focus
	 * @param int $disabled Disable the selector
	 * @param string $morecss Extra CSS classes
	 * @param string $projectsListId Project ID filter
	 * @param string $showmore Additional option details
	 * @param \User|null $usertofilter User for native visibility checks
	 * @param int $nooutput Return the selector instead of printing it
	 * @return int|string Native count or rendered selector
	 */
	public function selectTasks($socid = -1, $selected = 0, $htmlname = 'taskid', $maxlength = 24, $option_only = 0, $show_empty = '1', $discard_closed = 0, $forcefocus = 0, $disabled = 0, $morecss = 'maxwidth500', $projectsListId = '', $showmore = 'all', $usertofilter = null, $nooutput = 0)
	{
		if (in_array($htmlname, array('taskid', 'fk_element'), true) && $showmore === 'all'
			&& ctype_digit((string) $projectsListId) && (int) $projectsListId > 0) {
			// The project already has its own field. Use the same task labels as the native AJAX refresh.
			$showmore = '';
		}
		return parent::selectTasks($socid, $selected, $htmlname, $maxlength, $option_only, $show_empty, $discard_closed,
			$forcefocus, $disabled, $morecss, $projectsListId, $showmore, $usertofilter, $nooutput);
	}
}
