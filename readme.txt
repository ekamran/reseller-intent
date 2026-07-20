=== Reseller Intent ===
Contributors: kamranaziz
Tags: godaddy, reseller store, domain search, analytics, domains
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Domain search analytics for GoDaddy Reseller Store. See what visitors search, what they select and what they take to cart.

== Description ==

Your Reseller Store domain search is where buying decisions happen, but by default you cannot see any of it. Reseller Intent records what visitors search, whether their domain was available, what they select instead and what they take to cart. Then it turns all of this into a clean dashboard.

**Dashboard**

* KPI cards with previous-period comparison
* Search vs cart trend chart (7 / 30 / 90 days / lifetime / custom date range)
* Intent funnel: searches → selections → cart clicks, with per-stage rates
* TLD demand distribution
* "Kept searched name" vs settled-for-alternative selection insights
* Availability quality and device split
* Per-page breakdown (which search box converts)
* Recent searches log with filter
* CSV and JSON export for any range
* Weekly email digest (searches, conversion, top domains & TLDs) with a test-send button

**Data controls**

* Browser-style "Clear data", delete the last hour, 24 hours, 7 days, 30 days, 6 months, year, or everything, with a preview count before you confirm
* Optional automatic retention (30 days – 2 years, off by default)
* Optional full cleanup on uninstall

**Privacy by design**

* No cookies, no fingerprinting, no IP addresses stored, no user accounts recorded, events are anonymous interaction counts
* Known bots and crawlers are ignored by default
* Country stats without IPs, CDN geo headers when available (Cloudflare, host GeoIP), otherwise the visitor's browser timezone mapped to a country. Works on plain shared hosting with no CDN.
* Ignore-list for your own test searches (wildcards supported)
* `rintent_should_track` filter lets consent plugins pause tracking until consent is given

**For developers**

* `rintent_should_track` / `rintent_event_data` filters
* Indexed custom table, zero queries on requests that don't involve tracking
* Frontend script loads only on pages where the Reseller Store widget is present

Requires the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs.

== Frequently Asked Questions ==

= Does this plugin make any external API calls? =

Tracking and the dashboard talk only to your own WordPress site. The optional TLD price strip shortcode fetches prices server-side from GoDaddy's storefront API (secureserver.net) using your reseller ID and a static probe domain, no visitor data is ever sent. Prices are cached for 12 hours and refreshed by a background task.

= Is visitor data personal data under GDPR? =

Reseller Intent stores no IP addresses, cookies, identifiers, or user accounts, only anonymous events like "someone searched example.com on a mobile device". Searched domain names are stored as typed; add your own note to your privacy policy if you want to disclose the tracking. Retention and clear-data controls are built in.

= My dashboard shows no data. =

Data starts collecting from activation. Make sure the Reseller Store domain search widget is actually on a page, and note that most page caches don't affect tracking (events post to admin-ajax).

= Can I import data from the Reseller Store Add-On? =

Yes, if the legacy table exists, Settings shows a one-click importer.

== Shortcodes ==

Build both visually under Reseller Intent → Shortcodes.

* `[rintent_tld_strip tlds=".com,.in,.io" theme="light|dark" more_url="" more_label=""]`, live TLD price pills matching checkout prices (12h cache + background refresh).
* `[rintent_price ids="12,14,15" before="Starting at " after="/mo" fallback="$3.99"]`, live price across the selected Reseller Store products. `mode="min|max|range"` shows the cheapest (default), highest, or a full range with a `separator` of your choice. Wrap it in your own words with `before` and `after`. Every part has its own CSS class (`.rintent-price`, `-before`, `-amount`, `-sep`, `-after`).
* `[rintent_phone format="link|text" prefix="Call "]`, geo-aware support number. Manage the numbers on the same Shortcodes page; GoDaddy's global support numbers are built in as defaults and your own list is never touched by updates. Visitors see their region's number via a page-cache-safe client-side swap based on the browser timezone. No IPs, no lookup services.

== Changelog ==

= 1.0.0 =
* Initial release: anonymous domain-search tracking, intent dashboard, chunked CSV export, browser-style clear-data, retention controls, legacy importer.
* Widget style pack: accent-driven styling, skeleton loaders, floating Clear All, dark context, documented theming variables.
* Shortcodes with generator UI: TLD price strip (cached + cron-warmed) and starting-at price.
* Top Countries panel (privacy-safe geo headers), WP dashboard glance widget, search ignore-list, filterable dashboard capability.
* Timezone-based country fallback for hosts without geo headers; legacy timezone aliases handled.
* Geo-aware support phone shortcode with regional numbers manager.
* Custom date range on the dashboard, JSON export, weekly email digest.
