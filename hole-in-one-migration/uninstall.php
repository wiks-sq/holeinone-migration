<?php
/**
 * Uninstall: remove settings, notices and the storage folder (jobs and backups).
 *
 * @package HoleInOneMigration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Another copy of this plugin is still installed (for example the pre-1.0.3
// "holeinone-migration" folder): it uses the same settings and backups, so
// leave them alone.
foreach ( array( 'hole-in-one-migration', 'holeinone-migration' ) as $hio_slug ) {
	foreach ( (array) glob( WP_PLUGIN_DIR . '/*/' . $hio_slug . '.php' ) as $hio_other ) {
		if ( realpath( dirname( $hio_other ) ) !== realpath( __DIR__ ) ) {
			return;
		}
	}
}

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
