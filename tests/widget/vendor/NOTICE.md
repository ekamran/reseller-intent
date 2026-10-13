# Third-party files used only by the widget test

- `jquery-3.7.1.min.js`: jQuery 3.7.1, MIT license, https://jquery.com/
- `reseller-store-3.0.1-domain-search.min.js`: the domain search widget shipped in Reseller Store 3.0.1 by GoDaddy Reseller Programs, GPL-2.0-or-later, https://github.com/godaddy/wp-reseller-store (assets/js/domain-search.min.js at the 3.0.1 release)
- `reseller-store-3.0.2-pr214-domain-search.min.js`: the same file from GoDaddy's pull request 214 (branch fix/multi-domain-cart-add, commit 895d708), the Continue to cart change expected in Reseller Store 3.0.2

None of this ships in the plugin zip. The tests directory is export-ignored.
