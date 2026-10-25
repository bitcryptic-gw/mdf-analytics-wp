<?php
/**
 * mdf-analytics-wp test suite — stub harness, no PHPUnit, no WordPress.
 *
 * Run: php tests/unit.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
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

// ---------------------------------------------------------------------------
group( 'serve: singular objects only, non-empty variants only' );
// ---------------------------------------------------------------------------

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'md' => [ 5 => 120 ] ] );
same( 200, $case['status'], 'singular page with a variant is served' );
same( 'text/markdown; charset=utf-8', header_value( $case['headers'], 'Content-Type' ), 'serves Content-Type: text/markdown' );
same( 'Accept', header_value( $case['headers'], 'Vary' ), 'serves Vary: Accept' );
same( 120, strlen( $case['body'] ), 'serves the variant body' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'md' => [ 5 => 0 ] ] );
falls_through( $case, 'zero-byte variant' );

$case = run_serve_case( [
	'queried_object_id' => 1,   // a term id that collides with post 1
	'is_singular'       => false,
	'offer'             => true,
	'md'                => [ 1 => 85 ],
] );
falls_through( $case, 'archive (non-singular), term id colliding with a cached post' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => false, 'md' => [ 5 => 120 ] ] );
falls_through( $case, 'offering off' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'accept' => 'text/html', 'md' => [ 5 => 120 ] ] );
falls_through( $case, 'browser request' );

$case = run_serve_case( [ 'queried_object_id' => 5, 'is_singular' => true, 'offer' => true, 'method' => 'HEAD', 'md' => [ 5 => 120 ] ] );
same( 200, $case['status'], 'HEAD gets the markdown headers' );
same( '', $case['body'], 'HEAD gets no body' );

exit( summary() );
