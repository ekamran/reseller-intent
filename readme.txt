=== Reseller Intent for GoDaddy Reseller Store ===
Contributors: ekamran, yusufmudagal, arina01
Tags: godaddy, godaddy reseller, reseller store, domain reseller, reseller analytics
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which domains visitors search on your reseller storefront, which ones they pick, and which ones they take to cart.

== Description ==

**An add-on for the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs. Set that up first.**

Your analytics tell you about pages and traffic. They tell you nothing about the search box that actually sells. Reseller Intent records what happens in it: what people search, whether the name was free, what they pick instead, and what reaches the cart. It watches the transfer box and the product buttons too, so hosting, email and SSL demand shows up next to the domains.

= What you get =

* A dashboard of search intent: searches, cart clicks, trends, top TLDs, repeat searches, carted domains, added products and transfer searches, every number compared to the previous period and explained with a tooltip
* Filter the whole dashboard by the page a search happened on, or watch all pages together
* Every list pages through the full dataset; any range exports as CSV or JSON
* An optional style pack for the Reseller Store widgets, plus three shortcodes: TLD price strip, live product price, regional support number
* Today and this week at a glance on your WordPress dashboard

= Privacy =

Events are written to one table in your own database and never leave your site. No cookies, no fingerprinting, no IP addresses. An event is "someone on a mobile device searched example.com and it was available", nothing more. Clear data by time window with a count shown first, set a retention window, ignore your own test searches. Bots are never recorded.

= For developers =

Filter `rintent_should_track` to pause tracking, `rintent_event_data` to change or drop an event, `rintent_page_needs_store` when a page builder hides the widget from detection, and `rintent_track_bots` for the rare debugging session that needs bot traffic recorded. Everything lives in one indexed custom table.

== Installation ==

1. Install and set up the free Reseller Store plugin first.
2. Install and activate Reseller Intent.
3. Tracking starts straight away on any page that shows a Reseller Store element. Open Reseller Intent in the admin menu and watch it come in. Everything under Settings is optional.

== Frequently Asked Questions ==

= Do I need the Reseller Store plugin? =

Yes. Reseller Intent reads the domain search widget that Reseller Store renders. WordPress will tell you if it is missing.

= Which Reseller Store versions are supported? =

2.2.17 up to 3.0.1. On anything older the plugin pauses itself with a notice instead of guessing, update Reseller Store and it resumes on its own.

= Which Reseller Store elements does it cover? =

The domain search, the simple search box, the transfer box, product pods and their Add to cart button, the cart and the sign in link. Reseller Store renders all of these through the same classes whether you place them as a shortcode, a widget or a block, so tracking and styling reach all three placements at once.

= My dashboard is empty =

Collection starts at activation, so earlier searches are not there. Check the search widget is on a page visitors can reach, then run a search yourself. If your own searches are in the ignore list they will not appear.

= Does it slow my site down? =

The tracking script is about 6 KB and loads only where the search widget is. There is also a setting that trims Reseller Store's own sitewide scripts from pages that do not need them.

= Does it work with page caching? =

Yes, including full page caching. Tracking avoids nonces, which go stale inside a cached page, and prices come from a server side cache.

= Is any of this personal data? =

No identifiers are stored. Searched names are kept as typed, and people can type anything into a search box, so mention the tracking in your privacy policy if you want to be thorough. Running a consent banner? Return false from the `rintent_should_track` filter until the visitor agrees, and nothing is recorded before that.

= Where does the visitor country come from? =

From your edge or host, when it says: Cloudflare and similar CDNs send an exact country header with every request. Without one, the visitor's browser timezone is mapped to a country, approximate but right for most visitors. No lookup service is called and no IP address is stored either way.

= Can I see the numbers for one page only? =

Yes. The page filter next to the date ranges follows every panel: KPIs, trend, TLDs, repeats, carted domains and the recent log. Search by Page keeps comparing all pages so you always see the whole field, and exports always carry everything.

= I updated to 2.0 and some panels are gone =

Three low-value panels were removed on purpose: Missed Opportunities, Conversion Funnel and Selection Behavior. The useful part of the funnel, the cart-size split, lives on inside Carted Domains. Your data was not touched, only the panels reading it.

= Will it change how my search widget looks? =

Only if you let it. The style pack is optional and matches the Reseller Store widgets to your accent color and corner rounding. Turn it off in Settings and the plugin loads no CSS at all.

= What happens when I uninstall? =

Nothing is deleted unless you asked for it. Tick the uninstall option in Settings first if you want the table and settings removed.

== Screenshots ==

1. The intent dashboard: six KPI cards with tooltips, search vs cart trend, and every list paging in place.
2. The whole dashboard following one page through the page filter.
3. Repeat Demand, Carted Domains with the cart-size split, and Search by Page on one row.
4. The Shortcodes page: the family-first price builder with a live preview.
5. The TLD price strip builder, with the refresh clock in the card footer.
6. The support number card, previewed with your own timezone.
7. Settings: four cards, everything optional.
8. Browser-style clear data with a preview count before anything is deleted.

== Shortcodes ==

Each one has a visual builder with a live preview under Reseller Intent, Shortcodes.

`[rintent_tld_strip tlds=".com,.in,.io" theme="dark" more_url="/domains/" more_label="560+ more TLDs"]`

A row of price pills using live prices from your own catalog. `theme` is light or dark; dark sections are also detected on their own.

`[rintent_price family="cpanel" mode="range" before="cPanel from" after="per month"]`

A live price from a product family in your catalog: cheapest, highest or the range across its plans. Build it visually on the Shortcodes page, the family keeps the number correct when prices change. Older `ids=""` embeds keep working unchanged.

`[rintent_phone]`

One phone number as a tap to call link, picked for the visitor's region from the browser timezone. GoDaddy's public numbers are the defaults; your own are never overwritten.

== WP-CLI ==

`wp rintent stats --days=30` prints searches, unique searches, cart clicks, domains added and the search to cart rate.

`wp rintent export --days=90 --format=csv > events.csv` streams every event as CSV or JSON, in batches.

`wp rintent clear --range=month --yes` deletes events in a window: `hour`, `day`, `week`, `month`, `6months`, `year` or `all`.

`wp rintent refresh-tld` fetches fresh TLD strip prices immediately.

== External services ==

The optional TLD price strip fetches live domain prices from GoDaddy's storefront API at secureserver.net, the platform behind every Reseller Store storefront, so the pills match checkout.

What is sent: your public reseller ID and one static probe domain name per TLD you configured. This happens from your server about twice a day, plus whenever you press refresh, and only while the shortcode is in use. No visitor data is ever sent.

That API is operated by GoDaddy: [Universal Terms of Service](https://www.godaddy.com/legal/agreements/universal-terms-of-service-agreement), [Privacy Policy](https://www.godaddy.com/legal/agreements/privacy-policy). Reseller Intent itself is an independent plugin, built for GoDaddy resellers but not made by, endorsed by or affiliated with GoDaddy.

The default support numbers for the phone shortcode are GoDaddy's published numbers, stored inside the plugin. Showing them involves no external request.

== Upgrade Notice ==

= 2.1.0 =
Adds transfer and product tracking with two new dashboard panels, and extends the style pack to the rest of the Reseller Store widgets. Also fixes white button labels turning dark when a Dark accent color is set.

= 2.0.0 =
The dashboard, Shortcodes and Settings screens are rebuilt, and three low-value panels are gone. The price shortcode is now family-first; every old embed keeps working. Your data is untouched.

== Changelog ==

= 2.1.0 =
* Added: transfer searches and product Add to cart are now recorded. Both hand the visitor to GoDaddy the moment they fire, so they are sent with sendBeacon and survive the jump.
* Added: two dashboard panels, Added Products and Transfer Searches. Each appears only once it has something in it, so a storefront without those widgets sees no change.
* Added: the Recent Searches log carries transfer searches too, marked Transfer in the Result column beside Available and Registered.
* Added: the style pack now covers the simple search, the transfer box, Add to cart, the cart and the sign in link, matching the search widget's field height, accent buttons and corner rounding.
* Changed: the styling card in Settings is now Store widgets, and its theming reference lists the new classes.
* Fixed: on sites with a Dark accent color set, the Search and Continue to cart buttons in a dark section drew their labels in dark ink instead of white. The Dark accent is a text color for dark surfaces and never colors buttons, so it no longer takes part in the button label color.
* Fixed: a product family whose plans differ in the middle of their names was labelled by that one shared word alone, so two SSL services read as plain "Managed". The shared tail of the names joins the label, giving "Managed SSL Service".
* Removed: the `--rintent-accent-dark-text` variable, which nothing consumed. Button labels follow `--rintent-accent-text`, and `--rintent-dark-button-text` still overrides them on dark sections.

= 2.0.0 =
* Added: filter the whole dashboard by the page a search happened on.
* Added: the plugin version back in the dashboard header.
* Added: the live preview blueprint seeds several widget pages and six months of sample data.
* Changed: the dashboard is rebuilt on a fixed grid. Every list panel is the same machine: ten rows per page with a pager pinned in the footer. Show more is gone, and panels sharing a row always match height.
* Changed: the dashboard runs the plugin's own fixed palette. Your accent color keeps styling the search widget and the TLD price strip, and the picker now lives with them on the Settings Widget card.
* Changed: KPI cards renamed to say what they count. Repeat Searches replaces Unique Searches and counts searches beyond the first for a name. Domains Sent to Cart replaces Domains Added. Avg per Cart Click replaces Avg Domains / Cart. Every card explains itself with a hover tooltip.
* Changed: Availability and Devices folded into two chips on the trend panel, Available % and Mobile %.
* Changed: the cart-size split reads 1x, 2x, 3x and 4+.
* Changed: Search by Page lists plain paths, only the homepage gets a word.
* Changed: Top Countries starts hidden in the panel picker and hides itself while it has no data.
* Changed: the price shortcode is family-first. Pick a product family and every plan is included automatically, so the number stays correct when prices change. Old `ids=""` embeds keep working unchanged.
* Changed: the Settings page slimmed to four cards. Retention is a plain day count, 0 keeps everything forever.
* Changed: the phone preview on the Shortcodes page localizes with your own browser timezone, using the same script visitors get.
* Changed: price wording pre-fills follow the family and the mode, per month by default.
* Changed: fresher green and red for the good and bad numbers.
* Removed: the Missed Opportunities, Conversion Funnel and Selection Behavior panels. The cart-size split lives on inside Carted Domains.
* Removed: the bot-tracking option. Bots are simply never recorded, developers can opt back in with the `rintent_track_bots` filter.
* Removed: the separate skeleton-loading toggle, folded into the style pack.
* Removed: the phone shortcode's unused `prefix` attribute.
* Fixed: tables, tooltips and delta badges render correctly in right-to-left admin languages.
* Fixed: modal search results keep a breathing gap instead of sticking to the top of the screen.
* Fixed: the documented `--rintent-accent-dark-text` variable now actually recolors text on dark sections.
* Fixed: the color picker keeps its Clear button beside the hex input.
* Improved: the TLD strip card shows when prices were last refreshed and when the next automatic refresh runs.

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
