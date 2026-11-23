<?php
/**
 * Fills the source site with content that exercises the tricky parts of a
 * migration. Run once with: wp eval-file /e2e/seed.php
 *
 *  - posts and pages whose content links to the site's own address, plain,
 *    JSON-escaped (http:\/\/host) and inside PHP serialized post meta
 *  - serialized options with nested arrays and an object
 *  - images with generated thumbnails (attachments with metadata)
 *  - a custom table with a primary key and one without, enough rows for
 *    several pages of keyset pagination
 *  - a plugin, an mu-plugin and a child theme, all active
 *  - a 40 MB file (written in several steps), an empty file, file names
 *    with spaces and non-ASCII letters, a deep folder
 *
 * PHP 7.4 compatible on purpose (the oldest supported PHP is tested too).
 *
 * @package HoleInOneMigration
 */

global $wpdb;
$e2e_home = home_url();
$e2e_esc  = str_replace( '/', '\\/', $e2e_home );

// Posts and pages.
for ( $e2e_i = 1; $e2e_i <= 120; $e2e_i++ ) {
	// wp_insert_post() unslashes its input, so slash it to keep the \/ escapes.
	$e2e_id = wp_insert_post(
		wp_slash(
			array(
			'post_type'    => 0 === $e2e_i % 5 ? 'page' : 'post',
			'post_status'  => 'publish',
			'post_title'   => "E2E post $e2e_i ääkköset",
			'post_content' => "<p>Read <a href=\"$e2e_home/?p=$e2e_i\">this</a> and <img src=\"$e2e_home/wp-content/uploads/e2e/pic.png\"></p>\n<!-- wp:e2e/block {\"url\":\"$e2e_esc\\/x\"} /-->",
			)
		)
	);
	update_post_meta( $e2e_id, 'e2e_meta', array( 'link' => "$e2e_home/x/$e2e_i", 'list' => array( $e2e_home, 'ääkköset' ) ) );
}

// Serialized options, including an object and a string whose length changes.
update_option( 'e2e_serialized', array( 'url' => $e2e_home, 'nested' => array( 'deep' => array( 'u' => "$e2e_home/deep" ) ) ) );
$e2e_obj        = new stdClass();
$e2e_obj->url   = "$e2e_home/object";
$e2e_obj->count = 3;
update_option( 'e2e_object', $e2e_obj );
update_option( 'e2e_json', wp_json_encode( array( 'url' => "$e2e_home/json" ) ) );

// Images with thumbnails.
require_once ABSPATH . 'wp-admin/includes/image.php';
for ( $e2e_i = 1; $e2e_i <= 12; $e2e_i++ ) {
	$e2e_img = imagecreatetruecolor( 800, 600 );
	imagefill( $e2e_img, 0, 0, imagecolorallocate( $e2e_img, ( $e2e_i * 20 ) % 255, 90, 160 ) );
	imagestring( $e2e_img, 5, 20, 20, "e2e image $e2e_i", imagecolorallocate( $e2e_img, 255, 255, 255 ) );
	ob_start();
	imagepng( $e2e_img );
	$e2e_up = wp_upload_bits( "e2e-image-$e2e_i.png", null, ob_get_clean() );
	if ( ! empty( $e2e_up['error'] ) ) {
		fwrite( STDERR, $e2e_up['error'] . "\n" );
		exit( 1 );
	}
	$e2e_att = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => "e2e image $e2e_i",
			'post_status'    => 'inherit',
			'guid'           => $e2e_up['url'],
		),
		$e2e_up['file']
	);
	wp_update_attachment_metadata( $e2e_att, wp_generate_attachment_metadata( $e2e_att, $e2e_up['file'] ) );
}

// Custom tables.
$wpdb->query( "CREATE TABLE {$wpdb->prefix}e2e_keyed ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, url VARCHAR(255), data LONGTEXT ) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}e2e_nokey ( url VARCHAR(255), note TEXT ) DEFAULT CHARSET=utf8mb4" );
for ( $e2e_i = 1; $e2e_i <= 6000; $e2e_i += 500 ) {
	$e2e_rows = array();
	for ( $e2e_j = $e2e_i; $e2e_j < $e2e_i + 500; $e2e_j++ ) {
		$e2e_rows[] = $wpdb->prepare( '(%s, %s)', "$e2e_home/row/$e2e_j", maybe_serialize( array( 'u' => "$e2e_home/s/$e2e_j" ) ) );
	}
	$wpdb->query( "INSERT INTO {$wpdb->prefix}e2e_keyed (url, data) VALUES " . implode( ',', $e2e_rows ) );
}
for ( $e2e_i = 1; $e2e_i <= 50; $e2e_i++ ) {
	$wpdb->insert( "{$wpdb->prefix}e2e_nokey", array( 'url' => "$e2e_home/nokey/$e2e_i", 'note' => 'no primary key' ) );
}

// A plugin, an mu-plugin and a child theme.
$e2e_files = array(
	WP_PLUGIN_DIR . '/e2e-demo/e2e-demo.php'   => "<?php\n/*\nPlugin Name: E2E Demo\n*/\nadd_action( 'wp_footer', function () { echo '<!-- e2e-demo -->'; } );\n",
	WPMU_PLUGIN_DIR . '/e2e-mu.php'            => "<?php\n// E2E must-use plugin.\ndefine( 'E2E_MU_LOADED', true );\n",
	get_theme_root() . '/e2e-child/style.css'  => "/*\nTheme Name: E2E Child\nTemplate: " . get_template() . "\n*/\n",
	get_theme_root() . '/e2e-child/functions.php' => "<?php\n// E2E child theme.\n",
);
// Odd files in uploads.
$e2e_up_dir = wp_upload_dir()['basedir'] . '/e2e';
$e2e_files[ "$e2e_up_dir/pic.png" ]                         = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$e2e_files[ "$e2e_up_dir/empty.txt" ]                       = '';
$e2e_files[ "$e2e_up_dir/name with spaces.txt" ]            = 'spaces';
$e2e_files[ "$e2e_up_dir/ääkköset-ñ-文字.txt" ]              = 'utf-8 name';
$e2e_files[ "$e2e_up_dir/a/b/c/d/e/f/g/h/i/j/deep.txt" ]    = 'deep';
foreach ( $e2e_files as $e2e_path => $e2e_body ) {
	wp_mkdir_p( dirname( $e2e_path ) );
	file_put_contents( $e2e_path, $e2e_body );
}
// 40 MB of random data: larger than one step can copy.
$e2e_fh = fopen( "$e2e_up_dir/big.bin", 'wb' );
for ( $e2e_i = 0; $e2e_i < 40; $e2e_i++ ) {
	fwrite( $e2e_fh, random_bytes( 1048576 ) );
}
fclose( $e2e_fh );

$e2e_active   = (array) get_option( 'active_plugins', array() );
$e2e_active[] = 'e2e-demo/e2e-demo.php';
update_option( 'active_plugins', array_values( array_unique( $e2e_active ) ) );
switch_theme( 'e2e-child' );

echo 'seeded ', count( $wpdb->get_col( 'SHOW TABLES' ) ), " tables\n";
