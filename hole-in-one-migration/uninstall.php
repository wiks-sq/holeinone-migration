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
// get_plugins() lists the installed plugins relative to the plugins folder,
// wherever the site keeps it; plugin_basename() gives this copy's entry.
$holemi_self = dirname( plugin_basename( __DIR__ . '/hole-in-one-migration.php' ) );
foreach ( array_keys( function_exists( 'get_plugins' ) ? get_plugins() : array() ) as $holemi_other ) {
	if ( in_array( basename( $holemi_other ), array( 'hole-in-one-migration.php', 'holeinone-migration.php' ), true ) && dirname( $holemi_other ) !== $holemi_self ) {
		return;
	}
}

// The plugin's own helpers resolve the storage folder (see HOLEMI_Storage).
if ( ! class_exists( 'HOLEMI_Util' ) ) {
	require_once __DIR__ . '/includes/class-holemi-util.php';
}
if ( ! class_exists( 'HOLEMI_Storage' ) ) {
	require_once __DIR__ . '/includes/class-holemi-storage.php';
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
foreach ( (array) glob( HOLEMI_Util::content_dir() . '/' . HOLEMI_Storage::PREFIX . '*', GLOB_ONLYDIR ) as $holemi_dir ) {
	if ( preg_match( HOLEMI_Storage::NAME_RE, basename( $holemi_dir ) ) ) {
		holemi_uninstall_rrmdir( $holemi_dir );
	}
}
