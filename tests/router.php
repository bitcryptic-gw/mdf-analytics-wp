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

// Negotiation state, so the gated discovery documents can be exercised.
if ( isset( $spec['negotiation'] ) ) {
	$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [
		'status'  => (string) $spec['negotiation'],
		'checked' => (int) ( $spec['checked'] ?? 1780000000 ),
		'detail'  => '',
	];
}
if ( ! empty( $spec['owner_confirmed'] ) ) {
	$GLOBALS['mdf_test']['options'][ MDF_OWNER_CONFIRMED_OPTION ] = true;
}

$_SERVER['REQUEST_METHOD'] = $spec['method'] ?? ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
if ( isset( $spec['accept'] ) ) {
	$_SERVER['HTTP_ACCEPT'] = $spec['accept'];
}

// Discovery documents: /llms.txt and /mdf.json are answered by their own
// handlers, which exit when they serve. They match on REQUEST_URI.
if ( ! empty( $spec['path'] ) ) {
	$_SERVER['REQUEST_URI'] = (string) $spec['path'];
	if ( isset( $spec['if_modified_since'] ) ) {
		$_SERVER['HTTP_IF_MODIFIED_SINCE'] = (string) $spec['if_modified_since'];
	}

	mdf_test_load_plugin();

	if ( $spec['path'] === '/mdf.json' ) {
		mdf_serve_mdf_json();
	} elseif ( $spec['path'] === '/llms.txt' ) {
		mdf_serve_llms_txt();
	}
}

mdf_test_load_plugin();
mdf_maybe_serve_markdown();

// The HTML rendering at a URL that has a markdown variant carries
// X-MDF-Version when the site is asserting MDF.
if ( ! empty( $spec['html_headers'] ) ) {
	mdf_send_html_mdf_version_header();
}

header( 'Content-Type: text/html; charset=UTF-8' );
echo 'HTML-FALLBACK';
