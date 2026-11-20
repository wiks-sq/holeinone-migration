<?php
/**
 * Storage folders for jobs, finished backups and archives uploaded by FTP.
 *
 * wp-content/hio-migration/
 *   work/     running jobs (state, temporary SQL dump, partial archives) - denied
 *   backups/  finished export archives (random, unguessable file names)
 *   import/   drop archives here by FTP/SFTP to import them - denied
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage paths.
 */
class HIO_Storage {

	const DIRNAME = 'hio-migration';

	/**
	 * Base folder.
	 *
	 * @return string
	 */
	public static function base() {
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . self::DIRNAME;
	}

	/**
	 * Sub folder path.
	 *
	 * @param string $sub work|backups|import.
	 * @return string
	 */
	public static function dir( $sub ) {
		return self::base() . '/' . $sub;
	}

	/**
	 * Public URL of a file in backups/.
	 *
	 * @param string $file File name.
	 * @return string
	 */
	public static function backup_url( $file ) {
		return content_url( self::DIRNAME . '/backups/' . rawurlencode( $file ) );
	}

	/**
	 * Create folders and protection files. Safe to call often.
	 *
	 * @return bool
	 */
	public static function ensure() {
		$deny = "# Hole-in-One Migration: no web access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

		$backups = "# Hole-in-One Migration: archives are downloadable by their secret name only\nOptions -Indexes\n<FilesMatch \"\\.(json|sql|txt|log|part|php)$\">\n\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n\t<IfModule !mod_authz_core.c>\n\t\tOrder allow,deny\n\t\tDeny from all\n\t</IfModule>\n</FilesMatch>\n<IfModule mod_mime.c>\n\tAddType application/octet-stream .gz .zip\n</IfModule>\n";

		$web_config = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>';

		$ok = wp_mkdir_p( self::base() );
		foreach ( array( 'work', 'backups', 'import' ) as $sub ) {
			$ok = wp_mkdir_p( self::dir( $sub ) ) && $ok;
		}
		$files = array(
			self::base() . '/index.php'           => "<?php // Silence is golden.\n",
			self::base() . '/.htaccess'           => "Options -Indexes\n",
			self::dir( 'work' ) . '/index.php'    => "<?php // Silence is golden.\n",
			self::dir( 'work' ) . '/.htaccess'    => $deny,
			self::dir( 'work' ) . '/web.config'   => $web_config,
			self::dir( 'import' ) . '/index.php'  => "<?php // Silence is golden.\n",
			self::dir( 'import' ) . '/.htaccess'  => $deny,
			self::dir( 'import' ) . '/web.config' => $web_config,
			self::dir( 'backups' ) . '/index.php' => "<?php // Silence is golden.\n",
			self::dir( 'backups' ) . '/.htaccess' => $backups,
		);
		foreach ( $files as $path => $content ) {
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $content ); // phpcs:ignore
			}
		}
		return $ok && wp_is_writable( self::dir( 'work' ) );
	}

	/**
	 * Path of wp-content relative storage folder (used for exclusions).
	 *
	 * @return string
	 */
	public static function rel() {
		return self::DIRNAME;
	}

	/**
	 * Finished backups, newest first.
	 *
	 * @return array[]
	 */
	public static function backups() {
		$out = array();
		$dir = self::dir( 'backups' );
		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			$name = basename( $path );
			if ( ! preg_match( '/\.(tar\.gz|zip)$/', $name ) || ! is_file( $path ) ) {
				continue;
			}
			$meta = array();
			if ( is_file( $path . '.json' ) ) {
				$meta = json_decode( (string) file_get_contents( $path . '.json' ), true ); // phpcs:ignore
			}
			$out[] = array(
				'name'  => $name,
				'path'  => $path,
				'size'  => filesize( $path ),
				'time'  => filemtime( $path ),
				'meta'  => is_array( $meta ) ? $meta : array(),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return $out;
	}

	/**
	 * Archives waiting in import/ (uploaded by FTP).
	 *
	 * @return array[]
	 */
	public static function import_files() {
		$out = array();
		foreach ( (array) glob( self::dir( 'import' ) . '/*' ) as $path ) {
			$name = basename( $path );
			if ( preg_match( '/\.(tar\.gz|tgz|zip)$/i', $name ) && is_file( $path ) ) {
				$out[] = array(
					'name' => $name,
					'size' => filesize( $path ),
					'time' => filemtime( $path ),
				);
			}
		}
		return $out;
	}

	/**
	 * Recursively delete a folder that lives inside our storage.
	 *
	 * @param string $dir Folder.
	 */
	public static function rrmdir( $dir ) {
		$base = self::base();
		if ( '' === $dir || 0 !== strpos( wp_normalize_path( $dir ), wp_normalize_path( $base ) . '/' ) || ! is_dir( $dir ) ) {
			return;
		}
		$items = @scandir( $dir ); // phpcs:ignore
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$p = $dir . '/' . $item;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				self::rrmdir( $p );
			} else {
				@unlink( $p ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
