/* Convert the notice display while the booking engine keeps whole minutes. */
(function () {
	'use strict';

	function enhanceNotice() {
		var input = document.getElementById('notice');
		var unit = document.getElementById('notice_unit');
		if (!input || !unit || unit.dataset.noticeReady) return;
		var previous = unit.value;
		function changeUnit() {
			var selected = unit.value;
			if (selected === previous || !['minutes', 'days'].includes(selected)) return;
			var value = input.valueAsNumber;
			if (Number.isFinite(value)) {
				var minutes = previous === 'days' ? value * 1440 : value;
				var converted = selected === 'days' ? minutes / 1440 : minutes;
				if (selected === 'minutes' && Math.abs(converted - Math.round(converted)) <= 0.0000001) {
					converted = Math.round(converted);
				}
				input.value = String(Number(converted.toFixed(10)));
			}
			input.max = selected === 'days' ? '30' : '43200';
			input.step = selected === 'days' ? 'any' : '1';
			previous = selected;
		}
		// The native combobox emits jQuery changes; plain selects use DOM events.
		if (window.jQuery) window.jQuery(unit).on('change.cdavNotice', changeUnit);
		else unit.addEventListener('change', changeUnit);
		unit.dataset.noticeReady = '1';
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhanceNotice, {once: true});
	else enhanceNotice();
}());
