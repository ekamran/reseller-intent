/**
 * Reseller Intent, geo-aware support number swap.
 *
 * The shortcode renders the default number (page-cache safe). This swaps
 * in the visitor's regional number using only the browser timezone mapped
 * against the countries the site owner configured, no IP, no lookups.
 */
(function() {
	'use strict';

	var config = window.resellerIntentPhone;

	if (!config || !config.numbers || !config.numbers.length) {
		return;
	}

	function visitorCountry() {
		try {
			var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
			return (config.tzMap && config.tzMap[tz]) || '';
		} catch (error) {
			return '';
		}
	}

	function numberFor(country) {
		var i;
		var entry;

		if (country) {
			for (i = 0; i < config.numbers.length; i += 1) {
				entry = config.numbers[i];
				if (entry.countries && entry.countries.indexOf(country) !== -1) {
					return entry.number;
				}
			}
		}

		return null; // keep the server-rendered default
	}

	function apply() {
		var number = numberFor(visitorCountry());

		if (!number) {
			return;
		}

		var nodes = document.querySelectorAll('[data-rintent-phone]');
		var i;
		var target;

		for (i = 0; i < nodes.length; i += 1) {
			target = nodes[i].querySelector('.rintent-phone-number') || nodes[i];
			target.textContent = number;

			if (nodes[i].tagName === 'A') {
				nodes[i].setAttribute('href', 'tel:' + number.replace(/[^0-9+]/g, ''));
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', apply);
	} else {
		apply();
	}
})();
