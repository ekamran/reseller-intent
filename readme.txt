=== Reseller Intent ===
Contributors: kamranaziz
Tags: godaddy, reseller store, domain search, analytics, domains
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Domain search analytics and buyer intent for GoDaddy Reseller Store — see what visitors search, select, and carry to cart.

== Description ==

Your Reseller Store domain search is where buying decisions happen — and by default you can't see any of it. Reseller Intent records what visitors search, whether their domain was available, what they select instead, and what they carry to cart, then turns it into a clean dashboard.

**Dashboard**

* KPI cards with previous-period comparison
* Search vs cart trend chart (7 / 30 / 90 days / lifetime)
* Intent funnel: searches → selections → cart clicks, with per-stage rates
* TLD demand distribution
* "Kept searched name" vs settled-for-alternative selection insights
* Availability quality and device split
* Per-page breakdown (which search box converts)
* Recent searches log with filter
* CSV export for any range

**Data controls**

* Browser-style "Clear data" — delete the last hour, 24 hours, 7 days, 30 days, 6 months, year, or everything, with a preview count before you confirm
* Optional automatic retention (30 days – 2 years, off by default)
* Optional full cleanup on uninstall

**Privacy by design**

* No cookies, no fingerprinting, no IP addresses stored, no user accounts recorded — events are anonymous interaction counts
* Known bots and crawlers are ignored by default
* `rintent_should_track` filter lets consent plugins pause tracking until consent is given

**For developers**

* `rintent_should_track` / `rintent_event_data` filters
* Indexed custom table, zero queries on requests that don't involve tracking
* Frontend script loads only on pages where the Reseller Store widget is present

Requires the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs.

== Frequently Asked Questions ==

= Does this plugin make any external API calls? =

No. Version 1.0 talks only to your own WordPress site. (The Reseller Store plugin itself talks to GoDaddy's storefront API to power the search widget — that is unchanged.)

= Is visitor data personal data under GDPR? =

Reseller Intent stores no IP addresses, cookies, identifiers, or user accounts — only anonymous events like "someone searched example.com on a mobile device". Searched domain names are stored as typed; add your own note to your privacy policy if you want to disclose the tracking. Retention and clear-data controls are built in.

= My dashboard shows no data. =

Data starts collecting from activation. Make sure the Reseller Store domain search widget is actually on a page, and note that most page caches don't affect tracking (events post to admin-ajax).

= Can I import data from the Reseller Store Add-On? =

Yes — if the legacy table exists, Settings shows a one-click importer.

== Roadmap ==

* TLD price strip and "starting at" price shortcodes with a generator UI
* Optional widget style pack (dark theme, floating clear-all, skeleton loaders)
* Country stats (privacy-safe, header-based), custom date ranges, dashboard glance widget
* Weekly email digest, JSON export, capability control, search blocklist

== Changelog ==

= 1.0.0 =
* Initial release: anonymous domain-search tracking, intent dashboard, chunked CSV export, browser-style clear-data, retention controls, legacy importer.
