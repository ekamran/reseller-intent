/**
 * Reseller Intent, Settings and Shortcodes pages.
 *
 * Click-to-copy on every code chip. The shortcode builders keep their own
 * Copy buttons, this makes each class/variable chip copyable too.
 */
(function() {
	'use strict';

	var config = window.rintentPages || {};

	function initChips() {
		var chips = document.querySelectorAll('.rintent-pages code');

		Array.prototype.forEach.call(chips, function(chip) {
			chip.setAttribute('title', config.copyHint || 'Click to copy');
			chip.setAttribute('data-copied', config.copied || 'Copied');
		});
	}

	document.addEventListener('click', function(event) {
		var chip = event.target.closest ? event.target.closest('.rintent-pages code') : null;

		if (!chip || !navigator.clipboard) {
			return;
		}

		var text = (chip.textContent || '').trim();

		if (!text) {
			return;
		}

		navigator.clipboard.writeText(text);
		chip.classList.add('is-copied');
		setTimeout(function() {
			chip.classList.remove('is-copied');
		}, 1100);
	});

	/* Support numbers repeater (Shortcodes page, phone card) */

	function initSupportRows() {
		var addButton = document.getElementById('rintent-support-add');

		if (!addButton) {
			return;
		}

		addButton.addEventListener('click', function() {
			var tbody = document.querySelector('#rintent-support-rows tbody');
			var row = document.createElement('tr');

			row.innerHTML = '<td><input type="text" name="support_label[]" /></td>'
				+ '<td><input type="text" name="support_number[]" placeholder="+1-480-000-0000" /></td>'
				+ '<td><input type="text" name="support_countries[]" placeholder="US,CA" /></td>'
				+ '<td><button type="button" class="button-link-delete rintent-support-remove" aria-label="Remove row">&times;</button></td>';
			tbody.appendChild(row);
			row.querySelector('input').focus();
		});
	}

	document.addEventListener('click', function(event) {
		if (event.target.classList && event.target.classList.contains('rintent-support-remove')) {
			event.target.closest('tr').remove();
		}
	});

	function init() {
		initChips();
		initSupportRows();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
