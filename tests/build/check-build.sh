#!/bin/bash
# Build the release zip exactly as wordpress.org users get it (git archive,
# which honours the export-ignore rules) and check what is in it and what
# is not, plus the version strings that have to agree before a release.
#
#   tests/build/check-build.sh            builds HEAD into build/
#   tests/build/check-build.sh 2.4.1      also requires that version everywhere
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT" || exit 1
OUT="$ROOT/build"
mkdir -p "$OUT"
rm -rf "$OUT/reseller-intent" "$OUT/reseller-intent.zip"
git archive --format=zip --prefix=reseller-intent/ -o "$OUT/reseller-intent.zip" HEAD || exit 1
unzip -q "$OUT/reseller-intent.zip" -d "$OUT" || exit 1

fail=0
ok()  { echo " ok   $1"; }
bad() { echo "FAIL  $1"; fail=1; }

for f in reseller-intent.php readme.txt uninstall.php LICENSE includes/class-reseller-intent.php includes/class-reseller-intent-reports.php includes/class-reseller-intent-abilities.php assets/js/tracker.js languages/reseller-intent.pot blueprints/blueprint.json; do
	[ -f "$OUT/reseller-intent/$f" ] && ok "ships $f" || bad "missing $f"
done
for f in .git .github .gitattributes .gitignore .distignore README.md phpcs.xml.dist phpcompat.xml.dist tests package.json package-lock.json node_modules .wp-env.json .wordpress-org build; do
	[ -e "$OUT/reseller-intent/$f" ] && bad "ships $f" || ok "keeps out $f"
done

V_PLUGIN="$(grep -m1 '^ \* Version:' reseller-intent.php | sed 's/.*Version: *//' | tr -d '[:space:]')"
V_STABLE="$(grep -m1 '^Stable tag:' readme.txt | sed 's/.*: *//' | tr -d '[:space:]')"
[ -n "$V_PLUGIN" ] && [ "$V_PLUGIN" = "$V_STABLE" ] && ok "plugin header $V_PLUGIN = readme stable tag" || bad "plugin header '$V_PLUGIN' vs stable tag '$V_STABLE'"
grep -q "^= $V_PLUGIN =" readme.txt && ok "changelog has $V_PLUGIN" || bad "changelog lacks $V_PLUGIN"
grep -m1 '^"Project-Id-Version:' languages/reseller-intent.pot | grep -qF " $V_PLUGIN\\n" && ok "POT is $V_PLUGIN" || bad "POT version is not $V_PLUGIN"
if [ -n "${1:-}" ]; then
	[ "${1#v}" = "$V_PLUGIN" ] && ok "tag $1 matches the version" || bad "tag $1 vs version $V_PLUGIN"
fi
grep -q "—" readme.txt && bad "em-dash in readme.txt" || ok "no em-dash in readme.txt"
grep -q '^ \* Requires Plugins: reseller-store' reseller-intent.php && ok "declares Requires Plugins: reseller-store" || bad "Requires Plugins header missing"
grep -q '^Requires at least: 6.2' readme.txt && grep -q '^Requires PHP: 7.4' readme.txt && ok "floors unchanged (WP 6.2, PHP 7.4)" || bad "readme floors changed"

lint_fail=0
while IFS= read -r -d '' f; do php -l "$f" >/dev/null 2>&1 || { echo "      php -l: $f"; lint_fail=1; }; done < <(find "$OUT/reseller-intent" -name '*.php' -print0)
[ "$lint_fail" = 0 ] && ok "every shipped PHP file lints on PHP $(php -r 'echo PHP_VERSION;')" || bad "shipped PHP fails to lint"
find "$OUT/reseller-intent" -name '.*' -print | grep -q . && bad "dot-files inside the zip" || ok "no dot-files inside the zip"

echo "zip: $OUT/reseller-intent.zip, $(unzip -l "$OUT/reseller-intent.zip" | tail -1 | awk '{print $2}') files, $(du -h "$OUT/reseller-intent.zip" | cut -f1)"
exit $fail
