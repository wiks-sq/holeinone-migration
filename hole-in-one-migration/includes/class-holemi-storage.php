<?php
/**
 * Storage folders for jobs, finished backups and archives uploaded by FTP.
 *
 * wp-content/hole-in-one-migration-<24 random hex digits>/
 *   work/     running jobs (state, temporary SQL dump, partial archives)
 *   backups/  finished export archives
 *   import/   drop archives here by FTP/SFTP to import them
 *
 * A migration plugin needs storage that does not depend on the database (an
 * import replaces it) or on the per-site uploads folder. None of these files
 * is ever served by the web server directly:
 *  - The folder name contains 96 random bits and is not linked from anywhere,
 *    so it cannot be found on servers that ignore .htaccess (nginx, Apache
 *    with AllowOverride None). Job folders and archive names are random too.
 *  - On Apache and IIS, .htaccess and web.config files deny all access.
 *  - Every folder has an index.php, so it cannot be listed.
 *  - Archives are downloaded only through the authenticated download handler
 *    (HOLEMI_Plugin::download(): administrators only, with a nonce).
 * The random name is found again by looking for the folder on disk, not in the
 * database, so it survives the database being replaced by an import.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Writes the small protection files (index.php, .htaccess, web.config) of its own storage folder; WP_Filesystem may need FTP credentials in an AJAX request.

/**
 * Storage paths.
 */
class HOLEMI_Storage {

	/** Folder name prefix inside wp-content (the plugin slug). */
	const PREFIX = 'hole-in-one-migration-';

	/** Pattern of a storage folder name. */
	const NAME_RE = '/^hole-in-one-migration-[a-f0-9]{24}$/';

	/**
	 * Base folder of this request (found or chosen once).
	 *
	 * @var string|null
	 */
	private static $base = null;

	/**
	 * Base folder.
	 *
	 * @return string
	 */
	public static function base() {
		if ( null === self::$base ) {
			$content = HOLEMI_Util::content_dir();
			$found   = array();
			foreach ( (array) glob( $content . '/' . self::PREFIX . '*', GLOB_ONLYDIR ) as $d ) {
				if ( preg_match( self::NAME_RE, basename( $d ) ) ) {
					$found[] = $d;
				}
			}
			sort( $found );
			self::$base = $found ? wp_normalize_path( $found[0] ) : $content . '/' . self::PREFIX . bin2hex( random_bytes( 12 ) );
		}
		return self::$base;
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
	 * Is this a folder name of this plugin's storage (any site's, or the
	 * folder of versions before 1.1.0)? Such folders are never exported or
	 * extracted.
	 *
	 * @param string $name Folder name.
	 * @return bool
	 */
	public static function is_storage_name( $name ) {
		return 'hio-migration' === $name || (bool) preg_match( self::NAME_RE, $name );
	}

	/**
	 * Create folders and protection files. Safe to call often.
	 *
	 * @return bool
	 */
	public static function ensure() {
		$deny = "# Hole-in-One Migration: no web access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n";

		$web_config = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization><directoryBrowse enabled="false" /></system.webServer></configuration>';

		$ok = wp_mkdir_p( self::base() );
		foreach ( array( '', '/work', '/backups', '/import' ) as $sub ) {
			$dir = self::base() . $sub;
			$ok  = wp_mkdir_p( $dir ) && $ok;
			foreach ( array(
				'index.php'  => "<?php // Silence is golden.\n",
				'.htaccess'  => $deny,
				'web.config' => $web_config,
			) as $file => $content ) {
				// Keep .htaccess current (a deny rule changed by hand or by a future version is restored).
				$path = $dir . '/' . $file;
				if ( is_dir( $dir ) && ( ! file_exists( $path ) || ( '.htaccess' === $file && HOLEMI_Util::read_file( $path ) !== $content ) ) ) {
					@file_put_contents( $path, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		return $ok && wp_is_writable( self::dir( 'work' ) );
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
				$meta = json_decode( (string) HOLEMI_Util::read_file( $path . '.json' ), true );
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
