<?php
/**
 * Import: upload (chunked) -> validate -> extract into a staging folder ->
 * import database into temporary tables -> search & replace -> move the
 * staged files into place -> atomic table swap.
 *
 * The live site keeps running on its own files and database while the archive
 * is extracted and imported: files go into a staging folder inside the job
 * folder, tables get a temporary prefix. Only at the end are the staged
 * folders moved into place (one rename per plugin, theme or uploads year; the
 * folders they replace are kept until the end, so a cancelled import puts
 * them back), and then all tables are swapped in one atomic RENAME TABLE.
 * Must-use plugins and drop-ins, which WordPress loads on every request, are
 * put in place last, after the swap.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-gigabyte archives and SQL dumps in small chunks across requests (fopen/fread/fwrite/fseek/ftruncate); WP_Filesystem has no streaming API and would load whole files into memory.

/**
 * Importer.
 */
class HOLEMI_Importer implements HOLEMI_Tar_Handler {

	/** Archive folder of each role in archives without a "roots" map (before 1.1.0). */
	const DEFAULT_ROOTS = array(
		'plugins'    => 'wp-content/plugins',
		'mu-plugins' => 'wp-content/mu-plugins',
		'themes'     => 'wp-content/themes',
		'uploads'    => 'wp-content/uploads',
	);

	/** Entries up to this size are extracted in one go. */
	const SMALL_ENTRY = 8388608;

	/**
	 * Current job (while extracting).
	 *
	 * @var HOLEMI_Job
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
	 * @return HOLEMI_Job
	 */
	private static function new_job() {
		global $wpdb;
		if ( is_multisite() ) {
			throw new HOLEMI_Exception( esc_html__( 'WordPress Multisite is not supported.', 'hole-in-one-migration' ) );
		}
		$job              = HOLEMI_Job::create( 'imp' );
		$settings         = HOLEMI_Util::settings();
		$up               = wp_upload_dir( null, false );
		$job->state['target'] = array(
			'home'         => HOLEMI_Util::raw_url( 'home' ),
			'siteurl'      => HOLEMI_Util::raw_url( 'siteurl' ),
			'db_home'      => HOLEMI_Util::raw_option( 'home' ),
			'db_siteurl'   => HOLEMI_Util::raw_option( 'siteurl' ),
			'content_url'  => HOLEMI_Util::raw_content_urls()[0],
			'uploads_url'  => HOLEMI_Util::raw_content_urls()[1],
			'abspath'      => HOLEMI_Util::install_dir(),
			'content_dir'  => HOLEMI_Util::content_dir(),
			'uploads_dir'  => untrailingslashit( wp_normalize_path( $up['basedir'] ) ),
			// Where this site keeps each role. Taken now: after the database swap
			// the upload path could come from the imported options.
			'dirs'         => array_merge( array( 'content' => HOLEMI_Util::content_dir() ), HOLEMI_Util::roots() ),
			'plugin_dir'   => untrailingslashit( wp_normalize_path( plugin_dir_path( HOLEMI_FILE ) ) ),
			// The uploads location is also a database setting. This site's value
			// is kept, because the media files are put where this site keeps them.
			'upload_opts'  => array(
				'upload_path'     => HOLEMI_DB::value( 'SELECT option_value FROM %i WHERE option_name = %s', array( $wpdb->options, 'upload_path' ) ),
				'upload_url_path' => HOLEMI_DB::value( 'SELECT option_value FROM %i WHERE option_name = %s', array( $wpdb->options, 'upload_url_path' ) ),
			),
			'prefix'       => $wpdb->prefix,
			'plugin'       => plugin_basename( HOLEMI_FILE ),
			'delete_after' => ! empty( $settings['delete_after'] ),
		);
		return $job;
	}

	/**
	 * Start an import that will be uploaded in chunks from the browser.
	 *
	 * @param string $name File name.
	 * @param int    $size File size.
	 * @return HOLEMI_Job
	 * @throws HOLEMI_Exception On invalid input.
	 */
	public static function start_upload( $name, $size ) {
		if ( ! preg_match( '/\.(tar\.gz|tgz|zip)$/i', $name ) ) {
			throw new HOLEMI_Exception( esc_html__( 'Please choose a .tar.gz or .zip archive created by Hole-in-One Migration.', 'hole-in-one-migration' ) );
		}
		if ( $size <= 0 ) {
			throw new HOLEMI_Exception( esc_html__( 'The file is empty.', 'hole-in-one-migration' ) );
		}
		$free = HOLEMI_Util::free_space( HOLEMI_Storage::base() );
		if ( null !== $free && $free < $size * 1.1 ) {
			/* translators: 1: size the archive needs, 2: free disk space. */
			throw new HOLEMI_Exception( esc_html( sprintf( __( 'Not enough disk space: the archive needs %1$s, but only %2$s is free on this server.', 'hole-in-one-migration' ), HOLEMI_Util::size( $size ), HOLEMI_Util::size( $free ) ) ) );
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
		$job->state['message']   = __( 'Uploading…', 'hole-in-one-migration' );
		$job->log( sprintf( 'Import started (browser upload): %s, %s', sanitize_file_name( $name ), HOLEMI_Util::size( $size ) ) );
		$job->save();
		return $job;
	}

	/**
	 * Start an import from a file in the storage folder's import/ folder.
	 *
	 * @param string $name File name.
	 * @return HOLEMI_Job
	 * @throws HOLEMI_Exception If the file does not exist.
	 */
	public static function start_server_file( $name ) {
		$name = basename( (string) $name );
		$path = HOLEMI_Storage::dir( 'import' ) . '/' . $name;
		if ( ! preg_match( '/\.(tar\.gz|tgz|zip)$/i', $name ) || ! is_file( $path ) ) {
			throw new HOLEMI_Exception( esc_html__( 'That file was not found in the import folder.', 'hole-in-one-migration' ) );
		}
		$job                       = self::new_job();
		$job->state['phase']       = 'validate';
		$job->state['archive']     = $path;
		$job->state['own_archive'] = false;
		$job->log( sprintf( 'Import started (server file): %s, %s', $name, HOLEMI_Util::size( filesize( $path ) ) ) );
		$job->save();
		return $job;
	}

	/**
	 * Receive one upload chunk. Idempotent: a chunk sent twice is harmless.
	 *
	 * @param HOLEMI_Job $job    Job.
	 * @param int     $offset Offset of this chunk.
	 * @param string  $tmp    Uploaded temp file.
	 * @return int Bytes on the server after this chunk.
	 * @throws HOLEMI_Exception On error.
	 */
	public static function upload_chunk( HOLEMI_Job $job, $offset, $tmp ) {
		if ( 'upload' !== $job->state['phase'] ) {
			throw new HOLEMI_Exception( esc_html__( 'This import is not waiting for an upload.', 'hole-in-one-migration' ) );
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
			throw new HOLEMI_Exception( esc_html__( 'Could not store the uploaded chunk.', 'hole-in-one-migration' ) );
		}
		ftruncate( $fp, $offset );
		fseek( $fp, $offset );
		while ( ! feof( $in ) ) {
			$buf = fread( $in, 1048576 );
			if ( false === $buf ) {
				break;
			}
			HOLEMI_Util::fwrite_all( $fp, $buf );
		}
		fclose( $in );
		fflush( $fp );
		$have = ftell( $fp );
		fclose( $fp );

		$up             = &$job->state['upload'];
		$up['received'] = $have;
		$job->state['progress'] = (int) ( 10 * $have / max( 1, $up['size'] ) );
		/* translators: 1: size uploaded, 2: total size. */
		$job->state['message']  = sprintf( __( 'Uploading: %1$s of %2$s', 'hole-in-one-migration' ), HOLEMI_Util::size( $have ), HOLEMI_Util::size( $up['size'] ) );
		if ( $have >= $up['size'] ) {
			if ( $have > $up['size'] ) {
				$fp = fopen( $part, 'c+b' ); // phpcs:ignore
				ftruncate( $fp, $up['size'] );
				fclose( $fp );
			}
			if ( ! @rename( $part, $job->state['archive'] ) ) { // phpcs:ignore
				throw new HOLEMI_Exception( esc_html__( 'Could not finish the upload.', 'hole-in-one-migration' ) );
			}
			$job->state['phase']   = 'validate';
			$job->state['message'] = __( 'Upload complete. Checking the archive…', 'hole-in-one-migration' );
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
	 * @param HOLEMI_Job $job Job.
	 * @throws HOLEMI_Exception On error.
	 */
	public static function step( HOLEMI_Job $job ) {
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
				case 'install_files':
					$imp->install_files();
					if ( 'install_files' !== $job->state['phase'] ) {
						return; // Checkpoint with all files in place; the swap runs in a request of its own.
					}
					break;
				case 'swap':
					$imp->swap();
					return;
				case 'finish_files':
					$imp->finish_files_only();
					return;
				default:
					throw new HOLEMI_Exception( esc_html( 'Unknown phase.' ) );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Validate.
	 * ------------------------------------------------------------------ */

	/**
	 * Detect format, read the manifest, check compatibility.
	 *
	 * @throws HOLEMI_Exception On an invalid archive.
	 */
	private function validate() {
		$job  = $this->job;
		$s    = &$job->state;
		$path = $s['archive'];
		$fp   = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( ! $fp ) {
			throw new HOLEMI_Exception( esc_html__( 'Could not open the archive.', 'hole-in-one-migration' ) );
		}
		$magic = fread( $fp, 4 );
		fclose( $fp );

		if ( "\x1f\x8b" === substr( $magic, 0, 2 ) ) {
			$s['format'] = 'tar.gz';
			$manifest    = $this->read_tar_manifest( $path );
		} elseif ( "PK\x03\x04" === $magic ) {
			$s['format'] = 'zip';
			$info        = HOLEMI_Zip_Reader::build_index( $path, $job->path( 'zipindex.jsonl' ) );
			$s['zip']    = $info;
			$manifest    = null;
			$ih          = fopen( $job->path( 'zipindex.jsonl' ), 'rb' ); // phpcs:ignore
			while ( false !== ( $line = fgets( $ih ) ) ) { // phpcs:ignore
				$e = json_decode( $line, true );
				if ( 'hio-manifest.json' === base64_decode( $e['n'] ) ) { // phpcs:ignore
					$manifest = json_decode( HOLEMI_Zip_Reader::read_entry( $path, $e ), true );
					break;
				}
			}
			fclose( $ih );
		} else {
			throw new HOLEMI_Exception( esc_html__( 'This is not a .tar.gz or .zip file.', 'hole-in-one-migration' ) );
		}

		// Archive format id. It stayed 'holeinone-migration' when the plugin slug changed in 1.0.3.
		if ( ! is_array( $manifest ) || ! in_array( $manifest['generator'] ?? '', array( 'holeinone-migration', 'hole-in-one-migration' ), true ) ) {
			throw new HOLEMI_Exception( esc_html__( 'This archive was not created by Hole-in-One Migration (no manifest found). Please export the old site with this plugin first.', 'hole-in-one-migration' ) );
		}
		if ( (int) $manifest['format_version'] > HOLEMI_FORMAT_VERSION ) {
			throw new HOLEMI_Exception( esc_html__( 'This archive was made with a newer version of Hole-in-One Migration. Please update the plugin on this site first.', 'hole-in-one-migration' ) );
		}
		if ( ! empty( $manifest['multisite'] ) ) {
			throw new HOLEMI_Exception( esc_html__( 'Archives of WordPress Multisite networks are not supported.', 'hole-in-one-migration' ) );
		}

		// Archives of version 1.0.1 and older may carry an address that a plugin
		// had changed during the export, for example "/en" added by a
		// translation plugin. The database value is the real one then.
		foreach ( array( 'home' => 'db_home', 'siteurl' => 'db_siteurl' ) as $k => $dbk ) {
			if ( ! empty( $manifest[ $k ] ) && ! empty( $manifest[ $dbk ] ) ) {
				$have = untrailingslashit( (string) $manifest[ $k ] );
				$real = untrailingslashit( (string) $manifest[ $dbk ] );
				if ( $have !== $real && 0 === strpos( $have, $real . '/' ) ) {
					$job->log( sprintf( 'The archive lists the site address as %1$s; using the address stored in its database, %2$s.', $have, $real ) );
					$manifest[ $k ] = $real;
				}
			}
		}
		$s['manifest'] = $manifest;
		$job->log( sprintf( 'Archive OK: %s from %s (WordPress %s, PHP %s, contents: %s, created %s).', $s['format'], $manifest['home'], $manifest['wp_version'], $manifest['php_version'], $manifest['contents'], $manifest['created'] ) );
		if ( version_compare( PHP_VERSION, $manifest['php_version'], '<' ) ) {
			$job->log( sprintf( 'This server runs PHP %s, the old one ran %s. Some plugins may need the newer PHP version.', PHP_VERSION, $manifest['php_version'] ), 'warning' );
		}
		$free = HOLEMI_Util::free_space( HOLEMI_Util::content_dir() );
		$need = (float) ( $manifest['bytes'] ?? 0 );
		if ( null !== $free && $need > 0 && $free < $need ) {
			$job->log( sprintf( 'Low disk space: %1$s free, the site files need up to %2$s.', HOLEMI_Util::size( $free ), HOLEMI_Util::size( $need ) ), 'warning' );
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
		$s['roots']   = self::source_roots( $manifest );
		$s['phase']   = 'extract';
		$s['message'] = __( 'Extracting files…', 'hole-in-one-migration' );
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
	 * Archive folder of each role in this archive, longest first.
	 *
	 * @param array $m Manifest.
	 * @return string[] role => archive folder.
	 */
	private static function source_roots( array $m ) {
		$roots = self::DEFAULT_ROOTS;
		// Before 1.1.0 an uploads folder with another name inside wp-content
		// was stored under that name.
		if ( ! empty( $m['uploads_dir'] ) && ! empty( $m['content_dir'] ) && HOLEMI_Util::inside( $m['uploads_dir'], $m['content_dir'] ) && $m['uploads_dir'] !== $m['content_dir'] ) {
			$roots['uploads'] = 'wp-content' . substr( untrailingslashit( $m['uploads_dir'] ), strlen( untrailingslashit( $m['content_dir'] ) ) );
		}
		if ( ! empty( $m['roots'] ) && is_array( $m['roots'] ) ) {
			foreach ( $m['roots'] as $role => $folder ) {
				if ( isset( $roots[ $role ] ) && is_string( $folder ) && '' !== $folder ) {
					$roots[ $role ] = trim( str_replace( '\\', '/', $folder ), '/' );
				}
			}
		}
		uasort(
			$roots,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $roots;
	}

	/**
	 * Split an archive entry name into the role of its folder and the path
	 * inside that folder, or null when it is not a site file.
	 *
	 * @param string $name Entry name.
	 * @return array|null [ role, path ]
	 */
	private function split_entry( $name ) {
		$name = trim( preg_replace( '#^\./#', '', str_replace( '\\', '/', $name ) ), '/' );
		if ( false !== strpos( $name, "\0" ) ) {
			return null;
		}
		$role = null;
		$rest = '';
		foreach ( $this->job->state['roots'] as $r => $folder ) {
			if ( $name === $folder ) {
				return null; // The role folder itself always exists.
			}
			if ( 0 === strpos( $name, $folder . '/' ) ) {
				$role = $r;
				$rest = substr( $name, strlen( $folder ) + 1 );
				break;
			}
		}
		if ( null === $role ) {
			if ( 0 !== strpos( $name, 'wp-content/' ) ) {
				return null;
			}
			$role = 'content';
			$rest = substr( $name, 11 );
		}
		if ( '' === $rest ) {
			return null;
		}
		foreach ( explode( '/', $rest ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return null; // No path tricks: stay inside the folder.
			}
		}
		return array( $role, $rest );
	}

	/**
	 * Map an archive entry name to the path it is extracted to, or null to
	 * skip it. Site files never go into the live site directly: they are
	 * extracted into files/<role>/ in the job folder and moved into place by
	 * install_files(). Files that WordPress loads on every request go into
	 * staged/ and are put in place after the database swap.
	 *
	 * @param string $name Entry name.
	 * @return string|null
	 */
	private function map_entry( $name ) {
		if ( 'hio-database.sql' === trim( preg_replace( '#^\./#', '', $name ), '/' ) ) {
			return $this->job->path( 'database.sql' );
		}
		$m = $this->job->state['manifest'];
		if ( 'db' === $m['contents'] ) {
			return null;
		}
		$split = $this->split_entry( $name );
		if ( null === $split ) {
			return null;
		}
		list( $role, $rest ) = $split;
		$rel  = 'content' === $role ? $rest : $role . '/' . $rest;
		$top  = explode( '/', $rest )[0];
		$skip = array(
			'plugins/' . basename( $this->job->state['target']['plugin_dir'] ),
			'plugins/' . ( $m['plugin_dir'] ?? 'holeinone-migration' ),
			'plugins/holeinone-migration', // Copies of this plugin under its pre-1.0.3 name.
			'plugins/hole-in-one-migration',
			'mu-plugins/0000-hio-import-guard.php', // Leftover of versions before 1.1.0.
		);
		foreach ( $skip as $sk ) {
			if ( $rel === $sk || 0 === strpos( $rel, $sk . '/' ) ) {
				return null;
			}
		}
		if ( 'content' === $role && HOLEMI_Storage::is_storage_name( $top ) ) {
			return null;
		}
		if ( self::autoloaded( $rel ) ) {
			// WordPress runs these on every request, also on this import's own
			// requests. A loader whose folder is not in place yet, or one that
			// changes the site address and so logs the user out, would break the
			// import. They are put in place only after the database switch, see
			// install_staged().
			$this->job->state['staged'][ $rel ] = array( $role, $rest );
			return $this->job->path( 'staged/' . $rel );
		}
		return $this->job->path( 'files/' . $role . '/' . $rest );
	}

	/**
	 * Is this file loaded by WordPress on every request? Must-use plugins in
	 * the folder root, and drop-ins that load without any setting in
	 * wp-config.php.
	 *
	 * @param string $rel Path relative to wp-content, with role folders under their role name.
	 * @return bool
	 */
	private static function autoloaded( $rel ) {
		$rel = strtolower( $rel );
		if ( preg_match( '#^mu-plugins/[^/]+\.php$#', $rel ) ) {
			return true;
		}
		return in_array( $rel, array( 'object-cache.php', 'db.php', 'advanced-cache.php', 'fatal-error-handler.php', 'sunrise.php' ), true );
	}

	/**
	 * Live path of a role folder on this site.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	private function target_dir( $role ) {
		$dirs = $this->job->state['target']['dirs'];
		return isset( $dirs[ $role ] ) ? $dirs[ $role ] : $dirs['content'];
	}

	/**
	 * Move the held-back must-use plugins and drop-ins into place. A file that
	 * hard-codes the OLD site address (WP_HOME / WP_SITEURL) is left out: it
	 * would send every visitor and login back to the old site. Safe to run
	 * again after a crash: files already moved are skipped.
	 */
	private function install_staged() {
		$job = $this->job;
		$s   = &$job->state;
		if ( empty( $s['staged'] ) ) {
			return;
		}
		$old_host = (string) wp_parse_url( (string) $s['manifest']['home'], PHP_URL_HOST );
		$moved    = untrailingslashit( (string) $s['manifest']['home'] ) !== untrailingslashit( (string) $s['target']['home'] );
		foreach ( $s['staged'] as $rel => $where ) {
			$src = $job->path( 'staged/' . $rel );
			$dst = $this->target_dir( $where[0] ) . '/' . $where[1];
			if ( ! is_file( $src ) ) {
				unset( $s['staged'][ $rel ] );
				continue;
			}
			$code = (string) HOLEMI_Util::read_file( $src );
			if ( $moved && '' !== $old_host && preg_match( '/define\s*\(\s*[\'"]WP_(HOME|SITEURL)[\'"]/i', $code ) && false !== stripos( $code, $old_host ) ) {
				$job->log( sprintf( 'Left out %s: it hard-codes the old site address (WP_HOME / WP_SITEURL), which would send visitors and logins back to the old site. It is usually a leftover of another migration tool.', $rel ), 'warning' );
				@unlink( $src ); // phpcs:ignore
				unset( $s['staged'][ $rel ] );
				continue;
			}
			if ( ! is_dir( dirname( $dst ) ) ) {
				wp_mkdir_p( dirname( $dst ) );
			}
			if ( ! @rename( $src, $dst ) && ! ( @copy( $src, $dst ) && @unlink( $src ) ) ) { // phpcs:ignore
				$job->log( 'Could not install ' . $rel . ' (check permissions).', 'warning' );
			}
			unset( $s['staged'][ $rel ] );
		}
		$job->save();
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
			HOLEMI_Util::fwrite_all( $this->out_fp, $data );
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
	 * @throws HOLEMI_Exception On a damaged archive.
	 */
	private function extract_tar() {
		$job  = $this->job;
		$s    = &$job->state;
		$ex   = &$s['ex'];
		$path = $s['archive'];
		$size = filesize( $path );
		$fp   = fopen( $path, 'rb' ); // phpcs:ignore

		// Work on a copy of the checkpoint; commit it only at member boundaries.
		$parser = new HOLEMI_Tar_Parser( $this, $ex['parser'] );
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
						throw new HOLEMI_Exception( esc_html__( 'The archive is incomplete (unexpected end of file). Please upload it again.', 'hole-in-one-migration' ) );
					}
					$out = @inflate_add( $ctx, $chunk, ZLIB_SYNC_FLUSH ); // phpcs:ignore
					if ( false === $out ) {
						throw new HOLEMI_Exception( esc_html__( 'The archive is damaged (decompression failed). Please upload it again.', 'hole-in-one-migration' ) );
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
		$s['message']  = sprintf( __( 'Extracting: %1$s files, %2$s', 'hole-in-one-migration' ), HOLEMI_Util::num( $ex['files'] ), HOLEMI_Util::size( $ex['bytes'] ) );

		if ( $parser->ended() || $ex['coff'] >= $size ) {
			if ( ! $parser->ended() ) {
				throw new HOLEMI_Exception( esc_html__( 'The archive is incomplete (end marker missing). Please create and upload it again.', 'hole-in-one-migration' ) );
			}
			$this->extract_done();
		}
	}

	/**
	 * Extract a .zip entry by entry.
	 *
	 * @throws HOLEMI_Exception On a damaged archive.
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
							throw new HOLEMI_Exception( esc_html__( 'The archive is incomplete (unexpected end of file).', 'hole-in-one-migration' ) );
						}
						$this->entry_data( $buf );
						$cur['crc']  = HOLEMI_Util::crc32_combine( $cur['crc'], crc32( $buf ), strlen( $buf ) );
						$cur['done'] += strlen( $buf );
					}
					if ( $cur['done'] >= $cur['u'] ) {
						if ( ( $cur['crc'] & 0xFFFFFFFF ) !== ( $cur['crc_want'] & 0xFFFFFFFF ) ) {
							/* translators: %s: file name inside the archive. */
							throw new HOLEMI_Exception( esc_html( sprintf( __( 'Checksum error in %s. The archive is damaged; please upload it again.', 'hole-in-one-migration' ), $cur['name'] ) ) );
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
				$data_off = HOLEMI_Zip_Reader::data_offset( $fp, $e['o'] );
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
						throw new HOLEMI_Exception( esc_html__( 'The archive is incomplete (unexpected end of file).', 'hole-in-one-migration' ) );
					}
					$left -= strlen( $buf );
					if ( $ctx ) {
						$buf = inflate_add( $ctx, $buf, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH );
						if ( false === $buf ) {
							/* translators: %s: file name inside the archive. */
							throw new HOLEMI_Exception( esc_html( sprintf( __( 'Decompression failed for %s. The archive is damaged.', 'hole-in-one-migration' ), $name ) ) );
						}
					}
					hash_update( $hc, $buf );
					$this->entry_data( $buf );
				}
				if ( hexdec( hash_final( $hc ) ) !== $e['crc'] ) {
					/* translators: %s: file name inside the archive. */
					throw new HOLEMI_Exception( esc_html( sprintf( __( 'Checksum error in %s. The archive is damaged; please upload it again.', 'hole-in-one-migration' ), $name ) ) );
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
		$s['message']  = sprintf( __( 'Extracting: %1$s files, %2$s', 'hole-in-one-migration' ), HOLEMI_Util::num( $ex['files'] ), HOLEMI_Util::size( $ex['bytes'] ) );
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
			$s['phase']   = 'install_files';
			$s['message'] = __( 'Moving the files into place…', 'hole-in-one-migration' );
			return;
		}
		if ( ! is_file( $this->job->path( 'database.sql' ) ) ) {
			throw new HOLEMI_Exception( esc_html__( 'The archive does not contain a database.', 'hole-in-one-migration' ) );
		}
		$s['phase']   = 'db_import';
		$s['message'] = __( 'Importing database…', 'hole-in-one-migration' );
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
			if ( ! HOLEMI_DB::tables_with_prefix( $p ) ) {
				return $p;
			}
		}
		throw new HOLEMI_Exception( esc_html( 'Could not find a free temporary table prefix.' ) );
	}

	/**
	 * Set safe session variables for the import.
	 */
	private function db_session() {
		HOLEMI_DB::exec( 'SET SESSION sql_mode = %s', array( 'NO_AUTO_VALUE_ON_ZERO' ) );
		HOLEMI_DB::exec( 'SET SESSION foreign_key_checks = %d', array( 0 ) );
		try {
			HOLEMI_DB::exec( 'SET NAMES %s', array( 'utf8mb4' ) );
		} catch ( HOLEMI_Exception $e ) {
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
		foreach ( HOLEMI_DB::rows( 'SHOW COLLATION LIKE %s', array( '%' ) ) as $r ) {
			$coll[ strtolower( $r[0] ) ] = true;
		}
		$eng = array();
		foreach ( HOLEMI_DB::rows( 'SELECT ENGINE FROM information_schema.ENGINES WHERE SUPPORT IN (%s, %s)', array( 'YES', 'DEFAULT' ) ) as $r ) {
			$eng[ strtolower( $r[0] ) ] = true;
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
	 * @throws HOLEMI_Exception On a failing statement.
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
		$tok = '`' . HOLEMI_Exporter::PREFIX_TOKEN;
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
			if ( '' === $sql || 0 === strpos( $sql, '--' ) || 0 === strpos( $sql, '/*!' ) ) {
				// Comments and the session settings in the header: this
				// connection already uses them (see db_session()).
				$d['off'] = $next;
				continue;
			}
			if ( ';' !== substr( $sql, -1 ) ) {
				throw new HOLEMI_Exception( esc_html__( 'The database dump in the archive is incomplete. Please export the old site again.', 'hole-in-one-migration' ) );
			}
			if ( 0 === strpos( $sql, 'INSERT INTO ' . $tok ) ) {
				// REPLACE makes re-running a statement after a crash harmless.
				$sql = 'REPLACE INTO `' . $tmp . substr( $sql, strlen( 'INSERT INTO ' . $tok ) );
			} elseif ( 0 === strpos( $sql, 'DROP TABLE IF EXISTS ' . $tok ) ) {
				$sql = 'DROP TABLE IF EXISTS `' . $tmp . substr( $sql, strlen( 'DROP TABLE IF EXISTS ' . $tok ) );
			} elseif ( 0 === strpos( $sql, 'CREATE TABLE ' ) ) {
				$sql = $this->fix_create( str_replace( $tok, '`' . $tmp, $sql ), $coll, $eng );
				if ( preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $m ) && strlen( $m[1] ) > 64 ) {
					throw new HOLEMI_Exception( esc_html( sprintf( 'Table name too long: %s', $m[1] ) ) );
				}
			} else {
				$job->log( 'Unknown statement in dump skipped: ' . substr( $sql, 0, 80 ), 'warning' );
				$d['off'] = $next;
				continue;
			}
			try {
				// A statement of the dump file (migration dump exception, see
				// HOLEMI_DB::run_dump_statement()); checked above to be one of
				// the three kinds the export writes.
				HOLEMI_DB::run_dump_statement( $sql );
			} catch ( HOLEMI_Exception $e ) {
				$msg = HOLEMI_Util::plain( $e );
				if ( false !== stripos( $msg, 'max_allowed_packet' ) || false !== stripos( $msg, 'gone away' ) ) {
					$msg .= ' ' . __( '(The database server rejected a large statement. Ask your host to raise max_allowed_packet to 16M or more.)', 'hole-in-one-migration' );
				}
				fclose( $fp );
				/* translators: 1: database error message, 2: start of the failed SQL statement. */
				throw new HOLEMI_Exception( esc_html( sprintf( __( 'Database import failed: %1$s — statement: %2$s', 'hole-in-one-migration' ), $msg, substr( $sql, 0, 300 ) ) ) );
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
		$s['message']  = sprintf( __( 'Importing database: %1$s of %2$s', 'hole-in-one-migration' ), HOLEMI_Util::size( $d['off'] ), HOLEMI_Util::size( $d['size'] ) );
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
	 * @return HOLEMI_Search_Replace
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
		$t_dirs = $t['dirs'];
		foreach ( array(
			'uploads_dir'    => $t['uploads_dir'],
			'plugins_dir'    => $t_dirs['plugins'],
			'mu_plugins_dir' => $t_dirs['mu-plugins'],
			'themes_dir'     => $t_dirs['themes'],
			'content_dir'    => $t['content_dir'],
			'abspath'        => $t['abspath'],
		) as $k => $new ) {
			if ( ! empty( $m[ $k ] ) && ! isset( $paths[ $m[ $k ] ] ) ) {
				$paths[ $m[ $k ] ] = $new;
			}
		}
		return new HOLEMI_Search_Replace( HOLEMI_Search_Replace::build_map( $urls, $paths ) );
	}

	/**
	 * Run search & replace until time runs out.
	 */
	private function replace() {
		global $wpdb;
		$job = $this->job;
		$s   = &$job->state;
		$tmp = $s['dbi']['tmp'];
		$sr  = $this->engine();

		if ( ! isset( $s['rep'] ) ) {
			$tables = array();
			$status = HOLEMI_DB::table_status( $tmp );
			foreach ( HOLEMI_DB::tables_with_prefix( $tmp ) as $t ) {
				$cols  = array();
				$types = array();
				foreach ( HOLEMI_DB::columns( $t ) as $c ) {
					$types[ $c['name'] ] = $c['type'];
					if ( ! $c['generated'] && preg_match( '/char|text|blob/', $c['type'] ) ) {
						$cols[] = $c['name'];
					}
				}
				$pk       = HOLEMI_DB::primary_key( $t );
				// Key range of numeric keys, only used to show real progress.
				$range = null;
				if ( $pk && $cols && preg_match( '/int/', $types[ $pk[0] ] ) ) {
					$mm    = HOLEMI_DB::rows( 'SELECT MIN(%i), MAX(%i) FROM %i', array( $pk[0], $pk[0], $t ) );
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
			$table = $t['name'];

			if ( empty( $t['pk'] ) ) {
				// No primary key: plain SQL REPLACE (rare, e.g. some log tables).
				// UPDATE %i SET %i = REPLACE(REPLACE(%i, %s, %s), %s, %s) ...
				foreach ( $t['cols'] as $c ) {
					$expr = '%i';
					$vals = array( $c );
					foreach ( $sr->map() as $from => $to ) {
						$expr   = 'REPLACE(' . $expr . ', %s, %s)';
						$vals[] = $from;
						$vals[] = $to;
					}
					HOLEMI_DB::exec( 'UPDATE %i SET %i = ' . $expr, array_merge( array( $table, $c ), $vals ) );
				}
				$this->next_table( $r );
				continue;
			}

			$order   = HOLEMI_DB::idents( count( $t['pk'] ) );
			$batch   = $job->scaled( $t['batch'], 5 );
			$window  = $job->scaled( 5000, 200 );
			$after   = null !== $r['last'] ? HOLEMI_DB::after( $t['pk'], (array) $r['last'] ) : null;

			// 1) End of the next window of rows in key order (index-only, cheap).
			//    Bounding every query to a window keeps it fast even when few
			//    rows match, so no single query can run into a timeout.
			$query = 'SELECT ' . $order . ' FROM %i';
			$args  = array_merge( $t['pk'], array( $table ) );
			if ( $after ) {
				$query .= ' WHERE ' . $after[0];
				$args   = array_merge( $args, $after[1] );
			}
			$query .= ' ORDER BY ' . $order . ' LIMIT %d, 1';
			$args   = array_merge( $args, $t['pk'], array( $window - 1 ) );
			$upper  = HOLEMI_DB::rows( $query, $args );
			$upper  = $upper ? $upper[0] : null;

			// 2) Rows in that window that contain an old address or path.
			//    Template and values are built side by side: every table name,
			//    column name, key value and search string is a placeholder.
			$where = array();
			$wargs = array();
			if ( $after ) {
				$where[] = $after[0];
				$wargs   = array_merge( $wargs, $after[1] );
			}
			if ( null !== $upper ) {
				$not     = HOLEMI_DB::after( $t['pk'], $upper );
				$where[] = 'NOT ' . $not[0];
				$wargs   = array_merge( $wargs, $not[1] );
			}
			$likes = array();
			foreach ( $t['cols'] as $c ) {
				foreach ( $needles as $nd ) {
					$likes[] = '%i LIKE %s';
					$wargs[] = $c;
					$wargs[] = '%' . $wpdb->esc_like( $nd ) . '%';
				}
			}
			if ( $likes ) {
				$where[] = '(' . implode( ' OR ', $likes ) . ')';
			}
			$sel   = array_values( array_unique( array_merge( $t['pk'], $t['cols'] ) ) );
			$query = 'SELECT ' . HOLEMI_DB::idents( count( $sel ) ) . ' FROM %i';
			$args  = array_merge( $sel, array( $table ) );
			if ( $where ) {
				$query .= ' WHERE ' . implode( ' AND ', $where );
				$args   = array_merge( $args, $wargs );
			}
			$query .= ' ORDER BY ' . $order . ' LIMIT %d';
			$args   = array_merge( $args, $t['pk'], array( $batch ) );

			$rows = HOLEMI_DB::rows( $query, $args, true );
			// One transaction per batch: one disk sync instead of one per row
			// (often 10-50x faster on InnoDB). A crash rolls the batch back.
			HOLEMI_DB::begin();
			foreach ( $rows as $row ) {
				$set  = array();
				$args = array( $table );
				foreach ( $t['cols'] as $c ) {
					if ( null === $row[ $c ] ) {
						continue;
					}
					$new = $sr->run( $row[ $c ] );
					if ( $new !== $row[ $c ] ) {
						$set[]  = '%i = %s';
						$args[] = $c;
						$args[] = $new;
					}
				}
				if ( $set ) {
					$cond = array();
					foreach ( $t['pk'] as $k ) {
						$cond[] = '%i = %s';
						$args[] = $k;
						$args[] = (string) $row[ $k ];
					}
					// UPDATE %i SET %i = %s, ... WHERE %i = %s AND ...
					HOLEMI_DB::exec( 'UPDATE %i SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $cond ), $args );
					$r['rows']++;
				}
			}
			HOLEMI_DB::commit();
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
				$s['message'] = sprintf( __( 'Updating addresses: table %1$d of %2$d (%3$s, about %4$d%%)', 'hole-in-one-migration' ), $r['ti'] + 1, count( $r['tables'] ), $cur['name'], $pct );
			}
		}

		$s['progress'] = 85 + (int) ( 10 * $r['ti'] / max( 1, count( $r['tables'] ) ) );
		if ( $r['ti'] >= count( $r['tables'] ) ) {
			$job->log( sprintf( 'Search & replace complete: %d rows updated.', $r['rows'] ) );
			$this->fixups();
			$s['phase']   = 'install_files';
			$s['message'] = __( 'Moving the files into place…', 'hole-in-one-migration' );
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
		global $wpdb;
		$s    = $this->job->state;
		$tmp  = $s['dbi']['tmp'];
		$src  = $s['manifest']['prefix'];
		$dst  = $s['target']['prefix'];
		$opt  = $tmp . 'options';
		$um   = $tmp . 'usermeta';

		if ( ! in_array( $opt, HOLEMI_DB::tables_with_prefix( $tmp ), true ) ) {
			throw new HOLEMI_Exception( esc_html__( 'The imported database has no options table. The archive does not look like a WordPress database.', 'hole-in-one-migration' ) );
		}

		// Table prefix changed: rename prefix-dependent keys.
		if ( $src !== $dst ) {
			if ( in_array( $um, HOLEMI_DB::tables_with_prefix( $tmp ), true ) ) {
				HOLEMI_DB::exec(
					'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
					array( $um, $dst, strlen( $src ) + 1, $wpdb->esc_like( $src ) . '%' )
				);
			}
			HOLEMI_DB::exec( 'DELETE FROM %i WHERE option_name = %s', array( $opt, $dst . 'user_roles' ) );
			HOLEMI_DB::exec( 'UPDATE %i SET option_name = %s WHERE option_name = %s', array( $opt, $dst . 'user_roles', $src . 'user_roles' ) );
			$this->job->log( sprintf( 'Table prefix changed from %s to %s; user roles and capabilities updated.', $src, $dst ) );
		}

		// This site's address and the plugin must stay intact.
		HOLEMI_DB::exec( 'UPDATE %i SET option_value = %s WHERE option_name = %s', array( $opt, $s['target']['db_home'], 'home' ) );
		HOLEMI_DB::exec( 'UPDATE %i SET option_value = %s WHERE option_name = %s', array( $opt, $s['target']['db_siteurl'], 'siteurl' ) );

		// Media files were put into this site's uploads folder (see install_files()),
		// so this site's upload settings must stay too.
		foreach ( $s['target']['upload_opts'] as $name => $value ) {
			$was = HOLEMI_DB::value( 'SELECT option_value FROM %i WHERE option_name = %s', array( $opt, $name ) );
			if ( (string) $was !== (string) $value ) {
				$this->put_option( $opt, $name, (string) $value );
				$this->job->log( sprintf( 'Kept this site\'s %1$s setting (%2$s) instead of the old site\'s (%3$s): the media files are in this site\'s uploads folder.', $name, '' === (string) $value ? 'empty' : $value, $was ) );
			}
		}

		$active  = HOLEMI_DB::value( 'SELECT option_value FROM %i WHERE option_name = %s', array( $opt, 'active_plugins' ) );
		$plugins = is_string( $active ) ? @unserialize( $active, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
		$plugins = is_array( $plugins ) ? $plugins : array();
		$mine    = $s['target']['plugin'];
		$plugins = array_values(
			array_filter(
				$plugins,
				function ( $p ) use ( $mine ) {
					// Drop other copies of this plugin, including the pre-1.0.3 name.
					return is_string( $p ) && ( $p === $mine || ! preg_match( '#(^|/)hole-?in-?one-migration\.php$#', $p ) );
				}
			)
		);
		if ( ! in_array( $mine, $plugins, true ) ) {
			$plugins[] = $mine;
		}
		$this->put_option( $opt, 'active_plugins', serialize( $plugins ) ); // phpcs:ignore

		// Caches that contain old paths/addresses are rebuilt automatically.
		HOLEMI_DB::exec(
			'DELETE FROM %i WHERE option_name = %s OR option_name LIKE %s OR option_name LIKE %s',
			array( $opt, 'rewrite_rules', $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' )
		);

		$notice = array(
			'time'     => time(),
			'from'     => $s['manifest']['home'],
			'warnings' => (int) $s['warnings'],
			'flush'    => 1,
			'job'      => $this->job->id, // Marker: proves this job's swap happened.
		);
		$this->put_option( $opt, 'holemi_post_import', serialize( $notice ) ); // phpcs:ignore
	}

	/**
	 * Set an option in the imported (not yet live) options table. The
	 * WordPress option functions cannot be used: they work on the live table.
	 *
	 * @param string $table Options table.
	 * @param string $name  Option name.
	 * @param string $value Option value (already serialized when needed).
	 * @throws HOLEMI_Exception On error.
	 */
	private function put_option( $table, $name, $value ) {
		HOLEMI_DB::exec( 'DELETE FROM %i WHERE option_name = %s', array( $table, $name ) );
		HOLEMI_DB::exec( 'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( $table, $name, $value, 'yes' ) );
	}

	/* --------------------------------------------------------------------
	 * Swap: one atomic RENAME TABLE.
	 * ------------------------------------------------------------------ */

	/**
	 * Put the imported tables live.
	 *
	 * @throws HOLEMI_Exception If the swap fails (the site is then unchanged).
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
		$new = HOLEMI_DB::tables_with_prefix( $tmp );
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
			HOLEMI_DB::tables_with_prefix( $dst ),
			function ( $t ) use ( $finals ) {
				return isset( $finals[ $t ] );
			}
		);
		$this->db_session();

		$check = HOLEMI_DB::value( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', array( $tmp . 'options', 'siteurl' ) );
		if ( 1 > (int) $check ) {
			throw new HOLEMI_Exception( esc_html__( 'Safety check failed: the imported options table has no site address. Nothing was changed.', 'hole-in-one-migration' ) );
		}

		// RENAME TABLE %i TO %i, %i TO %i, ...
		$names = array();
		foreach ( $old as $t ) {
			$names[] = $t;
			$names[] = $bak . substr( $t, strlen( $dst ) );
		}
		foreach ( $new as $t ) {
			$final = $dst . substr( $t, strlen( $tmp ) );
			if ( strlen( $final ) > 64 ) {
				throw new HOLEMI_Exception( esc_html( sprintf( 'Table name too long: %s', $final ) ) );
			}
			$names[] = $t;
			$names[] = $final;
		}
		$job->log( sprintf( 'Swapping %d imported tables in (one atomic operation)…', count( $new ) ) );
		$job->save(); // Checkpoint right before the point of no return.
		HOLEMI_DB::exec( 'RENAME TABLE ' . implode( ', ', array_fill( 0, count( $names ) / 2, '%i TO %i' ) ), $names );
		$s['swapped'] = true;
		$job->save(); // ...and right after it.
		$this->after_swap();
	}

	/**
	 * Was this job's swap already done? True when the state says so, or when
	 * the temporary tables are gone and the live options table carries this
	 * job's marker (the request died between the swap and its checkpoint).
	 *
	 * @param HOLEMI_Job $job Job.
	 * @return bool
	 */
	private static function is_swapped( HOLEMI_Job $job ) {
		$s = $job->state;
		if ( ! empty( $s['swapped'] ) ) {
			return true;
		}
		if ( empty( $s['dbi']['tmp'] ) || HOLEMI_DB::tables_with_prefix( $s['dbi']['tmp'] ) ) {
			return false;
		}
		$v = HOLEMI_DB::value( 'SELECT option_value FROM %i WHERE option_name = %s', array( $s['target']['prefix'] . 'options', 'holemi_post_import' ) );
		$v = is_string( $v ) ? @unserialize( $v, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore
		return is_array( $v ) && isset( $v['job'] ) && $v['job'] === $job->id;
	}

	/**
	 * Finish an import whose swap is done. Used by the status endpoint, which
	 * works without a login: after the swap the old login session is gone, so
	 * the normal step request can no longer finish the job.
	 *
	 * @param HOLEMI_Job $job Job (locked by the caller).
	 * @return bool Whether the job was finished.
	 */
	public static function finish_if_swapped( HOLEMI_Job $job ) {
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
		$backups = empty( $s['bak'] ) ? array() : HOLEMI_DB::tables_with_prefix( $s['bak'] );
		foreach ( array_chunk( $backups, 50 ) as $chunk ) {
			try {
				HOLEMI_DB::exec( 'DROP TABLE IF EXISTS ' . HOLEMI_DB::idents( count( $chunk ), ', ' ), $chunk );
			} catch ( HOLEMI_Exception $e ) {
				$job->log( 'Could not remove old tables: ' . HOLEMI_Util::plain( $e ), 'warning' );
			}
		}
		$job->log( 'Database switched. Old tables removed.' );
		$this->install_staged();
		$this->complete();
	}

	/**
	 * Files-only import: nothing to swap.
	 */
	private function finish_files_only() {
		$this->install_staged();
		$this->complete();
	}

	/**
	 * Clean up and mark the job as done.
	 */
	private function complete() {
		$job = $this->job;
		$s   = &$job->state;
		@unlink( $job->path( 'database.sql' ) ); // phpcs:ignore
		@unlink( $job->path( 'zipindex.jsonl' ) ); // phpcs:ignore
		// The folders that the imported ones replaced (kept until now for a cancel).
		foreach ( array( 'replaced', 'files', 'staged', 'trash' ) as $sub ) {
			HOLEMI_Storage::rrmdir( $job->path( $sub ) );
		}
		if ( ! empty( $s['own_archive'] ) ) {
			@unlink( $s['archive'] ); // phpcs:ignore
		} elseif ( ! empty( $s['target']['delete_after'] ) ) {
			@unlink( $s['archive'] ); // phpcs:ignore
		}
		$db             = 'files' !== $s['manifest']['contents'];
		$s['phase']     = 'done';
		$s['status']    = 'done';
		$s['progress']  = 100;
		$s['message']   = __( 'Import complete!', 'hole-in-one-migration' );
		$s['result']    = array(
			'relogin' => $db,
			'login'   => wp_login_url( admin_url( 'admin.php?page=hole-in-one-migration' ) ),
			'home'    => home_url( '/' ),
		);
		$job->log( 'Import complete.' . ( $db ? ' Log in with the username and password of the OLD site.' : '' ) );
	}

	/* --------------------------------------------------------------------
	 * Moving the extracted files into place.
	 * ------------------------------------------------------------------ */

	/**
	 * Would replacing $dst touch a folder that must stay (a role folder, this
	 * plugin or its storage)? Such an archive entry is skipped.
	 *
	 * @param string $dst Live path.
	 * @return bool
	 */
	private function is_protected( $dst ) {
		$t    = $this->job->state['target'];
		$keep = array_merge( array_values( $t['dirs'] ), array( $t['plugin_dir'], HOLEMI_Storage::base() ) );
		foreach ( $keep as $k ) {
			if ( HOLEMI_Util::inside( $k, $dst ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Move the extracted folders into place, one unit at a time: every folder
	 * or file directly inside a role folder (one plugin, one theme, one uploads
	 * year, one wp-content folder). Each unit is a single rename, so the live
	 * site never sees a half-written plugin or theme. The unit it replaces is
	 * moved into the job folder first and deleted only when the import is
	 * complete; a cancelled import moves it back (see rollback_files()).
	 * When a rename is impossible (staging and site on different disks), the
	 * unit is copied in resumable chunks instead.
	 */
	private function install_files() {
		$job = $this->job;
		$s   = &$job->state;
		if ( ! isset( $s['inst'] ) ) {
			$units = array();
			$base  = $job->path( 'files' );
			foreach ( (array) @scandir( $base ) as $role ) { // phpcs:ignore
				if ( ! is_string( $role ) || '.' === $role || '..' === $role || ! is_dir( $base . '/' . $role ) ) {
					continue;
				}
				foreach ( (array) @scandir( $base . '/' . $role ) as $name ) { // phpcs:ignore
					if ( is_string( $name ) && '.' !== $name && '..' !== $name ) {
						$units[] = array( $role, base64_encode( $name ) ); // phpcs:ignore
					}
				}
			}
			$s['inst'] = array(
				'units' => $units,
				'i'     => 0,
				'moved' => array(),
				'copy'  => null,
			);
			$job->log( sprintf( 'Moving %d extracted folders and files into place.', count( $units ) ) );
			$job->save();
		}
		$in = &$s['inst'];

		while ( $in['i'] < count( $in['units'] ) && ! $job->should_stop() ) {
			$role = $in['units'][ $in['i'] ][0];
			$name = base64_decode( $in['units'][ $in['i'] ][1] ); // phpcs:ignore
			$src  = $job->path( 'files/' . $role . '/' . $name );
			$dst  = $this->target_dir( $role ) . '/' . $name;
			$old  = $job->path( 'replaced/' . $role . '/' . $name );
			$unit = array( $role, $in['units'][ $in['i'] ][1] );

			if ( null !== $in['copy'] ) {
				if ( ! $this->copy_step( $in['copy'] ) ) {
					break; // Time is up; continue in the next request.
				}
				if ( is_dir( $src ) ) {
					HOLEMI_Storage::rrmdir( $src );
				} else {
					@unlink( $src ); // phpcs:ignore
				}
				$in['moved'][] = array_merge( $unit, array( ! empty( $in['copy']['replaced'] ), true, ! empty( $in['copy']['merged'] ) ) );
				$in['copy']    = null;
				$in['i']++;
				$job->save();
				continue;
			}
			if ( ! file_exists( $src ) && ! is_link( $src ) ) {
				// Moved by a request that stopped before its checkpoint.
				if ( file_exists( $dst ) ) {
					$in['moved'][] = array_merge( $unit, array( file_exists( $old ), false, false ) );
				}
				$in['i']++;
				continue;
			}
			if ( $this->is_protected( $dst ) ) {
				$job->log( sprintf( 'Skipped %s from the archive: it would replace a folder this site needs (%s).', $role . '/' . $name, $dst ), 'warning' );
				$in['i']++;
				continue;
			}
			$replaced = file_exists( $old ) || is_link( $old );
			if ( file_exists( $dst ) || is_link( $dst ) ) {
				wp_mkdir_p( dirname( $old ) );
				if ( ! $replaced && @rename( $dst, $old ) ) { // phpcs:ignore
					$replaced = true;
				} else {
					// The existing one cannot be moved aside: copy over it.
					$job->log( sprintf( 'Could not move %s aside; copying the imported files over it (a cancel cannot undo this one).', $dst ), 'warning' );
					$in['copy'] = self::copy_state( $src, $dst, $replaced, true );
					continue;
				}
			}
			if ( ! is_dir( dirname( $dst ) ) ) {
				wp_mkdir_p( dirname( $dst ) );
			}
			if ( @rename( $src, $dst ) ) { // phpcs:ignore
				$in['moved'][] = array_merge( $unit, array( $replaced, false, false ) );
				$in['i']++;
				$job->save();
				continue;
			}
			// Different disk: copy in chunks.
			$in['copy'] = self::copy_state( $src, $dst, $replaced, false );
		}

		$n             = max( 1, count( $in['units'] ) );
		$s['progress'] = max( (int) $s['progress'], 95 + (int) ( 4 * $in['i'] / $n ) );
		/* translators: 1: number of items moved, 2: number of items. */
		$s['message']  = sprintf( __( 'Moving the files into place: %1$d of %2$d', 'hole-in-one-migration' ), $in['i'], count( $in['units'] ) );
		if ( $in['i'] >= count( $in['units'] ) ) {
			HOLEMI_Storage::rrmdir( $job->path( 'files' ) );
			$job->log( 'Files are in place.' );
			if ( 'files' === $s['manifest']['contents'] ) {
				$s['phase'] = 'finish_files';
			} else {
				$s['phase']   = 'swap';
				$s['message'] = __( 'Switching to the new database…', 'hole-in-one-migration' );
			}
		}
	}

	/**
	 * State of a resumable copy.
	 *
	 * @param string $src      Source (file or folder).
	 * @param string $dst      Destination.
	 * @param bool   $replaced The old unit was moved aside.
	 * @param bool   $merged   Copying over an existing unit.
	 * @return array
	 */
	private static function copy_state( $src, $dst, $replaced, $merged ) {
		return array(
			'src'      => base64_encode( $src ), // phpcs:ignore
			'dst'      => base64_encode( $dst ), // phpcs:ignore
			'stack'    => is_dir( $src ) ? array( base64_encode( '' ) ) : array(), // phpcs:ignore
			'dir'      => '',
			'files'    => is_dir( $src ) ? array() : array( base64_encode( '' ) ), // phpcs:ignore
			'cur'      => null,
			'replaced' => $replaced,
			'merged'   => $merged,
		);
	}

	/**
	 * Copy a unit until it is done (true) or time is up (false). Works like
	 * every other step: a file being copied is truncated back to its saved
	 * offset when the copy continues.
	 *
	 * @param array $c Copy state (by reference).
	 * @return bool
	 */
	private function copy_step( array &$c ) {
		$job = $this->job;
		$src = base64_decode( $c['src'] ); // phpcs:ignore
		$dst = base64_decode( $c['dst'] ); // phpcs:ignore
		while ( ! $job->should_stop() ) {
			if ( $c['cur'] ) {
				$rel = base64_decode( $c['cur']['rel'] ); // phpcs:ignore
				if ( ! $this->copy_chunks( $src . $rel, $dst . $rel, $c['cur']['off'] ) ) {
					return false;
				}
				$c['cur'] = null;
				continue;
			}
			if ( $c['files'] ) {
				$rel = $c['dir'] . base64_decode( array_shift( $c['files'] ) ); // phpcs:ignore
				$p   = $src . $rel;
				if ( is_dir( $p ) && ! is_link( $p ) ) {
					$c['stack'][] = base64_encode( $rel ); // phpcs:ignore
				} elseif ( is_file( $p ) ) {
					$c['cur'] = array(
						'rel' => base64_encode( $rel ), // phpcs:ignore
						'off' => 0,
					);
				}
				continue;
			}
			if ( ! $c['stack'] ) {
				return true;
			}
			$dir = base64_decode( array_pop( $c['stack'] ) ); // phpcs:ignore
			wp_mkdir_p( $dst . $dir );
			$c['dir']   = $dir . '/';
			$c['files'] = array();
			foreach ( (array) @scandir( $src . $dir ) as $n ) { // phpcs:ignore
				if ( is_string( $n ) && '.' !== $n && '..' !== $n ) {
					$c['files'][] = base64_encode( $n ); // phpcs:ignore
				}
			}
		}
		return false;
	}

	/**
	 * Copy one file from $off on, until done (true) or time is up (false).
	 *
	 * @param string $from Source.
	 * @param string $to   Destination.
	 * @param int    $off  Bytes already copied (by reference).
	 * @return bool
	 */
	private function copy_chunks( $from, $to, &$off ) {
		$in  = @fopen( $from, 'rb' ); // phpcs:ignore
		$out = @fopen( $to, 'c+b' ); // phpcs:ignore
		if ( ! $in || ! $out ) {
			$this->job->log( 'Could not copy ' . $to . ' (check permissions).', 'warning' );
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				fclose( $out );
			}
			return true;
		}
		ftruncate( $out, $off );
		fseek( $out, $off );
		fseek( $in, $off );
		$chunk = $this->job->scaled( 2097152, 65536 );
		$done  = false;
		while ( ! $this->job->should_stop() ) {
			$buf = fread( $in, $chunk );
			if ( false === $buf || '' === $buf ) {
				$done = true;
				break;
			}
			HOLEMI_Util::fwrite_all( $out, $buf );
			$off += strlen( $buf );
		}
		fclose( $in );
		fclose( $out );
		if ( $done ) {
			@touch( $to, (int) @filemtime( $from ) ); // phpcs:ignore
		}
		return $done;
	}

	/**
	 * Undo install_files() for a cancelled import: take the imported units out
	 * and move the replaced ones back. Only before the database swap.
	 */
	private function rollback_files() {
		$job = $this->job;
		if ( empty( $job->state['inst']['moved'] ) ) {
			return;
		}
		foreach ( array_reverse( $job->state['inst']['moved'] ) as $m ) {
			$role = $m[0];
			$name = base64_decode( $m[1] ); // phpcs:ignore
			$dst  = $this->target_dir( $role ) . '/' . $name;
			$old  = $job->path( 'replaced/' . $role . '/' . $name );
			if ( ! empty( $m[4] ) ) {
				$job->log( 'Cannot undo ' . $dst . ': the imported files were copied over it.', 'warning' );
				continue;
			}
			if ( file_exists( $dst ) || is_link( $dst ) ) {
				$trash = $job->path( 'trash/' . $role . '/' . $name );
				wp_mkdir_p( dirname( $trash ) );
				if ( ! @rename( $dst, $trash ) ) { // phpcs:ignore
					$job->log( 'Could not remove the imported ' . $dst . '.', 'warning' );
					continue;
				}
			}
			if ( ! empty( $m[2] ) && ( file_exists( $old ) || is_link( $old ) ) && ! @rename( $old, $dst ) ) { // phpcs:ignore
				$job->log( 'Could not restore ' . $dst . '.', 'warning' );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Cancel.
	 * ------------------------------------------------------------------ */

	/**
	 * Cancel an import: put back replaced files, drop temporary tables and
	 * delete the job folder. After the database swap the import is complete
	 * and nothing is undone.
	 *
	 * @param HOLEMI_Job $job Job.
	 */
	public static function cancel( HOLEMI_Job $job ) {
		if ( 'done' !== $job->state['phase'] && ! self::is_swapped( $job ) ) {
			$imp      = new self();
			$imp->job = $job;
			$imp->rollback_files();
			if ( ! empty( $job->state['dbi']['tmp'] ) ) {
				$tmp = $job->state['dbi']['tmp'];
				try {
					HOLEMI_DB::exec( 'SET SESSION foreign_key_checks = %d', array( 0 ) );
					foreach ( array_chunk( HOLEMI_DB::tables_with_prefix( $tmp ), 50 ) as $chunk ) {
						HOLEMI_DB::exec( 'DROP TABLE IF EXISTS ' . HOLEMI_DB::idents( count( $chunk ), ', ' ), $chunk );
					}
				} catch ( HOLEMI_Exception $e ) {
					unset( $e );
				}
			}
		}
		$job->destroy();
	}
}
