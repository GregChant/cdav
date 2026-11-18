/* Keep checkbox selection read-only; booking still requires the confirmation POST. */
(function () {
	'use strict';

	function enhanceSelection() {
		var form = document.getElementById('booking-add-selection');
		if (!form || form.method.toLowerCase() !== 'get' || form.dataset.selectionReady) return;
		var action = form.elements.namedItem('selection_action');
		var button = form.querySelector('button[type="submit"]');
		if (!action || !button) return;
		var pending;
		var submitting = false;
		window.addEventListener('pageshow', function (event) {
			if (!event.persisted) return;
			clearTimeout(pending);
			submitting = false;
			form.removeAttribute('aria-busy');
		});
		form.addEventListener('change', function (event) {
			if (!event.target.matches('input[type="checkbox"][name="add[]"]') || submitting) return;
			clearTimeout(pending);
			pending = setTimeout(function () {
				action.value = 'set';
				submitting = true;
				form.setAttribute('aria-busy', 'true');
				try {
					if (typeof form.requestSubmit === 'function') form.requestSubmit();
					else form.submit();
				} catch (error) {
					submitting = false;
					form.removeAttribute('aria-busy');
					button.hidden = false;
				}
			}, 150);
		});
		form.dataset.selectionReady = '1';
		button.hidden = true;
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhanceSelection, {once: true});
	else enhanceSelection();
}());
