/* global resellerIntentAdmin, wp */
(function() {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var useRef = wp.element.useRef;
	var Fragment = wp.element.Fragment;

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var ACCENT = (window.resellerIntentAdmin && resellerIntentAdmin.accentColor) || '#3858e9';
	var ACCENT_TEXT = (window.resellerIntentAdmin && resellerIntentAdmin.accentText) || '#ffffff';

	/**
	 * The accent at a given alpha, for chart fills. Derived rather than
	 * hard coded so the chart always matches whatever accent is saved.
	 */
	function accentAlpha(alpha) {
		var hex = String(ACCENT).replace('#', '');

		if (3 === hex.length) {
			hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		}

		if (6 !== hex.length || /[^0-9a-f]/i.test(hex)) {
			return 'rgba(56,88,233,' + alpha + ')';
		}

		return 'rgba(' + parseInt(hex.slice(0, 2), 16) + ','
			+ parseInt(hex.slice(2, 4), 16) + ','
			+ parseInt(hex.slice(4, 6), 16) + ',' + alpha + ')';
	}
	var INK = '#1d2327';
	var TZ_LABEL = (window.resellerIntentAdmin && resellerIntentAdmin.tzLabel) || '';

	var RANGES = [
		{ key: '7', label: __( '7d', 'reseller-intent' ) },
		{ key: '30', label: __( '30d', 'reseller-intent' ) },
		{ key: '90', label: __( '90d', 'reseller-intent' ) },
		{ key: 'all', label: __( 'Lifetime', 'reseller-intent' ) }
	];

	function fetchPanelRows(panel, range, custom, offset, mapRow) {
		var body = new window.FormData();
		body.append('action', 'rintent_panel_rows');
		body.append('nonce', resellerIntentAdmin.nonce);
		body.append('panel', panel);
		body.append('range', range);
		if (range === 'custom' && custom) {
			body.append('from', custom.from);
			body.append('to', custom.to);
		}
		body.append('offset', String(offset));

		return window.fetch(resellerIntentAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function(response) { return response.json(); })
			.then(function(json) {
				if (!json || !json.success || !json.data) {
					return { rows: [], hasMore: false };
				}
				return { rows: (json.data.items || []).map(mapRow), hasMore: !!json.data.hasMore };
			});
	}

	var PANELS = [
		{ key: 'trend', label: __( 'Search vs Cart Trend', 'reseller-intent' ) },
		{ key: 'tlds', label: __( 'Searched TLDs', 'reseller-intent' ) },
		{ key: 'carted', label: __( 'Carted Domains', 'reseller-intent' ) },
		{ key: 'repeats', label: __( 'Repeat Demand', 'reseller-intent' ) },
		{ key: 'quality', label: __( 'Availability & Devices', 'reseller-intent' ) },
		{ key: 'pages', label: __( 'Search by Page', 'reseller-intent' ) },
		{ key: 'countries', label: __( 'Top Countries', 'reseller-intent' ) },
		{ key: 'recent', label: __( 'Recent Searches', 'reseller-intent' ) }
	];

	var PANELS_STORAGE = 'rintentHiddenPanels';

	function loadHiddenPanels() {
		try {
			var raw = window.localStorage.getItem(PANELS_STORAGE);
			return raw ? JSON.parse(raw) : {};
		} catch (e) {
			return {};
		}
	}

	function saveHiddenPanels(hidden) {
		try {
			window.localStorage.setItem(PANELS_STORAGE, JSON.stringify(hidden));
		} catch (e) {
			// Private mode etc., preference just will not stick.
		}
	}

	var CLEAR_RANGES = [
		{ key: 'hour', label: __( 'Last hour', 'reseller-intent' ) },
		{ key: 'day', label: __( 'Last 24 hours', 'reseller-intent' ) },
		{ key: 'week', label: __( 'Last 7 days', 'reseller-intent' ) },
		{ key: 'month', label: __( 'Last 30 days', 'reseller-intent' ) },
		{ key: 'half_year', label: __( 'Last 6 months', 'reseller-intent' ) },
		{ key: 'year', label: __( 'Last year', 'reseller-intent' ) },
		{ key: 'all', label: __( 'All time', 'reseller-intent' ) }
	];

	function fmt(value, decimals) {
		var num = Number(value) || 0;
		return num.toLocaleString(undefined, {
			minimumFractionDigits: decimals || 0,
			maximumFractionDigits: decimals || 0
		});
	}

	function pct(part, total, decimals) {
		if (!total) {
			return 0;
		}
		return (part / total) * 100;
	}

	/* ---------- Small building blocks ---------- */

	function DeltaBadge(props) {
		var current = Number(props.current) || 0;
		var previous = props.previous === null || props.previous === undefined ? null : Number(props.previous);
		var cls = 'ri-delta is-flat';
		var text = '';

		if (previous === null) {
			text = __( 'All time', 'reseller-intent' );
		} else if (previous <= 0 && current <= 0) {
			return null; // no data either side, a badge is just noise
		} else if (previous <= 0) {
			cls = 'ri-delta is-up';
			text = __( 'New', 'reseller-intent' );
		} else {
			var change = ((current - previous) / previous) * 100;
			if (change >= 0.05) {
				cls = 'ri-delta is-up';
				text = '+' + fmt(Math.abs(change), 1) + '%';
			} else if (change <= -0.05) {
				cls = 'ri-delta is-down';
				text = '-' + fmt(Math.abs(change), 1) + '%';
			} else {
				text = '±0%';
			}
		}

		return el('span', { className: cls, title: previous === null ? '' : __( 'vs previous period', 'reseller-intent' ) }, text);
	}

	function Panel(props) {
		return el(
			'section',
			{ className: 'ri-panel' + (props.className ? ' ' + props.className : '') },
			el('header', { className: 'ri-panel-head' },
				el('h2', null, props.title),
				props.note ? el('p', { className: 'ri-note' }, props.note) : null
			),
			props.children
		);
	}

	function MiniTable(props) {
		var shellRef = useRef(null);
		var expandState = useState(false);
		var expanded = expandState[0];
		var setExpanded = expandState[1];
		var extraState = useState({ rows: [], hasMore: null, loading: false });
		var extra = extraState[0];
		var setExtra = extraState[1];
		var maxRows = props.maxRows || 6;
		var PAGE_SIZE = 15;
		var pageState = useState(1);
		var page = pageState[0];
		var setPage = pageState[1];

		// Re-span this table's masonry cell after any size-changing state,
		// deterministically (ResizeObserver sleeps in background tabs).
		useEffect(function() {
			var node = shellRef.current;
			var cell = node && node.closest ? node.closest('.ri-cell') : null;
			var panel = cell ? cell.querySelector('.ri-panel') : null;

			if (panel) {
				cell.style.gridRowEnd = 'span ' + Math.max(2, Math.ceil((panel.getBoundingClientRect().height + 16) / 8));
			}
		});

		var allRows = props.rows.concat(extra.rows);
		// Expanded view is PAGED at a fixed height instead of growing
		// forever: 15 rows per page, next fetches quietly when needed.
		var visible = expanded ? allRows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE) : allRows.slice(0, maxRows);
		var hidden = Math.max(props.totalRows || 0, allRows.length) - maxRows;
		// Until the server says otherwise, a full first slice means there
		// is probably more on the server.
		var hasMore = extra.hasMore === null
			? !!(props.loadMore && props.rows.length >= (props.initialFetched || 25))
			: extra.hasMore;
		var lastLoadedPage = Math.ceil(allRows.length / PAGE_SIZE);
		var totalRows = props.totalRows || 0;
		var totalPages = totalRows ? Math.max(1, Math.ceil(totalRows / PAGE_SIZE)) : 0;
		var canNext = totalPages ? page < totalPages : (page < lastLoadedPage || hasMore);

		function goNext() {
			if (page < lastLoadedPage) {
				setPage(page + 1);
				return;
			}
			if (totalPages && page >= totalPages) {
				return;
			}
			if ((!totalPages && !hasMore) || extra.loading || !props.loadMore) {
				return;
			}
			setExtra({ rows: extra.rows, hasMore: extra.hasMore, loading: true });
			props.loadMore(allRows.length).then(function(result) {
				setExtra({ rows: extra.rows.concat(result.rows), hasMore: result.hasMore, loading: false });
				if (result.rows.length) {
					setPage(page + 1);
				}
			}).catch(function() {
				setExtra({ rows: extra.rows, hasMore: false, loading: false });
			});
		}

		/*
		 * Column sizing: the first column flexes and truncates long values
		 * with a full-value tooltip; fixed-width columns (numbers, dates)
		 * hug the right so a lone "1" never floats in a wide gap.
		 */
		var colWidths = props.colWidths || props.columns.map(function(c, i) { return i === 0 ? '' : '110px'; });

		return el('div', { className: 'ri-table-shell', ref: shellRef },
			el('table', { className: 'ri-table ri-table--fixed' + (props.tableClass ? ' ' + props.tableClass : '') },
				el('colgroup', null, colWidths.map(function(w, i) {
					return el('col', { key: i, style: w ? { width: w } : null });
				})),
				el('thead', null,
					el('tr', null, props.columns.map(function(col, i) {
						return el('th', { key: i, className: colWidths[i] ? 'ri-col-tight' : null, title: typeof col === 'string' ? col : null }, col);
					}))
				),
				el('tbody', null,
					visible.length
						? visible.map(function(cells, r) {
							return el('tr', { key: r }, cells.map(function(cell, c) {
								return el('td', {
									key: c,
									className: colWidths[c] ? 'ri-col-tight' : null,
									title: typeof cell === 'string' ? cell : null
								}, cell);
							}));
						})
						: el('tr', null, el('td', { className: 'ri-empty', colSpan: props.columns.length }, props.empty))
				)
			),
			el('span', { className: 'ri-showmore-row' },
				props.copyList && allRows.length ? el('button', {
					className: 'ri-showmore',
					onClick: function(event) {
						var text = allRows.map(function(r) { return typeof r[0] === 'string' ? r[0] : ''; }).filter(Boolean).join('\n');
						if (navigator.clipboard && text) {
							navigator.clipboard.writeText(text);
							var btn = event.currentTarget;
							var original = btn.textContent;
							btn.textContent = __( 'Copied!', 'reseller-intent' );
							window.setTimeout(function() { btn.textContent = original; }, 1200);
						}
					}
				}, sprintf( /* translators: %s: number of domains */ __( 'Copy %s domains', 'reseller-intent' ), fmt(allRows.length) )) : null,
				hidden > 0 && ! expanded ? el('button', {
					className: 'ri-showmore',
					'aria-expanded': false,
					onClick: function() { setExpanded(true); setPage(1); }
				}, sprintf( /* translators: %s: number of hidden rows */ __( 'Show %s more', 'reseller-intent' ), fmt(hidden) )) : null,
				expanded && (canNext || page > 1) ? el('span', { className: 'ri-pager' },
					el('button', { className: 'button', disabled: page <= 1, 'aria-label': __( 'Previous page', 'reseller-intent' ), onClick: function() { setPage(Math.max(1, page - 1)); } }, '\u2039'),
					el('span', { className: 'ri-pager-state' }, extra.loading ? '\u2026' : (totalPages ? fmt(page) + ' / ' + fmt(totalPages) : fmt(page))),
					el('button', { className: 'button', disabled: !canNext || extra.loading, 'aria-label': __( 'Next page', 'reseller-intent' ), onClick: goNext }, '\u203a')
				) : null,
				expanded ? el('button', {
					className: 'ri-showmore',
					'aria-expanded': true,
					onClick: function() { setExpanded(false); setPage(1); }
				}, __( 'Show less', 'reseller-intent' )) : null
			)
		);
	}

	function BarRow(props) {
		return el('div', { className: 'ri-bar-row' },
			el('span', { className: 'ri-bar-label' }, props.label),
			el('span', { className: 'ri-bar-track' },
				el('span', { className: 'ri-bar-fill', style: { width: Math.min(100, props.width) + '%' } })
			),
			el('span', { className: 'ri-bar-value' }, props.value)
		);
	}

	function StatChip(props) {
		return el('div', { className: 'ri-chip' },
			el('strong', null, props.value),
			el('span', null, props.label)
		);
	}

	/* ---------- Trend chart (pure SVG) ---------- */

	function TrendChart(props) {
		var labels = props.labels || [];
		var searches = props.searches || [];
		var carts = props.carts || [];
		var W = 640;
		var H = 190;
		var padX = 36;
		var padY = 14;
		var plotW = W - padX - 10;
		var plotH = H - padY - 32;
		var maxVal = Math.max(1, Math.max.apply(null, searches.concat(carts, [1])));
		var n = labels.length;
		var step = n > 1 ? plotW / (n - 1) : plotW;

		function points(series) {
			return series.map(function(v, i) {
				var x = padX + i * step;
				var y = padY + plotH - (v / maxVal) * plotH;
				return x.toFixed(1) + ',' + y.toFixed(1);
			}).join(' ');
		}

		function areaPoints(series) {
			var base = (padY + plotH).toFixed(1);
			return padX + ',' + base + ' ' + points(series) + ' ' + (padX + (n - 1) * step).toFixed(1) + ',' + base;
		}

		var gridLines = [0.25, 0.5, 0.75].map(function(frac) {
			var y = (padY + plotH - frac * plotH).toFixed(1);
			return el('line', {
				key: 'g' + frac,
				x1: padX, y1: y, x2: padX + plotW, y2: y,
				stroke: '#eef2f7', strokeDasharray: '3 4'
			});
		});

		var yLabels = [
			el('text', { key: 'ymax', x: padX - 6, y: padY + 4, fontSize: 9, textAnchor: 'end', fill: '#64748b' }, fmt(maxVal)),
			el('text', { key: 'y0', x: padX - 6, y: padY + plotH + 3, fontSize: 9, textAnchor: 'end', fill: '#64748b' }, '0')
		];

		// sparse data renders as a near-invisible sliver, mark the active days
		var dots = null;
		var activeDays = searches.filter(function(v) { return v > 0; }).length;
		if (activeDays > 0 && activeDays <= 30) {
			dots = searches.map(function(v, i) {
				if (!v) {
					return null;
				}
				return el('circle', {
					key: 'd' + i,
					cx: (padX + i * step).toFixed(1),
					cy: (padY + plotH - (v / maxVal) * plotH).toFixed(1),
					r: 3,
					fill: ACCENT
				});
			});
		}

		var labelStep = Math.max(1, Math.floor(n / 6));
		var axisLabels = [];
		labels.forEach(function(label, i) {
			var isLast = i === n - 1;
			if (i % labelStep === 0 || isLast) {
				if (!isLast && n > 1 && (n - 1 - i) * step < 40) {
					return; // avoid overlapping the right-anchored last label
				}
				axisLabels.push(el('text', {
					key: i,
					x: (padX + i * step).toFixed(1),
					y: H - 8,
					fontSize: 9,
					textAnchor: isLast ? 'end' : (i === 0 ? 'start' : 'middle'),
					fill: '#64748b'
				}, label));
			}
		});

		if (!n) {
			return el('p', { className: 'ri-empty' }, __( 'No activity yet.', 'reseller-intent' ));
		}

		return el('svg', { className: 'ri-trend', viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': __( 'Search vs cart trend chart', 'reseller-intent' ) },
			gridLines,
			el('line', { x1: padX, y1: padY + plotH, x2: padX + plotW, y2: padY + plotH, stroke: '#e2e8f0' }),
			el('polygon', { points: areaPoints(searches), fill: accentAlpha(0.08) }),
			el('polyline', { points: points(searches), fill: 'none', stroke: ACCENT, strokeWidth: 2.5, strokeLinejoin: 'round', strokeLinecap: 'round' }),
			el('polyline', { points: points(carts), fill: 'none', stroke: INK, strokeWidth: 2.5, strokeLinejoin: 'round', strokeLinecap: 'round' }),
			dots,
			yLabels,
			axisLabels
		);
	}

	/* ---------- Panels ---------- */

	function KpiGrid(props) {
		var now = props.now || {};
		var prev = props.prev;
		var conversion = now.searches > 0 ? (now.cartClicks / now.searches) * 100 : 0;
		var avgCart = now.cartClicks > 0 ? now.domainsAdded / now.cartClicks : 0;
		var prevConversion = prev ? (prev.searches > 0 ? (prev.cartClicks / prev.searches) * 100 : 0) : null;
		var prevAvgCart = prev ? (prev.cartClicks > 0 ? prev.domainsAdded / prev.cartClicks : 0) : null;

		var cards = [
			{ label: __( 'Domain Searches', 'reseller-intent' ), tip: __( 'Every search run in the domain search box during the period.', 'reseller-intent' ), value: fmt(now.searches), current: now.searches, previous: prev ? prev.searches : null },
			{ label: __( 'Repeat Searches', 'reseller-intent' ), tip: __( 'Searches for a name already searched before in this period.', 'reseller-intent' ), value: fmt(now.repeatSearches), current: now.repeatSearches, previous: prev ? prev.repeatSearches : null },
			{ label: __( 'Cart Clicks', 'reseller-intent' ), tip: __( 'Presses of Continue to cart in the domain search widget.', 'reseller-intent' ), value: fmt(now.cartClicks), current: now.cartClicks, previous: prev ? prev.cartClicks : null },
			{ label: __( 'Domains Sent to Cart', 'reseller-intent' ), tip: __( 'Names still selected when Continue was pressed. Unticked or abandoned names are not counted.', 'reseller-intent' ), value: fmt(now.domainsAdded), current: now.domainsAdded, previous: prev ? prev.domainsAdded : null },
			{ label: __( 'Avg per Cart Click', 'reseller-intent' ), tip: __( 'Domains sent to cart divided by cart clicks.', 'reseller-intent' ), value: fmt(avgCart, 2), current: avgCart, previous: prevAvgCart },
			{ label: __( 'Search → Cart Rate', 'reseller-intent' ), tip: __( 'Cart clicks as a share of all searches. Compare against your own past periods.', 'reseller-intent' ), value: fmt(conversion, 1) + '%', current: conversion, previous: prevConversion }
		];

		return el('div', { className: 'ri-kpis' }, cards.map(function(card, i) {
			return el('div', { className: 'ri-kpi', key: i },
				el('p', { className: 'ri-kpi-label' }, card.label,
					el('span', { className: 'ri-tip', tabIndex: 0, 'data-tip': card.tip, 'aria-label': card.tip }, '?')
				),
				el('p', { className: 'ri-kpi-value' }, card.value),
				el(DeltaBadge, { current: card.current, previous: card.previous })
			);
		}));
	}

	function TldPanel(props) {
		var items = props.items || [];
		var total = props.total || 0;
		function mapItem(item) {
			return [
				item.label,
				el(Fragment, null,
					el('span', { className: 'ri-inline-track' },
						el('span', { className: 'ri-inline-fill', style: { width: pct(item.count, total) + '%' } })
					),
					fmt(item.count) + ' (' + fmt(pct(item.count, total), 1) + '%)'
				)
			];
		}
		var rows = items.map(mapItem);
		return el(Panel, { title: __( 'Searched TLDs', 'reseller-intent' ), note: __( 'Which extensions people look for.', 'reseller-intent' ) },
			el(MiniTable, { columns: [__( 'TLD', 'reseller-intent' ), __( 'Searches', 'reseller-intent' )], rows: rows, empty: __( 'No searches yet. Every search adds its TLD here.', 'reseller-intent' ), colWidths: ['', '200px'], initialFetched: 25, totalRows: props.totalRows, loadMore: props.loadRows ? function(offset) { return props.loadRows('tlds', offset, mapItem); } : null })
		);
	}

	function DemandPanel(props) {
		function mapItem(row) {
			return [row.domain, fmt(row.hits)];
		}
		var repeats = (props.repeats || []).map(mapItem);
		return el(Panel, { title: __( 'Repeat Demand', 'reseller-intent' ), note: __( 'Domains searched 2+ times, buyers circling.', 'reseller-intent' ) },
			el(MiniTable, { columns: [__( 'Domain', 'reseller-intent' ), __( 'Searches', 'reseller-intent' )], rows: repeats, empty: __( 'Quiet so far. When a visitor searches the same name twice, it lands here, a buyer circling.', 'reseller-intent' ), colWidths: ['', '100px'], initialFetched: 25, totalRows: props.totalRows, loadMore: props.loadRows ? function(offset) { return props.loadRows('repeats', offset, mapItem); } : null })
		);
	}

	function CartedPanel(props) {
		var carted = props.carted || { domains: [], tlds: [] };
		var cartSizes = props.cartSizes || [];
		var cartTotal = cartSizes.reduce(function(sum, b) { return sum + b.count; }, 0);
		function mapItem(row) {
			return [row.domain, fmt(row.count)];
		}
		var rows = carted.domains.map(mapItem);
		return el(Panel, { title: __( 'Carted Domains', 'reseller-intent' ), note: __( 'What shoppers actually sent to cart.', 'reseller-intent' ) },
			cartTotal > 0
				? el('div', { className: 'ri-bars ri-cart-split' }, cartSizes.map(function(bucket, i) {
					return el(BarRow, {
						key: i,
						label: bucket.label,
						width: pct(bucket.count, cartTotal),
						value: fmt(bucket.count) + ' (' + fmt(pct(bucket.count, cartTotal), 1) + '%)'
					});
				}))
				: null,
			carted.tlds.length
				? el('div', { className: 'ri-chips' }, carted.tlds.map(function(tld, i) {
					return el(StatChip, { key: i, value: fmt(tld.count), label: tld.label });
				}))
				: null,
			el(MiniTable, { columns: [__( 'Domain', 'reseller-intent' ), __( 'Added', 'reseller-intent' )], rows: rows, empty: __( 'Cart clicks will land here. Tracking is live, watch the Last event chip up top.', 'reseller-intent' ), colWidths: ['', '80px'], initialFetched: 15, totalRows: props.totalRows, loadMore: props.loadRows ? function(offset) { return props.loadRows('carted', offset, mapItem); } : null })
		);
	}

	function PagesPanel(props) {
		var items = props.pages || [];
		var total = items.reduce(function(sum, row) { return sum + (row.searches || 0); }, 0);
		var rows = items.map(function(row) {
			return [
				el('span', { title: row.path }, row.label || row.path),
				el(Fragment, null,
					el('span', { className: 'ri-inline-track' },
						el('span', { className: 'ri-inline-fill', style: { width: pct(row.searches, total) + '%' } })
					),
					fmt(row.searches) + ' (' + fmt(pct(row.searches, total), 1) + '%)'
				),
				fmt(row.carts)
			];
		});
		return el(Panel, { title: __( 'Search by Page', 'reseller-intent' ), note: __( 'Which page each search and cart click came from.', 'reseller-intent' ) },
			el(MiniTable, { columns: [__( 'Page', 'reseller-intent' ), __( 'Searches', 'reseller-intent' ), __( 'Cart clicks', 'reseller-intent' )], rows: rows, colWidths: ['', '158px', '78px'], tableClass: 'ri-table--pages', empty: __( 'Once searches come in, you will see which page they happen on.', 'reseller-intent' ) })
		);
	}

	function QualityPanel(props) {
		var availability = props.availability || { available: 0, taken: 0 };
		var devices = props.devices || { mobile: 0, tablet: 0, desktop: 0 };
		var availTotal = availability.available + availability.taken;
		var availRate = pct(availability.available, availTotal);
		var deviceTotal = devices.mobile + (devices.tablet || 0) + devices.desktop;

		/*
		 * This panel's content is fixed size forever (3 chips, 2 device
		 * bars), so it renders as a wide short strip instead of being
		 * stretched down a tall column it can never fill.
		 */
		return el(Panel, { title: __( 'Availability & Devices', 'reseller-intent' ), note: __( 'How often the searched name is free, and who is searching.', 'reseller-intent' ) },
			el('div', { className: 'ri-quality-wide' },
				el('div', { className: 'ri-quality-col' },
					availTotal > 0
						? el('div', { className: 'ri-chips' },
							el(StatChip, { value: fmt(availRate, 1) + '%', label: __( 'Available', 'reseller-intent' ) }),
							el(StatChip, { value: fmt(availability.available), label: __( 'Free', 'reseller-intent' ) }),
							el(StatChip, { value: fmt(availability.taken), label: __( 'Taken', 'reseller-intent' ) })
						)
						: el('p', { className: 'ri-empty' }, __( 'No availability data in this range yet.', 'reseller-intent' ))
				),
				el('div', { className: 'ri-quality-col' },
					el('p', { className: 'ri-subhead' }, __( 'Searches by device', 'reseller-intent' )),
					deviceTotal > 0
						? el('div', { className: 'ri-bars' },
							el(BarRow, { label: __( 'Desktop', 'reseller-intent' ), width: pct(devices.desktop, deviceTotal), value: fmt(devices.desktop) + ' (' + fmt(pct(devices.desktop, deviceTotal), 1) + '%)' }),
							el(BarRow, { label: __( 'Tablet', 'reseller-intent' ), width: pct(devices.tablet || 0, deviceTotal), value: fmt(devices.tablet || 0) + ' (' + fmt(pct(devices.tablet || 0, deviceTotal), 1) + '%)' }),
							el(BarRow, { label: __( 'Mobile', 'reseller-intent' ), width: pct(devices.mobile, deviceTotal), value: fmt(devices.mobile) + ' (' + fmt(pct(devices.mobile, deviceTotal), 1) + '%)' })
						)
						: el('p', { className: 'ri-empty' }, __( 'No device data in this range yet.', 'reseller-intent' ))
				)
			)
		);
	}

	function RecentLog(props) {
		var _f = useState(''), filter = _f[0], setFilter = _f[1];
		var _p = useState(1), page = _p[0], setPage = _p[1];
		var perPage = 12;
		var rows = props.recent || [];

		var filtered = useMemo(function() {
			var query = filter.trim().toLowerCase();
			if (!query) {
				return rows;
			}
			return rows.filter(function(row) {
				return row.domain.indexOf(query) !== -1;
			});
		}, [rows, filter]);

		var totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
		var safePage = Math.min(page, totalPages);
		var pageRows = filtered.slice((safePage - 1) * perPage, safePage * perPage).map(function(row) {
			var availCell = '-';
			if (row.available === true) {
				availCell = el('span', { className: 'ri-tag is-good' }, __( 'Available', 'reseller-intent' ));
			} else if (row.available === false) {
				availCell = el('span', { className: 'ri-tag is-bad' }, __( 'Registered', 'reseller-intent' ));
			}
			return [row.domain, availCell, row.device || '-', row.time];
		});

		var noteText = rows.length === 1
			? 'Latest search in this range (' + TZ_LABEL + ').'
			: 'Latest ' + fmt(rows.length) + ' searches in this range (' + TZ_LABEL + ').';

		return el(Panel, { title: __( 'Recent Searches', 'reseller-intent' ), note: noteText, className: 'ri-panel--wide' },
			el('div', { className: 'ri-log-tools' },
				el('input', {
					type: 'search',
					className: 'ri-log-filter',
					placeholder: __( 'Filter domains...', 'reseller-intent' ),
					value: filter,
					onChange: function(event) {
						setFilter(event.target.value);
						setPage(1);
					}
				}),
				totalPages > 1
					? el('span', { className: 'ri-pager' },
						el('button', {
							className: 'button',
							disabled: safePage <= 1,
							onClick: function() { setPage(safePage - 1); }
						}, '‹'),
						el('span', { className: 'ri-pager-state' }, safePage + ' / ' + totalPages),
						el('button', {
							className: 'button',
							disabled: safePage >= totalPages,
							onClick: function() { setPage(safePage + 1); }
						}, '›')
					)
					: null
			),
			el(MiniTable, {
				columns: [__( 'Domain', 'reseller-intent' ), __( 'Result', 'reseller-intent' ), __( 'Device', 'reseller-intent' ), __( 'Searched At', 'reseller-intent' )], colWidths: ['', '90px', '95px', '155px'],
				rows: pageRows,
				empty: filter ? __( 'Nothing matches that filter.', 'reseller-intent' ) : __( 'No searches in this range.', 'reseller-intent' )
			})
		);
	}

	function flagEmoji(code) {
		if (!/^[A-Z]{2}$/.test(code)) {
			return '';
		}
		return String.fromCodePoint(0x1F1E6 + code.charCodeAt(0) - 65, 0x1F1E6 + code.charCodeAt(1) - 65);
	}

	function CountriesPanel(props) {
		var items = (props.countries && props.countries.items) || [];
		var total = (props.countries && props.countries.total) || 0;

		return el(Panel, { title: __( 'Top Countries', 'reseller-intent' ), note: items.length ? 'Searches by visitor country (edge geo header).' : null },
			items.length
				? items.map(function(item) {
					return el(BarRow, {
						key: item.code,
						label: flagEmoji(item.code) + ' ' + item.code,
						width: pct(item.hits, total),
						value: fmt(item.hits)
					});
				})
				: el('p', { className: 'ri-empty' }, __( 'No country data yet. Your host/CDN needs to send a geo header (e.g. Cloudflare’s CF-IPCountry).', 'reseller-intent' ))
		);
	}

	/*
	 * Browser-style "Clear data" modal: pick a window, see exactly how many
	 * events it covers, then confirm. Preview count loads live per range.
	 */
	function ClearDataModal(props) {
		var _s = useState('hour'), sel = _s[0], setSel = _s[1];
		var _c = useState(null), count = _c[0], setCount = _c[1];

		useEffect(function() {
			var cancelled = false;
			setCount(null);

			var body = new window.FormData();
			body.append('action', 'rintent_clear_preview');
			body.append('nonce', resellerIntentAdmin.actionNonce);
			body.append('range', sel);

			window.fetch(resellerIntentAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function(response) { return response.json(); })
				.then(function(json) {
					if (!cancelled && json && json.success && json.data) {
						setCount(Number(json.data.count) || 0);
					}
				})
				.catch(function() {});

			return function() { cancelled = true; };
		}, [sel]);

		useEffect(function() {
			var selectEl = document.getElementById('ri-clear-range');
			if (selectEl) {
				selectEl.focus();
			}

			function onKey(event) {
				if (event.key === 'Escape') {
					props.onCancel();
					return;
				}
				if (event.key !== 'Tab') {
					return;
				}
				var focusables = document.querySelectorAll('.ri-modal select, .ri-modal button:not([disabled])');
				if (!focusables.length) {
					return;
				}
				var first = focusables[0];
				var last = focusables[focusables.length - 1];
				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				}
			}

			document.addEventListener('keydown', onKey);
			return function() { document.removeEventListener('keydown', onKey); };
		}, []);

		return el('div', { className: 'ri-modal-backdrop', onClick: props.onCancel },
			el('div', {
				className: 'ri-modal',
				role: 'dialog',
				'aria-modal': 'true',
				'aria-label': __( 'Clear data', 'reseller-intent' ),
				onClick: function(event) { event.stopPropagation(); }
			},
				el('h2', null, __( 'Clear data', 'reseller-intent' )),
				el('p', null, __( 'Delete tracked events from the selected time window. There is no undo.', 'reseller-intent' )),
				el('label', { className: 'ri-clear-label', htmlFor: 'ri-clear-range' }, __( 'Time window', 'reseller-intent' )),
				el('select', {
					id: 'ri-clear-range',
					className: 'ri-clear-select',
					value: sel,
					onChange: function(event) { setSel(event.target.value); }
				}, CLEAR_RANGES.map(function(option) {
					return el('option', { key: option.key, value: option.key }, option.label);
				})),
				el('p', { className: 'ri-clear-preview' },
					count === null
						? 'Counting...'
						: (count === 0 ? __( 'No events in this window.', 'reseller-intent' ) : sprintf( /* translators: %s: number of events */ wp.i18n._n( '%s event will be permanently deleted.', '%s events will be permanently deleted.', count, 'reseller-intent' ), fmt(count) ))
				),
				el('div', { className: 'ri-modal-actions' },
					el('button', { className: 'button', onClick: props.onCancel }, __( 'Cancel', 'reseller-intent' )),
					el('button', {
						className: 'button ri-danger',
						disabled: count === null || count === 0,
						onClick: function() { props.onConfirm(sel); }
					}, __( 'Clear data', 'reseller-intent' ))
				)
			)
		);
	}

	/* ---------- App ---------- */

	function App() {
		var _r = useState('90'), range = _r[0], setRange = _r[1];
		var _d = useState(null), data = _d[0], setData = _d[1];
		var _l = useState(true), loading = _l[0], setLoading = _l[1];
		var _e = useState(''), error = _e[0], setError = _e[1];
		var _m = useState(false), showClear = _m[0], setShowClear = _m[1];
		var _h = useState(loadHiddenPanels), hiddenPanels = _h[0], setHiddenPanels = _h[1];

		/*
		 * Masonry sizing: each cell spans its own content height in 8px
		 * grid rows, so expanding one panel never stretches its row
		 * neighbors and no white space opens up anywhere.
		 */
		useEffect(function() {
			var grid = document.querySelector('.ri-liquid');

			if (!grid) {
				return undefined;
			}

			function spanCell(panel) {
				var height = panel.getBoundingClientRect().height;
				// +16 covers the panel's bottom margin (the visual row gap).
				panel.parentElement.style.gridRowEnd = 'span ' + Math.max(2, Math.ceil((height + 16) / 8));
			}

			function measureAll() {
				grid.querySelectorAll('.ri-cell > .ri-panel').forEach(spanCell);
			}

			/*
			 * Measure synchronously on every render: ResizeObserver (and
			 * anything rAF-timed) is suspended in background tabs, so a
			 * dashboard opened behind another tab would keep zero spans.
			 * The observer then covers out-of-band changes only (fonts,
			 * window resizes), with a late timeout as a further net.
			 */
			measureAll();
			var late = window.setTimeout(measureAll, 400);

			var observer = null;
			if (window.ResizeObserver) {
				observer = new window.ResizeObserver(function(entries) {
					entries.forEach(function(entry) {
						spanCell(entry.target);
					});
				});
				grid.querySelectorAll('.ri-cell > .ri-panel').forEach(function(panel) {
					observer.observe(panel);
				});
			}

			var resizeSettle = null;
			function onResize() {
				measureAll();
				window.clearTimeout(resizeSettle);
				resizeSettle = window.setTimeout(measureAll, 250);
			}
			window.addEventListener('resize', onResize);

			return function() {
				window.clearTimeout(late);
				window.clearTimeout(resizeSettle);
				window.removeEventListener('resize', onResize);
				if (observer) {
					observer.disconnect();
				}
			};
		});
		var _pp = useState(false), showPanelsMenu = _pp[0], setShowPanelsMenu = _pp[1];

		function isShown(key) {
			return !hiddenPanels[key];
		}

		function togglePanel(key) {
			var next = {};
			Object.keys(hiddenPanels).forEach(function(k) { next[k] = hiddenPanels[k]; });
			if (next[key]) {
				delete next[key];
			} else {
				next[key] = true;
			}
			setHiddenPanels(next);
			saveHiddenPanels(next);
		}
		var _n = useState(resellerIntentAdmin.notice || ''), notice = _n[0], setNotice = _n[1];
		var today = new Date().toISOString().slice(0, 10);
		var _cf = useState(today), customFrom = _cf[0], setCustomFrom = _cf[1];
		var _ct = useState(today), customTo = _ct[0], setCustomTo = _ct[1];
		var _ca = useState({ from: today, to: today }), customApplied = _ca[0], setCustomApplied = _ca[1];

		useEffect(function() {
			var cancelled = false;
			setLoading(true);
			setError('');

			var body = new window.FormData();
			body.append('action', 'rintent_dashboard_data');
			body.append('nonce', resellerIntentAdmin.nonce);
			body.append('range', range);
			if (range === 'custom') {
				body.append('from', customApplied.from);
				body.append('to', customApplied.to);
			}

			window.fetch(resellerIntentAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function(response) { return response.json(); })
				.then(function(json) {
					if (cancelled) {
						return;
					}
					if (json && json.success && json.data) {
						setData(json.data);
					} else {
						setError( __( 'Could not load dashboard data.', 'reseller-intent' ) );
					}
				})
				.catch(function() {
					if (!cancelled) {
						setError( __( 'Could not load dashboard data.', 'reseller-intent' ) );
					}
				})
				.then(function() {
					if (!cancelled) {
						setLoading(false);
					}
				});

			return function() { cancelled = true; };
		}, [range, customApplied]);

		function confirmClear(rangeKey) {
			var form = document.createElement('form');
			form.method = 'post';
			form.action = resellerIntentAdmin.clearUrl;

			var nonceField = document.createElement('input');
			nonceField.type = 'hidden';
			nonceField.name = '_wpnonce';
			nonceField.value = resellerIntentAdmin.actionNonce;
			form.appendChild(nonceField);

			var rangeField = document.createElement('input');
			rangeField.type = 'hidden';
			rangeField.name = 'range';
			rangeField.value = rangeKey;
			form.appendChild(rangeField);

			document.body.appendChild(form);
			form.submit();
		}

		function clearNoticeText(key) {
			var match = /^cleared_(\d+)$/.exec(key || '');
			if (!match) {
				return '';
			}
			var n = Number(match[1]) || 0;
			return n === 0 ? __( 'No events matched that window.', 'reseller-intent' ) : sprintf( /* translators: %s: number of events */ wp.i18n._n( '%s event deleted.', '%s events deleted.', n, 'reseller-intent' ), fmt(n) );
		}

		var rangeQuery = '&range=' + encodeURIComponent(range)
			+ (range === 'custom' ? '&from=' + encodeURIComponent(customApplied.from) + '&to=' + encodeURIComponent(customApplied.to) : '');
		var exportHref = resellerIntentAdmin.exportUrl + rangeQuery;
		var exportJsonHref = resellerIntentAdmin.exportUrl + rangeQuery + '&format=json';

		return el('div', { className: 'ri-app' + (loading ? ' is-loading' : ''), 'aria-busy': loading ? 'true' : 'false', style: { '--ri-accent': ACCENT, '--ri-accent-text': ACCENT_TEXT } },
			loading ? el('div', { className: 'ri-progress', role: 'status', 'aria-label': __( 'Loading', 'reseller-intent' ) }) : null,
			el('div', { className: 'ri-header' },
				el('div', null,
					el('h1', null, __( 'Reseller Intent', 'reseller-intent' )),
					el('p', { className: 'ri-note' },
						__( 'Domain search analytics', 'reseller-intent' ) + ' · v' + resellerIntentAdmin.version +
						(data ? ' · ' + data.rangeLabel : ''),
						data && data.lastEvent ? el('span', {
							className: 'ri-health' + (data.lastEvent.stale ? ' is-stale' : ''),
							title: data.lastEvent.stale ? __( 'No recent events. Check that the search widget is live and tracking is not blocked.', 'reseller-intent' ) : null
						}, data.lastEvent.ago) : null
					)
				),
				el('div', { className: 'ri-header-actions' },
					el('span', { className: 'ri-ranges' }, RANGES.concat([{ key: 'custom', label: __( 'Custom', 'reseller-intent' ) }]).map(function(option) {
						return el('button', {
							key: option.key,
							className: 'ri-range' + (range === option.key ? ' is-active' : ''),
							'aria-pressed': range === option.key,
							onClick: function() { setRange(option.key); }
						}, option.label);
					})),
					el('span', { className: 'ri-export-group' },
						el('a', { className: 'button ri-export', href: exportHref }, __( 'Export CSV', 'reseller-intent' )),
						el('a', { className: 'button ri-export', href: exportJsonHref, title: __( 'Export JSON', 'reseller-intent' ) }, __( 'JSON', 'reseller-intent' ))
					),
					el('button', { className: 'button ri-danger-ghost', onClick: function() { setShowClear(true); } }, __( 'Clear data', 'reseller-intent' )),
					el('span', { className: 'ri-panels-menu' },
						el('button', {
							className: 'button ri-panels-toggle',
							'aria-expanded': showPanelsMenu,
							'aria-haspopup': 'true',
							title: __( 'Choose which panels to show', 'reseller-intent' ),
							onClick: function() { setShowPanelsMenu(!showPanelsMenu); }
						}, __( 'Panels', 'reseller-intent' )),
						showPanelsMenu ? el('div', { className: 'ri-panels-pop', role: 'group', 'aria-label': __( 'Visible panels', 'reseller-intent' ) },
							PANELS.map(function(panel) {
								return el('label', { key: panel.key, className: 'ri-panels-item' },
									el('input', {
										type: 'checkbox',
										checked: isShown(panel.key),
										onChange: function() { togglePanel(panel.key); }
									}),
									panel.label
								);
							})
						) : null
					)
				)
			),

			range === 'custom' ? el('div', { className: 'ri-custom-range' },
				el('label', null, __( 'From', 'reseller-intent' ) + ' ',
					el('input', { type: 'date', value: customFrom, max: today, onChange: function(e) { setCustomFrom(e.target.value); } })
				),
				el('label', null, __( 'To', 'reseller-intent' ) + ' ',
					el('input', { type: 'date', value: customTo, max: today, onChange: function(e) { setCustomTo(e.target.value); } })
				),
				el('button', {
					className: 'button',
					disabled: !customFrom || !customTo || customFrom > customTo,
					onClick: function() { setCustomApplied({ from: customFrom, to: customTo }); }
				}, __( 'Apply', 'reseller-intent' ))
			) : null,

			clearNoticeText(notice) ? el('div', { className: 'notice notice-success is-dismissible ri-notice', onClick: function() { setNotice(''); } }, el('p', null, clearNoticeText(notice))) : null,
			notice === 'clear_error' ? el('div', { className: 'notice notice-error ri-notice' }, el('p', null, __( 'Clearing data failed.', 'reseller-intent' ))) : null,
			notice === 'settings_saved' ? el('div', { className: 'notice notice-success is-dismissible ri-notice', onClick: function() { setNotice(''); } }, el('p', null, __( 'Settings saved.', 'reseller-intent' ))) : null,
			/^imported_\d+$/.test(notice) ? el('div', { className: 'notice notice-success is-dismissible ri-notice', onClick: function() { setNotice(''); } }, el('p', null, sprintf( /* translators: %s: number of events */ __( '%s legacy events imported.', 'reseller-intent' ), fmt(Number(notice.replace('imported_', '')) || 0) ))) : null,
			error ? el('div', { className: 'notice notice-error ri-notice' }, el('p', null, error)) : null,

			data
				? el(Fragment, null,
					el(KpiGrid, { now: data.kpis.now, prev: data.kpis.prev }),
					(function() {
						/*
						 * Liquid layout: one 12-column dense grid. Every panel
						 * declares its natural width; panels with data come
						 * first, empty ones shrink and pack together at the
						 * end, so no range ever leaves holes in the middle.
						 */
						var now = data.kpis.now;
						/*
						 * Remount the panels when the RANGE changes, not when the
						 * rows array is a new object. Every parent render built a
						 * fresh array, so the old reset effect fired on any render
						 * at all and threw away pages the visitor had loaded.
						 */
						var rangeKey = range + ('custom' === range ? '|' + customApplied.from + '|' + customApplied.to : '');
						var loadRows = function(panel, offset, mapRow) {
							return fetchPanelRows(panel, range, customApplied, offset, mapRow);
						};
						var defs = [
							{ key: 'quality', span: 12, short: true, isEmpty: !(data.availability.available + data.availability.taken), node: el(QualityPanel, { availability: data.availability, devices: data.devices }) },
							{ key: 'trend', span: 8, isEmpty: !((data.trend.searches || []).some(function(v) { return v > 0; }) || (data.trend.carts || []).some(function(v) { return v > 0; })), node: el(Panel, { title: __( 'Search vs Cart Trend', 'reseller-intent' ), note: data.bounded ? __( 'Daily activity in this range.', 'reseller-intent' ) : __( 'Monthly activity, all time.', 'reseller-intent' ) },
								el(TrendChart, data.trend),
								el('div', { className: 'ri-legend' },
									el('span', null, el('i', { className: 'ri-dot', style: { background: ACCENT } }), __( 'Searches', 'reseller-intent' )),
									el('span', null, el('i', { className: 'ri-dot', style: { background: INK } }), __( 'Cart clicks', 'reseller-intent' ))
								)
							) },
							{ key: 'tlds', span: 4, isEmpty: !data.tlds.items.length, node: el(TldPanel, { items: data.tlds.items, total: data.tlds.total, totalRows: (data.totals || {}).tlds, loadRows: loadRows }) },
							{ key: 'carted', span: 4, isEmpty: !data.carted.domains.length, node: el(CartedPanel, { carted: data.carted, cartSizes: data.cartSizes, totalRows: (data.totals || {}).carted, loadRows: loadRows }) },
							{ key: 'repeats', span: 4, isEmpty: !data.repeats.length, node: el(DemandPanel, { repeats: data.repeats, totalRows: (data.totals || {}).repeats, loadRows: loadRows }) },
							{ key: 'pages', span: 4, short: true, isEmpty: !data.pages.length, node: el(PagesPanel, { pages: data.pages }) },
							{ key: 'countries', span: 4, short: true, isEmpty: !data.countries.items.length, node: el(CountriesPanel, { countries: data.countries }) },
							{ key: 'recent', span: 12, isEmpty: !data.recent.length, node: el(RecentLog, { recent: data.recent }) }
						].filter(function(d) { return isShown(d.key); });

						var filled = defs.filter(function(d) { return !d.isEmpty; });
						var empties = defs.filter(function(d) { return d.isEmpty; });

						return el('div', { className: 'ri-liquid', key: rangeKey }, filled.concat(empties).map(function(d) {
							var span = d.isEmpty ? 4 : d.span;
							return el('div', { key: d.key, className: 'ri-cell ri-span-' + span + (d.short ? ' ri-cell--short' : '') + (d.isEmpty ? ' ri-cell--empty' : '') }, d.node);
						}));
					})()
				)
				: (loading ? el('div', { className: 'ri-loading' }, __( 'Loading...', 'reseller-intent' )) : null),

			showClear ? el(ClearDataModal, {
				onCancel: function() { setShowClear(false); },
				onConfirm: confirmClear
			}) : null
		);
	}

	function boot() {
		var root = document.getElementById('rintent-root');
		if (!root || typeof wp === 'undefined' || !wp.element) {
			return;
		}
		if (wp.element.createRoot) {
			wp.element.createRoot(root).render(el(App));
		} else {
			wp.element.render(el(App), root);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
