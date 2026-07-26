=== Reseller Intent for GoDaddy Reseller Store ===
Contributors: ekamran, yusufmudagal, arina01
Tags: godaddy, godaddy reseller, reseller store, domain reseller, reseller analytics
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which domains visitors search on your reseller storefront, which ones they pick, and which ones they take to cart.

== Description ==

**An add-on for the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs. Install and set that up first, or there is nothing here to read.**

Your analytics tell you about pages and traffic. They tell you nothing about the search box that actually sells. Reseller Intent records what happens in it: what people search, whether the name was free, what they pick instead, and what reaches the cart.

= What you get =

* A dashboard with searches, unique searches, cart clicks and how they compare to the previous period
* Daily trend, conversion funnel, top TLDs, availability, repeat searches and what people settled for
* Every list pages through the full dataset, and any range exports as CSV or JSON
* A small widget on your WordPress dashboard for today and this week
* An optional style pack for the search widget, and three shortcodes for your storefront

= Privacy =

Events are written to a table in your own database and never leave your site. No cookies, no fingerprinting, no IP addresses, no user accounts. An event is "someone on a mobile device searched example.com and it was available", nothing more.

Clear data by time window the way a browser clears history, with a count shown before you confirm. Set a retention window and old events go on their own. Your own test searches can be ignored, and bots are skipped by default.

= For developers =

Filter `rintent_should_track` to pause tracking, `rintent_event_data` to change or drop an event, and `rintent_page_needs_store` when a page builder hides the widget from detection. Everything lives in one indexed custom table.

== Installation ==

1. Install and set up the free Reseller Store plugin first.
2. Install and activate Reseller Intent.
3. Tracking starts straight away on any page that already shows the domain search.
4. Everything under Settings is optional.

== Frequently Asked Questions ==

= Do I need the Reseller Store plugin? =

Yes. Reseller Intent reads the domain search widget that Reseller Store renders. WordPress will tell you if it is missing.

= My dashboard is empty =

Collection starts at activation, so earlier searches are not there. Check the search widget is on a page visitors can reach, then run a search yourself. If your own searches are in the ignore list they will not appear.

= Does it slow my site down? =

The tracking script is about 6 KB and only loads on pages that have the search widget. The dashboard stays in wp-admin. There is also a setting that trims Reseller Store's sitewide scripts from pages that do not need them.

= Does it work with page caching? =

Yes, including full page caching. Tracking avoids nonces, which go stale inside a cached page, and prices come from a server side cache.

= Is any of this personal data? =

No identifiers are stored. Searched names are kept as typed, and people can type anything into a search box, so mention the tracking in your privacy policy if you want to be thorough.

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

= 1.0.4 =
* Fixed: the search button always used a white label, which was hard to read on a light or warm accent color. It now picks light or dark ink by whichever reads better.
* Fixed: prices in the light TLD strip were hard to read with a warm accent color.
* Fixed: the price and the support number were dark text on dark sections.
* Fixed: several faint labels on the dashboard, the shortcode builders and the dashboard widget.
* New theming variable `--rintent-accent-ink` for the accent used as text on light surfaces.

= 1.0.3 =
* Corner rounding is now a theming variable, `--rintent-radius`. Use 0 for square corners or 50px for pills.

= 1.0.2 =
* Shorter plugin name so it fits on one line.

= 1.0.1 =
* Fixed: international domain names lost their accents and were stored incorrectly.
* Fixed: the trend chart ignored a custom accent color.
* Fixed: page names were truncated in the Search by Page panel.
* Fixed: some dashboard strings were not translatable.

= 1.0.0 =
* Anonymous domain search tracking and the intent dashboard.
* CSV and JSON export, clear data by time window, retention controls.
* Optional style pack for the search widget, with documented theming variables.
* Shortcodes with visual builders: TLD price strip, live product price, regional support line.
* Top countries, dashboard glance widget, search ignore list.
* WP-CLI commands and Site Health checks.
