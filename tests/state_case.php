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

echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
