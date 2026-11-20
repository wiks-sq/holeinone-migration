<?php
/**
 * Small helpers shared by all components.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-gigabyte archives and SQL dumps in small chunks across requests (fopen/fread/fwrite/fseek/ftruncate); WP_Filesystem has no streaming API and would load whole files into memory.

/**
 * Exception used for errors that should be shown to the user.
 */
class HIO_Exception extends Exception {}

/**
 * Utility functions.
 */
class HIO_Util {

	/**
	 * Exception messages are HTML-escaped where they are created (so they are
	 * safe even if PHP ever prints an uncaught one). This turns one back into
	 * plain text for JSON responses and the log, which are shown as text.
	 *
	 * @param Throwable|string $e Exception or message.
	 * @return string
	 */
	public static function plain( $e ) {
		$m = $e instanceof Throwable ? $e->getMessage() : (string) $e;
		return html_entity_decode( $m, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	const OPTION = 'hio_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'format'          => 'tar.gz',
			'time_budget'     => 15,
			'pause_ms'        => 250,
			'exclude_cache'   => 1,
			'custom_excludes' => '',
			'delete_after'    => 1,
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Sanitize settings coming from the settings form.
	 *
	 * @param mixed $in Raw input.
	 * @return array
	 */
	public static function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = self::defaults();

		$out['format']          = ( isset( $in['format'] ) && 'zip' === $in['format'] ) ? 'zip' : 'tar.gz';
		$out['time_budget']     = isset( $in['time_budget'] ) ? max( 3, min( 120, (int) $in['time_budget'] ) ) : 15;
		$out['pause_ms']        = isset( $in['pause_ms'] ) ? max( 0, min( 10000, (int) $in['pause_ms'] ) ) : 250;
		$out['exclude_cache']   = empty( $in['exclude_cache'] ) ? 0 : 1;
		$out['delete_after']    = empty( $in['delete_after'] ) ? 0 : 1;
		$out['custom_excludes'] = isset( $in['custom_excludes'] ) ? sanitize_textarea_field( wp_unslash( $in['custom_excludes'] ) ) : '';
		return $out;
	}

	/**
	 * Seconds a single request may work, taking the PHP limit into account.
	 *
	 * @param float $wanted Desired budget.
	 * @return float
	 */
	public static function effective_budget( $wanted ) {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			// Never use more than half of what PHP allows; the rest is safety margin
			// for WordPress bootstrap and for writing the checkpoint.
			$wanted = min( $wanted, max( 2, $max * 0.5 ) );
		}
		return max( 2, (float) $wanted );
	}

	/**
	 * Prepare PHP for a work step: no abort on disconnect, generous limits.
	 *
	 * @param float $budget Seconds the step will work.
	 */
	public static function prepare_runtime( $budget ) {
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			// Each step stops itself after $budget seconds; this only stops hosts with a very
			// low limit from killing the request before its checkpoint. Never unlimited.
			@set_time_limit( (int) max( 120, $budget * 4 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
	}

	/**
	 * Memory limit in bytes (0 = unlimited).
	 *
	 * @return int
	 */
	public static function memory_limit() {
		$v = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $v || '-1' === $v ) {
			return 0;
		}
		return (int) wp_convert_hr_to_bytes( $v );
	}

	/**
	 * Human readable size.
	 *
	 * @param int|float $bytes Bytes.
	 * @return string
	 */
	public static function size( $bytes ) {
		return self::text( size_format( max( 0, (float) $bytes ), $bytes >= 1048576 ? 1 : 0 ) );
	}

	/**
	 * A number formatted for the site's language, as plain text. Some languages
	 * use "&nbsp;" as thousands separator, which would show up literally in
	 * progress messages (they are shown as text, not HTML).
	 *
	 * @param float $n Number.
	 * @return string
	 */
	public static function num( $n ) {
		return self::text( number_format_i18n( $n ) );
	}

	/**
	 * HTML entities to plain text.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function text( $s ) {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The site address as configured (wp-config.php constant or database),
	 * never through filters: translation plugins add a language path such as
	 * "/en" to home_url() and CDN plugins rewrite addresses, which would break
	 * search & replace on the new host.
	 *
	 * @param string $which home|siteurl.
	 * @return string
	 */
	public static function raw_url( $which ) {
		$const = 'home' === $which ? 'WP_HOME' : 'WP_SITEURL';
		if ( defined( $const ) && constant( $const ) ) {
			return untrailingslashit( (string) constant( $const ) );
		}
		global $wpdb;
		return untrailingslashit( (string) HIO_DB::value( 'SELECT option_value FROM ' . self::ident( $wpdb->options ) . ' WHERE option_name = ' . HIO_DB::quote( $which ) . ' LIMIT 1' ) );
	}

	/**
	 * Content and uploads addresses without filters (see raw_url()).
	 *
	 * @return string[] content_url, uploads_url.
	 */
	public static function raw_content_urls() {
		$content = defined( 'WP_CONTENT_URL' ) ? untrailingslashit( WP_CONTENT_URL ) : untrailingslashit( content_url() );
		$up      = function_exists( '_wp_upload_dir' ) ? _wp_upload_dir() : wp_upload_dir( null, false );
		return array( $content, untrailingslashit( $up['baseurl'] ) );
	}

	/**
	 * Escape a value for a MySQL string literal. Also escapes new lines so a
	 * statement always fits on one line in the dump file.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	public static function sql_escape( $v ) {
		return strtr(
			(string) $v,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/**
	 * Quote a MySQL identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Escape a value for use inside LIKE '...'.
	 *
	 * @param string $v Value.
	 * @return string
	 */
	public static function like_escape( $v ) {
		return self::sql_escape( addcslashes( $v, '_%\\' ) );
	}

	/**
	 * Combine two CRC-32 values (port of zlib's crc32_combine). Lets us compute
	 * the CRC of a huge file in pieces spread over many requests.
	 *
	 * @param int $crc1 CRC of the first part.
	 * @param int $crc2 CRC of the second part.
	 * @param int $len2 Length of the second part.
	 * @return int
	 */
	public static function crc32_combine( $crc1, $crc2, $len2 ) {
		if ( $len2 <= 0 ) {
			return $crc1;
		}
		$odd    = array( 0xedb88320 );
		$row    = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}
		$even = self::gf2_square( $odd );
		$odd  = self::gf2_square( $even );
		do {
			$even = self::gf2_square( $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( 0 === $len2 ) {
				break;
			}
			$odd = self::gf2_square( $even );
			if ( $len2 & 1 ) {
				$crc1 = self::gf2_times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( 0 !== $len2 );
		return ( $crc1 ^ $crc2 ) & 0xFFFFFFFF;
	}

	/**
	 * GF(2) matrix times vector.
	 *
	 * @param array $mat Matrix.
	 * @param int   $vec Vector.
	 * @return int
	 */
	private static function gf2_times( $mat, $vec ) {
		$sum = 0;
		$i   = 0;
		while ( $vec ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
			$vec >>= 1;
			$i++;
		}
		return $sum;
	}

	/**
	 * GF(2) matrix squared.
	 *
	 * @param array $mat Matrix.
	 * @return array
	 */
	private static function gf2_square( $mat ) {
		$sq = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$sq[ $n ] = self::gf2_times( $mat, $mat[ $n ] );
		}
		return $sq;
	}

	/**
	 * Write the whole buffer to a stream or throw.
	 *
	 * @param resource $fp   Stream.
	 * @param string   $data Data.
	 * @throws HIO_Exception When the disk is full or not writable.
	 */
	public static function fwrite_all( $fp, $data ) {
		$len = strlen( $data );
		$off = 0;
		while ( $off < $len ) {
			$w = fwrite( $fp, 0 === $off ? $data : substr( $data, $off ) );
			if ( false === $w || 0 === $w ) {
				throw new HIO_Exception( esc_html__( 'Could not write to disk. Is the disk full or the folder not writable?', 'hole-in-one-migration' ) );
			}
			$off += $w;
		}
	}

	/**
	 * Free disk space for a path, or null when unknown.
	 *
	 * @param string $path Path.
	 * @return float|null
	 */
	public static function free_space( $path ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return null;
		}
		$v = @disk_free_space( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $v ? null : (float) $v;
	}
}
