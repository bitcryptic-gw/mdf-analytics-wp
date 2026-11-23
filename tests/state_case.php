<?php
/**
 * In-process state checks for the Woo exclusion, run in a fresh PHP process so
 * mdf_excluded_page_ids()'s per-request static cache starts empty.
 *
 * Usage: php state_case.php <spec.json>
 * Prints a single JSON object of results on stdout.
 */

require __DIR__ . '/bootstrap.php';

$spec = json_decode( (string) file_get_contents( $argv[1] ), true );

foreach ( ( $spec['posts'] ?? [] ) as $id => $fields ) {
	mdf_test_add_post( (int) $id, (array) $fields );
}

mdf_test_load_plugin();

mdf_test_enable_woo( (array) ( $spec['woo_pages'] ?? [] ), (array) ( $spec['woo_flags'] ?? [] ) );

$GLOBALS['wpdb']->like_result = (array) ( $spec['like_result'] ?? [] );

foreach ( ( $spec['md'] ?? [] ) as $id => $size ) {
	mdf_test_write_md( (int) $id, $size > 0 ? str_repeat( 'x', (int) $size ) : '' );
}

$out = [];

$excluded = mdf_excluded_page_ids();
sort( $excluded );
$out['excluded']  = $excluded;
$out['sql']       = (string) ( $GLOBALS['mdf_test']['wpdb_sql'][0] ?? '' );
$out['excluded_from_markdown'] = [];
foreach ( (array) ( $spec['probe_ids'] ?? [] ) as $id ) {
	$out['excluded_from_markdown'][ (string) $id ] = mdf_excluded_from_markdown( (int) $id );
}

if ( ! empty( $spec['list_md'] ) ) {
	foreach ( $spec['list_md'] as $id => $size ) {
		mdf_test_write_md( (int) $id, $size > 0 ? str_repeat( 'x', (int) $size ) : '' );
	}
	$ids = mdf_list_cached_post_ids();
	sort( $ids );
	$out['listed_ids'] = $ids;
}

if ( isset( $spec['convert_id'] ) ) {
	$id = (int) $spec['convert_id'];
	// Stale artefacts an earlier version would have left behind.
	mdf_test_write_md( $id, "stale\n" );
	file_put_contents( mdf_sidecar_path( $id ), "{}\n" );
	$out['convert_result'] = mdf_convert_post( $id );
	clearstatcache();
	$out['md_exists']      = file_exists( mdf_cache_posts_dir() . '/' . $id . '.md' );
	$out['sidecar_exists'] = file_exists( mdf_sidecar_path( $id ) );
}

if ( ! empty( $spec['backfill'] ) ) {
	mdf_start_backfill();
	$out['backfill_queue'] = array_values( (array) get_option( 'mdf_backfill_queue', [] ) );
	sort( $out['backfill_queue'] );
}

if ( ! empty( $spec['upgrade_md'] ) ) {
	foreach ( $spec['upgrade_md'] as $id => $size ) {
		mdf_test_write_md( (int) $id, $size > 0 ? str_repeat( 'x', (int) $size ) : '' );
	}
	mdf_manifest_write( [ 'version' => 1, 'last_full_run' => null, 'post_count' => 5, 'failed_ids' => [ 10, 55 ] ] );

	$removed = mdf_upgrade_exclusion_cleanup();

	$raw = [];
	foreach ( (array) scandir( mdf_cache_posts_dir() ) as $entry ) {
		if ( substr( $entry, -3 ) === '.md' ) {
			$raw[] = (int) basename( $entry, '.md' );
		}
	}
	sort( $raw );

	$out['upgrade'] = [
		'removed'      => $removed,
		'raw_md_ids'   => $raw,
		'manifest'     => mdf_manifest_read(),
		'option'       => get_option( 'mdf_exclusion_cleanup' ),
	];
}

if ( ! empty( $spec['selftest'] ) ) {
	foreach ( (array) ( $spec['http'] ?? [] ) as $key => $ctype ) {
		$GLOBALS['mdf_test']['http_queue'][ $key ][] = [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => $ctype ],
			'body'     => '',
		];
	}
	$GLOBALS['mdf_test']['options']['mdf_offer_markdown'] = true;
	$out['test_urls']     = mdf_negotiation_test_urls( 3 );
	$out['excluded_urls'] = mdf_negotiation_excluded_urls();
	$out['selftest']      = mdf_perform_negotiation_self_test();
	$out['http_calls']    = count( $GLOBALS['mdf_test']['http'] );
}

if ( ! empty( $spec['llms'] ) ) {
	$GLOBALS['mdf_test']['options']['mdf_offer_markdown']     = true;
	$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [ 'status' => 'working', 'checked' => time(), 'detail' => '' ];
	$content = mdf_get_servable_llms_txt()['content'];
	$out['llms'] = [
		'size'       => strlen( $content ),
		'sha'        => hash( 'sha256', $content ),
		'has_note'   => false !== strpos( $content, '## Session-only pages' ),
		'has_claim'  => false !== strpos( $content, '## Machine-readable content' ),
	];
}

if ( ! empty( $spec['mdfjson'] ) ) {
	require __DIR__ . '/schema_lib.php';

	$schema = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/mdf.schema.json' ), true );

	$states = [];

	// Offering off.
	$GLOBALS['mdf_test']['options'] = [];
	$states['offering_off'] = mdf_negotiation_claim_block_reason();

	// Offering on, self-test working.
	$GLOBALS['mdf_test']['options']['mdf_offer_markdown']     = true;
	$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [ 'status' => 'working', 'checked' => 1780000000, 'detail' => '' ];
	$states['working'] = mdf_negotiation_claim_block_reason();

	$doc = mdf_capability_document();

	$example_errors = [];
	foreach ( (array) ( $schema['examples'] ?? [] ) as $i => $example ) {
		$example_errors[ $i ] = mdf_schema_validate( $example, $schema );
	}

	// Blocked.
	$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [ 'status' => 'blocked', 'checked' => 1780000000, 'detail' => '' ];
	$states['blocked'] = mdf_negotiation_claim_block_reason();

	// Unknown, no override.
	$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [ 'status' => 'unknown', 'checked' => 1780000000, 'detail' => '' ];
	$states['unknown'] = mdf_negotiation_claim_block_reason();

	// Unknown, owner override.
	$GLOBALS['mdf_test']['options'][ MDF_OWNER_CONFIRMED_OPTION ] = true;
	$states['unknown_with_owner'] = mdf_negotiation_claim_block_reason();

	// Blocked beats the owner override.
	$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [ 'status' => 'blocked', 'checked' => 1780000000, 'detail' => '' ];
	$states['blocked_with_owner'] = mdf_negotiation_claim_block_reason();

	// Static web-root detection.
	$static_file = ABSPATH . 'mdf.json';
	file_put_contents( $static_file, '{}' );
	mdf_refresh_static_mdf_json_detection();
	$detected = (bool) get_option( 'mdf_static_mdf_json_detected', false );
	unlink( $static_file );
	mdf_refresh_static_mdf_json_detection();
	$cleared = ! get_option( 'mdf_static_mdf_json_detected', false );

	// Content-Length and Last-Modified derive from what is actually served.
	$GLOBALS['mdf_test']['options'] = [
		'mdf_offer_markdown'     => true,
		'mdf_negotiation_status' => [ 'status' => 'working', 'checked' => 1780000000, 'detail' => '' ],
	];

	$out['mdfjson'] = [
		'block_reasons'       => $states,
		'document'            => $doc,
		'schema_errors'       => mdf_schema_validate( $doc, $schema ),
		'schema_example_errors' => $example_errors,
		'static_detected'     => $detected,
		'static_cleared'      => $cleared,
		'mtime'               => mdf_capability_document_mtime(),
	];
}

if ( ! empty( $spec['admin'] ) ) {
	$GLOBALS['mdf_test']['current_user']                      = 1;
	$GLOBALS['mdf_test']['options']['mdf_offer_markdown']     = true;
	$GLOBALS['mdf_test']['options']['mdf_negotiation_status'] = [ 'status' => 'working', 'checked' => 1780000000, 'detail' => '' ];

	$errors = [];

	ob_start();
	try {
		mdf_render_settings();
	} catch ( \Throwable $e ) {
		$errors[] = 'settings: ' . get_class( $e ) . ': ' . $e->getMessage();
	}
	$settings_html = (string) ob_get_clean();

	ob_start();
	try {
		mdf_render_dashboard();
	} catch ( \Throwable $e ) {
		$errors[] = 'dashboard: ' . get_class( $e ) . ': ' . $e->getMessage();
	}
	$dashboard_html = (string) ob_get_clean();

	$out['admin'] = [
		'settings_len'  => strlen( $settings_html ),
		'dashboard_len' => strlen( $dashboard_html ),
		'errors'        => $errors,
		'html'          => $settings_html . "\n" . $dashboard_html,
	];
}

if ( ! empty( $spec['activate'] ) ) {
	// Activation is a real upgrade, so offering is already on.
	$GLOBALS['mdf_test']['options']['mdf_offer_markdown'] = true;
	mdf_test_enable_wpsc( ! empty( $spec['wpsc'] ) );

	if ( ! empty( $spec['wpsc_adapter_present'] ) ) {
		$GLOBALS['mdf_test']['wpsc_plugins'] = [ mdf_wpsc_adapter_relative_path() ];
	}

	foreach ( (array) ( $spec['http'] ?? [] ) as $url => $ctype ) {
		mdf_test_queue_http( (string) $url, (string) $ctype, 3 );
	}

	mdf_activate();

	$first_scheduled = wp_next_scheduled( 'mdf_negotiation_delayed_retest' );

	// A second scheduling call must not add another event.
	mdf_schedule_delayed_negotiation_retest();
	$second_scheduled = wp_next_scheduled( 'mdf_negotiation_delayed_retest' );

	$status = get_option( 'mdf_negotiation_status' );

	$out['activation'] = [
		'status'                 => $status['status'],
		'detail'                 => $status['detail'],
		'delayed_scheduled'      => false !== $first_scheduled,
		'delayed_scheduled_time' => $first_scheduled,
		'second_call_same_event' => $first_scheduled === $second_scheduled,
		'adapter_registered'     => mdf_wpsc_adapter_registered(),
	];
}

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
