=== Hole-in-One Migration ===
Contributors: wikssq
Donate link: https://buymeacoffee.com/wikssq
Tags: migration, migrate, move site, backup, clone
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your whole WordPress site to a new host in one file. Chunked and resumable, so PHP timeouts can't break it. Free, no size limits.

== Description ==

Hole-in-One Migration moves a complete WordPress site (database, media, plugins and themes) from one web host to another.

**Built to never time out.** Every task is split into small chunks. Each server request works for a few seconds, saves a checkpoint and stops, far below any PHP or proxy timeout. If a request still fails (timeout, error 500/502/503/504, lost connection, crashed PHP worker), the browser retries automatically from the last checkpoint with smaller chunks. Nothing is lost and nothing is done twice.

* **One file, standard formats:** website + database in a single `.tar.gz` (default) or `.zip`. Both open with any archive tool.
* **Separate options:** export the website files only, or the database only.
* **No upload limits:** archives are uploaded in small pieces (works even with a 2 MB upload limit), and an interrupted upload resumes where it stopped. Huge sites can also be uploaded by FTP.
* **The new site stays online:** the database is imported into temporary tables and switched live in one atomic step at the very end. If anything fails before that, the site is unchanged.
* **Safe search & replace:** old addresses and server paths are replaced everywhere, including serialized data (widgets, page builders, theme options), JSON and URL-encoded data. A different table prefix on the new host is handled automatically.
* **Gentle on the server:** low memory use, a configurable pause between requests, and nothing running in the background.
* **Compatibility fixes:** MySQL 8 to MariaDB collations, unsupported storage engines, foreign keys, zero dates, generated columns and binary data.
* **Resume anytime:** closed the browser? Open the page again and click "Resume".
* **Free forever:** no paid upgrade, no size limit.

== Installation ==

1. Upload the `holeinone-migration` folder to `/wp-content/plugins/` or install the zip via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Open **Migration** in the admin menu.

== How to migrate your site ==

**1. Export the old site**

1. On the OLD site, go to Migration → Export.
2. Keep "Website + database in one file" and click **Start export**.
3. Keep the tab open until it says "Export complete", then click **Download**.

**2. Prepare the new host**

1. Install a fresh, empty WordPress at the new host (any admin username and password).
2. Install and activate Hole-in-One Migration there. Don't install other plugins: they come from your backup.

**3. Import**

1. On the NEW site, go to Migration → Import, choose the file and click **Upload and import**.
   For very large files, upload the archive by FTP into `wp-content/hio-migration/import/` instead, reload the page and click **Import this file**.
2. Wait for "Import complete". You are logged out automatically.
3. **Log in with the username and password of the OLD site.**

**4. Go live**

1. Check a few pages. If you get "Not found" errors, go to Settings → Permalinks and click "Save Changes".
2. Point your domain's DNS to the new host and set up SSL there.
3. Delete the backup files on both sites (Migration → Backups).

== Frequently Asked Questions ==

= What is included in the archive? =

The `wp-content` folder (uploads, plugins, themes, must-use plugins, languages) and all database tables with the WordPress table prefix. WordPress core files and `wp-config.php` are not included: the new host has its own, with its own database credentials.

= What is excluded by default? =

Cache folders and cache drop-ins, debug logs, archives of other backup plugins, and must-use plugins of hosting companies (WP Engine, Kinsta, GoDaddy, Bluehost) that would break on another host. You can turn this off and add your own exclusions under Settings.

= It keeps saying "retrying" =

The server is slow or overloaded. Let it run: the plugin automatically halves the work per request after every failure. You can also lower "Work time per request" in Settings.

= The download link returns 403 or 404 =

Use "Alternative download": it streams the file through WordPress and supports resuming. You can also fetch the file from `wp-content/hio-migration/backups/` by FTP.

= Is Multisite supported? =

No, single sites only.

= Can I open the archive myself? =

Yes. `.tar.gz` opens with 7-Zip, WinRAR, macOS Archive Utility or `tar -xzf`, and `.zip` opens everywhere. Inside are `wp-content/`, `hio-database.sql` (table prefix written as `__HIOP__`) and `hio-manifest.json`.

= Where are backups stored? Are they safe? =

In `wp-content/hio-migration/backups/` under long random file names that cannot be guessed, with directory listing disabled. Working files live in a folder that denies all web access (Apache/LiteSpeed via `.htaccess`, IIS via `web.config`). On nginx, `.htaccess` has no effect, so delete backups after downloading them, and delete archives you uploaded by FTP once the import is done.

= How do I set my own Buy Me a Coffee link? =

Edit `HIO_BUYMEACOFFEE_URL` at the top of `holeinone-migration.php`, define it in `wp-config.php`, or use the `hio_buymeacoffee_url` filter. An empty string hides the box.

== Changelog ==

= 1.0.2 =
* Fix: an import could stop halfway with "session expired" when the old site had a must-use plugin that sets the site address (for example a leftover of another migration plugin). Must-use plugins and drop-ins are now put in place after the database switch, and ones that hard-code the old address are left out with a warning.
* Fix: on sites with a translation plugin (TranslatePress, WPML, Polylang) the export could record the site address with a language path such as "/en", which damaged links on import. Exports now use the real address, and archives made with older versions are repaired during import.
* The SQL file in the archive now declares its character set, so it also imports correctly with the mysql command-line tool.
* Numbers in progress messages no longer show HTML code in languages that use a space as thousands separator.

= 1.0.1 =
* Passes the WordPress.org Plugin Check: error messages are escaped, all translatable strings have translator notes, and uploads are validated more strictly.
* Translations can now reorder the placeholders in the retry message.

= 1.0.0 =
* First release.
