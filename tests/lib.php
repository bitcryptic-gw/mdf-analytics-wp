<?php
/**
 * Assertion helpers + built-in-server lifecycle for the stub harness
 * (no PHPUnit dependency, no WordPress).
 */

$GLOBALS['mdf_test_assert'] = [ 'pass' => 0, 'fail' => 0, 'failures' => [] ];
$GLOBALS['mdf_test_server'] = null;

function ok( bool $condition, string $label, string $detail = '' ): void {
	if ( $condition ) {
		$GLOBALS['mdf_test_assert']['pass']++;
		echo "  ok   {$label}\n";
		return;
	}
	$GLOBALS['mdf_test_assert']['fail']++;
	$GLOBALS['mdf_test_assert']['failures'][] = $label . ( $detail !== '' ? ' — ' . $detail : '' );
	echo "  FAIL {$label}" . ( $detail !== '' ? " — {$detail}" : '' ) . "\n";
}

function same( $expected, $actual, string $label ): void {
	ok(
		$expected === $actual,
		$label,
		'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true )
	);
}

function group( string $name ): void {
	echo "\n[{$name}]\n";
}

function summary(): int {
	$a = $GLOBALS['mdf_test_assert'];
	echo "\n" . str_repeat( '-', 60 ) . "\n";
	echo "PASS {$a['pass']}  FAIL {$a['fail']}\n";
	if ( $a['fail'] > 0 ) {
		echo "Failures:\n";
		foreach ( $a['failures'] as $f ) { echo "  - {$f}\n"; }
		return 1;
	}
	return 0;
}

// ---------------------------------------------------------------------------
// PHP built-in server: mdf_maybe_serve_markdown() exits when it serves, and
// headers are only observable over real HTTP.
// ---------------------------------------------------------------------------

function mdf_test_server_start(): int {
	if ( $GLOBALS['mdf_test_server'] !== null ) {
		return $GLOBALS['mdf_test_server']['port'];
	}

	$sock = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	if ( ! $sock ) {
		fwrite( STDERR, "cannot allocate a port: {$errstr}\n" );
		exit( 2 );
	}
	$name = stream_socket_get_name( $sock, false );
	fclose( $sock );
	$port = (int) substr( strrchr( $name, ':' ), 1 );

	$log = sys_get_temp_dir() . '/mdf-test-server.log';
	$cmd = escapeshellarg( PHP_BINARY ) . ' -S 127.0.0.1:' . $port . ' '
		. escapeshellarg( __DIR__ . '/router.php' ) . ' > ' . escapeshellarg( $log ) . ' 2>&1';
	$proc = proc_open( $cmd, [], $pipes );
	if ( ! is_resource( $proc ) ) {
		fwrite( STDERR, "cannot start the test server\n" );
		exit( 2 );
	}
	$GLOBALS['mdf_test_server'] = [ 'proc' => $proc, 'port' => $port, 'log' => $log ];

	for ( $i = 0; $i < 100; $i++ ) {
		$probe = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.1 );
		if ( $probe ) { fclose( $probe ); return $port; }
		usleep( 50000 );
	}
	fwrite( STDERR, "test server did not start; see {$log}\n" );
	exit( 2 );
}

function mdf_test_server_stop(): void {
	$server = $GLOBALS['mdf_test_server'];
	if ( $server === null ) return;
	$status = proc_get_status( $server['proc'] );
	if ( ! empty( $status['pid'] ) ) {
		// The shell wraps php, so kill the whole process group.
		exec( 'pkill -f ' . escapeshellarg( '127.0.0.1:' . $server['port'] ) . ' 2>/dev/null' );
	}
	proc_close( $server['proc'] );
	@unlink( $server['log'] );
	$GLOBALS['mdf_test_server'] = null;
}
register_shutdown_function( 'mdf_test_server_stop' );

/**
 * Issue one request against a serve-case spec.
 *
 * @return array{body:string,status:int,headers:string[]}
 */
function run_serve_case( array $spec ): array {
	$port = mdf_test_server_start();

	$spec_file = tempnam( sys_get_temp_dir(), 'mdfspec' );
	file_put_contents( $spec_file, json_encode( $spec ) );

	$headers_file = tempnam( sys_get_temp_dir(), 'mdfhdr' );
	$body_file    = tempnam( sys_get_temp_dir(), 'mdfbody' );

	$method    = strtoupper( $spec['method'] ?? 'GET' );
	$accept    = $spec['accept'] ?? 'text/markdown';
	$url       = 'http://127.0.0.1:' . $port . '/?spec=' . rawurlencode( $spec_file );
	$cmd = 'curl -s -X ' . escapeshellarg( $method )
		. ' -H ' . escapeshellarg( 'Accept: ' . $accept )
		. ' -D ' . escapeshellarg( $headers_file )
		. ' -o ' . escapeshellarg( $body_file )
		. ' ' . escapeshellarg( $url );
	exec( $cmd );

	$raw_headers = (string) file_get_contents( $headers_file );
	$body        = (string) file_get_contents( $body_file );
	$lines       = preg_split( '/\r?\n/', trim( $raw_headers ) );
	$status      = 0;
	if ( isset( $lines[0] ) && preg_match( '#^HTTP/\S+\s+(\d{3})#', $lines[0], $m ) ) {
		$status = (int) $m[1];
	}
	$headers = array_values( array_filter( array_slice( $lines, 1 ) ) );

	@unlink( $spec_file );
	@unlink( $headers_file );
	@unlink( $body_file );

	return [ 'body' => $body, 'status' => $status, 'headers' => $headers ];
}

function header_value( array $headers, string $name ): string {
	foreach ( $headers as $line ) {
		if ( 0 === stripos( $line, $name . ':' ) ) {
			return trim( substr( $line, strlen( $name ) + 1 ) );
		}
	}
	return '';
}
