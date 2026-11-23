<?php
/**
 * Export: scan files -> dump database -> build archive. Every phase works in
 * time-boxed chunks and checkpoints after each request.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-gigabyte archives and SQL dumps in small chunks across requests (fopen/fread/fwrite/fseek/ftruncate); WP_Filesystem has no streaming API and would load whole files into memory.

/**
 * Exporter.
 */
class HOLEMI_Exporter {

	/** Files up to this size are read in one go (bytes). */
	const SMALL_FILE = 4194304;

	/** Max length of one INSERT statement in the dump (bytes). */
	const MAX_STATEMENT = 1000000;

	/** Placeholder for the table prefix in the dump. */
	const PREFIX_TOKEN = '__HIOP__';

	/**
	 * Default exclusions (relative to wp-content). Glob patterns allowed.
	 *
	 * @return string[]
	 */
	public static function cache_excludes() {
		return array(
			'cache',
			'et-cache',
			'litespeed',
			'upgrade',
			'upgrade-temp-backup',
			'wflogs',
			'debug.log',
			'object-cache.php',
			'advanced-cache.php',
			'ai1wm-backups',
			'updraft',
			'backups-dup-lite',
			'backups-dup-pro',
			'wpvividbackups',
			'backwpup-*',
			'uploads/backwpup-*',
			'uploads/backup-guard',
			// Hosting-company must-use plugins that break on other hosts.
			'mu-plugins/wpengine-common',
			'mu-plugins/mu-plugin.php',
			'mu-plugins/wpe-*',
			'mu-plugins/kinsta-mu-plugins',
			'mu-plugins/kinsta-mu-plugins.php',
			'mu-plugins/gd-system-plugin',
			'mu-plugins/gd-system-plugin.php',
			'mu-plugins/endurance-*',
			'mu-plugins/sso.php',
		);
	}

	/**
	 * Start an export job.
	 *
	 * @param array $opts contents (both|files|db), format (tar.gz|zip), exclude_uploads.
	 * @return HOLEMI_Job
	 * @throws HOLEMI_Exception On error.
	 */
	public static function start( array $opts ) {
		global $wpdb;
		$settings = HOLEMI_Util::settings();
		$contents = in_array( $opts['contents'] ?? '', array( 'both', 'files', 'db' ), true ) ? $opts['contents'] : 'both';
		$format   = ( 'zip' === ( $opts['format'] ?? $settings['format'] ) ) ? 'zip' : 'tar.gz';

		if ( 'tar.gz' === $format && ! function_exists( 'deflate_init' ) ) {
			$format = 'zip';
		}

		$job = HOLEMI_Job::create( 'exp' );
		$s   = &$job->state;

		// Leftover of an unfinished import made with a version before 1.1.0.
		$excludes = array( 'mu-plugins/0000-hio-import-guard.php' );
		if ( ! empty( $settings['exclude_cache'] ) ) {
			$excludes = array_merge( $excludes, self::cache_excludes() );
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $settings['custom_excludes'] ) as $line ) {
			$line = trim( str_replace( '\\', '/', $line ), "/ \t" );
			if ( '' !== $line && false === strpos( $line, '..' ) ) {
				$line       = preg_replace( '#^wp-content/#', '', $line );
				$excludes[] = $line;
			}
		}

		$s['opts']     = array(
			'contents'        => $contents,
			'format'          => $format,
			'exclude_uploads' => ! empty( $opts['exclude_uploads'] ),
		);
		$s['excludes'] = array_values( array_unique( array_filter( $excludes ) ) );
		self::plan_roots( $job, ! empty( $opts['exclude_uploads'] ) );
		$s['phase']    = ( 'db' === $contents ) ? 'db' : 'scan';
		$s['scan']     = array(
			'stack'     => array_reverse( wp_list_pluck( $s['roots'], 'name' ) ),
			'list_size' => 0,
			'files'     => 0,
			'dirs'      => 0,
			'bytes'     => 0,
		);
		$s['message']  = __( 'Starting…', 'hole-in-one-migration' );

		$job->log( sprintf( 'Export started: contents=%s, format=%s, WordPress %s, PHP %s, max_execution_time=%s, memory_limit=%s', $contents, $format, get_bloginfo( 'version' ), PHP_VERSION, ini_get( 'max_execution_time' ), ini_get( 'memory_limit' ) ) );
		if ( 'files' !== $contents ) {
			$job->log( 'Database tables prefix: ' . $wpdb->prefix );
		}
		$job->save();
		return $job;
	}

	/**
	 * Decide which folders go into the archive and under which name.
	 *
	 * wp-content is stored as "wp-content/...". Plugins, must-use plugins,
	 * themes and uploads are usually inside it; when the site keeps one of them
	 * somewhere else (WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, a theme root or an upload
	 * path outside wp-content), that folder is stored as
	 * "outside-wp-content/<role>/..." and the manifest says which archive
	 * folder holds which role, so the importer puts it where the NEW site keeps
	 * that role.
	 *
	 * @param HOLEMI_Job $job             Job.
	 * @param bool       $exclude_uploads Leave out the media library.
	 */
	private static function plan_roots( HOLEMI_Job $job, $exclude_uploads ) {
		$s       = &$job->state;
		$content = HOLEMI_Util::content_dir();
		$roots   = array(
			array(
				'name' => 'wp-content',
				'path' => $content,
				'role' => 'content',
			),
		);
		$map     = array();
		// Never archived, wherever they are: this plugin and its storage folder.
		$skip = array( untrailingslashit( wp_normalize_path( plugin_dir_path( HOLEMI_FILE ) ) ), HOLEMI_Storage::base() );
		foreach ( HOLEMI_Util::roots() as $role => $dir ) {
			if ( HOLEMI_Util::inside( $dir, $content ) ) {
				$map[ $role ] = 'wp-content' . substr( $dir, strlen( $content ) );
			} elseif ( is_dir( $dir ) && ! HOLEMI_Util::inside( $content, $dir ) ) {
				$taken = false;
				foreach ( $roots as $r ) {
					$taken = $taken || HOLEMI_Util::inside( $dir, $r['path'] );
				}
				if ( ! $taken ) {
					$roots[] = array(
						'name' => 'outside-wp-content/' . $role,
						'path' => $dir,
						'role' => $role,
					);
					$job->log( sprintf( 'The %1$s folder is outside wp-content (%2$s); it is exported too.', $role, $dir ) );
				}
				$map[ $role ] = 'outside-wp-content/' . $role;
				// A folder with the default name left behind in wp-content is not used by this site.
				if ( is_dir( $content . '/' . $role ) ) {
					$skip[] = $content . '/' . $role;
					$job->log( sprintf( 'Skipped wp-content/%s: this site keeps its %s elsewhere.', $role, $role ) );
				}
			}
			if ( 'uploads' === $role && $exclude_uploads ) {
				$skip[] = $dir;
			}
		}
		$s['roots']      = $roots;
		$s['role_map']   = $map;
		$s['skip_paths'] = array_values( array_unique( $skip ) );
	}

	/**
	 * Absolute path of an archive name (or null if it is outside every root).
	 *
	 * @param string $name  Archive name without a trailing slash.
	 * @param array  $roots Roots from plan_roots().
	 * @return string|null
	 */
	private static function source_path( $name, array $roots ) {
		foreach ( $roots as $r ) {
			if ( $name === $r['name'] || 0 === strpos( $name, $r['name'] . '/' ) ) {
				return $r['path'] . substr( $name, strlen( $r['name'] ) );
			}
		}
		return null;
	}

	/**
	 * Path used for exclusion patterns: relative to wp-content, with folders
	 * stored outside it under their role name ("plugins/...", "uploads/...").
	 *
	 * @param string $name Archive name.
	 * @return string
	 */
	private static function logical( $name ) {
		if ( 0 === strpos( $name, 'wp-content/' ) ) {
			return substr( $name, 11 );
		}
		return preg_replace( '#^outside-wp-content/#', '', $name );
	}

	/**
	 * Do as much work as possible within the step budget.
	 *
	 * @param HOLEMI_Job $job Job.
	 */
	public static function step( HOLEMI_Job $job ) {
		while ( ! $job->should_stop() && 'done' !== $job->state['phase'] ) {
			switch ( $job->state['phase'] ) {
				case 'scan':
					self::scan( $job );
					break;
				case 'db':
					self::dump( $job );
					break;
				case 'archive':
					self::archive( $job );
					break;
				default:
					throw new HOLEMI_Exception( esc_html( 'Unknown phase.' ) );
			}
		}
	}

	/**
	 * Is a wp-content relative path excluded?
	 *
	 * @param string $rel      Path.
	 * @param array  $excludes Patterns.
	 * @return bool
	 */
	private static function excluded( $rel, array $excludes ) {
		foreach ( $excludes as $ex ) {
			if ( $rel === $ex || 0 === strpos( $rel, $ex . '/' ) ) {
				return true;
			}
			if ( false !== strpbrk( $ex, '*?[' ) && function_exists( 'fnmatch' ) && fnmatch( $ex, $rel ) ) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------------
	 * Phase 1: scan files into a list file.
	 * ------------------------------------------------------------------ */

	/**
	 * Scan directories until time runs out.
	 *
	 * @param HOLEMI_Job $job Job.
	 * @throws HOLEMI_Exception On error.
	 */
	private static function scan( HOLEMI_Job $job ) {
		$sc   = &$job->state['scan'];
		$list = $job->path( 'filelist.txt' );
		$fp   = fopen( $list, 'c+b' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new HOLEMI_Exception( esc_html__( 'Could not write the file list.', 'hole-in-one-migration' ) );
		}
		ftruncate( $fp, $sc['list_size'] );
		fseek( $fp, 0, SEEK_END );
		$roots = $job->state['roots'];
		$ex    = $job->state['excludes'];
		$skip  = $job->state['skip_paths'];
		$tops  = wp_list_pluck( $roots, 'name' );

		// The stack holds archive names of folders still to read ("wp-content/plugins", ...).
		while ( ! empty( $sc['stack'] ) && ! $job->should_stop() ) {
			$rel  = array_pop( $sc['stack'] );
			$full = self::source_path( $rel, $roots );
			$dh   = null === $full ? false : @opendir( $full ); // phpcs:ignore
			if ( ! $dh ) {
				$job->log( 'Cannot read folder, skipped: ' . $rel, 'warning' );
				continue;
			}
			if ( ! in_array( $rel, $tops, true ) ) {
				HOLEMI_Util::fwrite_all( $fp, $rel . "/\n" );
				$sc['dirs']++;
			}
			$subdirs = array();
			while ( false !== ( $name = readdir( $dh ) ) ) { // phpcs:ignore
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$r = $rel . '/' . $name;
				$p = $full . '/' . $name;
				if ( false !== strpbrk( $name, "\n\r" ) ) {
					$job->log( 'File name contains a line break, skipped: ' . $r, 'warning' );
					continue;
				}
				if ( self::excluded( self::logical( $r ), $ex ) || in_array( wp_normalize_path( $p ), $skip, true ) ) {
					continue;
				}
				if ( 'wp-content' === $rel && is_dir( $p ) && HOLEMI_Storage::is_storage_name( $name ) ) {
					continue; // Storage folder of this plugin (also an old or a copied one).
				}
				if ( is_dir( $p ) ) {
					if ( is_link( $p ) ) {
						$job->log( 'Symlinked folder skipped: ' . $r, 'warning' );
						continue;
					}
					$subdirs[] = $r;
				} elseif ( is_file( $p ) ) {
					if ( ! is_readable( $p ) ) {
						$job->log( 'File not readable, skipped: ' . $r, 'warning' );
						continue;
					}
					HOLEMI_Util::fwrite_all( $fp, $r . "\n" );
					$sc['files']++;
					$sc['bytes'] += (float) @filesize( $p ); // phpcs:ignore
				}
			}
			closedir( $dh );
			rsort( $subdirs );
			foreach ( $subdirs as $d ) {
				$sc['stack'][] = $d;
			}
		}
		fflush( $fp );
		$sc['list_size'] = ftell( $fp );
		fclose( $fp );

		/* translators: 1: number of files, 2: total size, e.g. 12 MB. */
		$job->state['message']  = sprintf( __( 'Scanning files: %1$s files found (%2$s)', 'hole-in-one-migration' ), HOLEMI_Util::num( $sc['files'] ), HOLEMI_Util::size( $sc['bytes'] ) );
		$job->state['progress'] = 2;

		if ( empty( $sc['stack'] ) ) {
			$job->log( sprintf( 'Scan complete: %d files in %d folders, %s', $sc['files'], $sc['dirs'], HOLEMI_Util::size( $sc['bytes'] ) ) );
			$free = HOLEMI_Util::free_space( HOLEMI_Storage::base() );
			if ( null !== $free && $free < $sc['bytes'] * 1.05 ) {
				$job->log( sprintf( 'Low disk space: %s free, the site has %s of files. The archive is compressed, but the export may fail if the disk fills up.', HOLEMI_Util::size( $free ), HOLEMI_Util::size( $sc['bytes'] ) ), 'warning' );
			}
			$job->state['phase'] = 'both' === $job->state['opts']['contents'] ? 'db' : 'archive';
		}
	}

	/* --------------------------------------------------------------------
	 * Phase 2: database dump (one statement per line).
	 * ------------------------------------------------------------------ */

	/**
	 * Prepare the list of tables.
	 *
	 * @param HOLEMI_Job $job Job.
	 */
	private static function dump_init( HOLEMI_Job $job ) {
		global $wpdb;
		$tables = array();
		$total  = 0;
		$status = array();
		foreach ( HOLEMI_DB::rows( 'SHOW TABLE STATUS', true ) as $r ) {
			$status[ $r['Name'] ] = $r;
		}
		foreach ( HOLEMI_DB::tables_with_prefix( $wpdb->prefix ) as $t ) {
			$cols     = HOLEMI_DB::columns( $t );
			$pk       = HOLEMI_DB::primary_key( $t );
			$rows     = isset( $status[ $t ]['Rows'] ) ? (int) $status[ $t ]['Rows'] : 0;
			$avg      = isset( $status[ $t ]['Avg_row_length'] ) ? (int) $status[ $t ]['Avg_row_length'] : 0;
			$batch    = $avg > 0 ? (int) max( 50, min( 5000, floor( 16777216 / $avg ) ) ) : 2000;
			$total   += $rows;
			$tables[] = array(
				'name'   => $t,
				'cols'   => $cols,
				'pk'     => $pk,
				'batch'  => $batch,
				'rows'   => $rows,
			);
		}
		$job->state['db'] = array(
			'tables'     => $tables,
			'ti'         => 0,
			'created'    => false,
			'last'       => null,
			'offset'     => 0,
			'sql_size'   => 0,
			'rows_done'  => 0,
			'rows_total' => max( 1, $total ),
		);
		$job->log( sprintf( 'Database: %d tables, about %s rows.', count( $tables ), HOLEMI_Util::num( $total ) ) );
		$header = '-- Hole-in-One Migration database dump' . "\n"
			. '-- Site: ' . HOLEMI_Util::raw_url( 'home' ) . "\n"
			. '-- Date: ' . gmdate( 'c' ) . "\n"
			. '-- Table prefix is replaced by ' . self::PREFIX_TOKEN . "\n"
			// Like mysqldump: makes the file import correctly with any MySQL
			// client too (without SET NAMES, a client that assumes another
			// character set would garble every non-ASCII character).
			. "/*!40101 SET NAMES utf8mb4 */;\n"
			. "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n"
			. "/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
		file_put_contents( $job->path( 'database.sql' ), $header ); // phpcs:ignore
		$job->state['db']['sql_size'] = strlen( $header );
	}

	/**
	 * Format a value as SQL literal according to column type.
	 *
	 * @param mixed  $v    Value.
	 * @param string $type Column type.
	 * @return string
	 */
	private static function literal( $v, $type ) {
		if ( null === $v ) {
			return 'NULL';
		}
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|bigint|decimal|float|double|year)/', $type ) && is_numeric( $v ) ) {
			return (string) $v;
		}
		if ( preg_match( '/binary|blob|^bit|geometry|point|polygon|linestring/', $type ) ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . HOLEMI_Util::sql_escape( $v ) . "'";
	}

	/**
	 * Dump rows until time runs out.
	 *
	 * @param HOLEMI_Job $job Job.
	 * @throws HOLEMI_Exception On error.
	 */
	private static function dump( HOLEMI_Job $job ) {
		global $wpdb;
		if ( ! isset( $job->state['db'] ) ) {
			self::dump_init( $job );
		}
		$db = &$job->state['db'];
		$fp = fopen( $job->path( 'database.sql' ), 'c+b' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new HOLEMI_Exception( esc_html__( 'Could not write the database dump.', 'hole-in-one-migration' ) );
		}
		ftruncate( $fp, $db['sql_size'] );
		fseek( $fp, 0, SEEK_END );
		$plen = strlen( $wpdb->prefix );

		while ( $db['ti'] < count( $db['tables'] ) && ! $job->should_stop() ) {
			$t     = $db['tables'][ $db['ti'] ];
			$name  = $t['name'];
			$qname = HOLEMI_Util::ident( $name );
			$tok   = HOLEMI_Util::ident( self::PREFIX_TOKEN . substr( $name, $plen ) );

			if ( ! $db['created'] ) {
				$row    = HOLEMI_DB::rows( 'SHOW CREATE TABLE ' . $qname );
				$create = isset( $row[0][1] ) ? $row[0][1] : '';
				if ( '' === $create ) {
					throw new HOLEMI_Exception( esc_html( 'Could not read structure of table ' . $name ) );
				}
				// Replace the prefix in all backticked identifiers (table + FK references).
				$create = preg_replace_callback(
					'/`((?:[^`]|``)+)`/',
					function ( $m ) use ( $wpdb, $plen ) {
						return 0 === strpos( $m[1], $wpdb->prefix ) ? '`' . self::PREFIX_TOKEN . substr( $m[1], $plen ) . '`' : $m[0];
					},
					$create
				);
				$create = str_replace( array( "\r\n", "\n", "\r" ), ' ', $create );
				HOLEMI_Util::fwrite_all( $fp, "\n-- Table " . $name . "\nDROP TABLE IF EXISTS " . $tok . ";\n" . $create . ";\n" );
				$db['created'] = true;
				$db['last']    = null;
				$db['offset']  = 0;
			}

			$cols = array_values(
				array_filter(
					$t['cols'],
					function ( $c ) {
						return ! $c['generated'];
					}
				)
			);
			$names = implode( ',', array_map( array( 'HOLEMI_Util', 'ident' ), wp_list_pluck( $cols, 'name' ) ) );
			$types = wp_list_pluck( $cols, 'type' );
			$batch = $job->scaled( $t['batch'], 5 );

			// Keyset pagination on the primary key (cost independent of position);
			// LIMIT offset only for the rare tables without a usable key.
			$col_names = wp_list_pluck( $cols, 'name' );
			$pk_idx    = array();
			foreach ( $t['pk'] as $k ) {
				$i = array_search( $k, $col_names, true );
				if ( false === $i ) {
					$pk_idx = array();
					break;
				}
				$pk_idx[] = $i;
			}
			$sql = 'SELECT ' . $names . ' FROM ' . $qname;
			if ( $pk_idx ) {
				if ( null !== $db['last'] ) {
					$sql .= ' WHERE ' . HOLEMI_DB::after( $t['pk'], (array) $db['last'] );
				}
				$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'HOLEMI_Util', 'ident' ), $t['pk'] ) ) . ' LIMIT ' . $batch;
			} else {
				$sql .= ' LIMIT ' . (int) $db['offset'] . ',' . $batch;
			}

			// Rows are streamed (unbuffered): memory use stays constant.
			$head = 'INSERT INTO ' . $tok . ' (' . $names . ') VALUES ';
			$buf  = '';
			$last = null;
			$n    = HOLEMI_DB::each(
				$sql,
				function ( $r ) use ( $fp, $head, $types, $pk_idx, &$buf, &$last ) {
					$vals = array();
					foreach ( $r as $i => $v ) {
						$vals[] = self::literal( $v, $types[ $i ] );
					}
					$tuple = '(' . implode( ',', $vals ) . ')';
					if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::MAX_STATEMENT ) {
						HOLEMI_Util::fwrite_all( $fp, $head . $buf . ";\n" );
						$buf = '';
					}
					$buf .= ( '' === $buf ? '' : ',' ) . $tuple;
					if ( $pk_idx ) {
						$last = array();
						foreach ( $pk_idx as $i ) {
							$last[] = $r[ $i ];
						}
					}
				}
			);
			if ( '' !== $buf ) {
				HOLEMI_Util::fwrite_all( $fp, $head . $buf . ";\n" );
			}
			$db['rows_done'] += $n;
			$db['offset']    += $n;
			if ( null !== $last ) {
				$db['last'] = $last;
			}
			if ( $n < $batch ) {
				$db['ti']++;
				$db['created'] = false;
			}
			/* translators: 1: table name, 2: table number, 3: number of tables. */
			$job->state['message'] = sprintf( __( 'Exporting database: table %1$s (%2$d of %3$d)', 'hole-in-one-migration' ), $name, min( $db['ti'] + 1, count( $db['tables'] ) ), count( $db['tables'] ) );
		}

		fflush( $fp );
		$db['sql_size'] = ftell( $fp );
		fclose( $fp );

		$job->state['progress'] = 3 + (int) ( 27 * min( 1, $db['rows_done'] / $db['rows_total'] ) );

		if ( $db['ti'] >= count( $db['tables'] ) ) {
			file_put_contents( $job->path( 'database.sql' ), "-- End of dump\n", FILE_APPEND ); // phpcs:ignore
			$db['sql_size'] = filesize( $job->path( 'database.sql' ) );
			$job->log( sprintf( 'Database export complete: %s rows, %s.', HOLEMI_Util::num( $db['rows_done'] ), HOLEMI_Util::size( $db['sql_size'] ) ) );
			$job->state['phase'] = 'archive';
		}
	}

	/* --------------------------------------------------------------------
	 * Phase 3: build the archive.
	 * ------------------------------------------------------------------ */

	/**
	 * Raw option from the options table (ignores constants/filters).
	 *
	 * @param string $name Option.
	 * @return string
	 */
	private static function raw_option( $name ) {
		global $wpdb;
		return (string) HOLEMI_DB::value( 'SELECT option_value FROM ' . HOLEMI_Util::ident( $wpdb->options ) . ' WHERE option_name = ' . HOLEMI_DB::quote( $name ) . ' LIMIT 1' );
	}

	/**
	 * Write the manifest and initialize the archive state.
	 *
	 * @param HOLEMI_Job $job Job.
	 */
	private static function archive_init( HOLEMI_Job $job ) {
		global $wpdb, $wp_version;
		$s        = &$job->state;
		$contents = $s['opts']['contents'];
		$dirs     = HOLEMI_Util::roots();
		$outside  = 'db' !== $contents && isset( $s['roots'] ) && count( $s['roots'] ) > 1;
		$manifest = array(
			'generator'      => 'holeinone-migration', // Archive format id: keep, older versions check it.
			'plugin_version' => HOLEMI_VERSION,
			// 2 = has folders outside wp-content, which versions before 1.1.0 would skip.
			'format_version' => $outside ? 2 : 1,
			'created'        => gmdate( 'c' ),
			'contents'       => $contents,
			'home'           => HOLEMI_Util::raw_url( 'home' ),
			'siteurl'        => HOLEMI_Util::raw_url( 'siteurl' ),
			'db_home'        => self::raw_option( 'home' ),
			'db_siteurl'     => self::raw_option( 'siteurl' ),
			'content_url'    => HOLEMI_Util::raw_content_urls()[0],
			'abspath'        => HOLEMI_Util::install_dir(),
			'content_dir'    => HOLEMI_Util::content_dir(),
			'uploads_url'    => HOLEMI_Util::raw_content_urls()[1],
			'uploads_dir'    => $dirs['uploads'],
			'plugins_dir'    => $dirs['plugins'],
			'mu_plugins_dir' => $dirs['mu-plugins'],
			'themes_dir'     => $dirs['themes'],
			// Archive folder of each role (see plan_roots()).
			'roots'          => isset( $s['role_map'] ) ? $s['role_map'] : array(),
			'prefix'         => $wpdb->prefix,
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'mysql_version'  => (string) HOLEMI_DB::value( 'SELECT VERSION()' ),
			'multisite'      => is_multisite(),
			'plugin_dir'     => dirname( plugin_basename( HOLEMI_FILE ) ),
			'files'          => isset( $s['scan']['files'] ) ? $s['scan']['files'] : 0,
			'bytes'          => isset( $s['scan']['bytes'] ) ? $s['scan']['bytes'] : 0,
			'db_rows'        => isset( $s['db']['rows_done'] ) ? $s['db']['rows_done'] : 0,
			'db_tables'      => isset( $s['db']['tables'] ) ? count( $s['db']['tables'] ) : 0,
			'active_theme'   => get_stylesheet(),
		);
		file_put_contents( $job->path( 'manifest.json' ), wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore

		$host  = sanitize_title( preg_replace( '#^https?://#', '', HOLEMI_Util::raw_url( 'home' ) ) );
		$token = bin2hex( random_bytes( 8 ) );
		$ext   = 'zip' === $s['opts']['format'] ? 'zip' : 'tar.gz';
		$label = array(
			'both'  => 'full',
			'files' => 'files',
			'db'    => 'database',
		);

		$pre = array(
			array(
				'name' => 'hio-manifest.json',
				'src'  => $job->path( 'manifest.json' ),
			),
		);
		if ( 'files' !== $contents ) {
			$pre[] = array(
				'name' => 'hio-database.sql',
				'src'  => $job->path( 'database.sql' ),
			);
		}
		$total = ( 'files' !== $contents ? (float) $s['db']['sql_size'] : 0 ) + ( 'db' !== $contents ? (float) $s['scan']['bytes'] : 0 );

		$s['arc'] = array(
			'file'       => substr( $host, 0, 50 ) . '-' . gmdate( 'Ymd-His' ) . '-' . $label[ $contents ] . '-' . $token . '.' . $ext,
			'pre'        => $pre,
			'pre_i'      => 0,
			'list_off'   => 0,
			'cur'        => null,
			'writer'     => array(),
			'bytes_done' => 0,
			'bytes_all'  => max( 1, $total ),
			'entries'    => 0,
		);
	}

	/**
	 * Add files to the archive until time runs out.
	 *
	 * @param HOLEMI_Job $job Job.
	 * @throws HOLEMI_Exception On error.
	 */
	private static function archive( HOLEMI_Job $job ) {
		if ( ! isset( $job->state['arc'] ) ) {
			self::archive_init( $job );
		}
		$s     = &$job->state;
		$a     = &$s['arc'];
		$zip   = 'zip' === $s['opts']['format'];
		$path  = $job->path( 'archive.part' );
		$w     = $zip ? new HOLEMI_Zip_Writer( $path, $job->path( 'central.bin' ), $a['writer'] ) : new HOLEMI_Tar_Writer( $path, $a['writer'] );
		$roots = isset( $s['roots'] ) ? $s['roots'] : array();
		$files = 'db' !== $s['opts']['contents'];
		$list  = $files ? fopen( $job->path( 'filelist.txt' ), 'rb' ) : null; // phpcs:ignore
		$done  = false;
		$chunk = $job->scaled( 2097152, 65536 );

		try {
			while ( ! $job->should_stop() ) {
				// 1) Continue a large file that was started earlier.
				if ( $a['cur'] ) {
					$cur = &$a['cur'];
					$src = base64_decode( $cur['src'] ); // phpcs:ignore
					$fh  = @fopen( $src, 'rb' ); // phpcs:ignore
					if ( $fh ) {
						fseek( $fh, $cur['done'] );
					}
					while ( $cur['done'] < $cur['size'] && ! $job->should_stop() ) {
						$data = $fh ? fread( $fh, (int) min( $chunk, $cur['size'] - $cur['done'] ) ) : false;
						if ( false === $data || '' === $data ) {
							$job->log( 'File changed or became unreadable while archiving, padded: ' . $cur['name'], 'warning' );
							break; // end_entry() pads the rest with zeros.
						}
						$w->write( $data );
						$cur['done']     += strlen( $data );
						$a['bytes_done'] += strlen( $data );
					}
					if ( $fh ) {
						fclose( $fh );
					}
					if ( $cur['done'] >= $cur['size'] || ! $job->should_stop() ) {
						$w->end_entry();
						$a['entries']++;
						$a['cur'] = null;
					}
					unset( $cur );
					continue;
				}

				// 2) Next entry: manifest and database first, then the file list.
				if ( $a['pre_i'] < count( $a['pre'] ) ) {
					$e         = $a['pre'][ $a['pre_i'] ];
					$name      = $e['name'];
					$src       = $e['src'];
					$a['pre_i']++;
				} elseif ( $list ) {
					fseek( $list, $a['list_off'] );
					$line = fgets( $list );
					if ( false === $line || $a['list_off'] >= $s['scan']['list_size'] ) {
						$done = true;
						break;
					}
					$a['list_off'] = ftell( $list );
					$rel           = rtrim( $line, "\n" );
					if ( '/' === substr( $rel, -1 ) ) {
						$dir = rtrim( $rel, '/' );
						$w->add_dir( $dir, (int) @filemtime( (string) self::source_path( $dir, $roots ) ) ); // phpcs:ignore
						$a['entries']++;
						continue;
					}
					$name = $rel;
					$src  = (string) self::source_path( $rel, $roots );
				} else {
					$done = true;
					break;
				}

				clearstatcache( true, $src );
				if ( ! is_file( $src ) || ! is_readable( $src ) ) {
					$job->log( 'File disappeared or is not readable, skipped: ' . $name, 'warning' );
					continue;
				}
				$size  = (int) filesize( $src );
				$mtime = (int) filemtime( $src );
				if ( $size <= self::SMALL_FILE ) {
					$data = HOLEMI_Util::read_file( $src );
					if ( false === $data ) {
						$job->log( 'Could not read file, skipped: ' . $name, 'warning' );
						continue;
					}
					$w->add_small( $name, $data, $mtime );
					$a['bytes_done'] += strlen( $data );
					$a['entries']++;
					unset( $data );
				} else {
					$w->begin_entry( $name, $size, $mtime );
					$a['cur'] = array(
						'name' => $name,
						'src'  => base64_encode( $src ), // phpcs:ignore
						'size' => $size,
						'done' => 0,
					);
				}
			}

			if ( $done ) {
				$w->finish();
			}
		} finally {
			$a['writer'] = $w->close();
			if ( $list ) {
				fclose( $list );
			}
		}

		$pct                    = min( 1, $a['bytes_done'] / $a['bytes_all'] );
		$s['progress']          = 30 + (int) ( 69 * $pct );
		/* translators: 1: size done, 2: total size, 3: number of files. */
		$s['message']           = sprintf( __( 'Creating archive: %1$s of %2$s (%3$s files)', 'hole-in-one-migration' ), HOLEMI_Util::size( $a['bytes_done'] ), HOLEMI_Util::size( $a['bytes_all'] ), HOLEMI_Util::num( $a['entries'] ) );

		if ( $done ) {
			self::finalize( $job );
		}
	}

	/**
	 * Move the archive to backups/ and clean up.
	 *
	 * @param HOLEMI_Job $job Job.
	 * @throws HOLEMI_Exception On error.
	 */
	private static function finalize( HOLEMI_Job $job ) {
		$s    = &$job->state;
		$dest = HOLEMI_Storage::dir( 'backups' ) . '/' . $s['arc']['file'];
		if ( ! @rename( $job->path( 'archive.part' ), $dest ) ) { // phpcs:ignore
			throw new HOLEMI_Exception( esc_html__( 'Could not move the finished archive into the backups folder.', 'hole-in-one-migration' ) );
		}
		$size = filesize( $dest );
		$meta = array(
			'contents' => $s['opts']['contents'],
			'format'   => $s['opts']['format'],
			'site'     => HOLEMI_Util::raw_url( 'home' ),
			'created'  => time(),
			'files'    => $s['scan']['files'] ?? 0,
			'warnings' => $s['warnings'],
		);
		file_put_contents( $dest . '.json', wp_json_encode( $meta ) ); // phpcs:ignore
		foreach ( array( 'database.sql', 'filelist.txt', 'central.bin' ) as $f ) {
			@unlink( $job->path( $f ) ); // phpcs:ignore
		}
		$job->log( sprintf( 'Archive complete: %s (%s, %d entries).', $s['arc']['file'], HOLEMI_Util::size( $size ), $s['arc']['entries'] ) );
		$s['phase']    = 'done';
		$s['status']   = 'done';
		$s['progress'] = 100;
		$s['message']  = __( 'Export complete!', 'hole-in-one-migration' );
		$s['result']   = array(
			'file' => $s['arc']['file'],
			'size' => $size,
		);
	}
}
