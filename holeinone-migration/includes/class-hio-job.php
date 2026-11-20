<?php
/**
 * A resumable job. All progress lives in a JSON file inside the job folder
 * (never in the database, because an import replaces the database).
 *
 * Every step works for a limited time, then writes a checkpoint atomically.
 * If a request dies (timeout, crash, lost connection), the next request starts
 * again from the last checkpoint, so no work is lost or done twice.
 *
 * @package HoleInOneMigration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Job state container.
 */
class HIO_Job {

	const ID_RE = '/^(exp|imp)_[a-f0-9]{24}$/';

	/**
	 * Job id.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Job folder.
	 *
	 * @var string
	 */
	public $dir;

	/**
	 * Persistent state.
	 *
	 * @var array
	 */
	public $state = array();

	/**
	 * Log lines produced during the current request.
	 *
	 * @var string[]
	 */
	public $new_log = array();

	/**
	 * Step deadline (microtime).
	 *
	 * @var float
	 */
	private $deadline = 0;

	/**
	 * Memory ceiling for a step in bytes.
	 *
	 * @var int
	 */
	private $mem_ceiling = 0;

	/**
	 * Lock handle.
	 *
	 * @var resource|null
	 */
	private $lock_fp;

	/**
	 * Create a job.
	 *
	 * @param string $kind exp|imp.
	 * @return HIO_Job
	 * @throws HIO_Exception If the storage folder is not writable.
	 */
	public static function create( $kind ) {
		if ( ! HIO_Storage::ensure() ) {
			throw new HIO_Exception( sprintf( __( 'The folder %s is not writable. Please fix the folder permissions (755) and try again.', 'holeinone-migration' ), HIO_Storage::base() ) );
		}
		$job     = new self();
		$job->id = $kind . '_' . bin2hex( random_bytes( 12 ) );
		$job->dir = HIO_Storage::dir( 'work' ) . '/' . $job->id;
		if ( ! wp_mkdir_p( $job->dir ) ) {
			throw new HIO_Exception( __( 'Could not create the job folder.', 'holeinone-migration' ) );
		}
		@file_put_contents( $job->dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		$settings   = HIO_Util::settings();
		$job->state = array(
			'id'       => $job->id,
			'kind'     => $kind,
			'phase'    => 'new',
			'status'   => 'running',
			'created'  => time(),
			'updated'  => time(),
			'budget'   => (float) $settings['time_budget'],
			'budget_max' => (float) $settings['time_budget'],
			'factor'   => 1.0,
			'ok_streak' => 0,
			'retries'  => 0,
			'progress' => 0,
			'message'  => '',
			'warnings' => 0,
			'error'    => '',
		);
		$job->save();
		return $job;
	}

	/**
	 * Load a job by id.
	 *
	 * @param string $id Job id.
	 * @return HIO_Job|null
	 */
	public static function load( $id ) {
		if ( ! is_string( $id ) || ! preg_match( self::ID_RE, $id ) ) {
			return null;
		}
		$dir  = HIO_Storage::dir( 'work' ) . '/' . $id;
		$file = $dir . '/state.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$state = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
		if ( ! is_array( $state ) ) {
			// A torn write is impossible (atomic rename) but keep a fallback.
			$state = json_decode( (string) @file_get_contents( $file . '.bak' ), true ); // phpcs:ignore
			if ( ! is_array( $state ) ) {
				return null;
			}
		}
		$job        = new self();
		$job->id    = $id;
		$job->dir   = $dir;
		$job->state = $state;
		return $job;
	}

	/**
	 * All unfinished jobs.
	 *
	 * @return HIO_Job[]
	 */
	public static function unfinished() {
		$out = array();
		foreach ( (array) glob( HIO_Storage::dir( 'work' ) . '/*', GLOB_ONLYDIR ) as $d ) {
			$job = self::load( basename( $d ) );
			if ( $job && 'done' !== $job->state['phase'] ) {
				$out[] = $job;
			}
		}
		return $out;
	}

	/**
	 * Absolute path of a file in the job folder.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public function path( $name ) {
		return $this->dir . '/' . $name;
	}

	/**
	 * Atomically write the checkpoint.
	 *
	 * @throws HIO_Exception If writing fails.
	 */
	public function save() {
		$this->state['updated'] = time();
		$json = wp_json_encode( $this->state, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json ) {
			throw new HIO_Exception( 'Could not encode job state.' );
		}
		$file = $this->path( 'state.json' );
		$tmp  = $file . '.tmp';
		if ( false === @file_put_contents( $tmp, $json ) ) { // phpcs:ignore
			throw new HIO_Exception( __( 'Could not save progress. Is the disk full?', 'holeinone-migration' ) );
		}
		if ( is_file( $file ) ) {
			@copy( $file, $file . '.bak' ); // phpcs:ignore
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore
			// Windows cannot rename over an existing file.
			@unlink( $file ); // phpcs:ignore
			if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore
				throw new HIO_Exception( __( 'Could not save progress.', 'holeinone-migration' ) );
			}
		}
	}

	/**
	 * Get an exclusive lock so two requests never work on the same job.
	 *
	 * @return bool
	 */
	public function lock() {
		$this->lock_fp = @fopen( $this->path( 'lock' ), 'c' ); // phpcs:ignore
		if ( ! $this->lock_fp ) {
			return true; // Cannot lock on this filesystem; carry on.
		}
		return flock( $this->lock_fp, LOCK_EX | LOCK_NB );
	}

	/**
	 * Release the lock.
	 */
	public function unlock() {
		if ( $this->lock_fp ) {
			flock( $this->lock_fp, LOCK_UN );
			fclose( $this->lock_fp );
			$this->lock_fp = null;
		}
	}

	/**
	 * Start the clock for this request.
	 *
	 * @param bool $is_retry The previous request failed.
	 */
	public function begin_step( $is_retry ) {
		if ( $is_retry ) {
			// Previous request failed (most likely a timeout): do less per request.
			$this->state['budget']  = max( 2, $this->state['budget'] * 0.5 );
			$this->state['factor']  = max( 0.05, $this->state['factor'] * 0.5 );
			$this->state['retries'] = (int) $this->state['retries'] + 1;
			$this->state['ok_streak'] = 0;
			$this->log( sprintf( 'Previous request did not finish. Resuming from the last checkpoint with smaller chunks (%.1fs per request).', $this->state['budget'] ), 'warning' );
		} else {
			// Recover gradually after failures: a few hiccups early on must not slow
			// down the rest of a long migration. If the host really has a limit, this
			// settles just below it (one repeated chunk roughly every 16 requests).
			$max    = isset( $this->state['budget_max'] ) ? (float) $this->state['budget_max'] : 15.0;
			$streak = isset( $this->state['ok_streak'] ) ? (int) $this->state['ok_streak'] + 1 : 1;
			if ( $streak >= 5 && ( $this->state['budget'] < $max || $this->state['factor'] < 1 ) ) {
				$this->state['budget'] = min( $max, $this->state['budget'] * 1.25 );
				$this->state['factor'] = min( 1.0, $this->state['factor'] * 1.25 );
				$streak                = 0;
			}
			$this->state['ok_streak'] = $streak;
		}
		$budget = HIO_Util::effective_budget( $this->state['budget'] );
		HIO_Util::prepare_runtime( $budget );
		$this->deadline = microtime( true ) + $budget;
		$limit          = HIO_Util::memory_limit();
		$this->mem_ceiling = $limit > 0 ? (int) ( $limit * 0.6 ) : 0;
	}

	/**
	 * Seconds left in this step.
	 *
	 * @return float
	 */
	public function time_left() {
		return $this->deadline - microtime( true );
	}

	/**
	 * True when the step should stop and checkpoint.
	 *
	 * @return bool
	 */
	public function should_stop() {
		if ( microtime( true ) >= $this->deadline ) {
			return true;
		}
		return $this->mem_ceiling > 0 && memory_get_usage( true ) > $this->mem_ceiling;
	}

	/**
	 * Scale a batch size by the current load factor.
	 *
	 * @param int $n   Normal size.
	 * @param int $min Minimum.
	 * @return int
	 */
	public function scaled( $n, $min = 1 ) {
		return max( $min, (int) floor( $n * $this->state['factor'] ) );
	}

	/**
	 * Append a line to the job log.
	 *
	 * @param string $msg   Message.
	 * @param string $level info|warning|error.
	 */
	public function log( $msg, $level = 'info' ) {
		if ( 'warning' === $level ) {
			$this->state['warnings'] = (int) $this->state['warnings'] + 1;
		}
		$line            = gmdate( 'H:i:s' ) . ' ' . strtoupper( $level ) . ' ' . $msg;
		$this->new_log[] = $line;
		@file_put_contents( $this->path( 'log.txt' ), $line . "\n", FILE_APPEND ); // phpcs:ignore
	}

	/**
	 * Last N log lines.
	 *
	 * @param int $n Lines.
	 * @return string[]
	 */
	public function tail( $n = 200 ) {
		$f = $this->path( 'log.txt' );
		if ( ! is_file( $f ) ) {
			return array();
		}
		$lines = file( $f, FILE_IGNORE_NEW_LINES );
		return array_slice( (array) $lines, -$n );
	}

	/**
	 * Delete the job folder.
	 */
	public function destroy() {
		$this->unlock();
		HIO_Storage::rrmdir( $this->dir );
	}
}
