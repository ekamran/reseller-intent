=== Reseller Intent - Domain Search Analytics for GoDaddy Reseller Store ===
Contributors: ekamran, yusufmudagal, arina01
Tags: godaddy, godaddy reseller, reseller store, domain reseller, reseller analytics
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which domains visitors search on your reseller storefront, which ones they pick, and which ones they take to cart.

== Description ==

**This is an add-on for the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs. Install and set that plugin up first, otherwise there is nothing for Reseller Intent to read.**

Your domain search box is where people decide whether to buy from you. Out of the box you never get to see any of it. A visitor searches a name, finds it taken, tries three more, picks one, and leaves without buying. All of that happens and nothing is recorded.

Reseller Intent watches that search box and writes down what happened, without cookies and without storing anything that identifies a person. Then it turns those events into a dashboard you can actually read.

= What you get to see =

The dashboard opens on the numbers that matter: how many searches came in, how many were unique, how many people clicked through to the cart, and how that compares to the previous period. Below that, the story fills in.

You can follow the daily trend of searches against cart clicks, watch the funnel from search to selection to cart with the drop-off at each stage, and see which TLDs people actually ask for. You will find out how often a searched name was still free, which pages your searches come from, and whether people kept the name they typed or settled for something else.

Every list panel pages through the whole dataset, not just the top few rows, and any range can be exported as CSV or JSON.

You do not have to open the plugin to keep an eye on it. A small widget on your WordPress dashboard shows searches today, searches this week, and how many of them reached the cart.

= Your data, your rules =

There is no cloud service behind this plugin. Events are written to a table in your own database and never leave your site.

Nothing that identifies a visitor is stored. No cookies, no fingerprinting, no IP addresses, no user accounts. An event looks like "someone on a mobile device searched example.com and it was available", and that is all it is. Country data, when your host or CDN provides it, comes from a two letter header, never from an IP address you keep.

You can delete data the way a browser lets you clear history: pick the last hour, day, week, month, six months, year, or everything, and you will see exactly how many events that removes before you confirm. If you would rather not think about it, set a retention window and old events are cleaned up on their own. Uninstalling can take the whole table with it, if you tick that box.

Testing your own storefront is normal, so there is an ignore list for your own searches, and known bots are skipped by default.

= Shortcodes for your storefront =

Three shortcodes come along for the ride, each with a visual builder under Reseller Intent, Shortcodes. They are useful on their own, and they always show real prices from your own catalog.

A TLD price strip prints a row of pills like ".com $13.99", refreshed in the background so the numbers always match checkout. A live price shortcode prints the cheapest, highest, or full range across the plans you select, wrapped in your own wording. A support line prints the right regional phone number for each visitor as a single tap to call link.

= Built to stay out of the way =

The frontend footprint is a few small files, and they only load on pages where your search widget actually is. No React, no frameworks, no web fonts, and no requests to anyone else's server from your visitors' browsers.

Page caching does not break tracking, aggressive caching included. The dashboard itself only loads inside wp-admin, so it never touches your site's speed. There is even an optional setting that trims Reseller Store's own sitewide scripts from pages that have no store element on them.

= For developers =

Filter `rintent_should_track` to pause tracking, for example until your consent plugin says yes. Filter `rintent_event_data` to change or drop an event before it is written. Filter `rintent_page_needs_store` if a page builder renders the search widget somewhere the plugin cannot see.

Everything lives in one indexed custom table, and requests that have nothing to do with tracking run zero extra queries.

== Installation ==

1. Install and activate the free Reseller Store plugin, then finish its setup so your storefront works and shows products.
2. Install Reseller Intent from Plugins, Add New, or upload the plugin folder to `wp-content/plugins`.
3. Activate it. Tracking starts straight away on any page that already shows the Reseller Store domain search.
4. Open Reseller Intent in the admin menu. Everything under Settings is optional, the plugin works without touching any of it.

== Frequently Asked Questions ==

= Do I need the Reseller Store plugin? =

Yes. Reseller Intent reads the domain search widget that Reseller Store renders, so that plugin has to be installed, activated and set up with your reseller account. WordPress will tell you if it is missing.

= My dashboard is empty. What is wrong? =

Data collection starts at activation, so there is nothing to show for searches that happened before. Check that the Reseller Store domain search is actually on a page a visitor can reach, then run a search yourself. If your own searches are in the ignore list, they will not appear, which is easy to forget.

= Does it slow my site down? =

The tracking script is about 6 KB and only loads on pages that have the search widget. Everything else, including the dashboard, stays in wp-admin. There is also a setting that removes Reseller Store's own sitewide scripts from pages that do not need them, which usually makes the site faster than before you installed this.

= Does it work with page caching? =

Yes, including aggressive full page caching. Tracking deliberately avoids nonces, which go stale inside a cached page, and the phone shortcode renders a safe default that is swapped in the browser. Prices come from a server side cache, so no visitor ever waits on an API call.

= Is any of this personal data? =

No IP addresses, cookies, identifiers or user accounts are stored, so there is nothing that points back to a person. Searched domain names are stored exactly as typed, and someone can of course type their own name into a search box, so mention the tracking in your privacy policy if you want to be thorough. Retention limits and one click deletion are built in.

= Where is the data stored, and can I get it out? =

In a single custom table in your own WordPress database. Any date range can be exported as CSV or JSON from the dashboard, and WP-CLI can stream the whole table if you prefer.

= Does it change how my search widget looks? =

Only if you let it. The optional style pack matches the widget to your accent color, adds skeleton rows while results load, and puts a Clear All button under the search bar. Dark page sections are detected on their own. Turn the whole thing off in Settings and your theme's styling is untouched.

= Can I change the wording it shows visitors? =

Yes. Every string a visitor can see is editable, including the Clear All button, the text around prices, and the label on the more TLDs pill. Everything else is translatable in the usual way.

= What happens when I uninstall? =

Nothing is deleted unless you asked for it. Tick the uninstall option in Settings first if you want the table and all settings removed when the plugin is deleted.

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

Build any of these visually under Reseller Intent, Shortcodes. The builder shows a live preview and hands you the finished shortcode to copy.

= TLD price strip =

`[rintent_tld_strip tlds=".com,.in,.io" theme="dark" more_url="/domains/" more_label="560+ more TLDs"]`

A row of price pills using live prices from your own catalog, so they always match what the visitor sees at checkout. Prices are cached for twelve hours and refreshed in the background, and after a Reseller Store product import.

`tlds` is a comma separated list, with or without the leading dot. `theme` is light or dark, and dark sections are also detected on their own. `more_url` and `more_label` add an optional last pill that links wherever you want.

= Live product price =

`[rintent_price ids="12,14,15" before="Starting at " after=" per year" mode="min"]`

Prints a real price from the products you select, so it updates itself when GoDaddy reprices a plan.

`mode` can be `min` for the cheapest, `max` for the highest, or `range` for both ends with a `separator` between them. `before` and `after` are your own wording, and `fallback` is what shows if no price is available yet. Each part has its own CSS class, so your theme can style the number differently from the words around it.

= Regional support line =

`[rintent_phone prefix="Call us"]`

Shows one phone number as a tap to call link, picked for the visitor's region from the browser timezone. No IP lookups and no external service.

GoDaddy's public support numbers are built in as defaults. Add your own numbers on the Shortcodes page and updates will never overwrite them.

== WP-CLI ==

= wp rintent stats =

`wp rintent stats --days=30`

Prints searches, unique searches, cart clicks, domains added and the search to cart rate for the last N days. Leave off `--days` for everything.

= wp rintent export =

`wp rintent export --days=90 --format=csv > events.csv`

Streams every event to stdout as CSV or JSON, in batches, so the size of your table does not matter. Redirect it wherever you like.

= wp rintent clear =

`wp rintent clear --range=month --yes`

Deletes events in a window: `hour`, `day`, `week`, `month`, `6months`, `year` or `all`. Without `--yes` it asks first.

= wp rintent refresh-tld =

`wp rintent refresh-tld`

Fetches fresh TLD strip prices immediately instead of waiting for the scheduled refresh.

== External services ==

The optional TLD price strip fetches live domain prices from GoDaddy's storefront API at secureserver.net, the same platform that powers every Reseller Store storefront. This is what keeps the pills showing the same prices as checkout.

What is sent: your public reseller ID and one static probe domain name per TLD you configured. This happens from your server about twice a day, plus whenever you press refresh, and only while the price strip shortcode is actually in use. No visitor data of any kind is ever sent.

The service is operated by GoDaddy: [Universal Terms of Service](https://www.godaddy.com/legal/agreements/universal-terms-of-service-agreement), [Privacy Policy](https://www.godaddy.com/legal/agreements/privacy-policy).

The built in default support numbers for the phone shortcode are GoDaddy's published support numbers, stored inside the plugin. Showing them involves no external request.

== Changelog ==

= 1.0.1 =
* Fixed: searches for international domain names lost their accents on the way in, so München.de was recorded as a name nobody typed. They are now stored correctly and shown the way they were written.
* Fixed: the trend chart's fill stayed blue when a custom accent color was set, instead of following the accent.
* Fixed: page names in the Search by Page panel were cut down to a couple of letters in the narrow layout.
* Fixed: a handful of dashboard strings, and one screen reader label, were not available for translation.
* Hardening: admin rows are now built with DOM calls instead of HTML strings, and permission failures answer 403 instead of 500.

= 1.0.0 =
* Initial release: anonymous domain-search tracking, intent dashboard, chunked CSV export, browser-style clear-data, retention controls.
* Widget style pack: accent-driven styling, skeleton loaders, floating Clear All, automatic dark-section detection, documented theming variables.
* Shortcodes with generator UI: TLD price strip (cached + cron-warmed) and starting-at price.
* Top Countries panel (privacy-safe geo headers), WP dashboard glance widget, search ignore-list, filterable dashboard capability.
* Timezone-based country fallback for hosts without geo headers; legacy timezone aliases handled.
* Geo-aware support phone shortcode with regional numbers manager.
* Custom date range on the dashboard, JSON export.
* WP-CLI commands, Site Health checks, Playground blueprint.
