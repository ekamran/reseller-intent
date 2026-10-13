#!/bin/bash
# Checks against a running wp-env (npx @wordpress/env start): both plugins
# active, the demo data seeds, every ability and CLI report answers, the
# dashboard renders, activation survives a second run, and nothing from the
# plugin reached debug.log. Exit 1 on the first problem.
set -euo pipefail
cd "$(dirname "$0")/../.."
wpe() { npx --yes @wordpress/env@latest run cli wp "$@"; }
P="wp-content/plugins/reseller-intent/tests/wp"

echo "== WordPress $(wpe core version) on PHP $(wpe eval 'echo PHP_VERSION;')"
wpe plugin list --fields=name,status,version --format=csv
wpe plugin is-active reseller-store
wpe plugin is-active reseller-intent

echo "== seed"
wpe eval-file "$P/seed-events.php" --user=admin | grep SEEDED

echo "== abilities"
wpe eval-file "$P/abilities-check.php" --user=admin | tee /tmp/rintent-abilities.txt
if grep -q 'CHECK FAIL' /tmp/rintent-abilities.txt; then echo "abilities check failed"; exit 1; fi
grep -q 'CHECK ok' /tmp/rintent-abilities.txt

echo "== CLI"
wpe rintent report summary --range=30 | head -8
wpe rintent report tlds --range=all --limit=5
wpe rintent report pages --range=30
wpe rintent abilities
wpe rintent status

echo "== dashboard payload"
wpe eval-file "$P/dump-dashboard.php" --user=admin > /tmp/rintent-dashboard.json
test "$(wc -c < /tmp/rintent-dashboard.json)" -gt 10000
php -r '$j = json_decode(file_get_contents("/tmp/rintent-dashboard.json"), true); if (!is_array($j) || !isset($j["30|||"]["kpis"])) { fwrite(STDERR, "dashboard payload is not the expected JSON\n"); exit(1); } echo "dashboard payload ok, ", count($j), " windows\n";'

echo "== re-activation"
wpe plugin deactivate reseller-intent
wpe plugin activate reseller-intent

echo "== debug.log"
LOG="$(wpe eval 'echo file_exists( WP_CONTENT_DIR . "/debug.log" ) ? file_get_contents( WP_CONTENT_DIR . "/debug.log" ) : "";')"
if printf '%s' "$LOG" | grep -i "reseller-intent\|rintent" | grep -qi "fatal\|warning\|notice\|deprecated"; then
	echo "the plugin wrote to debug.log:"; printf '%s\n' "$LOG" | grep -i "reseller-intent\|rintent" | head -10; exit 1
fi
echo "nothing from the plugin in debug.log"
