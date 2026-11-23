<?php
/**
 * Help tab: step-by-step migration instructions.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="holemi-card holemi-help">
	<h2><?php esc_html_e( 'How to move your site to a new host', 'hole-in-one-migration' ); ?></h2>
	<p><?php esc_html_e( 'Plan about 15 minutes for a small site and an hour or more for a site with many gigabytes of media. You do not need any technical knowledge.', 'hole-in-one-migration' ); ?></p>

	<h3><span class="holemi-num">1</span> <?php esc_html_e( 'Export the old site', 'hole-in-one-migration' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'On the OLD site, install and activate Hole-in-One Migration (Plugins → Add New).', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Go to Migration → Export. Keep the default "Website + database in one file" and click "Start export".', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Keep the browser tab open until it says "Export complete". You may use other tabs meanwhile. If your connection drops or the computer goes to sleep, just come back to this page and click "Resume".', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Click "Download" and save the file on your computer. An interrupted download can be resumed by the browser or a download manager.', 'hole-in-one-migration' ); ?></li>
	</ol>

	<h3><span class="holemi-num">2</span> <?php esc_html_e( 'Prepare the new host', 'hole-in-one-migration' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'At the new host, install a fresh, empty WordPress (most hosts have a "1-click WordPress install"). Any username and password is fine: they will be replaced by your old ones.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Not moved your domain yet? Use the temporary address your new host gives you (for example a staging or preview URL). The plugin changes all links to the new address automatically.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Install and activate Hole-in-One Migration on the new site. Do not install other plugins: they will come from your backup.', 'hole-in-one-migration' ); ?></li>
	</ol>

	<h3><span class="holemi-num">3</span> <?php esc_html_e( 'Import on the new site', 'hole-in-one-migration' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Go to Migration → Import, choose the downloaded file and click "Upload and import". Confirm the warning.', 'hole-in-one-migration' ); ?></li>
		<li>
			<?php
			/* translators: %s: folder path */
			echo esc_html( sprintf( __( 'Very large file or slow upload? Upload it with FTP/SFTP or your host\'s file manager into %s instead, reload the Import page and click "Import this file". The folder has a random name so that nobody can guess it.', 'hole-in-one-migration' ), HOLEMI_Plugin::import_folder_label() ) );
			?>
		</li>
		<li><?php esc_html_e( 'Wait for "Import complete". You are then logged out automatically.', 'hole-in-one-migration' ); ?></li>
		<li><strong><?php esc_html_e( 'Log in with the username and password of your OLD site.', 'hole-in-one-migration' ); ?></strong></li>
	</ol>

	<h3><span class="holemi-num">4</span> <?php esc_html_e( 'Check and go live', 'hole-in-one-migration' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Open a few pages, posts and images. If pages show "Not found", go to Settings → Permalinks and click "Save Changes" once.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'If you used a temporary address: when you point your domain to the new host, change the address under Settings → General, or simply export the new site again and import it once the domain points to it.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Point your domain\'s DNS (A record or nameservers) to the new host. It can take a few hours until everyone sees the new server. Set up SSL (https) at the new host.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Delete the backup files from Migration → Backups on both sites: they contain your whole site, including user data.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Keep the old hosting for a few days until you are sure everything works.', 'hole-in-one-migration' ); ?></li>
	</ol>

	<h2><?php esc_html_e( 'Why it does not time out', 'hole-in-one-migration' ); ?></h2>
	<ul class="ul-disc">
		<li><?php esc_html_e( 'Every task (scanning files, dumping the database, compressing, uploading, extracting, importing, updating links) is split into small chunks. Each request works for a few seconds only, saves its progress and stops – far below any PHP or server timeout.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Even single huge files (videos, big databases) are processed piece by piece across many requests.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'If a request still fails (timeout, error 500/502/503/504, lost connection), the browser waits and retries automatically from the last saved point, with smaller chunks. Nothing is done twice and nothing is lost.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Uploads are sent in small pieces, so the host\'s upload size limit does not matter.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'The new site stays online during the import: files are extracted into a staging folder and the database into temporary tables. At the very end the files are moved into place folder by folder and the database is switched over in a single atomic step. Cancel before that, and the site is left as it was.', 'hole-in-one-migration' ); ?></li>
		<li><?php esc_html_e( 'Addresses are replaced safely, including inside serialized data (widgets, page builders, theme options) and JSON.', 'hole-in-one-migration' ); ?></li>
	</ul>

	<h2><?php esc_html_e( 'Troubleshooting', 'hole-in-one-migration' ); ?></h2>
	<dl class="holemi-faq">
		<dt><?php esc_html_e( 'It shows "retrying" many times', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'The server is slow or busy. Let it continue: the plugin reduces the chunk size by itself. If it gives up, click "Retry". You can also lower "Work time per request" to 5–8 seconds in Settings.', 'hole-in-one-migration' ); ?></dd>

		<dt><?php esc_html_e( 'I closed the browser / my computer went to sleep', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'Open the Migration page again and click "Resume" at the top. For an interrupted upload, choose the same file again: it continues where it stopped.', 'hole-in-one-migration' ); ?></dd>

		<dt><?php esc_html_e( 'The download stops or is very slow', 'hole-in-one-migration' ); ?></dt>
		<dd>
			<?php
			/* translators: %s: folder path */
			echo esc_html( sprintf( __( 'Start it again: the download supports resuming, so a browser or download manager continues where it stopped. You can also fetch the file by FTP from %s.', 'hole-in-one-migration' ), str_replace( '/import/', '/backups/', HOLEMI_Plugin::import_folder_label() ) ) );
			?>
		</dd>

		<dt><?php esc_html_e( '"Not enough disk space"', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'Delete old backups (Backups tab and other backup plugins), or exclude big folders under Settings → Also exclude and copy those by FTP.', 'hole-in-one-migration' ); ?></dd>

		<dt><?php esc_html_e( '"Database import failed … max_allowed_packet"', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'Ask your new host to raise the MySQL setting max_allowed_packet to at least 16M, then click "Retry". Your current site was not changed.', 'hole-in-one-migration' ); ?></dd>

		<dt><?php esc_html_e( 'After the import I cannot log in', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'Use the username and password of the OLD site. Lost it? Use "Lost your password?" on the login page, or reset it in phpMyAdmin.', 'hole-in-one-migration' ); ?></dd>

		<dt><?php esc_html_e( 'White screen or error after import', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'Usually a plugin that needs a newer PHP version, or a cache plugin. Check that the new host runs the same or a newer PHP version (shown in the log). You can disable a plugin by renaming its folder in wp-content/plugins via FTP.', 'hole-in-one-migration' ); ?></dd>

		<dt><?php esc_html_e( 'What is not included?', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'WordPress core files and wp-config.php (the new host has its own, with its own database password), cache folders, and hosting-specific must-use plugins. WordPress Multisite networks are not supported. Database tables that do not use your WordPress table prefix are not exported.', 'hole-in-one-migration' ); ?></dd>

		<dt><?php esc_html_e( 'Can I open the archive myself?', 'hole-in-one-migration' ); ?></dt>
		<dd><?php esc_html_e( 'Yes. Both formats are standard: .tar.gz opens with 7-Zip, WinRAR, macOS Archive Utility or "tar -xzf"; .zip opens everywhere. Inside are your wp-content folder, hio-database.sql and a small hio-manifest.json. The table prefix in the SQL file is written as __HIOP__.', 'hole-in-one-migration' ); ?></dd>
	</dl>
</div>
