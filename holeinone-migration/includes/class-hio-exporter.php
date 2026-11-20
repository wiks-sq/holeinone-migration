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
class HIO_Exporter {

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
	 * @return HIO_Job
	 * @throws HIO_Exception On error.
	 */
	public static function start( array $opts ) {
		global $wpdb;
		$settings = HIO_Util::settings();
		$contents = in_array( $opts['contents'] ?? '', array( 'both', 'files', 'db' ), true ) ? $opts['contents'] : 'both';
		$format   = ( 'zip' === ( $opts['format'] ?? $settings['format'] ) ) ? 'zip' : 'tar.gz';

		if ( 'tar.gz' === $format && ! function_exists( 'deflate_init' ) ) {
			$format = 'zip';
		}

		$job = HIO_Job::create( 'exp' );
		$s   = &$job->state;

		$excludes   = array( HIO_Storage::rel(), 'plugins/' . dirname( plugin_basename( HIO_FILE ) ), 'mu-plugins/0000-hio-import-guard.php' );
		if ( ! empty( $opts['exclude_uploads'] ) ) {
			$excludes[] = self::uploads_rel();
		}
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
		$s['phase']    = ( 'db' === $contents ) ? 'db' : 'scan';
		$s['scan']     = array(
			'stack'     => array( '' ),
			'list_size' => 0,
			'files'     => 0,
			'dirs'      => 0,
			'bytes'     => 0,
		);
		$s['message']  = __( 'Starting…', 'holeinone-migration' );

		$job->log( sprintf( 'Export started: contents=%s, format=%s, WordPress %s, PHP %s, max_execution_time=%s, memory_limit=%s', $contents, $format, get_bloginfo( 'version' ), PHP_VERSION, ini_get( 'max_execution_time' ), ini_get( 'memory_limit' ) ) );
		if ( 'files' !== $contents ) {
			$job->log( 'Database tables prefix: ' . $wpdb->prefix );
		}
		$job->save();
		return $job;
	}

	/**
	 * Uploads folder relative to wp-content.
	 *
	 * @return string
	 */
	private static function uploads_rel() {
		$up   = wp_upload_dir( null, false );
		$base = wp_normalize_path( $up['basedir'] );
		$wpc  = wp_normalize_path( WP_CONTENT_DIR );
		if ( 0 === strpos( $base, $wpc . '/' ) ) {
			return substr( $base, strlen( $wpc ) + 1 );
		}
		return 'uploads';
	}

	/**
	 * Do as much work as possible within the step budget.
	 *
	 * @param HIO_Job $job Job.
	 */
	public static function step( HIO_Job $job ) {
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
					throw new HIO_Exception( esc_html( 'Unknown phase.' ) );
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
	 * @param HIO_Job $job Job.
	 * @throws HIO_Exception On error.
	 */
	private static function scan( HIO_Job $job ) {
		$sc   = &$job->state['scan'];
		$list = $job->path( 'filelist.txt' );
		$fp   = fopen( $list, 'c+b' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new HIO_Exception( esc_html__( 'Could not write the file list.', 'holeinone-migration' ) );
		}
		ftruncate( $fp, $sc['list_size'] );
		fseek( $fp, 0, SEEK_END );
		$root = rtrim( WP_CONTENT_DIR, '/\\' );
		$ex   = $job->state['excludes'];

		while ( ! empty( $sc['stack'] ) && ! $job->should_stop() ) {
			$rel  = array_pop( $sc['stack'] );
			$full = '' === $rel ? $root : $root . '/' . $rel;
			$dh   = @opendir( $full ); // phpcs:ignore
			if ( ! $dh ) {
				$job->log( 'Cannot read folder, skipped: wp-content/' . $rel, 'warning' );
				continue;
			}
			if ( '' !== $rel ) {
				HIO_Util::fwrite_all( $fp, $rel . "/\n" );
				$sc['dirs']++;
			}
			$subdirs = array();
			while ( false !== ( $name = readdir( $dh ) ) ) { // phpcs:ignore
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$r = '' === $rel ? $name : $rel . '/' . $name;
				$p = $full . '/' . $name;
				if ( false !== strpbrk( $name, "\n\r" ) ) {
					$job->log( 'File name contains a line break, skipped: wp-content/' . $r, 'warning' );
					continue;
				}
				if ( self::excluded( $r, $ex ) ) {
					continue;
				}
				if ( is_dir( $p ) ) {
					if ( is_link( $p ) ) {
						$job->log( 'Symlinked folder skipped: wp-content/' . $r, 'warning' );
						continue;
					}
					$subdirs[] = $r;
				} elseif ( is_file( $p ) ) {
					if ( ! is_readable( $p ) ) {
						$job->log( 'File not readable, skipped: wp-content/' . $r, 'warning' );
						continue;
					}
					HIO_Util::fwrite_all( $fp, $r . "\n" );
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
		$job->state['message']  = sprintf( __( 'Scanning files: %1$s files found (%2$s)', 'holeinone-migration' ), number_format_i18n( $sc['files'] ), HIO_Util::size( $sc['bytes'] ) );
		$job->state['progress'] = 2;

		if ( empty( $sc['stack'] ) ) {
			$job->log( sprintf( 'Scan complete: %d files in %d folders, %s', $sc['files'], $sc['dirs'], HIO_Util::size( $sc['bytes'] ) ) );
			$free = HIO_Util::free_space( HIO_Storage::base() );
			if ( null !== $free && $free < $sc['bytes'] * 1.05 ) {
				$job->log( sprintf( 'Low disk space: %s free, the site has %s of files. The archive is compressed, but the export may fail if the disk fills up.', HIO_Util::size( $free ), HIO_Util::size( $sc['bytes'] ) ), 'warning' );
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
	 * @param HIO_Job $job Job.
	 */
	private static function dump_init( HIO_Job $job ) {
		global $wpdb;
		$tables = array();
		$total  = 0;
		$status = array();
		foreach ( HIO_DB::rows( 'SHOW TABLE STATUS', true ) as $r ) {
			$status[ $r['Name'] ] = $r;
		}
		foreach ( HIO_DB::tables_with_prefix( $wpdb->prefix ) as $t ) {
			$cols     = HIO_DB::columns( $t );
			$pk       = HIO_DB::primary_key( $t );
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
		$job->log( sprintf( 'Database: %d tables, about %s rows.', count( $tables ), number_format_i18n( $total ) ) );
		$header = '-- Hole-in-One Migration database dump' . "\n"
			. '-- Site: ' . home_url() . "\n"
			. '-- Date: ' . gmdate( 'c' ) . "\n"
			. '-- Table prefix is replaced by ' . self::PREFIX_TOKEN . "\n";
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
		return "'" . HIO_Util::sql_escape( $v ) . "'";
	}

	/**
	 * Dump rows until time runs out.
	 *
	 * @param HIO_Job $job Job.
	 * @throws HIO_Exception On error.
	 */
	private static function dump( HIO_Job $job ) {
		global $wpdb;
		if ( ! isset( $job->state['db'] ) ) {
			self::dump_init( $job );
		}
		$db = &$job->state['db'];
		$fp = fopen( $job->path( 'database.sql' ), 'c+b' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new HIO_Exception( esc_html__( 'Could not write the database dump.', 'holeinone-migration' ) );
		}
		ftruncate( $fp, $db['sql_size'] );
		fseek( $fp, 0, SEEK_END );
		$plen = strlen( $wpdb->prefix );

		while ( $db['ti'] < count( $db['tables'] ) && ! $job->should_stop() ) {
			$t     = $db['tables'][ $db['ti'] ];
			$name  = $t['name'];
			$qname = HIO_Util::ident( $name );
			$tok   = HIO_Util::ident( self::PREFIX_TOKEN . substr( $name, $plen ) );

			if ( ! $db['created'] ) {
				$row    = HIO_DB::rows( 'SHOW CREATE TABLE ' . $qname );
				$create = isset( $row[0][1] ) ? $row[0][1] : '';
				if ( '' === $create ) {
					throw new HIO_Exception( esc_html( 'Could not read structure of table ' . $name ) );
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
				HIO_Util::fwrite_all( $fp, "\n-- Table " . $name . "\nDROP TABLE IF EXISTS " . $tok . ";\n" . $create . ";\n" );
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
			$names = implode( ',', array_map( array( 'HIO_Util', 'ident' ), wp_list_pluck( $cols, 'name' ) ) );
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
					$sql .= ' WHERE ' . HIO_DB::after( $t['pk'], (array) $db['last'] );
				}
				$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'HIO_Util', 'ident' ), $t['pk'] ) ) . ' LIMIT ' . $batch;
			} else {
				$sql .= ' LIMIT ' . (int) $db['offset'] . ',' . $batch;
			}

			// Rows are streamed (unbuffered): memory use stays constant.
			$head = 'INSERT INTO ' . $tok . ' (' . $names . ') VALUES ';
			$buf  = '';
			$last = null;
			$n    = HIO_DB::each(
				$sql,
				function ( $r ) use ( $fp, $head, $types, $pk_idx, &$buf, &$last ) {
					$vals = array();
					foreach ( $r as $i => $v ) {
						$vals[] = self::literal( $v, $types[ $i ] );
					}
					$tuple = '(' . implode( ',', $vals ) . ')';
					if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::MAX_STATEMENT ) {
						HIO_Util::fwrite_all( $fp, $head . $buf . ";\n" );
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
				HIO_Util::fwrite_all( $fp, $head . $buf . ";\n" );
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
			$job->state['message'] = sprintf( __( 'Exporting database: table %1$s (%2$d of %3$d)', 'holeinone-migration' ), $name, min( $db['ti'] + 1, count( $db['tables'] ) ), count( $db['tables'] ) );
		}

		fflush( $fp );
		$db['sql_size'] = ftell( $fp );
		fclose( $fp );

		$job->state['progress'] = 3 + (int) ( 27 * min( 1, $db['rows_done'] / $db['rows_total'] ) );

		if ( $db['ti'] >= count( $db['tables'] ) ) {
			file_put_contents( $job->path( 'database.sql' ), "-- End of dump\n", FILE_APPEND ); // phpcs:ignore
			$db['sql_size'] = filesize( $job->path( 'database.sql' ) );
			$job->log( sprintf( 'Database export complete: %s rows, %s.', number_format_i18n( $db['rows_done'] ), HIO_Util::size( $db['sql_size'] ) ) );
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
		return (string) HIO_DB::value( 'SELECT option_value FROM ' . HIO_Util::ident( $wpdb->options ) . ' WHERE option_name = ' . HIO_DB::quote( $name ) . ' LIMIT 1' );
	}

	/**
	 * Write the manifest and initialize the archive state.
	 *
	 * @param HIO_Job $job Job.
	 */
	private static function archive_init( HIO_Job $job ) {
		global $wpdb, $wp_version;
		$s        = &$job->state;
		$contents = $s['opts']['contents'];
		$manifest = array(
			'generator'      => 'holeinone-migration',
			'plugin_version' => HIO_VERSION,
			'format_version' => HIO_FORMAT_VERSION,
			'created'        => gmdate( 'c' ),
			'contents'       => $contents,
			'home'           => home_url(),
			'siteurl'        => site_url(),
			'db_home'        => self::raw_option( 'home' ),
			'db_siteurl'     => self::raw_option( 'siteurl' ),
			'content_url'    => content_url(),
			'abspath'        => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'content_dir'    => untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
			'uploads_url'    => wp_upload_dir( null, false )['baseurl'],
			'uploads_dir'    => untrailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) ),
			'prefix'         => $wpdb->prefix,
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'mysql_version'  => (string) HIO_DB::value( 'SELECT VERSION()' ),
			'multisite'      => is_multisite(),
			'plugin_dir'     => dirname( plugin_basename( HIO_FILE ) ),
			'files'          => isset( $s['scan']['files'] ) ? $s['scan']['files'] : 0,
			'bytes'          => isset( $s['scan']['bytes'] ) ? $s['scan']['bytes'] : 0,
			'db_rows'        => isset( $s['db']['rows_done'] ) ? $s['db']['rows_done'] : 0,
			'db_tables'      => isset( $s['db']['tables'] ) ? count( $s['db']['tables'] ) : 0,
			'active_theme'   => get_stylesheet(),
		);
		file_put_contents( $job->path( 'manifest.json' ), wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore

		$host  = sanitize_title( preg_replace( '#^https?://#', '', home_url() ) );
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
	 * @param HIO_Job $job Job.
	 * @throws HIO_Exception On error.
	 */
	private static function archive( HIO_Job $job ) {
		if ( ! isset( $job->state['arc'] ) ) {
			self::archive_init( $job );
		}
		$s     = &$job->state;
		$a     = &$s['arc'];
		$zip   = 'zip' === $s['opts']['format'];
		$path  = $job->path( 'archive.part' );
		$w     = $zip ? new HIO_Zip_Writer( $path, $job->path( 'central.bin' ), $a['writer'] ) : new HIO_Tar_Writer( $path, $a['writer'] );
		$root  = rtrim( WP_CONTENT_DIR, '/\\' );
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
						$w->add_dir( 'wp-content/' . $dir, (int) @filemtime( $root . '/' . $dir ) ); // phpcs:ignore
						$a['entries']++;
						continue;
					}
					$name = 'wp-content/' . $rel;
					$src  = $root . '/' . $rel;
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
					$data = @file_get_contents( $src ); // phpcs:ignore
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
		$s['message']           = sprintf( __( 'Creating archive: %1$s of %2$s (%3$s files)', 'holeinone-migration' ), HIO_Util::size( $a['bytes_done'] ), HIO_Util::size( $a['bytes_all'] ), number_format_i18n( $a['entries'] ) );

		if ( $done ) {
			self::finalize( $job );
		}
	}

	/**
	 * Move the archive to backups/ and clean up.
	 *
	 * @param HIO_Job $job Job.
	 * @throws HIO_Exception On error.
	 */
	private static function finalize( HIO_Job $job ) {
		$s    = &$job->state;
		$dest = HIO_Storage::dir( 'backups' ) . '/' . $s['arc']['file'];
		if ( ! @rename( $job->path( 'archive.part' ), $dest ) ) { // phpcs:ignore
			throw new HIO_Exception( esc_html__( 'Could not move the finished archive into the backups folder.', 'holeinone-migration' ) );
		}
		$size = filesize( $dest );
		$meta = array(
			'contents' => $s['opts']['contents'],
			'format'   => $s['opts']['format'],
			'site'     => home_url(),
			'created'  => time(),
			'files'    => $s['scan']['files'] ?? 0,
			'warnings' => $s['warnings'],
		);
		file_put_contents( $dest . '.json', wp_json_encode( $meta ) ); // phpcs:ignore
		foreach ( array( 'database.sql', 'filelist.txt', 'central.bin' ) as $f ) {
			@unlink( $job->path( $f ) ); // phpcs:ignore
		}
		$job->log( sprintf( 'Archive complete: %s (%s, %d entries).', $s['arc']['file'], HIO_Util::size( $size ), $s['arc']['entries'] ) );
		$s['phase']    = 'done';
		$s['status']   = 'done';
		$s['progress'] = 100;
		$s['message']  = __( 'Export complete!', 'holeinone-migration' );
		$s['result']   = array(
			'file' => $s['arc']['file'],
			'size' => $size,
		);
	}
}
