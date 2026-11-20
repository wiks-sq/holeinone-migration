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
class HIO_Plugin {

	const SLUG  = 'holeinone-migration';
	const NONCE = 'hio_ajax';

	/**
	 * Instance.
	 *
	 * @var HIO_Plugin|null
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
	 * @return HIO_Plugin
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
		add_filter( 'plugin_action_links_' . plugin_basename( HIO_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );

		foreach ( array( 'export_start', 'import_start', 'upload_chunk', 'step', 'cancel', 'delete_backup' ) as $a ) {
			add_action( 'wp_ajax_hio_' . $a, array( $this, 'ajax_' . $a ) );
		}
		// Status check that works without a login session: after the database
		// swap the old session is gone, but the browser must still learn that
		// the import finished. The 24-hex-digit job id acts as the secret.
		add_action( 'wp_ajax_hio_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_hio_status', array( $this, 'ajax_status' ) );
		add_action( 'admin_post_hio_download', array( $this, 'download' ) );
		add_action( 'admin_post_hio_dismiss', array( $this, 'dismiss' ) );
		add_action( 'admin_post_hio_log', array( $this, 'download_log' ) );
		register_activation_hook( HIO_FILE, array( __CLASS__, 'activate' ) );
	}

	/**
	 * Activation: create storage folders.
	 */
	public static function activate() {
		HIO_Storage::ensure();
	}

	/**
	 * Admin menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'Hole-in-One Migration', 'holeinone-migration' ),
			__( 'Migration', 'holeinone-migration' ),
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
			'hio_settings_group',
			HIO_Util::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'HIO_Util', 'sanitize_settings' ),
				'default'           => HIO_Util::defaults(),
			)
		);

		// First admin page load after an import: rebuild permalinks (.htaccess).
		$post = get_option( 'hio_post_import' );
		if ( is_array( $post ) && ! empty( $post['flush'] ) && current_user_can( 'manage_options' ) ) {
			$post['flush'] = 0;
			update_option( 'hio_post_import', $post );
			require_once ABSPATH . 'wp-admin/includes/misc.php';
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
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Migrate', 'holeinone-migration' ) . '</a>' );
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
		if ( plugin_basename( HIO_FILE ) === $file && self::coffee_url() ) {
			$meta[] = '<a href="' . esc_url( self::coffee_url() ) . '" target="_blank" rel="noopener noreferrer">☕ ' . esc_html__( 'Buy me a coffee', 'holeinone-migration' ) . '</a>';
		}
		return $meta;
	}

	/**
	 * Buy Me a Coffee URL (filterable).
	 *
	 * @return string
	 */
	public static function coffee_url() {
		return (string) apply_filters( 'hio_buymeacoffee_url', HIO_BUYMEACOFFEE_URL );
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
		wp_enqueue_style( 'hio-admin', HIO_URL . 'assets/admin.css', array(), HIO_VERSION );
		wp_enqueue_script( 'hio-admin', HIO_URL . 'assets/admin.js', array(), HIO_VERSION, true );

		$limit    = (int) wp_max_upload_size();
		$chunk    = (int) max( 262144, min( 5242880, ( $limit > 0 ? $limit : 2097152 ) - 131072 ) );
		$settings = HIO_Util::settings();
		$resume   = array();
		foreach ( HIO_Job::unfinished() as $job ) {
			$resume[] = $this->payload( $job, true );
		}
		wp_localize_script(
			'hio-admin',
			'HIO',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'chunk'   => $chunk,
				'pause'   => (int) $settings['pause_ms'],
				'jobs'    => $resume,
				'i18n'    => array(
					'confirmImport' => __( 'This will REPLACE the content, settings, users, plugins and themes of THIS site with the uploaded backup. Continue?', 'holeinone-migration' ),
					'confirmCancel' => __( 'Cancel this job and delete its temporary files?', 'holeinone-migration' ),
					'confirmDelete' => __( 'Delete this backup file permanently?', 'holeinone-migration' ),
					/* translators: 1: seconds until the next attempt, 2: attempt number. */
					'retrying'      => __( 'Connection problem or timeout – retrying in %1$s seconds (attempt %2$d)…', 'holeinone-migration' ),
					'busy'          => __( 'The server is still working on the previous request – waiting…', 'holeinone-migration' ),
					'leave'         => __( 'A migration is running. If you leave, you can resume it later from this page.', 'holeinone-migration' ),
					'chooseFile'    => __( 'Please choose a backup file first.', 'holeinone-migration' ),
					/* translators: %s: file name. */
					'reselect'      => __( 'To resume this upload, choose the same file again (%s) in the import box, then click "Resume upload".', 'holeinone-migration' ),
					/* translators: %s: file name and size. */
					'wrongFile'     => __( 'That is not the same file that was being uploaded. Please choose %s.', 'holeinone-migration' ),
					'gaveUp'        => __( 'The server did not respond after many attempts. Your progress is saved: click "Retry" to continue, or check the troubleshooting tips in the Help tab.', 'holeinone-migration' ),
					'expired'       => __( 'Your login session expired. Reload this page and click "Resume" – your progress is saved.', 'holeinone-migration' ),
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
			$this->fail( __( 'You are not allowed to do this.', 'holeinone-migration' ), 'auth' );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			$this->fail( __( 'Your login session expired. Reload the page.', 'holeinone-migration' ), 'auth' );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in begin_ajax().
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
	}

	/**
	 * Job data for the browser.
	 *
	 * @param HIO_Job $job      Job.
	 * @param bool    $with_log Include the log tail.
	 * @return array
	 */
	private function payload( HIO_Job $job, $with_log = false ) {
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
					'action'   => 'hio_log',
					'job'      => $job->id,
					'_wpnonce' => wp_create_nonce( 'hio_log' ),
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
				'size'   => HIO_Util::size( $s['result']['size'] ),
				'direct' => HIO_Storage::backup_url( $file ),
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
				'action'   => 'hio_download',
				'file'     => rawurlencode( $file ),
				'_wpnonce' => wp_create_nonce( 'hio_download' ),
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
			$job = HIO_Exporter::start(
				array(
					'contents'        => self::post( 'contents', 'both' ),
					'format'          => self::post( 'format', 'tar.gz' ),
					'exclude_uploads' => '1' === self::post( 'exclude_uploads' ),
				)
			);
			$this->ok( $this->payload( $job ) );
		} catch ( Throwable $e ) {
			$this->fail( HIO_Util::plain( $e ) );
		}
	}

	/**
	 * Start an import.
	 */
	public function ajax_import_start() {
		$this->begin_ajax();
		try {
			foreach ( HIO_Job::unfinished() as $other ) {
				if ( 'imp' === $other->state['kind'] ) {
					throw new HIO_Exception( esc_html__( 'Another import is unfinished. Resume or cancel it first (see the top of this page).', 'holeinone-migration' ) );
				}
			}
			if ( 'server' === self::post( 'source' ) ) {
				$job = HIO_Importer::start_server_file( self::post( 'file' ) );
			} else {
				$job = HIO_Importer::start_upload( self::post( 'name' ), (int) self::post( 'size' ) );
			}
			$this->ok( $this->payload( $job ) );
		} catch ( Throwable $e ) {
			$this->fail( HIO_Util::plain( $e ) );
		}
	}

	/**
	 * Receive an upload chunk.
	 */
	public function ajax_upload_chunk() {
		$this->begin_ajax();
		$job = HIO_Job::load( self::post( 'job' ) );
		if ( ! $job ) {
			$this->fail( __( 'Job not found.', 'holeinone-migration' ), 'gone' );
		}
		if ( ! $job->lock() ) {
			$this->ok( array( 'busy' => true ) );
		}
		HIO_Util::prepare_runtime( 60 );
		try {
			// The nonce was verified above. Only the temporary path and the error
			// code are used; the file is never moved to a name the client chose.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$tmp = isset( $_FILES['chunk']['tmp_name'] ) && is_string( $_FILES['chunk']['tmp_name'] ) ? sanitize_text_field( $_FILES['chunk']['tmp_name'] ) : '';
			$err = isset( $_FILES['chunk']['error'] ) ? absint( $_FILES['chunk']['error'] ) : -1;
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( '' === $tmp || 0 !== $err || ! is_uploaded_file( $tmp ) ) {
				/* translators: %d: PHP upload error code. */
				throw new HIO_Exception( esc_html( sprintf( __( 'The chunk was not received (upload error %d).', 'holeinone-migration' ), $err ) ) );
			}
			$have = HIO_Importer::upload_chunk( $job, (int) self::post( 'offset' ), $tmp );
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
			$this->fail( HIO_Util::plain( $e ), 'chunk' );
		}
	}

	/**
	 * Do one unit of work.
	 */
	public function ajax_step() {
		$this->begin_ajax();
		$job = HIO_Job::load( self::post( 'job' ) );
		if ( ! $job ) {
			$this->fail( __( 'Job not found. It may have been cancelled.', 'holeinone-migration' ), 'gone' );
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
				HIO_Exporter::step( $job );
			} else {
				HIO_Importer::step( $job );
			}
			$job->save();
		} catch ( Throwable $e ) {
			// Keep the last good checkpoint; only record the error.
			$log   = $job->new_log;
			$fresh = HIO_Job::load( $job->id );
			if ( $fresh ) {
				$job->state = $fresh->state;
			}
			$job->new_log         = $log;
			$job->state['status'] = 'error';
			$job->state['error']  = HIO_Util::plain( $e );
			$job->log( HIO_Util::plain( $e ), 'error' );
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
		$job = HIO_Job::load( $this->current_job );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- intentional, see above.
		$id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$job = HIO_Job::load( $id );
		if ( ! $job || ( 'imp' !== $job->state['kind'] && ! current_user_can( 'manage_options' ) ) ) {
			$this->fail( __( 'Job not found.', 'holeinone-migration' ), 'gone' );
		}
		// The swap happened but its request died before finishing: finish now.
		if ( 'imp' === $job->state['kind'] && 'swap' === $job->state['phase'] && $job->lock() ) {
			try {
				HIO_Importer::finish_if_swapped( $job );
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
		$job = HIO_Job::load( self::post( 'job' ) );
		if ( $job ) {
			if ( 'imp' === $job->state['kind'] ) {
				HIO_Importer::cancel( $job );
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
		foreach ( HIO_Storage::backups() as $b ) {
			if ( $b['name'] === $name ) {
				@unlink( $b['path'] ); // phpcs:ignore
				@unlink( $b['path'] . '.json' ); // phpcs:ignore
				$this->ok( array( 'deleted' => $name ) );
			}
		}
		$this->fail( __( 'Backup not found.', 'holeinone-migration' ) );
	}

	/* --------------------------------------------------------------------
	 * Downloads.
	 * ------------------------------------------------------------------ */

	/**
	 * Stream a backup through PHP with HTTP Range support, so interrupted
	 * downloads can be resumed by the browser or a download manager.
	 */
	public function download() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hio_download' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'holeinone-migration' ), 403 );
		}
		$name = isset( $_GET['file'] ) ? basename( sanitize_file_name( wp_unslash( $_GET['file'] ) ) ) : '';
		$path = '';
		foreach ( HIO_Storage::backups() as $b ) {
			if ( $b['name'] === $name ) {
				$path = $b['path'];
			}
		}
		if ( ! $path ) {
			wp_die( esc_html__( 'Backup not found.', 'holeinone-migration' ), 404 );
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
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hio_log' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'holeinone-migration' ), 403 );
		}
		$job = HIO_Job::load( isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '' );
		if ( ! $job ) {
			wp_die( esc_html__( 'Log not found.', 'holeinone-migration' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="hio-' . $job->id . '.log"' );
		readfile( $job->path( 'log.txt' ) ); // phpcs:ignore
		exit;
	}

	/**
	 * Dismiss the post-import notice.
	 */
	public function dismiss() {
		if ( current_user_can( 'manage_options' ) && check_admin_referer( 'hio_dismiss' ) ) {
			delete_option( 'hio_post_import' );
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
		$post = get_option( 'hio_post_import' );
		if ( is_array( $post ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=hio_dismiss' ), 'hio_dismiss' );
			echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'Hole-in-One Migration: your site was imported successfully!', 'holeinone-migration' ) . '</strong> ';
			/* translators: %s old site address */
			echo esc_html( sprintf( __( 'Content was moved from %s.', 'holeinone-migration' ), $post['from'] ) ) . ' ';
			echo esc_html__( 'Please check a few pages. If you see "page not found" errors, go to Settings → Permalinks and click "Save Changes" once.', 'holeinone-migration' );
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', 'holeinone-migration' ) . '</a></p></div>';
		}
		$guard = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/' . HIO_Importer::GUARD_FILE;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( is_file( $guard ) && $screen && 'toplevel_page_' . self::SLUG !== $screen->id ) {
			/* translators: %s: link to the import page. */
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'An import with <strong>Hole-in-One Migration</strong> is unfinished. <a href="%s">Resume or cancel it</a>.', 'holeinone-migration' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=import' ) ) ) ) . '</p></div>';
		}
	}

	/**
	 * Remove finished job folders older than 3 days.
	 */
	private function cleanup() {
		foreach ( (array) glob( HIO_Storage::dir( 'work' ) . '/*', GLOB_ONLYDIR ) as $d ) {
			$job = HIO_Job::load( basename( $d ) );
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
		HIO_Storage::ensure();
		$this->cleanup();
		$tabs = array(
			'export'   => __( 'Export', 'holeinone-migration' ),
			'import'   => __( 'Import', 'holeinone-migration' ),
			'backups'  => __( 'Backups', 'holeinone-migration' ),
			'settings' => __( 'Settings', 'holeinone-migration' ),
			'help'     => __( 'Help & Instructions', 'holeinone-migration' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'export';
		$settings = HIO_Util::settings();
		include HIO_DIR . 'admin/page.php';
	}
}
