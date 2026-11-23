<?php
/**
 * mdf-analytics-wp test suite — stub harness, no PHPUnit, no WordPress.
 *
 * Run: php tests/unit.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/schema_lib.php';
mdf_test_load_plugin();

/**
 * A request the plugin declined is answered by WordPress's normal HTML render,
 * which the router represents as this marker. The point is that MDF added no
 * markdown representation and no Vary: Accept.
 */
function falls_through( array $case, string $label ): void {
	same( 'HTML-FALLBACK', $case['body'], $label . ' (normal HTML, not markdown)' );
	same( '', header_value( $case['headers'], 'Vary' ), $label . ' (no Vary: Accept)' );
}

$woo_pages = [ 'cart' => 10, 'checkout' => 11, 'myaccount' => 12, 'shop' => 9 ];

// ---------------------------------------------------------------------------
group( 'non-Woo site: nothing changes' );
// ---------------------------------------------------------------------------

same( [], mdf_excluded_page_ids(), 'no exclusions resolved' );
mdf_test_add_post( 9 );
ok( ! mdf_excluded_from_markdown( 9 ), 'ordinary page is convertible' );

// The served llms.txt must be byte-identical to 0.1.13 on a non-Woo site.
$GLOBALS['mdf_test']['options']['mdf_offer_markdown']    = true;
$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [ 'status' => 'working', 'checked' => time(), 'detail' => '' ];
$non_woo_llms = mdf_get_servable_llms_txt()['content'];
same( 1161, strlen( $non_woo_llms ), 'non-Woo llms.txt is still 1161 B' );
same( '65120144834c37e8827c0a9005b517a488c72d6e44b2748662732003bbe28d08', hash( 'sha256', $non_woo_llms ), 'non-Woo llms.txt is byte-identical to 0.1.13' );
ok( false === strpos( $non_woo_llms, 'Session-only pages' ), 'non-Woo llms.txt gains no exception note' );

$GLOBALS['mdf_test']['options'] = [];

// ---------------------------------------------------------------------------
group( 'serving: singular objects only, non-empty variants only' );
// ---------------------------------------------------------------------------

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'posts' => [ 5 => [] ], 'md' => [ 5 => 120 ] ] );
same( 200, $case['status'], 'singular page with a variant is served' );
same( 'text/markdown; charset=utf-8', header_value( $case['headers'], 'Content-Type' ), 'serves Content-Type: text/markdown' );
same( 'Accept', header_value( $case['headers'], 'Vary' ), 'serves Vary: Accept' );
same( 120, strlen( $case['body'] ), 'serves the variant body' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'posts' => [ 5 => [] ], 'md' => [ 5 => 0 ] ] );
falls_through( $case, 'zero-byte variant' );

$case = run_serve_case( [
	'queried_object_id' => 1,   // a term id that collides with post 1
	'is_singular'       => false,
	'offer'             => true,
	'md'                => [ 1 => 85 ],
] );
falls_through( $case, 'archive (non-singular), term id colliding with a cached post' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => false, 'posts' => [ 5 => [] ], 'md' => [ 5 => 120 ] ] );
falls_through( $case, 'offering off' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'accept' => 'text/html', 'posts' => [ 5 => [] ], 'md' => [ 5 => 120 ] ] );
falls_through( $case, 'browser request' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'method' => 'HEAD', 'posts' => [ 5 => [] ], 'md' => [ 5 => 120 ] ] );
same( 200, $case['status'], 'HEAD gets the markdown headers' );
same( '', $case['body'], 'HEAD gets no body' );

// ---------------------------------------------------------------------------
group( 'serving: WooCommerce transactional pages' );
// ---------------------------------------------------------------------------

$case = run_serve_case( [
	'queried_object_id' => 10,
	'is_singular'       => true,
	'offer'             => true,
	'woo_pages'         => $woo_pages,
	'posts'             => [ 10 => [], 11 => [], 12 => [], 9 => [], 21 => [] ],
	'md'                => [ 10 => 60, 21 => 90 ],
] );
falls_through( $case, 'cart page with a stale variant' );

$case = run_serve_case( [
	'queried_object_id' => 12,
	'is_singular'       => true,
	'offer'             => true,
	'woo_pages'         => $woo_pages,
	'woo_flags'         => [ 'account' => true, 'endpoint' => true ],
	'posts'             => [ 12 => [] ],
	'md'                => [ 12 => 80 ],
] );
falls_through( $case, 'account endpoint (my-account/orders)' );

$case = run_serve_case( [
	'queried_object_id' => 11,
	'is_singular'       => true,
	'offer'             => true,
	'woo_pages'         => $woo_pages,
	'woo_flags'         => [ 'checkout' => true ],
	'posts'             => [ 11 => [] ],
	'md'                => [ 11 => 40 ],
] );
falls_through( $case, 'checkout page' );

$case = run_serve_case( [
	'queried_object_id' => 21,
	'is_singular'       => true,
	'offer'             => true,
	'woo_pages'         => $woo_pages,
	'posts'             => [ 21 => [ 'post_type' => 'product' ] ],
	'md'                => [ 21 => 90 ],
] );
same( 200, $case['status'], 'product page still serves markdown' );
same( 'Accept', header_value( $case['headers'], 'Vary' ), 'product page still sends Vary: Accept' );

$case = run_serve_case( [
	'queried_object_id' => 40,
	'is_singular'       => true,
	'offer'             => true,
	'posts'             => [ 40 => [ 'post_password' => 'secret' ] ],
	'md'                => [ 40 => 70 ],
] );
falls_through( $case, 'password-protected page' );

$case = run_serve_case( [
	'queried_object_id'   => 21,
	'is_singular'         => true,
	'offer'               => true,
	'third_party_nocache' => true,
	'posts'               => [ 21 => [] ],
	'md'                  => [ 21 => 90 ],
] );
falls_through( $case, 'third-party DONOTCACHEPAGE' );

$case = run_serve_case( [
	'queried_object_id' => 21,
	'is_singular'       => true,
	'offer'             => true,
	'adapter_marker'    => true,
	'posts'             => [ 21 => [] ],
	'md'                => [ 21 => 90 ],
] );
same( 200, $case['status'], 'adapter-defined DONOTCACHEPAGE does not suppress markdown' );
same( 'Accept', header_value( $case['headers'], 'Vary' ), 'adapter path still sends Vary: Accept' );

// ---------------------------------------------------------------------------
group( 'exclusion resolution, conversion, backfill, listing, llms.txt' );
// ---------------------------------------------------------------------------

$state = run_state_case( [
	'woo_pages'   => $woo_pages,
	'posts'       => [
		9 => [], 10 => [], 11 => [], 12 => [],
		21 => [ 'post_type' => 'product' ],
		40 => [ 'post_password' => 'secret' ],
		41 => [ 'post_type' => 'hidden' ],
		42 => [ 'post_status' => 'draft' ],
	],
	'like_result' => [ 30 ],
	'probe_ids'   => [ 9, 10, 11, 12, 21, 40, 41, 42 ],
	'list_md'     => [ 9 => 20, 10 => 20, 21 => 20 ],
	'convert_id'  => 11,
	'backfill'    => true,
	'llms'        => true,
] );
if ( isset( $state['error'] ) ) {
	ok( false, 'state_case ran', (string) $state['error'] );
	
}

same( [ 9, 10, 11, 12, 30 ], $state['excluded'], 'exclusions cover shop, cart, checkout, account and a relocated page' );

$sql = str_replace( '\\_', '_', (string) $state['sql'] );   // undo esc_like() escaping
foreach ( [ '[woocommerce_cart', '[woocommerce_checkout', '[woocommerce_my_account', 'wp:woocommerce/cart', 'wp:woocommerce/checkout' ] as $token ) {
	ok( false !== strpos( $sql, $token ), 'content scan looks for ' . $token );
}
ok( false !== strpos( $sql, "post_type = 'page'" ), 'content scan only looks at pages' );

$flags = $state['excluded_from_markdown'];
foreach ( [ 9, 10, 11, 12 ] as $id ) {
	ok( true === $flags[ (string) $id ], 'transactional page ' . $id . ' is not convertible' );
}
ok( false === $flags['21'], 'product page is convertible' );
ok( true === $flags['40'], 'password-protected page is not convertible' );
ok( true === $flags['41'], 'non-viewable post type is not convertible' );
ok( true === $flags['42'], 'non-published object is not convertible' );

same( [ 21 ], $state['listed_ids'], 'cached-ID listing hides excluded pages' );
same( false, $state['convert_result'], 'convert_post refuses the checkout page' );
ok( false === $state['md_exists'], 'convert_post deletes the stale .md' );
ok( false === $state['sidecar_exists'], 'convert_post deletes the stale sidecar' );

foreach ( [ 9, 10, 11, 12, 30 ] as $id ) {
	ok( ! in_array( $id, $state['backfill_queue'], true ), 'backfill queue excludes page ' . $id );
}
ok( in_array( 21, $state['backfill_queue'], true ), 'backfill queue still contains the product page' );

ok( true === $state['llms']['has_note'], 'Woo llms.txt states the session-only exception' );
ok( true === $state['llms']['has_claim'], 'Woo llms.txt still publishes the claim when working' );
ok( $state['llms']['size'] > 1161, 'Woo llms.txt is longer than the generic claim', (string) $state['llms']['size'] );

// ---------------------------------------------------------------------------
group( 'upgrade 0.1.13 -> 0.1.14: remove variants for excluded objects' );
// ---------------------------------------------------------------------------

$up = run_state_case( [
	'woo_pages'   => $woo_pages,
	'posts'       => [ 9 => [], 10 => [], 11 => [], 12 => [], 21 => [ 'post_type' => 'product' ] ],
	'upgrade_md'  => [ 9 => 20, 10 => 20, 11 => 40, 12 => 30, 21 => 90 ],
] );
if ( isset( $up['error'] ) ) {
	ok( false, 'upgrade state_case ran', (string) $up['error'] );
} else {
	$removed_order = $up['upgrade']['removed'];
	sort( $removed_order );
	same( [ 9, 10, 11, 12 ], $removed_order, 'upgrade removes cart, checkout, account and shop variants' );
	same( [ 21 ], $up['upgrade']['raw_md_ids'], 'upgrade leaves the product variant on disk' );
	same( [ 55 ], $up['upgrade']['manifest']['failed_ids'], 'upgrade drops excluded IDs from failed_ids' );
	same( 1, $up['upgrade']['manifest']['post_count'], 'upgrade recounts post_count' );
	$removed_ids = $up['upgrade']['option']['removed_ids'];
	sort( $removed_ids );
	same( [ 9, 10, 11, 12 ], $removed_ids, 'upgrade records what it removed' );
	$excluded_ids = $up['upgrade']['option']['excluded_ids'];
	sort( $excluded_ids );
	same( [ 9, 10, 11, 12 ], $excluded_ids, 'upgrade records the exclusion set' );
}

$up = run_state_case( [
	'posts'      => [ 9 => [], 10 => [] ],
	'upgrade_md' => [ 9 => 20, 10 => 20 ],
] );
if ( isset( $up['error'] ) ) {
	ok( false, 'non-Woo upgrade state_case ran', (string) $up['error'] );
} else {
	same( [], $up['upgrade']['removed'], 'non-Woo upgrade removes nothing' );
	same( [ 9, 10 ], $up['upgrade']['raw_md_ids'], 'non-Woo upgrade leaves every variant in place' );
}


// ---------------------------------------------------------------------------
group( 'self-test: negative probes on transactional URLs' );
// ---------------------------------------------------------------------------

$woo_base = [
	'woo_pages' => $woo_pages,
	'posts'     => [
		9  => [],
		10 => [ 'permalink' => 'https://example.test/cart/' ],
		11 => [ 'permalink' => 'https://example.test/checkout/' ],
		12 => [ 'permalink' => 'https://example.test/my-account/' ],
		21 => [ 'post_type' => 'product', 'permalink' => 'https://example.test/product/thing/' ],
	],
	'list_md'   => [ 21 => 90 ],
	'selftest'  => true,
];

// Exclusions broken: the cart answers markdown.
$broken = $woo_base;
$broken['http'] = [
	'https://example.test/product/thing/|'          => 'text/html',
	'https://example.test/product/thing/|gzip'      => 'text/markdown',
	'https://example.test/product/thing/|identity'  => 'text/markdown',
	'https://example.test/cart/|gzip'               => 'text/markdown',
	'https://example.test/cart/|identity'           => 'text/markdown',
	'https://example.test/checkout/|gzip'           => 'text/html',
	'https://example.test/checkout/|identity'       => 'text/html',
	'https://example.test/my-account/|gzip'         => 'text/html',
	'https://example.test/my-account/|identity'     => 'text/html',
	'https://example.test/my-account/orders/|gzip'  => 'text/html',
	'https://example.test/my-account/orders/|identity' => 'text/html',
];
$r = run_state_case( $broken );
if ( isset( $r['error'] ) ) {
	ok( false, 'self-test (exclusion broken) ran', (string) $r['error'] );
} else {
	same( [ 'https://example.test/product/thing/' ], $r['test_urls'], 'positive probes skip the transactional pages' );
	same( 4, count( $r['excluded_urls'] ), 'negative probes cover cart, checkout, account and one account endpoint' );
	same( 'blocked', $r['selftest']['status'], 'markdown on a transactional URL reports blocked' );
	ok( false !== strpos( $r['selftest']['detail'], 'Excluded URL probes returned markdown' ), 'blocked detail names the excluded-URL failure' );
}

// Exclusion working: transactional URLs answer HTML.
$good = $woo_base;
$good['http'] = [
	'https://example.test/product/thing/|'             => 'text/html',
	'https://example.test/product/thing/|gzip'         => 'text/markdown',
	'https://example.test/product/thing/|identity'     => 'text/markdown',
	'https://example.test/cart/|gzip'                  => 'text/html',
	'https://example.test/cart/|identity'              => 'text/html',
	'https://example.test/checkout/|gzip'              => 'text/html',
	'https://example.test/checkout/|identity'          => 'text/html',
	'https://example.test/my-account/|gzip'            => 'text/html',
	'https://example.test/my-account/|identity'        => 'text/html',
	'https://example.test/my-account/orders/|gzip'     => 'text/html',
	'https://example.test/my-account/orders/|identity' => 'text/html',
];
$r = run_state_case( $good );
if ( isset( $r['error'] ) ) {
	ok( false, 'self-test (exclusion working) ran', (string) $r['error'] );
} else {
	same( 'working', $r['selftest']['status'], 'exclusion in place keeps the self-test working' );
	ok( false !== strpos( $r['selftest']['detail'], 'excluded URL probe(s)' ), 'working detail reports the negative probes' );
}

// Non-Woo: no negative probes at all.
$r = run_state_case( [
	'posts'    => [ 21 => [ 'permalink' => 'https://example.test/post-21/' ] ],
	'list_md'  => [ 21 => 90 ],
	'selftest' => true,
	'http'     => [
		'https://example.test/post-21/|'          => 'text/html',
		'https://example.test/post-21/|gzip'      => 'text/markdown',
		'https://example.test/post-21/|identity'  => 'text/markdown',
	],
] );
if ( isset( $r['error'] ) ) {
	ok( false, 'self-test (non-Woo) ran', (string) $r['error'] );
} else {
	same( [], $r['excluded_urls'], 'non-Woo site has no negative probes' );
	same( 'working', $r['selftest']['status'], 'non-Woo self-test still reports working' );
	same( 'All 2 probe(s) returned text/markdown.', $r['selftest']['detail'], 'non-Woo detail is unchanged from 0.1.13' );
}

// ---------------------------------------------------------------------------
group( '/mdf.json: free-only capability document' );
// ---------------------------------------------------------------------------

$schema = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/mdf.schema.json' ), true );
ok( is_array( $schema ) && isset( $schema['$defs'] ), 'the v0.3.1 schema fixture loads' );
same( 'https://raw.githubusercontent.com/bitcryptic-gw/mdf/main/mdf.schema.json', $schema['$id'] ?? '', 'fixture is the schema this plugin declares against' );

$mj = run_state_case( [ 'mdfjson' => true ] );
if ( isset( $mj['error'] ) ) {
	ok( false, 'mdf.json state_case ran', (string) $mj['error'] );
} else {
	$doc = $mj['mdfjson']['document'];

	same( '0.3.1', $doc['mdf_version'], 'document declares MDF 0.3.1' );
	same( 'https://example.test', $doc['site'], 'site is the WordPress canonical base URL' );
	same( 'Test Site', $doc['name'], 'name comes from the site title' );
	same( [], $doc['pricing']['default']['rails'], 'free everywhere: the default rail list is empty' );
	ok( ! isset( $doc['pricing']['sections'] ), 'no priced sections' );
	ok( ! isset( $doc['auth'] ), 'no auth block on a free site' );
	ok( ! isset( $doc['feed'] ), 'no feed block (MDF change metadata is not implemented)' );
	ok( ! isset( $doc['content_signals'] ), 'no content signals are asserted on the owner behalf' );
	same( 'commonmark', $doc['formats']['dialect'], 'formats.dialect matches the vendored converter' );
	same( 'https://example.test/llms.txt', $doc['llms_txt'], 'llms.txt is advertised' );

	same( [], $mj['mdfjson']['schema_errors'], 'document validates against the MDF 0.3.1 schema' );
	foreach ( (array) $mj['mdfjson']['schema_example_errors'] as $i => $errors ) {
		same( [], $errors, 'schema example ' . $i . ' validates (validator is not a no-op)' );
	}

	// Gating matrix.
	$reasons = $mj['mdfjson']['block_reasons'];
	same( 'offering_disabled', $reasons['offering_off'], 'offering off withholds the document' );
	same( '', $reasons['working'], 'working publishes the document' );
	same( 'negotiation_blocked', $reasons['blocked'], 'blocked withholds the document' );
	same( 'negotiation_unconfirmed', $reasons['unknown'], 'unknown withholds the document' );
	same( '', $reasons['unknown_with_owner'], 'the owner override publishes the document' );
	same( 'negotiation_blocked', $reasons['blocked_with_owner'], 'a blocked result beats the owner override' );

	same( true, $mj['mdfjson']['static_detected'], 'a web-root mdf.json is detected' );
	same( true, $mj['mdfjson']['static_cleared'], 'detection clears when the file goes away' );
	same( 1780000000, $mj['mdfjson']['mtime'], 'Last-Modified follows the last self-test' );
}

// Negative controls: the validator rejects a document the plugin must not emit.
$bad = $mj['mdfjson']['document'];
unset( $bad['pricing'] );
ok( mdf_schema_validate( $bad, $schema ) !== [], 'schema rejects a document with no pricing' );

$bad = $mj['mdfjson']['document'];
$bad['pricing']['default']['rails'] = [ [ 'scheme' => 'exact', 'network' => 'eip155:8453', 'asset' => 'x', 'amount' => '0' ] ];
ok( mdf_schema_validate( $bad, $schema ) !== [], 'schema rejects a zero-priced rail' );

$bad = $mj['mdfjson']['document'];
$bad['invented'] = true;
ok( mdf_schema_validate( $bad, $schema ) !== [], 'schema rejects an invented top-level field' );

// ---------------------------------------------------------------------------
group( '/mdf.json: served, gated, cached, HEAD, 304' );
// ---------------------------------------------------------------------------

$published = [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'working' ];

$case = run_serve_case( $published );
same( 200, $case['status'], 'published document answers 200' );
same( 'application/json; charset=utf-8', header_value( $case['headers'], 'Content-Type' ), 'serves application/json' );
same( 'nosniff', header_value( $case['headers'], 'X-Content-Type-Options' ), 'serves nosniff' );
same( 'public, max-age=3600', header_value( $case['headers'], 'Cache-Control' ), 'one-hour public cache, same as llms.txt' );
same( 'Thu, 28 May 2026 20:26:40 GMT', header_value( $case['headers'], 'Last-Modified' ), 'Last-Modified follows the self-test timestamp' );

$decoded = json_decode( $case['body'], true );
ok( is_array( $decoded ), 'body is JSON', substr( $case['body'], 0, 120 ) );
same( '0.3.1', $decoded['mdf_version'] ?? null, 'served body declares 0.3.1' );
same( (string) strlen( $case['body'] ), header_value( $case['headers'], 'Content-Length' ), 'Content-Length matches the body' );
same( 'https://example.test', $decoded['site'] ?? null, 'served site matches the fixture site' );

$case = run_serve_case( [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'working', 'method' => 'HEAD' ] );
same( 200, $case['status'], 'HEAD gets the document headers' );
same( '', $case['body'], 'HEAD gets no body' );

$case = run_serve_case( [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'working', 'if_modified_since' => 'Thu, 28 May 2026 20:26:40 GMT' ] );
same( 304, $case['status'], 'If-Modified-Since at or after Last-Modified answers 304' );
same( '', $case['body'], '304 has no body' );

$case = run_serve_case( [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'working', 'if_modified_since' => 'Wed, 27 May 2026 20:26:40 GMT' ] );
same( 200, $case['status'], 'an older If-Modified-Since still gets the document' );

// Every withheld state answers 404, and never leaks the document.
foreach ( [
	'offering off'                => [ 'path' => '/mdf.json', 'offer' => false, 'negotiation' => 'working' ],
	'blocked'                     => [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'blocked' ],
	'unknown, no override'        => [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'unknown' ],
	'blocked, override present'   => [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'blocked', 'owner_confirmed' => true ],
] as $label => $spec ) {
	$case = run_serve_case( $spec );
	same( 404, $case['status'], $label . ': answers 404' );
	same( 'application/json; charset=utf-8', header_value( $case['headers'], 'Content-Type' ), $label . ': 404 is JSON' );
	same( 'no-store', header_value( $case['headers'], 'Cache-Control' ), $label . ': 404 is not cached' );
	$body = json_decode( $case['body'], true );
	same( 'unavailable', $body['error'] ?? null, $label . ': names the state' );
	ok( ! isset( $body['mdf_version'] ), $label . ': the capability document is not leaked' );
}

$case = run_serve_case( [ 'path' => '/mdf.json', 'offer' => true, 'negotiation' => 'unknown', 'owner_confirmed' => true ] );
same( 200, $case['status'], 'unknown with the owner override publishes the document' );

// /llms.txt keeps working through the shared responder.
$case = run_serve_case( [ 'path' => '/llms.txt', 'offer' => true, 'negotiation' => 'working' ] );
same( 200, $case['status'], 'llms.txt still answers 200' );
same( 'text/plain; charset=utf-8', header_value( $case['headers'], 'Content-Type' ), 'llms.txt is still text/plain' );
same( 'nosniff', header_value( $case['headers'], 'X-Content-Type-Options' ), 'llms.txt still sends nosniff' );
same( '1161', header_value( $case['headers'], 'Content-Length' ), 'llms.txt is still 1161 B on a non-Woo site' );

$case = run_serve_case( [ 'path' => '/llms.txt', 'offer' => true, 'negotiation' => 'working', 'method' => 'HEAD' ] );
same( 200, $case['status'], 'llms.txt HEAD works' );
same( '', $case['body'], 'llms.txt HEAD has no body' );

// ---------------------------------------------------------------------------
group( 'X-MDF-Version: protocol identity on the markdown response and its HTML' );
// ---------------------------------------------------------------------------

$case = run_serve_case( [
	'queried_object_id' => 5,
	'is_singular'       => true,
	'offer'             => true,
	'negotiation'       => 'working',
	'posts'             => [ 5 => [] ],
	'md'                => [ 5 => 120 ],
] );
same( '1', header_value( $case['headers'], 'X-MDF-Version' ), 'markdown response carries X-MDF-Version: 1' );

$case = run_serve_case( [
	'queried_object_id' => 5,
	'is_singular'       => true,
	'offer'             => true,
	'negotiation'       => 'unknown',
	'posts'             => [ 5 => [] ],
	'md'                => [ 5 => 120 ],
] );
same( '', header_value( $case['headers'], 'X-MDF-Version' ), 'an unclaimed site does not advertise the protocol family' );
same( 'text/markdown; charset=utf-8', header_value( $case['headers'], 'Content-Type' ), 'markdown is still served while unconfirmed' );

$case = run_serve_case( [
	'queried_object_id' => 5,
	'is_singular'       => true,
	'offer'             => true,
	'negotiation'       => 'working',
	'accept'            => 'text/html',
	'html_headers'      => true,
	'posts'             => [ 5 => [] ],
	'md'                => [ 5 => 120 ],
] );
same( '1', header_value( $case['headers'], 'X-MDF-Version' ), 'HTML at a URL with a variant carries X-MDF-Version' );
same( 'HTML-FALLBACK', $case['body'], 'the HTML itself is unchanged' );

$case = run_serve_case( [
	'queried_object_id' => 5,
	'is_singular'       => true,
	'offer'             => true,
	'negotiation'       => 'working',
	'accept'            => 'text/html',
	'html_headers'      => true,
	'posts'             => [ 5 => [] ],
] );
same( '', header_value( $case['headers'], 'X-MDF-Version' ), 'HTML with no variant carries no protocol header' );

$case = run_serve_case( [
	'queried_object_id' => 9,
	'is_singular'       => true,
	'offer'             => true,
	'negotiation'       => 'working',
	'accept'            => 'text/html',
	'html_headers'      => true,
	'woo_pages'         => $woo_pages,
	'posts'             => [ 9 => [] ],
	'md'                => [ 9 => 40 ],
] );
same( '', header_value( $case['headers'], 'X-MDF-Version' ), 'an excluded page (shop) carries no protocol header' );

// ---------------------------------------------------------------------------
group( 'activation: one delayed re-test only for blocked + adapter just registered' );
// ---------------------------------------------------------------------------

$act = [
	'activate' => true,
	'wpsc'     => true,
	'posts'    => [],
	'http'     => [],
];

// blocked, adapter registered by this activation → schedule once.
$spec = $act;
$spec['posts'] = [ 21 => [ 'permalink' => 'https://example.test/post-21/' ] ];
$spec['md']    = [ 21 => 90 ];
$spec['http']  = [ 'https://example.test/post-21/' => 'text/html' ];
$r = run_state_case( $spec );
if ( isset( $r['error'] ) ) {
	ok( false, 'activation (blocked, adapter registered now) ran', (string) $r['error'] );
} else {
	$a = $r['activation'];
	same( 'blocked', $a['status'], 'blocked is still reported blocked' );
	same( true, $a['adapter_registered'], 'the adapter was registered during activation' );
	same( true, $a['delayed_scheduled'], 'one delayed re-test is scheduled' );
	ok( $a['delayed_scheduled_time'] >= time() + 55, 'the re-test is about a minute out' );
	same( true, $a['second_call_same_event'], 'a second scheduling call does not add another event' );
}

// working, adapter registered by this activation → nothing scheduled.
$spec = $act;
$spec['posts'] = [ 21 => [ 'permalink' => 'https://example.test/post-21/' ] ];
$spec['md']    = [ 21 => 90 ];
$spec['http']  = [ 'https://example.test/post-21/' => 'text/markdown' ];
$r = run_state_case( $spec );
if ( isset( $r['error'] ) ) {
	ok( false, 'activation (working) ran', (string) $r['error'] );
} else {
	same( 'working', $r['activation']['status'], 'a working activation stays working' );
	same( false, $r['activation']['delayed_scheduled'], 'nothing is scheduled when the result is working' );
}

// blocked, adapter already registered before activation → nothing scheduled.
$spec = $act;
$spec['wpsc_adapter_present'] = true;
$spec['posts'] = [ 21 => [ 'permalink' => 'https://example.test/post-21/' ] ];
$spec['md']    = [ 21 => 90 ];
$spec['http']  = [ 'https://example.test/post-21/' => 'text/html' ];
$r = run_state_case( $spec );
if ( isset( $r['error'] ) ) {
	ok( false, 'activation (blocked, adapter already registered) ran', (string) $r['error'] );
} else {
	same( 'blocked', $r['activation']['status'], 'a pre-registered adapter does not mask a real block' );
	same( false, $r['activation']['delayed_scheduled'], 'no re-test when this activation did not register the adapter' );
}

// blocked, no WP Super Cache at all → nothing scheduled.
$spec = $act;
$spec['wpsc']  = false;
$spec['posts'] = [ 21 => [ 'permalink' => 'https://example.test/post-21/' ] ];
$spec['md']    = [ 21 => 90 ];
$spec['http']  = [ 'https://example.test/post-21/' => 'text/html' ];
$r = run_state_case( $spec );
if ( isset( $r['error'] ) ) {
	ok( false, 'activation (blocked, no WP Super Cache) ran', (string) $r['error'] );
} else {
	same( false, $r['activation']['delayed_scheduled'], 'no re-test without WP Super Cache' );
}

// ---------------------------------------------------------------------------
group( 'admin output: no retired Phase copy, and the new links are present' );
// ---------------------------------------------------------------------------

$admin = run_state_case( [ 'admin' => true ] );
if ( isset( $admin['error'] ) ) {
	ok( false, 'admin render ran', (string) $admin['error'] );
} else {
	$html = $admin['admin']['html'];

	same( [], $admin['admin']['errors'], 'settings and dashboard render without error' );
	ok( strlen( $admin['admin']['settings_len'] ) > 0 && $admin['admin']['settings_len'] > 2000, 'settings page rendered', (string) $admin['admin']['settings_len'] );
	ok( $admin['admin']['dashboard_len'] > 1000, 'dashboard rendered', (string) $admin['admin']['dashboard_len'] );

	foreach ( [ 'Phase 1', 'Phase 2', 'Phase 3', 'coming soon', 'oracle', 'L402', 'wallet' ] as $retired ) {
		ok( false === stripos( $html, $retired ), 'admin output has no "' . $retired . '"' );
	}

	ok( false !== strpos( $html, 'markdownfirst.com' ), 'admin output links markdownfirst.com' );
	ok( false !== strpos( $html, 'bitcryptic.com' ), 'admin output links BitCryptic' );
	ok( false !== strpos( $html, 'slepner.com.au' ), 'admin output links Slepner' );
	ok( false !== strpos( $html, 'github.com/bitcryptic-gw/mdf-analytics-wp' ), 'admin output links the plugin README' );
	ok( false !== strpos( $html, 'Capability document' ), 'settings states the /mdf.json state' );
	ok( false !== strpos( $html, 'not earnings' ), 'dashboard says the cards are estimates' );
}

exit( summary() );
