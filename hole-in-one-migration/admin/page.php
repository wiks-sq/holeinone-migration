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

$hio_page   = admin_url( 'admin.php?page=' . HIO_Plugin::SLUG );
$hio_coffee = HIO_Plugin::coffee_url();
?>
<div class="wrap hio-wrap">
	<h1 class="hio-title"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Hole-in-One Migration', 'hole-in-one-migration' ); ?></h1>
	<p class="hio-tagline"><?php esc_html_e( 'Move your whole WordPress site to a new host in one shot. Works in small, resumable chunks: no timeouts, no broken migrations.', 'hole-in-one-migration' ); ?></p>

	<div id="hio-resume" class="hio-resume" hidden></div>

	<nav class="nav-tab-wrapper hio-tabs">
		<?php foreach ( $tabs as $hio_key => $hio_label ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'tab', $hio_key, $hio_page ) ); ?>" class="nav-tab <?php echo $tab === $hio_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $hio_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="hio-layout">
		<div class="hio-main">

		<?php if ( 'export' === $tab ) : ?>
			<div class="hio-card">
				<h2><?php esc_html_e( 'Step 1 – Export this site', 'hole-in-one-migration' ); ?></h2>
				<p><?php esc_html_e( 'Run this on the OLD site. It creates one archive file that you then import on the new site.', 'hole-in-one-migration' ); ?></p>

				<form id="hio-export-form" class="hio-form">
					<fieldset>
						<legend><?php esc_html_e( 'What to export', 'hole-in-one-migration' ); ?></legend>
						<label class="hio-choice">
							<input type="radio" name="contents" value="both" checked>
							<strong><?php esc_html_e( 'Website + database in one file', 'hole-in-one-migration' ); ?></strong>
							<span class="hio-badge"><?php esc_html_e( 'Recommended', 'hole-in-one-migration' ); ?></span>
							<span class="description"><?php esc_html_e( 'Everything needed to move the site: posts, pages, users, settings, media, plugins and themes.', 'hole-in-one-migration' ); ?></span>
						</label>
						<label class="hio-choice">
							<input type="radio" name="contents" value="files">
							<strong><?php esc_html_e( 'Website files only', 'hole-in-one-migration' ); ?></strong>
							<span class="description"><?php esc_html_e( 'The wp-content folder: media uploads, plugins and themes. No database.', 'hole-in-one-migration' ); ?></span>
						</label>
						<label class="hio-choice">
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

					<fieldset class="hio-files-only">
						<legend><?php esc_html_e( 'Options', 'hole-in-one-migration' ); ?></legend>
						<label><input type="checkbox" name="exclude_uploads" value="1"> <?php esc_html_e( 'Leave out the media library (wp-content/uploads) – only if you copy it yourself by FTP', 'hole-in-one-migration' ); ?></label>
					</fieldset>

					<p><button type="submit" class="button button-primary button-hero" id="hio-export-btn"><?php esc_html_e( 'Start export', 'hole-in-one-migration' ); ?></button></p>
				</form>
			</div>
			<div id="hio-export-progress"></div>

		<?php elseif ( 'import' === $tab ) : ?>
			<div class="hio-card">
				<h2><?php esc_html_e( 'Step 2 – Import on the new site', 'hole-in-one-migration' ); ?></h2>
				<div class="hio-warning">
					<p><strong><?php esc_html_e( 'Run this on the NEW site. Importing replaces this site\'s content, users, settings, plugins and themes with the backup.', 'hole-in-one-migration' ); ?></strong></p>
					<p><?php esc_html_e( 'This site keeps working until the very last second: the database is imported into temporary tables first and switched over in one atomic step. If anything goes wrong before that, nothing on this site has changed.', 'hole-in-one-migration' ); ?></p>
					<p><?php esc_html_e( 'After the import you will be logged out. Log in again with the username and password of the OLD site.', 'hole-in-one-migration' ); ?></p>
				</div>

				<h3><?php esc_html_e( 'Option A: upload from your computer', 'hole-in-one-migration' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Any file size works: the file is sent in small pieces, so the server upload limit does not matter. An interrupted upload continues where it stopped.', 'hole-in-one-migration' ); ?></p>
				<p>
					<input type="file" id="hio-file" accept=".gz,.tgz,.zip,application/gzip,application/zip">
					<button type="button" class="button button-primary" id="hio-upload-btn"><?php esc_html_e( 'Upload and import', 'hole-in-one-migration' ); ?></button>
				</p>

				<h3><?php esc_html_e( 'Option B: file already on this server (FTP / SFTP / file manager)', 'hole-in-one-migration' ); ?></h3>
				<p class="description">
					<?php
					/* translators: %s folder path */
					echo wp_kses_post( sprintf( __( 'Best for very large sites or slow internet. Upload the archive with FTP into <code>%s</code>, then reload this page.', 'hole-in-one-migration' ), esc_html( str_replace( wp_normalize_path( ABSPATH ), '/', wp_normalize_path( HIO_Storage::dir( 'import' ) ) ) . '/' ) ) );
					?>
				</p>
				<?php $hio_files = HIO_Storage::import_files(); ?>
				<?php if ( $hio_files ) : ?>
					<table class="widefat striped hio-table">
						<thead><tr><th><?php esc_html_e( 'File', 'hole-in-one-migration' ); ?></th><th><?php esc_html_e( 'Size', 'hole-in-one-migration' ); ?></th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $hio_files as $hio_f ) : ?>
							<tr>
								<td><code><?php echo esc_html( $hio_f['name'] ); ?></code></td>
								<td><?php echo esc_html( HIO_Util::size( $hio_f['size'] ) ); ?></td>
								<td><button type="button" class="button button-primary hio-import-server" data-file="<?php echo esc_attr( $hio_f['name'] ); ?>"><?php esc_html_e( 'Import this file', 'hole-in-one-migration' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p><em><?php esc_html_e( 'No archives found in the import folder yet.', 'hole-in-one-migration' ); ?></em></p>
				<?php endif; ?>
			</div>
			<div id="hio-import-progress"></div>

		<?php elseif ( 'backups' === $tab ) : ?>
			<div class="hio-card">
				<h2><?php esc_html_e( 'Backups on this server', 'hole-in-one-migration' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Archives you exported. Download them to your computer, then delete them here: they contain your whole site including the database.', 'hole-in-one-migration' ); ?></p>
				<?php $hio_backups = HIO_Storage::backups(); ?>
				<?php if ( $hio_backups ) : ?>
					<table class="widefat striped hio-table">
						<thead><tr>
							<th><?php esc_html_e( 'Archive', 'hole-in-one-migration' ); ?></th>
							<th><?php esc_html_e( 'Contents', 'hole-in-one-migration' ); ?></th>
							<th><?php esc_html_e( 'Size', 'hole-in-one-migration' ); ?></th>
							<th><?php esc_html_e( 'Created', 'hole-in-one-migration' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php
						$hio_labels = array(
							'both'  => __( 'Website + database', 'hole-in-one-migration' ),
							'files' => __( 'Website files', 'hole-in-one-migration' ),
							'db'    => __( 'Database', 'hole-in-one-migration' ),
						);
						foreach ( $hio_backups as $hio_b ) :
							$hio_c = isset( $hio_b['meta']['contents'] ) ? $hio_b['meta']['contents'] : 'both';
							?>
							<tr>
								<td><code><?php echo esc_html( $hio_b['name'] ); ?></code></td>
								<td><?php echo esc_html( isset( $hio_labels[ $hio_c ] ) ? $hio_labels[ $hio_c ] : $hio_c ); ?></td>
								<td><?php echo esc_html( HIO_Util::size( $hio_b['size'] ) ); ?></td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $hio_b['time'] ) ); ?></td>
								<td class="hio-actions">
									<a class="button button-primary" href="<?php echo esc_url( HIO_Storage::backup_url( $hio_b['name'] ) ); ?>" download><?php esc_html_e( 'Download', 'hole-in-one-migration' ); ?></a>
									<a class="button" href="<?php echo esc_url( HIO_Plugin::php_download_url( $hio_b['name'] ) ); ?>" title="<?php esc_attr_e( 'Use this if the normal download link gives a 403/404 error.', 'hole-in-one-migration' ); ?>"><?php esc_html_e( 'Alternative download', 'hole-in-one-migration' ); ?></a>
									<button type="button" class="button button-link-delete hio-delete-backup" data-file="<?php echo esc_attr( $hio_b['name'] ); ?>"><?php esc_html_e( 'Delete', 'hole-in-one-migration' ); ?></button>
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
			<div class="hio-card">
				<h2><?php esc_html_e( 'Settings', 'hole-in-one-migration' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'hio_settings_group' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Default archive format', 'hole-in-one-migration' ); ?></th>
							<td>
								<select name="hio_settings[format]">
									<option value="tar.gz" <?php selected( 'tar.gz', $settings['format'] ); ?>>.tar.gz</option>
									<option value="zip" <?php selected( 'zip', $settings['format'] ); ?>>.zip</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hio-budget"><?php esc_html_e( 'Work time per request', 'hole-in-one-migration' ); ?></label></th>
							<td>
								<input id="hio-budget" type="number" min="3" max="120" name="hio_settings[time_budget]" value="<?php echo esc_attr( $settings['time_budget'] ); ?>" class="small-text"> <?php esc_html_e( 'seconds', 'hole-in-one-migration' ); ?>
								<p class="description">
									<?php
									/* translators: %s PHP max_execution_time */
									echo esc_html( sprintf( __( 'Each request stops after this time and saves its progress. Never more than half of PHP\'s limit is used (max_execution_time on this server: %s). If a request still fails, the plugin halves this automatically. 15 is safe for almost every host.', 'hole-in-one-migration' ), ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) . 's' : __( 'unlimited', 'hole-in-one-migration' ) ) );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hio-pause"><?php esc_html_e( 'Pause between requests', 'hole-in-one-migration' ); ?></label></th>
							<td>
								<input id="hio-pause" type="number" min="0" max="10000" step="50" name="hio_settings[pause_ms]" value="<?php echo esc_attr( $settings['pause_ms'] ); ?>" class="small-text"> ms
								<p class="description"><?php esc_html_e( 'A short break between chunks keeps the server responsive for your visitors. Raise it (e.g. 1000) on very small shared hosting plans.', 'hole-in-one-migration' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Skip caches', 'hole-in-one-migration' ); ?></th>
							<td>
								<label><input type="checkbox" name="hio_settings[exclude_cache]" value="1" <?php checked( 1, $settings['exclude_cache'] ); ?>> <?php esc_html_e( 'Leave out cache folders, cache drop-ins, logs, other backup plugins\' archives and hosting-company must-use plugins', 'hole-in-one-migration' ); ?></label>
								<p class="description"><?php echo esc_html( implode( ', ', HIO_Exporter::cache_excludes() ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hio-excl"><?php esc_html_e( 'Also exclude', 'hole-in-one-migration' ); ?></label></th>
							<td>
								<textarea id="hio-excl" name="hio_settings[custom_excludes]" rows="5" class="large-text code" placeholder="uploads/big-videos&#10;plugins/some-plugin&#10;uploads/*.zip"><?php echo esc_textarea( $settings['custom_excludes'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One path per line, relative to wp-content. Wildcards (*) are allowed.', 'hole-in-one-migration' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'After import', 'hole-in-one-migration' ); ?></th>
							<td><label><input type="checkbox" name="hio_settings[delete_after]" value="1" <?php checked( 1, $settings['delete_after'] ); ?>> <?php esc_html_e( 'Delete the archive from the import folder after a successful import', 'hole-in-one-migration' ); ?></label></td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</div>

		<?php else : ?>
			<?php include HIO_DIR . 'admin/help.php'; ?>
		<?php endif; ?>

		</div>

		<aside class="hio-side">
			<?php if ( $hio_coffee ) : ?>
				<div class="hio-card hio-coffee">
					<h3>☕ <?php esc_html_e( 'Free, forever', 'hole-in-one-migration' ); ?></h3>
					<p><?php esc_html_e( 'Hole-in-One Migration is 100% free: no paid upgrade, no size limit. If it saved your day, you can buy me a coffee!', 'hole-in-one-migration' ); ?></p>
					<p><a class="hio-coffee-btn" href="<?php echo esc_url( $hio_coffee ); ?>" target="_blank" rel="noopener noreferrer">☕ <?php esc_html_e( 'Buy me a coffee', 'hole-in-one-migration' ); ?></a></p>
				</div>
			<?php endif; ?>
			<div class="hio-card">
				<h3><?php esc_html_e( 'Quick guide', 'hole-in-one-migration' ); ?></h3>
				<ol class="hio-steps">
					<li><?php esc_html_e( 'Old site: Export → download the file.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'New host: install a fresh WordPress and this plugin.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'New site: Import → choose the file.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'Log in with your OLD username and password.', 'hole-in-one-migration' ); ?></li>
					<li><?php esc_html_e( 'Point your domain to the new host.', 'hole-in-one-migration' ); ?></li>
				</ol>
				<p><a href="<?php echo esc_url( add_query_arg( 'tab', 'help', $hio_page ) ); ?>"><?php esc_html_e( 'Full instructions →', 'hole-in-one-migration' ); ?></a></p>
			</div>
			<div class="hio-card hio-env">
				<h3><?php esc_html_e( 'This server', 'hole-in-one-migration' ); ?></h3>
				<?php
				$hio_free = HIO_Util::free_space( WP_CONTENT_DIR );
				$hio_env  = array(
					'PHP'                => PHP_VERSION,
					'max_execution_time' => ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) . 's' : '∞',
					'memory_limit'       => ini_get( 'memory_limit' ),
					'upload limit'       => size_format( wp_max_upload_size() ),
					'zlib (.tar.gz)'     => function_exists( 'deflate_init' ) ? '✔' : '✘',
					'free disk'          => null === $hio_free ? '?' : HIO_Util::size( $hio_free ),
					'storage writable'   => wp_is_writable( HIO_Storage::dir( 'work' ) ) ? '✔' : '✘',
				);
				?>
				<table class="hio-envtable">
					<?php foreach ( $hio_env as $hio_k => $hio_v ) : ?>
						<tr><th><?php echo esc_html( $hio_k ); ?></th><td><?php echo esc_html( $hio_v ); ?></td></tr>
					<?php endforeach; ?>
				</table>
				<p class="description"><?php esc_html_e( 'Low limits are fine: the plugin adapts to them.', 'hole-in-one-migration' ); ?></p>
			</div>
		</aside>
	</div>
</div>
