/* global resellerIntentAdmin, wp */
(function() {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	/*
	 * Blue D2: the dashboard's own fixed palette (assets/css/admin.css owns
	 * the tokens). Only the SVG chart needs the raw values here.
	 */
	var ACCENT = '#435FE8';
	var INK = '#1A222C';

	/**
	 * The accent at a given alpha, for the chart's area fill.
	 */
	function accentAlpha(alpha) {
		var hex = ACCENT.replace('#', '');

		return 'rgba(' + parseInt(hex.slice(0, 2), 16) + ','
			+ parseInt(hex.slice(2, 4), 16) + ','
			+ parseInt(hex.slice(4, 6), 16) + ',' + alpha + ')';
	}

	var TZ_LABEL = (window.resellerIntentAdmin && resellerIntentAdmin.tzLabel) || '';

	var RANGES = [
		{ key: '7', label: __( '7d', 'reseller-intent' ) },
		{ key: '30', label: __( '30d', 'reseller-intent' ) },
		{ key: '90', label: __( '90d', 'reseller-intent' ) },
		{ key: 'all', label: __( 'Lifetime', 'reseller-intent' ) }
	];

	// One geometry for every list panel: 10 rows per page, pager pinned in
	// the footer, deep paging stops at 500 rows; past that the export is
	// the tool.
	var PAGE_SIZE = 10;
	var DEEP_CAP = 500;

	function fetchPanelRows(panel, range, custom, pagePath, offset, mapRow) {
		var body = new window.FormData();
		body.append('action', 'rintent_panel_rows');
		body.append('nonce', resellerIntentAdmin.nonce);
		body.append('panel', panel);
		body.append('range', range);
		if (range === 'custom' && custom) {
			body.append('from', custom.from);
			body.append('to', custom.to);
		}
		if (pagePath) {
			body.append('page_path', pagePath);
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
		{ key: 'repeats', label: __( 'Repeat Demand', 'reseller-intent' ) },
		{ key: 'carted', label: __( 'Carted Domains', 'reseller-intent' ) },
		{ key: 'pages', label: __( 'Search by Page', 'reseller-intent' ) },
		{ key: 'countries', label: __( 'Top Countries', 'reseller-intent' ) },
		{ key: 'recent', label: __( 'Recent Searches', 'reseller-intent' ) }
	];

	// Panels hidden until the visitor opts in. Countries needs an edge geo
	// header most sites do not send, so it starts off.
	var DEFAULT_HIDDEN = { countries: true };

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

	function pct(part, total) {
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

	/**
	 * The one list-panel machine: header, exactly 10 rows of 36px, pager
	 * pinned in the footer. Row partners always match height, so the grid
	 * never opens gaps. Short pages keep the height; the structured space
	 * reads as room to grow.
	 */
	function ListPanel(props) {
		var pageSize = props.pageSize || PAGE_SIZE;
		var _p = useState(1), page = _p[0], setPage = _p[1];
		var _x = useState({ rows: [], hasMore: null, loading: false }), extra = _x[0], setExtra = _x[1];

		var allRows = props.rows.concat(extra.rows);
		var loadedTotal = allRows.length;
		var serverTotal = props.totalRows || 0;
		var knownTotal = Math.max(serverTotal, loadedTotal);
		// The 500-row cap protects server-backed deep paging only; panels
		// that already hold every row client-side page through all of them.
		var capped = !!props.loadMore && knownTotal > DEEP_CAP;
		var cappedTotal = capped ? DEEP_CAP : knownTotal;
		var totalPages = Math.max(1, Math.ceil(cappedTotal / pageSize));
		// Until the server says otherwise, a full first slice means there
		// is probably more on the server.
		var hasMore = extra.hasMore === null
			? !!(props.loadMore && props.rows.length >= (props.initialFetched || 25))
			: extra.hasMore;
		// With a known server total the page count is authoritative; without
		// one (Countries) the pager keeps going while the server has more.
		var canNext = (page * pageSize < cappedTotal)
			|| (!serverTotal && !!props.loadMore && hasMore && page * pageSize < DEEP_CAP);

		var visible = allRows.slice((page - 1) * pageSize, page * pageSize);

		function goNext() {
			if (!canNext || extra.loading) {
				return;
			}
			// Fetch ahead when the next page would render short of rows the
			// server still has, so middle pages always arrive full.
			var nextEnd = (page + 1) * pageSize;
			if (nextEnd <= loadedTotal || !props.loadMore || !hasMore) {
				setPage(page + 1);
				return;
			}
			setExtra({ rows: extra.rows, hasMore: extra.hasMore, loading: true });
			props.loadMore(loadedTotal).then(function(result) {
				setExtra({ rows: extra.rows.concat(result.rows), hasMore: result.hasMore, loading: false });
				if (loadedTotal + result.rows.length > page * pageSize) {
					setPage(page + 1);
				}
			}).catch(function() {
				setExtra({ rows: extra.rows, hasMore: false, loading: false });
			});
		}

		var footLabel = props.footLabel || '';
		if (capped) {
			/* translators: 1: number of listed rows, 2: total number of rows */
			footLabel = sprintf( __( '%1$s of %2$s. Export has everything.', 'reseller-intent' ), fmt(DEEP_CAP), fmt(knownTotal) );
		}

		var colWidths = props.colWidths || props.columns.map(function(c, i) { return i === 0 ? '' : '110px'; });

		return el('section', { className: 'ri-panel' + (props.className ? ' ' + props.className : '') },
			el('header', { className: 'ri-panel-head' },
				el('h2', null, props.title),
				props.note ? el('p', { className: 'ri-note' }, props.note) : null
			),
			props.tools || null,
			el('div', { className: 'ri-panel-body' },
				props.children,
				el('table', { className: 'ri-table' },
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
				)
			),
			el('footer', { className: 'ri-panel-foot' },
				el('span', { className: 'ri-foot-label' }, footLabel),
				el('span', { className: 'ri-pager' },
					el('button', { className: 'ri-page-btn', disabled: page <= 1, 'aria-label': __( 'Previous page', 'reseller-intent' ), onClick: function() { setPage(Math.max(1, page - 1)); } }, '‹'),
					el('span', { className: 'ri-pager-state' }, extra.loading ? '…' : fmt(page) + ' / ' + fmt(totalPages)),
					el('button', { className: 'ri-page-btn', disabled: !canNext || extra.loading, 'aria-label': __( 'Next page', 'reseller-intent' ), onClick: goNext }, '›')
				)
			)
		);
	}

	/* ---------- Trend chart (pure SVG) ---------- */

	function TrendChart(props) {
		var labels = props.labels || [];
		var searches = props.searches || [];
		var carts = props.carts || [];
		var W = 640;
		var H = 260;
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
				stroke: '#eceff3', strokeDasharray: '3 4'
			});
		});

		var yLabels = [
			el('text', { key: 'ymax', x: padX - 6, y: padY + 4, fontSize: 9, textAnchor: 'end', fill: '#6d7585' }, fmt(maxVal)),
			el('text', { key: 'y0', x: padX - 6, y: padY + plotH + 3, fontSize: 9, textAnchor: 'end', fill: '#6d7585' }, '0')
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
					fill: '#6d7585'
				}, label));
			}
		});

		if (!n) {
			return el('p', { className: 'ri-empty' }, __( 'No activity yet.', 'reseller-intent' ));
		}

		return el('svg', { className: 'ri-trend', viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': __( 'Search vs cart trend chart', 'reseller-intent' ) },
			gridLines,
			el('line', { x1: padX, y1: padY + plotH, x2: padX + plotW, y2: padY + plotH, stroke: '#e4e7ed' }),
			el('polygon', { points: areaPoints(searches), fill: accentAlpha(0.1) }),
			el('polyline', { points: points(searches), fill: 'none', stroke: ACCENT, strokeWidth: 2.5, strokeLinejoin: 'round', strokeLinecap: 'round' }),
			el('polyline', { points: points(carts), fill: 'none', stroke: INK, strokeWidth: 2, strokeOpacity: 0.55, strokeLinejoin: 'round', strokeLinecap: 'round' }),
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

	function TrendPanel(props) {
		var trend = props.trend || {};
		var availability = props.availability || { available: 0, taken: 0 };
		var devices = props.devices || { mobile: 0, tablet: 0, desktop: 0 };
		var availTotal = availability.available + availability.taken;
		var deviceTotal = devices.mobile + (devices.tablet || 0) + devices.desktop;

		var chips = [];
		if (availTotal > 0) {
			chips.push(el('span', { className: 'ri-stat-chip', key: 'avail', title: __( 'How often the searched name was free to register.', 'reseller-intent' ) },
				__( 'Available', 'reseller-intent' ), ' ', el('b', null, fmt(pct(availability.available, availTotal), 0) + '%')
			));
		}
		if (deviceTotal > 0) {
			chips.push(el('span', { className: 'ri-stat-chip', key: 'mobile', title: __( 'Share of searches made on a phone.', 'reseller-intent' ) },
				__( 'Mobile', 'reseller-intent' ), ' ', el('b', null, fmt(pct(devices.mobile, deviceTotal), 0) + '%')
			));
		}

		return el('section', { className: 'ri-panel' },
			el('header', { className: 'ri-panel-head' },
				el('h2', null, __( 'Search vs Cart Trend', 'reseller-intent' )),
				el('p', { className: 'ri-note' }, props.bounded ? __( 'Daily activity in this range.', 'reseller-intent' ) : __( 'Monthly activity, all time.', 'reseller-intent' ))
			),
			el('div', { className: 'ri-panel-body ri-chart' },
				chips.length ? el('div', { className: 'ri-stat-chips' }, chips) : null,
				el(TrendChart, trend),
				el('div', { className: 'ri-legend' },
					el('span', null, el('i', { className: 'ri-dot', style: { background: ACCENT } }), __( 'Searches', 'reseller-intent' )),
					el('span', null, el('i', { className: 'ri-dot ri-dot--ink' }), __( 'Cart clicks', 'reseller-intent' ))
				)
			),
			el('footer', { className: 'ri-panel-foot' },
				el('span', { className: 'ri-foot-label' }, props.rangeLabel || ''),
				el('span', null)
			)
		);
	}

	function TldPanel(props) {
		function mapItem(item) {
			return [item.label, fmt(item.count)];
		}
		return el(ListPanel, {
			title: __( 'Searched TLDs', 'reseller-intent' ),
			note: __( 'Which extensions people look for.', 'reseller-intent' ),
			columns: [__( 'TLD', 'reseller-intent' ), __( 'Searches', 'reseller-intent' )],
			colWidths: ['', '110px'],
			rows: (props.items || []).map(mapItem),
			empty: __( 'No searches yet. Every search adds its TLD here.', 'reseller-intent' ),
			initialFetched: 25,
			totalRows: props.totalRows,
			/* translators: %s: number of TLDs */
			footLabel: sprintf( _n( '%s TLD', '%s TLDs', props.totalRows || 0, 'reseller-intent' ), fmt(props.totalRows || 0) ),
			loadMore: props.loadRows ? function(offset) { return props.loadRows('tlds', offset, mapItem); } : null
		});
	}

	function DemandPanel(props) {
		function mapItem(row) {
			return [row.domain, fmt(row.hits)];
		}
		return el(ListPanel, {
			title: __( 'Repeat Demand', 'reseller-intent' ),
			note: __( 'Domains searched 2+ times, buyers circling.', 'reseller-intent' ),
			columns: [__( 'Domain', 'reseller-intent' ), __( 'Searches', 'reseller-intent' )],
			colWidths: ['', '100px'],
			rows: (props.repeats || []).map(mapItem),
			empty: __( 'Quiet so far. When a visitor searches the same name twice, it lands here, a buyer circling.', 'reseller-intent' ),
			initialFetched: 25,
			totalRows: props.totalRows,
			/* translators: %s: number of repeated domains */
			footLabel: sprintf( _n( '%s repeat', '%s repeats', props.totalRows || 0, 'reseller-intent' ), fmt(props.totalRows || 0) ),
			loadMore: props.loadRows ? function(offset) { return props.loadRows('repeats', offset, mapItem); } : null
		});
	}

	function CartedPanel(props) {
		var carted = props.carted || { domains: [] };
		var cartSizes = props.cartSizes || [];
		var cartTotal = cartSizes.reduce(function(sum, b) { return sum + b.count; }, 0);
		function mapItem(row) {
			return [row.domain, fmt(row.count)];
		}

		// The split strip takes about three rows of the body, so the table
		// pages at seven to keep the panel's height on the shared grid.
		var split = cartTotal > 0
			? el('div', { className: 'ri-split' }, cartSizes.map(function(bucket, i) {
				return el('div', { className: 'ri-split-row', key: i },
					el('span', { className: 'ri-split-lbl' }, bucket.label),
					el('span', { className: 'ri-split-bar' },
						el('i', { style: { width: Math.min(100, pct(bucket.count, cartTotal)) + '%' } })
					),
					el('span', { className: 'ri-split-val' }, fmt(bucket.count) + ' (' + fmt(pct(bucket.count, cartTotal), 0) + '%)')
				);
			}))
			: null;

		return el(ListPanel, {
			title: __( 'Carted Domains', 'reseller-intent' ),
			note: __( 'What shoppers sent to cart.', 'reseller-intent' ),
			columns: [__( 'Domain', 'reseller-intent' ), __( 'Sent', 'reseller-intent' )],
			colWidths: ['', '80px'],
			rows: carted.domains.map(mapItem),
			empty: __( 'Cart clicks will land here. Tracking is live, watch the Last event chip up top.', 'reseller-intent' ),
			pageSize: split ? 7 : PAGE_SIZE,
			initialFetched: 15,
			totalRows: props.totalRows,
			/* translators: %s: number of carted domains */
			footLabel: sprintf( _n( '%s domain', '%s domains', props.totalRows || 0, 'reseller-intent' ), fmt(props.totalRows || 0) ),
			loadMore: props.loadRows ? function(offset) { return props.loadRows('carted', offset, mapItem); } : null,
			children: split
		});
	}

	function PagesPanel(props) {
		var items = props.pages || [];
		var rows = items.map(function(row) {
			return [
				el('span', { title: row.path }, row.label || row.path),
				fmt(row.searches),
				fmt(row.carts)
			];
		});
		return el(ListPanel, {
			title: __( 'Search by Page', 'reseller-intent' ),
			note: __( 'Where each search and cart click happened.', 'reseller-intent' ),
			columns: [__( 'Page', 'reseller-intent' ), __( 'Searches', 'reseller-intent' ), __( 'Carts', 'reseller-intent' )],
			colWidths: ['', '90px', '70px'],
			rows: rows,
			empty: __( 'Once searches come in, you will see which page they happen on.', 'reseller-intent' ),
			/* translators: %s: number of pages */
			footLabel: sprintf( _n( '%s page', '%s pages', items.length, 'reseller-intent' ), fmt(items.length) )
		});
	}

	function flagEmoji(code) {
		if (!/^[A-Z]{2}$/.test(code)) {
			return '';
		}
		return String.fromCodePoint(0x1F1E6 + code.charCodeAt(0) - 65, 0x1F1E6 + code.charCodeAt(1) - 65);
	}

	function CountriesPanel(props) {
		var items = (props.countries && props.countries.items) || [];
		function mapItem(item) {
			return [flagEmoji(item.code) + ' ' + item.code, fmt(item.hits || item.count)];
		}
		return el(ListPanel, {
			title: __( 'Top Countries', 'reseller-intent' ),
			note: __( 'Searches by visitor country (edge geo header).', 'reseller-intent' ),
			columns: [__( 'Country', 'reseller-intent' ), __( 'Searches', 'reseller-intent' )],
			colWidths: ['', '110px'],
			rows: items.map(mapItem),
			empty: __( 'No country data yet. Your host/CDN needs to send a geo header (e.g. Cloudflare’s CF-IPCountry).', 'reseller-intent' ),
			initialFetched: 20,
			footLabel: __( 'Searches by country', 'reseller-intent' ),
			loadMore: props.loadRows ? function(offset) { return props.loadRows('countries', offset, mapItem); } : null
		});
	}

	function RecentLog(props) {
		var _f = useState(''), filter = _f[0], setFilter = _f[1];
		var rows = props.recent || [];
		var totalSearches = props.totalSearches || 0;

		var filtered = useMemo(function() {
			var query = filter.trim().toLowerCase();
			if (!query) {
				return rows;
			}
			return rows.filter(function(row) {
				return row.domain.indexOf(query) !== -1;
			});
		}, [rows, filter]);

		var mapped = filtered.map(function(row) {
			var availCell = '-';
			if (row.available === true) {
				availCell = el('span', { className: 'ri-tag is-good' }, __( 'Available', 'reseller-intent' ));
			} else if (row.available === false) {
				availCell = el('span', { className: 'ri-tag is-bad' }, __( 'Registered', 'reseller-intent' ));
			}
			return [row.domain, availCell, row.device || '-', row.time];
		});

		var noteText = sprintf(
			/* translators: 1: number of listed searches, 2: timezone label */
			_n( 'Latest %1$s search in this range (%2$s). Use Export for full data.', 'Latest %1$s searches in this range (%2$s). Use Export for full data.', rows.length, 'reseller-intent' ),
			fmt(rows.length),
			TZ_LABEL
		);

		var footLabel;
		if (totalSearches > rows.length) {
			/* translators: 1: number of listed rows, 2: total number of rows */
			footLabel = sprintf( __( '%1$s of %2$s. Export has everything.', 'reseller-intent' ), fmt(filtered.length), fmt(totalSearches) );
		} else {
			/* translators: %s: number of searches */
			footLabel = sprintf( _n( '%s search', '%s searches', filtered.length, 'reseller-intent' ), fmt(filtered.length) );
		}

		return el(ListPanel, {
			key: filter, // new filter, back to page 1
			className: 'ri-panel--feed',
			title: __( 'Recent Searches', 'reseller-intent' ),
			note: noteText,
			columns: [__( 'Domain', 'reseller-intent' ), __( 'Result', 'reseller-intent' ), __( 'Device', 'reseller-intent' ), __( 'Searched At', 'reseller-intent' )],
			colWidths: ['', '110px', '95px', '155px'],
			rows: mapped,
			empty: filter ? __( 'Nothing matches that filter.', 'reseller-intent' ) : __( 'No searches in this range.', 'reseller-intent' ),
			footLabel: footLabel,
			tools: el('div', { className: 'ri-feed-tools' },
				el('input', {
					type: 'search',
					className: 'ri-log-filter',
					/* translators: %s: number of listed searches */
					placeholder: sprintf( __( 'Filter these %s...', 'reseller-intent' ), fmt(rows.length) ),
					'aria-label': __( 'Filter recent searches', 'reseller-intent' ),
					value: filter,
					onChange: function(event) { setFilter(event.target.value); }
				})
			)
		});
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
						: (count === 0 ? __( 'No events in this window.', 'reseller-intent' ) : sprintf( /* translators: %s: number of events */ _n( '%s event will be permanently deleted.', '%s events will be permanently deleted.', count, 'reseller-intent' ), fmt(count) ))
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

	function BrandMark() {
		return el('span', { className: 'ri-mark', 'aria-hidden': 'true' },
			el('svg', { viewBox: '0 0 20 20', width: 18, height: 18 },
				el('path', { fill: '#fff', fillRule: 'evenodd', d: 'M10 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm0 1.7a6.3 6.3 0 1 0 0 12.6 6.3 6.3 0 0 0 0-12.6Z' }),
				el('path', { fill: '#fff', d: 'M10 10 11.08 3.89a6.2 6.2 0 0 1 4.54 3.49Z' }),
				el('circle', { fill: '#fff', cx: 6.4, cy: 12.2, r: 1.5 })
			)
		);
	}

	function App() {
		var _r = useState('90'), range = _r[0], setRange = _r[1];
		var _d = useState(null), data = _d[0], setData = _d[1];
		var _l = useState(true), loading = _l[0], setLoading = _l[1];
		var _e = useState(''), error = _e[0], setError = _e[1];
		var _m = useState(false), showClear = _m[0], setShowClear = _m[1];
		var _h = useState(loadHiddenPanels), hiddenPanels = _h[0], setHiddenPanels = _h[1];
		var _pp = useState(false), showPanelsMenu = _pp[0], setShowPanelsMenu = _pp[1];

		function isShown(key) {
			if (Object.prototype.hasOwnProperty.call(hiddenPanels, key)) {
				return !hiddenPanels[key];
			}
			return !DEFAULT_HIDDEN[key];
		}

		function togglePanel(key) {
			var next = {};
			Object.keys(hiddenPanels).forEach(function(k) { next[k] = hiddenPanels[k]; });
			next[key] = isShown(key); // shown -> hidden, hidden -> shown (explicit, survives defaults)
			setHiddenPanels(next);
			saveHiddenPanels(next);
		}

		var _pf = useState(''), pageFilter = _pf[0], setPageFilter = _pf[1];
		var _n2 = useState(resellerIntentAdmin.notice || ''), notice = _n2[0], setNotice = _n2[1];
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
			if (pageFilter) {
				body.append('page_path', pageFilter);
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
		}, [range, customApplied, pageFilter]);

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
			var count = Number(match[1]) || 0;
			return count === 0 ? __( 'No events matched that window.', 'reseller-intent' ) : sprintf( /* translators: %s: number of events */ _n( '%s event deleted.', '%s events deleted.', count, 'reseller-intent' ), fmt(count) );
		}

		var rangeQuery = '&range=' + encodeURIComponent(range)
			+ (range === 'custom' ? '&from=' + encodeURIComponent(customApplied.from) + '&to=' + encodeURIComponent(customApplied.to) : '');
		var exportHref = resellerIntentAdmin.exportUrl + rangeQuery;
		var exportJsonHref = resellerIntentAdmin.exportUrl + rangeQuery + '&format=json';

		var grid = null;
		if (data) {
			var rangeKey = range + ('custom' === range ? '|' + customApplied.from + '|' + customApplied.to : '') + '|' + pageFilter;
			var loadRows = function(panel, offset, mapRow) {
				return fetchPanelRows(panel, range, customApplied, pageFilter, offset, mapRow);
			};
			var totals = data.totals || {};
			var cells = [];

			if (isShown('trend')) {
				cells.push(el('div', { key: 'trend', className: 'ri-s8' },
					el(TrendPanel, { trend: data.trend, bounded: data.bounded, rangeLabel: data.rangeLabel, availability: data.availability, devices: data.devices })));
			}
			if (isShown('tlds')) {
				cells.push(el('div', { key: 'tlds', className: 'ri-s4' },
					el(TldPanel, { items: data.tlds.items, totalRows: totals.tlds, loadRows: loadRows })));
			}
			if (isShown('repeats')) {
				cells.push(el('div', { key: 'repeats', className: 'ri-s4' },
					el(DemandPanel, { repeats: data.repeats, totalRows: totals.repeats, loadRows: loadRows })));
			}
			if (isShown('carted')) {
				cells.push(el('div', { key: 'carted', className: 'ri-s4' },
					el(CartedPanel, { carted: data.carted, cartSizes: data.cartSizes, totalRows: totals.carted, loadRows: loadRows })));
			}
			if (isShown('pages')) {
				cells.push(el('div', { key: 'pages', className: 'ri-s4' },
					el(PagesPanel, { pages: data.pages })));
			}
			if (isShown('countries') && data.countries.items.length) {
				cells.push(el('div', { key: 'countries', className: 'ri-s4' },
					el(CountriesPanel, { countries: data.countries, loadRows: loadRows })));
			}
			if (isShown('recent')) {
				cells.push(el('div', { key: 'recent', className: 'ri-s12' },
					el(RecentLog, { recent: data.recent, totalSearches: (data.kpis.now || {}).searches })));
			}

			grid = el('div', { className: 'ri-grid12', key: rangeKey }, cells);
		}

		return el('div', { className: 'ri-app' + (loading ? ' is-loading' : ''), 'aria-busy': loading ? 'true' : 'false' },
			loading ? el('div', { className: 'ri-progress', role: 'status', 'aria-label': __( 'Loading', 'reseller-intent' ) }) : null,
			el('div', { className: 'ri-header' },
				el('div', { className: 'ri-brand' },
					el(BrandMark),
					el('h1', null, __( 'Reseller Intent', 'reseller-intent' )),
					el('span', { className: 'ri-version' }, 'v' + resellerIntentAdmin.version),
					data && data.lastEvent ? el('span', {
						className: 'ri-live' + (data.lastEvent.stale ? ' is-stale' : ''),
						title: data.lastEvent.stale ? __( 'No recent events. Check that the search widget is live and tracking is not blocked.', 'reseller-intent' ) : null
					}, data.lastEvent.ago) : null
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
					data && (data.pages || []).length > 1 ? el('select', {
						className: 'ri-page-filter' + (pageFilter ? ' is-active' : ''),
						value: pageFilter,
						'aria-label': __( 'Filter by page', 'reseller-intent' ),
						title: __( 'Every panel follows this page filter. Search by Page keeps comparing all pages.', 'reseller-intent' ),
						onChange: function(event) { setPageFilter(event.target.value); }
					}, [el('option', { key: '', value: '' }, __( 'All pages', 'reseller-intent' ))].concat(
						(data.pages || []).map(function(row) {
							return el('option', { key: row.path, value: row.path }, row.label || row.path);
						})
					)) : null,
					el('span', { className: 'ri-export-group' },
						el('a', { className: 'ri-btn', href: exportHref, title: __( 'Export CSV', 'reseller-intent' ) }, __( 'CSV', 'reseller-intent' )),
						el('a', { className: 'ri-btn', href: exportJsonHref, title: __( 'Export JSON', 'reseller-intent' ) }, __( 'JSON', 'reseller-intent' ))
					),
					el('button', { className: 'ri-btn ri-danger-ghost', onClick: function() { setShowClear(true); } }, __( 'Clear data', 'reseller-intent' )),
					el('span', { className: 'ri-panels-menu' },
						el('button', {
							className: 'ri-btn ri-btn--primary ri-panels-toggle',
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
					className: 'ri-btn',
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
				? el(wp.element.Fragment, null,
					el(KpiGrid, { now: data.kpis.now, prev: data.kpis.prev }),
					grid
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
