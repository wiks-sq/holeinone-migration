<?php
/**
 * Plugin Name:       Hole-in-One Migration
 * Plugin URI:        https://wordpress.org/plugins/hole-in-one-migration/
 * Description:       Move a complete WordPress site (files + database) to a new web host in one shot. Every step runs in small, resumable chunks, so PHP timeouts and flaky connections cannot break your migration.
 * Version:           1.1.1
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Hole-in-One Migration
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hole-in-one-migration
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

define( 'HOLEMI_VERSION', '1.1.1' );
define( 'HOLEMI_FORMAT_VERSION', 2 ); // Newest archive format this version reads.
define( 'HOLEMI_FILE', __FILE__ );
define( 'HOLEMI_DIR', plugin_dir_path( __FILE__ ) );
define( 'HOLEMI_URL', plugin_dir_url( __FILE__ ) );

/*
 * ---------------------------------------------------------------------------
 *  Buy Me a Coffee
 * ---------------------------------------------------------------------------
 * This plugin is free. Put your own Buy Me a Coffee page here and it is shown
 * in the plugin sidebar and in the "Plugins" list. You can also override it
 * from wp-config.php with: define( 'HOLEMI_BUYMEACOFFEE_URL', 'https://...' );
 * Set it to an empty string to hide the donation box completely.
 */
if ( ! defined( 'HOLEMI_BUYMEACOFFEE_URL' ) ) {
	define( 'HOLEMI_BUYMEACOFFEE_URL', 'https://buymeacoffee.com/wikssq' );
}

require_once HOLEMI_DIR . 'includes/class-holemi-util.php';
require_once HOLEMI_DIR . 'includes/class-holemi-db.php';
require_once HOLEMI_DIR . 'includes/class-holemi-storage.php';
require_once HOLEMI_DIR . 'includes/class-holemi-job.php';
require_once HOLEMI_DIR . 'includes/class-holemi-tar-writer.php';
require_once HOLEMI_DIR . 'includes/class-holemi-tar-parser.php';
require_once HOLEMI_DIR . 'includes/class-holemi-zip-writer.php';
require_once HOLEMI_DIR . 'includes/class-holemi-zip-reader.php';
require_once HOLEMI_DIR . 'includes/class-holemi-search-replace.php';
require_once HOLEMI_DIR . 'includes/class-holemi-exporter.php';
require_once HOLEMI_DIR . 'includes/class-holemi-importer.php';
require_once HOLEMI_DIR . 'includes/class-holemi-plugin.php';

HOLEMI_Plugin::instance();
