#!/bin/bash
# The .min siblings are committed. This rebuilds them into a temporary
# directory and fails when a committed copy differs, so a change to a
# readable file cannot ship without its minified twin.
#
#   bash tests/build/check-minified.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT" || exit 1
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

MINIFY_OUT="$TMP" node tests/build/minify.js >/dev/null || { echo "FAIL  minify.js did not run (npm ci first?)"; exit 1; }

fail=0
for f in assets/js/tracker.min.js assets/js/widget.min.js assets/js/phone.min.js assets/css/widget.min.css; do
	if cmp -s "$TMP/$f" "$f"; then
		echo " ok   $f is fresh"
	else
		echo "FAIL  $f is stale, run: npm run minify"
		fail=1
	fi
done
exit $fail
