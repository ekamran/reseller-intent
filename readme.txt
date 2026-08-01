=== Reseller Intent for GoDaddy Reseller Store ===
Contributors: ekamran, yusufmudagal, arina01
Tags: godaddy, godaddy reseller, reseller store, domain reseller, reseller analytics
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which domains visitors search on your reseller storefront, which ones they pick, and which ones they take to cart.

== Description ==

**An add-on for the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs. Set that up first.**

Your analytics tell you about pages and traffic. They tell you nothing about the search box that actually sells. Reseller Intent records what happens in it: what people search, whether the name was free, what they pick instead, and what reaches the cart.

= What you get =

* A dashboard: searches, cart clicks, trends, top TLDs, repeat searches, each compared to the previous period
* Every list pages through the full dataset; any range exports as CSV or JSON
* An optional style pack for the search widget, plus three shortcodes: TLD price strip, live product price, regional support number
* Today and this week at a glance on your WordPress dashboard

= Privacy =

Events are written to one table in your own database and never leave your site. No cookies, no fingerprinting, no IP addresses. An event is "someone on a mobile device searched example.com and it was available", nothing more. Clear data by time window with a count shown first, set a retention window, ignore your own test searches. Bots are never recorded.

= For developers =

Filter `rintent_should_track` to pause tracking, `rintent_event_data` to change or drop an event, and `rintent_page_needs_store` when a page builder hides the widget from detection. Everything lives in one indexed custom table.

== Installation ==

1. Install and set up the free Reseller Store plugin first.
2. Install and activate Reseller Intent.
3. Tracking starts straight away on any page that shows the domain search. Everything under Settings is optional.

== Frequently Asked Questions ==

= Do I need the Reseller Store plugin? =

Yes. Reseller Intent reads the domain search widget that Reseller Store renders. WordPress will tell you if it is missing.

= Which Reseller Store versions are supported? =

2.2.17 up to 3.0.1. On anything older the plugin pauses itself with a notice instead of guessing, update Reseller Store and it resumes on its own.

= My dashboard is empty =

Collection starts at activation, so earlier searches are not there. Check the search widget is on a page visitors can reach, then run a search yourself. If your own searches are in the ignore list they will not appear.

= Does it slow my site down? =

The tracking script is about 6 KB and loads only where the search widget is. There is also a setting that trims Reseller Store's own sitewide scripts from pages that do not need them.

= Does it work with page caching? =

Yes, including full page caching. Tracking avoids nonces, which go stale inside a cached page, and prices come from a server side cache.

= Is any of this personal data? =

No identifiers are stored. Searched names are kept as typed, and people can type anything into a search box, so mention the tracking in your privacy policy if you want to be thorough. Running a consent banner? Return false from the `rintent_should_track` filter until the visitor agrees, and nothing is recorded before that.

= Will it change how my search widget looks? =

Only if you let it. The style pack is optional and matches the widget to your accent color and corner rounding. Turn it off in Settings and the plugin loads no CSS at all.

= What happens when I uninstall? =

Nothing is deleted unless you asked for it. Tick the uninstall option in Settings first if you want the table and settings removed.

== Screenshots ==

1. The intent dashboard: KPIs with 7-day sparklines, search vs cart trend, conversion funnel and availability at a glance.
2. Every list panel pages through the full dataset, with real totals.
3. Shortcode builders with live previews: the TLD price strip and the live product price.
4. The shortcodes on a real page: TLD price strip, price lines and the support number, on light and dark sections.
5. The styled domain search widget on light and dark sections.
6. Settings: accent colors, widget styling, performance trim and privacy controls.
7. Browser-style clear data with a preview count before anything is deleted.
8. Today and this week on your WordPress dashboard, without opening the plugin.

== Shortcodes ==

Each one has a visual builder with a live preview under Reseller Intent, Shortcodes.

`[rintent_tld_strip tlds=".com,.in,.io" theme="dark" more_url="/domains/" more_label="560+ more TLDs"]`

A row of price pills using live prices from your own catalog. `theme` is light or dark; dark sections are also detected on their own.

`[rintent_price ids="12,14,15" mode="min" before="Starting at " after=" per year"]`

A real price from the products you select. `mode` is `min`, `max` or `range`. `before`, `after` and `fallback` are your own wording.

`[rintent_phone prefix="Call us"]`

One phone number as a tap to call link, picked for the visitor's region from the browser timezone. GoDaddy's public numbers are the defaults; your own are never overwritten.

== WP-CLI ==

`wp rintent stats --days=30` prints searches, unique searches, cart clicks, domains added and the search to cart rate.

`wp rintent export --days=90 --format=csv > events.csv` streams every event as CSV or JSON, in batches.

`wp rintent clear --range=month --yes` deletes events in a window: `hour`, `day`, `week`, `month`, `6months`, `year` or `all`.

`wp rintent refresh-tld` fetches fresh TLD strip prices immediately.

== External services ==

The optional TLD price strip fetches live domain prices from GoDaddy's storefront API at secureserver.net, the platform behind every Reseller Store storefront, so the pills match checkout.

What is sent: your public reseller ID and one static probe domain name per TLD you configured. This happens from your server about twice a day, plus whenever you press refresh, and only while the shortcode is in use. No visitor data is ever sent.

The service is operated by GoDaddy: [Universal Terms of Service](https://www.godaddy.com/legal/agreements/universal-terms-of-service-agreement), [Privacy Policy](https://www.godaddy.com/legal/agreements/privacy-policy).

The default support numbers for the phone shortcode are GoDaddy's published numbers, stored inside the plugin. Showing them involves no external request.

== Changelog ==

= 1.0.6 =
* Changed: Reseller Store 2.2.17 is now the minimum supported version. On older builds the plugin pauses itself with a clear notice instead of running against untested widget markup. The PHP requirement is lowered to 7.4 so Reseller Store 2.x sites can install.
* Changed: search results in the dashboard now read Available and Registered instead of free and taken, and both labels are translatable.
* Changed: the Recent Searches log keeps the latest 1,000 searches of the selected range on hand instead of 100. Use Export for everything.
* Fixed: the comparison behind every KPI arrow measured a partial day against a whole one, which showed false drops. Both windows are now the same length.
* Fixed: on the day a timezone changes to or from DST, events could land in the wrong day bucket.
* Fixed: the Search by Page panel counted the same page as separate rows when links carried fragments or query strings.
* Fixed: deeper pages of Missed Opportunities could miscount because already-carted domains were filtered after paging instead of before.
* Fixed: Clear data reported the wrong number of removed events in some ranges.
* Fixed: tracking could bind twice when another plugin evaluated scripts a second time, doubling counts on affected sites.
* Fixed: changing the date range no longer remounts every panel, which caused a visible flicker and lost scroll positions.
* Fixed: clearing data on hosts that forbid TRUNCATE now falls back to DELETE instead of failing silently.
* Fixed: the dashboard glance widget no longer warns on a fresh install before the first event arrives.
* Improved: the styled search widget pins its own typography, spacing and button geometry, so themes with aggressive global styles cannot stretch or shrink it.
* Improved: cart-size accuracy on busy stores, the carted-domains scan looks four times deeper.
* Improved: CSV exports guard against spreadsheet formula injection in every column, in the dashboard and WP-CLI alike.

= 1.0.5 =
* Security: the Clear All button text was placed into the page without escaping, so wording that contained quote characters could inject markup into the search widget. Update if you use the style pack.
* Fixed: searches that arrive through a `?domainToCheck=` link were never recorded, so traffic from campaigns and emails was missing from the dashboard.
* Fixed: if a visitor started typing again before results appeared, the availability was recorded against the wrong search.
* Fixed: TLDs that show a note next to the name, such as .app, were recorded with that note stuck to the domain.
* Fixed: the loading rows could stay on screen underneath the results.
* Fixed: the support number shortcode stopped the page on hosts without the mbstring extension.
* Fixed: the Shortcodes screen previews failed on stores with no products yet.
* Fixed: an international domain that cannot be encoded is now skipped instead of stored with its accents removed.
* Fixed: `wp rintent export --format=csv` now guards spreadsheet formula characters, matching the export in the dashboard.
* Improved: results shown in a modal are capped to the screen and scroll on their own, instead of running off the bottom where they could not be reached.
* Improved: the exact domain you searched is now marked out from the suggestions below it.

Earlier versions are documented in the GitHub repository.
