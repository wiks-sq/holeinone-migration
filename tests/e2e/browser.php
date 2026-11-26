<?php
/**
 * Drives the plugin over HTTP the way the admin page's JavaScript does: log in
 * with a cookie, read the nonce from the admin page, then call admin-ajax.php
 * and admin-post.php with the action names that assets/admin.js uses. The
 * WP-CLI tests call the PHP classes directly, so they can't notice when the
 * browser and the server disagree about a name.
 *
 * Runs inside a site's web container (PHP with curl), talks to its Apache:
 *
 *   php browser.php <port> export <save as>   export (database, zip), download it
 *   php browser.php <port> import <archive>   upload in chunks and import it
 *
 * Prints PASS/FAIL lines. Exit code: 0 when every check passed.
 *
 * @package HoleInOneMigration
 */

// phpcs:disable -- Test tool, not part of the plugin.

$port = (int) ( $argv[1] ?? 0 );
$cmd  = $argv[2] ?? '';
$arg  = $argv[3] ?? '';
$base = "http://localhost:$port";
$jar  = tempnam( sys_get_temp_dir(), 'jar' );
$fail = 0;

function pass( $m ) { echo "PASS  $m\n"; }
function fail( $m ) { global $fail; $fail = 1; echo "FAIL  $m\n"; }
function check( $ok, $m ) { $ok ? pass( $m ) : fail( $m ); return $ok; }
function done() { global $fail, $jar; @unlink( $jar ); exit( $fail ); }

/**
 * One HTTP request. The site's address is http://localhost:<port>; the
 * connection goes to this container's Apache on port 80.
 */
function http( $url, array $opt = array() ) {
	global $base, $jar, $port;
	$url = 0 === strpos( $url, 'http' ) ? $url : $base . $url;
	$ch  = curl_init( $url );
	$hdr = array();
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECT_TO     => array( "localhost:$port:localhost:80" ),
			CURLOPT_TIMEOUT        => 180,
			CURLOPT_HEADERFUNCTION => function ( $c, $h ) use ( &$hdr ) {
				$p = strpos( $h, ':' );
				if ( $p ) {
					$hdr[ strtolower( trim( substr( $h, 0, $p ) ) ) ] = trim( substr( $h, $p + 1 ) );
				}
				return strlen( $h );
			},
		)
	);
	if ( empty( $opt['anonymous'] ) ) {
		curl_setopt( $ch, CURLOPT_COOKIEFILE, $jar );
		curl_setopt( $ch, CURLOPT_COOKIEJAR, $jar );
	}
	if ( isset( $opt['post'] ) ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $opt['post'] );
	}
	if ( isset( $opt['headers'] ) ) {
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $opt['headers'] );
	}
	// 'save' streams the body into a file: an archive of a real site can be
	// larger than PHP's memory_limit. The returned body is then empty.
	$fh = null;
	if ( isset( $opt['save'] ) ) {
		$fh = fopen( $opt['save'], 'wb' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
		curl_setopt( $ch, CURLOPT_FILE, $fh );
	}
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	if ( $fh ) {
		fclose( $fh );
		$body = '';
	}
	return array( $code, (string) $body, $hdr );
}

function login() {
	http( '/wp-login.php' );
	list( $code, , $h ) = http(
		'/wp-login.php',
		array(
			'post'    => array( 'log' => 'admin', 'pwd' => 'admin', 'testcookie' => '1', 'redirect_to' => '/wp-admin/' ),
			'headers' => array( 'Cookie: wordpress_test_cookie=WP%20Cookie%20check' ),
		)
	);
	return 302 === $code && false !== strpos( $h['location'] ?? '', 'wp-admin' );
}

/** The admin page and the HOLEMI config object the script receives from it. */
function admin_page() {
	list( $code, $body ) = http( '/wp-admin/admin.php?page=hole-in-one-migration' );
	if ( 200 !== $code || ! preg_match( '/var HOLEMI = (\{.*?\});\s*\n/s', $body, $m ) ) {
		return array( null, $body );
	}
	return array( json_decode( $m[1], true ), $body );
}

/**
 * One admin-ajax call. Returns the decoded answer, or array( 'raw' => body )
 * when it isn't JSON: WordPress answers a bare 0 or -1 for an action nobody
 * registered or a request without a login.
 */
function ajax( $action, array $fields, $nonce, $anonymous = false ) {
	$fields = array( 'action' => $action, 'nonce' => $nonce ) + $fields;
	list( $code, $body ) = http( '/wp-admin/admin-ajax.php', array( 'post' => $fields, 'anonymous' => $anonymous ) );
	$j = json_decode( $body, true );
	return is_array( $j ) ? $j + array( 'http' => $code ) : array( 'raw' => trim( $body ), 'http' => $code );
}

function is_auth_answer( array $r ) {
	return isset( $r['raw'] ) && in_array( $r['raw'], array( '0', '-1' ), true );
}

function describe( array $r ) {
	return isset( $r['raw'] ) ? 'HTTP ' . $r['http'] . ' "' . substr( $r['raw'], 0, 80 ) . '"' : json_encode( $r['data'] ?? $r );
}

/** The action names admin.js sends. */
function js_actions() {
	$js = (string) file_get_contents( '/var/www/html/wp-content/plugins/hole-in-one-migration/assets/admin.js' );
	preg_match_all( "/(?:api|start)\\('([a-z_]+)'/", $js, $m );
	return array_values( array_unique( $m[1] ) );
}

/** Run a job with holemi_step until it is done. Returns the last answer. */
function run_steps( $job, $nonce, $on_auth = null ) {
	for ( $n = 1; $n <= 2000; $n++ ) {
		$r = ajax( 'holemi_step', array( 'job' => $job, 'retry' => 0, 'resume' => 0 ), $nonce );
		if ( is_auth_answer( $r ) ) {
			return $on_auth ? $on_auth( $r, $n ) : $r;
		}
		if ( empty( $r['success'] ) ) {
			return $r;
		}
		if ( ! empty( $r['data']['busy'] ) ) {
			sleep( 1 );
			continue;
		}
		if ( 'done' === ( $r['data']['phase'] ?? '' ) ) {
			echo "   done after $n steps\n";
			return $r;
		}
	}
	return array( 'raw' => 'too many steps', 'http' => 0 );
}

if ( ! check( login(), "site $port: admin can log in" ) ) {
	done();
}
list( $cfg, $page ) = admin_page();
if ( ! check( is_array( $cfg ) && ! empty( $cfg['nonce'] ), "site $port: admin page hands the script its settings and nonce" ) ) {
	echo substr( strip_tags( $page ), 0, 300 ), "\n";
	done();
}
$nonce = $cfg['nonce'];

if ( 'export' === $cmd ) {
	// Every action the script uses must reach the plugin. An unregistered one
	// gets a bare "0", which the page shows as "Your login session expired".
	$actions = js_actions();
	check( count( $actions ) >= 7, 'admin.js uses ' . count( $actions ) . ' AJAX actions: ' . implode( ', ', $actions ) );
	foreach ( array_diff( $actions, array( 'holemi_export_start', 'holemi_import_start', 'holemi_step', 'holemi_upload_chunk' ) ) as $a ) {
		// Harmless calls: an unknown job or file only gets an error message back.
		$r = ajax( $a, array( 'job' => 'exp_000000000000000000000000', 'file' => 'none.zip' ), $nonce );
		check( ! is_auth_answer( $r ) && isset( $r['success'] ), "$a reaches the plugin (" . describe( $r ) . ')' );
	}
	$r = ajax( 'holemi_step', array( 'job' => 'exp_000000000000000000000000' ), 'wrong-nonce' );
	check( isset( $r['success'] ) && false === $r['success'] && 'auth' === ( $r['data']['code'] ?? '' ), 'a request with a wrong nonce is refused (' . describe( $r ) . ')' );

	$r = ajax( 'holemi_export_start', array( 'contents' => 'db', 'format' => 'zip', 'exclude_uploads' => '0' ), $nonce );
	if ( ! check( ! empty( $r['success'] ) && ! empty( $r['data']['id'] ), 'holemi_export_start starts an export (' . describe( $r ) . ')' ) ) {
		done();
	}
	$job = $r['data']['id'];
	$r   = run_steps( $job, $nonce );
	if ( ! check( 'done' === ( $r['data']['phase'] ?? '' ) && ! empty( $r['data']['result']['php'] ), 'holemi_step runs the export to the end (' . describe( $r ) . ')' ) ) {
		done();
	}
	$url = $r['data']['result']['php'];
	list( $code ) = http( $url, array( 'save' => $arg ) );
	clearstatcache();
	$head = (string) file_get_contents( $arg, false, null, 0, 100 );
	check( 200 === $code && 'PK' === substr( $head, 0, 2 ), "download link (holemi_download) sends the archive (HTTP $code, " . filesize( $arg ) . ' bytes)' );
	list( $code, $part ) = http( $url, array( 'headers' => array( 'Range: bytes=0-99' ) ) );
	check( 206 === $code && $head === $part, "download supports resuming (Range: HTTP $code, " . strlen( $part ) . ' bytes)' );
	list( $code ) = http( $url, array( 'anonymous' => true ) );
	check( 200 !== $code, "download needs a login (HTTP $code without one)" );
	list( $code, $log ) = http( $r['data']['logUrl'] );
	check( 200 === $code && false !== stripos( $log, 'export' ), "log link (holemi_log) sends the job log (HTTP $code)" );
	$r = ajax( 'holemi_status', array( 'job' => $job ), $nonce );
	check( ! empty( $r['success'] ) && 'done' === ( $r['data']['phase'] ?? '' ), 'holemi_status reports the export (' . describe( $r ) . ')' );
	// Without a login only imports are visible (the import needs it after the database switch).
	$r = ajax( 'holemi_status', array( 'job' => $job ), '', true );
	check( ! is_auth_answer( $r ) && empty( $r['success'] ), 'holemi_status shows no export jobs without a login (' . describe( $r ) . ')' );
	if ( preg_match( '#href="([^"]*action=holemi_dismiss[^"]*)"#', $page, $m ) ) {
		list( $code ) = http( html_entity_decode( $m[1] ) );
		check( 302 === $code, "notice dismiss link (holemi_dismiss) works (HTTP $code)" );
	}
	done();
}

if ( 'import' === $cmd ) {
	$size = filesize( $arg );
	$r    = ajax( 'holemi_import_start', array( 'source' => 'upload', 'name' => basename( $arg ), 'size' => $size ), $nonce );
	if ( ! check( ! empty( $r['success'] ) && ! empty( $r['data']['id'] ), 'holemi_import_start starts an import (' . describe( $r ) . ')' ) ) {
		done();
	}
	$job = $r['data']['id'];
	// Upload in pieces like the browser: each answer says how much the server has.
	$chunk  = 262144;
	$offset = 0;
	$fh     = fopen( $arg, 'rb' );
	$calls  = 0;
	while ( $offset < $size && $calls++ < 10000 ) {
		fseek( $fh, $offset );
		$tmp = tempnam( sys_get_temp_dir(), 'chunk' );
		file_put_contents( $tmp, fread( $fh, $chunk ) );
		$fields = array( 'action' => 'holemi_upload_chunk', 'nonce' => $nonce, 'job' => $job, 'offset' => $offset, 'chunk' => new CURLFile( $tmp, 'application/octet-stream', 'blob' ) );
		list( , $body ) = http( '/wp-admin/admin-ajax.php', array( 'post' => $fields ) );
		unlink( $tmp );
		$r = json_decode( $body, true );
		if ( empty( $r['success'] ) || ! isset( $r['data']['received'] ) ) {
			fail( 'holemi_upload_chunk at offset ' . $offset . ': ' . substr( $body, 0, 200 ) );
			done();
		}
		$offset = (int) $r['data']['received'];
	}
	fclose( $fh );
	check( $offset === $size, "holemi_upload_chunk received all $size bytes in $calls chunks" );

	// The database switch replaces this site's users and sessions, so the
	// login is gone afterwards. The page then polls holemi_status, which
	// works without a login, until the import reports that it is done.
	$saw_auth = false;
	$r        = run_steps(
		$job,
		$nonce,
		function ( $r, $n ) use ( $job, &$saw_auth ) {
			$saw_auth = true;
			echo "   step $n: logged out by the database switch, polling holemi_status\n";
			for ( $i = 0; $i < 40; $i++ ) {
				$s = ajax( 'holemi_status', array( 'job' => $job ), '', true );
				if ( 'done' === ( $s['data']['phase'] ?? '' ) ) {
					return $s;
				}
				sleep( 1 );
			}
			return $s;
		}
	);
	check( 'done' === ( $r['data']['phase'] ?? '' ), 'the import finishes (' . describe( $r ) . ')' );
	if ( ! $saw_auth ) {
		// The switch and the clean-up fit in one request: the login is gone now.
		$s = ajax( 'holemi_step', array( 'job' => $job ), $nonce );
		check( is_auth_answer( $s ), 'the old login no longer works after the database switch (' . describe( $s ) . ')' );
		$s = ajax( 'holemi_status', array( 'job' => $job ), '', true );
		check( 'done' === ( $s['data']['phase'] ?? '' ), 'holemi_status reports the finished import without a login (' . describe( $s ) . ')' );
	}
	@unlink( $jar );
	$jar = tempnam( sys_get_temp_dir(), 'jar' );
	check( login(), 'logging in with the imported site\'s account works' );
	list( $cfg ) = admin_page();
	check( is_array( $cfg ), 'the plugin page opens after the import' );
	done();
}

fail( "unknown command: $cmd" );
done();
