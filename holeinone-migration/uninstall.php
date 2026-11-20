<?php
/**
 * Uninstall: remove settings, notices and the storage folder (jobs and backups).
 *
 * @package HoleInOneMigration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hio_settings' );
delete_option( 'hio_post_import' );

$hio_guard = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/0000-hio-import-guard.php';
if ( is_file( $hio_guard ) ) {
	@unlink( $hio_guard ); // phpcs:ignore
}

/**
 * Recursively delete a folder.
 *
 * @param string $dir Folder.
 */
function hio_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$p = $dir . '/' . $item;
		if ( is_dir( $p ) && ! is_link( $p ) ) {
			hio_uninstall_rrmdir( $p );
		} else {
			@unlink( $p ); // phpcs:ignore
		}
	}
	@rmdir( $dir ); // phpcs:ignore
}
hio_uninstall_rrmdir( rtrim( WP_CONTENT_DIR, '/\\' ) . '/hio-migration' );
