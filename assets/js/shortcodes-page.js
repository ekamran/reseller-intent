/**
 * Reseller Intent, Shortcodes page generators and live previews.
 * Config arrives via the rintentGen object (wp_localize_script).
 */
(function() {
	'use strict';

	var cfg = window.rintentGen || {};

	cfg.placeholders = cfg.placeholders || { min: ['', ''], max: ['', ''], range: ['', ''] };
	function esc(value) {
		return String(value).replace(/"/g, '');
	}

	function flashCopied(button) {
		var original = button.textContent;
		button.textContent = cfg.copied;
		setTimeout(function() { button.textContent = original; }, 1200);
	}

	function buildTld() {
		var tlds = esc(document.getElementById('rintent-gen-tlds').value || '.com,.in,.org,.net,.io');
		var theme = document.getElementById('rintent-gen-theme').value;
		var label = esc(document.getElementById('rintent-gen-more-label').value);
		var url = esc(document.getElementById('rintent-gen-more-url').value);
		var out = '[rintent_tld_strip tlds="' + tlds + '"';
		if (theme !== 'light') {
			out += ' theme="' + theme + '"';
		}
		if (url) {
			out += ' more_url="' + url + '"';
			if (label) {
				out += ' more_label="' + label + '"';
			}
		}
		document.getElementById('rintent-gen-tld-out').textContent = out + ']';
	}

	var PLACEHOLDERS = cfg.placeholders;

	function priceIds(mode) {
		if (mode === 'range') {
			var fam = document.getElementById('rintent-gen-family');
			return fam && fam.value ? fam.value.split(',') : [];
		}
		return Array.prototype.slice.call(document.querySelectorAll('.rintent-gen-product:checked')).map(function(cb) { return cb.value; });
	}

	function buildPrice() {
		var outEl = document.getElementById('rintent-gen-price-out');
		if (!outEl) {
			return;
		}
		var mode = document.getElementById('rintent-gen-mode').value;
		var ids = priceIds(mode);
		var before = esc(document.getElementById('rintent-gen-before').value);
		var after = esc(document.getElementById('rintent-gen-after').value);
		var separator = esc(document.getElementById('rintent-gen-separator').value);
		var fallback = esc(document.getElementById('rintent-gen-fallback').value);

		document.getElementById('rintent-gen-sep-row').style.display = mode === 'range' ? '' : 'none';
		var famRow = document.getElementById('rintent-gen-family-row');
		var prodRow = document.getElementById('rintent-gen-products-row');
		if (famRow) { famRow.style.display = mode === 'range' ? '' : 'none'; }
		if (prodRow) { prodRow.style.display = mode === 'range' ? 'none' : ''; }
		document.getElementById('rintent-gen-before').placeholder = PLACEHOLDERS[mode][0];
		document.getElementById('rintent-gen-after').placeholder = PLACEHOLDERS[mode][1];

		var out = '[rintent_price ids="' + ids.join(',') + '"';
		if (mode !== 'min') {
			out += ' mode="' + mode + '"';
		}
		if (before) {
			out += ' before="' + before + '"';
		}
		if (after) {
			out += ' after="' + after + '"';
		}
		if (mode === 'range' && separator) {
			out += ' separator="' + separator + '"';
		}
		if (fallback) {
			out += ' fallback="' + fallback + '"';
		}
		outEl.textContent = ids.length ? out + ']' : '';
	}

	function copy(sourceId, button) {
		var text = document.getElementById(sourceId).textContent;
		if (text && navigator.clipboard) {
			navigator.clipboard.writeText(text);
			flashCopied(button);
		}
	}

	['rintent-gen-tlds', 'rintent-gen-theme', 'rintent-gen-more-label', 'rintent-gen-more-url'].forEach(function(id) {
		document.getElementById(id).addEventListener('input', buildTld);
		document.getElementById(id).addEventListener('change', buildTld);
	});
	document.getElementById('rintent-gen-tld-copy').addEventListener('click', function() { copy('rintent-gen-tld-out', this); });

	var filter = document.getElementById('rintent-gen-filter');
	if (filter) {
		filter.addEventListener('input', function() {
			var q = filter.value.toLowerCase();
			document.querySelectorAll('.rintent-gen-product').forEach(function(cb) {
				cb.closest('label').style.display = cb.getAttribute('data-title').indexOf(q) === -1 ? 'none' : 'block';
			});
		});
		document.querySelectorAll('.rintent-gen-product').forEach(function(cb) {
			cb.addEventListener('change', buildPrice);
		});
		['rintent-gen-mode', 'rintent-gen-before', 'rintent-gen-after', 'rintent-gen-separator', 'rintent-gen-fallback'].forEach(function(id) {
			document.getElementById(id).addEventListener('input', buildPrice);
			document.getElementById(id).addEventListener('change', buildPrice);
		});
		document.getElementById('rintent-gen-price-copy').addEventListener('click', function() { copy('rintent-gen-price-out', this); });
	}

	var previewNonce = cfg.previewNonce;
	var previewTimers = {};
	var previewSeq = {};

	function fetchPreview(type, params, targetId) {
		window.clearTimeout(previewTimers[type]);
		previewTimers[type] = window.setTimeout(function() {
			var seq = (previewSeq[type] || 0) + 1;
			previewSeq[type] = seq;
			var target = document.getElementById(targetId);
			if (!target) {
				return;
			}
			var body = new window.FormData();
			body.append('action', 'rintent_preview_shortcode');
			body.append('nonce', previewNonce);
			body.append('type', type);
			Object.keys(params).forEach(function(k) { body.append(k, params[k]); });
			window.fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function(r) { return r.json(); })
				.then(function(json) {
					if (seq !== previewSeq[type]) {
						return; // a newer request superseded this one
					}
					if (json && json.success && json.data) {
						target.innerHTML = json.data.html || '<em>' + cfg.emptyText + '</em>';
						var dark = type === 'tld' && document.getElementById('rintent-gen-theme').value === 'dark';
						target.classList.toggle('rintent-preview--dark', dark);
					}
				}).catch(function() {});
		}, 350);
	}

	function previewTld() {
		fetchPreview('tld', {
			tlds: document.getElementById('rintent-gen-tlds').value,
			theme: document.getElementById('rintent-gen-theme').value,
			more_label: document.getElementById('rintent-gen-more-label').value,
			more_url: document.getElementById('rintent-gen-more-url').value
		}, 'rintent-preview-tld');
	}

	function previewPrice() {
		var mode = document.getElementById('rintent-gen-mode').value;
		var ids = priceIds(mode).join(',');
		var target = document.getElementById('rintent-preview-price');
		if (!ids) {
			if (target) { target.innerHTML = '<em>' + (target.getAttribute('data-empty') || '') + '</em>'; }
			return;
		}
		fetchPreview('price', {
			ids: ids,
			mode: document.getElementById('rintent-gen-mode').value,
			before: document.getElementById('rintent-gen-before').value,
			after: document.getElementById('rintent-gen-after').value,
			separator: document.getElementById('rintent-gen-separator').value,
			fallback: document.getElementById('rintent-gen-fallback').value
		}, 'rintent-preview-price');
	}

	['rintent-gen-tlds', 'rintent-gen-theme', 'rintent-gen-more-label', 'rintent-gen-more-url'].forEach(function(id) {
		var node = document.getElementById(id);
		node.addEventListener('input', previewTld);
		node.addEventListener('change', previewTld);
	});
	['rintent-gen-mode', 'rintent-gen-before', 'rintent-gen-after', 'rintent-gen-separator', 'rintent-gen-fallback'].forEach(function(id) {
		var node = document.getElementById(id);
		if (node) {
			node.addEventListener('input', previewPrice);
			node.addEventListener('change', previewPrice);
		}
	});
	document.querySelectorAll('.rintent-gen-product').forEach(function(cb) {
		cb.addEventListener('change', previewPrice);
	});
	var famSel = document.getElementById('rintent-gen-family');
	if (famSel) {
		famSel.addEventListener('change', function() { buildPrice(); previewPrice(); });
	}

	previewTld();
	previewPrice();
	fetchPreview('phone', {}, 'rintent-preview-phone');
})();
