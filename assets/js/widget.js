/**
 * Reseller Intent — optional widget enhancements.
 *
 * Feature-flagged from Settings via the localized `resellerIntentWidget`
 * object: skeleton loading rows and a floating query-aware "Clear All".
 * Pure presentation — tracking lives in tracker.js and works without this.
 */
(function($) {
	'use strict';

	var config = window.resellerIntentWidget || {};

	function updateInputValue(input, value) {
		var setter;

		if (!input) {
			return;
		}

		setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
		if (setter && typeof setter.set === 'function') {
			setter.set.call(input, value);
		} else {
			input.value = value;
		}

		input.dispatchEvent(new Event('input', { bubbles: true }));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}

	function clickElement(element) {
		try {
			element.dispatchEvent(
				new MouseEvent('click', {
					bubbles: true,
					cancelable: true,
					view: window
				})
			);
		} catch (error) {
			$(element).trigger('click');
		}
	}

	/* ---------- Skeleton rows ---------- */

	function ensureSkeletonRows() {
		if (!config.skeletons) {
			return;
		}

		$('.rstore-domain-search .rstore-loading').each(function() {
			var $loader = $(this);
			var i;

			if ($loader.attr('data-rintent-skeleton') === '1') {
				return;
			}

			$loader.empty();
			for (i = 0; i < 5; i += 1) {
				$loader.append(
					'<div class="rintent-skeleton-row">' +
						'<span class="sk-name"></span>' +
						'<span class="sk-price"></span>' +
						'<span class="sk-btn"></span>' +
					'</div>'
				);
			}

			$loader.attr('data-rintent-skeleton', '1');
		});
	}

	/* ---------- Floating Clear All ---------- */

	function ensureClearAllButtons() {
		if (!config.clearAll) {
			return;
		}

		$('.rstore-domain-search').each(function() {
			var $scope = $(this);
			var $formContainer = $scope.find('.form-container').first();

			if (!$formContainer.length) {
				return;
			}

			$scope.addClass('rintent-has-clear');

			if ($formContainer.children('.rintent-clear-btn').length) {
				return;
			}

			$formContainer.append(
				'<button type="button" class="rintent-clear-btn" aria-label="' + (config.clearLabel || 'Clear all') + '">' +
					(config.clearLabel || 'Clear All') +
				'</button>'
			);
		});
	}

	function updateClearAllVisibility() {
		if (!config.clearAll) {
			return;
		}

		$('.rstore-domain-search').each(function() {
			var $scope = $(this);
			var value = ($scope.find('input[name="domainToCheck"], .search-field').first().val() || '').trim();
			var cleared = $scope.hasClass('rintent-results-cleared');
			var hasResults = !cleared && $scope.find('.rstore-message, .rstore-domain-buy-button').length > 0;

			$scope.toggleClass('rintent-has-query', value.length > 0 || hasResults);
		});
	}

	function alignClearButtonsToSearchForm() {
		if (!config.clearAll) {
			return;
		}

		$('.rstore-domain-search').each(function() {
			var $scope = $(this);
			var $formContainer = $scope.find('.form-container').first();
			var $searchForm = $formContainer.find('.search-form').first();
			var $clearBtn = $formContainer.children('.rintent-clear-btn').first();
			var containerEl;
			var formEl;
			var offsetLeft;

			if (!$formContainer.length || !$searchForm.length || !$clearBtn.length) {
				return;
			}

			containerEl = $formContainer.get(0);
			formEl = $searchForm.get(0);
			if (!containerEl || !formEl) {
				return;
			}

			offsetLeft = Math.max(0, Math.round(formEl.getBoundingClientRect().left - containerEl.getBoundingClientRect().left));
			$formContainer.css('--rintent-clear-left-offset', offsetLeft + 'px');
		});
	}

	function deselectAllDomains($scope) {
		$scope.find('.rstore-domain-buy-button.selected').each(function() {
			clickElement(this);
		});
	}

	function clearDomainSearchScope($scope) {
		var $searchField;
		var fieldEl;

		if (!$scope || !$scope.length) {
			return;
		}

		deselectAllDomains($scope);

		$searchField = $scope.find('.search-form .search-field').first();
		fieldEl = $searchField.get(0);
		if (fieldEl) {
			updateInputValue(fieldEl, '');
		} else if ($searchField.length) {
			$searchField.val('');
		}

		$scope.find('.rstore-loading').removeAttr('data-rintent-skeleton').empty();

		$scope.addClass('rintent-results-cleared');
		ensureSkeletonRows();
	}

	$(document).on('input', '.rstore-domain-search input[name="domainToCheck"], .rstore-domain-search .search-field', function() {
		updateClearAllVisibility();
	});

	$(document).on('click', '.rintent-clear-btn', function(event) {
		var $scope = $(this).closest('.rstore-domain-search');

		event.preventDefault();
		clearDomainSearchScope($scope);
		updateClearAllVisibility();
		setTimeout(updateClearAllVisibility, 150);
	});

	$(document).on('submit', '.rstore-domain-search .search-form', function() {
		$(this).closest('.rstore-domain-search').removeClass('rintent-results-cleared');
	});

	$(document).ready(function() {
		ensureSkeletonRows();
		ensureClearAllButtons();
		alignClearButtonsToSearchForm();
		updateClearAllVisibility();

		$(window).on('resize', function() {
			alignClearButtonsToSearchForm();
		});

		$('.rstore-domain-search').each(function() {
			var observer = new MutationObserver(function() {
				ensureSkeletonRows();
				ensureClearAllButtons();
				alignClearButtonsToSearchForm();
				updateClearAllVisibility();
			});

			observer.observe(this, { childList: true, subtree: true });
		});
	});
})(jQuery);
