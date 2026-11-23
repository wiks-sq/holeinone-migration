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
foreach ( array( 'hole-in-one-migration', 'holeinone-migration' ) as $holemi_slug ) {
	foreach ( (array) glob( WP_PLUGIN_DIR . '/*/' . $holemi_slug . '.php' ) as $holemi_other ) {
		if ( realpath( dirname( $holemi_other ) ) !== realpath( __DIR__ ) ) {
			return;
		}
	}
}

delete_option( 'holemi_settings' );
delete_option( 'holemi_post_import' );

/**
 * Recursively delete a folder.
 *
 * @param string $dir Folder.
 */
function holemi_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$p = $dir . '/' . $item;
		if ( is_dir( $p ) && ! is_link( $p ) ) {
			holemi_uninstall_rrmdir( $p );
		} else {
			@unlink( $p ); // phpcs:ignore
		}
	}
	@rmdir( $dir ); // phpcs:ignore
}
// The storage folder (see HOLEMI_Storage): wp-content/hole-in-one-migration-<random>.
foreach ( (array) glob( untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/hole-in-one-migration-*', GLOB_ONLYDIR ) as $holemi_dir ) {
	if ( preg_match( '/^hole-in-one-migration-[a-f0-9]{24}$/', basename( $holemi_dir ) ) ) {
		holemi_uninstall_rrmdir( $holemi_dir );
	}
}
