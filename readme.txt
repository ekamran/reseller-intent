=== Reseller Intent ===
Contributors: ekamran
Tags: domain search, domains, analytics, reseller, tld pricing
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

**Data controls**

* Browser-style "Clear data", delete the last hour, 24 hours, 7 days, 30 days, 6 months, year, or everything, with a preview count before you confirm
* Optional automatic retention (30 days to 2 years, off by default)
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

Requires the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs. Tested with Reseller Store 3.0.1; if a newer Reseller Store version is detected, the plugin shows a friendly heads-up so you can double-check your dashboard.

== Installation ==

1. Install and activate the free Reseller Store plugin, then complete its setup so your storefront works.
2. Install Reseller Intent from Plugins > Add New, or upload the plugin folder to wp-content/plugins.
3. Activate it. Tracking starts immediately on any page that already shows the Reseller Store domain search.
4. Open Reseller Intent in the admin menu to see the dashboard. Everything under Settings is optional.

== Frequently Asked Questions ==

= Does this plugin make any external API calls? =

Tracking and the dashboard talk only to your own WordPress site. The optional TLD price strip shortcode fetches prices server-side from GoDaddy's storefront API (secureserver.net) using your reseller ID and a static probe domain, no visitor data is ever sent. Prices are cached for 12 hours and refreshed by a background task.

= Is visitor data personal data under GDPR? =

Reseller Intent stores no IP addresses, cookies, identifiers, or user accounts, only anonymous events like "someone searched example.com on a mobile device". Searched domain names are stored as typed; add your own note to your privacy policy if you want to disclose the tracking. Retention and clear-data controls are built in.

= My dashboard shows no data. =

Data starts collecting from activation. Make sure the Reseller Store domain search widget is actually on a page, and note that most page caches don't affect tracking (events post to admin-ajax).

== Shortcodes ==

Build both visually under Reseller Intent → Shortcodes.

* `[rintent_tld_strip tlds=".com,.in,.io" theme="light|dark" more_url="" more_label=""]`, live TLD price pills matching checkout prices (12h cache + background refresh).
* `[rintent_price ids="12,14,15" before="Starting at " after="/mo" fallback="$3.99"]`, live price across the selected Reseller Store products. `mode="min|max|range"` shows the cheapest (default), highest, or a full range with a `separator` of your choice. Wrap it in your own words with `before` and `after`. Every part has its own CSS class (`.rintent-price`, `-before`, `-amount`, `-sep`, `-after`).
* `[rintent_phone]`, geo-aware support line: the visitor's regional flag and number as one tel: link (optional `prefix` text). Manage the numbers on the same Shortcodes page; GoDaddy's global support numbers are built in as defaults and your own list is never touched by updates. Visitors see their region's number via a page-cache-safe client-side swap based on the browser timezone. No IPs, no lookup services.

== WP-CLI ==

* `wp rintent export [--days=<n>] [--format=<csv|json>]`, stream all events to stdout.
* `wp rintent stats [--days=<n>]`, event counts and conversion.
* `wp rintent clear --range=<hour|day|week|month|6months|year|all> [--yes]`, delete events.
* `wp rintent refresh-tld`, fetch fresh TLD strip prices now.

== External services ==

The optional TLD price strip shortcode fetches live domain prices from GoDaddy's storefront API at secureserver.net, the platform that powers every Reseller Store storefront. This keeps the displayed prices identical to the checkout prices.

What is sent: your public reseller ID and one static probe domain name per TLD you configured. This happens server side about twice a day and after a manual refresh, and only while the price strip shortcode is in use. No visitor data of any kind is ever sent.

The service is operated by GoDaddy: [Universal Terms of Service](https://www.godaddy.com/legal/agreements/universal-terms-of-service-agreement), [Privacy Policy](https://www.godaddy.com/legal/agreements/privacy-policy).

The built-in default support numbers for the phone shortcode are GoDaddy's public support numbers, stored inside the plugin; showing them involves no external request.

== Performance ==

Built to stay out of your page speed score.

* Frontend files load only on pages where the Reseller Store widget script is present. Pages without it load nothing from this plugin.
* The whole frontend footprint is a few small files (tracking about 6 KB, optional styling about 20 KB before compression). No React, no frameworks, no web fonts.
* No external requests from your visitors' browsers. Tracking talks to your own site only, phone number swapping uses the browser timezone, TLD prices are fetched by your server on a schedule and cached.
* Page caches are fine, even aggressive ones. Tracking does not use nonces, the phone shortcode renders a cache-safe default and swaps client-side, prices come from a server-side cache.
* Cart clicks are sent with sendBeacon, so the event survives the jump to checkout.
* The dashboard (React) loads in wp-admin only, never on your site.
* Bonus: an opt-in Performance setting trims Reseller Store's own sitewide assets (React, jQuery add-ons, styles) from pages that have no store element on them.

== Screenshots ==

1. The intent dashboard: KPIs with 7-day sparklines, search vs cart trend, conversion funnel and availability at a glance.
2. Every list panel pages through the full dataset, with real totals.
3. Shortcode builders with live previews: the TLD price strip and the live product price.
4. The shortcodes on a real page: TLD price strip, price lines and the support number, on light and dark sections.
5. The styled domain search widget on light and dark sections.
6. Settings: accent colors, widget styling, performance trim and privacy controls.
7. Browser-style clear data with a preview count before anything is deleted.

== Changelog ==

= 1.0.0 =
* Initial release: anonymous domain-search tracking, intent dashboard, chunked CSV export, browser-style clear-data, retention controls.
* Widget style pack: accent-driven styling, skeleton loaders, floating Clear All, automatic dark-section detection, documented theming variables.
* Shortcodes with generator UI: TLD price strip (cached + cron-warmed) and starting-at price.
* Top Countries panel (privacy-safe geo headers), WP dashboard glance widget, search ignore-list, filterable dashboard capability.
* Timezone-based country fallback for hosts without geo headers; legacy timezone aliases handled.
* Geo-aware support phone shortcode with regional numbers manager.
* Custom date range on the dashboard, JSON export.
