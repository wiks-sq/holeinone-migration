<?php
/**
 * Import: upload (chunked) -> validate -> extract -> import database into
 * temporary tables -> search & replace -> atomic table swap.
 *
 * The live site keeps running on its current database until the very last
 * step, which swaps all tables in one atomic RENAME TABLE statement. If
 * anything fails before that, the site is untouched and the import can be
 * resumed or cancelled.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-gigabyte archives and SQL dumps in small chunks across requests (fopen/fread/fwrite/fseek/ftruncate); WP_Filesystem has no streaming API and would load whole files into memory.

/**
 * Importer.
 */
class HIO_Importer implements HIO_Tar_Handler {

	const GUARD_FILE = '0000-hio-import-guard.php';

	/** Entries up to this size are extracted in one go. */
	const SMALL_ENTRY = 8388608;

	/**
	 * Current job (while extracting).
	 *
	 * @var HIO_Job
	 */
	private $job;

	/**
	 * Output file handle of the entry being extracted.
	 *
	 * @var resource|null
	 */
	private $out_fp = null;

	/* --------------------------------------------------------------------
	 * Starting a job.
	 * ------------------------------------------------------------------ */

	/**
	 * Common job setup.
	 *
	 * @return HIO_Job
	 */
	private static function new_job() {
		global $wpdb;
		if ( is_multisite() ) {
			throw new HIO_Exception( esc_html__( 'WordPress Multisite is not supported.', 'holeinone-migration' ) );
		}
		$job              = HIO_Job::create( 'imp' );
		$settings         = HIO_Util::settings();
		$up               = wp_upload_dir( null, false );
		$job->state['target'] = array(
			'home'         => home_url(),
			'siteurl'      => site_url(),
			'db_home'      => (string) HIO_DB::value( 'SELECT option_value FROM ' . HIO_Util::ident( $wpdb->options ) . " WHERE option_name = 'home'" ),
			'db_siteurl'   => (string) HIO_DB::value( 'SELECT option_value FROM ' . HIO_Util::ident( $wpdb->options ) . " WHERE option_name = 'siteurl'" ),
			'content_url'  => content_url(),
			'uploads_url'  => $up['baseurl'],
			'abspath'      => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'content_dir'  => untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
			'uploads_dir'  => untrailingslashit( wp_normalize_path( $up['basedir'] ) ),
			'prefix'       => $wpdb->prefix,
			'plugin'       => plugin_basename( HIO_FILE ),
			'delete_after' => ! empty( $settings['delete_after'] ),
		);
		return $job;
	}

	/**
	 * Start an import that will be uploaded in chunks from the browser.
	 *
	 * @param string $name File name.
	 * @param int    $size File size.
	 * @return HIO_Job
	 * @throws HIO_Exception On invalid input.
	 */
	public static function start_upload( $name, $size ) {
		if ( ! preg_match( '/\.(tar\.gz|tgz|zip)$/i', $name ) ) {
			throw new HIO_Exception( esc_html__( 'Please choose a .tar.gz or .zip archive created by Hole-in-One Migration.', 'holeinone-migration' ) );
		}
		if ( $size <= 0 ) {
			throw new HIO_Exception( esc_html__( 'The file is empty.', 'holeinone-migration' ) );
		}
		$free = HIO_Util::free_space( HIO_Storage::base() );
		if ( null !== $free && $free < $size * 1.1 ) {
			/* translators: 1: size the archive needs, 2: free disk space. */
			throw new HIO_Exception( esc_html( sprintf( __( 'Not enough disk space: the archive needs %1$s, but only %2$s is free on this server.', 'holeinone-migration' ), HIO_Util::size( $size ), HIO_Util::size( $free ) ) ) );
		}
		$job                     = self::new_job();
		$ext                     = preg_match( '/\.zip$/i', $name ) ? 'zip' : 'tar.gz';
		$job->state['phase']     = 'upload';
		$job->state['archive']   = $job->path( 'upload.' . $ext );
		$job->state['own_archive'] = true;
		$job->state['upload']    = array(
			'name'     => sanitize_file_name( $name ),
			'size'     => (int) $size,
			'received' => 0,
		);
		$job->state['message']   = __( 'Uploading…', 'holeinone-migration' );
		$job->log( sprintf( 'Import started (browser upload): %s, %s', sanitize_file_name( $name ), HIO_Util::size( $size ) ) );
		$job->save();
		return $job;
	}

	/**
	 * Start an import from a file in wp-content/hio-migration/import/.
	 *
	 * @param string $name File name.
	 * @return HIO_Job
	 * @throws HIO_Exception If the file does not exist.
	 */
	public static function start_server_file( $name ) {
		$name = basename( (string) $name );
		$path = HIO_Storage::dir( 'import' ) . '/' . $name;
		if ( ! preg_match( '/\.(tar\.gz|tgz|zip)$/i', $name ) || ! is_file( $path ) ) {
			throw new HIO_Exception( esc_html__( 'That file was not found in the import folder.', 'holeinone-migration' ) );
		}
		$job                       = self::new_job();
		$job->state['phase']       = 'validate';
		$job->state['archive']     = $path;
		$job->state['own_archive'] = false;
		$job->log( sprintf( 'Import started (server file): %s, %s', $name, HIO_Util::size( filesize( $path ) ) ) );
		$job->save();
		return $job;
	}

	/**
	 * Receive one upload chunk. Idempotent: a chunk sent twice is harmless.
	 *
	 * @param HIO_Job $job    Job.
	 * @param int     $offset Offset of this chunk.
	 * @param string  $tmp    Uploaded temp file.
	 * @return int Bytes on the server after this chunk.
	 * @throws HIO_Exception On error.
	 */
	public static function upload_chunk( HIO_Job $job, $offset, $tmp ) {
		if ( 'upload' !== $job->state['phase'] ) {
			throw new HIO_Exception( esc_html__( 'This import is not waiting for an upload.', 'holeinone-migration' ) );
		}
		$part = $job->state['archive'] . '.part';
		clearstatcache( true, $part );
		$have = is_file( $part ) ? filesize( $part ) : 0;
		if ( $offset > $have ) {
			return $have; // Client must resend from $have.
		}
		$fp = @fopen( $part, 'c+b' ); // phpcs:ignore
		$in = @fopen( $tmp, 'rb' ); // phpcs:ignore
		if ( ! $fp || ! $in ) {
			throw new HIO_Exception( esc_html__( 'Could not store the uploaded chunk.', 'holeinone-migration' ) );
		}
		ftruncate( $fp, $offset );
		fseek( $fp, $offset );
		while ( ! feof( $in ) ) {
			$buf = fread( $in, 1048576 );
			if ( false === $buf ) {
				break;
			}
			HIO_Util::fwrite_all( $fp, $buf );
		}
		fclose( $in );
		fflush( $fp );
		$have = ftell( $fp );
		fclose( $fp );

		$up             = &$job->state['upload'];
		$up['received'] = $have;
		$job->state['progress'] = (int) ( 10 * $have / max( 1, $up['size'] ) );
		/* translators: 1: size uploaded, 2: total size. */
		$job->state['message']  = sprintf( __( 'Uploading: %1$s of %2$s', 'holeinone-migration' ), HIO_Util::size( $have ), HIO_Util::size( $up['size'] ) );
		if ( $have >= $up['size'] ) {
			if ( $have > $up['size'] ) {
				$fp = fopen( $part, 'c+b' ); // phpcs:ignore
				ftruncate( $fp, $up['size'] );
				fclose( $fp );
			}
			if ( ! @rename( $part, $job->state['archive'] ) ) { // phpcs:ignore
				throw new HIO_Exception( esc_html__( 'Could not finish the upload.', 'holeinone-migration' ) );
			}
			$job->state['phase']   = 'validate';
			$job->state['message'] = __( 'Upload complete. Checking the archive…', 'holeinone-migration' );
			$job->log( 'Upload complete.' );
		}
		$job->save();
		return $have;
	}

	/* --------------------------------------------------------------------
	 * Step dispatcher.
	 * ------------------------------------------------------------------ */

	/**
	 * Do as much work as possible within the step budget.
	 *
	 * @param HIO_Job $job Job.
	 * @throws HIO_Exception On error.
	 */
	public static function step( HIO_Job $job ) {
		$imp      = new self();
		$imp->job = $job;
		while ( ! $job->should_stop() && 'done' !== $job->state['phase'] ) {
			switch ( $job->state['phase'] ) {
				case 'upload':
					return; // Driven by upload_chunk().
				case 'validate':
					$imp->validate();
					break;
				case 'extract':
					if ( 'zip' === $job->state['format'] ) {
						$imp->extract_zip();
					} else {
						$imp->extract_tar();
					}
					break;
				case 'db_import':
					$imp->db_import();
					break;
				case 'replace':
					$imp->replace();
					break;
				case 'swap':
					$imp->swap();
					return;
				case 'finish_files':
					$imp->finish_files_only();
					return;
				default:
					throw new HIO_Exception( esc_html( 'Unknown phase.' ) );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Validate.
	 * ------------------------------------------------------------------ */

	/**
	 * Detect format, read the manifest, check compatibility.
	 *
	 * @throws HIO_Exception On an invalid archive.
	 */
	private function validate() {
		$job  = $this->job;
		$s    = &$job->state;
		$path = $s['archive'];
		$fp   = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new HIO_Exception( esc_html__( 'Could not open the archive.', 'holeinone-migration' ) );
		}
		$magic = fread( $fp, 4 );
		fclose( $fp );

		if ( "\x1f\x8b" === substr( $magic, 0, 2 ) ) {
			$s['format'] = 'tar.gz';
			$manifest    = $this->read_tar_manifest( $path );
		} elseif ( "PK\x03\x04" === $magic ) {
			$s['format'] = 'zip';
			$info        = HIO_Zip_Reader::build_index( $path, $job->path( 'zipindex.jsonl' ) );
			$s['zip']    = $info;
			$manifest    = null;
			$ih          = fopen( $job->path( 'zipindex.jsonl' ), 'rb' ); // phpcs:ignore
			while ( false !== ( $line = fgets( $ih ) ) ) { // phpcs:ignore
				$e = json_decode( $line, true );
				if ( 'hio-manifest.json' === base64_decode( $e['n'] ) ) { // phpcs:ignore
					$manifest = json_decode( HIO_Zip_Reader::read_entry( $path, $e ), true );
					break;
				}
			}
			fclose( $ih );
		} else {
			throw new HIO_Exception( esc_html__( 'This is not a .tar.gz or .zip file.', 'holeinone-migration' ) );
		}

		if ( ! is_array( $manifest ) || ( $manifest['generator'] ?? '' ) !== 'holeinone-migration' ) {
			throw new HIO_Exception( esc_html__( 'This archive was not created by Hole-in-One Migration (no manifest found). Please export the old site with this plugin first.', 'holeinone-migration' ) );
		}
		if ( (int) $manifest['format_version'] > HIO_FORMAT_VERSION ) {
			throw new HIO_Exception( esc_html__( 'This archive was made with a newer version of Hole-in-One Migration. Please update the plugin on this site first.', 'holeinone-migration' ) );
		}
		if ( ! empty( $manifest['multisite'] ) ) {
			throw new HIO_Exception( esc_html__( 'Archives of WordPress Multisite networks are not supported.', 'holeinone-migration' ) );
		}

		$s['manifest'] = $manifest;
		$job->log( sprintf( 'Archive OK: %s from %s (WordPress %s, PHP %s, contents: %s, created %s).', $s['format'], $manifest['home'], $manifest['wp_version'], $manifest['php_version'], $manifest['contents'], $manifest['created'] ) );
		if ( version_compare( PHP_VERSION, $manifest['php_version'], '<' ) ) {
			$job->log( sprintf( 'This server runs PHP %s, the old one ran %s. Some plugins may need the newer PHP version.', PHP_VERSION, $manifest['php_version'] ), 'warning' );
		}
		$free = HIO_Util::free_space( WP_CONTENT_DIR );
		$need = (float) ( $manifest['bytes'] ?? 0 );
		if ( null !== $free && $need > 0 && $free < $need ) {
			$job->log( sprintf( 'Low disk space: %1$s free, the site files need up to %2$s.', HIO_Util::size( $free ), HIO_Util::size( $need ) ), 'warning' );
		}

		$s['ex']    = array(
			'coff'   => 0,
			'parser' => array(),
			'out'    => null,
			'bytes'  => 0,
			'files'  => 0,
			'idx'    => 0,
			'cur'    => null,
		);
		$s['phase'] = 'extract';
		$s['message'] = __( 'Extracting files…', 'holeinone-migration' );
		if ( 'db' !== $manifest['contents'] ) {
			self::install_guard( $s['target']['plugin'], $job );
		}
		$s['progress'] = 11;
	}

	/**
	 * Read the manifest (first entry) from a tar.gz.
	 *
	 * @param string $path Archive.
	 * @return array|null
	 */
	private function read_tar_manifest( $path ) {
		$gz = @gzopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $gz ) {
			return null;
		}
		$h = gzread( $gz, 512 );
		if ( strlen( $h ) < 512 || 'hio-manifest.json' !== rtrim( substr( $h, 0, 100 ), "\0" ) ) {
			gzclose( $gz );
			return null;
		}
		$size = (int) octdec( trim( substr( $h, 124, 12 ), " \0" ) );
		$data = $size > 0 && $size < 1048576 ? gzread( $gz, $size ) : '';
		gzclose( $gz );
		return json_decode( (string) $data, true );
	}

	/* --------------------------------------------------------------------
	 * Extract.
	 * ------------------------------------------------------------------ */

	/**
	 * Map an archive entry name to a destination path, or null to skip.
	 *
	 * @param string $name Entry name.
	 * @return string|null
	 */
	private function map_entry( $name ) {
		$name = str_replace( '\\', '/', $name );
		if ( false !== strpos( $name, "\0" ) ) {
			return null;
		}
		$name = preg_replace( '#^\./#', '', $name );
		if ( 'hio-database.sql' === $name ) {
			return $this->job->path( 'database.sql' );
		}
		if ( 0 !== strpos( $name, 'wp-content/' ) ) {
			return null;
		}
		$rel = trim( substr( $name, 11 ), '/' );
		if ( '' === $rel ) {
			return null;
		}
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return null;
			}
		}
		$t    = $this->job->state['target'];
		$m    = $this->job->state['manifest'];
		$skip = array(
			HIO_Storage::rel(),
			'plugins/' . dirname( $t['plugin'] ),
			'plugins/' . ( $m['plugin_dir'] ?? 'holeinone-migration' ),
			'mu-plugins/' . self::GUARD_FILE,
		);
		foreach ( $skip as $sk ) {
			if ( $rel === $sk || 0 === strpos( $rel, $sk . '/' ) ) {
				return null;
			}
		}
		if ( 'db' === $m['contents'] ) {
			return null;
		}
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/' . $rel;
	}

	/**
	 * Tar handler: an entry starts.
	 *
	 * @param string $name  Name.
	 * @param string $type  Type.
	 * @param int    $size  Size.
	 * @param int    $mtime Time.
	 * @return bool
	 */
	public function entry_start( $name, $type, $size, $mtime ) {
		$dest = $this->map_entry( $name );
		if ( null === $dest ) {
			return false;
		}
		if ( '5' === $type ) {
			if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
				$this->job->log( 'Could not create folder: ' . $name, 'warning' );
			}
			return false;
		}
		if ( '0' !== $type ) {
			return false; // Links and special files are never needed.
		}
		$this->open_out( $dest, 0, $mtime, $name );
		return true;
	}

	/**
	 * Open the output file of an entry (fresh or resumed).
	 *
	 * @param string $dest    Path.
	 * @param int    $written Bytes already written.
	 * @param int    $mtime   Time.
	 * @param string $name    Entry name (for messages).
	 */
	private function open_out( $dest, $written, $mtime, $name ) {
		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$fp = @fopen( $dest, 'c+b' ); // phpcs:ignore
		if ( ! $fp ) {
			$this->job->log( 'Could not write file (check permissions), skipped: ' . $name, 'warning' );
			$this->out_fp                = null;
			$this->job->state['ex']['out'] = array(
				'path'    => base64_encode( $dest ), // phpcs:ignore
				'written' => $written,
				'mtime'   => $mtime,
				'failed'  => true,
			);
			return;
		}
		ftruncate( $fp, $written );
		fseek( $fp, $written );
		$this->out_fp                  = $fp;
		$this->job->state['ex']['out'] = array(
			'path'    => base64_encode( $dest ), // phpcs:ignore
			'written' => $written,
			'mtime'   => $mtime,
			'failed'  => false,
		);
	}

	/**
	 * Tar handler: data.
	 *
	 * @param string $data Bytes.
	 */
	public function entry_data( $data ) {
		if ( $this->out_fp ) {
			HIO_Util::fwrite_all( $this->out_fp, $data );
		}
		$this->job->state['ex']['out']['written'] += strlen( $data );
		$this->job->state['ex']['bytes']          += strlen( $data );
	}

	/**
	 * Tar handler: entry done.
	 */
	public function entry_end() {
		$out = $this->job->state['ex']['out'];
		if ( $this->out_fp ) {
			fclose( $this->out_fp );
			$this->out_fp = null;
			if ( $out['mtime'] > 0 ) {
				@touch( base64_decode( $out['path'] ), $out['mtime'] ); // phpcs:ignore
			}
			$this->job->state['ex']['files']++;
		}
		$this->job->state['ex']['out'] = null;
	}

	/**
	 * Reopen the output file after a checkpoint.
	 */
	private function resume_out() {
		$out = $this->job->state['ex']['out'];
		if ( $out && empty( $out['failed'] ) ) {
			$this->open_out( base64_decode( $out['path'] ), $out['written'], $out['mtime'], basename( base64_decode( $out['path'] ) ) ); // phpcs:ignore
		}
	}

	/**
	 * Close the output file at a checkpoint.
	 */
	private function pause_out() {
		if ( $this->out_fp ) {
			fflush( $this->out_fp );
			fclose( $this->out_fp );
			$this->out_fp = null;
		}
	}

	/**
	 * Extract a .tar.gz, one gzip member at a time.
	 *
	 * @throws HIO_Exception On a damaged archive.
	 */
	private function extract_tar() {
		$job  = $this->job;
		$s    = &$job->state;
		$ex   = &$s['ex'];
		$path = $s['archive'];
		$size = filesize( $path );
		$fp   = fopen( $path, 'rb' ); // phpcs:ignore

		// Work on a copy of the checkpoint; commit it only at member boundaries.
		$parser = new HIO_Tar_Parser( $this, $ex['parser'] );
		$this->resume_out();
		$read = $job->scaled( 1048576, 65536 );

		try {
			while ( ! $job->should_stop() && $ex['coff'] < $size && ! $parser->ended() ) {
				fseek( $fp, $ex['coff'] );
				$ctx = inflate_init( ZLIB_ENCODING_GZIP );
				$end = false;
				while ( ! $end ) {
					$chunk = fread( $fp, $read );
					if ( false === $chunk || '' === $chunk ) {
						throw new HIO_Exception( esc_html__( 'The archive is incomplete (unexpected end of file). Please upload it again.', 'holeinone-migration' ) );
					}
					$out = @inflate_add( $ctx, $chunk, ZLIB_SYNC_FLUSH ); // phpcs:ignore
					if ( false === $out ) {
						throw new HIO_Exception( esc_html__( 'The archive is damaged (decompression failed). Please upload it again.', 'holeinone-migration' ) );
					}
					if ( '' !== $out ) {
						$parser->feed( $out );
					}
					if ( ZLIB_STREAM_END === inflate_get_status( $ctx ) ) {
						$ex['coff'] += inflate_get_read_len( $ctx );
						$end         = true;
					}
				}
				// Member boundary = consistent checkpoint.
				$ex['parser'] = $parser->export_state();
			}
		} finally {
			$this->pause_out();
			fclose( $fp );
		}

		$s['progress'] = 11 + (int) ( 49 * min( 1, $ex['coff'] / max( 1, $size ) ) );
		/* translators: 1: number of files, 2: total size. */
		$s['message']  = sprintf( __( 'Extracting: %1$s files, %2$s', 'holeinone-migration' ), number_format_i18n( $ex['files'] ), HIO_Util::size( $ex['bytes'] ) );

		if ( $parser->ended() || $ex['coff'] >= $size ) {
			if ( ! $parser->ended() ) {
				throw new HIO_Exception( esc_html__( 'The archive is incomplete (end marker missing). Please create and upload it again.', 'holeinone-migration' ) );
			}
			$this->extract_done();
		}
	}

	/**
	 * Extract a .zip entry by entry.
	 *
	 * @throws HIO_Exception On a damaged archive.
	 */
	private function extract_zip() {
		$job   = $this->job;
		$s     = &$job->state;
		$ex    = &$s['ex'];
		$fp    = fopen( $s['archive'], 'rb' ); // phpcs:ignore
		$ih    = fopen( $job->path( 'zipindex.jsonl' ), 'rb' ); // phpcs:ignore
		$chunk = $job->scaled( 2097152, 65536 );
		$done  = false;

		try {
			while ( ! $job->should_stop() ) {
				if ( $ex['cur'] ) {
					// Continue a large stored entry.
					$cur = &$ex['cur'];
					$this->resume_out();
					fseek( $fp, $cur['data'] + $cur['done'] );
					while ( $cur['done'] < $cur['u'] && ! $job->should_stop() ) {
						$buf = fread( $fp, (int) min( $chunk, $cur['u'] - $cur['done'] ) );
						if ( false === $buf || '' === $buf ) {
							throw new HIO_Exception( esc_html__( 'The archive is incomplete (unexpected end of file).', 'holeinone-migration' ) );
						}
						$this->entry_data( $buf );
						$cur['crc']  = HIO_Util::crc32_combine( $cur['crc'], crc32( $buf ), strlen( $buf ) );
						$cur['done'] += strlen( $buf );
					}
					if ( $cur['done'] >= $cur['u'] ) {
						if ( ( $cur['crc'] & 0xFFFFFFFF ) !== ( $cur['crc_want'] & 0xFFFFFFFF ) ) {
							/* translators: %s: file name inside the archive. */
							throw new HIO_Exception( esc_html( sprintf( __( 'Checksum error in %s. The archive is damaged; please upload it again.', 'holeinone-migration' ), $cur['name'] ) ) );
						}
						$this->entry_end();
						$ex['cur'] = null;
					} else {
						$this->pause_out();
					}
					unset( $cur );
					continue;
				}

				fseek( $ih, $ex['idx'] );
				$line = fgets( $ih );
				if ( false === $line ) {
					$done = true;
					break;
				}
				$ex['idx'] = ftell( $ih );
				$e         = json_decode( $line, true );
				$name      = base64_decode( $e['n'] ); // phpcs:ignore
				$dest      = $this->map_entry( $name );
				if ( null === $dest ) {
					continue;
				}
				if ( '/' === substr( $name, -1 ) ) {
					if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
						$job->log( 'Could not create folder: ' . $name, 'warning' );
					}
					continue;
				}
				$data_off = HIO_Zip_Reader::data_offset( $fp, $e['o'] );
				$this->open_out( $dest, 0, 0, $name );

				if ( 0 === (int) $e['m'] && $e['u'] > self::SMALL_ENTRY ) {
					$ex['cur'] = array(
						'name'     => $name,
						'data'     => $data_off,
						'u'        => $e['u'],
						'done'     => 0,
						'crc'      => 0,
						'crc_want' => $e['crc'],
					);
					$this->pause_out();
					continue;
				}

				// Small or deflated entry: in one go, streamed.
				fseek( $fp, $data_off );
				$left = $e['c'];
				$ctx  = 8 === (int) $e['m'] ? inflate_init( ZLIB_ENCODING_RAW ) : null;
				$hc   = hash_init( 'crc32b' );
				if ( ! in_array( (int) $e['m'], array( 0, 8 ), true ) ) {
					$job->log( 'Unsupported compression method, skipped: ' . $name, 'warning' );
					$this->pause_out();
					$s['ex']['out'] = null;
					continue;
				}
				while ( $left > 0 ) {
					$buf = fread( $fp, (int) min( 1048576, $left ) );
					if ( false === $buf || '' === $buf ) {
						throw new HIO_Exception( esc_html__( 'The archive is incomplete (unexpected end of file).', 'holeinone-migration' ) );
					}
					$left -= strlen( $buf );
					if ( $ctx ) {
						$buf = inflate_add( $ctx, $buf, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH );
						if ( false === $buf ) {
							/* translators: %s: file name inside the archive. */
							throw new HIO_Exception( esc_html( sprintf( __( 'Decompression failed for %s. The archive is damaged.', 'holeinone-migration' ), $name ) ) );
						}
					}
					hash_update( $hc, $buf );
					$this->entry_data( $buf );
				}
				if ( hexdec( hash_final( $hc ) ) !== $e['crc'] ) {
					/* translators: %s: file name inside the archive. */
					throw new HIO_Exception( esc_html( sprintf( __( 'Checksum error in %s. The archive is damaged; please upload it again.', 'holeinone-migration' ), $name ) ) );
				}
				$this->entry_end();
			}
		} finally {
			$this->pause_out();
			fclose( $fp );
			fclose( $ih );
		}

		$total         = max( 1, (float) ( $s['zip']['bytes'] ?? 1 ) );
		$s['progress'] = 11 + (int) ( 49 * min( 1, $ex['bytes'] / $total ) );
		/* translators: 1: number of files, 2: total size. */
		$s['message']  = sprintf( __( 'Extracting: %1$s files, %2$s', 'holeinone-migration' ), number_format_i18n( $ex['files'] ), HIO_Util::size( $ex['bytes'] ) );
		if ( $done ) {
			$this->extract_done();
		}
	}

	/**
	 * Decide what comes after extraction.
	 */
	private function extract_done() {
		$s = &$this->job->state;
		$this->job->log( sprintf( 'Extraction complete: %d files written.', $s['ex']['files'] ) );
		if ( 'files' === $s['manifest']['contents'] ) {
			$s['phase'] = 'finish_files';
			return;
		}
		if ( ! is_file( $this->job->path( 'database.sql' ) ) ) {
			throw new HIO_Exception( esc_html__( 'The archive does not contain a database.', 'holeinone-migration' ) );
		}
		$s['phase']   = 'db_import';
		$s['message'] = __( 'Importing database…', 'holeinone-migration' );
		$s['progress'] = 60;
	}

	/* --------------------------------------------------------------------
	 * Database import into temporary tables.
	 * ------------------------------------------------------------------ */

	/**
	 * Random lower-case prefix that does not clash with anything.
	 *
	 * @param string $start Start letters.
	 * @return string
	 */
	private function free_prefix( $start ) {
		$target = $this->job->state['target']['prefix'];
		for ( $i = 0; $i < 50; $i++ ) {
			$p = $start . substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz0123456789' ), 0, 3 ) . '_';
			if ( 0 === strpos( $p, $target ) || 0 === strpos( $target, $p ) ) {
				continue;
			}
			if ( ! HIO_DB::tables_with_prefix( $p ) ) {
				return $p;
			}
		}
		throw new HIO_Exception( esc_html( 'Could not find a free temporary table prefix.' ) );
	}

	/**
	 * Set safe session variables for the import.
	 */
	private function db_session() {
		HIO_DB::exec( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		HIO_DB::exec( 'SET SESSION foreign_key_checks = 0' );
		try {
			HIO_DB::exec( 'SET NAMES utf8mb4' );
		} catch ( HIO_Exception $e ) {
			// Very old MySQL without utf8mb4: keep the WordPress default.
			unset( $e );
		}
	}

	/**
	 * Supported collations and engines on this server.
	 *
	 * @return array
	 */
	private function server_caps() {
		$coll = array();
		foreach ( HIO_DB::rows( 'SHOW COLLATION' ) as $r ) {
			$coll[ strtolower( $r[0] ) ] = true;
		}
		$eng = array();
		foreach ( HIO_DB::rows( 'SHOW ENGINES', true ) as $r ) {
			if ( in_array( strtoupper( $r['Support'] ), array( 'YES', 'DEFAULT' ), true ) ) {
				$eng[ strtolower( $r['Engine'] ) ] = true;
			}
		}
		return array( $coll, $eng );
	}

	/**
	 * Make a CREATE TABLE statement compatible with this server.
	 *
	 * @param string $sql  Statement.
	 * @param array  $coll Supported collations.
	 * @param array  $eng  Supported engines.
	 * @return string
	 */
	private function fix_create( $sql, $coll, $eng ) {
		$job = $this->job;
		if ( ! preg_grep( '/^utf8mb3_/', array_keys( $coll ) ) ) {
			$sql = str_ireplace( 'utf8mb3', 'utf8', $sql );
		}
		$sql = preg_replace_callback(
			'/COLLATE(\s*=\s*|\s+)([A-Za-z0-9_]+)/',
			function ( $m ) use ( $coll, $job ) {
				$c = strtolower( $m[2] );
				if ( isset( $coll[ $c ] ) ) {
					return $m[0];
				}
				if ( 0 === strpos( $c, 'utf8mb4' ) ) {
					foreach ( array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ) as $f ) {
						if ( isset( $coll[ $f ] ) ) {
							return 'COLLATE' . $m[1] . $f;
						}
					}
				}
				if ( 0 === strpos( $c, 'utf8' ) && isset( $coll['utf8_general_ci'] ) ) {
					return 'COLLATE' . $m[1] . 'utf8_general_ci';
				}
				return '';
			},
			$sql
		);
		if ( preg_match( '/ENGINE\s*=\s*(\w+)/i', $sql, $m ) && ! isset( $eng[ strtolower( $m[1] ) ] ) ) {
			$sql = preg_replace( '/ENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $sql );
			$sql = preg_replace( '/\s(PAGE_CHECKSUM|TRANSACTIONAL)\s*=\s*\d+/i', '', $sql );
		}
		// Let MySQL name foreign keys itself, so they never clash with the live tables.
		$sql = preg_replace( '/CONSTRAINT\s+`(?:[^`]|``)+`\s+FOREIGN KEY/i', 'FOREIGN KEY', $sql );
		return $sql;
	}

	/**
	 * Execute the dump statement by statement.
	 *
	 * @throws HIO_Exception On a failing statement.
	 */
	private function db_import() {
		$job  = $this->job;
		$s    = &$job->state;
		$file = $job->path( 'database.sql' );
		if ( ! isset( $s['dbi'] ) ) {
			$s['dbi'] = array(
				'off'   => 0,
				'size'  => filesize( $file ),
				'tmp'   => $this->free_prefix( 'hi' ),
				'stmts' => 0,
			);
			$job->log( sprintf( 'Importing database into temporary tables (%s*). The live site keeps working meanwhile.', $s['dbi']['tmp'] ) );
			$job->save();
		}
		$d   = &$s['dbi'];
		$tmp = $d['tmp'];
		$tok = '`' . HIO_Exporter::PREFIX_TOKEN;
		$this->db_session();
		list( $coll, $eng ) = $this->server_caps();

		$fp = fopen( $file, 'rb' ); // phpcs:ignore
		fseek( $fp, $d['off'] );
		$last_save = microtime( true );
		$finished  = false;

		while ( ! $job->should_stop() ) {
			$line = fgets( $fp );
			if ( false === $line ) {
				$finished = true;
				break;
			}
			$next = ftell( $fp );
			$sql  = rtrim( $line, "\r\n" );
			if ( '' === $sql || 0 === strpos( $sql, '--' ) ) {
				$d['off'] = $next;
				continue;
			}
			if ( ';' !== substr( $sql, -1 ) ) {
				throw new HIO_Exception( esc_html__( 'The database dump in the archive is incomplete. Please export the old site again.', 'holeinone-migration' ) );
			}
			if ( 0 === strpos( $sql, 'INSERT INTO ' . $tok ) ) {
				// REPLACE makes re-running a statement after a crash harmless.
				$sql = 'REPLACE INTO `' . $tmp . substr( $sql, strlen( 'INSERT INTO ' . $tok ) );
			} elseif ( 0 === strpos( $sql, 'DROP TABLE IF EXISTS ' . $tok ) ) {
				$sql = 'DROP TABLE IF EXISTS `' . $tmp . substr( $sql, strlen( 'DROP TABLE IF EXISTS ' . $tok ) );
			} elseif ( 0 === strpos( $sql, 'CREATE TABLE ' ) ) {
				$sql = $this->fix_create( str_replace( $tok, '`' . $tmp, $sql ), $coll, $eng );
				if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) && strlen( $m[1] ) > 64 ) {
					throw new HIO_Exception( esc_html( sprintf( 'Table name too long: %s', $m[1] ) ) );
				}
			} else {
				$job->log( 'Unknown statement in dump skipped: ' . substr( $sql, 0, 80 ), 'warning' );
				$d['off'] = $next;
				continue;
			}
			try {
				HIO_DB::exec( $sql );
			} catch ( HIO_Exception $e ) {
				$msg = HIO_Util::plain( $e );
				if ( false !== stripos( $msg, 'max_allowed_packet' ) || false !== stripos( $msg, 'gone away' ) ) {
					$msg .= ' ' . __( '(The database server rejected a large statement. Ask your host to raise max_allowed_packet to 16M or more.)', 'holeinone-migration' );
				}
				fclose( $fp );
				/* translators: 1: database error message, 2: start of the failed SQL statement. */
				throw new HIO_Exception( esc_html( sprintf( __( 'Database import failed: %1$s — statement: %2$s', 'holeinone-migration' ), $msg, substr( $sql, 0, 300 ) ) ) );
			}
			$d['off'] = $next;
			$d['stmts']++;
			if ( microtime( true ) - $last_save > 1.5 ) {
				$job->save();
				$last_save = microtime( true );
			}
		}
		fclose( $fp );

		$s['progress'] = 60 + (int) ( 25 * min( 1, $d['off'] / max( 1, $d['size'] ) ) );
		/* translators: 1: size done, 2: total size. */
		$s['message']  = sprintf( __( 'Importing database: %1$s of %2$s', 'holeinone-migration' ), HIO_Util::size( $d['off'] ), HIO_Util::size( $d['size'] ) );
		if ( $finished ) {
			$job->log( sprintf( 'Database import complete: %d statements.', $d['stmts'] ) );
			$s['phase'] = 'replace';
		}
	}

	/* --------------------------------------------------------------------
	 * Search & replace in the temporary tables.
	 * ------------------------------------------------------------------ */

	/**
	 * Build the replacement map from old site to this site.
	 *
	 * @return HIO_Search_Replace
	 */
	private function engine() {
		$m    = $this->job->state['manifest'];
		$t    = $this->job->state['target'];
		$urls = array();
		foreach ( array(
			'home'        => 'home',
			'siteurl'     => 'siteurl',
			'db_home'     => 'home',
			'db_siteurl'  => 'siteurl',
			'content_url' => 'content_url',
			'uploads_url' => 'uploads_url',
		) as $old_key => $new_key ) {
			if ( ! empty( $m[ $old_key ] ) && ! isset( $urls[ untrailingslashit( $m[ $old_key ] ) ] ) ) {
				$urls[ untrailingslashit( $m[ $old_key ] ) ] = $t[ $new_key ];
			}
		}
		// www / non-www variants of the old address.
		foreach ( $urls as $old => $new ) {
			if ( preg_match( '#^(https?://)(www\.)?(.+)$#i', $old, $mm ) ) {
				$alt = $mm[1] . ( $mm[2] ? '' : 'www.' ) . $mm[3];
				if ( ! isset( $urls[ $alt ] ) ) {
					$urls[ $alt ] = $new;
				}
			}
		}
		$paths = array();
		foreach ( array( 'uploads_dir', 'content_dir', 'abspath' ) as $k ) {
			if ( ! empty( $m[ $k ] ) && ! isset( $paths[ $m[ $k ] ] ) ) {
				$paths[ $m[ $k ] ] = $t[ $k ];
			}
		}
		return new HIO_Search_Replace( HIO_Search_Replace::build_map( $urls, $paths ) );
	}

	/**
	 * Run search & replace until time runs out.
	 */
	private function replace() {
		$job = $this->job;
		$s   = &$job->state;
		$tmp = $s['dbi']['tmp'];
		$sr  = $this->engine();

		if ( ! isset( $s['rep'] ) ) {
			$tables = array();
			$status = array();
			foreach ( HIO_DB::rows( 'SHOW TABLE STATUS', true ) as $st ) {
				$status[ $st['Name'] ] = $st;
			}
			foreach ( HIO_DB::tables_with_prefix( $tmp ) as $t ) {
				$cols  = array();
				$types = array();
				foreach ( HIO_DB::columns( $t ) as $c ) {
					$types[ $c['name'] ] = $c['type'];
					if ( ! $c['generated'] && preg_match( '/char|text|blob/', $c['type'] ) ) {
						$cols[] = $c['name'];
					}
				}
				$pk       = HIO_DB::primary_key( $t );
				// Key range of numeric keys, only used to show real progress.
				$range = null;
				if ( $pk && $cols && preg_match( '/int/', $types[ $pk[0] ] ) ) {
					$mm    = HIO_DB::rows( 'SELECT MIN(' . HIO_Util::ident( $pk[0] ) . '), MAX(' . HIO_Util::ident( $pk[0] ) . ') FROM ' . HIO_Util::ident( $t ) );
					$range = isset( $mm[0][1] ) ? array( (float) $mm[0][0], (float) $mm[0][1] ) : null;
				}
				$avg      = isset( $status[ $t ]['Avg_row_length'] ) ? (int) $status[ $t ]['Avg_row_length'] : 0;
				$tables[] = array(
					'name'  => $t,
					'cols'  => $cols,
					'pk'    => $pk,
					// Rows are held in memory while updating: keep a batch near 4 MB.
					'batch' => $avg > 0 ? (int) max( 10, min( 500, floor( 4194304 / $avg ) ) ) : 200,
					'rows'  => isset( $status[ $t ]['Rows'] ) ? (int) $status[ $t ]['Rows'] : 0,
					'range' => $range,
				);
			}
			$s['rep'] = array(
				'tables' => $tables,
				'ti'     => 0,
				'last'    => null,
				'scanned' => 0,
				'rows'    => 0,
			);
			if ( $sr->is_empty() ) {
				$job->log( 'Same address and folder as the old site: no search & replace needed.' );
				$s['rep']['ti'] = count( $tables );
			} else {
				$job->log( sprintf( 'Search & replace: %s → %s (%d variants, serialized data safe).', $s['manifest']['home'], $s['target']['home'], count( $sr->map() ) ) );
			}
			$job->save();
		}
		$r = &$s['rep'];
		$this->db_session();
		$needles = $sr->needles();

		while ( $r['ti'] < count( $r['tables'] ) && ! $job->should_stop() ) {
			$t = $r['tables'][ $r['ti'] ];
			if ( empty( $t['cols'] ) ) {
				$this->next_table( $r );
				continue;
			}
			$q = HIO_Util::ident( $t['name'] );

			if ( empty( $t['pk'] ) ) {
				// No primary key: plain SQL REPLACE (rare, e.g. some log tables).
				foreach ( $t['cols'] as $c ) {
					$expr = HIO_Util::ident( $c );
					foreach ( $sr->map() as $from => $to ) {
						$expr = 'REPLACE(' . $expr . ',' . HIO_DB::quote( $from ) . ',' . HIO_DB::quote( $to ) . ')';
					}
					HIO_DB::exec( 'UPDATE ' . $q . ' SET ' . HIO_Util::ident( $c ) . ' = ' . $expr );
				}
				$this->next_table( $r );
				continue;
			}

			$pk_cols = array_map( array( 'HIO_Util', 'ident' ), $t['pk'] );
			$order   = implode( ',', $pk_cols );
			$batch   = $job->scaled( $t['batch'], 5 );
			$window  = $job->scaled( 5000, 200 );
			$after   = null !== $r['last'] ? HIO_DB::after( $t['pk'], (array) $r['last'] ) : '';

			// 1) End of the next window of rows in key order (index-only, cheap).
			//    Bounding every query to a window keeps it fast even when few
			//    rows match, so no single query can run into a timeout.
			$upper = HIO_DB::rows( 'SELECT ' . $order . ' FROM ' . $q . ( $after ? ' WHERE ' . $after : '' ) . ' ORDER BY ' . $order . ' LIMIT ' . ( $window - 1 ) . ',1' );
			$upper = $upper ? $upper[0] : null;

			// 2) Rows in that window that contain an old address or path.
			$where = array();
			if ( $after ) {
				$where[] = $after;
			}
			if ( null !== $upper ) {
				$where[] = 'NOT ' . HIO_DB::after( $t['pk'], $upper );
			}
			$likes = array();
			foreach ( $t['cols'] as $c ) {
				foreach ( $needles as $nd ) {
					$likes[] = HIO_Util::ident( $c ) . " LIKE '%" . HIO_Util::like_escape( $nd ) . "%'";
				}
			}
			if ( $likes ) {
				$where[] = '(' . implode( ' OR ', $likes ) . ')';
			}
			$sel  = array_unique( array_merge( $t['pk'], $t['cols'] ) );
			$sql  = 'SELECT ' . implode( ',', array_map( array( 'HIO_Util', 'ident' ), $sel ) ) . ' FROM ' . $q;
			$sql .= $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
			$sql .= ' ORDER BY ' . $order . ' LIMIT ' . $batch;

			$rows = HIO_DB::rows( $sql, true );
			// One transaction per batch: one disk sync instead of one per row
			// (often 10-50x faster on InnoDB). A crash rolls the batch back.
			HIO_DB::exec( 'START TRANSACTION' );
			foreach ( $rows as $row ) {
				$set = array();
				foreach ( $t['cols'] as $c ) {
					if ( null === $row[ $c ] ) {
						continue;
					}
					$new = $sr->run( $row[ $c ] );
					if ( $new !== $row[ $c ] ) {
						$set[] = HIO_Util::ident( $c ) . ' = ' . HIO_DB::quote( $new );
					}
				}
				if ( $set ) {
					$cond = array();
					foreach ( $t['pk'] as $k ) {
						$cond[] = HIO_Util::ident( $k ) . ' = ' . HIO_DB::quote( $row[ $k ] );
					}
					HIO_DB::exec( 'UPDATE ' . $q . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ) );
					$r['rows']++;
				}
			}
			HIO_DB::exec( 'COMMIT' );
			$n = count( $rows );
			if ( $n >= $batch ) {
				// More matches inside this window: continue after the last one.
				$last = array();
				foreach ( $t['pk'] as $k ) {
					$last[] = $rows[ $n - 1 ][ $k ];
				}
				$r['last'] = $last;
			} elseif ( null !== $upper ) {
				$r['last']     = array_values( $upper );
				$r['scanned'] += $window;
			} else {
				$this->next_table( $r );
			}
			unset( $rows );
			// Checkpoint right after the commit, so a crash never repeats a
			// committed batch.
			$job->save();
			if ( $r['ti'] < count( $r['tables'] ) ) {
				$cur          = $r['tables'][ $r['ti'] ];
				$pct = 0;
				if ( ! empty( $cur['range'] ) && null !== $r['last'] && $cur['range'][1] > $cur['range'][0] ) {
					$pct = (int) ( 100 * ( (float) $r['last'][0] - $cur['range'][0] ) / ( $cur['range'][1] - $cur['range'][0] ) );
				} elseif ( $cur['rows'] > 0 ) {
					$pct = (int) ( 100 * $r['scanned'] / $cur['rows'] );
				}
				$pct          = max( 0, min( 99, $pct ) );
				/* translators: 1: table number, 2: number of tables, 3: table name, 4: percent done. */
				$s['message'] = sprintf( __( 'Updating addresses: table %1$d of %2$d (%3$s, about %4$d%%)', 'holeinone-migration' ), $r['ti'] + 1, count( $r['tables'] ), $cur['name'], $pct );
			}
		}

		$s['progress'] = 85 + (int) ( 10 * $r['ti'] / max( 1, count( $r['tables'] ) ) );
		if ( $r['ti'] >= count( $r['tables'] ) ) {
			$job->log( sprintf( 'Search & replace complete: %d rows updated.', $r['rows'] ) );
			$this->fixups();
			$s['phase']   = 'swap';
			$s['message'] = __( 'Switching to the new database…', 'holeinone-migration' );
		}
	}

	/**
	 * Advance to the next table.
	 *
	 * @param array $r Replace state (by reference).
	 */
	private function next_table( array &$r ) {
		$r['ti']++;
		$r['last']    = null;
		$r['scanned'] = 0;
	}

	/**
	 * Final adjustments in the temporary tables before they go live.
	 */
	private function fixups() {
		$s    = $this->job->state;
		$tmp  = $s['dbi']['tmp'];
		$src  = $s['manifest']['prefix'];
		$dst  = $s['target']['prefix'];
		$opt  = HIO_Util::ident( $tmp . 'options' );
		$um   = HIO_Util::ident( $tmp . 'usermeta' );

		if ( ! in_array( $tmp . 'options', HIO_DB::tables_with_prefix( $tmp ), true ) ) {
			throw new HIO_Exception( esc_html__( 'The imported database has no options table. The archive does not look like a WordPress database.', 'holeinone-migration' ) );
		}

		// Table prefix changed: rename prefix-dependent keys.
		if ( $src !== $dst ) {
			if ( in_array( $tmp . 'usermeta', HIO_DB::tables_with_prefix( $tmp ), true ) ) {
				HIO_DB::exec( 'UPDATE ' . $um . ' SET meta_key = CONCAT(' . HIO_DB::quote( $dst ) . ', SUBSTRING(meta_key, ' . ( strlen( $src ) + 1 ) . ")) WHERE meta_key LIKE '" . HIO_Util::like_escape( $src ) . "%'" );
			}
			HIO_DB::exec( 'DELETE FROM ' . $opt . ' WHERE option_name = ' . HIO_DB::quote( $dst . 'user_roles' ) );
			HIO_DB::exec( 'UPDATE ' . $opt . ' SET option_name = ' . HIO_DB::quote( $dst . 'user_roles' ) . ' WHERE option_name = ' . HIO_DB::quote( $src . 'user_roles' ) );
			$this->job->log( sprintf( 'Table prefix changed from %s to %s; user roles and capabilities updated.', $src, $dst ) );
		}

		// This site's address and the plugin must stay intact.
		HIO_DB::exec( 'UPDATE ' . $opt . ' SET option_value = ' . HIO_DB::quote( $s['target']['db_home'] ) . " WHERE option_name = 'home'" );
		HIO_DB::exec( 'UPDATE ' . $opt . ' SET option_value = ' . HIO_DB::quote( $s['target']['db_siteurl'] ) . " WHERE option_name = 'siteurl'" );

		$active  = HIO_DB::value( 'SELECT option_value FROM ' . $opt . " WHERE option_name = 'active_plugins'" );
		$plugins = is_string( $active ) ? @unserialize( $active, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
		$plugins = is_array( $plugins ) ? $plugins : array();
		$mine    = $s['target']['plugin'];
		$plugins = array_values(
			array_filter(
				$plugins,
				function ( $p ) use ( $mine ) {
					return is_string( $p ) && ( $p === $mine || false === strpos( $p, 'holeinone-migration.php' ) );
				}
			)
		);
		if ( ! in_array( $mine, $plugins, true ) ) {
			$plugins[] = $mine;
		}
		HIO_DB::exec( 'DELETE FROM ' . $opt . " WHERE option_name = 'active_plugins'" );
		HIO_DB::exec( 'INSERT INTO ' . $opt . " (option_name, option_value, autoload) VALUES ('active_plugins', " . HIO_DB::quote( serialize( $plugins ) ) . ", 'yes')" ); // phpcs:ignore

		// Caches that contain old paths/addresses are rebuilt automatically.
		HIO_DB::exec( 'DELETE FROM ' . $opt . " WHERE option_name = 'rewrite_rules' OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );

		$notice = array(
			'time'     => time(),
			'from'     => $s['manifest']['home'],
			'warnings' => (int) $s['warnings'],
			'flush'    => 1,
			'job'      => $this->job->id, // Marker: proves this job's swap happened.
		);
		HIO_DB::exec( 'DELETE FROM ' . $opt . " WHERE option_name = 'hio_post_import'" );
		HIO_DB::exec( 'INSERT INTO ' . $opt . " (option_name, option_value, autoload) VALUES ('hio_post_import', " . HIO_DB::quote( serialize( $notice ) ) . ", 'yes')" ); // phpcs:ignore
	}

	/* --------------------------------------------------------------------
	 * Swap: one atomic RENAME TABLE.
	 * ------------------------------------------------------------------ */

	/**
	 * Put the imported tables live.
	 *
	 * @throws HIO_Exception If the swap fails (the site is then unchanged).
	 */
	private function swap() {
		$job = $this->job;
		$s   = &$job->state;
		if ( self::is_swapped( $job ) ) {
			// A previous request swapped and then died before saving: just finish.
			$this->after_swap();
			return;
		}
		$tmp = $s['dbi']['tmp'];
		$dst = $s['target']['prefix'];
		$new = HIO_DB::tables_with_prefix( $tmp );
		if ( empty( $s['bak'] ) ) {
			$s['bak'] = $this->free_prefix( 'hb' );
		}
		$bak    = $s['bak'];
		$finals = array();
		foreach ( $new as $t ) {
			$finals[ $dst . substr( $t, strlen( $tmp ) ) ] = true;
		}
		// Only tables that get replaced are touched. Other tables (for example
		// of a second WordPress install in the same database) are left alone.
		$old = array_filter(
			HIO_DB::tables_with_prefix( $dst ),
			function ( $t ) use ( $finals ) {
				return isset( $finals[ $t ] );
			}
		);
		$this->db_session();

		$check = HIO_DB::value( 'SELECT COUNT(*) FROM ' . HIO_Util::ident( $tmp . 'options' ) . " WHERE option_name = 'siteurl'" );
		if ( 1 > (int) $check ) {
			throw new HIO_Exception( esc_html__( 'Safety check failed: the imported options table has no site address. Nothing was changed.', 'holeinone-migration' ) );
		}

		$pairs = array();
		foreach ( $old as $t ) {
			$pairs[] = HIO_Util::ident( $t ) . ' TO ' . HIO_Util::ident( $bak . substr( $t, strlen( $dst ) ) );
		}
		foreach ( $new as $t ) {
			$final = $dst . substr( $t, strlen( $tmp ) );
			if ( strlen( $final ) > 64 ) {
				throw new HIO_Exception( esc_html( sprintf( 'Table name too long: %s', $final ) ) );
			}
			$pairs[] = HIO_Util::ident( $t ) . ' TO ' . HIO_Util::ident( $final );
		}
		$job->log( sprintf( 'Swapping %d imported tables in (one atomic operation)…', count( $new ) ) );
		$job->save(); // Checkpoint right before the point of no return.
		HIO_DB::exec( 'RENAME TABLE ' . implode( ', ', $pairs ) );
		$s['swapped'] = true;
		$job->save(); // ...and right after it.
		$this->after_swap();
	}

	/**
	 * Was this job's swap already done? True when the state says so, or when
	 * the temporary tables are gone and the live options table carries this
	 * job's marker (the request died between the swap and its checkpoint).
	 *
	 * @param HIO_Job $job Job.
	 * @return bool
	 */
	private static function is_swapped( HIO_Job $job ) {
		$s = $job->state;
		if ( ! empty( $s['swapped'] ) ) {
			return true;
		}
		if ( empty( $s['dbi']['tmp'] ) || HIO_DB::tables_with_prefix( $s['dbi']['tmp'] ) ) {
			return false;
		}
		$v = HIO_DB::value( 'SELECT option_value FROM ' . HIO_Util::ident( $s['target']['prefix'] . 'options' ) . " WHERE option_name = 'hio_post_import'" );
		$v = is_string( $v ) ? @unserialize( $v, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore
		return is_array( $v ) && isset( $v['job'] ) && $v['job'] === $job->id;
	}

	/**
	 * Finish an import whose swap is done. Used by the status endpoint, which
	 * works without a login: after the swap the old login session is gone, so
	 * the normal step request can no longer finish the job.
	 *
	 * @param HIO_Job $job Job (locked by the caller).
	 * @return bool Whether the job was finished.
	 */
	public static function finish_if_swapped( HIO_Job $job ) {
		if ( 'imp' !== $job->state['kind'] || 'swap' !== $job->state['phase'] || ! self::is_swapped( $job ) ) {
			return false;
		}
		$imp      = new self();
		$imp->job = $job;
		$imp->after_swap();
		$job->save();
		return true;
	}

	/**
	 * After the swap: flush caches, remove the old tables, clean up.
	 */
	private function after_swap() {
		$job = $this->job;
		$s   = &$job->state;
		// From here on, the site runs on the imported database.
		$s['swapped'] = true;
		wp_cache_flush();
		$this->db_session();
		$backups = empty( $s['bak'] ) ? array() : HIO_DB::tables_with_prefix( $s['bak'] );
		foreach ( array_chunk( $backups, 50 ) as $chunk ) {
			try {
				HIO_DB::exec( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( array( 'HIO_Util', 'ident' ), $chunk ) ) );
			} catch ( HIO_Exception $e ) {
				$job->log( 'Could not remove old tables: ' . HIO_Util::plain( $e ), 'warning' );
			}
		}
		$job->log( 'Database switched. Old tables removed.' );
		$this->complete();
	}

	/**
	 * Files-only import: nothing to swap.
	 */
	private function finish_files_only() {
		$this->complete();
	}

	/**
	 * Clean up and mark the job as done.
	 */
	private function complete() {
		$job = $this->job;
		$s   = &$job->state;
		self::remove_guard();
		@unlink( $job->path( 'database.sql' ) ); // phpcs:ignore
		@unlink( $job->path( 'zipindex.jsonl' ) ); // phpcs:ignore
		if ( ! empty( $s['own_archive'] ) ) {
			@unlink( $s['archive'] ); // phpcs:ignore
		} elseif ( ! empty( $s['target']['delete_after'] ) ) {
			@unlink( $s['archive'] ); // phpcs:ignore
		}
		$db             = 'files' !== $s['manifest']['contents'];
		$s['phase']     = 'done';
		$s['status']    = 'done';
		$s['progress']  = 100;
		$s['message']   = __( 'Import complete!', 'holeinone-migration' );
		$s['result']    = array(
			'relogin' => $db,
			'login'   => wp_login_url( admin_url( 'admin.php?page=holeinone-migration' ) ),
			'home'    => home_url( '/' ),
		);
		$job->log( 'Import complete.' . ( $db ? ' Log in with the username and password of the OLD site.' : '' ) );
	}

	/* --------------------------------------------------------------------
	 * Cancel and guard.
	 * ------------------------------------------------------------------ */

	/**
	 * Cancel an import: drop temporary tables, remove the guard, delete files.
	 *
	 * @param HIO_Job $job Job.
	 */
	public static function cancel( HIO_Job $job ) {
		if ( ! empty( $job->state['dbi']['tmp'] ) && 'done' !== $job->state['phase'] ) {
			$tmp = $job->state['dbi']['tmp'];
			try {
				HIO_DB::exec( 'SET SESSION foreign_key_checks = 0' );
				foreach ( array_chunk( HIO_DB::tables_with_prefix( $tmp ), 50 ) as $chunk ) {
					HIO_DB::exec( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( array( 'HIO_Util', 'ident' ), $chunk ) ) );
				}
			} catch ( HIO_Exception $e ) {
				unset( $e );
			}
		}
		if ( ! self::other_imports_running( $job->id ) ) {
			self::remove_guard();
		}
		$job->destroy();
	}

	/**
	 * Is another import job unfinished?
	 *
	 * @param string $except Job id to ignore.
	 * @return bool
	 */
	private static function other_imports_running( $except ) {
		foreach ( HIO_Job::unfinished() as $j ) {
			if ( 'imp' === $j->state['kind'] && $j->id !== $except ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Install a tiny must-use plugin that, ONLY for this plugin's own AJAX
	 * requests, stops other plugins and the theme from loading. Files of
	 * plugins and themes are overwritten during extraction; without the guard
	 * a half-written plugin could crash the next import request.
	 *
	 * @param string  $basename Plugin basename.
	 * @param HIO_Job $job      Job (for logging).
	 */
	private static function install_guard( $basename, HIO_Job $job ) {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$job->log( 'Could not create the mu-plugins folder; continuing without the import guard.', 'warning' );
			return;
		}
		$code = "<?php\n"
			. "/**\n * Hole-in-One Migration import guard. Created automatically during an import and\n * removed automatically when it finishes. Safe to delete if no import is running.\n */\n"
			. "if ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( \$_REQUEST['action'] ) && in_array( \$_REQUEST['action'], array( 'hio_step', 'hio_upload_chunk', 'hio_cancel' ), true ) ) {\n"
			. "\t\$hio_plugin = " . var_export( $basename, true ) . ";\n" // phpcs:ignore
			. "\tadd_filter( 'option_active_plugins', function () use ( \$hio_plugin ) { return array( \$hio_plugin ); }, PHP_INT_MAX );\n"
			. "\tadd_filter( 'site_option_active_sitewide_plugins', function () { return array(); }, PHP_INT_MAX );\n"
			. "\tadd_filter( 'pre_option_template', function () { return 'hio-no-theme'; } );\n"
			. "\tadd_filter( 'pre_option_stylesheet', function () { return 'hio-no-theme'; } );\n"
			. "\tadd_filter( 'validate_current_theme', '__return_false' );\n"
			. "}\n";
		if ( false === @file_put_contents( $dir . '/' . self::GUARD_FILE, $code ) ) { // phpcs:ignore
			$job->log( 'Could not write the import guard; continuing without it.', 'warning' );
		}
	}

	/**
	 * Remove the guard.
	 */
	public static function remove_guard() {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( is_file( $dir . '/' . self::GUARD_FILE ) ) {
			@unlink( $dir . '/' . self::GUARD_FILE ); // phpcs:ignore
		}
	}
}
