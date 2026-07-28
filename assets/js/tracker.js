/**
 * Reseller Intent, frontend tracker.
 *
 * Listens to the GoDaddy Reseller Store domain-search widget (React 18) and
 * records four anonymous events: domain_search, search_result (availability
 * attached to the matching search), domain_select, continue_to_cart.
 *
 * Privacy: no cookies, no fingerprinting, no IP storage, no user accounts.
 */
(function($) {
	'use strict';

	function getDeviceType() {
		try {
			var ua = navigator.userAgent || '';

			// iPadOS reports a Mac user agent; touch points give it away.
			if (/tablet|ipad/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1)) {
				return 'tablet';
			}

			if (/mobi/i.test(ua) || window.matchMedia('(max-width: 782px)').matches) {
				return 'mobile';
			}

			return 'desktop';
		} catch (error) {
			return '';
		}
	}

	function getTimezone() {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (error) {
			return '';
		}
	}

	function trackEvent(eventType, payload) {
		var data = $.extend(
			{
				action: 'rintent_track',
				event_type: eventType,
				device: getDeviceType(),
				tz: getTimezone(),
				page_url: window.location.href
			},
			payload || {}
		);

		if (!window.resellerIntent || !window.resellerIntent.ajaxUrl) {
			return;
		}

		/*
		 * Cart clicks navigate away immediately; a plain XHR can be killed
		 * mid-flight and the most valuable event is lost. sendBeacon is
		 * guaranteed to survive the navigation.
		 */
		if (eventType === 'continue_to_cart' && navigator.sendBeacon) {
			var formData = new FormData();

			Object.keys(data).forEach(function(key) {
				formData.append(key, data[key]);
			});

			if (navigator.sendBeacon(window.resellerIntent.ajaxUrl, formData)) {
				return;
			}
		}

		$.ajax({
			url: window.resellerIntent.ajaxUrl,
			type: 'POST',
			data: data
		});
	}

	function parseItemsFromForm($form) {
		var raw = '';
		var parsed = null;
		var countField = parseInt($form.find('input[name="items_count"]').first().val() || '0', 10);

		function countList(list) {
			if (!Array.isArray(list)) {
				return 0;
			}

			return list.filter(function(item) {
				if (item && typeof item === 'object') {
					return Object.keys(item).length > 0;
				}
				return String(item || '').trim() !== '';
			}).length;
		}

		function countFromPayload(payload) {
			var nestedKeys;
			var i;

			if (Array.isArray(payload)) {
				return countList(payload);
			}

			if (payload && typeof payload === 'object') {
				nestedKeys = ['items', 'domains', 'selected_domains', 'selectedDomains', 'domain_list'];
				for (i = 0; i < nestedKeys.length; i += 1) {
					if (Array.isArray(payload[nestedKeys[i]])) {
						return countList(payload[nestedKeys[i]]);
					}
				}

				if (!isNaN(parseInt(payload.count, 10))) {
					return Math.max(0, parseInt(payload.count, 10));
				}

				return Object.keys(payload).length;
			}

			return 0;
		}

		raw = $form.find('input[name="items"]').first().val() || '';

		if (raw) {
			try {
				parsed = JSON.parse(raw);
			} catch (e) {
				parsed = String(raw)
					.split(/[,;\n]/)
					.map(function(item) { return item.trim(); })
					.filter(Boolean);
			}
		}

		return {
			itemsRaw: raw,
			itemsCount: (function() {
				var parsedCount = countFromPayload(parsed);
				if (parsedCount > 0) {
					return parsedCount;
				}
				if (!isNaN(countField) && countField > 0) {
					return countField;
				}
				return 0;
			})()
		};
	}

	/*
	 * The search box is a controlled input, so it holds whatever is typed
	 * right now, not the query the results on screen came from. Someone who
	 * starts typing a second name before the first result lands would
	 * otherwise have the availability stamped onto the wrong search. Prefer
	 * the query that was actually submitted; the field is only the fallback.
	 */
	function getSearchQuery($scope) {
		var stored = $scope.attr('data-rintent-query');

		if (undefined !== stored) {
			return stored;
		}

		return String($scope.find('.search-form .search-field').first().val() || '').trim();
	}

	/*
	 * Once results render, report whether the searched domain was available.
	 * The server attaches the flag to the matching domain_search row. The
	 * signature dedupes repeat DOM mutations for the same render; it is
	 * cleared on every new search submit so a fresh row always gets its flag.
	 */
	function reportSearchOutcome() {
		$('.rstore-domain-search').each(function() {
			var $scope = $(this);
			var $status = $scope.find('.result-content > p.available, .result-content > p.not-available').first();
			var query;
			var available;
			var signature;

			if (!$status.length) {
				return;
			}

			query = getSearchQuery($scope);
			if (!query) {
				return;
			}

			available = $status.hasClass('available') ? 1 : 0;
			signature = query + ':' + available;
			if ($scope.attr('data-rintent-outcome-sent') === signature) {
				return;
			}

			$scope.attr('data-rintent-outcome-sent', signature);
			trackEvent('search_result', {
				domain_query: query,
				is_available: available
			});
		});
	}

	function bindSelectTracking() {
		if (window.__rintentSelectTrackingBound) {
			return;
		}

		window.__rintentSelectTrackingBound = true;

		document.addEventListener('click', function(event) {
			var button = event.target && event.target.closest
				? event.target.closest('.rstore-domain-search .rstore-domain-buy-button.select')
				: null;
			var $scope;
			var $result;
			var domainName;

			if (!button) {
				return;
			}

			$scope = $(button).closest('.rstore-domain-search');
			$result = $(button).closest('.domain-result');
			/*
			 * Only this element's own text. Restricted TLDs render a
			 * "Restrictions apply" note inside .domain-name, and .text()
			 * would glue it on: "example.appRestrictions apply".
			 */
			domainName = $result
				.find('.domain-name')
				.first()
				.contents()
				.filter(function() {
					return this.nodeType === 3;
				})
				.text() || '';
			domainName = String(domainName).trim();

			if (!domainName) {
				return;
			}

			trackEvent('domain_select', {
				domain_query: domainName,
				related_query: getSearchQuery($scope)
			});
		}, true);
	}

	$(document).on('submit', '.continue-form', function() {
		var itemsMeta = parseItemsFromForm($(this));

		trackEvent('continue_to_cart', {
			items_count: itemsMeta.itemsCount,
			items_json: itemsMeta.itemsRaw || ''
		});
	});

	$(document).on('submit', '.rstore-domain-search .search-form', function() {
		var $form = $(this);
		var $scope = $form.closest('.rstore-domain-search');
		var domainQuery;

		// New search = new row; let the outcome reporter fire again.
		$scope.removeAttr('data-rintent-outcome-sent');

		domainQuery = String($form.find('input[name="domainToCheck"], .search-field').first().val() || '').trim();

		/*
		 * Remember what was actually submitted. The box can change before the
		 * results land, and an empty submit is ignored by the widget, so it
		 * must not overwrite the query the results still belong to.
		 */
		if (domainQuery) {
			$scope.attr('data-rintent-query', domainQuery);
		}

		trackEvent('domain_search', {
			domain_query: domainQuery
		});
	});

	$(document).ready(function() {
		/*
		 * The widget also searches on mount, with no submit, when the URL
		 * carries ?domainToCheck= (a documented Reseller Store feature). The
		 * submit handler never sees those, so campaign and email deep links
		 * were invisible. Fire it here, before the observers attach, so the
		 * search_result that follows has a row to attach to. URLSearchParams
		 * is what the widget itself parses with, and it never throws on a
		 * malformed escape.
		 */
		var deepLink = '';

		try {
			deepLink = String(new URLSearchParams(window.location.search).get('domainToCheck') || '').trim();
		} catch (error) {
			deepLink = '';
		}

		if (deepLink && $('.rstore-domain-search').length) {
			$('.rstore-domain-search').attr('data-rintent-query', deepLink);
			trackEvent('domain_search', {
				domain_query: deepLink
			});
		}

		bindSelectTracking();
		reportSearchOutcome();

		// React re-renders replace nodes; watch for results appearing.
		$('.rstore-domain-search').each(function() {
			var observer = new MutationObserver(function() {
				reportSearchOutcome();
			});

			observer.observe(this, { childList: true, subtree: true });
		});
	});
})(jQuery);
