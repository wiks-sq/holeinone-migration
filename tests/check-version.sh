#!/usr/bin/env bash
# The version must be the same everywhere WordPress and the plugin read it:
#   plugin header "Version:", HOLEMI_VERSION, readme.txt "Stable tag:",
#   and readme.txt must have a changelog entry "= <version> =".
# With an argument (a release tag such as v1.1.0 or 1.1.0) it must match too.
set -euo pipefail
cd "$(dirname "$0")/../hole-in-one-migration"
header=$(sed -n 's/^ \* Version: *//p' hole-in-one-migration.php | tr -d '\r ')
const=$(sed -n "s/^define( *'HOLEMI_VERSION', *'\([^']*\)'.*/\1/p" hole-in-one-migration.php)
stable=$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '\r ')
fail=0
echo "plugin header: $header, HOLEMI_VERSION: $const, readme Stable tag: $stable"
[ -n "$header" ] || { echo "ERROR: no Version: in the plugin header"; fail=1; }
[ "$header" = "$const" ] || { echo "ERROR: HOLEMI_VERSION ($const) differs from the header ($header)"; fail=1; }
[ "$header" = "$stable" ] || { echo "ERROR: readme.txt Stable tag ($stable) differs from the header ($header)"; fail=1; }
grep -q "^= $header =" readme.txt || { echo "ERROR: readme.txt has no changelog entry \"= $header =\""; fail=1; }
if [ $# -gt 0 ]; then
  tag=${1#v}
  [ "$tag" = "$header" ] || { echo "ERROR: release tag $1 does not match the plugin version $header"; fail=1; }
fi
[ $fail = 0 ] && echo "OK: version $header"
exit $fail
