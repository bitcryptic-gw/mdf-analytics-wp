<?php
/**
 * Plugin Name: MDF Analytics
 * Plugin URI:  https://github.com/bitcryptic-gw/mdf
 * Description: Tracks AI agent traffic and Accept: text/markdown requests. Phase 1 of MDF (Markdown First) ecosystem support — visibility dashboard with estimated earnings. No content modification, no payment processing.
 * Version:     0.1.12
 * Author:      Gary Walker (BitCryptic™) & Graham Hall (Slepner)
 * Author URI:  https://bitcryptic.com
 * License:     MIT
 * Text Domain: mdf-analytics
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'MDF_VERSION',    '0.1.12' );
define( 'MDF_TABLE',      'mdf_requests' );
define( 'MDF_LOG_DAYS',   90 );       // retention window
define( 'MDF_PURGE_FREQ', 'daily' );  // WP-Cron schedule

// Bundled WP Super Cache adapter. Registered by path (relative to ABSPATH)
// through WP Super Cache's `wpsc_plugins` setting so it is included during the
// early cache phase and survives WP Super Cache upgrades.
define( 'MDF_WPSC_ADAPTER_FILE', 'mdf-supercache-adapter.php' );

// README anchor for the adapter + negotiation-status documentation.
define( 'MDF_NEGOTIATION_README_URL', 'https://github.com/bitcryptic-gw/mdf-analytics-wp#wp-super-cache-adapter' );

// Owner override: the site owner has verified markdown negotiation themselves.
// Never set by default or programmatically; only from the Settings page.
define( 'MDF_OWNER_CONFIRMED_OPTION', 'mdf_negotiation_owner_confirmed' );

// Hard cap for the owner-supplied llms.txt stored in the mdf_llms_txt option.
// Anything larger is rejected outright rather than silently truncated.
define( 'MDF_LLMS_TXT_MAX_BYTES', 65536 );

// ---------------------------------------------------------------------------
// Known agent User-Agent substrings (case-insensitive)
// Sourced from Cloudflare bot management public lists + common crawlers.
// ---------------------------------------------------------------------------

define( 'MDF_KNOWN_AGENTS', serialize( [
    // AI assistants & inference
    'claudebot', 'claude-web', 'anthropic',
    'gptbot', 'chatgpt-user', 'openai',
    'gemini', 'google-extended', 'googleother',
    'perplexitybot', 'perplexity',
    'cohere-ai', 'coherebot',
    'you.com', 'youbot',
    'mistral',
    'meta-externalagent', 'meta-externalfetcher',
    // Agentic frameworks
    'langchain', 'crewai', 'autogen', 'llamaindex',
    'agentgpt', 'superagent', 'fixie',
    // Search & indexing bots
    'googlebot', 'bingbot', 'slurp', 'duckduckbot',
    'baiduspider', 'yandexbot', 'sogou',
    'exabot', 'facebot', 'ia_archiver',
    // Generic crawlers / fetchers
    'python-requests', 'python-httpx', 'python-urllib',
    'go-http-client', 'java/', 'curl/', 'wget/',
    'axios/', 'node-fetch', 'undici',
    'scrapy', 'mechanize', 'httpclient',
    // LLM hosting / inference
    'vercel-edge', 'aws-lambda', 'cloudflare-workers',
] ) );

// ---------------------------------------------------------------------------
// Internal / platform UAs — logged but excluded from agent counts & earnings.
// type = 3: WordPress core, uptime monitors, CDN health checks, pingback clients.
// ---------------------------------------------------------------------------

define( 'MDF_INTERNAL_AGENTS', serialize( [
    // WordPress platform self-calls
    'wordpress/',
    // MDF Analytics' own negotiation self-test loopback
    'mdf-analytics-selftest',
    // Uptime & health monitors
    'uptime-kuma', 'uptimerobot', 'statuscake', 'pingdom',
    'hetrixtools', 'freshping', 'betterstack', 'hyperping',
    'site24x7', 'monitis', 'nodeping', 'oh-dear',
    // CDN / load balancer health probes
    'cloudflare-healthcheck', 'aws-elb-healthchecker',
    'googlehc/', 'kube-probe/',
    // Generic synthetic monitors
    'synthetic-monitoring', 'blackbox-exporter',
] ) );

// ---------------------------------------------------------------------------
// Vendored dependencies
// ---------------------------------------------------------------------------

// Namespace-scoped league/html-to-markdown — avoids class-redeclaration
// collisions if another plugin also vendors the same upstream library.
if ( ! class_exists( 'MdfAnalytics\Vendor\League\HTMLToMarkdown\HtmlConverter' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ---------------------------------------------------------------------------
// Markdown pre-build cache pipeline
// ---------------------------------------------------------------------------

define( 'MDF_CACHE_BATCH_SIZE', 20 ); // posts per backfill cron tick

/**
 * Return the base cache directory path (wp-content/uploads/mdf-cache/).
 */
function mdf_cache_base_dir(): string {
    $upload = wp_upload_dir();
    return $upload['basedir'] . '/mdf-cache';
}

/**
 * Return the posts subdirectory path.
 */
function mdf_cache_posts_dir(): string {
    return mdf_cache_base_dir() . '/posts';
}

/**
 * Create the cache directory structure with an empty index.html to block
 * directory listing.
 */
function mdf_create_cache_dirs(): void {
    $base  = mdf_cache_base_dir();
    $posts = mdf_cache_posts_dir();

    if ( ! is_dir( $posts ) ) {
        wp_mkdir_p( $posts );
    }

    @chmod( $base, 0775 );
    @chmod( $posts, 0775 );

    $index = $base . '/index.html';
    if ( ! file_exists( $index ) ) {
        file_put_contents( $index, '' );
    }
}

/**
 * Compute a SHA-256 hash of content (post-filter, shortcodes expanded).
 */
function mdf_content_hash( string $content ): string {
    return 'sha256:' . hash( 'sha256', $content );
}

// ---------------------------------------------------------------------------
// Sidecar (.meta.json) read / write
// ---------------------------------------------------------------------------

function mdf_sidecar_path( int $post_id ): string {
    return mdf_cache_posts_dir() . '/' . $post_id . '.meta.json';
}

function mdf_sidecar_read( int $post_id ): ?array {
    $path = mdf_sidecar_path( $post_id );
    if ( ! file_exists( $path ) ) {
        return null;
    }
    $json = file_get_contents( $path );
    if ( $json === false ) {
        return null;
    }
    $data = json_decode( $json, true );
    return is_array( $data ) ? $data : null;
}

function mdf_sidecar_write( int $post_id, array $data ): bool {
    $path = mdf_sidecar_path( $post_id );
    $dir  = mdf_cache_posts_dir();

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    if ( $json === false || file_put_contents( $tmp, $json . "\n" ) === false ) {
        return false;
    }

    // Atomic rename — old file stays servable until the new one is in place.
    return rename( $tmp, $path );
}

// ---------------------------------------------------------------------------
// Manifest (manifest.json) read / write
// ---------------------------------------------------------------------------

function mdf_manifest_path(): string {
    return mdf_cache_base_dir() . '/manifest.json';
}

function mdf_manifest_read(): array {
    $path = mdf_manifest_path();
    if ( ! file_exists( $path ) ) {
        return [
            'version'       => 1,
            'last_full_run' => null,
            'post_count'    => 0,
            'failed_ids'    => [],
        ];
    }
    $json = file_get_contents( $path );
    if ( $json === false ) {
        return [
            'version'       => 1,
            'last_full_run' => null,
            'post_count'    => 0,
            'failed_ids'    => [],
        ];
    }
    $data = json_decode( $json, true );
    return is_array( $data ) ? $data : [
        'version'       => 1,
        'last_full_run' => null,
        'post_count'    => 0,
        'failed_ids'    => [],
    ];
}

function mdf_manifest_write( array $manifest ): bool {
    $path = mdf_manifest_path();
    $dir  = mdf_cache_base_dir();

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $tmp  = $path . '.' . getmypid() . '.tmp';
    $json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    if ( $json === false || file_put_contents( $tmp, $json . "\n" ) === false ) {
        return false;
    }
    return rename( $tmp, $path );
}

/**
 * Acquire an exclusive lock on the manifest file and read its current
 * contents.  Must be paired with mdf_manifest_write_and_unlock().
 *
 * The lock (advisory flock) prevents concurrent read-modify-write races
 * across separate PHP processes (e.g. overlapping WP-Cron events or
 * near-simultaneous save_post-triggered rebuilds).
 *
 * @return array{0: mixed, 1: array} File handle (or null on lock failure)
 *                                    and the current manifest data.
 */
function mdf_manifest_lock_and_read(): array {
    $path = mdf_manifest_path();
    $dir  = dirname( $path );

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $handle = fopen( $path, 'c+' );
    if ( $handle === false ) {
        return [ null, [
            'version'       => 1,
            'last_full_run' => null,
            'post_count'    => 0,
            'failed_ids'    => [],
        ] ];
    }

    if ( ! flock( $handle, LOCK_EX ) ) {
        fclose( $handle );
        return [ null, [
            'version'       => 1,
            'last_full_run' => null,
            'post_count'    => 0,
            'failed_ids'    => [],
        ] ];
    }

    rewind( $handle );
    $json = stream_get_contents( $handle );
    if ( $json === false || $json === '' ) {
        return [ $handle, [
            'version'       => 1,
            'last_full_run' => null,
            'post_count'    => 0,
            'failed_ids'    => [],
        ] ];
    }

    $data = json_decode( $json, true );
    return [ $handle, is_array( $data ) ? $data : [
        'version'       => 1,
        'last_full_run' => null,
        'post_count'    => 0,
        'failed_ids'    => [],
    ] ];
}

/**
 * Write the manifest via atomic temp-file + rename, then release the
 * exclusive lock (and close the file handle) obtained by
 * mdf_manifest_lock_and_read().
 *
 * @param mixed $handle   File handle from mdf_manifest_lock_and_read().
 * @param array $manifest Manifest data to write.
 * @return bool
 */
function mdf_manifest_write_and_unlock( $handle, array $manifest ): bool {
    $path = mdf_manifest_path();
    $dir  = dirname( $path );

    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }

    $tmp  = $path . '.' . getmypid() . '.tmp';
    $json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

    $ok = ( $json !== false && file_put_contents( $tmp, $json . "\n" ) !== false && rename( $tmp, $path ) );

    if ( $handle !== null ) {
        // After rename the handle points to the old (unlinked) inode.
        // Release the lock and close — the old inode is freed automatically.
        flock( $handle, LOCK_UN );
        fclose( $handle );
    }

    return $ok;
}

/**
 * Update manifest after a single-post conversion result.
 */
function mdf_manifest_record_result( int $post_id, bool $success ): void {
    [ $handle, $manifest ] = mdf_manifest_lock_and_read();

    if ( $success ) {
        $manifest['failed_ids'] = array_values( array_diff( $manifest['failed_ids'], [ $post_id ] ) );
        $manifest['post_count'] = max( $manifest['post_count'], count( mdf_list_cached_post_ids() ) );
    } else {
        if ( ! in_array( $post_id, $manifest['failed_ids'], true ) ) {
            $manifest['failed_ids'][] = $post_id;
        }
    }

    $manifest['last_full_run'] = gmdate( 'c' );
    mdf_manifest_write_and_unlock( $handle, $manifest );
}

/**
 * Count .md files in the posts/ directory.  Fast — just a directory scan,
 * no JSON parsing per file.
 */
function mdf_list_cached_post_ids(): array {
    $posts_dir = mdf_cache_posts_dir();
    if ( ! is_dir( $posts_dir ) ) {
        return [];
    }
    $ids = [];
    foreach ( scandir( $posts_dir ) as $entry ) {
        if ( substr( $entry, -3 ) === '.md' ) {
            $ids[] = (int) basename( $entry, '.md' );
        }
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// Conversion
// ---------------------------------------------------------------------------

/**
 * Convert a single post to markdown and write the cached files.
 *
 * - Computes the_content() (post-filter) and its SHA-256 hash.
 * - Compares against the existing sidecar source_hash.
 * - No-op if unchanged.
 * - Writes to temp first, then atomic rename — old .md stays servable
 *   throughout the rebuild.
 * - Updates manifest.json on completion.
 *
 * @return bool True if a (re)build occurred, false if no-op.
 */
function mdf_convert_post( int $post_id ): bool {
    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) {
        mdf_manifest_record_result( $post_id, false );
        return false;
    }

    // Ensure page-builder modules are loaded.  Some themes (e.g. Divi)
    // conditionally load their builder framework only during real frontend
    // requests and skip WP-Cron, leaving shortcodes unregistered even
    // though the theme/content filters are technically active.
    if ( function_exists( 'et_builder_add_main_elements' ) ) {
        et_builder_add_main_elements();
    }

    // Establish a real singular-post query context so page-builder/theme
    // content filters that gate on is_singular(), get_the_ID(), get_queried_object(),
    // etc. can expand shortcodes correctly in the WP-Cron path.  A temporary
    // WP_Query swapped into the global $wp_query ensures ALL is_*() conditionals
    // behave correctly for the duration of the the_content filter chain.
    global $wp_query;
    $original_query = $wp_query;

    $temp_query = new \WP_Query( [
        'p'         => $post_id,
        'post_type' => 'any',
    ] );
    if ( $temp_query->have_posts() ) {
        $wp_query = $temp_query;
        $temp_query->the_post();
        $html = apply_filters( 'the_content', get_the_content() );
    } else {
        $html = apply_filters( 'the_content', get_the_content( null, false, $post ) );
    }

    $wp_query = $original_query;
    wp_reset_postdata();
    $hash = mdf_content_hash( $html );

    // Compare against existing sidecar — no-op if unchanged.
    $existing = mdf_sidecar_read( $post_id );
    if ( $existing !== null && ( $existing['source_hash'] ?? '' ) === $hash ) {
        return false; // unchanged, nothing to do
    }

    try {
        $converter = new MdfAnalytics\Vendor\League\HTMLToMarkdown\HtmlConverter( [
            'strip_tags' => true,
        ] );
        $markdown = $converter->convert( $html );
    } catch ( \Throwable $e ) {
        mdf_manifest_record_result( $post_id, false );
        return false;
    }

    $md_dir = mdf_cache_posts_dir();
    if ( ! is_dir( $md_dir ) ) {
        wp_mkdir_p( $md_dir );
    }
    if ( ! is_writable( $md_dir ) ) {
        if ( ! get_option( 'mdf_cache_writable_error' ) ) {
            update_option( 'mdf_cache_writable_error', gmdate( 'c' ) . ' — Cache directory not writable by web server. Manual permissions fix required.' );
        }
        mdf_manifest_record_result( $post_id, false );
        return false;
    }

    // Write .md to temp first, then atomic rename.
    $md_path = $md_dir . '/' . $post_id . '.md';
    $md_tmp = $md_path . '.' . getmypid() . '.tmp';
    if ( file_put_contents( $md_tmp, $markdown ) === false ) {
        mdf_manifest_record_result( $post_id, false );
        return false;
    }
    if ( ! rename( $md_tmp, $md_path ) ) {
        mdf_manifest_record_result( $post_id, false );
        return false;
    }

    // Write sidecar.
    $sidecar = [
        'post_id'           => $post_id,
        'post_modified_gmt' => $post->post_modified_gmt,
        'built_at'          => gmdate( 'c' ),
        'source_hash'       => $hash,
        'converter_version' => 'league/html-to-markdown@5.1.1',
    ];
    mdf_sidecar_write( $post_id, $sidecar );

    mdf_manifest_record_result( $post_id, true );
    return true;
}

// ---------------------------------------------------------------------------
// WP-Cron handlers
// ---------------------------------------------------------------------------

/**
 * Single-post rebuild callback for WP-Cron.
 * Hook: mdf_markdown_rebuild
 */
function mdf_cron_rebuild_post( int $post_id ): void {
    mdf_convert_post( $post_id );
    // Rebuild ran (success or not) — clear the pending marker so the self-heal
    // check doesn't treat this as a lost schedule. Conversion failures are
    // tracked separately via the manifest's failed_ids.
    delete_post_meta( $post_id, 'mdf_pending_rebuild' );
}
add_action( 'mdf_markdown_rebuild', 'mdf_cron_rebuild_post' );

/**
 * Process one batch of the backfill queue.
 * Re-schedules itself if there are more posts to process.
 * Hook: mdf_backfill_batch
 */
function mdf_cron_backfill_batch(): void {
    if ( ! get_option( 'mdf_offer_markdown', false ) ) {
        return;
    }

    $posts_dir = mdf_cache_posts_dir();
    if ( ! is_dir( $posts_dir ) ) {
        wp_mkdir_p( $posts_dir );
    }
    if ( ! is_writable( $posts_dir ) ) {
        if ( ! get_option( 'mdf_cache_writable_error' ) ) {
            update_option( 'mdf_cache_writable_error', gmdate( 'c' ) . ' — Cache directory not writable by web server. Manual permissions fix required.' );
        }
        return;
    }

    $queue     = get_option( 'mdf_backfill_queue', [] );
    $batch     = array_splice( $queue, 0, MDF_CACHE_BATCH_SIZE );
    $processed = 0;

    foreach ( $batch as $post_id ) {
        if ( mdf_convert_post( (int) $post_id ) ) {
            $processed++;
        }
    }

    update_option( 'mdf_backfill_queue', $queue );

    if ( count( $queue ) > 0 ) {
        // Still more to go — re-schedule.
        if ( ! wp_next_scheduled( 'mdf_backfill_batch' ) ) {
            wp_schedule_single_event( time() + 10, 'mdf_backfill_batch' );
        }
    } else {
        // Backfill complete — update total count and clean up.
        [ $handle, $manifest ]    = mdf_manifest_lock_and_read();
        $manifest['last_full_run'] = gmdate( 'c' );
        $manifest['post_count']    = count( mdf_list_cached_post_ids() );
        mdf_manifest_write_and_unlock( $handle, $manifest );
        update_option( 'mdf_backfill_total', null );
    }

    if ( $processed > 0 ) {
        update_option( 'mdf_backfill_processed', (int) get_option( 'mdf_backfill_processed', 0 ) + $processed );
    }
}
add_action( 'mdf_backfill_batch', 'mdf_cron_backfill_batch' );

// ---------------------------------------------------------------------------
// save_post — enqueue rebuild when content changes
// ---------------------------------------------------------------------------

/**
 * On post save, compute the new content hash and compare against the
 * existing sidecar.  If changed (or no sidecar exists), enqueue a
 * rebuild via WP-Cron — never convert synchronously inside the save_post
 * request handler.
 */
function mdf_on_save_post( int $post_id, \WP_Post $post, bool $update ): void {
    // Skip autosaves, revisions, and non-published posts.
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( $post->post_status !== 'publish' ) return;

    // Only act if markdown offering is enabled.
    if ( ! get_option( 'mdf_offer_markdown', false ) ) return;

    // Ensure page-builder modules are loaded (see mdf_convert_post).
    if ( function_exists( 'et_builder_add_main_elements' ) ) {
        et_builder_add_main_elements();
    }

    // Establish a real singular-post query context for the content hash
    // computation so it matches what mdf_convert_post() produces when it
    // actually rebuilds.  A temporary WP_Query swapped into the global
    // $wp_query ensures ALL is_*() conditionals behave correctly.
    global $wp_query;
    $original_query = $wp_query;

    $temp_query = new \WP_Query( [
        'p'         => $post->ID,
        'post_type' => 'any',
    ] );
    if ( $temp_query->have_posts() ) {
        $wp_query = $temp_query;
        $temp_query->the_post();
        $html = apply_filters( 'the_content', get_the_content() );
    } else {
        $html = apply_filters( 'the_content', get_the_content( null, false, $post ) );
    }

    $wp_query = $original_query;
    wp_reset_postdata();
    $hash = mdf_content_hash( $html );

    // Compare against existing sidecar.
    $existing = mdf_sidecar_read( $post_id );
    if ( $existing !== null && ( $existing['source_hash'] ?? '' ) === $hash ) {
        return; // unchanged
    }

    // Schedule a rebuild for this post.
    mdf_schedule_post_rebuild( $post_id );
}
add_action( 'save_post', 'mdf_on_save_post', 20, 3 );

/**
 * Schedule a single-post markdown rebuild via WP-Cron.
 */
function mdf_schedule_post_rebuild( int $post_id ): void {
    $args = [ $post_id ];

    // Schedule, then verify it actually persisted — the same cron-option write
    // race that can drop mdf_backfill_batch (see mdf_start_backfill). Retry once.
    if ( ! wp_next_scheduled( 'mdf_markdown_rebuild', $args ) ) {
        wp_schedule_single_event( time() + 5, 'mdf_markdown_rebuild', $args );
        if ( ! wp_next_scheduled( 'mdf_markdown_rebuild', $args ) ) {
            wp_schedule_single_event( time() + 5, 'mdf_markdown_rebuild', $args );
            if ( ! wp_next_scheduled( 'mdf_markdown_rebuild', $args ) ) {
                error_log( "MDF Analytics: mdf_markdown_rebuild for post {$post_id} not scheduled after retry — the self-heal check will attempt recovery." );
            }
        }
    }

    // Record the pending rebuild so the self-heal check can detect a lost event.
    update_post_meta( $post_id, 'mdf_pending_rebuild', time() );
}

// ---------------------------------------------------------------------------
// Negotiation gating — serve cached markdown on Accept: text/markdown
// ---------------------------------------------------------------------------

/**
 * On template_redirect, if the client requests text/markdown and a cached
 * .md file exists for the current post, serve it.  Otherwise do nothing
 * (let WordPress render the normal HTML response).
 *
 * Key design rule: NEVER offer markdown for a URL until a pre-built .md
 * file actually exists for it.  No live-conversion fallback.
 */
function mdf_maybe_serve_markdown(): void {
    // Must be a singular post/page/CPT (get_queried_object_id returns 0 otherwise).
    $post_id = get_queried_object_id();
    if ( $post_id <= 0 ) return;

    // Only if the site admin has enabled markdown offering.
    if ( ! get_option( 'mdf_offer_markdown', false ) ) return;

    // Check Accept header for text/markdown.
    $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
    if ( stripos( $accept, 'text/markdown' ) === false ) return;

    // Fast filesystem stat — do NOT read or parse the .meta.json sidecar.
    $md_path = mdf_cache_posts_dir() . '/' . $post_id . '.md';
    if ( ! file_exists( $md_path ) ) return;

    $mtime = filemtime( $md_path );
    if ( $mtime === false ) return;

    $size = filesize( $md_path );
    if ( $size === false ) return;

    // Conditional GET support.
    $if_mod = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';
    if ( $if_mod !== '' ) {
        $if_mod_time = strtotime( $if_mod );
        if ( $if_mod_time !== false && $if_mod_time >= $mtime ) {
            status_header( 304 );
            header( 'Vary: Accept' );
            header( 'Cache-Control: public, max-age=3600' );
            exit;
        }
    }

    // Prevent WP Super Cache from caching this markdown response.
    // Without this, WPSC maps text/markdown to text/html internally and
    // shares the same cache key with normal HTML page views, causing a
    // race where whichever representation is cached first gets served
    // to all subsequent requests regardless of Accept header.
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    status_header( 200 );
    header( 'Content-Type: text/markdown; charset=utf-8' );
    header( 'Content-Length: ' . $size );
    header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
    header( 'Vary: Accept' );
    header( 'Cache-Control: public, max-age=3600' );

    if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) === 'HEAD' ) {
        exit;
    }

    readfile( $md_path );
    exit;
}
add_action( 'template_redirect', 'mdf_maybe_serve_markdown', 5 );

// ---------------------------------------------------------------------------
// Negotiation self-test — does markdown actually reach clients?
// ---------------------------------------------------------------------------

/**
 * Read the stored negotiation self-test result.
 *
 * The status is one of:
 *   - working: a plain-URL loopback request received `text/markdown`.
 *   - blocked: a plain-URL loopback request returned `text/html`, meaning
 *              something in front of WordPress is serving a cached page.
 *   - unknown: the request failed, timed out, was refused, or returned a
 *              non-200. Loopback requests are blocked on plenty of hosts, so
 *              this is a normal outcome and is never reported as blocked.
 *
 * @return array{status:string,checked:int,detail:string}
 */
function mdf_get_negotiation_status(): array {
    $stored = get_option( 'mdf_negotiation_status', [] );

    if ( ! is_array( $stored ) ) {
        $stored = [];
    }

    $status = isset( $stored['status'] ) ? (string) $stored['status'] : '';
    if ( ! in_array( $status, [ 'working', 'blocked', 'unknown' ], true ) ) {
        $status = 'unknown';
    }

    return [
        'status'  => $status,
        'checked' => isset( $stored['checked'] ) ? (int) $stored['checked'] : 0,
        'detail'  => isset( $stored['detail'] ) ? (string) $stored['detail'] : '',
    ];
}

/**
 * Whether the last self-test result was "blocked".
 */
function mdf_negotiation_is_blocked(): bool {
    $status = mdf_get_negotiation_status();
    return $status['status'] === 'blocked';
}

/**
 * Whether the site owner has explicitly confirmed negotiation themselves.
 */
function mdf_negotiation_owner_confirmed(): bool {
    return (bool) get_option( MDF_OWNER_CONFIRMED_OPTION, false );
}

/**
 * Whether the bundled llms.txt may assert that the site serves markdown.
 *
 * Only when markdown offering is enabled AND negotiation is actually confirmed
 * — either by the self-test (`working`) or by the owner's explicit override.
 * Offering off, `blocked`, and un-overridden `unknown` all withhold the claim.
 */
function mdf_negotiation_claim_confirmed(): bool {
    if ( ! get_option( 'mdf_offer_markdown', false ) ) {
        return false;
    }
    // A blocked result is direct evidence and beats any earlier owner assertion.
    if ( mdf_negotiation_is_blocked() ) {
        return false;
    }
    if ( mdf_negotiation_owner_confirmed() ) {
        return true;
    }

    return mdf_get_negotiation_status()['status'] === 'working';
}

/**
 * Return the post IDs eligible to be probed, most preferred first.
 *
 * A post qualifies only when it has a non-empty cached .md file, is published,
 * is not password-protected, and is of a publicly viewable post type. The front
 * page is preferred when it is a qualifying cached page; otherwise the most
 * recently modified qualifying post leads. Later candidates follow by most
 * recently modified.
 *
 * @return int[]
 */
function mdf_negotiation_candidate_post_ids(): array {
    $ids       = mdf_list_cached_post_ids();
    $posts_dir = mdf_cache_posts_dir();

    $qualifying = [];
    foreach ( $ids as $id ) {
        $id = (int) $id;

        $path = $posts_dir . '/' . $id . '.md';
        clearstatcache( true, $path );
        if ( ! is_file( $path ) ) {
            continue;
        }
        $size = filesize( $path );
        if ( $size === false || $size <= 0 ) {
            continue;
        }

        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post ) {
            continue;
        }
        if ( $post->post_status !== 'publish' ) {
            continue;
        }
        if ( $post->post_password !== '' ) {
            continue;
        }
        if ( ! is_post_type_viewable( $post->post_type ) ) {
            continue;
        }

        $qualifying[ $id ] = $post;
    }

    // Front page first, when it is a qualifying cached page.
    $ordered = [];
    if ( get_option( 'show_on_front' ) === 'page' ) {
        $front_id = (int) get_option( 'page_on_front' );
        if ( $front_id > 0 && isset( $qualifying[ $front_id ] ) ) {
            $ordered[] = $front_id;
        }
    }

    // Then most recently modified first.
    uasort(
        $qualifying,
        static function ( $a, $b ) {
            return strcmp( (string) $b->post_modified_gmt, (string) $a->post_modified_gmt );
        }
    );

    foreach ( array_keys( $qualifying ) as $id ) {
        if ( ! in_array( $id, $ordered, true ) ) {
            $ordered[] = $id;
        }
    }

    return $ordered;
}

/**
 * Return up to $limit distinct, plain, public URLs to probe, preferring the
 * front page and then the most recently modified qualifying content.
 *
 * @return string[]
 */
function mdf_negotiation_test_urls( int $limit = 3 ): array {
    $urls = [];

    foreach ( mdf_negotiation_candidate_post_ids() as $id ) {
        $permalink = get_permalink( $id );
        if ( ! is_string( $permalink ) || $permalink === '' ) {
            continue;
        }
        if ( in_array( $permalink, $urls, true ) ) {
            continue;
        }

        $urls[] = $permalink;
        if ( count( $urls ) >= $limit ) {
            break;
        }
    }

    return $urls;
}

/**
 * Probe each URL: confirm it is publicly reachable (HTTP 200) before drawing
 * any conclusion from the markdown request, then tally the markdown responses.
 *
 * A URL that is not reachable is skipped (its probe is invalid); this is what
 * keeps a stale cached .md whose permalink now 404s from producing a result.
 *
 * @param string[] $urls
 * @return array{probes:int,reachable:int,markdown:int,html:int,other:int,notes:string[]}
 */
function mdf_probe_negotiation_urls( array $urls ): array {
    $result = [
        'probes'    => 0,
        'reachable' => 0,
        'markdown'  => 0,
        'html'      => 0,
        'other'     => 0,
        'notes'     => [],
    ];

    foreach ( $urls as $url ) {
        $reach = wp_remote_get(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 0,
                'headers'     => [ 'Accept' => 'text/html' ],
                'user-agent'  => 'MDF-Analytics-SelfTest/' . MDF_VERSION,
            ]
        );

        if ( is_wp_error( $reach ) ) {
            $result['notes'][] = 'reachability check failed (' . $reach->get_error_code() . ')';
            continue;
        }

        $reach_code = (int) wp_remote_retrieve_response_code( $reach );
        if ( $reach_code !== 200 ) {
            $result['notes'][] = 'base URL not reachable (HTTP ' . $reach_code . ')';
            continue;
        }
        $result['reachable']++;

        $response = wp_remote_get(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 0,
                'headers'     => [ 'Accept' => 'text/markdown' ],
                'user-agent'  => 'MDF-Analytics-SelfTest/' . MDF_VERSION,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $result['notes'][] = 'markdown probe failed (' . $response->get_error_code() . ')';
            continue;
        }

        $code  = (int) wp_remote_retrieve_response_code( $response );
        $ctype = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );

        if ( $code !== 200 ) {
            $result['notes'][] = 'markdown probe returned HTTP ' . $code;
            continue;
        }

        $result['probes']++;

        if ( strpos( $ctype, 'text/markdown' ) !== false ) {
            $result['markdown']++;
        } elseif ( strpos( $ctype, 'text/html' ) !== false ) {
            $result['html']++;
        } else {
            $result['other']++;
            $result['notes'][] = 'markdown probe returned ' . ( $ctype !== '' ? $ctype : 'no content-type' );
        }
    }

    return $result;
}

/**
 * Turn raw probe tallies into a stored self-test result.
 *
 * @param array{probes:int,reachable:int,markdown:int,html:int,other:int,notes:string[]} $r
 * @return array{status:string,checked:int,detail:string}
 */
function mdf_summarize_negotiation_probe( array $r, int $checked ): array {
    $suffix = ! empty( $r['notes'] )
        ? ' (' . implode( '; ', array_slice( array_unique( $r['notes'] ), 0, 3 ) ) . ')'
        : '';

    if ( $r['probes'] === 0 ) {
        return [
            'status'  => 'unknown',
            'checked' => $checked,
            'detail'  => 'No probe URL returned a usable 200 response' . $suffix . '.',
        ];
    }

    if ( $r['html'] === 0 && $r['other'] === 0 ) {
        return [
            'status'  => 'working',
            'checked' => $checked,
            'detail'  => sprintf( 'All %d probed URL(s) returned text/markdown.', $r['probes'] ),
        ];
    }

    if ( $r['html'] > 0 ) {
        $detail = $r['markdown'] > 0
            ? sprintf(
                '%d of %d probed URL(s) returned markdown, %d returned text/html — inconsistent.',
                $r['markdown'],
                $r['probes'],
                $r['html']
            )
            : sprintf( 'All %d probed URL(s) returned text/html instead of markdown.', $r['probes'] );

        return [
            'status'  => 'blocked',
            'checked' => $checked,
            'detail'  => $detail,
        ];
    }

    return [
        'status'  => 'unknown',
        'checked' => $checked,
        'detail'  => 'Probe responses were not markdown or HTML' . $suffix . '.',
    ];
}

/**
 * Perform the negotiation self-test.
 *
 * Probes up to three qualifying URLs (front page first, then most recently
 * modified), confirming each is publicly reachable before drawing any
 * conclusion. Retries the whole set once if a probe comes back as HTML while
 * the WP Super Cache adapter is registered — registering the adapter rewrites
 * WP Super Cache's config file, and a worker holding a stale compiled copy can
 * serve one more cached HTML page immediately afterwards.
 *
 * @return array{status:string,checked:int,detail:string}
 */
function mdf_perform_negotiation_self_test(): array {
    $checked = time();

    if ( ! get_option( 'mdf_offer_markdown', false ) ) {
        return [
            'status'  => 'unknown',
            'checked' => $checked,
            'detail'  => 'Markdown offering is disabled.',
        ];
    }

    $urls = mdf_negotiation_test_urls( 3 );
    if ( empty( $urls ) ) {
        return [
            'status'  => 'unknown',
            'checked' => $checked,
            'detail'  => 'No qualifying cached markdown content (non-empty, published, publicly viewable) is available to test yet.',
        ];
    }

    $result = mdf_probe_negotiation_urls( $urls );

    if ( $result['html'] > 0 && mdf_wpsc_adapter_registered() ) {
        if ( function_exists( 'opcache_invalidate' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @opcache_invalidate( WP_CONTENT_DIR . '/wp-cache-config.php', true );
        }
        usleep( 2000000 );
        $result = mdf_probe_negotiation_urls( $urls );
    }

    return mdf_summarize_negotiation_probe( $result, $checked );
}

/**
 * Run the self-test and store the result in `mdf_negotiation_status`
 * (autoload off). Never runs recursively.
 *
 * @return array{status:string,checked:int,detail:string}
 */
function mdf_run_negotiation_self_test(): array {
    static $running = false;

    if ( $running ) {
        return mdf_get_negotiation_status();
    }

    $running = true;
    $result  = mdf_perform_negotiation_self_test();
    update_option( 'mdf_negotiation_status', $result, false );

    // A blocked result is evidence and beats an earlier owner assertion.
    if ( $result['status'] === 'blocked' ) {
        delete_option( MDF_OWNER_CONFIRMED_OPTION );
    }

    $running = false;

    return $result;
}
add_action( 'mdf_negotiation_self_test', 'mdf_run_negotiation_self_test' );

/**
 * Ensure the daily self-test cron event is scheduled.
 */
function mdf_schedule_negotiation_self_test(): void {
    if ( ! wp_next_scheduled( 'mdf_negotiation_self_test' ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mdf_negotiation_self_test' );
    }
}

// ---------------------------------------------------------------------------
// Bundled WP Super Cache adapter
//
// WP Super Cache folds every non-JSON Accept value into text/html and uses
// that value for both its cache key and its static-file gate. Its read path
// runs in advanced-cache.php before normal plugins load, so a request whose
// HTML is already cached never reaches mdf_maybe_serve_markdown().
//
// The adapter (mdf-supercache-adapter.php, shipped inside this plugin and
// registered by path with WP Super Cache) hooks WP Super Cache's own
// `wp_cache_get_cookies_values` cache action. Appending a fixed marker there
// changes the cache key and makes the static supercache gate stand aside, so
// markdown requests fall through to PHP.
// ---------------------------------------------------------------------------

/**
 * Whether WP Super Cache is active.
 */
function mdf_wpsc_active(): bool {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return function_exists( 'is_plugin_active' ) && is_plugin_active( 'wp-super-cache/wp-cache.php' );
}

/**
 * Whether WP Super Cache is present and actually caching this site.
 */
function mdf_wpsc_caching(): bool {
    if ( ! mdf_wpsc_active() ) {
        return false;
    }
    if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
        return false;
    }

    return file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
}

/**
 * Absolute path to the bundled adapter file.
 */
function mdf_wpsc_adapter_path(): string {
    return __DIR__ . '/' . MDF_WPSC_ADAPTER_FILE;
}

/**
 * The adapter path in the ABSPATH-relative form WP Super Cache stores.
 */
function mdf_wpsc_adapter_relative_path(): string {
    $file    = mdf_wpsc_adapter_path();
    $abspath = trailingslashit( ABSPATH );

    return strpos( $file, $abspath ) === 0 ? substr( $file, strlen( $abspath ) ) : $file;
}

/**
 * Whether the adapter is present in WP Super Cache's `wpsc_plugins` list.
 */
function mdf_wpsc_adapter_registered(): bool {
    if ( ! mdf_wpsc_active() || ! function_exists( 'wpsc_get_plugins' ) ) {
        return false;
    }

    $plugins = wpsc_get_plugins();

    return is_array( $plugins ) && in_array( mdf_wpsc_adapter_relative_path(), $plugins, true );
}

/**
 * Register the bundled adapter with WP Super Cache.
 *
 * Only when markdown offering is enabled and WP Super Cache is actually
 * caching. WP Super Cache itself records the path in its `wpsc_plugins`
 * setting (ABSPATH-relative), which is read during its early cache phase.
 */
function mdf_maybe_register_wpsc_adapter(): void {
    if ( ! get_option( 'mdf_offer_markdown', false ) ) {
        return;
    }
    if ( ! mdf_wpsc_caching() || ! function_exists( 'wpsc_add_plugin' ) ) {
        return;
    }

    wpsc_add_plugin( mdf_wpsc_adapter_relative_path() );
}

/**
 * Unregister the bundled adapter from WP Super Cache.
 */
function mdf_maybe_unregister_wpsc_adapter(): void {
    if ( ! function_exists( 'wpsc_delete_plugin' ) ) {
        return;
    }

    wpsc_delete_plugin( mdf_wpsc_adapter_relative_path() );
}

/**
 * Whether WP Super Cache is in Expert (mod_rewrite) mode.
 *
 * In that mode Apache serves the cached HTML file directly from .htaccess
 * before any PHP runs, so no adapter can help. The WP Super Cache config file
 * is included in an isolated scope to read the setting without leaking its
 * variables into this request.
 *
 * @return bool|null True/false when known, null when WP Super Cache is
 *                   inactive or its config cannot be read.
 */
function mdf_wpsc_mod_rewrite_mode(): ?bool {
    static $mode = null;
    static $done = false;

    if ( $done ) {
        return $mode;
    }
    $done = true;

    if ( ! mdf_wpsc_active() ) {
        return null;
    }

    $config = WP_CONTENT_DIR . '/wp-cache-config.php';
    if ( ! is_readable( $config ) ) {
        return null;
    }

    $wp_cache_mod_rewrite = null;
    ob_start();
    include $config;
    ob_end_clean();

    if ( isset( $wp_cache_mod_rewrite ) ) {
        $mode = (bool) $wp_cache_mod_rewrite;
    }

    return $mode;
}

// Self-heal the registration on admin loads (e.g. after a plugin update, when
// the activation hook does not run). wpsc_add_plugin() is a no-op when the
// adapter is already registered.
add_action( 'admin_init', 'mdf_maybe_register_wpsc_adapter' );

// ---------------------------------------------------------------------------
// Backfill — full catalogue rebuild on toggle enable
// ---------------------------------------------------------------------------

/**
 * Kick off a full backfill: enqueue every published post ID into a batched
 * WP-Cron queue.
 */
function mdf_start_backfill(): void {
    $post_types = get_post_types( [ 'public' => true ] );
    $post_ids   = get_posts( [
        'post_type'        => $post_types,
        'post_status'      => 'publish',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => true,
    ] );

    $total = count( $post_ids );
    if ( $total === 0 ) return;

    update_option( 'mdf_backfill_queue', $post_ids );
    update_option( 'mdf_backfill_total', $total );
    update_option( 'mdf_backfill_processed', 0 );

    // Schedule the first batch, then verify it actually persisted. The single-event
    // cron write can race with other schedulers (e.g. Action Scheduler) and be
    // silently dropped before it ever fires — retry once if the first write lost.
    if ( ! wp_next_scheduled( 'mdf_backfill_batch' ) ) {
        wp_schedule_single_event( time() + 3, 'mdf_backfill_batch' );
        if ( ! wp_next_scheduled( 'mdf_backfill_batch' ) ) {
            wp_schedule_single_event( time() + 3, 'mdf_backfill_batch' );
            if ( ! wp_next_scheduled( 'mdf_backfill_batch' ) ) {
                error_log( 'MDF Analytics: mdf_backfill_batch not scheduled after retry — the self-heal check will attempt recovery.' );
            }
        }
    }
}

/**
 * Check whether a backfill is currently in progress.
 */
function mdf_backfill_in_progress(): bool {
    $queue = get_option( 'mdf_backfill_queue', [] );
    return count( $queue ) > 0;
}

/**
 * Count published posts/pages eligible for markdown conversion — i.e. the same
 * set the backfill processes (all public post types, publish status). One cheap
 * indexed COUNT query; used only on Settings-page load for the status line.
 */
function mdf_eligible_content_count(): int {
    $post_types = get_post_types( [ 'public' => true ] );
    if ( empty( $post_types ) ) {
        return 0;
    }

    global $wpdb;
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
            $post_types
        )
    );
}

// ---------------------------------------------------------------------------
// Admin notice during backfill
// ---------------------------------------------------------------------------

function mdf_maybe_show_backfill_notice(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Dismissal via query param.
    if ( isset( $_GET['mdf_dismiss_backfill'] ) && check_admin_referer( 'mdf_dismiss_backfill' ) ) {
        update_option( 'mdf_backfill_notice_dismissed', true );
        return;
    }

    if ( get_option( 'mdf_backfill_notice_dismissed', false ) ) return;
    if ( ! mdf_backfill_in_progress() ) return;

    $total     = (int) get_option( 'mdf_backfill_total', 0 );
    $processed = (int) get_option( 'mdf_backfill_processed', 0 );
    $remaining = $total - $processed;

    $dismiss_url = add_query_arg( [
        'mdf_dismiss_backfill' => 1,
        '_wpnonce'             => wp_create_nonce( 'mdf_dismiss_backfill' ),
    ], admin_url( 'admin.php?page=mdf-analytics' ) );

    echo '<div class="notice notice-info is-dismissible" data-dismiss-url="' . esc_url( $dismiss_url ) . '">';
    echo '<p><strong>MDF Analytics:</strong> Building markdown versions of ' . (int) $total . ' posts — agents will be offered markdown as each post finishes. ';
    if ( $remaining > 0 ) {
        echo esc_html( $remaining ) . ' remaining.';
    }
    echo '</p></div>';
}
add_action( 'admin_notices', 'mdf_maybe_show_backfill_notice' );

// ---------------------------------------------------------------------------
// llms.txt static-file detection notice
// ---------------------------------------------------------------------------

/**
 * Re-check the web root for a static llms.txt on admin page loads so the
 * "detected" state stays current — it clears itself once the owner removes
 * the file, and reappears if a file shows up later.
 */
function mdf_refresh_static_llms_txt_detection(): void {
    if ( file_exists( ABSPATH . 'llms.txt' ) ) {
        update_option( 'mdf_static_llms_txt_detected', true );
    } else {
        delete_option( 'mdf_static_llms_txt_detected' );
        delete_option( 'mdf_static_llms_txt_notice_dismissed' );
    }
}
add_action( 'admin_init', 'mdf_refresh_static_llms_txt_detection' );

function mdf_maybe_show_static_llms_txt_notice(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Dismissal via query param.
    if ( isset( $_GET['mdf_dismiss_static_llms'] ) && check_admin_referer( 'mdf_dismiss_static_llms' ) ) {
        update_option( 'mdf_static_llms_txt_notice_dismissed', true );
        return;
    }

    if ( ! get_option( 'mdf_static_llms_txt_detected', false ) ) return;
    if ( get_option( 'mdf_static_llms_txt_notice_dismissed', false ) ) return;

    $dismiss_url = add_query_arg( [
        'mdf_dismiss_static_llms' => 1,
        '_wpnonce'                => wp_create_nonce( 'mdf_dismiss_static_llms' ),
    ] );

    $snippet = mdf_markdown_negotiation_snippet();
    ?>
    <div class="notice notice-warning is-dismissible" data-dismiss-url="<?php echo esc_url( $dismiss_url ); ?>">
        <p><strong>MDF Analytics: existing llms.txt detected</strong></p>
        <p>Your site already has an <code>llms.txt</code> file at the web root, so MDF Analytics will not serve its own copy — your existing file takes priority automatically and nothing has been changed or overwritten.</p>
        <?php if ( mdf_negotiation_is_blocked() ) : ?>
            <p><strong>Warning:</strong> the negotiation self-test currently reports that this site is <em>blocked</em> — a page cache is serving HTML to plain-URL requests even when they ask for markdown. The snippet below would describe behaviour agents are not actually getting on those URLs. Fix the cache first (see Settings → Offer markdown to agents), then re-test.</p>
        <?php else : ?>
            <p>If you'd like AI agents to know this site serves clean markdown on request, consider adding the snippet below to your existing <code>llms.txt</code>:</p>
        <?php endif; ?>
        <p>
            <button type="button" class="button button-secondary" data-mdf-copy-snippet="<?php echo esc_attr( $snippet ); ?>">Copy snippet</button>
        </p>
        <pre style="max-width:720px; white-space:pre-wrap; background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:8px 12px;"><code><?php echo esc_html( $snippet ); ?></code></pre>
    </div>
    <?php
    mdf_enqueue_copy_snippet_script();
}
add_action( 'admin_notices', 'mdf_maybe_show_static_llms_txt_notice' );

/**
 * The machine-readable-content snippet offered for manual paste into an
 * existing static llms.txt. Kept attribution-free — it lands in the site
 * owner's own document.
 */
function mdf_markdown_negotiation_snippet(): string {
    return "## Machine-readable content\n\nThis site serves clean markdown to AI agents on request. Send an `Accept: text/markdown` header when fetching any page URL to receive a CommonMark version instead of HTML, at the same URL. No separate endpoint or path change is needed.";
}

function mdf_enqueue_copy_snippet_script(): void {
    $handle = 'mdf-copy-snippet';
    if ( wp_script_is( $handle, 'enqueued' ) ) return;
    wp_register_script( $handle, '', [], '1.0', true );
    wp_enqueue_script( $handle );
    wp_add_inline_script( $handle, mdf_copy_snippet_js(), 'after' );
}

function mdf_copy_snippet_js(): string {
    return <<<'JS'
(function(){
    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        document.body.removeChild(ta);
    }
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-mdf-copy-snippet]');
        if (!btn) return;
        var text = btn.getAttribute('data-mdf-copy-snippet') || '';
        var done = function() {
            var orig = btn.innerHTML;
            btn.innerHTML = 'Copied!';
            setTimeout(function(){ btn.innerHTML = orig; }, 1600);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function(){ fallbackCopy(text, done); });
        } else {
            fallbackCopy(text, done);
        }
    });
})();
JS;
}

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'mdf_activate' );
register_deactivation_hook( __FILE__, 'mdf_deactivate' );
register_uninstall_hook( __FILE__, 'mdf_uninstall' );

function mdf_activate(): void {
    mdf_create_table();
    mdf_schedule_purge();
    mdf_create_cache_dirs();
    mdf_schedule_negotiation_self_test();

    // Register the WP Super Cache adapter when markdown offering is already on
    // (e.g. reactivation after an update). The admin-load self-heal handles the
    // upgrade path, where the activation hook does not run.
    mdf_maybe_register_wpsc_adapter();

    // Record an initial negotiation status. Bounded by the self-test timeout
    // and never fatal on failure.
    mdf_run_negotiation_self_test();

    // If the site already has a static llms.txt in the web root, it shadows the
    // plugin's virtual copy (the web server serves it before WordPress boots).
    // Record that so an admin notice can offer the markdown-negotiation snippet
    // — never read, modify, or delete that file.
    if ( file_exists( ABSPATH . 'llms.txt' ) ) {
        update_option( 'mdf_static_llms_txt_detected', true );
    }
}

function mdf_deactivate(): void {
    // Deactivation is a deliberate no-op for llms.txt and cache state.
    wp_clear_scheduled_hook( 'mdf_purge_old_records' );
    wp_clear_scheduled_hook( 'mdf_negotiation_self_test' );

    // Remove the WP Super Cache adapter registration so a deactivated plugin
    // has no effect on caching.
    mdf_maybe_unregister_wpsc_adapter();
}

function mdf_uninstall(): void {
    global $wpdb;

    // Drop the analytics table.
    $table = $wpdb->prefix . MDF_TABLE;
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );

    // Remove the generated markdown cache directory under wp-content/uploads.
    mdf_remove_dir( mdf_cache_base_dir() );

    // Remove all plugin options.
    delete_option( 'mdf_db_version' );
    delete_option( 'mdf_sat_rate' );
    delete_option( 'mdf_usdc_rate' );
    delete_option( 'mdf_use_currency' );
    delete_option( 'mdf_offer_markdown' );
    delete_option( 'mdf_backfill_queue' );
    delete_option( 'mdf_backfill_total' );
    delete_option( 'mdf_backfill_processed' );
    delete_option( 'mdf_backfill_notice_dismissed' );
    delete_option( 'mdf_cache_writable_error' );
    delete_option( 'mdf_static_llms_txt_detected' );
    delete_option( 'mdf_static_llms_txt_notice_dismissed' );
    delete_option( 'mdf_llms_txt' );
    delete_option( 'mdf_negotiation_status' );
    delete_option( MDF_OWNER_CONFIRMED_OPTION );

    // Clear scheduled events.
    wp_clear_scheduled_hook( 'mdf_purge_old_records' );
    wp_clear_scheduled_hook( 'mdf_backfill_batch' );
    wp_clear_scheduled_hook( 'mdf_negotiation_self_test' );

    // Remove the WP Super Cache adapter registration.
    mdf_maybe_unregister_wpsc_adapter();

    // Deliberately NOT touching the web root: the plugin never writes there.
}

/**
 * Recursively remove a directory tree (uninstall cleanup).
 */
function mdf_remove_dir( string $dir ): void {
    if ( ! is_dir( $dir ) ) return;

    $items = scandir( $dir );
    foreach ( $items as $item ) {
        if ( $item === '.' || $item === '..' ) continue;
        $path = $dir . '/' . $item;
        if ( is_dir( $path ) ) {
            mdf_remove_dir( $path );
        } else {
            @unlink( $path );
        }
    }
    @rmdir( $dir );
}

function mdf_create_table(): void {
    global $wpdb;
    $table   = $wpdb->prefix . MDF_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        requested_at  DATETIME        NOT NULL,
        path          VARCHAR(2048)   NOT NULL DEFAULT '',
        method        VARCHAR(10)     NOT NULL DEFAULT 'GET',
        visitor_type  TINYINT         NOT NULL DEFAULT 0,
        ua_snippet    VARCHAR(255)    NOT NULL DEFAULT '',
        wants_markdown TINYINT        NOT NULL DEFAULT 0,
        status_code   SMALLINT        NOT NULL DEFAULT 200,
        PRIMARY KEY (id),
        KEY idx_requested_at  (requested_at),
        KEY idx_visitor_type  (visitor_type),
        KEY idx_wants_markdown (wants_markdown)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'mdf_db_version', MDF_VERSION );
}

// ---------------------------------------------------------------------------
// Purge cron
// ---------------------------------------------------------------------------

function mdf_schedule_purge(): void {
    if ( ! wp_next_scheduled( 'mdf_purge_old_records' ) ) {
        wp_schedule_event( time(), MDF_PURGE_FREQ, 'mdf_purge_old_records' );
    }
}
add_action( 'mdf_purge_old_records', 'mdf_run_purge' );

function mdf_run_purge(): void {
    global $wpdb;
    $table     = $wpdb->prefix . MDF_TABLE;
    $threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . MDF_LOG_DAYS . ' days' ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE requested_at < %s", $threshold ) );
}

/**
 * Recover a stuck backfill or a lost per-post rebuild. Runs on the existing
 * purge cron (guaranteed daily recovery) and on admin page loads (fast recovery
 * while an admin is working) — no new cron schedule.
 *
 * 1. Backfill: if the queue still has work but the mdf_backfill_batch single
 *    event is not scheduled (its cron-option write was lost to a race with
 *    another scheduler), re-schedule it.
 * 2. Per-post rebuilds: if a post carries a stale mdf_pending_rebuild marker
 *    (older than the grace window) with no scheduled mdf_markdown_rebuild
 *    event, its schedule was lost the same way — re-schedule it.
 */
function mdf_maybe_reschedule_backfill(): void {
    if ( ! get_option( 'mdf_offer_markdown', false ) ) {
        return;
    }

    // Backfill: queue still has work but no batch event pending.
    $queue = get_option( 'mdf_backfill_queue', [] );
    if ( ! empty( $queue ) && is_array( $queue ) && ! wp_next_scheduled( 'mdf_backfill_batch' ) ) {
        wp_schedule_single_event( time() + 3, 'mdf_backfill_batch' );
    }

    // Per-post rebuilds: stale pending marker with no scheduled event. The grace
    // window exceeds the +5s schedule delay plus the cron tick interval, so a
    // legitimately pending rebuild (event scheduled, waiting to fire) is never
    // matched — only one whose event was genuinely lost.
    $pending = get_posts( [
        'post_type'        => 'any',
        'post_status'      => 'any',
        'fields'           => 'ids',
        'posts_per_page'   => 100,
        'no_found_rows'    => true,
        'meta_key'         => 'mdf_pending_rebuild',
        'meta_value'       => time() - 10 * MINUTE_IN_SECONDS,
        'meta_compare'     => '<',
        'meta_type'        => 'NUMERIC',
    ] );

    foreach ( $pending as $post_id ) {
        $post_id = (int) $post_id;
        if ( ! wp_next_scheduled( 'mdf_markdown_rebuild', [ $post_id ] ) ) {
            mdf_schedule_post_rebuild( $post_id );
        }
    }
}
add_action( 'mdf_purge_old_records', 'mdf_maybe_reschedule_backfill' );
add_action( 'admin_init', 'mdf_maybe_reschedule_backfill' );

// ---------------------------------------------------------------------------
// Request logging — fires on shutdown so status code is finalised
// ---------------------------------------------------------------------------

add_action( 'shutdown', 'mdf_log_request', 1 );

function mdf_log_request(): void {
    // Skip WP-Cron, REST API internal calls, and admin-ajax unless front-end
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return;
    if ( is_admin() ) return;

    // Skip asset requests WordPress doesn't normally handle (belt-and-braces)
    $path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
    $ext  = strtolower( pathinfo( strtok( $path, '?' ), PATHINFO_EXTENSION ) );
    if ( in_array( $ext, [ 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'map' ], true ) ) return;

    $ua             = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
    $accept         = isset( $_SERVER['HTTP_ACCEPT'] )      ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) )      : '';
    $method         = isset( $_SERVER['REQUEST_METHOD'] )   ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )   : 'GET';
    $wants_markdown = ( stripos( $accept, 'text/markdown' ) !== false ) ? 1 : 0;

    [ 'type' => $visitor_type, 'snippet' => $ua_snippet ] = mdf_classify_ua_with_snippet( $ua );

    // Only log agents and markdown-requesting clients; skip ordinary human browsers
    // to keep the table lean and the data meaningful.
    // Type 3 (internal/monitor) is logged but excluded from earnings calculations.
    if ( $visitor_type === 0 && $wants_markdown === 0 ) return;

    $status_code = http_response_code() ?: 200;

    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . MDF_TABLE,
        [
            'requested_at'   => current_time( 'mysql', true ),
            'path'           => substr( $path, 0, 2048 ),
            'method'         => substr( $method, 0, 10 ),
            'visitor_type'   => $visitor_type,
            'ua_snippet'     => $ua_snippet,
            'wants_markdown' => $wants_markdown,
            'status_code'    => (int) $status_code,
        ],
        [ '%s', '%s', '%s', '%d', '%s', '%d', '%d' ]
    );
}

/**
 * Classify a UA string and return a display snippet in one pass.
 *
 * Returns an array:
 *   'type'    => int  — 3 = internal/monitor, 2 = known agent,
 *                       1 = likely automated, 0 = human/unknown
 *   'snippet' => string — matched fragment (type 2), first token of UA (types 1/3),
 *                         or '(empty)' for blank UA. Empty string for type 0 (not logged).
 *
 * For type-2 hits the snippet is the matched fragment (title-cased), so the dashboard
 * shows "Googlebot" / "Go-http-client" rather than the misleading "Mozilla" prefix
 * that bot UAs commonly begin with.
 */
function mdf_classify_ua_with_snippet( string $ua ): array {
    if ( $ua === '' ) {
        return [ 'type' => 1, 'snippet' => '(empty)' ];
    }

    $ua_lower = strtolower( $ua );

    // Internal/platform agents — own bucket, excluded from agent counts & earnings
    $internal = unserialize( MDF_INTERNAL_AGENTS ); // phpcs:ignore
    foreach ( $internal as $fragment ) {
        if ( strpos( $ua_lower, $fragment ) !== false ) {
            return [ 'type' => 3, 'snippet' => mdf_ua_first_token( $ua ) ];
        }
    }

    // Known agents — store the matched fragment as the snippet
    $agents = unserialize( MDF_KNOWN_AGENTS ); // phpcs:ignore
    foreach ( $agents as $fragment ) {
        if ( strpos( $ua_lower, $fragment ) !== false ) {
            return [ 'type' => 2, 'snippet' => ucwords( $fragment, '-' ) ];
        }
    }

    // Heuristic: presence of a browser engine marker means human browser
    $browser_markers = [ 'mozilla/', 'webkit', 'gecko/', 'trident/', 'presto/' ];
    foreach ( $browser_markers as $marker ) {
        if ( strpos( $ua_lower, $marker ) !== false ) {
            return [ 'type' => 0, 'snippet' => '' ];
        }
    }

    // No browser marker, no known agent — likely automated
    return [ 'type' => 1, 'snippet' => mdf_ua_first_token( $ua ) ];
}

/**
 * Extract the first token from a UA string for display (up to first whitespace or slash).
 * Used for type-1 and type-3 snippets where we don't have a matched fragment.
 */
function mdf_ua_first_token( string $ua ): string {
    preg_match( '/^[^\s\/]{1,80}/', $ua, $m );
    return $m[0] ?? substr( $ua, 0, 80 );
}

// ---------------------------------------------------------------------------
// llms.txt serving — serves the plugin's llms.txt at site root
// ---------------------------------------------------------------------------

add_action( 'init', 'mdf_serve_llms_txt' );

/**
 * Read the owner-supplied llms.txt option.
 *
 * Stored as a single option so the content and its timestamp can never get out
 * of step. Returns a normalised array whether or not the option exists.
 *
 * @return array{content:string,modified:int}
 */
function mdf_get_llms_txt_option(): array {
    $opt = get_option( 'mdf_llms_txt', [] );

    if ( ! is_array( $opt ) ) {
        return [ 'content' => '', 'modified' => 0 ];
    }

    return [
        'content'  => ( isset( $opt['content'] ) && is_string( $opt['content'] ) ) ? $opt['content'] : '',
        'modified' => isset( $opt['modified'] ) ? (int) $opt['modified'] : 0,
    ];
}

/**
 * Resolve the content that should actually be served.
 *
 * Custom option content wins when non-empty; otherwise the bundled file is the
 * read-only default template. The bundled file is never written to.
 *
 * @return array{content:string,modified:int,custom:bool}
 */
function mdf_get_effective_llms_txt(): array {
    $opt = mdf_get_llms_txt_option();

    if ( $opt['content'] !== '' ) {
        return [
            'content'  => $opt['content'],
            'modified' => $opt['modified'] > 0 ? $opt['modified'] : time(),
            'custom'   => true,
        ];
    }

    $file = plugin_dir_path( __FILE__ ) . 'llms.txt';
    if ( file_exists( $file ) && is_readable( $file ) ) {
        $mtime = filemtime( $file );
        return [
            'content'  => (string) file_get_contents( $file ),
            'modified' => $mtime ?: time(),
            'custom'   => false,
        ];
    }

    return [ 'content' => '', 'modified' => 0, 'custom' => false ];
}

/**
 * Normalise owner-supplied llms.txt input.
 *
 * Deliberately not sanitize_textarea_field(): markdown legitimately contains
 * angle-bracket autolinks such as <https://example.com>, which tag stripping
 * would destroy. Invalid UTF-8 is rejected rather than silently mangled.
 *
 * @return array{ok:bool,content:string,error:string}
 */
function mdf_sanitize_llms_txt_input( string $raw ): array {
    // wp_check_invalid_utf8() returns '' for a non-empty, invalid input when
    // stripping is disabled — WordPress's own UTF-8 gate, no mbstring needed.
    $checked = wp_check_invalid_utf8( $raw, false );
    if ( $raw !== '' && $checked === '' ) {
        return [
            'ok'      => false,
            'content' => '',
            'error'   => 'The llms.txt content is not valid UTF-8 and was not saved.',
        ];
    }

    // Strip NUL bytes, then normalise line endings to LF.
    $raw = str_replace( "\0", '', $raw );
    $raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );

    if ( strlen( $raw ) > MDF_LLMS_TXT_MAX_BYTES ) {
        return [
            'ok'      => false,
            'content' => '',
            'error'   => sprintf(
                'The llms.txt content is %d bytes, over the %d-byte limit, and was not saved.',
                strlen( $raw ),
                MDF_LLMS_TXT_MAX_BYTES
            ),
        ];
    }

    return [ 'ok' => true, 'content' => $raw, 'error' => '' ];
}

/**
 * Replace the bundled template's "Machine-readable content" section with a
 * neutral, attribution-only "About this file" section.
 *
 * Only ever applied to the bundled default template, only when the
 * self-test reports blocked, and never to content the owner saved in the
 * editor. If the heading is not found the content is returned unchanged so an
 * unrecognised template is never mangled.
 */
function mdf_llms_txt_without_negotiation_claim( string $content ): string {
    $heading = '## Machine-readable content';
    $pos     = strpos( $content, $heading );

    if ( $pos === false ) {
        return $content;
    }

    $neutral = "## About this file\n\n"
        . 'This file is provided by [MDF Analytics](https://github.com/bitcryptic-gw/mdf-analytics-wp), '
        . 'an implementation of the [MDF (Markdown First)](https://github.com/bitcryptic-gw/mdf) open standard.';

    return rtrim( substr( $content, 0, $pos ) ) . "\n\n" . $neutral . "\n";
}

/**
 * Resolve the llms.txt content to actually serve.
 *
 * Custom owner content is always served verbatim. The bundled default is
 * served unchanged only when the markdown claim is confirmed (offering enabled
 * and negotiation confirmed by the self-test or the owner's override). In every
 * other case — offering off, blocked, or unconfirmed unknown — the claim is
 * replaced with the neutral attribution-only section, because publishing it
 * would assert something the site has not been shown to do.
 *
 * @return array{content:string,modified:int,custom:bool}
 */
function mdf_get_servable_llms_txt(): array {
    $effective = mdf_get_effective_llms_txt();

    if ( ! $effective['custom'] && ! mdf_negotiation_claim_confirmed() ) {
        $effective['content'] = mdf_llms_txt_without_negotiation_claim( $effective['content'] );
    }

    return $effective;
}

function mdf_serve_llms_txt(): void {
    $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    $path = wp_parse_url( $uri, PHP_URL_PATH );

    if ( $path !== '/llms.txt' ) {
        return;
    }

    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
    if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
        return;
    }

    $effective = mdf_get_servable_llms_txt();

    if ( $effective['content'] === '' ) {
        return;
    }

    $content = $effective['content'];
    $mtime   = $effective['modified'];
    $size    = strlen( $content );
    $if_mod  = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';

    if ( $if_mod !== '' ) {
        $if_mod_time = strtotime( $if_mod );
        if ( $if_mod_time !== false && $if_mod_time >= $mtime ) {
            status_header( 304 );
            header( 'Cache-Control: public, max-age=3600' );
            exit;
        }
    }

    status_header( 200 );
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Content-Length: ' . $size );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
    header( 'Cache-Control: public, max-age=3600' );

    if ( $method === 'HEAD' ) {
        exit;
    }

    echo $content;
    exit;
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'mdf_register_menu' );

function mdf_register_menu(): void {
    add_menu_page(
        'MDF Analytics',
        'MDF Analytics',
        'manage_options',
        'mdf-analytics',
        'mdf_render_dashboard',
        'dashicons-chart-bar',
        80
    );
    add_submenu_page(
        'mdf-analytics',
        'MDF Settings',
        'Settings',
        'manage_options',
        'mdf-settings',
        'mdf_render_settings'
    );
}

/**
 * Render the negotiation self-test status next to the markdown toggle.
 */
function mdf_render_negotiation_status_block(): void {
    $offer       = (bool) get_option( 'mdf_offer_markdown', false );
    $status      = mdf_get_negotiation_status();
    $owner       = mdf_negotiation_owner_confirmed();
    $claim       = mdf_negotiation_claim_confirmed();
    $wpsc_active = mdf_wpsc_active();
    $mod_rewrite = mdf_wpsc_mod_rewrite_mode();
    $adapter     = $wpsc_active && mdf_wpsc_adapter_registered();

    if ( ! $offer ) {
        $color = '#646970';
        $label = 'Offering off';
    } elseif ( $status['status'] === 'blocked' ) {
        $color = '#b32d2e';
        $label = 'Blocked';
    } elseif ( $owner ) {
        $color = '#1e7e34';
        $label = 'Confirmed by owner';
    } elseif ( $status['status'] === 'working' ) {
        $color = '#1e7e34';
        $label = 'Confirmed by self-test';
    } else {
        $color = '#9e6b00';
        $label = 'Unconfirmed';
    }

    $link = '<a href="' . esc_url( MDF_NEGOTIATION_README_URL ) . '" target="_blank" rel="noopener noreferrer">README</a>';
    ?>
    <div style="margin-top:10px; padding:10px 12px; background:#fff; border:1px solid #dcdcde; border-left:4px solid <?php echo esc_attr( $color ); ?>; border-radius:3px;">
        <p style="margin:0 0 4px;"><strong style="color:<?php echo esc_attr( $color ); ?>;">Markdown negotiation: <?php echo esc_html( $label ); ?></strong></p>
        <p style="margin:0;">
        <?php
        if ( ! $offer ) {
            echo 'Markdown offering is off, so the plugin serves no markdown and <code>/llms.txt</code> withholds the machine-readable claim.';
        } elseif ( $status['status'] === 'blocked' ) {
            echo 'A page cache is serving HTML to agents, so <code>/llms.txt</code> withholds the machine-readable claim. ';
            if ( $wpsc_active && $mod_rewrite === true ) {
                echo 'WP Super Cache is in <strong>Expert (mod_rewrite) mode</strong>: Apache serves cached pages before PHP runs, so the bundled adapter cannot help. Add the <code>RewriteCond</code> shown in the ' . $link . ' to your <code>.htaccess</code> supercache rules.';
            } elseif ( $wpsc_active && $adapter ) {
                echo 'WP Super Cache is active and the MDF adapter is registered, but a plain URL still returned HTML — clear the WP Super Cache cache and re-test, and check for another cache or CDN in front of the site. See the ' . $link . '.';
            } elseif ( $wpsc_active ) {
                echo 'WP Super Cache is active but the MDF adapter is not registered. Toggle "Offer markdown to agents" off and on to register it. See the ' . $link . '.';
            } else {
                echo 'A page cache or CDN in front of WordPress is serving cached HTML. See the ' . $link . '.';
            }
        } elseif ( $owner ) {
            echo 'You have confirmed markdown negotiation manually, so <code>/llms.txt</code> publishes the machine-readable claim.';
            if ( $status['status'] === 'unknown' ) {
                echo ' The self-test could not confirm it (this is normal on hosts that block loopback requests).';
            }
        } elseif ( $status['status'] === 'working' ) {
            echo 'Plain-URL requests with <code>Accept: text/markdown</code> receive markdown, so <code>/llms.txt</code> publishes the machine-readable claim.';
        } else {
            echo 'The self-test could not confirm negotiation (the loopback failed, timed out, or returned a non-200). This is normal on hosts that block loopback requests. <code>/llms.txt</code> withholds the machine-readable claim until it is confirmed — either by a passing self-test or by your own verification below.';
        }
        ?>
        </p>

        <p class="description" style="margin:6px 0 0;"><strong>Machine-readable claim in /llms.txt:</strong> <?php echo $claim ? 'published' : 'withheld'; ?>.</p>

        <?php if ( $status['checked'] > 0 ) : ?>
            <p class="description" style="margin:4px 0 0;">
                Last checked <?php echo esc_html( wp_date( 'Y-m-d H:i:s', $status['checked'] ) ); ?><?php echo $status['detail'] !== '' ? ' — ' . esc_html( $status['detail'] ) : ''; ?>
            </p>
        <?php endif; ?>

        <?php if ( $wpsc_active ) : ?>
            <?php
            if ( $mod_rewrite === true ) {
                $mode_label = 'Expert (mod_rewrite) mode';
            } elseif ( $mod_rewrite === false ) {
                $mode_label = 'standard mode';
            } else {
                $mode_label = 'mode unknown';
            }
            ?>
            <p class="description" style="margin:4px 0 0;">
                WP Super Cache: <code><?php echo esc_html( $mode_label ); ?></code>; MDF adapter <?php echo $adapter ? 'registered' : 'not registered'; ?>.
            </p>
        <?php endif; ?>

        <?php if ( ! $claim && mdf_get_llms_txt_option()['content'] !== '' ) : ?>
            <p class="description" style="margin:4px 0 0;">Your saved llms.txt is served verbatim, so its own "Machine-readable content" section (if it has one) is still published as you wrote it. Edit it below if that is no longer accurate.</p>
        <?php endif; ?>

        <?php if ( $offer && $status['status'] === 'unknown' ) : ?>
            <?php
            $verify_cmd = "curl -sI -H 'Accept: text/markdown' " . home_url( '/' );
            ?>
            <div style="margin-top:8px; padding:8px 10px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:3px;">
                <p style="margin:0 0 4px;">
                    <label><input type="checkbox" name="mdf_negotiation_owner_confirmed" value="1" <?php checked( $owner ); ?>> I have verified markdown negotiation myself</label>
                </p>
                <p class="description" style="margin:0 0 4px;">The plugin could not confirm negotiation with its loopback request (common on hosts that block loopback HTTP). You can check it yourself. Run:</p>
                <pre style="margin:0 0 4px; padding:6px 8px; background:#fff; border:1px solid #dcdcde; overflow:auto;"><?php echo esc_html( $verify_cmd ); ?></pre>
                <p class="description" style="margin:0;">A confirmed site answers with <code>content-type: text/markdown</code> and <code>Vary: Accept</code> instead of HTML. Only tick the box if you have seen that. Leaving it unticked keeps the machine-readable claim withheld. This override is cleared automatically if you switch markdown offering off or if a later self-test reports blocked.</p>
            </div>
        <?php endif; ?>

        <?php if ( $offer ) : ?>
            <p style="margin:8px 0 0;"><input type="submit" name="mdf_negotiation_retest" class="button button-secondary" value="Re-test now"></p>
        <?php endif; ?>
    </div>
    <?php
}

function mdf_render_settings(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['mdf_save_settings'] ) && check_admin_referer( 'mdf_settings_save' ) ) {
        $old_offer = (bool) get_option( 'mdf_offer_markdown', false );

        update_option( 'mdf_sat_rate',       absint( $_POST['mdf_sat_rate'] ?? 1 ) );
        update_option( 'mdf_usdc_rate',      floatval( $_POST['mdf_usdc_rate'] ?? 0.001 ) );
        update_option( 'mdf_use_currency',   sanitize_text_field( $_POST['mdf_use_currency'] ?? 'sats' ) );

        $new_offer = isset( $_POST['mdf_offer_markdown'] ) && $_POST['mdf_offer_markdown'] === '1';
        update_option( 'mdf_offer_markdown', $new_offer );

        // Toggle just flipped from off → on: kick off full backfill, register
        // the WP Super Cache adapter, and run the negotiation self-test.
        if ( ! $old_offer && $new_offer ) {
            update_option( 'mdf_backfill_notice_dismissed', false );
            mdf_start_backfill();
            mdf_maybe_register_wpsc_adapter();
            mdf_run_negotiation_self_test();
            echo '<div class="notice notice-info"><p><strong>MDF Analytics:</strong> Markdown offering enabled. Building markdown versions of all published posts — agents will be offered markdown as each post finishes. <a href="' . esc_url( admin_url( 'admin.php?page=mdf-analytics' ) ) . '">View dashboard →</a></p></div>';
        } elseif ( $old_offer && ! $new_offer ) {
            // Toggle flipped off: clear the backfill queue and stop affecting
            // the page cache.
            update_option( 'mdf_backfill_queue', [] );
            update_option( 'mdf_backfill_total', null );
            update_option( 'mdf_backfill_processed', 0 );
            mdf_maybe_unregister_wpsc_adapter();
            // Reflect that negotiation is no longer offered rather than leaving
            // a stale blocked/working result in place.
            mdf_run_negotiation_self_test();
        }

        // Owner confirmation override. The checkbox is only rendered while
        // offering is on and the self-test is unknown, so the option is only
        // written from that state. It is cleared whenever offering is switched
        // off (below) and by the self-test on a later blocked result.
        if ( ! $new_offer ) {
            delete_option( MDF_OWNER_CONFIRMED_OPTION );
        } elseif ( mdf_get_negotiation_status()['status'] === 'unknown' ) {
            $owner_confirmed = isset( $_POST['mdf_negotiation_owner_confirmed'] ) && $_POST['mdf_negotiation_owner_confirmed'] === '1';
            update_option( MDF_OWNER_CONFIRMED_OPTION, $owner_confirmed, false );
        }

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // The re-test button lives inside the settings form (no nested form), so it
    // shares that form's nonce. Clicking it does not save the other fields
    // because the save handler requires mdf_save_settings.
    if ( isset( $_POST['mdf_negotiation_retest'] ) && check_admin_referer( 'mdf_settings_save' ) ) {
        mdf_run_negotiation_self_test();
        echo '<div class="notice notice-success"><p>MDF Analytics: negotiation self-test complete.</p></div>';
    }

    $llms_notice = '';
    if ( isset( $_POST['mdf_llms_txt_save'] ) && check_admin_referer( 'mdf_llms_txt_save' ) ) {
        $raw    = isset( $_POST['mdf_llms_txt_content'] ) ? wp_unslash( (string) $_POST['mdf_llms_txt_content'] ) : '';
        $result = mdf_sanitize_llms_txt_input( $raw );

        if ( $result['ok'] ) {
            update_option( 'mdf_llms_txt', [ 'content' => $result['content'], 'modified' => time() ], false );
            $llms_notice = '<div class="notice notice-success"><p>llms.txt saved.</p></div>';
        } else {
            $llms_notice = '<div class="notice notice-error"><p><strong>MDF Analytics:</strong> ' . esc_html( $result['error'] ) . '</p></div>';
        }
    } elseif ( isset( $_POST['mdf_llms_txt_reset'] ) && check_admin_referer( 'mdf_llms_txt_save' ) ) {
        delete_option( 'mdf_llms_txt' );
        $llms_notice = '<div class="notice notice-success"><p>llms.txt reset to the bundled default.</p></div>';
    }

    $posts_dir = mdf_cache_posts_dir();
    if ( is_dir( $posts_dir ) && is_writable( $posts_dir ) ) {
        delete_option( 'mdf_cache_writable_error' );
    }
    $cache_error = get_option( 'mdf_cache_writable_error' );
    if ( $cache_error ) {
        echo '<div class="notice notice-error"><p><strong>MDF Analytics:</strong> ' . esc_html( $cache_error ) . '</p></div>';
    }

    $sat_rate       = (int)    get_option( 'mdf_sat_rate',       1 );
    $usdc_rate      = (float)  get_option( 'mdf_usdc_rate',      0.001 );
    $use_currency   =          get_option( 'mdf_use_currency',   'sats' );
    $offer_markdown = (bool)   get_option( 'mdf_offer_markdown', false );

    $llms_option    = mdf_get_llms_txt_option();
    $llms_effective = mdf_get_effective_llms_txt();
    $llms_value     = $llms_effective['content'];
    $llms_custom    = $llms_option['content'] !== '';
    ?>
    <div class="wrap">
        <h1>MDF Analytics — Settings</h1>
        <form method="post">
            <?php wp_nonce_field( 'mdf_settings_save' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="mdf_use_currency">Estimated earnings currency</label></th>
                    <td>
                        <select name="mdf_use_currency" id="mdf_use_currency">
                            <option value="sats"  <?php selected( $use_currency, 'sats' ); ?>>Sats (Lightning)</option>
                            <option value="usdc"  <?php selected( $use_currency, 'usdc' ); ?>>USDC (Base)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="mdf_sat_rate">Rate per markdown request (sats)</label></th>
                    <td><input type="number" name="mdf_sat_rate" id="mdf_sat_rate" value="<?php echo esc_attr( $sat_rate ); ?>" min="1" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="mdf_usdc_rate">Rate per markdown request (USDC)</label></th>
                    <td><input type="number" name="mdf_usdc_rate" id="mdf_usdc_rate" value="<?php echo esc_attr( $usdc_rate ); ?>" step="0.0001" min="0.0001" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="mdf_offer_markdown">Offer markdown to agents</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="mdf_offer_markdown" id="mdf_offer_markdown" value="1" <?php checked( $offer_markdown ); ?>>
                            Enable pre-built markdown serving for <code>Accept: text/markdown</code> requests.  When enabled, all published content is converted to CommonMark and cached.  Agents requesting markdown for content that has been converted will receive the cached <code>.md</code> file; content not yet converted will fall through to normal HTML rendering.
                        </label>
                        <p class="description">Enabling this toggle automatically queues all published posts for conversion in the background.  No separate "pre-warm" step is needed.  Disabling stops markdown serving immediately.</p>
                        <?php if ( $offer_markdown ) : ?>
                            <?php if ( mdf_backfill_in_progress() ) : ?>
                                <p class="description" style="color:#2271b1;">
                                    <?php
                                    $total     = (int) get_option( 'mdf_backfill_total', 0 );
                                    $processed = (int) get_option( 'mdf_backfill_processed', 0 );
                                    printf(
                                        'Backfill in progress: %d of %d pieces of content converted.',
                                        $processed,
                                        $total
                                    );
                                    ?>
                                </p>
                            <?php else : ?>
                                <p class="description">
                                    <?php
                                    $cached   = count( mdf_list_cached_post_ids() );
                                    $eligible = mdf_eligible_content_count();
                                    if ( $eligible === 0 ) {
                                        echo 'No published content yet to convert.';
                                    } else {
                                        printf(
                                            '%d of %d published posts/pages have a cached markdown version.',
                                            $cached,
                                            $eligible
                                        );
                                    }
                                    ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php mdf_render_negotiation_status_block(); ?>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="mdf_save_settings" class="button button-primary" value="Save Settings"></p>
        </form>
        <hr>
        <h2>llms.txt</h2>
        <?php echo $llms_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped fragments. ?>
        <?php if ( get_option( 'mdf_static_llms_txt_detected', false ) ) : ?>
            <div class="notice notice-warning inline">
                <p><strong>A static llms.txt exists in your web root.</strong> The web server serves that file before WordPress runs, so it takes priority over anything shown here — the content in this editor will not be served until that file is removed.</p>
            </div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'mdf_llms_txt_save' ); ?>
            <p>This is the content served at <a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code></a>.
            It starts as the bundled default template; saving your own content stores it in the database so plugin upgrades no longer overwrite it.</p>
            <textarea name="mdf_llms_txt_content" id="mdf_llms_txt_content" rows="16" class="large-text code" spellcheck="false"><?php echo esc_textarea( $llms_value ); ?></textarea>
            <p class="description">
                <?php if ( $llms_custom ) : ?>
                    Currently serving your saved custom content.
                <?php else : ?>
                    Currently serving the bundled default template.
                <?php endif; ?>
                Served copies may be cached by browsers and proxies for up to an hour, so changes can take a moment to appear. "Reset to default" removes your saved content and restores the bundled template.
            </p>
            <p class="submit">
                <input type="submit" name="mdf_llms_txt_save" class="button button-primary" value="Save llms.txt">
                <input type="submit" name="mdf_llms_txt_reset" class="button" value="Reset to default" onclick="return confirm('Discard your custom llms.txt and restore the bundled default?');">
            </p>
        </form>
        <hr>
        <h2>About MDF Analytics</h2>
        <p>This plugin is Phase 1 of the <a href="https://github.com/bitcryptic-gw/mdf" target="_blank">MDF (Markdown First)</a> ecosystem.
        It tracks AI agent traffic and <code>Accept: text/markdown</code> requests to your site, giving you visibility into potential earnings
        before you set up a wallet or serve markdown content.</p>
        <p><strong>Phase 2 (roadmap):</strong> Connect a Lightning or Base wallet and start earning from agents that request markdown.</p>
        <p><strong>Phase 3 — shipped:</strong> Published content is automatically converted to CommonMark and cached — no manual work required. Enable it above under "Offer markdown to agents."</p>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// Dashboard
// ---------------------------------------------------------------------------

function mdf_render_dashboard(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $table        = $wpdb->prefix . MDF_TABLE;
    $window       = isset( $_GET['window'] ) ? (int) $_GET['window'] : 30;
    $window       = in_array( $window, [ 7, 30, 90 ], true ) ? $window : 30;
    $since        = gmdate( 'Y-m-d H:i:s', strtotime( "-{$window} days" ) );
    $sat_rate     = (int)   get_option( 'mdf_sat_rate',     1 );
    $usdc_rate    = (float) get_option( 'mdf_usdc_rate',    0.001 );
    $use_currency =         get_option( 'mdf_use_currency', 'sats' );

    // Summary counts — type 3 (internal/monitor) tracked separately, excluded from earnings
    $total_requests    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE requested_at >= %s", $since ) );
    $known_agents      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE requested_at >= %s AND visitor_type = 2", $since ) );
    $likely_agents     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE requested_at >= %s AND visitor_type = 1", $since ) );
    $internal_hits     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE requested_at >= %s AND visitor_type = 3", $since ) );
    $markdown_requests = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE requested_at >= %s AND wants_markdown = 1", $since ) );

    // Estimated earnings
    if ( $use_currency === 'sats' ) {
        $est_earned = $markdown_requests * $sat_rate;
        $est_missed = ( $known_agents + $likely_agents - $markdown_requests ) * $sat_rate;
        $currency_label = 'sats';
    } else {
        $est_earned = round( $markdown_requests * $usdc_rate, 4 );
        $est_missed = round( ( $known_agents + $likely_agents - $markdown_requests ) * $usdc_rate, 4 );
        $currency_label = 'USDC';
    }

    // Top agent snippets — exclude internal/monitor (type 3)
    $top_agents = $wpdb->get_results( $wpdb->prepare(
        "SELECT ua_snippet, COUNT(*) as hits FROM {$table}
         WHERE requested_at >= %s AND visitor_type IN (1, 2)
         GROUP BY ua_snippet ORDER BY hits DESC LIMIT 10",
        $since
    ) );

    // Top internal/monitor snippets
    $top_internal = $wpdb->get_results( $wpdb->prepare(
        "SELECT ua_snippet, COUNT(*) as hits FROM {$table}
         WHERE requested_at >= %s AND visitor_type = 3
         GROUP BY ua_snippet ORDER BY hits DESC LIMIT 10",
        $since
    ) );

    // Top requested paths (markdown only)
    $top_paths = $wpdb->get_results( $wpdb->prepare(
        "SELECT path, COUNT(*) as hits FROM {$table}
         WHERE requested_at >= %s AND wants_markdown = 1
         GROUP BY path ORDER BY hits DESC LIMIT 10",
        $since
    ) );

    // Daily trend (inbound agent requests only, excludes internal/monitor) — for chart
    $daily = $wpdb->get_results( $wpdb->prepare(
        "SELECT DATE(requested_at) as day, COUNT(*) as hits
         FROM {$table}
         WHERE requested_at >= %s AND visitor_type IN (1, 2)
         GROUP BY DATE(requested_at)
         ORDER BY day ASC",
        $since
    ) );

    ?>
    <div class="wrap" id="mdf-dashboard">
        <h1>MDF Analytics</h1>

        <nav class="mdf-window-nav" style="margin-bottom:16px;">
            <?php foreach ( [ 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days' ] as $w => $label ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'mdf-analytics', 'window' => $w ], admin_url( 'admin.php' ) ) ); ?>"
                   class="button <?php echo $w === $window ? 'button-primary' : ''; ?>"
                   style="margin-right:4px;"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php mdf_stat_cards( $total_requests, $known_agents, $likely_agents, $internal_hits, $markdown_requests, $est_earned, $est_missed, $currency_label ); ?>

        <?php if ( ! empty( $daily ) ) : ?>
            <h2 style="margin-top:32px;">Agent requests — daily trend</h2>
            <?php mdf_sparkline( $daily ); ?>
        <?php endif; ?>

        <div style="display:flex; gap:32px; flex-wrap:wrap; margin-top:32px;">
            <?php if ( ! empty( $top_agents ) ) : ?>
            <div style="flex:1; min-width:280px;">
                <h2>Top agents</h2>
                <table class="widefat striped">
                    <thead><tr><th>Agent</th><th>Requests</th></tr></thead>
                    <tbody>
                    <?php foreach ( $top_agents as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row->ua_snippet ); ?></td>
                            <td><?php echo (int) $row->hits; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $top_internal ) ) : ?>
            <div style="flex:1; min-width:280px;">
                <h2>Internal &amp; monitors <span style="font-size:12px;font-weight:400;color:#888;">(excluded from earnings)</span></h2>
                <table class="widefat striped">
                    <thead><tr><th>Client</th><th>Requests</th></tr></thead>
                    <tbody>
                    <?php foreach ( $top_internal as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row->ua_snippet ); ?></td>
                            <td><?php echo (int) $row->hits; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $top_paths ) ) : ?>
            <div style="flex:1; min-width:280px;">
                <h2>Top markdown-requested paths</h2>
                <table class="widefat striped">
                    <thead><tr><th>Path</th><th>Requests</th></tr></thead>
                    <tbody>
                    <?php foreach ( $top_paths as $row ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $row->path ); ?></code></td>
                            <td><?php echo (int) $row->hits; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php mdf_phase2_callout(); ?>

        <p style="margin-top:32px; color:#888; font-size:12px;">
            MDF Analytics v<?php echo esc_html( MDF_VERSION ); ?> —
            <a href="https://github.com/bitcryptic-gw/mdf" target="_blank">MDF on GitHub</a> —
            Data retained for <?php echo MDF_LOG_DAYS; ?> days.
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'mdf-settings' ], admin_url( 'admin.php' ) ) ); ?>">Settings</a>
        </p>
    </div>
    <?php
}

function mdf_stat_cards( int $total, int $known, int $likely, int $internal, int $markdown, $earned, $missed, string $currency ): void {
    $cards = [
        [ 'label' => 'Total logged requests',          'value' => number_format( $total ),    'color' => '#2271b1' ],
        [ 'label' => 'Known AI agents',                'value' => number_format( $known ),    'color' => '#8c5bd4' ],
        [ 'label' => 'Likely automated',               'value' => number_format( $likely ),   'color' => '#9e6b00' ],
        [ 'label' => 'Internal / monitors',            'value' => number_format( $internal ), 'color' => '#aaa' ],
        [ 'label' => 'Wanted markdown',                'value' => number_format( $markdown ), 'color' => '#1e7e34' ],
        [ 'label' => "Estimated earned ({$currency})", 'value' => number_format( $earned ),   'color' => '#1e7e34' ],
        [ 'label' => "Estimated missed ({$currency})", 'value' => number_format( $missed ),   'color' => '#c0392b' ],
    ];
    echo '<div style="display:flex; gap:16px; flex-wrap:wrap;">';
    foreach ( $cards as $c ) {
        printf(
            '<div style="background:#fff; border:1px solid #ddd; border-top:4px solid %s; border-radius:4px; padding:16px 20px; min-width:140px; flex:1;">
                <div style="font-size:28px; font-weight:700; color:%s;">%s</div>
                <div style="font-size:13px; color:#555; margin-top:4px;">%s</div>
            </div>',
            esc_attr( $c['color'] ),
            esc_attr( $c['color'] ),
            esc_html( $c['value'] ),
            esc_html( $c['label'] )
        );
    }
    echo '</div>';
}

function mdf_sparkline( array $daily ): void {
    $max = max( array_column( $daily, 'hits' ) ) ?: 1;
    $w   = 600;
    $h   = 80;
    $n   = count( $daily );
    $pad = 4;

    echo '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" style="max-width:600px;display:block;margin-bottom:8px;" xmlns="http://www.w3.org/2000/svg">';

    if ( $n === 1 ) {
        // Single day — render a single centred bar
        $bar_w = 40;
        $bar_h = ( $daily[0]->hits / $max ) * ( $h - $pad * 2 );
        $x     = ( $w - $bar_w ) / 2;
        $y     = $h - $pad - $bar_h;
        echo '<rect x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . $bar_w . '" height="' . round( $bar_h, 1 ) . '" fill="#2271b1" rx="2"/>';
        echo '<text x="' . round( $w / 2, 1 ) . '" y="' . ( $h - $pad + 12 ) . '" text-anchor="middle" font-size="10" fill="#888">' . esc_html( $daily[0]->day ) . '</text>';
    } else {
        // Multiple days — render bar chart
        $bar_w    = max( 4, ( $w - $pad * 2 ) / $n - 2 );
        $slot_w   = ( $w - $pad * 2 ) / $n;
        foreach ( $daily as $i => $row ) {
            $bar_h = ( $row->hits / $max ) * ( $h - $pad * 2 );
            $x     = $pad + $i * $slot_w + ( $slot_w - $bar_w ) / 2;
            $y     = $h - $pad - $bar_h;
            echo '<rect x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( $bar_w, 1 ) . '" height="' . round( $bar_h, 1 ) . '" fill="#2271b1" rx="1"/>';
        }
        // X-axis: first and last date labels
        echo '<text x="' . $pad . '" y="' . ( $h + 12 ) . '" font-size="10" fill="#888">' . esc_html( $daily[0]->day ) . '</text>';
        echo '<text x="' . ( $w - $pad ) . '" y="' . ( $h + 12 ) . '" text-anchor="end" font-size="10" fill="#888">' . esc_html( $daily[ $n - 1 ]->day ) . '</text>';
    }

    echo '</svg>';
}

function mdf_phase2_callout(): void {
    ?>
    <div style="margin-top:32px; background:#f0f6fc; border:1px solid #c3d9f0; border-left:4px solid #2271b1; border-radius:4px; padding:16px 20px;">
        <strong>Ready to start earning?</strong>
        Phase 2 of MDF Analytics (coming soon) will let you connect a Lightning wallet or Base USDC wallet and serve markdown content to AI agents — converting the traffic above into real payments.
        <a href="https://github.com/bitcryptic-gw/mdf" target="_blank" style="margin-left:8px;">Learn more about MDF →</a>
    </div>
    <?php
}

// ---------------------------------------------------------------------------
// DB version upgrade check on admin init
// ---------------------------------------------------------------------------

add_action( 'admin_init', 'mdf_maybe_upgrade_db' );

function mdf_maybe_upgrade_db(): void {
    if ( get_option( 'mdf_db_version' ) !== MDF_VERSION ) {
        mdf_create_table();
        mdf_schedule_purge();
        mdf_schedule_negotiation_self_test();
        mdf_maybe_register_wpsc_adapter();
    }
}
