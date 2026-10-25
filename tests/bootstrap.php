<?php
/**
 * Minimal WordPress stub harness for testing mdf-analytics-wp without a
 * WordPress install. Loads the real plugin file and exercises its pure logic
 * (option and post lookups are backed by in-memory fixtures).
 *
 * Deliberately not PHPUnit: the plugin has no composer dev dependencies and
 * ships to production without dev tooling, so the tests must run with a bare
 * `php tests/unit.php`.
 */

define( 'ABSPATH', '/tmp/mdf-test-abspath/' );
define( 'WP_CONTENT_DIR', '/tmp/mdf-test-content' );
define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

// ---------------------------------------------------------------------------
// Fixture state
// ---------------------------------------------------------------------------

$GLOBALS['mdf_test'] = [
	'options'    => [],
	'posts'      => [],
	'woo_active' => false,
	'woo_pages'  => [],
	'woo_flags'  => [],
	'actions'    => [],
	'filters'    => [],
	'upload_dir' => '/tmp/mdf-test-uploads',
	'wpdb_like'  => [],
	'wpdb_sql'   => [],
	'http'       => [],
	'headers'    => [],
	'current_user' => 0,
	'hidden_types' => [ 'hidden' ],
	'scheduled' => [],
];

function mdf_test_reset(): void {
	$GLOBALS['mdf_test'] = [
		'options'    => [],
		'posts'      => [],
		'woo_active' => false,
		'woo_pages'  => [],
		'woo_flags'  => [],
		'actions'    => [],
		'filters'    => [],
		'upload_dir' => '/tmp/mdf-test-uploads',
		'wpdb_like'  => [],
		'wpdb_sql'   => [],
		'http'       => [],
		'headers'    => [],
		'current_user' => 0,
		'hidden_types' => [ 'hidden' ],
		'scheduled' => [],
	];
	if ( is_dir( '/tmp/mdf-test-uploads' ) ) {		mdf_test_rmdir( '/tmp/mdf-test-uploads' );
	}
	mkdir( '/tmp/mdf-test-uploads', 0777, true );
}

function mdf_test_rmdir( string $dir ): void {
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( $entry === '.' || $entry === '..' ) continue;
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) { mdf_test_rmdir( $path ); } else { unlink( $path ); }
	}
	rmdir( $dir );
}

// ---------------------------------------------------------------------------
// WordPress API stubs
// ---------------------------------------------------------------------------

function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['mdf_test']['actions'][ $hook ][] = $callback;
	return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) {
	$GLOBALS['mdf_test']['filters'][ $hook ][] = $callback;
	return true;
}
function remove_filter( $hook, $callback ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook ) {}
function register_activation_hook( $file, $cb ) { $GLOBALS['mdf_test']['actions']['activate'][] = $cb; }
function register_deactivation_hook( $file, $cb ) { $GLOBALS['mdf_test']['actions']['deactivate'][] = $cb; }
function register_uninstall_hook( $file, $cb ) { $GLOBALS['mdf_test']['actions']['uninstall'][] = $cb; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['mdf_test']['options'] )
		? $GLOBALS['mdf_test']['options'][ $name ]
		: $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['mdf_test']['options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['mdf_test']['options'][ $name ] );
	return true;
}

function get_post( $id ) {
	$id = (int) $id;
	return $GLOBALS['mdf_test']['posts'][ $id ] ?? null;
}
function get_posts( $args = [] ) {
	$ids = array_keys( $GLOBALS['mdf_test']['posts'] );
	sort( $ids );
	return $ids;
}
function get_post_types( $args = [], $output = 'names' ) {
	return [ 'post' => 'post', 'page' => 'page' ];
}
function wp_is_post_revision( $post ) { return false; }
function is_post_type_viewable( $type ) {
	return ! in_array( $type, $GLOBALS['mdf_test']['hidden_types'] ?? [], true );
}
function get_permalink( $id ) {
	$post = get_post( $id );
	return $post ? 'https://example.test/?p=' . (int) $id : false;
}
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function wp_upload_dir() { return [ 'basedir' => $GLOBALS['mdf_test']['upload_dir'], 'baseurl' => 'https://example.test/uploads' ]; }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0775, true ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function wp_unslash( $value ) { return $value; }
function esc_html( $text ) { return htmlspecialchars( (string) $text ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text ); }
function esc_url( $url ) { return $url; }
function absint( $value ) { return abs( (int) $value ); }
function status_header( $code ) {
	$GLOBALS['mdf_test']['status'] = $code;
	if ( 'cli' !== PHP_SAPI ) {
		http_response_code( (int) $code );
	}
}
function current_user_can( $cap ) { return $GLOBALS['mdf_test']['current_user'] > 0; }
function wp_set_current_user( $id ) { $GLOBALS['mdf_test']['current_user'] = (int) $id; }
function get_users( $args = [] ) { return [ 1 ]; }
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function wp_verify_nonce( $nonce, $action = -1 ) { return 1; }
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) { return true; }
function wp_nonce_field( $action = -1, $name = '_wpnonce' ) {}
function checked( $checked, $current = true, $echo = true ) { return ''; }
function selected( $selected, $current = true, $echo = true ) { return ''; }
function wp_next_scheduled( $hook, $args = [] ) {
	return empty( $GLOBALS['mdf_test']['scheduled'][ $hook ] ) ? false : time() + 3;
}
function wp_schedule_single_event( $time, $hook, $args = [] ) {
	$GLOBALS['mdf_test']['scheduled'][ $hook ] = true;
	return true;
}
function wp_schedule_event( $time, $recurrence, $hook ) { return true; }
function wp_clear_scheduled_hook( $hook, $args = [] ) { return true; }
function wp_doing_cron() { return false; }
function is_admin() { return false; }
function get_bloginfo( $key = '' ) { return 'Test Site'; }
function add_query_arg( ...$args ) { return 'https://example.test/?x=1'; }
function size_format( $bytes ) { return $bytes . ' B'; }
function number_format_i18n( $number, $decimals = 0 ) { return number_format( (float) $number, $decimals ); }
function wp_date( $format, $timestamp = null ) { return gmdate( $format, $timestamp ?? time() ); }
function human_time_diff( $from, $to = 0 ) { return '1 min'; }
function __( $text, $domain = '' ) { return $text; }
function _e( $text, $domain = '' ) { return null; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_attr__( $text, $domain = '' ) { return $text; }
function wp_kses_post( $html ) { return $html; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
function sanitize_title( $title ) { return strtolower( preg_replace( '/[^a-z0-9\-]/i', '-', (string) $title ) ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function plugin_dir_path( $file ) { return rtrim( dirname( $file ), '/\\' ) . '/'; }

#[AllowDynamicProperties]
class WP_Post {
	public $ID                = 0;
	public $post_status       = 'publish';
	public $post_password     = '';
	public $post_type         = 'page';
	public $post_title        = '';
	public $post_content      = '';
	public $post_modified_gmt = '2026-10-01 00:00:00';
}

class WP_Error {	private $code;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return ''; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['mdf_test']['http'][] = [ 'url' => $url, 'args' => $args ];
	$key = $url . '|' . ( $args['headers']['Accept-Encoding'] ?? '' );
	if ( isset( $GLOBALS['mdf_test']['http_queue'][ $key ] ) ) {
		return array_shift( $GLOBALS['mdf_test']['http_queue'][ $key ] );
	}
	return new WP_Error( 'http_request_failed' );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
function wp_remote_retrieve_header( $response, $header ) {
	$headers = $response['headers'] ?? [];
	foreach ( $headers as $name => $value ) {
		if ( strtolower( $name ) === strtolower( $header ) ) return $value;
	}
	return '';
}
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function add_cacheaction( $action, $callback ) { $GLOBALS['mdf_test']['cacheactions'][] = [ $action, $callback ]; }
function wpsc_get_plugins() { return $GLOBALS['mdf_test']['wpsc_plugins'] ?? []; }
function wpsc_add_plugin( $file ) { $GLOBALS['mdf_test']['wpsc_plugins'][] = $file; return $file; }
function wpsc_delete_plugin( $file ) { return $file; }

// WooCommerce stubs (only defined when a test turns them on, so the non-Woo
// path is exercised by their absence).
function mdf_test_enable_woo( array $pages = [], array $flags = [] ): void {
	$GLOBALS['mdf_test']['woo_active'] = true;
	$GLOBALS['mdf_test']['woo_pages']  = $pages;
	$GLOBALS['mdf_test']['woo_flags']  = $flags;

	if ( ! function_exists( 'wc_get_page_id' ) ) {
		eval( '
		function wc_get_page_id( $key ) { return $GLOBALS["mdf_test"]["woo_pages"][ $key ] ?? -1; }
		function is_cart() { return ! empty( $GLOBALS["mdf_test"]["woo_flags"]["cart"] ); }
		function is_checkout() { return ! empty( $GLOBALS["mdf_test"]["woo_flags"]["checkout"] ); }
		function is_account_page() { return ! empty( $GLOBALS["mdf_test"]["woo_flags"]["account"] ); }
		function is_wc_endpoint_url( $endpoint = false ) { return ! empty( $GLOBALS["mdf_test"]["woo_flags"]["endpoint"] ); }
		function wc_get_account_endpoint_url( $endpoint ) { return "https://example.test/my-account/" . $endpoint . "/"; }
		function is_shop() { return ! empty( $GLOBALS["mdf_test"]["woo_flags"]["shop"] ); }
		' );
	}
}

class MDF_Test_WPDB {
	public $posts   = 'wp_posts';
	public $prefix  = 'wp_';
	public $like_result = [];
	public $sql     = [];

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . str_replace( "'", "\\'", (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	public function get_col( $query ) {
		$this->sql[] = $query;
		$GLOBALS['mdf_test']['wpdb_sql'][] = $query;
		return $this->like_result;
	}
	public function get_var( $query ) { return 0; }
	public function query( $query ) { return 0; }
	public function get_results( $query ) { return []; }
}

$GLOBALS['wpdb'] = new MDF_Test_WPDB();

// ---------------------------------------------------------------------------
// Fixture helpers
// ---------------------------------------------------------------------------

function mdf_test_add_post( int $id, array $overrides = [] ): void {
	$post = new WP_Post();
	foreach ( array_merge( [
		'ID'                => $id,
		'post_status'       => 'publish',
		'post_password'     => '',
		'post_type'         => 'page',
		'post_title'        => 'Post ' . $id,
		'post_content'      => 'Body ' . $id,
		'post_modified_gmt' => '2026-10-01 00:00:00',
	], $overrides ) as $key => $value ) {
		$post->$key = $value;
	}
	$GLOBALS['mdf_test']['posts'][ $id ] = $post;
}

function mdf_test_write_md( int $id, string $contents = "hello\n" ): void {
	$dir = mdf_cache_posts_dir();
	if ( ! is_dir( $dir ) ) { mkdir( $dir, 0775, true ); }
	file_put_contents( $dir . '/' . $id . '.md', $contents );
}

function mdf_test_load_plugin(): void {
	require_once dirname( __DIR__ ) . '/mdf-analytics.php';
}

mdf_test_reset();
