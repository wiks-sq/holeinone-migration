#!/usr/bin/env bash
# Builds hole-in-one-migration.zip (the file uploaded to WordPress.org) from the
# committed plugin folder, so local leftovers never end up in a release.
#   bin/build-zip.sh [output.zip]     default: ./hole-in-one-migration.zip
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=${1:-$ROOT/hole-in-one-migration.zip}
case $OUT in /*) ;; *) OUT=$PWD/$OUT;; esac
rm -f "$OUT"
git -C "$ROOT" archive --format=zip -o "$OUT" HEAD hole-in-one-migration
if [ -n "$(git -C "$ROOT" status --porcelain -- hole-in-one-migration)" ]; then
  echo "WARNING: hole-in-one-migration/ has uncommitted changes; they are NOT in the zip." >&2
fi
echo "$OUT ($(unzip -l "$OUT" | tail -1 | awk '{print $2}') files, version $(unzip -p "$OUT" hole-in-one-migration/hole-in-one-migration.php | sed -n 's/^ \* Version: *//p'))"
