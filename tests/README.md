# Tests

Nothing in this directory ships. It is export-ignored, so the release zip
and the wordpress.org deploy never contain it. CI runs all of it on every
push and pull request (.github/workflows/ci.yml); this is how to run the
same things on a laptop.

## unit/harness.php

Standalone PHP checks against the real class files with WordPress stubbed:
the TLD price strip, Site Health, the activation notice, the cart reset
guards, the abilities (shape, schemas, permissions, error codes), the CLI
map, and structural guards (no SQL outside the Reports class, no MySQL-only
functions). No WordPress, no database, no network.

    php tests/unit/harness.php

Runs on PHP 7.4 through 8.4 in CI.

## build/check-build.sh

Builds the release zip with `git archive` (what wordpress.org users get)
and checks what is inside, what is kept out, and that the plugin header,
readme stable tag, changelog and POT agree. With a version argument it also
requires that version, which is what the release workflow does with the tag.

    bash tests/build/check-build.sh
    bash tests/build/check-build.sh 2.4.1

## playground/

The plugin on WordPress Playground, which is SQLite: the wordpress.org Live
Preview runs there. `pg-build.php` takes the demo seeder out of the shipped
blueprint and adds `pg-verify.php`, which registers and executes every
ability through core, compares them with the dashboard, and exercises the
CLI. Needs Node.

    php tests/playground/pg-build.php
    mkdir -p tests/playground/out
    npx @wp-playground/cli@latest run-blueprint \
      --blueprint=tests/playground/pg-blueprint.json --php=8.2 --wp=latest \
      --mount="$PWD:/wordpress/wp-content/plugins/reseller-intent" \
      --mount="$PWD/tests/playground/out:/wordpress/pg-out"
    cat tests/playground/out/result.txt

## wp/ with wp-env (MySQL)

A real WordPress with Reseller Store from wordpress.org and this checkout
as the plugin. `.wp-env.json` at the repository root describes it; drop a
`.wp-env.override.json` with `{ "core": "WordPress/WordPress#6.2", "phpVersion": "8.0" }`
to test the floor. Needs Docker and Node.

    npx @wordpress/env start
    bash tests/wp/run-checks.sh
    npx @wordpress/env destroy

`seed-events.php` writes 360 deterministic events, `abilities-check.php`
executes every ability through core and compares it with the dashboard,
`dump-dashboard.php` renders every dashboard window to JSON.

## widget/continue-click-check.js

The tracker against the real Reseller Store domain search widget, both the
3.0.1 build and the Continue to cart change from GoDaddy's pull request 214
(the files are pinned in `widget/vendor/`, see the NOTICE there). The page
is served through request interception with real footer script tags, so the
DOMContentLoaded order is the one a WordPress page has. Needs Node.

    npm ci
    npx puppeteer browsers install chrome
    node tests/widget/continue-click-check.js

With a local Chrome instead of the bundled one:

    CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" node tests/widget/continue-click-check.js

`TRACKER_JS=/path/to/old/tracker.js` runs the same checks against another
tracker build, which is how a regression is shown before it is fixed.
