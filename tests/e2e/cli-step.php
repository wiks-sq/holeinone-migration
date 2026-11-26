<?php
/**
 * Runs Hole-in-One Migration jobs from WP-CLI, one step per call, the same way
 * the browser's AJAX steps do. Works with this version (HOLEMI_ classes) and with
 * 1.0.x (HIO_ classes, plugin folder holeinone-migration).
 *
 *   wp eval-file cli-step.php start <archive in import folder>   prints job id
 *   wp eval-file cli-step.php export <both|files|db> <tar.gz|zip>  prints job id
 *   wp eval-file cli-step.php step <job> [retry]   prints "<phase> <status> <progress> <message>"
 *   wp eval-file cli-step.php result <job>         export file name
 *   wp eval-file cli-step.php dir <work|backups|import>
 *   wp eval-file cli-step.php cancel <job>
 *
 * Run with --skip-plugins, so the plugin is loaded here. With the environment
 * variable E2E_FACTOR (0.05 to 1), batches are scaled down by that factor.
 *
 * @package HoleInOneMigration
 */

if ( ! class_exists( 'HOLEMI_Job' ) && ! class_exists( 'HIO_Job' ) ) {
	foreach ( array( 'hole-in-one-migration/hole-in-one-migration.php', 'holeinone-migration/holeinone-migration.php' ) as $e2e_main ) {
		if ( is_file( WP_PLUGIN_DIR . '/' . $e2e_main ) ) {
			require_once WP_PLUGIN_DIR . '/' . $e2e_main;
			break;
		}
	}
}
$e2e_p = class_exists( 'HOLEMI_Job' ) ? 'HOLEMI_' : 'HIO_';
if ( ! class_exists( $e2e_p . 'Job' ) ) {
	fwrite( STDERR, "Plugin not found.\n" );
	exit( 2 );
}
$e2e_job_c = $e2e_p . 'Job';
$e2e_imp_c = $e2e_p . 'Importer';
$e2e_exp_c = $e2e_p . 'Exporter';
$e2e_sto_c = $e2e_p . 'Storage';
$e2e_utl_c = $e2e_p . 'Util';
$e2e_cmd   = isset( $args[0] ) ? $args[0] : '';

if ( 'start' === $e2e_cmd ) {
	echo $e2e_imp_c::start_server_file( $args[1] )->id, "\n";
	return;
}
if ( 'dir' === $e2e_cmd ) {
	$e2e_sto_c::ensure();
	echo $e2e_sto_c::dir( $args[1] ), "\n";
	return;
}
if ( 'export' === $e2e_cmd ) {
	echo $e2e_exp_c::start( array( 'contents' => $args[1], 'format' => $args[2], 'exclude_uploads' => false ) )->id, "\n";
	return;
}

$e2e_job = $e2e_job_c::load( $args[1] );
if ( ! $e2e_job ) {
	fwrite( STDERR, "Job not found.\n" );
	exit( 2 );
}
if ( 'cancel' === $e2e_cmd ) {
	$e2e_imp_c::cancel( $e2e_job );
	echo "cancelled\n";
	return;
}
if ( 'result' === $e2e_cmd ) {
	echo basename( $e2e_job->state['result']['file'] ), "\n";
	return;
}
if ( 'done' !== $e2e_job->state['phase'] ) {
	if ( ! $e2e_job->lock() ) {
		echo "busy\n";
		return;
	}
	if ( 'error' === $e2e_job->state['status'] ) {
		$e2e_job->state['status'] = 'running';
		$e2e_job->state['error']  = '';
		$e2e_job->log( 'Retrying after error.' );
	}
	$e2e_job->begin_step( ! empty( $args[2] ) );
	// E2E_FACTOR (run.sh): smaller batches than the plugin would choose, so
	// tables are read in many batches (as on a slow host after retries).
	$e2e_factor = (float) getenv( 'E2E_FACTOR' );
	if ( $e2e_factor > 0 && isset( $e2e_job->state['factor'] ) ) {
		$e2e_job->state['factor'] = min( (float) $e2e_job->state['factor'], $e2e_factor );
	}
	try {
		if ( 'exp' === $e2e_job->state['kind'] ) {
			$e2e_exp_c::step( $e2e_job );
		} else {
			$e2e_imp_c::step( $e2e_job );
		}
		$e2e_job->save();
	} catch ( Throwable $e ) {
		$e2e_fresh = $e2e_job_c::load( $e2e_job->id );
		if ( $e2e_fresh ) {
			$e2e_job->state = $e2e_fresh->state;
		}
		$e2e_job->state['status'] = 'error';
		$e2e_job->state['error']  = $e2e_utl_c::plain( $e );
		$e2e_job->log( $e2e_utl_c::plain( $e ), 'error' );
		$e2e_job->save();
	}
	$e2e_job->unlock();
}
$e2e_s = $e2e_job->state;
printf( "%s %s %d %s\n", $e2e_s['phase'], $e2e_s['status'], (int) $e2e_s['progress'], preg_replace( '/\s+/', ' ', (string) ( $e2e_s['error'] ? $e2e_s['error'] : $e2e_s['message'] ) ) );
