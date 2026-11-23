#!/usr/bin/env bash
# End-to-end test of the plugin in throwaway Docker containers. Used by GitHub
# Actions and runnable locally (needs Docker and git):
#
#   tests/e2e/run.sh                 seeded test site (no private data needed)
#   tests/e2e/run.sh site.tar.gz     a real site archive instead (made by any version)
#
#  1. WordPress.org Plugin Check on a clean WordPress          (skip: PLUGIN_CHECK=0)
#  2. A site built by seed.php is exported with plugin 1.0.2 (from git history,
#     OLD_REF) and imported into site a with this version. With an archive
#     argument, that archive is imported instead.
#  3. Site a is exported with this version; the import into site b is first
#     cancelled just before the database swap (b must be unchanged), then run fully;
#     tables, row counts, every file and the addresses are compared.
#  4. Media moved outside wp-content on site a arrive in b's own uploads folder.
#
# Environment: WP_IMAGE (default php8.5-apache), CLI_IMAGE (default cli),
# PLUGIN_CHECK (1), OLD_REF (c1984fc = 1.0.2), E2E_LOG (tests/e2e/e2e-results.log).
# On failure, debug logs and job logs are kept in tests/e2e/artifacts/.
# Exit code: 0 when every check passed.
set -uo pipefail
LOG=${E2E_LOG:-}; [ -n "$LOG" ] && case $LOG in /*) ;; *) LOG=$PWD/$LOG;; esac
cd "$(dirname "$0")"
HERE=$PWD
REPO=$(cd ../.. && pwd)
ARCHIVE=${1:-}
OLD_REF=${OLD_REF:-c1984fc}
export E2E_TMP=$HERE/.e2e-tmp
TMP=$E2E_TMP
ART=$HERE/artifacts
exec > >(tee "${LOG:-$HERE/e2e-results.log}") 2>&1
dt() { docker compose -f "$HERE/docker-compose.yml" "$@"; }
wps() { local s=$1; shift; dt exec -T "$s-cli" wp --skip-plugins --skip-themes "$@"; }
FAIL=0
pass() { echo "PASS  $*"; }
fail() { echo "FAIL  $*"; FAIL=1; }
SITES="old a b"
port_of() { case $1 in pc) echo 8082;; old) echo 8083;; a) echo 8084;; b) echo 8085;; esac; }

collect() { # keep logs for the CI artifact
  rm -rf "$ART"; mkdir -p "$ART"
  for s in $SITES; do
    dt exec -T $s-cli sh -c 'cat wp-content/debug.log 2>/dev/null' > "$ART/debug-$s.log" 2>/dev/null
    dt exec -T $s-cli sh -c 'for f in wp-content/hole-in-one-migration-*/work/*/log.txt wp-content/hio-migration/work/*/log.txt; do [ -f "$f" ] && { echo "== $f"; tail -n 300 "$f"; }; done' > "$ART/jobs-$s.log" 2>/dev/null
  done
}
cleanup() {
  [ $FAIL = 0 ] || collect
  echo "== Removing the test containers"
  dt down -v >/dev/null 2>&1; rm -rf "$TMP"
}
trap cleanup EXIT
end() { echo; [ $FAIL = 0 ] && echo "ALL TESTS PASSED" || echo "SOME TESTS FAILED"; exit $FAIL; }
abort() { fail "$*"; end; }

# Front page: redirects on the same site are followed (a translation plugin may
# send / to /fi/); the final page must answer 200 on the site's own address.
front() { # site
  local p r code path; p=$(port_of "$1")
  r=$(dt exec -T "$1" sh -c "u=/; for i in 1 2 3 4 5; do out=\$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: localhost:$p' http://localhost\$u); c=\${out%% *}; loc=\${out#* }; case \$c in 30?) case \$loc in http://localhost:$p/*) u=\${loc#http://localhost:$p};; *) echo \"\$c \$loc\"; exit;; esac;; *) echo \"\$c \$u\"; exit;; esac; done")
  code=${r%% *}; path=${r#* }
  [ "$code" = 200 ] && pass "site $1 front page answers 200 ($path)" || fail "site $1 front page answers $r"
}

run_job() { # site job
  local site=$1 job=$2 retry="" out phase status pct msg n=0 errs=0 t0=$SECONDS
  while :; do
    n=$((n+1))
    if out=$(wps "$site" eval-file /e2e/cli-step.php step "$job" $retry 2>&1 | tail -1); then retry=""; else retry=1; fi
    read -r phase status pct msg <<<"$out" || true
    [ "$phase" = done ] && break
    if [ "$status" = error ]; then
      echo "   step error: $msg"; retry=1; errs=$((errs+1))
      [ $errs -ge 5 ] && { fail "job on site $site keeps failing: $msg"; return 1; }
    else errs=0; fi
    [ $n -gt 3000 ] && { fail "job $job on $site did not finish"; return 1; }
  done
  echo "   finished in $n steps, $((SECONDS-t0)) s"
}
# Count occurrences of a string in every table (dry run, changes nothing).
count_str() { wps "$1" search-replace "$2" 'e2e-dry-run-placeholder' --all-tables --dry-run --precise --format=count 2>&1 | tail -1; }
# Posts whose content holds the JSON-escaped address http:\/\/localhost:<port>.
esc_count() { wps "$1" eval '$n = 0; $needle = "http:" . chr( 92 ) . "/" . chr( 92 ) . "/localhost:'"$2"'"; foreach ( get_posts( array( "numberposts" => -1, "post_type" => "any" ) ) as $p ) { $n += false !== strpos( $p->post_content, $needle ) ? 1 : 0; } echo $n;'; }
SUMS='cd wp-content && find . -type f ! -path "./hole-in-one-migration-*" ! -path "./hio-migration/*" ! -path "./plugins/hole-in-one-migration/*" ! -path "./plugins/holeinone-migration/*" ! -name debug.log -exec md5sum {} + | sort -k2'
TABLES='global $wpdb; foreach ( $wpdb->get_col( "SHOW TABLES" ) as $t ) { echo $t, " ", $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ), "\n"; }'

rm -rf "$TMP" "$ART"; mkdir -p "$TMP/old-plugin"
if [ -n "$ARCHIVE" ]; then
  [ -f "$ARCHIVE" ] || { echo "Archive not found: $ARCHIVE"; exit 1; }
  ARCHIVE=$(cd "$(dirname "$ARCHIVE")" && pwd)/$(basename "$ARCHIVE")
  SITES="a b"
fi
# Plugin 1.0.2 from git history (the old site needs its folder even when unused).
git -C "$REPO" archive "$OLD_REF" holeinone-migration | tar -x -C "$TMP/old-plugin" \
  || { echo "Could not read plugin $OLD_REF from git (in CI: checkout with fetch-depth: 0)"; exit 1; }
[ "${PLUGIN_CHECK:-1}" = 1 ] && SVC="pc pc-cli" || SVC=""
for s in $SITES; do SVC="$SVC $s $s-cli"; done

echo "== Starting test containers (wordpress:${WP_IMAGE:-php8.5-apache}, wordpress:${CLI_IMAGE:-cli})"
dt up -d --quiet-pull db $SVC >/dev/null 2>&1 || { dt up -d db $SVC; exit 1; }
INSTALL="$SITES"; [ "${PLUGIN_CHECK:-1}" = 1 ] && INSTALL="pc $INSTALL"
for s in $INSTALL; do
  for i in $(seq 1 90); do
    dt exec -T $s-cli php -r '$d = getenv("WORDPRESS_DB_NAME"); try { $c = file_exists("wp-config.php") ? mysqli_connect("db", "root", "root") : false; } catch (Throwable $e) { $c = false; } exit($c && mysqli_query($c, "CREATE DATABASE IF NOT EXISTS `$d` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") ? 0 : 1);' 2>/dev/null && break
    [ $i = 90 ] && { dt ps -a; dt logs --tail=30 db $s; exit 1; }
    sleep 2
  done
  wps $s core install --url=http://localhost:$(port_of $s) --title="e2e $s" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email >/dev/null || abort "WordPress install failed on $s"
done
for s in $SITES; do
  if [ $s = old ]; then wps old plugin activate holeinone-migration >/dev/null
  else wps $s plugin activate hole-in-one-migration >/dev/null; fi || abort "plugin activation failed on $s"
done
echo "   WordPress $(wps a core version), PHP $(dt exec -T a-cli php -r 'echo PHP_VERSION;') (CLI) / $(dt exec -T a php -r 'echo PHP_VERSION;') (web), plugin $(wps a plugin get hole-in-one-migration --field=version)"

if [ "${PLUGIN_CHECK:-1}" = 1 ]; then
  echo "== 1. Plugin Check"
  if dt exec -T pc-cli wp plugin install plugin-check --activate >/dev/null 2>&1; then
    dt exec -T pc-cli wp plugin check hole-in-one-migration --format=csv \
      --require=./wp-content/plugins/plugin-check/cli.php > "$TMP/pc.csv" 2>&1
    cat "$TMP/pc.csv"
    e=$(grep -c ',ERROR,' "$TMP/pc.csv"); w=$(grep -c ',WARNING,' "$TMP/pc.csv")
    [ "$e" = 0 ] && [ "$w" = 0 ] && pass "Plugin Check: $e errors, $w warnings" || fail "Plugin Check: $e errors, $w warnings"
  else fail "could not install Plugin Check"; fi
fi

IMPA=$(wps a eval-file /e2e/cli-step.php dir import | tail -1)
[[ "$IMPA" =~ /wp-content/hole-in-one-migration-[a-f0-9]{24}/import$ ]] && pass "storage folder has a random name" || fail "unexpected storage folder: $IMPA"
if [ -z "$ARCHIVE" ]; then
  echo "== 2. Seeded site, exported with plugin 1.0.2, imported into site a"
  wps old eval-file /e2e/seed.php || abort "seeding the old site failed"
  v=$(esc_count old 8083)
  [ "$v" -ge 100 ] 2>/dev/null && pass "control: seeded site has JSON-escaped addresses in $v posts" || fail "control: seeded site has JSON-escaped addresses in $v posts"
  JOB=$(wps old eval-file /e2e/cli-step.php export both tar.gz | tail -1)
  run_job old "$JOB" || end
  OLDEXP=$(wps old eval-file /e2e/cli-step.php result "$JOB" | tail -1)
  OLDBAK=$(wps old eval-file /e2e/cli-step.php dir backups | tail -1)
  dt cp "old-cli:$OLDBAK/$OLDEXP" "$TMP/" >/dev/null 2>&1
  tar -xzOf "$TMP/$OLDEXP" hio-manifest.json | grep -q '"plugin_version": *"1.0.2"' && pass "archive was made by plugin 1.0.2" || fail "archive was not made by 1.0.2"
  dt exec -T old-cli sh -c "$SUMS" > "$TMP/files-old"
  NAME=$OLDEXP
else
  echo "== 2. Archive $(basename "$ARCHIVE") imported into site a"
  cp "$ARCHIVE" "$TMP/"; NAME=$(basename "$ARCHIVE")
fi
dt cp "$TMP/$NAME" "a-cli:$IMPA/$NAME" >/dev/null 2>&1
JOB=$(wps a eval-file /e2e/cli-step.php start "$NAME" | tail -1)
run_job a "$JOB" || end
if [ -z "$ARCHIVE" ]; then
  dt exec -T a-cli sh -c "$SUMS" > "$TMP/files-a0"
  diff -q "$TMP/files-old" "$TMP/files-a0" >/dev/null && pass "all $(wc -l < "$TMP/files-old") files of the old site arrived in a" || { fail "files differ between old site and a:"; diff "$TMP/files-old" "$TMP/files-a0" | head -20; }
  n=$(count_str a localhost:8083)
  [ "$n" -le 1 ] 2>/dev/null && pass "no address of the old site left in a (found $n, the import notice)" || fail "old site address found $n times in a"
fi

echo "== 3. Export site a with this version"
JOB=$(wps a eval-file /e2e/cli-step.php export both tar.gz | tail -1)
run_job a "$JOB" || end
EXP=$(wps a eval-file /e2e/cli-step.php result "$JOB" | tail -1)
BAKA=$(wps a eval-file /e2e/cli-step.php dir backups | tail -1)
dt cp "a-cli:$BAKA/$EXP" "$TMP/" >/dev/null 2>&1
code=$(dt exec -T a curl -s -o /dev/null -w '%{http_code}' -H "Host: localhost:8084" "http://localhost${BAKA#/var/www/html}/$EXP")
[ "$code" = 403 ] && pass "archive cannot be downloaded directly over HTTP ($code)" || fail "direct HTTP download of the archive answers $code"
code=$(dt exec -T a curl -s -o /dev/null -w '%{http_code}' -H "Host: localhost:8084" "http://localhost${BAKA#/var/www/html}/")
[ "$code" = 403 ] && pass "backups folder cannot be listed over HTTP ($code)" || fail "backups folder over HTTP answers $code"
echo "   size: $(wc -c < "$TMP/$EXP") bytes"
tar -tzf "$TMP/$EXP" >/dev/null && pass "export is a valid tar.gz" || fail "export is not a valid tar.gz"
tar -xzOf "$TMP/$EXP" hio-manifest.json | grep -q '"generator": *"holeinone-migration"' && pass "export keeps the archive id older versions check" || fail "export changed the archive id (older versions would refuse it)"
tar -tzf "$TMP/$EXP" | grep -q 'plugins/hole-in-one-migration/' && fail "export contains the plugin itself" || pass "export leaves out the plugin itself"
IMPB=$(wps b eval-file /e2e/cli-step.php dir import | tail -1)
dt cp "$TMP/$EXP" "b-cli:$IMPB/$EXP" >/dev/null 2>&1

echo "== 3a. Cancel an import after its files were moved into place: site b must be unchanged"
dt exec -T b-cli sh -c "$SUMS" > "$TMP/b-before"; wps b eval "$TABLES" | sort > "$TMP/b-tables-before"
JOB=$(wps b eval-file /e2e/cli-step.php start "$EXP" | tail -1)
n=0; saw_mu=0; phase=""
while :; do
  out=$(wps b eval-file /e2e/cli-step.php step "$JOB" 2>&1 | tail -1); phase=${out%% *}; n=$((n+1))
  dt exec -T b-cli sh -c 'ls wp-content/mu-plugins 2>/dev/null' | grep -qi 'guard\|holemi\|e2e-mu' && saw_mu=1
  [ "$phase" = swap ] && break
  [ "$phase" = done ] && { fail "import finished before it could be cancelled"; break; }
  [ $n -gt 3000 ] && { fail "import did not reach the swap phase"; break; }
done
echo "   reached phase $phase after $n steps"
[ $saw_mu = 0 ] && pass "nothing written into mu-plugins before the swap" || fail "a file appeared in mu-plugins before the swap"
wps b eval-file /e2e/cli-step.php cancel "$JOB" >/dev/null
dt exec -T b-cli sh -c "$SUMS" > "$TMP/b-after"; wps b eval "$TABLES" | sort > "$TMP/b-tables-after"
diff -q "$TMP/b-before" "$TMP/b-after" >/dev/null && pass "cancel restored every file of site b ($(wc -l < "$TMP/b-after") files)" || { fail "files of site b changed after cancel:"; diff "$TMP/b-before" "$TMP/b-after" | head -20; }
diff -q "$TMP/b-tables-before" "$TMP/b-tables-after" >/dev/null && pass "cancel left the database of site b unchanged" || { fail "tables of site b changed after cancel:"; diff "$TMP/b-tables-before" "$TMP/b-tables-after" | head; }
[ "$(wps b option get home)" = http://localhost:8085 ] && pass "site b still has its own address" || fail "site b address changed"

echo "== 3b. Import it into site b"
JOB=$(wps b eval-file /e2e/cli-step.php start "$EXP" | tail -1)
run_job b "$JOB" || end

echo "== Checks"
for s in a b; do
  ap=$(wps $s option get active_plugins --format=json)
  echo "$ap" | grep -q '"hole-in-one-migration\\/hole-in-one-migration.php"' && pass "site $s: plugin active under its new name" || fail "site $s: plugin not active under its new name: $ap"
  echo "$ap" | grep -q 'holeinone-migration' && fail "site $s: old plugin name still listed" || pass "site $s: old plugin name removed"
  [ "$(wps $s option get home)" = "http://localhost:$(port_of $s)" ] && pass "site $s address is http://localhost:$(port_of $s)" || fail "site $s address is $(wps $s option get home)"
done
na=$(count_str a localhost:8084); nb=$(count_str b localhost:8084)
[[ "$na" =~ ^[0-9]+$ ]] && [ "$na" -gt 0 ] && pass "control: the address check finds localhost:8084 $na times in site a" || fail "control: the address check does not work on site a: $na"
# One match is expected: the plugin's own "Content was moved from <old address>" notice.
if wps b option get holemi_post_import --format=json 2>/dev/null | grep -q 'localhost:8084'; then exp=1; else exp=0; fi
[ "$nb" = "$exp" ] && pass "no old address (localhost:8084) left in site b, apart from the import notice" || fail "old address found in site b: $nb times (expected $exp, from the import notice)"

wps a eval "$TABLES" | sort > "$TMP/rows-a"; wps b eval "$TABLES" | sort > "$TMP/rows-b"
echo "   site a: $(wc -l < "$TMP/rows-a") tables, $(awk '{s+=$2} END {print s}' "$TMP/rows-a") rows"
diff "$TMP/rows-a" "$TMP/rows-b" > "$TMP/rows-diff" && pass "same tables and row counts in a and b" || { fail "table/row counts differ (a < > b):"; cat "$TMP/rows-diff"; }
dt exec -T a-cli sh -c "$SUMS" > "$TMP/files-a"; dt exec -T b-cli sh -c "$SUMS" > "$TMP/files-b"
diff "$TMP/files-a" "$TMP/files-b" > "$TMP/files-diff" && pass "all $(wc -l < "$TMP/files-a") files identical in a and b" || { fail "files differ (a < > b), first 30:"; head -30 "$TMP/files-diff"; }

if [ -z "$ARCHIVE" ]; then
  # Seeded content survived both migrations (old -> a with 1.0.2, a -> b with this version).
  v=$(wps b eval '$o = get_option( "e2e_object" ); echo is_object( $o ) ? $o->url : "BROKEN";')
  [ "$v" = http://localhost:8085/object ] && pass "serialized object option rewritten and intact ($v)" || fail "serialized object option is $v"
  v=$(wps b eval '$o = get_option( "e2e_serialized" ); echo $o["nested"]["deep"]["u"];')
  [ "$v" = http://localhost:8085/deep ] && pass "nested serialized option rewritten" || fail "nested serialized option is $v"
  v=$(esc_count b 8085)
  [ "$v" -ge 100 ] 2>/dev/null && pass "JSON-escaped addresses (http:\\/\\/) rewritten ($v posts)" || fail "JSON-escaped addresses rewritten in $v posts"
  v=$(wps b eval 'global $wpdb; $bad = 0; foreach ( $wpdb->get_col( "SELECT data FROM {$wpdb->prefix}e2e_keyed" ) as $d ) { $u = maybe_unserialize( $d ); if ( ! is_array( $u ) || 0 !== strpos( $u["u"], "http://localhost:8085/" ) ) { $bad++; } } echo $bad;')
  [ "$v" = 0 ] && pass "6000 serialized rows of a custom table intact" || fail "$v broken rows in the custom table"
  [ "$(wps b option get stylesheet)" = e2e-child ] && pass "child theme still active" || fail "active theme is $(wps b option get stylesheet)"
  wps b option get active_plugins --format=json | grep -q 'e2e-demo' && pass "demo plugin still active" || fail "demo plugin not active"
  dt exec -T b-cli test -f wp-content/mu-plugins/e2e-mu.php && pass "mu-plugin migrated" || fail "mu-plugin missing"
  dt exec -T b-cli php -r '$f = glob("wp-content/uploads/e2e/*文字.txt"); exit($f ? 0 : 1);' && pass "non-ASCII file name kept" || fail "non-ASCII file name lost"
fi

for s in a b; do
  front $s
  log=$(dt exec -T $s-cli sh -c 'cat wp-content/debug.log 2>/dev/null' | grep -i 'hole-in-one\|holemi' | head -5)
  [ -z "$log" ] && pass "site $s: no PHP notices from the plugin" || { fail "site $s: PHP notices from the plugin:"; echo "$log"; }
done

echo "== 4. Uploads outside wp-content: site a keeps its media in /var/www/html/media"
dt exec -T a-cli sh -c 'mv wp-content/uploads media'
wps a option update upload_path media >/dev/null
[ "$(wps a eval 'echo wp_upload_dir()["basedir"];')" = /var/www/html/media ] && pass "site a uploads folder is now /var/www/html/media" || fail "could not move the uploads folder of site a"
JOB=$(wps a eval-file /e2e/cli-step.php export both tar.gz | tail -1)
run_job a "$JOB" || end
EXP2=$(wps a eval-file /e2e/cli-step.php result "$JOB" | tail -1)
dt cp "a-cli:$BAKA/$EXP2" "$TMP/" >/dev/null 2>&1
tar -tzf "$TMP/$EXP2" > "$TMP/list2"
grep -q '^outside-wp-content/uploads/' "$TMP/list2" && pass "export contains the uploads folder outside wp-content ($(grep -c '^outside-wp-content/uploads/.*[^/]$' "$TMP/list2") files)" || fail "export is missing the uploads folder outside wp-content"
grep -q '^wp-content/uploads/' "$TMP/list2" && fail "export also has a wp-content/uploads folder" || pass "no stale wp-content/uploads in the export"
tar -xzOf "$TMP/$EXP2" hio-manifest.json | grep -q '"format_version": 2' && pass "manifest says format 2 (older versions refuse it instead of skipping the media)" || fail "manifest format_version is not 2"
dt cp "$TMP/$EXP2" "b-cli:$IMPB/$EXP2" >/dev/null 2>&1
JOB=$(wps b eval-file /e2e/cli-step.php start "$EXP2" | tail -1)
run_job b "$JOB" || end
[ "$(wps b eval 'echo wp_upload_dir()["basedir"];')" = /var/www/html/wp-content/uploads ] && pass "site b keeps its own uploads folder (wp-content/uploads)" || fail "site b uploads folder is $(wps b eval 'echo wp_upload_dir()["basedir"];')"
MS='cd /var/www/html/media && find . -type f -exec md5sum {} + | sort -k2'; BS='cd wp-content/uploads && find . -type f -exec md5sum {} + | sort -k2'
dt exec -T a-cli sh -c "$MS" > "$TMP/media-a"; dt exec -T b-cli sh -c "$BS" > "$TMP/media-b"
diff -q "$TMP/media-a" "$TMP/media-b" >/dev/null && pass "all $(wc -l < "$TMP/media-a") media files of a are in b's wp-content/uploads" || { fail "media files differ:"; diff "$TMP/media-a" "$TMP/media-b" | head; }
nm=$(count_str b 'localhost:8084/media')
[ "$nm" = 0 ] && pass "no links to the old media address left in site b" || fail "old media address found $nm times in site b"
front b

end
