=== Reseller Intent for GoDaddy Reseller Store ===
Contributors: ekamran, yusufmudagal, arina01
Tags: godaddy, godaddy reseller, reseller store, domain reseller, reseller analytics
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See which domains visitors search on your reseller storefront, which ones they pick, and which ones they take to cart.

== Description ==

**An add-on for the free [Reseller Store](https://wordpress.org/plugins/reseller-store/) plugin by GoDaddy Reseller Programs. Set that up first.**

Your analytics tell you about pages and traffic. They tell you nothing about the search box that actually sells. Reseller Intent records what happens inside it: what people search, whether the name was free, what they pick instead, and what reaches the cart. It watches the transfer box and the product buttons too, so hosting, email and SSL demand shows up next to the domains.

= What you get =

* A dashboard of real intent: searches, cart clicks, top TLDs, repeat searches, carted domains, added products, transfer searches and outbound clicks
* Every number compared with the period before it, so you see direction, not just totals
* A page filter that follows every panel, for when one landing page is doing the work
* Full history in every list, and any range exported as CSV or JSON
* The week at a glance on your WordPress dashboard, with the name people asked for most
* Site Health tells you when tracking or prices have quietly stopped, instead of leaving you to notice
* An optional style pack that matches the Reseller Store widgets to your colors, plus shortcodes for a TLD price strip, a live product price and a regional support number

= Privacy =

Events are written to one table in your own database and never leave your site. No cookies, no fingerprinting, no IP addresses. An event is "someone on a mobile device searched example.com and it was available", nothing more. Bots are never recorded, you can ignore your own test searches, and you can clear any window of data with the count shown first.

= For developers =

`rintent_should_track` pauses tracking, `rintent_event_data` changes or drops an event before it is stored, and `rintent_track_bots` records bot traffic while you debug. Everything lives in one indexed custom table.

== Installation ==

1. Install the free Reseller Store plugin and connect it to your GoDaddy reseller account. Nothing here works until Reseller Store is showing real prices.
2. Install and activate Reseller Intent.
3. Put a Reseller Store search widget on a page visitors can reach, as a block, a widget or a shortcode.
4. Open Reseller Intent in the admin menu. Run a search on your own site and watch it arrive.

Everything under Settings is optional. Tracking works out of the box.

== Frequently Asked Questions ==

= Do I need the Reseller Store plugin? =

Yes. Reseller Intent reads the widgets that Reseller Store renders, so it cannot work alone. WordPress will tell you if it is missing.

= Which Reseller Store versions are supported? =

2.2.17 up to 3.0.1. On anything older the plugin pauses itself with a notice instead of guessing, and resumes on its own once you update Reseller Store.

= Which Reseller Store elements does it cover? =

The domain search, the simple search box, the transfer box, product pods and their Add to cart button, the cart, the sign in link, and the support number from the phone shortcode. Reseller Store renders all of these through the same classes whether you place them as a shortcode, a widget or a block, so tracking and styling reach all three placements at once.

= My dashboard is empty =

Collection starts at activation, so searches from before that are not there. Check the search widget is on a page visitors can reach, then run a search yourself. If your own searches are in the ignore list they will not appear. Tools then Site Health also checks the tracking for you.

= Does it slow my site down, and does it work with caching? =

The tracking script is about 6 KB and loads only where a store widget is. Full page caching is fine: tracking avoids nonces, which go stale inside a cached page, and prices come from a server side cache.

= Is any of this personal data? =

No identifiers are stored. Searched names are kept as typed, and people can type anything into a search box, so mention the tracking in your privacy policy if you want to be thorough. Running a consent banner? Return false from the `rintent_should_track` filter until the visitor agrees, and nothing is recorded before that.

= Will it change how my search widget looks? =

Only if you let it. The style pack is optional and matches the Reseller Store widgets to your accent color and corner rounding. Turn it off and the plugin loads no CSS at all, so your page comes back exactly as it was. That is tested by measuring every store element with the pack off against the same page with the plugin deactivated, and the two match.

= My own CSS does not override the style pack. Why? =

The pack uses `!important` on the parts that have to win, because themes reach the same buttons through selectors that outrank a plain class. Do not fight it, set the variables instead:

`body { --rintent-accent: #0f766e; --rintent-radius: 0; }`

Variables win without `!important`, and one line changes every button, field and card at once. Corner style, domain name size and price color also have plain settings, no CSS needed. The full list lives under Settings, Store widgets, Theming reference.

= What happens when I uninstall? =

Nothing is deleted unless you asked for it. Tick the uninstall option in Settings first if you want the table and settings removed.

== Screenshots ==

1. The intent dashboard: six KPI cards with tooltips, search vs cart trend, and every list paging in place.
2. The whole dashboard following one page through the page filter.
3. Repeat Demand, Carted Domains with the cart-size split, and Search by Page on one row.
4. Added Products, Transfer Searches and Outbound Clicks. Each one hides itself until it has something to show.
5. The Shortcodes page: the family-first price builder with a live preview.
6. The TLD price strip builder, with the refresh clock in the card footer.
7. The support number card, previewed with your own timezone.
8. Settings: three cards, everything optional.
9. Browser-style clear data with a preview count before anything is deleted.
10. The WordPress dashboard widget: the week against the week before, and the name people asked for most.

== Shortcodes ==

Each one has a visual builder with a live preview under Reseller Intent, Shortcodes.

`[rintent_tld_strip tlds=".com,.in,.io" theme="dark" more_url="/domains/" more_label="560+ more TLDs"]`

A row of price pills using live prices from your own catalog. `theme` is light or dark; dark sections are also detected on their own.

`[rintent_price family="cpanel" mode="range" before="cPanel from" after="per month"]`

A live price from a product family in your catalog: cheapest, highest or the range across its plans. Build it visually on the Shortcodes page, the family keeps the number correct when prices change. Older `ids=""` embeds keep working unchanged.

`[rintent_phone]`

One phone number as a tap to call link, picked for the visitor's region from the browser timezone. GoDaddy's public numbers are the defaults; your own are never overwritten.

== WP-CLI ==

`wp rintent stats --days=30` prints searches, unique searches, cart clicks, domains added, transfer searches, product adds and the search to cart rate.

`wp rintent export --days=90 --format=csv > events.csv` streams every event as CSV or JSON, in batches.

`wp rintent clear --range=month --yes` deletes events in a window: `hour`, `day`, `week`, `month`, `6months`, `year` or `all`.

`wp rintent refresh-tld` fetches fresh TLD strip prices immediately.

== External services ==

The optional TLD price strip fetches live domain prices from GoDaddy's storefront API at secureserver.net, the platform behind every Reseller Store storefront, so the pills match checkout.

What is sent: your public reseller ID and one static probe domain name per TLD you configured. This happens from your server about twice a day, plus whenever you press refresh, and only while the shortcode is in use. No visitor data is ever sent.

That API is operated by GoDaddy: [Universal Terms of Service](https://www.godaddy.com/legal/agreements/universal-terms-of-service-agreement), [Privacy Policy](https://www.godaddy.com/legal/agreements/privacy-policy). Reseller Intent itself is an independent plugin, built for GoDaddy resellers but not made by, endorsed by or affiliated with GoDaddy.

The default support numbers for the phone shortcode are GoDaddy's published numbers, stored inside the plugin. Showing them involves no external request.

== Upgrade Notice ==

= 2.2.2 =
A visitor no longer waits on price lookups when the strip renders for the very first time, a dead connection costs one timeout instead of one per TLD, and large retention cleanups run in small batches. The dashboard version label reads from one place now.

= 2.2.1 =
Fixes a dashboard rate that could read above 100%, a price strip that lost a TLD's price after one failed lookup, and empty pills for TLDs with no price. The Site Health checks are one entry now.

= 2.2.0 =
New Site Health tests catch failures that used to stay silent, above all a price strip quietly serving stale prices. The dashboard widget was rebuilt and now counts transfers and product adds too. The asset trim setting is gone; it saved under 1% of a page and could break store widgets.

= 2.1.1 =
Fixes the cart and sign in links being missed on sites that place them with Elementor, the asset trim breaking store widgets that live in a builder's header, and the Panels menu offering panels that had nothing to show.

= 2.1.0 =
Transfer searches and product add to cart are tracked now, with a panel each. The style pack reaches the rest of the Reseller Store widgets, corner style and type are plain settings, and store links can open in a new tab.

= 2.0.0 =
The dashboard, Shortcodes and Settings screens are rebuilt, and three low-value panels are gone. The price shortcode is now family-first; every old embed keeps working. Your data is untouched.

== Changelog ==

= 2.2.2 =
* Fixed: the very first render of a price strip could hold the page while prices were fetched.
* Fixed: a dead connection cost one timeout per TLD; the sweep now stops at the first.
* Fixed: the version on the dashboard could disagree with the installed version.
* Changed: retention cleanup deletes in small batches on large tables.
* Changed: every screen counts events the same way.

= 2.2.1 =
* Fixed: the dashboard widget could show a search to cart rate above 100%.
* Fixed: a failed price lookup wiped that TLD's saved price for good.
* Fixed: a TLD with no price drew an empty pill in the price strip.
* Added: Site Health names any TLD it has no price for.
* Changed: the six Site Health checks are one entry now.

= 2.2.0 =
* Added: Site Health now flags the failures that used to stay silent: a TLD price strip serving stale prices, a scheduled job that is not queued, and stored events growing with no retention window.
* Added: the WordPress dashboard widget compares this week with the week before, names the domain or product asked for most, and counts transfer searches and product adds, which it used to ignore.
* Fixed: under a page filter, empty panels claimed nothing was being tracked. They now say the page and the range are the reason.
* Fixed: Search by Page says it keeps comparing all pages while a filter is on, instead of looking like the one panel that broke.
* Removed: the asset trim setting. It saved under 1% of a page, could not run on most storefronts, and a wrong guess silently broke the cart, sign in and click tracking. The `rintent_page_needs_store` filter goes with it.

= 2.1.1 =
* Fixed: with the asset trim on, store elements placed in a page builder's header, footer or popup were invisible to the detection, so its scripts were removed sitewide and the cart count, sign in state and click tracking died.
* Fixed: the cart and sign in links got no styling and no tracking when placed with Elementor's WP Widget element. Reseller Store's own inner classes are matched now, so every placement is covered.
* Fixed: the Panels menu offered Added Products, Transfer Searches, Outbound Clicks and Top Countries on sites with no data for them. They read "nothing yet" until there is something to show.

= 2.1.0 =
* Added: View cart, Sign in and the support number are tracked, in one Outbound Clicks panel.
* Added: transfer searches and product Add to cart are tracked, with a panel each. Both are sent with sendBeacon so they survive the jump to GoDaddy.
* Added: the Recent Searches log carries transfer searches, marked Transfer beside Available and Registered.
* Added: the style pack now covers the simple search, the transfer box, Add to cart, the cart, the sign in link, product pods and product pages.
* Added: corner style, domain name size and price color are plain settings, no CSS needed.
* Added: an option to open store links in a new tab. Off by default.
* Changed: `wp rintent stats` reports the new event types alongside the existing counts.
* Fixed: the page filter could not reach a page that only saw a product added or a domain transferred. It now lists every page with any tracked event.
* Fixed: on right-to-left sites the search bar reversed but its corners did not. Corners are logical now.
* Fixed: the cart widget sat flush against the left edge of the page instead of in the content column.
* Fixed: the simple search and transfer selectors outweighed the ones the theming reference documents, so your own CSS could not win. They match now.
* Fixed: with a Dark accent color set, buttons in dark sections drew their labels in dark ink instead of white.
* Fixed: a product family whose plans differ mid-name was labelled by one shared word, so two SSL services both read "Managed".

= 2.0.0 =
* Added: filter the whole dashboard by the page a search happened on.
* Added: a live preview blueprint that seeds widget pages and six months of sample data.
* Changed: the dashboard is rebuilt on a fixed grid. Every list panel is the same machine, ten rows per page with a pager in the footer.
* Changed: the dashboard runs its own fixed palette. Your accent color still styles the search widget and the price strip.
* Changed: KPI cards renamed to say what they count, each with a tooltip. Repeat Searches, Domains Sent to Cart and Avg per Cart Click replace the old names.
* Changed: the price shortcode is family-first, so the number stays correct when prices change. Old `ids=""` embeds keep working.
* Changed: Settings slimmed to four cards. Retention is a plain day count, 0 keeps everything forever.
* Removed: the Missed Opportunities, Conversion Funnel and Selection Behavior panels. The cart-size split lives on inside Carted Domains.
* Removed: the bot-tracking option. Bots are never recorded; developers can opt back in with `rintent_track_bots`.
* Fixed: tables, tooltips and delta badges render correctly in right-to-left admin languages.

Earlier versions are documented in the [GitHub repository](https://github.com/ekamran/reseller-intent/releases).
