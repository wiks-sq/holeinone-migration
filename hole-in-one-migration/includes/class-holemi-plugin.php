<?php
/**
 * Admin UI, AJAX endpoints and downloads.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams multi-gigabyte archives and SQL dumps in small chunks across requests (fopen/fread/fwrite/fseek/ftruncate); WP_Filesystem has no streaming API and would load whole files into memory.

/**
 * Main plugin class.
 */
class HOLEMI_Plugin {

	const SLUG  = 'hole-in-one-migration';
	const NONCE = 'holemi_ajax';

	/**
	 * Instance.
	 *
	 * @var HOLEMI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Job id of the running step (for the fatal error handler).
	 *
	 * @var string|null
	 */
	private $current_job = null;

	/**
	 * Output buffer level at the start of an AJAX request.
	 *
	 * @var int
	 */
	private $ob_level = 0;

	/**
	 * Singleton.
	 *
	 * @return HOLEMI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HOLEMI_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );

		foreach ( array( 'export_start', 'import_start', 'upload_chunk', 'step', 'cancel', 'delete_backup' ) as $a ) {
			add_action( 'wp_ajax_holemi_' . $a, array( $this, 'ajax_' . $a ) );
		}
		// Status check that works without a login session: after the database
		// swap the old session is gone, but the browser must still learn that
		// the import finished. The 24-hex-digit job id acts as the secret.
		add_action( 'wp_ajax_holemi_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_holemi_status', array( $this, 'ajax_status' ) );
		add_action( 'admin_post_holemi_download', array( $this, 'download' ) );
		add_action( 'admin_post_holemi_dismiss', array( $this, 'dismiss' ) );
		add_action( 'admin_post_holemi_log', array( $this, 'download_log' ) );
		register_activation_hook( HOLEMI_FILE, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Activation: create storage folders.
	 */
	public static function activate() {
		HOLEMI_Storage::ensure();
	}

	/**
	 * Admin menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'Hole-in-One Migration', 'hole-in-one-migration' ),
			__( 'Migration', 'hole-in-one-migration' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-migrate',
			80
		);
	}

	/**
	 * Settings + post-import tasks.
	 */
	public function admin_init() {
		register_setting(
			'holemi_settings_group',
			HOLEMI_Util::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'HOLEMI_Util', 'sanitize_settings' ),
				'default'           => HOLEMI_Util::defaults(),
			)
		);

		// First admin page load after an import: rebuild permalinks (.htaccess).
		$post = get_option( 'holemi_post_import' );
		if ( is_array( $post ) && ! empty( $post['flush'] ) && current_user_can( 'manage_options' ) ) {
			$post['flush'] = 0;
			update_option( 'holemi_post_import', $post );
			flush_rewrite_rules( true );
		}
	}

	/**
	 * Links in the plugin list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Migrate', 'hole-in-one-migration' ) . '</a>' );
		return $links;
	}

	/**
	 * Donation link in the plugin row.
	 *
	 * @param array  $meta Meta links.
	 * @param string $file Plugin file.
	 * @return array
	 */
	public function row_meta( $meta, $file ) {
		if ( plugin_basename( HOLEMI_FILE ) === $file && self::coffee_url() ) {
			$meta[] = '<a href="' . esc_url( self::coffee_url() ) . '" target="_blank" rel="noopener noreferrer">☕ ' . esc_html__( 'Buy me a coffee', 'hole-in-one-migration' ) . '</a>';
		}
		return $meta;
	}

	/**
	 * Buy Me a Coffee URL (filterable).
	 *
	 * @return string
	 */
	public static function coffee_url() {
		return (string) apply_filters( 'holemi_buymeacoffee_url', HOLEMI_BUYMEACOFFEE_URL );
	}

	/**
	 * Scripts and styles on our page only.
	 *
	 * @param string $hook Page hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'holemi-admin', HOLEMI_URL . 'assets/admin.css', array(), HOLEMI_VERSION );
		wp_enqueue_script( 'holemi-admin', HOLEMI_URL . 'assets/admin.js', array(), HOLEMI_VERSION, true );

		$limit    = (int) wp_max_upload_size();
		$chunk    = (int) max( 262144, min( 5242880, ( $limit > 0 ? $limit : 2097152 ) - 131072 ) );
		$settings = HOLEMI_Util::settings();
		$resume   = array();
		foreach ( HOLEMI_Job::unfinished() as $job ) {
			$resume[] = $this->payload( $job, true );
		}
		wp_localize_script(
			'holemi-admin',
			'HOLEMI',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'chunk'   => $chunk,
				'pause'   => (int) $settings['pause_ms'],
				'jobs'    => $resume,
				'i18n'    => array(
					'confirmImport' => __( 'This will REPLACE the content, settings, users, plugins and themes of THIS site with the uploaded backup. Continue?', 'hole-in-one-migration' ),
					'confirmCancel' => __( 'Cancel this job and delete its temporary files?', 'hole-in-one-migration' ),
					'confirmDelete' => __( 'Delete this backup file permanently?', 'hole-in-one-migration' ),
					/* translators: 1: seconds until the next attempt, 2: attempt number. */
					'retrying'      => __( 'Connection problem or timeout – retrying in %1$s seconds (attempt %2$d)…', 'hole-in-one-migration' ),
					'busy'          => __( 'The server is still working on the previous request – waiting…', 'hole-in-one-migration' ),
					'leave'         => __( 'A migration is running. If you leave, you can resume it later from this page.', 'hole-in-one-migration' ),
					'chooseFile'    => __( 'Please choose a backup file first.', 'hole-in-one-migration' ),
					/* translators: %s: file name. */
					'reselect'      => __( 'To resume this upload, choose the same file again (%s) in the import box, then click "Resume upload".', 'hole-in-one-migration' ),
					/* translators: %s: file name and size. */
					'wrongFile'     => __( 'That is not the same file that was being uploaded. Please choose %s.', 'hole-in-one-migration' ),
					'gaveUp'        => __( 'The server did not respond after many attempts. Your progress is saved: click "Retry" to continue, or check the troubleshooting tips in the Help tab.', 'hole-in-one-migration' ),
					'expired'       => __( 'Your login session expired. Reload this page and click "Resume" – your progress is saved.', 'hole-in-one-migration' ),
				),
			)
		);
	}

	/* --------------------------------------------------------------------
	 * AJAX.
	 * ------------------------------------------------------------------ */

	/**
	 * Common checks for every AJAX request.
	 */
	private function begin_ajax() {
		$this->ob_level = ob_get_level();
		ob_start();
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->fail( __( 'You are not allowed to do this.', 'hole-in-one-migration' ), 'auth' );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			$this->fail( __( 'Your login session expired. Reload the page.', 'hole-in-one-migration' ), 'auth' );
		}
	}

	/**
	 * Send success JSON (discarding any stray output from other code).
	 *
	 * @param array $data Data.
	 */
	private function ok( array $data ) {
		while ( ob_get_level() > $this->ob_level ) {
			ob_end_clean();
		}
		wp_send_json_success( $data );
	}

	/**
	 * Send error JSON.
	 *
	 * @param string $msg  Message.
	 * @param string $code Code.
	 */
	private function fail( $msg, $code = 'error' ) {
		while ( ob_get_level() > $this->ob_level ) {
			ob_end_clean();
		}
		wp_send_json_error(
			array(
				'message' => $msg,
				'code'    => $code,
			)
		);
	}

	/**
	 * POST value.
	 *
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	private static function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in begin_ajax(); ajax_status() has none on purpose (see there).
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/**
	 * Job data for the browser.
	 *
	 * @param HOLEMI_Job $job      Job.
	 * @param bool    $with_log Include the log tail.
	 * @return array
	 */
	private function payload( HOLEMI_Job $job, $with_log = false ) {
		$s   = $job->state;
		$out = array(
			'id'       => $job->id,
			'kind'     => $s['kind'],
			'phase'    => $s['phase'],
			'status'   => $s['status'],
			'progress' => (int) $s['progress'],
			'message'  => (string) $s['message'],
			'error'    => (string) $s['error'],
			'warnings' => (int) $s['warnings'],
			'log'      => $with_log ? $job->tail( 100 ) : $job->new_log,
			'created'  => (int) $s['created'],
			'logUrl'   => add_query_arg(
				array(
					'action'   => 'holemi_log',
					'job'      => $job->id,
					'_wpnonce' => wp_create_nonce( 'holemi_log' ),
				),
				admin_url( 'admin-post.php' )
			),
		);
		if ( 'upload' === $s['phase'] && isset( $s['upload'] ) ) {
			$out['upload'] = $s['upload'];
		}
		if ( 'exp' === $s['kind'] && ! empty( $s['result']['file'] ) ) {
			$file          = $s['result']['file'];
			$out['result'] = array(
				'file'   => $file,
				'size'   => HOLEMI_Util::size( $s['result']['size'] ),
				'php'    => self::php_download_url( $file ),
			);
		}
		if ( 'imp' === $s['kind'] && ! empty( $s['result'] ) ) {
			$out['result'] = $s['result'];
		}
		return $out;
	}

	/**
	 * Download link that streams through PHP (with resume support).
	 *
	 * @param string $file File name.
	 * @return string
	 */
	public static function php_download_url( $file ) {
		// Raw URL (not HTML-escaped like wp_nonce_url): it is also sent as JSON.
		return add_query_arg(
			array(
				'action'   => 'holemi_download',
				'file'     => rawurlencode( $file ),
				'_wpnonce' => wp_create_nonce( 'holemi_download' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Start an export.
	 */
	public function ajax_export_start() {
		$this->begin_ajax();
		try {
			$job = HOLEMI_Exporter::start(
				array(
					'contents'        => self::post( 'contents', 'both' ),
					'format'          => self::post( 'format', 'tar.gz' ),
					'exclude_uploads' => '1' === self::post( 'exclude_uploads' ),
				)
			);
			$this->ok( $this->payload( $job ) );
		} catch ( Throwable $e ) {
			$this->fail( HOLEMI_Util::plain( $e ) );
		}
	}

	/**
	 * Start an import.
	 */
	public function ajax_import_start() {
		$this->begin_ajax();
		try {
			foreach ( HOLEMI_Job::unfinished() as $other ) {
				if ( 'imp' === $other->state['kind'] ) {
					throw new HOLEMI_Exception( esc_html__( 'Another import is unfinished. Resume or cancel it first (see the top of this page).', 'hole-in-one-migration' ) );
				}
			}
			if ( 'server' === self::post( 'source' ) ) {
				$job = HOLEMI_Importer::start_server_file( self::post( 'file' ) );
			} else {
				$job = HOLEMI_Importer::start_upload( self::post( 'name' ), (int) self::post( 'size' ) );
			}
			$this->ok( $this->payload( $job ) );
		} catch ( Throwable $e ) {
			$this->fail( HOLEMI_Util::plain( $e ) );
		}
	}

	/**
	 * Receive an upload chunk.
	 */
	public function ajax_upload_chunk() {
		$this->begin_ajax();
		$job = HOLEMI_Job::load( self::post( 'job' ) );
		if ( ! $job ) {
			$this->fail( __( 'Job not found.', 'hole-in-one-migration' ), 'gone' );
		}
		if ( ! $job->lock() ) {
			$this->ok( array( 'busy' => true ) );
		}
		HOLEMI_Util::prepare_runtime( 60 );
		try {
			// The nonce was verified above. Only the temporary path and the error
			// code are used; the file is never moved to a name the client chose.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$tmp = isset( $_FILES['chunk']['tmp_name'] ) && is_string( $_FILES['chunk']['tmp_name'] ) ? sanitize_text_field( $_FILES['chunk']['tmp_name'] ) : '';
			$err = isset( $_FILES['chunk']['error'] ) ? absint( $_FILES['chunk']['error'] ) : -1;
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( '' === $tmp || 0 !== $err || ! is_uploaded_file( $tmp ) ) {
				/* translators: %d: PHP upload error code. */
				throw new HOLEMI_Exception( esc_html( sprintf( __( 'The chunk was not received (upload error %d).', 'hole-in-one-migration' ), $err ) ) );
			}
			$have = HOLEMI_Importer::upload_chunk( $job, (int) self::post( 'offset' ), $tmp );
			$job->unlock();
			$this->ok(
				array(
					'received' => $have,
					'phase'    => $job->state['phase'],
					'progress' => (int) $job->state['progress'],
					'message'  => $job->state['message'],
				)
			);
		} catch ( Throwable $e ) {
			$job->unlock();
			$this->fail( HOLEMI_Util::plain( $e ), 'chunk' );
		}
	}

	/**
	 * Do one unit of work.
	 */
	public function ajax_step() {
		$this->begin_ajax();
		$job = HOLEMI_Job::load( self::post( 'job' ) );
		if ( ! $job ) {
			$this->fail( __( 'Job not found. It may have been cancelled.', 'hole-in-one-migration' ), 'gone' );
		}
		if ( 'done' === $job->state['phase'] ) {
			$this->ok( $this->payload( $job ) );
		}
		if ( ! $job->lock() ) {
			$this->ok(
				array(
					'id'     => $job->id,
					'status' => 'busy',
				)
			);
		}
		if ( 'error' === $job->state['status'] ) {
			if ( '1' !== self::post( 'resume' ) ) {
				$job->unlock();
				$this->ok( $this->payload( $job ) );
			}
			$job->state['status'] = 'running';
			$job->state['error']  = '';
			$job->log( 'Retrying after error.' );
		}

		$this->current_job = $job->id;
		register_shutdown_function( array( $this, 'on_shutdown' ) );

		$job->begin_step( '1' === self::post( 'retry' ) );
		try {
			if ( 'exp' === $job->state['kind'] ) {
				HOLEMI_Exporter::step( $job );
			} else {
				HOLEMI_Importer::step( $job );
			}
			$job->save();
		} catch ( Throwable $e ) {
			// Keep the last good checkpoint; only record the error.
			$log   = $job->new_log;
			$fresh = HOLEMI_Job::load( $job->id );
			if ( $fresh ) {
				$job->state = $fresh->state;
			}
			$job->new_log         = $log;
			$job->state['status'] = 'error';
			$job->state['error']  = HOLEMI_Util::plain( $e );
			$job->log( HOLEMI_Util::plain( $e ), 'error' );
			try {
				$job->save();
			} catch ( Throwable $e2 ) {
				unset( $e2 );
			}
		}
		$this->current_job = null;
		$job->unlock();
		$this->ok( $this->payload( $job ) );
	}

	/**
	 * Turn a PHP fatal error (e.g. memory exhausted) into a retryable answer.
	 */
	public function on_shutdown() {
		if ( ! $this->current_job ) {
			return;
		}
		$err = error_get_last();
		if ( ! $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		$job = HOLEMI_Job::load( $this->current_job );
		if ( $job ) {
			$job->log( 'PHP fatal error: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'], 'warning' );
		}
		while ( ob_get_level() > $this->ob_level ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode(
			array(
				'success' => false,
				'data'    => array(
					'code'    => 'retry',
					'message' => 'PHP fatal error: ' . $err['message'],
				),
			)
		);
	}

	/**
	 * Minimal job status (no nonce: see constructor).
	 */
	public function ajax_status() {
		$this->ob_level = ob_get_level();
		ob_start();
		// No nonce on purpose: this endpoint is called right after the database
		// swap, when the user's session and nonces no longer exist. The random job
		// id (96 bits) is the secret instead, and only import jobs are shown to
		// visitors who are not logged in. It returns progress only, never data.
		$id  = sanitize_key( self::post( 'job' ) );
		$job = HOLEMI_Job::load( $id );
		if ( ! $job || ( 'imp' !== $job->state['kind'] && ! current_user_can( 'manage_options' ) ) ) {
			$this->fail( __( 'Job not found.', 'hole-in-one-migration' ), 'gone' );
		}
		// The swap happened but its request died before finishing: finish now.
		if ( 'imp' === $job->state['kind'] && 'swap' === $job->state['phase'] && $job->lock() ) {
			try {
				HOLEMI_Importer::finish_if_swapped( $job );
			} catch ( Throwable $e ) {
				unset( $e );
			}
			$job->unlock();
		}
		$s = $job->state;
		$this->ok(
			array(
				'id'       => $job->id,
				'kind'     => $s['kind'],
				'phase'    => $s['phase'],
				'status'   => $s['status'],
				'progress' => (int) $s['progress'],
				'message'  => (string) $s['message'],
				'warnings' => (int) $s['warnings'],
				'log'      => array(),
				'result'   => 'imp' === $s['kind'] && isset( $s['result'] ) ? $s['result'] : null,
			)
		);
	}

	/**
	 * Cancel a job.
	 */
	public function ajax_cancel() {
		$this->begin_ajax();
		$job = HOLEMI_Job::load( self::post( 'job' ) );
		if ( $job ) {
			// Wait for a step that is still running (the browser stops sending
			// new ones as soon as Cancel is clicked).
			$locked = $job->lock();
			for ( $i = 0; ! $locked && $i < 40; $i++ ) {
				usleep( 500000 );
				$locked = $job->lock();
			}
			if ( ! $locked ) {
				$this->fail( __( 'The server is still working on this job. Please try again in a minute.', 'hole-in-one-migration' ) );
			}
			if ( 'imp' === $job->state['kind'] ) {
				HOLEMI_Importer::cancel( $job );
			} else {
				$job->destroy();
			}
		}
		$this->ok( array( 'cancelled' => true ) );
	}

	/**
	 * Delete a finished backup.
	 */
	public function ajax_delete_backup() {
		$this->begin_ajax();
		$name = basename( self::post( 'file' ) );
		foreach ( HOLEMI_Storage::backups() as $b ) {
			if ( $b['name'] === $name ) {
				@unlink( $b['path'] ); // phpcs:ignore
				@unlink( $b['path'] . '.json' ); // phpcs:ignore
				$this->ok( array( 'deleted' => $name ) );
			}
		}
		$this->fail( __( 'Backup not found.', 'hole-in-one-migration' ) );
	}

	/* --------------------------------------------------------------------
	 * Downloads.
	 * ------------------------------------------------------------------ */

	/**
	 * Stream a backup through PHP with HTTP Range support, so interrupted
	 * downloads can be resumed by the browser or a download manager.
	 */
	public function download() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'holemi_download' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hole-in-one-migration' ), 403 );
		}
		$name = isset( $_GET['file'] ) ? basename( sanitize_file_name( wp_unslash( $_GET['file'] ) ) ) : '';
		$path = '';
		foreach ( HOLEMI_Storage::backups() as $b ) {
			if ( $b['name'] === $name ) {
				$path = $b['path'];
			}
		}
		if ( ! $path ) {
			wp_die( esc_html__( 'Backup not found.', 'hole-in-one-migration' ), 404 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		ignore_user_abort( false );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$size  = filesize( $path );
		$start = 0;
		$end   = $size - 1;
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			} elseif ( '' !== $m[2] ) {
				$start = max( 0, $size - (int) $m[2] );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'X-Accel-Buffering: no' );
		$fp = fopen( $path, 'rb' ); // phpcs:ignore
		fseek( $fp, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fp ) && ! connection_aborted() ) {
			$buf = fread( $fp, (int) min( 1048576, $left ) );
			echo $buf; // phpcs:ignore WordPress.Security.EscapeOutput
			flush();
			$left -= strlen( $buf );
		}
		fclose( $fp );
		exit;
	}

	/**
	 * Download the full log of a job.
	 */
	public function download_log() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'holemi_log' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hole-in-one-migration' ), 403 );
		}
		$job = HOLEMI_Job::load( isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '' );
		if ( ! $job ) {
			wp_die( esc_html__( 'Log not found.', 'hole-in-one-migration' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="holemi-' . $job->id . '.log"' );
		readfile( $job->path( 'log.txt' ) ); // phpcs:ignore
		exit;
	}

	/**
	 * Dismiss the post-import notice.
	 */
	public function dismiss() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'holemi_dismiss' ) ) {
			delete_option( 'holemi_post_import' );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/* --------------------------------------------------------------------
	 * Notices and page.
	 * ------------------------------------------------------------------ */

	/**
	 * Admin notices.
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$post = get_option( 'holemi_post_import' );
		if ( is_array( $post ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=holemi_dismiss' ), 'holemi_dismiss' );
			echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Hole-in-One Migration: your site was imported successfully!', 'hole-in-one-migration' ) . '</strong> ';
			/* translators: %s old site address */
			echo esc_html( sprintf( __( 'Content was moved from %s.', 'hole-in-one-migration' ), $post['from'] ) ) . ' ';
			echo esc_html__( 'Please check a few pages. If you see "page not found" errors, go to Settings → Permalinks and click "Save Changes" once.', 'hole-in-one-migration' );
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'hole-in-one-migration' ) . '</a></p></div>';
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_' . self::SLUG !== $screen->id && self::import_unfinished() ) {
			/* translators: %s: link to the import page. */
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'An import with <strong>Hole-in-One Migration</strong> is unfinished. <a href="%s">Resume or cancel it</a>.', 'hole-in-one-migration' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=import' ) ) ) ) . '</p></div>';
		}
	}

	/**
	 * The FTP import folder, relative to the WordPress folder when it is inside it.
	 *
	 * @return string
	 */
	public static function import_folder_label() {
		$dir  = HOLEMI_Storage::dir( 'import' );
		$root = HOLEMI_Util::install_dir();
		return ( 0 === strpos( $dir, $root . '/' ) ? substr( $dir, strlen( $root ) ) : $dir ) . '/';
	}

	/**
	 * Is an import job unfinished?
	 *
	 * @return bool
	 */
	private static function import_unfinished() {
		foreach ( HOLEMI_Job::unfinished() as $job ) {
			if ( 'imp' === $job->state['kind'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove finished job folders older than 3 days.
	 */
	private function cleanup() {
		foreach ( (array) glob( HOLEMI_Storage::dir( 'work' ) . '/*', GLOB_ONLYDIR ) as $d ) {
			$job = HOLEMI_Job::load( basename( $d ) );
			if ( $job && 'done' === $job->state['phase'] && $job->state['updated'] < time() - 3 * DAY_IN_SECONDS ) {
				$job->destroy();
			}
		}
	}

	/**
	 * Render the admin page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		HOLEMI_Storage::ensure();
		$this->cleanup();
		$tabs = array(
			'export'   => __( 'Export', 'hole-in-one-migration' ),
			'import'   => __( 'Import', 'hole-in-one-migration' ),
			'backups'  => __( 'Backups', 'hole-in-one-migration' ),
			'settings' => __( 'Settings', 'hole-in-one-migration' ),
			'help'     => __( 'Help & Instructions', 'hole-in-one-migration' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'export';
		$settings = HOLEMI_Util::settings();
		include HOLEMI_DIR . 'admin/page.php';
	}
}
