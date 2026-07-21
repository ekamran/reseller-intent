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

	function entryFor(country) {
		var i;
		var entry;

		if (country) {
			for (i = 0; i < config.numbers.length; i += 1) {
				entry = config.numbers[i];
				if (entry.countries && entry.countries.indexOf(country) !== -1) {
					return entry;
				}
			}
		}

		return null; // keep the server-rendered default
	}

	function apply() {
		var entry = entryFor(visitorCountry());
		var nodes = document.querySelectorAll('[data-rintent-phone]');
		var i;
		var numberNode;
		var flagNode;

		for (i = 0; i < nodes.length; i += 1) {
			if (entry) {
				numberNode = nodes[i].querySelector('.rintent-phone-number');
				flagNode = nodes[i].querySelector('.rintent-phone-flag');

				if (numberNode) {
					numberNode.textContent = entry.number;
				}
				if (flagNode && entry.flag) {
					flagNode.textContent = entry.flag;
				}
				if (nodes[i].tagName === 'A') {
					nodes[i].setAttribute('href', 'tel:' + entry.number.replace(/[^0-9+]/g, ''));
				}
			}

			// Reveal immediately, resolved or defaulted; the CSS delay is
			// only the no-JS safety net.
			nodes[i].classList.add('is-resolved');
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', apply);
	} else {
		apply();
	}
})();
