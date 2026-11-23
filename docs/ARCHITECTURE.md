# Architecture and test notes

How Hole-in-One Migration stays reliable on hosts with strict limits, and how it was tested. For user instructions, see the [main README](../README.md) or the plugin's *Help & Instructions* tab.

## How it stays reliable

| Problem | Solution |
|---|---|
| PHP `max_execution_time`, proxy/CDN timeouts | Every AJAX request works for a short time budget (default 15 s, never more than half of `max_execution_time`), writes an atomic checkpoint (`state.json`, written to a temp file and renamed into place) and returns. |
| Request dies anyway (timeout, 5xx, killed worker) | Browser retries with exponential backoff. The server truncates every output file back to its checkpoint size, then continues. Budget and batch sizes are halved on each retry, and grow back 25% after every 5 successful requests, so a few hiccups don't slow down the rest of a long migration. |
| Two requests at once (retry while the old one still runs) | Per-job `flock`; the second request gets `busy` and the browser waits. |
| Huge single files | Streamed across requests. tar.gz: gzip members are closed at every checkpoint (multi-member gzip, RFC 1952). ZIP: stored entries plus a CRC-32 combined across requests (`crc32_combine`), with the CRC patched into the local header. |
| ZipArchive rewrites the whole file on every close | Custom append-only ZIP writer (ZIP64 capable); central directory kept in a side file. |
| Upload limits (`upload_max_filesize`, nginx `client_max_body_size`) | Idempotent, offset-based chunked upload that halves the chunk size on HTTP 413. FTP drop folder as an alternative. |
| Extraction must resume mid-archive | Streaming tar parser with serializable state; checkpoints at gzip member boundaries (≤ 8 MB uncompressed each). |
| Huge tables (millions of rows) | Keyset pagination on the primary key, also multi-column keys (`(a > x) OR (a = x AND b > y)`), so every query costs the same no matter how deep into the table. The dump streams rows unbuffered (constant memory). Search & replace works in bounded key windows, so no single query can run long, and commits one transaction per batch instead of one per row. |
| Re-running SQL after a crash | One statement per line, byte-offset checkpoints, `INSERT` executed as `REPLACE`, `DROP TABLE IF EXISTS` before every `CREATE`. |
| Site broken while importing | Files are extracted into a staging folder in the job folder, the DB into temporary tables (`hiXXX_`). At the end every top-level unit (one plugin, one theme, one uploads year, one wp-content folder) is moved into place with a single `rename()`; the unit it replaces is kept in the job folder until the import completes, so Cancel puts it back. Then the tables are swapped in with one atomic `RENAME TABLE`. Must-use plugins and drop-ins go in last, after the swap. Across disks, units are copied in resumable chunks instead. |
| Lost answer to the final swap (session is gone) | The swap saves checkpoints right before and after itself and writes a job marker into the imported options table. The `holemi_status` endpoint works without a login session (the job id is the secret). The browser polls it, and it can finish the final cleanup even if the request that did the swap died. |
| Serialized data | Token-level serialize parser (no `unserialize`, so no object instantiation) that rewrites string lengths; `strtr` so nothing is replaced twice. |
| MySQL 8 → MariaDB | Collation/engine fallback, auto-named foreign keys, `sql_mode` without strict/zero-date checks, generated columns skipped, binary data as hex. |

## Layout

```
hole-in-one-migration/
  hole-in-one-migration.php       bootstrap, constants, Buy Me a Coffee URL
  includes/class-holemi-plugin.php    admin page, AJAX endpoints, downloads (HTTP Range), notices
  includes/class-holemi-job.php       job state, checkpoints, lock, time budget, log
  includes/class-holemi-exporter.php  scan -> database dump -> archive
  includes/class-holemi-importer.php  upload -> validate -> extract (staging) -> DB import -> search & replace -> move files into place -> swap
  includes/class-holemi-tar-writer.php / class-holemi-tar-parser.php
  includes/class-holemi-zip-writer.php / class-holemi-zip-reader.php
  includes/class-holemi-search-replace.php
  includes/class-holemi-db.php, class-holemi-storage.php, class-holemi-util.php
  admin/page.php, admin/help.php   UI and user instructions
  assets/admin.js, assets/admin.css
```

Storage: `wp-content/hole-in-one-migration-<24 random hex>/{work,backups,import}`, found again by its name on disk (not in the database, which an import replaces). Every folder has `index.php`, a deny-all `.htaccess` and `web.config`; on nginx the random name keeps it from being found. Archives are only served by the authenticated download handler. Uninstalling the plugin deletes this folder, **including backups**.

Archive layout: `wp-content/...` for wp-content; a plugins, mu-plugins, themes or uploads folder outside wp-content is stored as `outside-wp-content/<role>/...`, and the manifest's `roots` map says which archive folder holds which role. The importer puts each role where the new site keeps it (`WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `get_theme_root()`, `wp_upload_dir()`). Archives with outside folders say `format_version: 2`, so older versions refuse them instead of skipping those folders.

## Tested

**A real 5.2 GB WooCommerce site** (WordPress 6.8, PHP 8.2, MySQL 8.4): 1.35 GB of files and a 4.1 GB database with 6.9 million rows in 85 tables, mostly TranslatePress dictionaries. It runs WooCommerce, Elementor Pro, TranslatePress, All in One SEO, Prime Mover, Object Cache Pro and about 20 other plugins. Test host limits: 5 s time limit, 2 MB upload limit.
- Imported its 1.0.0 ZIP archive from the FTP folder while PHP workers were killed 10 times: 15.8 minutes, 342 requests. All 37,767 files were byte-identical to the archive. Every one of the 6,906,221 database rows was either identical or changed only by the intended address/prefix replacement (checked row by row against the archive's SQL).
- Exported the migrated site again (tar.gz, 1.1 GB, PHP killed 8 times: 6.3 minutes) and imported that into a third site (6 crashes). All 37,878 files and all database rows matched, apart from the intended address change. A ZIP export of the same site (4.8 GB, ZIP64) passed a full CRC check.
- This test found and fixed three bugs (see the 1.0.2 changelog): a must-use plugin that hard-codes the site address logged the user out halfway through the import; a translation plugin's "/en" address ended up in the manifest; and the SQL file did not declare its character set.

**A real 1.1 GB Elementor site** (WordPress 7.0, PHP 8.5, MySQL 8.4) with Patchstack, WP Rocket, Rank Math, Post SMTP and its old host's must-use plugins. Imported its 1.0.0 tar.gz archive by chunked browser upload into WordPress 7.1 on PHP 8.5: 38 seconds. All 14,457 files byte-identical; all 239,170 database rows identical or changed only by the intended address/prefix replacement. Then a new export with PHP killed every 4 seconds (40 crashes in 53 requests) and an import of it into a third site the same way (56 crashes in 64 requests, plus 4 interrupted upload chunks): all files and rows matched. The admin page ran in Chrome with all the site's plugins active, with no JavaScript errors.

**Synthetic large sites:**

**4.3 GB database, PHP 8.5, low limits** (`max_execution_time = 5`, `memory_limit = 64M` that WordPress may not raise, 2 MB upload limit; MySQL 8 → MariaDB 10.6; different URL and table prefix). The data was 1M posts, 4M postmeta rows (some serialized) and 2M term relationships (multi-column key). PHP workers were killed with `kill -9` during the export (3×) and the import (10×). Export: 3.3 GB SQL dump in 33 requests. Import: 8.4 minutes, 1,050,007 rows updated by search & replace. Checksums of all big tables were identical between source and target, apart from the intended URL change. No PHP warnings or deprecations with `WP_DEBUG` on.

**5.2 GB of files, PHP 8.5, same limits**, including a single 4.3 GB file (ZIP64): tar.gz with browser-style upload in 2,783 chunks, and ZIP imported from the FTP folder while PHP workers were killed 12 times. File checksums identical.

**Earlier rounds:**
Two Docker sites (MySQL 8 with prefix `wpa_` → MariaDB 10.6 with prefix `wp_`, different URLs). Both had `max_execution_time = 5` and a 2 MB upload limit. Test data: about 300 MB including a 250 MB file, 4,000 small files and 3,300 posts. Covered:

- tar.gz and ZIP, both directions
- full, files-only and database-only
- client-side request aborts, duplicated upload chunks, and Apache workers killed with `kill -9` during the import
- the real UI in headless Chrome

Checked afterwards: file checksums, binary columns, serialized/JSON/URL-encoded replacements, user roles after the prefix change, login with the old credentials, and pretty permalinks.
