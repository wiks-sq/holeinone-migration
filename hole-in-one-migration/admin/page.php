<?php
/**
 * Admin page template.
 *
 * @package HoleInOneMigration
 *
 * @var array  $tabs     Tabs.
 * @var string $tab      Current tab.
 * @var array  $settings Settings.
 */

defined( 'ABSPATH' ) || exit;

$holemi_page   = admin_url( 'admin.php?page=' . HOLEMI_Plugin::SLUG );
$holemi_coffee = HOLEMI_Plugin::coffee_url();
?>
<div class="wrap holemi-wrap">
	<h1 class="holemi-title"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Hole-in-One Migration', 'hole-in-one-migration' ); ?></h1>
	<p class="holemi-tagline"><?php esc_html_e( 'Move your whole WordPress site to a new host in one shot. Works in small, resumable chunks: no timeouts, no broken migrations.', 'hole-in-one-migration' ); ?></p>

	<div id="holemi-resume" class="holemi-resume" hidden></div>

	<nav class="nav-tab-wrapper holemi-tabs">
		<?php foreach ( $tabs as $holemi_key => $holemi_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $holemi_key, $holemi_page ) ); ?>" class="nav-tab <?php echo $tab === $holemi_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $holemi_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="holemi-layout">
		<div class="holemi-main">

		<?php if ( 'export' === $tab ) : ?>
			<div class="holemi-card">
				<h2><?php esc_html_e( 'Step 1 – Export this site', 'hole-in-one-migration' ); ?></h2>
				<p><?php esc_html_e( 'Run this on the OLD site. It creates one archive file that you then import on the new site.', 'hole-in-one-migration' ); ?></p>

				<form id="holemi-export-form" class="holemi-form">
					<fieldset>
						<legend><?php esc_html_e( 'What to export', 'hole-in-one-migration' ); ?></legend>
						<label class="holemi-choice">
							<input type="radio" name="contents" value="both" checked>
							<strong><?php esc_html_e( 'Website + database in one file', 'hole-in-one-migration' ); ?></strong>
							<span class="holemi-badge"><?php esc_html_e( 'Recommended', 'hole-in-one-migration' ); ?></span>
							<span class="description"><?php esc_html_e( 'Everything needed to move the site: posts, pages, users, settings, media, plugins and themes.', 'hole-in-one-migration' ); ?></span>
						</label>
						<label class="holemi-choice">
							<input type="radio" name="contents" value="files">
							<strong><?php esc_html_e( 'Website files only', 'hole-in-one-migration' ); ?></strong>
							<span class="description"><?php esc_html_e( 'The wp-content folder: media uploads, plugins and themes. No database.', 'hole-in-one-migration' ); ?></span>
						</label>
						<label class="holemi-choice">
							<input type="radio" name="contents" value="db">
							<strong><?php esc_html_e( 'Database only', 'hole-in-one-migration' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Posts, pages, comments, users and settings. No files.', 'hole-in-one-migration' ); ?></span>
						</label>
					</fieldset>

					<fieldset>
						<legend><?php esc_html_e( 'Archive format', 'hole-in-one-migration' ); ?></legend>
						<label><input type="radio" name="format" value="tar.gz" <?php checked( 'tar.gz', $settings['format'] ); ?>> <strong>.tar.gz</strong> – <?php esc_html_e( 'smaller and fastest to import (recommended)', 'hole-in-one-migration' ); ?></label><br>
						<label><input type="radio" name="format" value="zip" <?php checked( 'zip', $settings['format'] ); ?>> <strong>.zip</strong> – <?php esc_html_e( 'opens with a double click on Windows and macOS', 'hole-in-one-migration' ); ?></label>
					</fieldset>

					<fieldset class="holemi-files-only">
						<legend><?php esc_html_e( 'Options', 'hole-in-one-migration' ); ?></legend>
						<label><input type="checkbox" name="exclude_uploads" value="1"> <?php esc_html_e( 'Leave out the media library (uploads folder) – only if you copy it yourself by FTP', 'hole-in-one-migration' ); ?></label>
					</fieldset>

					<p><button type="submit" class="button button-primary button-hero" id="holemi-export-btn"><?php esc_html_e( 'Start export', 'hole-in-one-migration' ); ?></button></p>
				</form>
			</div>
			<div id="holemi-export-progress"></div>

		<?php elseif ( 'import' === $tab ) : ?>
			<div class="holemi-card">
				<h2><?php esc_html_e( 'Step 2 – Import on the new site', 'hole-in-one-migration' ); ?></h2>
				<div class="holemi-warning">
					<p><strong><?php esc_html_e( 'Run this on the NEW site. Importing replaces this site\'s content, users, settings, plugins and themes with the backup.', 'hole-in-one-migration' ); ?></strong></p>
					<p><?php esc_html_e( 'This site keeps working until the very last second: the database is imported into temporary tables first and switched over in one atomic step. If anything goes wrong before that, nothing on this site has changed.', 'hole-in-one-migration' ); ?></p>
					<p><?php esc_html_e( 'After the import you will be logged out. Log in again with the username and password of the OLD site.', 'hole-in-one-migration' ); ?></p>
				</div>

				<h3><?php esc_html_e( 'Option A: upload from your computer', 'hole-in-one-migration' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Any file size works: the file is sent in small pieces, so the server upload limit does not matter. An interrupted upload continues where it stopped.', 'hole-in-one-migration' ); ?></p>
				<p>
					<input type="file" id="holemi-file" accept=".gz,.tgz,.zip,application/gzip,application/zip">
					<button type="button" class="button button-primary" id="holemi-upload-btn"><?php esc_html_e( 'Upload and import', 'hole-in-one-migration' ); ?></button>
				</p>

				<h3><?php esc_html_e( 'Option B: file already on this server (FTP / SFTP / file manager)', 'hole-in-one-migration' ); ?></h3>
				<p class="description">
					<?php
					/* translators: %s folder path */
					echo wp_kses_post( sprintf( __( 'Best for very large sites or slow internet. Upload the archive with FTP into <code>%s</code>, then reload this page.', 'hole-in-one-migration' ), esc_html( HOLEMI_Plugin::import_folder_label() ) ) );
					?>
				</p>
				<?php $holemi_files = HOLEMI_Storage::import_files(); ?>
				<?php if ( $holemi_files ) : ?>
					<table class="widefat striped holemi-table">
						<thead><tr><th><?php esc_html_e( 'File', 'hole-in-one-migration' ); ?></th><th><?php esc_html_e( 'Size', 'hole-in-one-migration' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $holemi_files as $holemi_f ) : ?>
							<tr>
								<td><code><?php echo esc_html( $holemi_f['name'] ); ?></code></td>
								<td><?php echo esc_html( HOLEMI_Util::size( $holemi_f['size'] ) ); ?></td>
								<td><button type="button" class="button button-primary holemi-import-server" data-file="<?php echo esc_attr( $holemi_f['name'] ); ?>"><?php esc_html_e( 'Import this file', 'hole-in-one-migration' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><em><?php esc_html_e( 'No archives found in the import folder yet.', 'hole-in-one-migration' ); ?></em></p>
				<?php endif; ?>
			</div>
			<div id="holemi-import-progress"></div>

		<?php elseif ( 'backups' === $tab ) : ?>
			<div class="holemi-card">
				<h2><?php esc_html_e( 'Backups on this server', 'hole-in-one-migration' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Archives you exported. Download them to your computer, then delete them here: they contain your whole site including the database.', 'hole-in-one-migration' ); ?></p>
				<?php $holemi_backups = HOLEMI_Storage::backups(); ?>
				<?php if ( $holemi_backups ) : ?>
					<table class="widefat striped holemi-table">
						<thead><tr>
							<th><?php esc_html_e( 'Archive', 'hole-in-one-migration' ); ?></th>
							<th><?php esc_html_e( 'Contents', 'hole-in-one-migration' ); ?></th>
							<th><?php esc_html_e( 'Size', 'hole-in-one-migration' ); ?></th>
							<th><?php esc_html_e( 'Created', 'hole-in-one-migration' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php
						$holemi_labels = array(
							'both'  => __( 'Website + database', 'hole-in-one-migration' ),
							'files' => __( 'Website files', 'hole-in-one-migration' ),
							'db'    => __( 'Database', 'hole-in-one-migration' ),
						);
						foreach ( $holemi_backups as $holemi_b ) :
							$holemi_c = isset( $holemi_b['meta']['contents'] ) ? $holemi_b['meta']['contents'] : 'both';
							?>
							<tr>
								<td><code><?php echo esc_html( $holemi_b['name'] ); ?></code></td>
								<td><?php echo esc_html( isset( $holemi_labels[ $holemi_c ] ) ? $holemi_labels[ $holemi_c ] : $holemi_c ); ?></td>
								<td><?php echo esc_html( HOLEMI_Util::size( $holemi_b['size'] ) ); ?></td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $holemi_b['time'] ) ); ?></td>
								<td class="holemi-actions">
									<a class="button button-primary" href="<?php echo esc_url( HOLEMI_Plugin::php_download_url( $holemi_b['name'] ) ); ?>"><?php esc_html_e( 'Download', 'hole-in-one-migration' ); ?></a>
									<button type="button" class="button button-link-delete holemi-delete-backup" data-file="<?php echo esc_attr( $holemi_b['name'] ); ?>"><?php esc_html_e( 'Delete', 'hole-in-one-migration' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><em><?php esc_html_e( 'No backups yet. Create one on the Export tab.', 'hole-in-one-migration' ); ?></em></p>
				<?php endif; ?>
			</div>

		<?php elseif ( 'settings' === $tab ) : ?>
			<div class="holemi-card">
				<h2><?php esc_html_e( 'Settings', 'hole-in-one-migration' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'holemi_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Default archive format', 'hole-in-one-migration' ); ?></th>
							<td>
								<select name="holemi_settings[format]">
									<option value="tar.gz" <?php selected( 'tar.gz', $settings['format'] ); ?>>.tar.gz</option>
									<option value="zip" <?php selected( 'zip', $settings['format'] ); ?>>.zip</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="holemi-budget"><?php esc_html_e( 'Work time per request', 'hole-in-one-migration' ); ?></label></th>
							<td>
								<input id="holemi-budget" type="number" min="3" max="120" name="holemi_settings[time_budget]" value="<?php echo esc_attr( $settings['time_budget'] ); ?>" class="small-text"> <?php esc_html_e( 'seconds', 'hole-in-one-migration' ); ?>
								<p class="description">
									<?php
									/* translators: %s PHP max_execution_time */
									echo esc_html( sprintf( __( 'Each request stops after this time and saves its progress. Never more than half of PHP\'s limit is used (max_execution_time on this server: %s). If a request still fails, the plugin halves this automatically. 15 is safe for almost every host.', 'hole-in-one-migration' ), ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) . 's' : __( 'unlimited', 'hole-in-one-migration' ) ) );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="holemi-pause"><?php esc_html_e( 'Pause between requests', 'hole-in-one-migration' ); ?></label></th>
							<td>
								<input id="holemi-pause" type="number" min="0" max="10000" step="50" name="holemi_settings[pause_ms]" value="<?php echo esc_attr( $settings['pause_ms'] ); ?>" class="small-text"> ms
								<p class="description"><?php esc_html_e( 'A short break between chunks keeps the server responsive for your visitors. Raise it (e.g. 1000) on very small shared hosting plans.', 'hole-in-one-migration' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Skip caches', 'hole-in-one-migration' ); ?></th>
							<td>
								<label><input type="checkbox" name="holemi_settings[exclude_cache]" value="1" <?php checked( 1, $settings['exclude_cache'] ); ?>> <?php esc_html_e( 'Leave out cache folders, cache drop-ins, logs, other backup plugins\' archives and hosting-company must-use plugins', 'hole-in-one-migration' ); ?></label>
								<p class="description"><?php echo esc_html( implode( ', ', HOLEMI_Exporter::cache_excludes() ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="holemi-excl"><?php esc_html_e( 'Also exclude', 'hole-in-one-migration' ); ?></label></th>
							<td>
								<textarea id="holemi-excl" name="holemi_settings[custom_excludes]" rows="5" class="large-text code" placeholder="uploads/big-videos&#10;plugins/some-plugin&#10;uploads/*.zip"><?php echo esc_textarea( $settings['custom_excludes'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One path per line, relative to wp-content. Wildcards (*) are allowed.', 'hole-in-one-migration' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'After import', 'hole-in-one-migration' ); ?></th>
							<td><label><input type="checkbox" name="holemi_settings[delete_after]" value="1" <?php checked( 1, $settings['delete_after'] ); ?>> <?php esc_html_e( 'Delete the archive from the import folder after a successful import', 'hole-in-one-migration' ); ?></label></td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</div>

		<?php else : ?>
			<?php include HOLEMI_DIR . 'admin/help.php'; ?>
		<?php endif; ?>

		</div>

		<aside class="holemi-side">
			<?php if ( $holemi_coffee ) : ?>
				<div class="holemi-card holemi-coffee">
					<h3>☕ <?php esc_html_e( 'Free, forever', 'hole-in-one-migration' ); ?></h3>
					<p><?php esc_html_e( 'Hole-in-One Migration is 100% free: no paid upgrade, no size limit. If it saved your day, you can buy me a coffee!', 'hole-in-one-migration' ); ?></p>
					<p><a class="holemi-coffee-btn" href="<?php echo esc_url( $holemi_coffee ); ?>" target="_blank" rel="noopener noreferrer">☕ <?php esc_html_e( 'Buy me a coffee', 'hole-in-one-migration' ); ?></a></p>
				</div>
			<?php endif; ?>
			<div class="holemi-card">
				<h3><?php esc_html_e( 'Quick guide', 'hole-in-one-migration' ); ?></h3>
				<ol class="holemi-steps">
					<li><?php esc_html_e( 'Old site: Export → download the file.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'New host: install a fresh WordPress and this plugin.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'New site: Import → choose the file.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'Log in with your OLD username and password.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'Point your domain to the new host.', 'hole-in-one-migration' ); ?></li>
				</ol>
				<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'help', $holemi_page ) ); ?>"><?php esc_html_e( 'Full instructions →', 'hole-in-one-migration' ); ?></a></p>
			</div>
			<div class="holemi-card holemi-env">
				<h3><?php esc_html_e( 'This server', 'hole-in-one-migration' ); ?></h3>
				<?php
				$holemi_free = HOLEMI_Util::free_space( HOLEMI_Util::content_dir() );
				$holemi_env  = array(
					'PHP'                => PHP_VERSION,
					'max_execution_time' => ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) . 's' : '∞',
					'memory_limit'       => ini_get( 'memory_limit' ),
					'upload limit'       => size_format( wp_max_upload_size() ),
					'zlib (.tar.gz)'     => function_exists( 'deflate_init' ) ? '✔' : '✘',
					'free disk'          => null === $holemi_free ? '?' : HOLEMI_Util::size( $holemi_free ),
					'storage writable'   => wp_is_writable( HOLEMI_Storage::dir( 'work' ) ) ? '✔' : '✘',
				);
				?>
				<table class="holemi-envtable">
					<?php foreach ( $holemi_env as $holemi_k => $holemi_v ) : ?>
						<tr><th><?php echo esc_html( $holemi_k ); ?></th><td><?php echo esc_html( $holemi_v ); ?></td></tr>
					<?php endforeach; ?>
				</table>
				<p class="description"><?php esc_html_e( 'Low limits are fine: the plugin adapts to them.', 'hole-in-one-migration' ); ?></p>
			</div>
		</aside>
	</div>
</div>
