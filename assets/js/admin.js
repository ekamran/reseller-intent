/* global resellerIntentAdmin, wp */
(function() {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var Fragment = wp.element.Fragment;

	var ACCENT = (window.resellerIntentAdmin && resellerIntentAdmin.accentColor) || '#3858e9';
	var INK = '#1d2327';
	var TZ_LABEL = (window.resellerIntentAdmin && resellerIntentAdmin.tzLabel) || '';

	var RANGES = [
		{ key: '7', label: '7d' },
		{ key: '30', label: '30d' },
		{ key: '90', label: '90d' },
		{ key: 'all', label: 'Lifetime' }
	];

	var CLEAR_RANGES = [
		{ key: 'hour', label: 'Last hour' },
		{ key: 'day', label: 'Last 24 hours' },
		{ key: 'week', label: 'Last 7 days' },
		{ key: 'month', label: 'Last 30 days' },
		{ key: 'half_year', label: 'Last 6 months' },
		{ key: 'year', label: 'Last year' },
		{ key: 'all', label: 'All time' }
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
			text = 'All time';
		} else if (previous <= 0 && current <= 0) {
			return null; // no data either side — a badge is just noise
		} else if (previous <= 0) {
			cls = 'ri-delta is-up';
			text = 'New';
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

		return el('span', { className: cls, title: previous === null ? '' : 'vs previous period' }, text);
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
		return el('div', { className: 'ri-table-shell' },
			el('table', { className: 'ri-table' },
				el('thead', null,
					el('tr', null, props.columns.map(function(col, i) {
						return el('th', { key: i }, col);
					}))
				),
				el('tbody', null,
					props.rows.length
						? props.rows.map(function(cells, r) {
							return el('tr', { key: r }, cells.map(function(cell, c) {
								return el('td', { key: c }, cell);
							}));
						})
						: el('tr', null, el('td', { className: 'ri-empty', colSpan: props.columns.length }, props.empty))
				)
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
			el('text', { key: 'ymax', x: padX - 6, y: padY + 4, fontSize: 9, textAnchor: 'end', fill: '#94a3b8' }, fmt(maxVal)),
			el('text', { key: 'y0', x: padX - 6, y: padY + plotH + 3, fontSize: 9, textAnchor: 'end', fill: '#94a3b8' }, '0')
		];

		// sparse data renders as a near-invisible sliver — mark the active days
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
			return el('p', { className: 'ri-empty' }, 'No activity yet.');
		}

		return el('svg', { className: 'ri-trend', viewBox: '0 0 ' + W + ' ' + H, role: 'img' },
			gridLines,
			el('line', { x1: padX, y1: padY + plotH, x2: padX + plotW, y2: padY + plotH, stroke: '#e2e8f0' }),
			el('polygon', { points: areaPoints(searches), fill: 'rgba(85,62,232,0.08)' }),
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
			{ label: 'Domain Searches', value: fmt(now.searches), current: now.searches, previous: prev ? prev.searches : null },
			{ label: 'Unique Searches', value: fmt(now.uniqueSearches), current: now.uniqueSearches, previous: prev ? prev.uniqueSearches : null },
			{ label: 'Cart Clicks', value: fmt(now.cartClicks), current: now.cartClicks, previous: prev ? prev.cartClicks : null },
			{ label: 'Domains Added', value: fmt(now.domainsAdded), current: now.domainsAdded, previous: prev ? prev.domainsAdded : null },
			{ label: 'Avg Domains / Cart', value: fmt(avgCart, 2), current: avgCart, previous: prevAvgCart },
			{ label: 'Search → Cart Rate', value: fmt(conversion, 1) + '%', current: conversion, previous: prevConversion }
		];

		return el('div', { className: 'ri-kpis' }, cards.map(function(card, i) {
			return el('div', { className: 'ri-kpi', key: i },
				el('p', { className: 'ri-kpi-label' }, card.label),
				el('p', { className: 'ri-kpi-value' }, card.value),
				el(DeltaBadge, { current: card.current, previous: card.previous })
			);
		}));
	}

	function TldPanel(props) {
		var items = props.items || [];
		var total = props.total || 0;
		var rows = items.map(function(item) {
			return [
				item.label,
				el(Fragment, null,
					el('span', { className: 'ri-inline-track' },
						el('span', { className: 'ri-inline-fill', style: { width: pct(item.count, total) + '%' } })
					),
					fmt(item.count) + ' (' + fmt(pct(item.count, total), 1) + '%)'
				)
			];
		});
		return el(Panel, { title: 'Searched TLDs', note: 'Which extensions people look for.' },
			el(MiniTable, { columns: ['TLD', 'Searches'], rows: rows, empty: 'No searches in this range.' })
		);
	}

	function DemandPanel(props) {
		var repeats = (props.repeats || []).map(function(row) {
			return [row.domain, fmt(row.hits)];
		});
		return el(Panel, { title: 'Repeat Demand', note: 'Domains searched 2+ times — buyers circling.' },
			el(MiniTable, { columns: ['Domain', 'Searches'], rows: repeats, empty: 'No repeated searches in this range.' })
		);
	}

	function CartedPanel(props) {
		var carted = props.carted || { domains: [], tlds: [] };
		var rows = carted.domains.slice(0, 8).map(function(row) {
			return [row.domain, fmt(row.count)];
		});
		return el(Panel, { title: 'Carted Domains', note: 'What shoppers actually sent to cart.' },
			carted.tlds.length
				? el('div', { className: 'ri-chips' }, carted.tlds.map(function(tld, i) {
					return el(StatChip, { key: i, value: fmt(tld.count), label: tld.label });
				}))
				: null,
			el(MiniTable, { columns: ['Domain', 'Added'], rows: rows, empty: 'No carted domains in this range.' })
		);
	}

	function SelectionPanel(props) {
		var selection = props.selection || { total: 0, exact: 0, top: [], pairs: [] };
		var exactRate = pct(selection.exact, selection.total);
		var topRows = selection.top.map(function(row) {
			return [row.domain, fmt(row.hits)];
		});
		var pairRows = selection.pairs.map(function(row) {
			return [row.searched, row.selected, fmt(row.hits)];
		});

		return el(Panel, { title: 'Selection Behavior', note: 'What gets picked, and what taken searches settle for.' },
			selection.total > 0
				? el('div', { className: 'ri-chips' },
					el(StatChip, { value: fmt(selection.total), label: 'Select clicks' }),
					el(StatChip, { value: fmt(exactRate, 1) + '%', label: 'Kept searched name' })
				)
				: null,
			el(MiniTable, { columns: ['Domain', 'Selects'], rows: topRows, empty: 'No selection data in this range yet.' }),
			pairRows.length
				? el(Fragment, null,
					el('p', { className: 'ri-subhead' }, 'Searched → settled for'),
					el(MiniTable, { columns: ['Searched', 'Selected instead', 'Times'], rows: pairRows, empty: '' })
				)
				: null
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
		return el(Panel, { title: 'Search by Page', note: 'Which page each search and cart click came from.' },
			el(MiniTable, { columns: ['Page', 'Searches', 'Cart clicks'], rows: rows, empty: 'No page data in this range.' })
		);
	}

	function QualityPanel(props) {
		var availability = props.availability || { available: 0, taken: 0 };
		var devices = props.devices || { mobile: 0, desktop: 0 };
		var availTotal = availability.available + availability.taken;
		var availRate = pct(availability.available, availTotal);
		var deviceTotal = devices.mobile + devices.desktop;

		return el(Panel, { title: 'Availability & Devices', note: 'How often the searched name is free, and who is searching.' },
			availTotal > 0
				? el('div', { className: 'ri-chips' },
					el(StatChip, { value: fmt(availRate, 1) + '%', label: 'Available' }),
					el(StatChip, { value: fmt(availability.available), label: 'Free' }),
					el(StatChip, { value: fmt(availability.taken), label: 'Taken' })
				)
				: el('p', { className: 'ri-empty' }, 'No availability data in this range yet.'),
			el('p', { className: 'ri-subhead' }, 'Searches by device'),
			deviceTotal > 0
				? el('div', { className: 'ri-bars' },
					el(BarRow, { label: 'Desktop', width: pct(devices.desktop, deviceTotal), value: fmt(devices.desktop) + ' (' + fmt(pct(devices.desktop, deviceTotal), 1) + '%)' }),
					el(BarRow, { label: 'Mobile', width: pct(devices.mobile, deviceTotal), value: fmt(devices.mobile) + ' (' + fmt(pct(devices.mobile, deviceTotal), 1) + '%)' })
				)
				: el('p', { className: 'ri-empty' }, 'No device data in this range yet.')
		);
	}

	function FunnelPanel(props) {
		var now = props.now || {};
		var cartSizes = props.cartSizes || [];
		var maxStage = Math.max(1, now.searches || 0);
		var stages = [
			{ label: 'Searches', value: now.searches || 0, color: '#f0f0f1' },
			{ label: 'Cart Clicks', value: now.cartClicks || 0, color: '#dcdcde' },
			{ label: 'Domains Added', value: now.domainsAdded || 0, color: '#DCE3F2' }
		];
		var cartTotal = cartSizes.reduce(function(sum, b) { return sum + b.count; }, 0);

		return el(Panel, { title: 'Conversion Funnel', note: 'Searches → Cart Clicks → Domains Added.' },
			el('div', { className: 'ri-funnel' }, stages.map(function(stage, i) {
				var carry = i > 0 && stages[i - 1].value > 0
					? fmt(pct(stage.value, stages[i - 1].value), 1) + '%'
					: null;
				return el('div', { className: 'ri-funnel-bar', key: i },
					el('span', { className: 'ri-funnel-fill', style: { width: pct(stage.value, maxStage) + '%', background: stage.color } }),
					el('span', { className: 'ri-funnel-text' },
						el('span', null, stage.label),
						el('span', null,
							fmt(stage.value),
							carry ? el('span', { className: 'ri-funnel-rate' }, carry + ' of prev') : null
						)
					)
				);
			})),
			cartTotal > 0
				? el(Fragment, null,
					el('p', { className: 'ri-subhead' }, 'Cart size split'),
					el('div', { className: 'ri-bars' }, cartSizes.map(function(bucket, i) {
						return el(BarRow, {
							key: i,
							label: bucket.label,
							width: pct(bucket.count, cartTotal),
							value: fmt(bucket.count) + ' (' + fmt(pct(bucket.count, cartTotal), 1) + '%)'
						});
					}))
				)
				: null
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
			var availCell = '—';
			if (row.available === true) {
				availCell = el('span', { className: 'ri-tag is-good' }, 'free');
			} else if (row.available === false) {
				availCell = el('span', { className: 'ri-tag is-bad' }, 'taken');
			}
			return [row.domain, availCell, row.device || '—', row.time];
		});

		var noteText = rows.length === 1
			? 'Latest search in this range (' + TZ_LABEL + ').'
			: 'Latest ' + fmt(rows.length) + ' searches in this range (' + TZ_LABEL + ').';

		return el(Panel, { title: 'Recent Searches', note: noteText, className: 'ri-panel--wide' },
			el('div', { className: 'ri-log-tools' },
				el('input', {
					type: 'search',
					className: 'ri-log-filter',
					placeholder: 'Filter domains…',
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
				columns: ['Domain', 'Result', 'Device', 'Searched At'],
				rows: pageRows,
				empty: filter ? 'Nothing matches that filter.' : 'No searches in this range.'
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

		return el(Panel, { title: 'Top Countries', note: items.length ? 'Searches by visitor country (edge geo header).' : null },
			items.length
				? items.map(function(item) {
					return el(BarRow, {
						key: item.code,
						label: flagEmoji(item.code) + ' ' + item.code,
						width: pct(item.hits, total),
						value: fmt(item.hits)
					});
				})
				: el('p', { className: 'ri-empty' }, 'No country data yet. Your host/CDN needs to send a geo header (e.g. Cloudflare’s CF-IPCountry).')
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

		return el('div', { className: 'ri-modal-backdrop', onClick: props.onCancel },
			el('div', {
				className: 'ri-modal',
				role: 'dialog',
				'aria-modal': 'true',
				'aria-label': 'Clear data',
				onClick: function(event) { event.stopPropagation(); }
			},
				el('h2', null, 'Clear data'),
				el('p', null, 'Delete tracked events from the selected time window. There is no undo.'),
				el('label', { className: 'ri-clear-label', htmlFor: 'ri-clear-range' }, 'Time window'),
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
						? 'Counting…'
						: (count === 0 ? 'No events in this window.' : fmt(count) + ' event' + (count === 1 ? '' : 's') + ' will be permanently deleted.')
				),
				el('div', { className: 'ri-modal-actions' },
					el('button', { className: 'button', onClick: props.onCancel }, 'Cancel'),
					el('button', {
						className: 'button ri-danger',
						disabled: count === null || count === 0,
						onClick: function() { props.onConfirm(sel); }
					}, 'Clear data')
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
		var _n = useState(resellerIntentAdmin.notice || ''), notice = _n[0], setNotice = _n[1];

		useEffect(function() {
			var cancelled = false;
			setLoading(true);
			setError('');

			var body = new window.FormData();
			body.append('action', 'rintent_dashboard_data');
			body.append('nonce', resellerIntentAdmin.nonce);
			body.append('range', range);

			window.fetch(resellerIntentAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function(response) { return response.json(); })
				.then(function(json) {
					if (cancelled) {
						return;
					}
					if (json && json.success && json.data) {
						setData(json.data);
					} else {
						setError('Could not load dashboard data.');
					}
				})
				.catch(function() {
					if (!cancelled) {
						setError('Could not load dashboard data.');
					}
				})
				.then(function() {
					if (!cancelled) {
						setLoading(false);
					}
				});

			return function() { cancelled = true; };
		}, [range]);

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
			return n === 0 ? 'No events matched that window.' : fmt(n) + ' event' + (n === 1 ? '' : 's') + ' deleted.';
		}

		var exportHref = resellerIntentAdmin.exportUrl + '&range=' + encodeURIComponent(range);

		return el('div', { className: 'ri-app' + (loading ? ' is-loading' : ''), style: { '--ri-accent': ACCENT } },
			el('header', { className: 'ri-header' },
				el('div', null,
					el('h1', null, 'Reseller Intent'),
					el('p', { className: 'ri-note' },
						'Domain search analytics · v' + resellerIntentAdmin.version +
						(data ? ' · ' + data.rangeLabel : '')
					)
				),
				el('div', { className: 'ri-header-actions' },
					el('span', { className: 'ri-ranges' }, RANGES.map(function(option) {
						return el('button', {
							key: option.key,
							className: 'ri-range' + (range === option.key ? ' is-active' : ''),
							onClick: function() { setRange(option.key); }
						}, option.label);
					})),
					el('a', { className: 'button ri-export', href: exportHref }, 'Export CSV'),
					el('button', { className: 'button ri-danger-ghost', onClick: function() { setShowClear(true); } }, 'Clear data')
				)
			),

			clearNoticeText(notice) ? el('div', { className: 'notice notice-success is-dismissible ri-notice', onClick: function() { setNotice(''); } }, el('p', null, clearNoticeText(notice))) : null,
			notice === 'clear_error' ? el('div', { className: 'notice notice-error ri-notice' }, el('p', null, 'Clearing data failed.')) : null,
			notice === 'settings_saved' ? el('div', { className: 'notice notice-success is-dismissible ri-notice', onClick: function() { setNotice(''); } }, el('p', null, 'Settings saved.')) : null,
			/^imported_\d+$/.test(notice) ? el('div', { className: 'notice notice-success is-dismissible ri-notice', onClick: function() { setNotice(''); } }, el('p', null, fmt(Number(notice.replace('imported_', '')) || 0) + ' legacy events imported.')) : null,
			error ? el('div', { className: 'notice notice-error ri-notice' }, el('p', null, error)) : null,

			data
				? el(Fragment, null,
					el(KpiGrid, { now: data.kpis.now, prev: data.kpis.prev }),
					el('div', { className: 'ri-grid ri-grid--2' },
						el(Panel, { title: 'Search vs Cart Trend', note: data.bounded ? 'Daily activity in this range.' : 'Monthly activity, all time.' },
							el(TrendChart, data.trend),
							el('div', { className: 'ri-legend' },
								el('span', null, el('i', { className: 'ri-dot', style: { background: ACCENT } }), 'Searches'),
								el('span', null, el('i', { className: 'ri-dot', style: { background: INK } }), 'Cart clicks')
							)
						),
						el(FunnelPanel, { now: data.kpis.now, cartSizes: data.cartSizes })
					),
					el('div', { className: 'ri-grid ri-grid--3' },
						el(TldPanel, { items: data.tlds.items, total: data.tlds.total }),
						el(CartedPanel, { carted: data.carted }),
						el(DemandPanel, { repeats: data.repeats })
					),
					el('div', { className: 'ri-grid ri-grid--2' },
						el(SelectionPanel, { selection: data.selection }),
						el('div', { className: 'ri-stack' },
							el(QualityPanel, { availability: data.availability, devices: data.devices }),
							el(PagesPanel, { pages: data.pages }),
							el(CountriesPanel, { countries: data.countries })
						)
					),
					el(RecentLog, { recent: data.recent })
				)
				: (loading ? el('div', { className: 'ri-loading' }, 'Loading…') : null),

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
