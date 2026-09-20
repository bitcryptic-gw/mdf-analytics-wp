<?php
/**
 * MDF Analytics — WP Super Cache adapter.
 *
 * Registered with WP Super Cache through its `wpsc_plugins` setting, so WP
 * Super Cache includes this file during its early cache phase
 * (wp-cache-phase1.php) before normal WordPress plugins load.
 *
 * For a request that asks for markdown this adapter disables WP Super Cache
 * entirely for that request: the cache is neither read nor written. That is
 * deliberate. An earlier design gave markdown requests their own cache key via
 * the `wp_cache_get_cookies_values` marker; when such a marked request fell
 * through to normal HTML rendering (no cached .md yet — activation before
 * backfill completes, new content, any URL not yet converted), WP Super Cache
 * cached that HTML under the markdown key and then served it to every later
 * markdown request. Disabling the cache for markdown requests removes that
 * failure mode completely: no marker, no separate key, no encoding handling.
 *
 * The file is deliberately inert when included on its own: no output, no side
 * effects beyond registering a single cache action, and no assumptions about
 * WordPress being loaded.
 *
 * @package MDF_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

if ( ! function_exists( 'add_cacheaction' ) ) {
    return;
}

if ( ! function_exists( 'mdf_wpsc_is_markdown_request' ) ) {
    /**
     * Whether the current request is a GET/HEAD request that accepts markdown.
     *
     * Kept at least as broad as mdf_maybe_serve_markdown()'s own test — a
     * case-insensitive `text/markdown` anywhere in `Accept`. A missing,
     * non-string or oversized `Accept` is treated as "not markdown".
     *
     * @return bool
     */
    function mdf_wpsc_is_markdown_request() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! is_string( $_SERVER['REQUEST_METHOD'] ) ) {
            return false;
        }

        $method = strtoupper( $_SERVER['REQUEST_METHOD'] );
        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return false;
        }

        if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) || ! is_string( $_SERVER['HTTP_ACCEPT'] ) ) {
            return false;
        }

        $accept = $_SERVER['HTTP_ACCEPT'];
        if ( '' === $accept || strlen( $accept ) > 2048 ) {
            return false;
        }

        return stripos( $accept, 'text/markdown' ) !== false;
    }
}

if ( ! function_exists( 'mdf_wpsc_markdown_bypass' ) ) {
    /**
     * Disable WP Super Cache for markdown requests during its `cache_init`
     * action.
     *
     * Phase 1 checks `$cache_enabled` immediately after `cache_init` and returns
     * before `wp_cache_serve_cache_file()`, so nothing is read; and
     * `wp_cache_postload()` returns before `wp_cache_phase2()`, so the output
     * buffer callback is never installed and nothing is written. The
     * `DONOTCACHEPAGE` constant is belt-and-braces.
     *
     * @param mixed $value Unused cache-action value.
     * @return mixed The value, unchanged.
     */
    function mdf_wpsc_markdown_bypass( $value = '' ) {
        if ( ! mdf_wpsc_is_markdown_request() ) {
            return $value;
        }

        global $cache_enabled;
        $cache_enabled = false;

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        return $value;
    }
}

add_cacheaction( 'cache_init', 'mdf_wpsc_markdown_bypass' );
