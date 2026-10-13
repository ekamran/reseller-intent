/**
 * Continue to cart tracking against the REAL Reseller Store domain search
 * widget, both builds: 3.0.1 (master, a <form> with a hidden items input)
 * and 3.0.2 (PR 214, a <button> that POSTs each domain with fetch). The
 * widget runs on React 18 from node_modules, the fixture page is served
 * from https://fixture.test/ through request interception, GoDaddy's API
 * is answered with their own Cypress fixture. No WordPress, no network.
 *
 *   node continue-click-check.js
 */
// The bundled Chrome from the puppeteer package, or CHROME=/path/to/chrome with puppeteer-core.
let puppeteer;
try { puppeteer = require('puppeteer'); } catch (e) { puppeteer = require('puppeteer-core'); }
const path = require('path');
const fs = require('fs');

const CHROME = process.env.CHROME || undefined;
const TRACKER_JS = process.env.TRACKER_JS || path.join(__dirname, '../../assets/js/tracker.js');
const JQUERY_JS = path.join(__dirname, 'vendor/jquery-3.7.1.min.js');
// React 18 keeps the UMD builds out of its package exports, so resolve the package and walk in.
const REACT_JS = path.join(path.dirname(require.resolve('react/package.json')), 'umd/react.production.min.js');
const REACT_DOM_JS = path.join(path.dirname(require.resolve('react-dom/package.json')), 'umd/react-dom.production.min.js');
const BUILDS = {
	'3.0.1': path.join(__dirname, 'vendor/reseller-store-3.0.1-domain-search.min.js'),
	'3.0.2': path.join(__dirname, 'vendor/reseller-store-3.0.2-pr214-domain-search.min.js'),
};
const FIXTURE = {
	exactMatchDomain: { domain: 'cypresstest.com', available: true, listPrice: '$19.99', salePrice: '$9.99', productId: 101 },
	suggestedDomains: [
		{ domain: 'cypresstest.net', available: true, listPrice: '$14.99', salePrice: '$8.99', productId: 102 },
		{ domain: 'cypresstest.org', available: true, listPrice: '$12.99', salePrice: '$7.99', productId: 103 },
	],
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, pass, detail) => results.push({ name, pass: !!pass, detail });

const pageHtml = (intent, order) => `<!doctype html><html><head><meta charset="utf-8"><title>fixture</title>
<script src="https://fixture.test/js/jquery.js"></script>
</head><body>
<div class="rstore-domain-search" data-plid="1" data-base_url="secureserver.test" data-page_size="5"></div>
<script>
  window.resellerIntent = ${JSON.stringify(intent)};
  window.__beacons = [];
  window.__opened = [];
  navigator.sendBeacon = function (url, data) {
    var o = { __url: url };
    if (data && data.forEach) { data.forEach(function (v, k) { o[k] = String(v); }); }
    window.__beacons.push(o);
    return true;
  };
  window.open = function (u) { window.__opened.push(String(u)); return null; };
</script>
<script src="https://fixture.test/js/react.js"></script>
<script src="https://fixture.test/js/react-dom.js"></script>
${order.map((what) => `<script src="https://fixture.test/js/${what}.js"></script>`).join("\n")}
</body></html>`;

// Served as real footer script tags, in the given order, so the page goes
// through a genuine DOMContentLoaded: the widget mounts on that event and
// jQuery 3 runs ready handlers after it, exactly as on a WordPress page.
const FILES = {
	"/js/jquery.js": JQUERY_JS,
	"/js/react.js": REACT_JS,
	"/js/react-dom.js": REACT_DOM_JS,
	"/js/tracker.js": TRACKER_JS,
};

const CORS = { 'Access-Control-Allow-Origin': 'https://fixture.test', 'Access-Control-Allow-Credentials': 'true' };

/**
 * One fixture page: scripts injected in the given order, the search run,
 * results on screen. Returns the page plus the Node-side event log.
 */
async function open(browser, build, order, intent) {
	const page = await browser.newPage();
	const ajax = [];
	const cartPosts = [];
	page.on('popup', async (popup) => {
		try { await popup.close(); } catch (e) {}
		try { await page.bringToFront(); } catch (e) {}
	});
	await page.setRequestInterception(true);
	page.on('request', (req) => {
		const url = req.url();
		if (url === 'https://fixture.test/' || url === 'https://fixture.test/index.html') {
			return req.respond({ status: 200, contentType: 'text/html', body: pageHtml(intent, order) });
		}
		if (url.startsWith('https://fixture.test/js/')) {
			const name = url.slice('https://fixture.test'.length);
			const file = '/js/widget.js' === name ? BUILDS[build] : FILES[name];
			return req.respond({ status: 200, contentType: 'application/javascript', body: fs.readFileSync(file, 'utf8') });
		}
		if (url.startsWith('https://fixture.test/ajax')) {
			const o = {};
			new URLSearchParams(req.postData() || '').forEach((v, k) => { o[k] = v; });
			ajax.push(o);
			return req.respond({ status: 200, contentType: 'application/json', body: '{"success":true,"data":{"id":1}}' });
		}
		if (url.startsWith('https://www.secureserver.test/api/v1/domains/')) {
			return req.respond({ status: 200, contentType: 'application/json', headers: CORS, body: JSON.stringify(FIXTURE) });
		}
		if (url.startsWith('https://www.secureserver.test/api/v1/cart/')) {
			if (req.isNavigationRequest()) {
				// 3.0.1 form POST: a 204 answers it and leaves the fixture in place.
				cartPosts.push({ navigation: true, body: req.postData() || '' });
				return req.respond({ status: 204 });
			}
			cartPosts.push({ navigation: false, body: req.postData() || '' });
			return req.respond({ status: 200, contentType: 'application/json', headers: CORS, body: JSON.stringify({ cartCount: cartPosts.length, nextStepUrl: 'https://cart.test/next' }) });
		}
		if (url.startsWith('https://cart.test/')) {
			return req.respond({ status: 204 });
		}
		return req.respond({ status: 404, body: '' });
	});
	await page.goto('https://fixture.test/', { waitUntil: 'load' });
	await page.waitForSelector('.rstore-domain-search-input', { timeout: 5000 });
	await page.type('.rstore-domain-search-input', 'cypresstest.com');
	await page.click('.rstore-domain-search-button');
	await page.waitForSelector('.rstore-domain-continue-button', { timeout: 5000 });
	await sleep(300);
	return { page, ajax, cartPosts };
}

async function events(page, ajax) {
	const beacons = await page.evaluate(() => window.__beacons);
	return ajax.concat(beacons);
}

const byType = (list, type) => list.filter((e) => e.event_type === type);
const domainsOf = (e) => {
	try {
		const parsed = JSON.parse(e.items_json || '[]');
		return parsed.map((i) => (i && i.domain) || String(i)).sort();
	} catch (err) {
		return ['unparseable:' + e.items_json];
	}
};

(async () => {
	const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', protocolTimeout: 20000, args: ['--no-sandbox'] });
	const INTENT = { ajaxUrl: 'https://fixture.test/ajax', newTab: false };

	for (const build of Object.keys(BUILDS)) {
		for (const order of [['tracker', 'widget'], ['widget', 'tracker']]) {
			for (const selectTwo of [false, true]) {
				const tag = `${build} ${order.join('>')} ${selectTwo ? 'two selected' : 'exact only'}:`;
				const { page, ajax, cartPosts } = await open(browser, build, order, INTENT);
				let all = await events(page, ajax);
				check(`${tag} one domain_search for the typed name`, byType(all, 'domain_search').length === 1 && byType(all, 'domain_search')[0].domain_query === 'cypresstest.com', JSON.stringify(byType(all, 'domain_search')));
				check(`${tag} one search_result, available`, byType(all, 'search_result').length === 1 && '1' === String(byType(all, 'search_result')[0].is_available), JSON.stringify(byType(all, 'search_result')));
				if (selectTwo) {
					// Same click twice: the first Select turns into Selected and leaves the .select list.
					await page.click('.rstore-domain-list .rstore-domain-buy-button.select');
					await sleep(150);
					await page.click('.rstore-domain-list .rstore-domain-buy-button.select');
					await sleep(150);
				}
				await page.click('.rstore-domain-continue-button');
				await sleep(1200);
				all = await events(page, ajax);
				const cont = byType(all, 'continue_to_cart');
				const expected = selectTwo ? ['cypresstest.net', 'cypresstest.org'] : ['cypresstest.com'];
				check(`${tag} exactly one continue_to_cart`, cont.length === 1, 'count=' + cont.length + ' ' + JSON.stringify(cont));
				check(`${tag} items_count ${expected.length}`, cont.length === 1 && String(cont[0].items_count) === String(expected.length), JSON.stringify(cont[0] || {}));
				check(`${tag} items_json carries ${expected.join(', ')}`, cont.length === 1 && JSON.stringify(domainsOf(cont[0])) === JSON.stringify(expected), JSON.stringify(cont[0] || {}));
				check(`${tag} domain_select count ${selectTwo ? 2 : 0}`, byType(all, 'domain_select').length === (selectTwo ? 2 : 0), JSON.stringify(byType(all, 'domain_select')));
				if ('3.0.2' === build) {
					check(`${tag} widget sent ${expected.length} sequential cart POST(s)`, cartPosts.length === expected.length && cartPosts.every((p) => !p.navigation), JSON.stringify(cartPosts));
				} else {
					check(`${tag} widget submitted its form once`, cartPosts.length === 1 && cartPosts[0].navigation, JSON.stringify(cartPosts));
				}
				await page.close();
			}
		}

		// Our "open store links in a new tab" setting, in both script orders. The
		// flag must be on the root before the widget mounts on DOMContentLoaded,
		// and jQuery 3 ready handlers run after that, so it is set at load time.
		for (const order of [['widget', 'tracker'], ['tracker', 'widget']]) {
		const tag = `${build} new tab ${order.join('>')}:`;
		const { page, ajax, cartPosts } = await open(browser, build, order, { ajaxUrl: 'https://fixture.test/ajax', newTab: true });
		const before = await page.evaluate(() => ({
			root: document.querySelector('.rstore-domain-search').getAttribute('data-new_tab'),
			formTarget: (document.querySelector('.rstore-domain-search form.continue-form') || {}).getAttribute ? document.querySelector('.rstore-domain-search form.continue-form').getAttribute('target') : null,
		}));
		if ('3.0.1' === build) {
			check(`${tag} form target is _blank`, before.formTarget === '_blank', JSON.stringify(before));
		} else {
			check(`${tag} widget root carries data-new_tab=true before mount`, before.root === 'true', JSON.stringify(before));
		}
		await page.click('.rstore-domain-continue-button');
		await sleep(1200);
		const all = await events(page, ajax);
		const opened = await page.evaluate(() => window.__opened);
		check(`${tag} exactly one continue_to_cart`, byType(all, 'continue_to_cart').length === 1, JSON.stringify(byType(all, 'continue_to_cart')));
		if ('3.0.2' === build) {
			check(`${tag} widget opened the cart with window.open`, opened.length === 1 && opened[0] === 'https://cart.test/next', JSON.stringify({ opened, cartPosts }));
		} else {
			check(`${tag} widget mounted with its own new tab flag (no spinner rendered)`, !(await page.evaluate(() => !!document.querySelector('.rstore-domain-search .rstore-loading'))), 'spinner present');
		}
		await page.close();
		}
	}

	await browser.close();

	let fail = 0;
	for (const r of results) {
		console.log((r.pass ? ' ok   ' : 'FAIL  ') + r.name + (r.pass ? '' : '\n        ' + r.detail));
		if (!r.pass) fail++;
	}
	console.log(`\n${results.length - fail} passed, ${fail} failed`);
	process.exit(fail ? 1 : 0);
})();
