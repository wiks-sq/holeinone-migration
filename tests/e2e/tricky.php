<?php
/**
 * Content aimed at the query code: every value goes through $wpdb->prepare(),
 * which hides each "%" behind a placeholder string, and tables are read in
 * batches, continuing after the primary key of the last row read.
 *
 *   wp eval-file /e2e/tricky.php seed     on the source site (after seed.php)
 *   wp eval-file /e2e/tricky.php check    on a migrated site: PASS/FAIL lines
 *
 *  - posts and an option with "%", "%%", "%s", "%1$s" and "_" next to the
 *    site's address (the search & replace UPDATE writes them back)
 *  - a table with a two-column primary key (text, number) whose text values
 *    hold "_", "%", a quote and non-ASCII letters, 1500 rows: run.sh sets
 *    small batches (E2E_FACTOR), so the export and the search & replace read
 *    it in many batches
 *  - rows with "%" in a table without a primary key (nested REPLACE() update)
 *
 * PHP 7.4 compatible on purpose.
 *
 * @package HoleInOneMigration
 */

global $wpdb;

$e2e_home  = home_url();
$e2e_text  = '50% off_today, 100%% sure, %s %d %1$s, LIKE a_b%c';
$e2e_slugs = array( 'alpha', 'be_ta', 'gam%ma', "o'brien", 'ääkköset' );
$e2e_per   = 300;
$e2e_ctab  = $wpdb->prefix . 'e2e_composite';
$e2e_ntab  = $wpdb->prefix . 'e2e_nokey';

/**
 * Expected content of percent post $i on the site at $home.
 *
 * @param string $home Site address.
 * @param int    $i    Number.
 * @param string $text Text with "%" and "_".
 * @return string
 */
function e2e_pct_post( $home, $i, $text ) {
	return "<p>$text <a href=\"$home/caf%C3%A9/$i?q=100%25\">link</a></p>";
}

$e2e_cmd = isset( $args[0] ) ? $args[0] : '';

if ( 'seed' === $e2e_cmd ) {
	for ( $e2e_i = 1; $e2e_i <= 5; $e2e_i++ ) {
		wp_insert_post(
			wp_slash(
				array(
					'post_status'  => 'publish',
					'post_title'   => "E2E percent $e2e_i",
					'post_content' => e2e_pct_post( $e2e_home, $e2e_i, $e2e_text ),
				)
			)
		);
	}
	update_option( 'e2e_percent', array( 'u' => "$e2e_home/x?a=1%20b", 'p' => $e2e_text ) );

	$wpdb->query( "CREATE TABLE `$e2e_ctab` ( slug VARCHAR(64) NOT NULL, n INT NOT NULL, url VARCHAR(255), data TEXT, PRIMARY KEY (slug, n) ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
	foreach ( $e2e_slugs as $e2e_slug ) {
		$e2e_rows = array();
		for ( $e2e_n = 1; $e2e_n <= $e2e_per; $e2e_n++ ) {
			$e2e_rows[] = $wpdb->prepare( '(%s, %d, %s, %s)', $e2e_slug, $e2e_n, "$e2e_home/c/$e2e_n", maybe_serialize( array( 'u' => "$e2e_home/c/$e2e_n", 'p' => $e2e_text ) ) );
		}
		$wpdb->query( "INSERT INTO `$e2e_ctab` (slug, n, url, data) VALUES " . implode( ',', $e2e_rows ) );
	}
	for ( $e2e_i = 1; $e2e_i <= 20; $e2e_i++ ) {
		$wpdb->insert( $e2e_ntab, array( 'url' => "$e2e_home/nokey-pct/$e2e_i?q=100%25", 'note' => $e2e_text ) );
	}
	echo 'tricky content seeded: ', (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$e2e_ctab`" ), " composite-key rows\n";
	return;
}

if ( 'check' !== $e2e_cmd ) {
	fwrite( STDERR, "Usage: wp eval-file tricky.php seed|check\n" );
	exit( 2 );
}

$e2e_ok = function ( $cond, $msg ) {
	echo ( $cond ? 'PASS  ' : 'FAIL  ' ), $msg, "\n";
};

// Posts and option with "%" and "_".
$e2e_bad = array();
for ( $e2e_i = 1; $e2e_i <= 5; $e2e_i++ ) {
	$e2e_got = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE post_title = %s", "E2E percent $e2e_i" ) );
	if ( e2e_pct_post( $e2e_home, $e2e_i, $e2e_text ) !== $e2e_got ) {
		$e2e_bad[] = "$e2e_i: $e2e_got";
	}
}
$e2e_ok( ! $e2e_bad, 'posts with "%" and "_" have the new address and are otherwise unchanged' . ( $e2e_bad ? ' (' . implode( ' | ', $e2e_bad ) . ')' : '' ) );
$e2e_opt = get_option( 'e2e_percent' );
$e2e_ok( is_array( $e2e_opt ) && "$e2e_home/x?a=1%20b" === $e2e_opt['u'] && $e2e_text === $e2e_opt['p'], 'serialized option with "%" and "_" rewritten and intact' . ( is_array( $e2e_opt ) ? '' : ': ' . var_export( $e2e_opt, true ) ) );

// Two-column primary key, read in many batches.
$e2e_want = count( $e2e_slugs ) * $e2e_per;
$e2e_rows = $wpdb->get_results( "SELECT slug, n, url, data FROM `$e2e_ctab`", ARRAY_A );
$e2e_seen = array();
$e2e_bad  = 0;
foreach ( (array) $e2e_rows as $e2e_r ) {
	$e2e_seen[ $e2e_r['slug'] . '|' . $e2e_r['n'] ] = true;
	$e2e_u = maybe_unserialize( $e2e_r['data'] );
	if ( "$e2e_home/c/{$e2e_r['n']}" !== $e2e_r['url'] || ! is_array( $e2e_u ) || $e2e_r['url'] !== $e2e_u['u'] || $e2e_text !== $e2e_u['p'] ) {
		$e2e_bad++;
	}
}
$e2e_miss = 0;
foreach ( $e2e_slugs as $e2e_slug ) {
	for ( $e2e_n = 1; $e2e_n <= $e2e_per; $e2e_n++ ) {
		$e2e_miss += isset( $e2e_seen[ "$e2e_slug|$e2e_n" ] ) ? 0 : 1;
	}
}
$e2e_ok( count( (array) $e2e_rows ) === $e2e_want && 0 === $e2e_miss, "two-column key table (text, number) has all $e2e_want rows, none missing (" . count( (array) $e2e_rows ) . " rows, $e2e_miss missing)" );
$e2e_ok( 0 === $e2e_bad, "every two-column key row rewritten and intact ($e2e_bad wrong)" );

// No primary key, "%" in the values.
$e2e_rows = $wpdb->get_results( "SELECT url, note FROM `$e2e_ntab` WHERE url LIKE '%nokey-pct%'", ARRAY_A );
$e2e_bad  = 0;
foreach ( (array) $e2e_rows as $e2e_r ) {
	$e2e_bad += ( 0 === strpos( $e2e_r['url'], "$e2e_home/nokey-pct/" ) && '?q=100%25' === substr( $e2e_r['url'], -9 ) && $e2e_text === $e2e_r['note'] ) ? 0 : 1;
}
$e2e_ok( 20 === count( (array) $e2e_rows ) && 0 === $e2e_bad, 'table without primary key: 20 rows with "%" rewritten and intact (' . count( (array) $e2e_rows ) . " rows, $e2e_bad wrong)" );
