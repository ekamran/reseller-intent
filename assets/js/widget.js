/**
 * Reseller Intent, optional widget enhancements.
 *
 * Feature-flagged from Settings via the localized `resellerIntentWidget`
 * object: skeleton loading rows and a floating query-aware "Clear All".
 * Pure presentation, tracking lives in tracker.js and works without this.
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
			var $scope = $loader.closest('.rstore-domain-search');
			var i;

			if ($loader.attr('data-rintent-skeleton') === '1') {
				return;
			}

			/*
			 * The widget re-renders while results are on screen, and a
			 * re-render can hand us a brand new loader node after the search
			 * has already finished. Filling that one leaves skeleton rows
			 * shimmering above the real results, so only dress a loader that
			 * is genuinely waiting on something.
			 */
			if ($loader.hasClass('rstore-loading-hidden')) {
				return;
			}

			if ($scope.find('.domain-result').length || $scope.find('p.available, p.not-available').length) {
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
			var button;
			var label;

			if (!$formContainer.length) {
				return;
			}

			$scope.addClass('rintent-has-clear');

			if ($formContainer.children('.rintent-clear-btn').length) {
				return;
			}

			/*
			 * Built with DOM calls, not an HTML string. The label is the site
			 * owner's own wording and sanitize_text_field() leaves quotes
			 * alone, so concatenating it into markup let a label like
			 * `" onfocus="..." autofocus="x` break out of the attribute.
			 */
			label = config.clearLabel || 'Clear All';
			button = document.createElement('button');
			button.type = 'button';
			button.className = 'rintent-clear-btn';
			button.setAttribute('aria-label', label);
			button.textContent = label;

			$formContainer.append(button);
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

	/*
	 * Dark surfaces get .rintent-dark on the widget root automatically, so
	 * the dark styling needs zero setup. Order of truth:
	 * .rintent-light ancestor forces light, .rintent-dark or legacy
	 * .rstore-dark ancestors force dark, otherwise the surface behind the
	 * widget decides (solid color, then gradient stop, then surrounding
	 * text color as the last hint).
	 */
	function colorLuminance(value) {
		var parts = value ? value.match(/[\d.]+/g) : null;

		if (!parts || parts.length < 3) {
			return null;
		}

		if (parts.length >= 4 && parseFloat(parts[3]) < 0.5) {
			return null;
		}

		return (0.2126 * parts[0]) + (0.7152 * parts[1]) + (0.0722 * parts[2]);
	}

	function contextIsDark(root) {
		var node = root.parentElement;
		var lum;

		while (node && node.nodeType === 1 && node !== document.documentElement) {
			var style = window.getComputedStyle(node);

			lum = colorLuminance(style.backgroundColor);
			if (null !== lum) {
				return lum < 140;
			}

			var image = style.backgroundImage || '';
			if (-1 !== image.indexOf('gradient')) {
				var stop = image.match(/rgba?\([^)]+\)/);
				lum = stop ? colorLuminance(stop[0]) : null;
				if (null !== lum) {
					return lum < 140;
				}
			}

			node = node.parentElement;
		}

		lum = colorLuminance(window.getComputedStyle(root.parentElement || root).color);

		return null !== lum && lum > 150;
	}

	function autoDarkContext() {
		$('.rstore-domain-search').each(function() {
			if (this.closest('.rintent-light')) {
				this.classList.remove('rintent-dark');
				return;
			}

			if (this.closest('.rintent-dark')) {
				return;
			}

			if (this.closest('.rstore-dark') || contextIsDark(this)) {
				this.classList.add('rintent-dark');
			}
		});
	}

	function enhanceAll() {
		autoDarkContext();
		ensureSkeletonRows();
		ensureClearAllButtons();
		alignClearButtonsToSearchForm();
		updateClearAllVisibility();
	}

	$(document).ready(function() {
		enhanceAll();

		$(window).on('resize', function() {
			alignClearButtonsToSearchForm();
		});

		/*
		 * One document-level observer instead of one per widget found at
		 * load: widgets injected later (Elementor popups, AJAX-loaded
		 * sections, infinite scroll) get the same treatment. Batched via
		 * requestAnimationFrame so bursts of mutations run the (idempotent)
		 * enhancers once per frame, and only when a mutation actually
		 * involves a domain-search widget.
		 */
		var scheduled = false;

		function touchesWidget(mutation) {
			var node = mutation.target;

			if (node.nodeType === 1 && (node.closest('.rstore-domain-search') || node.querySelector && node.querySelector('.rstore-domain-search'))) {
				return true;
			}

			return false;
		}

		/*
		 * requestAnimationFrame alone is a trap here: browsers suspend it
		 * in background tabs, so a widget loading while the tab is hidden
		 * would never get its skeletons. Race it against a short timeout,
		 * whichever fires first runs the (idempotent) enhancers once.
		 */
		function runEnhance() {
			if (!scheduled) {
				return;
			}

			scheduled = false;
			enhanceAll();
		}

		var observer = new MutationObserver(function(mutations) {
			if (scheduled) {
				return;
			}

			var relevant = false;
			for (var i = 0; i < mutations.length; i += 1) {
				if (touchesWidget(mutations[i])) {
					relevant = true;
					break;
				}
			}

			if (!relevant) {
				return;
			}

			scheduled = true;
			window.requestAnimationFrame(runEnhance);
			window.setTimeout(runEnhance, 150);
		});

		observer.observe(document.body, { childList: true, subtree: true });
	});
})(jQuery);
