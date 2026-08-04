# Reseller Intent

[![CI](https://github.com/ekamran/reseller-intent/actions/workflows/ci.yml/badge.svg)](https://github.com/ekamran/reseller-intent/actions/workflows/ci.yml)
[![WordPress plugin](https://img.shields.io/wordpress/plugin/v/reseller-intent?label=wordpress.org)](https://wordpress.org/plugins/reseller-intent/)
[![Downloads](https://img.shields.io/wordpress/plugin/dt/reseller-intent)](https://wordpress.org/plugins/reseller-intent/)
[![Tested up to](https://img.shields.io/wordpress/plugin/tested/reseller-intent)](https://wordpress.org/plugins/reseller-intent/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

See which domains visitors search on your reseller storefront, which ones they
pick, and which ones they take to cart.

An add-on for the free [Reseller Store](https://wordpress.org/plugins/reseller-store/)
plugin by GoDaddy Reseller Programs. Reseller Intent is an independent plugin,
built for GoDaddy resellers but not made by, endorsed by or affiliated with
GoDaddy.

**On wordpress.org:** <https://wordpress.org/plugins/reseller-intent/>

## What it does

Your analytics tell you about pages and traffic. They tell you nothing about
the search box that actually sells. This records what happens in it, and in
every other Reseller Store element on the page:

- Domain searches, whether the name was free, what got picked instead, and what
  reached the cart
- Transfer searches and products added to cart, so hosting, email and SSL
  demand sits next to the domains
- The three links that hand a visitor on: View cart, Sign in, and the support
  number
- An optional style pack, so the store widgets match your site instead of the
  theme's defaults
- Three shortcodes: TLD price strip, live product price, regional support number

Everything is written to one table in your own database. No cookies, no
fingerprinting, no IP addresses, no third party.

## Requirements

| | |
|---|---|
| WordPress | 6.2 or newer |
| PHP | 7.4 or newer |
| Reseller Store | 2.2.17 or newer |

## Development

The plugin has no build step. Clone it into `wp-content/plugins/` and activate.

Coding standards are the WordPress ones, with the project's own exclusions in
`phpcs.xml.dist`:

```bash
phpcs --standard=phpcs.xml.dist
```

CI runs PHP syntax on 7.4 and 8.4, the coding standards, JavaScript parsing,
a Playground blueprint check, and the WordPress Plugin Check on every pull
request.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
