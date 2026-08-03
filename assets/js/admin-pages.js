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

		/* Built with DOM calls, not an HTML string, so nothing interpolated
		   here can ever be parsed as markup. */
		function cell(node) {
			var td = document.createElement('td');
			td.appendChild(node);
			return td;
		}

		function textInput(name, label, placeholder) {
			var input = document.createElement('input');
			input.type = 'text';
			input.name = name;
			input.setAttribute('aria-label', label);
			if (placeholder) {
				input.placeholder = placeholder;
			}
			return input;
		}

		addButton.addEventListener('click', function() {
			var tbody = document.querySelector('#rintent-support-rows tbody');
			var row = document.createElement('tr');
			var remove = document.createElement('button');

			remove.type = 'button';
			remove.className = 'button-link-delete rintent-support-remove';
			remove.setAttribute('aria-label', config.ariaRemoveRow || 'Remove row');
			remove.textContent = '\u00d7';

			row.appendChild(cell(textInput('support_label[]', config.ariaSupportLabel || 'Support entry label', '')));
			row.appendChild(cell(textInput('support_number[]', config.ariaSupportNumber || 'Support phone number', '+1-480-000-0000')));
			row.appendChild(cell(textInput('support_countries[]', config.ariaSupportCountries || 'Country codes for this number', 'US,CA')));
			row.appendChild(cell(remove));

			tbody.appendChild(row);
			row.querySelector('input').focus();
		});
	}

	document.addEventListener('click', function(event) {
		if (event.target.classList && event.target.classList.contains('rintent-support-remove')) {
			event.target.closest('tr').remove();
		}
	});

	/* Dark accent picker enables only when "pick my own" is checked */

	function initDarkAccent() {
		var toggle = document.getElementById('rintent-accent-dark-custom');

		if (!toggle) {
			return;
		}

		toggle.addEventListener('change', function() {
			syncDarkAccentState();
		});
	}

	/* WP color pickers (hex-first) on the accent fields */

	function initColorPickers() {
		if (typeof jQuery === 'undefined' || !jQuery.fn.wpColorPicker) {
			return;
		}

		jQuery('.rintent-colorpicker').each(function() {
			jQuery(this).wpColorPicker();
		});

		syncDarkAccentState();
	}

	function syncDarkAccentState() {
		var toggle = document.getElementById('rintent-accent-dark-custom');
		var picker = document.getElementById('rintent-accent-dark');

		if (!toggle || !picker) {
			return;
		}

		picker.disabled = !toggle.checked;

		var container = picker.closest ? picker.closest('.wp-picker-container') : null;

		if (container) {
			container.style.opacity = toggle.checked ? '' : '0.45';
			container.style.pointerEvents = toggle.checked ? '' : 'none';
		}
	}

	/* Clear All label field follows its toggle */

	function initClearLabelRow() {
		var toggle = document.getElementById('rintent-clear-all');
		var row = document.getElementById('rintent-clear-label-row');

		if (!toggle || !row) {
			return;
		}

		toggle.addEventListener('change', function() {
			row.style.display = toggle.checked ? '' : 'none';
		});
	}

	/* Clear All options live under the style pack: no styling, no button */

	function initStylePackChildren() {
		var toggle = document.getElementById('rintent-style-widget');
		var children = document.getElementById('rintent-style-children');

		if (!toggle || !children) {
			return;
		}

		toggle.addEventListener('change', function() {
			children.style.display = toggle.checked ? '' : 'none';
		});
	}

	function init() {
		initChips();
		initSupportRows();
		initDarkAccent();
		initColorPickers();
		initClearLabelRow();
		initStylePackChildren();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
