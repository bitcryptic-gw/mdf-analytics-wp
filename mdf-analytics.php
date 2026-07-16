<?php
/**
 * Plugin Name: MDF Analytics
 * Plugin URI:  https://github.com/bitcryptic-gw/mdf
 * Description: Tracks AI agent traffic and Accept: text/markdown requests. Phase 1 of MDF (Markdown First) ecosystem support — visibility dashboard with estimated earnings. No content modification, no payment processing.
 * Version:     0.1.4
 * Author:      Gary Walker (BitCryptic™) & Graham Hall (Slepner)
 * Author URI:  https://bitcryptic.com
 * License:     MIT
 * Text Domain: mdf-analytics
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'MDF_VERSION',    '0.1.4' );
define( 'MDF_TABLE',      'mdf_requests' );
define( 'MDF_LOG_DAYS',   90 );       // retention window
define( 'MDF_PURGE_FREQ', 'daily' );  // WP-Cron schedule

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

    // Apply the_content() filters so shortcodes / blocks are resolved to HTML.
    $html = apply_filters( 'the_content', get_the_content( null, false, $post ) );

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

    // Compute hash of new content.
    $html = apply_filters( 'the_content', get_the_content( null, false, $post ) );
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
    if ( ! wp_next_scheduled( 'mdf_markdown_rebuild', $args ) ) {
        wp_schedule_single_event( time() + 5, 'mdf_markdown_rebuild', $args );
    }
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

    // Schedule the first batch.
    if ( ! wp_next_scheduled( 'mdf_backfill_batch' ) ) {
        wp_schedule_single_event( time() + 3, 'mdf_backfill_batch' );
    }
}

/**
 * Check whether a backfill is currently in progress.
 */
function mdf_backfill_in_progress(): bool {
    $queue = get_option( 'mdf_backfill_queue', [] );
    return count( $queue ) > 0;
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
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'mdf_activate' );
register_deactivation_hook( __FILE__, 'mdf_deactivate' );

function mdf_activate(): void {
    mdf_create_table();
    mdf_schedule_purge();
    mdf_create_cache_dirs();
}

function mdf_deactivate(): void {
    wp_clear_scheduled_hook( 'mdf_purge_old_records' );
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

    $file = plugin_dir_path( __FILE__ ) . 'llms.txt';

    if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
        return;
    }

    $mtime  = filemtime( $file );
    $size   = filesize( $file );
    $if_mod = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';

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
    header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
    header( 'Cache-Control: public, max-age=3600' );

    if ( $method === 'HEAD' ) {
        exit;
    }

    readfile( $file );
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

function mdf_render_settings(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['mdf_save_settings'] ) && check_admin_referer( 'mdf_settings_save' ) ) {
        $old_offer = (bool) get_option( 'mdf_offer_markdown', false );

        update_option( 'mdf_sat_rate',       absint( $_POST['mdf_sat_rate'] ?? 1 ) );
        update_option( 'mdf_usdc_rate',      floatval( $_POST['mdf_usdc_rate'] ?? 0.001 ) );
        update_option( 'mdf_use_currency',   sanitize_text_field( $_POST['mdf_use_currency'] ?? 'sats' ) );

        $new_offer = isset( $_POST['mdf_offer_markdown'] ) && $_POST['mdf_offer_markdown'] === '1';
        update_option( 'mdf_offer_markdown', $new_offer );

        // Toggle just flipped from off → on: kick off full backfill.
        if ( ! $old_offer && $new_offer ) {
            update_option( 'mdf_backfill_notice_dismissed', false );
            mdf_start_backfill();
            echo '<div class="notice notice-info"><p><strong>MDF Analytics:</strong> Markdown offering enabled. Building markdown versions of all published posts — agents will be offered markdown as each post finishes. <a href="' . esc_url( admin_url( 'admin.php?page=mdf-analytics' ) ) . '">View dashboard →</a></p></div>';
        } elseif ( $old_offer && ! $new_offer ) {
            // Toggle flipped off: clear the backfill queue.
            update_option( 'mdf_backfill_queue', [] );
            update_option( 'mdf_backfill_total', null );
            update_option( 'mdf_backfill_processed', 0 );
        }

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
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
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p class="submit"><input type="submit" name="mdf_save_settings" class="button button-primary" value="Save Settings"></p>
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
    }
}
