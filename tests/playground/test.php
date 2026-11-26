<?php
/**
 * Smoke test on WordPress Playground (WordPress on SQLite, PHP in WebAssembly).
 * There $wpdb has no mysqli link, so every query takes the $wpdb fallback in
 * HOLEMI_DB (the $wpdb->get_results() / $wpdb->query() lines). Run by run.sh:
 * exports the database, imports it back into the same site as if it came from
 * another address and checks the result. Results go to /out/results.txt, one
 * PASS/FAIL line each.
 *
 * @package HoleInOneMigration
 */

require '/wordpress/wp-load.php';

$e2e_out = array();
$e2e_ok  = function ( $cond, $msg ) use ( &$e2e_out ) {
	$e2e_out[] = ( $cond ? 'PASS  ' : 'FAIL  ' ) . $msg;
};
$e2e_save = function () use ( &$e2e_out ) {
	file_put_contents( '/out/results.txt', implode( "\n", $e2e_out ) . "\n" );
};
register_shutdown_function(
	function () use ( &$e2e_out, $e2e_save ) {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			$e2e_out[] = 'FAIL  fatal error: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'];
		}
		$e2e_save();
	}
);

/**
 * Run a job step by step, like the browser does.
 *
 * @param HOLEMI_Job $job Job.
 * @return string Final status line.
 */
function e2e_run( $job ) {
	$errs = 0;
	for ( $n = 0; $n < 2000; $n++ ) {
		$job = HOLEMI_Job::load( $job->id );
		if ( 'done' === $job->state['phase'] ) {
			return 'done';
		}
		$retry = 'error' === $job->state['status'];
		if ( $retry ) {
			if ( ++$errs > 5 ) {
				return 'error: ' . $job->state['error'];
			}
			$job->state['status'] = 'running';
			$job->state['error']  = '';
		}
		$job->lock();
		$job->begin_step( $retry );
		try {
			if ( 'exp' === $job->state['kind'] ) {
				HOLEMI_Exporter::step( $job );
			} else {
				HOLEMI_Importer::step( $job );
			}
			$job->save();
		} catch ( Throwable $e ) {
			$fresh = HOLEMI_Job::load( $job->id );
			if ( $fresh ) {
				$job->state = $fresh->state;
			}
			$job->state['status'] = 'error';
			$job->state['error']  = HOLEMI_Util::plain( $e );
			$job->save();
		}
		$job->unlock();
	}
	return 'did not finish';
}

global $wpdb;
$e2e_ok( ! ( $wpdb->dbh instanceof mysqli ), 'control: $wpdb has no mysqli link (' . ( is_object( $wpdb->dbh ) ? get_class( $wpdb->dbh ) : gettype( $wpdb->dbh ) ) . '), so the $wpdb fallback is tested' );

// The archive is made to look as if it came from http://old.example (its
// manifest is edited), so the import has addresses to rewrite. The content
// has "%" and "_": prepare() hides "%" behind a placeholder string, and the
// rewritten values go back through prepare() in the UPDATE.
$e2e_home = home_url();
$e2e_old  = 'http://old.example';
$e2e_tpl  = '<p>50% off_today, 100%% sure, %s %d %1$s, LIKE a_b%c, <a href="{HOME}/caf%C3%A9/?q=100%25">link</a></p>';
$e2e_src  = str_replace( '{HOME}', $e2e_old, $e2e_tpl );
$e2e_want = str_replace( '{HOME}', $e2e_home, $e2e_tpl );
$e2e_post = wp_insert_post( wp_slash( array( 'post_title' => 'E2E percent', 'post_status' => 'publish', 'post_content' => $e2e_src ) ) );
$e2e_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
$e2e_live  = function () use ( $wpdb ) {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE post_title = %s", 'E2E percent' ) );
};

try {
	HOLEMI_Storage::ensure();
	$e2e_job = HOLEMI_Exporter::start( array( 'contents' => 'db', 'format' => 'zip', 'exclude_uploads' => false ) );
	$e2e_r   = e2e_run( $e2e_job );
	$e2e_ok( 'done' === $e2e_r, "export finished ($e2e_r)" );
	$e2e_file = HOLEMI_Storage::dir( 'backups' ) . '/' . basename( (string) ( HOLEMI_Job::load( $e2e_job->id )->state['result']['file'] ?? '' ) );
	$e2e_ok( is_file( $e2e_file ) && filesize( $e2e_file ) > 0, 'export archive written (' . ( is_file( $e2e_file ) ? filesize( $e2e_file ) : 0 ) . ' bytes)' );

	$e2e_imp = HOLEMI_Storage::dir( 'import' ) . '/pg-self.zip';
	copy( $e2e_file, $e2e_imp );
	$e2e_zip = new ZipArchive();
	$e2e_zip->open( $e2e_imp );
	$e2e_sql = (string) $e2e_zip->getFromName( 'hio-database.sql' );
	$e2e_ok( false !== strpos( $e2e_sql, wp_slash( $e2e_src ) ) || false !== strpos( $e2e_sql, str_replace( "'", "\\'", $e2e_src ) ), 'database dump contains the test post unchanged' );
	$e2e_man = json_decode( (string) $e2e_zip->getFromName( 'hio-manifest.json' ), true );
	foreach ( array( 'home', 'siteurl', 'db_home', 'db_siteurl' ) as $e2e_k ) {
		$e2e_man[ $e2e_k ] = $e2e_old;
	}
	$e2e_man['content_url'] = "$e2e_old/wp-content";
	$e2e_man['uploads_url'] = "$e2e_old/wp-content/uploads";
	$e2e_zip->addFromString( 'hio-manifest.json', wp_json_encode( $e2e_man ) );
	$e2e_zip->close();

	$e2e_job = HOLEMI_Importer::start_server_file( 'pg-self.zip' );
	$e2e_r   = e2e_run( $e2e_job );
	$e2e_s   = HOLEMI_Job::load( $e2e_job->id )->state;
	wp_cache_flush();
	if ( 'done' === $e2e_r ) {
		$e2e_ok( true, 'import finished' );
		$e2e_got = $e2e_live();
		$e2e_ok( $e2e_got === $e2e_want, 'post with "%" and "_" imported with its address rewritten' . ( $e2e_got === $e2e_want ? '' : ': ' . $e2e_got ) );
		$e2e_ok( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === $e2e_posts, "same number of posts ($e2e_posts)" );
	} else {
		// WordPress on SQLite cannot rename tables (RENAME TABLE is not
		// supported, ALTER TABLE ... RENAME does nothing), so the import stops
		// at the swap. Everything before it ran on SQLite: check the result in
		// the temporary tables, then that the live site was not touched.
		$e2e_ok( 'swap' === $e2e_s['phase'] && false !== strpos( $e2e_r, 'RENAME TABLE' ), 'import got to the database swap, the step SQLite cannot do (' . $e2e_s['phase'] . ')' . ( 'swap' === $e2e_s['phase'] ? '' : ": $e2e_r" ) );
		$e2e_tmp = $e2e_s['dbi']['tmp'] ?? '';
		$e2e_got = '' === $e2e_tmp ? '' : (string) $wpdb->get_var( $wpdb->prepare( 'SELECT post_content FROM %i WHERE post_title = %s', $e2e_tmp . 'posts', 'E2E percent' ) );
		$e2e_ok( $e2e_got === $e2e_want, 'imported post with "%" and "_" has its address rewritten, nothing else changed' . ( $e2e_got === $e2e_want ? '' : ': ' . $e2e_got ) );
		$e2e_n = '' === $e2e_tmp ? -1 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $e2e_tmp . 'posts' ) );
		$e2e_ok( $e2e_n === $e2e_posts, "imported posts table has all $e2e_posts rows ($e2e_n)" );
		HOLEMI_Importer::cancel( HOLEMI_Job::load( $e2e_job->id ) );
		$e2e_ok( $e2e_live() === $e2e_src, 'live site unchanged after the stopped import' );
		$e2e_ok( '' !== $e2e_tmp && ! HOLEMI_DB::tables_with_prefix( $e2e_tmp ), 'cancel removed the temporary tables' );
	}
	$e2e_ok( in_array( 'hole-in-one-migration/hole-in-one-migration.php', (array) get_option( 'active_plugins' ), true ), 'plugin still active' );
	$e2e_ok( home_url() === $e2e_home, 'site keeps its address' );
} catch ( Throwable $e ) {
	$e2e_ok( false, 'exception: ' . HOLEMI_Util::plain( $e ) );
}
