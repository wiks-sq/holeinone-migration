#!/usr/bin/env bash
# Smoke test on WordPress Playground: WordPress on SQLite, PHP in WebAssembly,
# as the plugin reviewers try plugins. Needs Node.js (24 or newer recommended).
#
#   tests/playground/run.sh
#
# There $wpdb has no mysqli link, so HOLEMI_DB's $wpdb fallback runs every
# query. test.php exports the database, imports it back as if it came from
# another address, and checks the rewritten content (with "%" and "_"). SQLite
# cannot rename tables, so on Playground the import stops at the database swap
# and is cancelled; the test checks that the live site was left untouched.
#
# Environment: PLAYGROUND_CLI (@wp-playground/cli version, default below),
# WP (WordPress version, default latest), PHP (PHP version, default Playground's).
set -uo pipefail
cd "$(dirname "$0")"
HERE=$PWD
PLUGIN=$(cd ../../hole-in-one-migration && pwd)
OUT=$(mktemp -d)
trap 'rm -rf "$OUT"' EXIT
args=(--blueprint="$HERE/blueprint.json"
  --mount="$PLUGIN:/wordpress/wp-content/plugins/hole-in-one-migration"
  --mount="$HERE:/e2e" --mount="$OUT:/out")
[ -n "${WP:-}" ] && args+=(--wp="$WP")
[ -n "${PHP:-}" ] && args+=(--php="$PHP")
echo "== WordPress Playground (@wp-playground/cli@${PLAYGROUND_CLI:-3.1.66})"
npx -y "@wp-playground/cli@${PLAYGROUND_CLI:-3.1.66}" run-blueprint "${args[@]}" 2>&1 \
  | grep -v -e 'EBADENGINE' -e '^npm warn' | tail -20
if [ ! -s "$OUT/results.txt" ]; then
  echo "FAIL  the test wrote no results"; echo; echo "SOME TESTS FAILED"; exit 1
fi
cat "$OUT/results.txt"
echo
if grep -q '^FAIL' "$OUT/results.txt" || ! grep -q '^PASS' "$OUT/results.txt"; then
  echo "SOME TESTS FAILED"; exit 1
fi
echo "ALL TESTS PASSED"
