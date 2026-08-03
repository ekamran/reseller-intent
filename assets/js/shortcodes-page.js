/**
 * Reseller Intent, Shortcodes page generators and live previews.
 * Config arrives via the rintentGen object (wp_localize_script).
 *
 * Price is family-first: the select carries the family slug and label,
 * the before/after fields arrive pre-filled from the family name and
 * stay auto-filled until the owner types their own wording.
 */
(function() {
	'use strict';

	var cfg = window.rintentGen || {};

	function esc(value) {
		return String(value).replace(/"/g, '');
	}

	function flashCopied(button) {
		var original = button.textContent;
		button.textContent = cfg.copied;
		setTimeout(function() { button.textContent = original; }, 1200);
	}

	function copy(sourceId, button) {
		var text = document.getElementById(sourceId).textContent;
		if (text && navigator.clipboard) {
			navigator.clipboard.writeText(text);
			flashCopied(button);
		}
	}

	/* ---------- TLD strip ---------- */

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

	/* ---------- Live product price, family-first ---------- */

	function familyEl() {
		return document.getElementById('rintent-gen-family');
	}

	function familyLabel() {
		var select = familyEl();
		var option = select && select.options[select.selectedIndex];
		return option ? (option.getAttribute('data-label') || '') : '';
	}

	/*
	 * Pre-fill follows the family until the owner types their own text.
	 * "Touched" means the value differs from what auto-fill last wrote,
	 * so switching families keeps updating untouched fields.
	 */
	function prefill(force) {
		var before = document.getElementById('rintent-gen-before');
		var after = document.getElementById('rintent-gen-after');
		if (!before) {
			return;
		}
		var autoBefore = (cfg.beforeTpl || '%s from').replace('%s', familyLabel());
		var autoAfter = cfg.afterTpl || 'per year';
		if (force || before.value === (before.getAttribute('data-auto') || '')) {
			before.value = autoBefore;
		}
		if (force || after.value === (after.getAttribute('data-auto') || '')) {
			after.value = autoAfter;
		}
		before.setAttribute('data-auto', autoBefore);
		after.setAttribute('data-auto', autoAfter);
	}

	function buildPrice() {
		var outEl = document.getElementById('rintent-gen-price-out');
		if (!outEl || !familyEl()) {
			return;
		}
		var mode = document.getElementById('rintent-gen-mode').value;
		var before = esc(document.getElementById('rintent-gen-before').value);
		var after = esc(document.getElementById('rintent-gen-after').value);

		// mode is always written out: the attribute default stays "min"
		// for old embeds, the generator default is range.
		var out = '[rintent_price family="' + esc(familyEl().value) + '" mode="' + mode + '"';
		if (before) {
			out += ' before="' + before + '"';
		}
		if (after) {
			out += ' after="' + after + '"';
		}
		outEl.textContent = out + ']';
	}

	/* ---------- Live previews (server-rendered) ---------- */

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
						if (json.data.html) {
							/* Server-rendered output of this plugin's own shortcodes,
							   from a nonce-checked, capability-checked endpoint. */
							target.innerHTML = json.data.html;
						} else {
							showEmpty(target, cfg.emptyText);
						}
						var dark = type === 'tld' && document.getElementById('rintent-gen-theme').value === 'dark';
						target.classList.toggle('rintent-preview--dark', dark);
					}
				}).catch(function() {});
		}, 350);
	}

	/* Placeholder text goes in as text, never as markup. */
	function showEmpty(target, text) {
		var em = document.createElement('em');
		em.textContent = text || '';
		target.textContent = '';
		target.appendChild(em);
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
		if (!familyEl()) {
			return;
		}
		fetchPreview('price', {
			family: familyEl().value,
			mode: document.getElementById('rintent-gen-mode').value,
			before: document.getElementById('rintent-gen-before').value,
			after: document.getElementById('rintent-gen-after').value
		}, 'rintent-preview-price');
	}

	/* ---------- Wiring ---------- */

	['rintent-gen-tlds', 'rintent-gen-theme', 'rintent-gen-more-label', 'rintent-gen-more-url'].forEach(function(id) {
		var node = document.getElementById(id);
		node.addEventListener('input', function() { buildTld(); previewTld(); });
		node.addEventListener('change', function() { buildTld(); previewTld(); });
	});
	document.getElementById('rintent-gen-tld-copy').addEventListener('click', function() { copy('rintent-gen-tld-out', this); });

	if (familyEl()) {
		familyEl().addEventListener('change', function() { prefill(); buildPrice(); previewPrice(); });
		['rintent-gen-mode', 'rintent-gen-before', 'rintent-gen-after'].forEach(function(id) {
			var node = document.getElementById(id);
			node.addEventListener('input', function() { buildPrice(); previewPrice(); });
			node.addEventListener('change', function() { buildPrice(); previewPrice(); });
		});
		document.getElementById('rintent-gen-price-copy').addEventListener('click', function() { copy('rintent-gen-price-out', this); });
	}

	var phoneCopy = document.getElementById('rintent-gen-phone-copy');
	if (phoneCopy) {
		phoneCopy.addEventListener('click', function() { copy('rintent-gen-phone-out', this); });
	}

	buildTld();
	previewTld();
	if (familyEl()) {
		prefill(true);
		buildPrice();
		previewPrice();
	}
})();
