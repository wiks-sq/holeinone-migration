<?php
/**
 * Plugin Name:       Hole-in-One Migration
 * Plugin URI:        https://wordpress.org/plugins/holeinone-migration/
 * Description:       Move a complete WordPress site (files + database) to a new web host in one shot. Every step runs in small, resumable chunks, so PHP timeouts and flaky connections cannot break your migration.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Hole-in-One Migration
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       holeinone-migration
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

define( 'HIO_VERSION', '1.0.0' );
define( 'HIO_FORMAT_VERSION', 1 );
define( 'HIO_FILE', __FILE__ );
define( 'HIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIO_URL', plugin_dir_url( __FILE__ ) );

/*
 * ---------------------------------------------------------------------------
 *  Buy Me a Coffee
 * ---------------------------------------------------------------------------
 * This plugin is free. Put your own Buy Me a Coffee page here and it is shown
 * in the plugin sidebar and in the "Plugins" list. You can also override it
 * from wp-config.php with: define( 'HIO_BUYMEACOFFEE_URL', 'https://...' );
 * Set it to an empty string to hide the donation box completely.
 */
if ( ! defined( 'HIO_BUYMEACOFFEE_URL' ) ) {
	define( 'HIO_BUYMEACOFFEE_URL', 'https://buymeacoffee.com/wikssq' );
}

require_once HIO_DIR . 'includes/class-hio-util.php';
require_once HIO_DIR . 'includes/class-hio-db.php';
require_once HIO_DIR . 'includes/class-hio-storage.php';
require_once HIO_DIR . 'includes/class-hio-job.php';
require_once HIO_DIR . 'includes/class-hio-tar-writer.php';
require_once HIO_DIR . 'includes/class-hio-tar-parser.php';
require_once HIO_DIR . 'includes/class-hio-zip-writer.php';
require_once HIO_DIR . 'includes/class-hio-zip-reader.php';
require_once HIO_DIR . 'includes/class-hio-search-replace.php';
require_once HIO_DIR . 'includes/class-hio-exporter.php';
require_once HIO_DIR . 'includes/class-hio-importer.php';
require_once HIO_DIR . 'includes/class-hio-plugin.php';

HIO_Plugin::instance();
