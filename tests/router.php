<?php
/**
 * Router for PHP's built-in server, so the tests can observe real response
 * headers. One request = one fresh fixture set, loaded from the JSON spec file
 * named in ?spec=.
 *
 * Used by lib.php run_serve_case(); not intended to be run by hand.
 */

require __DIR__ . '/bootstrap.php';

$spec_path = $_GET['spec'] ?? '';
$spec      = is_file( $spec_path ) ? json_decode( (string) file_get_contents( $spec_path ), true ) : [];

if ( ! is_array( $spec ) ) {
	http_response_code( 500 );
	echo 'bad spec';
	return;
}

$GLOBALS['mdf_test']['options']['mdf_offer_markdown'] = ! empty( $spec['offer'] );

foreach ( ( $spec['posts'] ?? [] ) as $id => $fields ) {
	mdf_test_add_post( (int) $id, (array) $fields );
}

mdf_test_load_plugin();

if ( ! empty( $spec['md'] ) ) {
	foreach ( $spec['md'] as $id => $size ) {
		mdf_test_write_md( (int) $id, $size > 0 ? str_repeat( 'x', (int) $size ) : '' );
	}
}
if ( ! empty( $spec['woo_pages'] ) || ! empty( $spec['woo_flags'] ) ) {
	mdf_test_enable_woo( (array) ( $spec['woo_pages'] ?? [] ), (array) ( $spec['woo_flags'] ?? [] ) );
}

if ( ! empty( $spec['third_party_nocache'] ) && ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}
if ( ! empty( $spec['adapter_marker'] ) ) {
	define( 'MDF_WPSC_MARKDOWN_BYPASS', true );
}

$GLOBALS['mdf_test']['queried_object_id'] = (int) ( $spec['queried_object_id'] ?? 0 );
$GLOBALS['mdf_test']['is_singular']       = (bool) ( $spec['is_singular'] ?? true );

function get_queried_object_id() { return $GLOBALS['mdf_test']['queried_object_id']; }
function is_singular( $type = '' ) { return $GLOBALS['mdf_test']['is_singular']; }

$_SERVER['REQUEST_METHOD'] = $spec['method'] ?? ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
if ( isset( $spec['accept'] ) ) {
	$_SERVER['HTTP_ACCEPT'] = $spec['accept'];
}

mdf_test_load_plugin();
mdf_maybe_serve_markdown();
header( 'Content-Type: text/html; charset=UTF-8' );
echo 'HTML-FALLBACK';
