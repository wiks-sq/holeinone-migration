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
| Site broken while importing | DB goes into temporary tables (`hiXXX_`) and is swapped in with one atomic `RENAME TABLE`. A temporary mu-plugin keeps half-extracted plugins and themes from loading during the plugin's own AJAX requests. |
| Lost answer to the final swap (session is gone) | The swap saves checkpoints right before and after itself and writes a job marker into the imported options table. The `hio_status` endpoint works without a login session (the job id is the secret). The browser polls it, and it can finish the final cleanup even if the request that did the swap died. |
| Serialized data | Token-level serialize parser (no `unserialize`, so no object instantiation) that rewrites string lengths; `strtr` so nothing is replaced twice. |
| MySQL 8 → MariaDB | Collation/engine fallback, auto-named foreign keys, `sql_mode` without strict/zero-date checks, generated columns skipped, binary data as hex. |

## Layout

```
holeinone-migration/
  holeinone-migration.php          bootstrap, constants, Buy Me a Coffee URL
  includes/class-hio-plugin.php    admin page, AJAX endpoints, downloads (HTTP Range), notices
  includes/class-hio-job.php       job state, checkpoints, lock, time budget, log
  includes/class-hio-exporter.php  scan -> database dump -> archive
  includes/class-hio-importer.php  upload -> validate -> extract -> DB import -> search & replace -> swap
  includes/class-hio-tar-writer.php / class-hio-tar-parser.php
  includes/class-hio-zip-writer.php / class-hio-zip-reader.php
  includes/class-hio-search-replace.php
  includes/class-hio-db.php, class-hio-storage.php, class-hio-util.php
  admin/page.php, admin/help.php   UI and user instructions
  assets/admin.js, assets/admin.css
```

Storage: `wp-content/hio-migration/{work,backups,import}`. Uninstalling the plugin deletes this folder, **including backups**.

## Tested

**4.3 GB database, PHP 8.5, low limits** (`max_execution_time = 5`, `memory_limit = 64M` that WordPress may not raise, 2 MB upload limit; MySQL 8 → MariaDB 10.6; different URL and table prefix). The data was 1M posts, 4M postmeta rows (some serialized) and 2M term relationships (multi-column key). PHP workers were killed with `kill -9` during the export (3×) and the import (10×). Export: 3.3 GB SQL dump in 33 requests. Import: 8.4 minutes, 1,050,007 rows updated by search & replace. Checksums of all big tables were identical between source and target, apart from the intended URL change. No PHP warnings or deprecations with `WP_DEBUG` on.

**5.2 GB of files, PHP 8.5, same limits**, including a single 4.3 GB file (ZIP64): tar.gz with browser-style upload in 2,783 chunks, and ZIP imported from the FTP folder while PHP workers were killed 12 times. File checksums identical.

**Earlier rounds:**
Two Docker sites (MySQL 8 with prefix `wpa_` → MariaDB 10.6 with prefix `wp_`, different URLs). Both had `max_execution_time = 5` and a 2 MB upload limit. Test data: about 300 MB including a 250 MB file, 4,000 small files and 3,300 posts. Covered:

- tar.gz and ZIP, both directions
- full, files-only and database-only
- client-side request aborts, duplicated upload chunks, and Apache workers killed with `kill -9` during the import
- the real UI in headless Chrome

Checked afterwards: file checksums, binary columns, serialized/JSON/URL-encoded replacements, user roles after the prefix change, login with the old credentials, and pretty permalinks.
