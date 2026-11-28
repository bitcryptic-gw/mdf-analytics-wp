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
     * WP Super Cache's phase 1 (`wp-cache-phase1.php`) checks `$cache_enabled`
     * immediately after `cache_init` and returns before
     * `wp_cache_serve_cache_file()`, so nothing is read; and
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

        // Marker, set before DONOTCACHEPAGE: the plugin's serve path must be
        // able to tell this deliberate define apart from a third party's
        // (see mdf_third_party_no_cache()).
        if ( ! defined( 'MDF_WPSC_MARKDOWN_BYPASS' ) ) {
            define( 'MDF_WPSC_MARKDOWN_BYPASS', true );
        }

        global $cache_enabled;
        $cache_enabled = false;

        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        return $value;
    }
}

// ---------------------------------------------------------------------------
// X-MDF-Version on cached HTML
//
// A page-cache hit is answered without loading WordPress, so the plugin's own
// header code never runs. The plugin leaves, in <content dir>/mdf-cache-markers/:
//
//   - one `<sha256>.path` file for every URL that has a servable markdown
//     variant, and
//   - a `confirmed` flag that exists only while the site's MDF claim is
//     confirmed (offering on, and negotiation working or owner-verified).
//
// This callback runs on WP Super Cache's `cache_init`, which phase 1 dispatches
// before `wp_cache_serve_cache_file()` and which nothing in phase 1 clears
// headers after. So a header set here travels with the cached page that PHP
// serves. Two stat() calls, no reads, and no WordPress functions or database
// access — this runs before WordPress loads.
//
// Fail-safe: if either file is missing, or the path is not a plain GET/HEAD
// request path, no header is sent. A missing header costs a validator warning;
// a wrong one would claim a variant that does not exist.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'mdf_wpsc_marker_dir' ) ) {
    /**
     * Marker directory, derived without WordPress state.
     *
     * WP_CONTENT_DIR is defined by wp-settings.php before advanced-cache.php is
     * loaded, so it is available here. The fallback (this file lives at
     * <content>/plugins/mdf-analytics/) covers a bootstrap that has not defined
     * it; if neither works the markers are simply not found, which withholds
     * the header rather than sending a wrong one.
     */
    function mdf_wpsc_marker_dir() {
        $content = defined( 'WP_CONTENT_DIR' )
            ? WP_CONTENT_DIR
            : dirname( dirname( __DIR__ ) );

        return rtrim( $content, '/\\' ) . '/mdf-cache-markers';
    }
}

if ( ! function_exists( 'mdf_wpsc_normalise_path' ) ) {
    /**
     * Normalise a request path to the marker key form.
     *
     * The plugin implements the same rule in mdf_normalise_path_for_marker();
     * the test suite asserts the two agree. Kept as a copy because this file
     * must stay loadable without the plugin.
     *
     *   - path only: the query string is dropped;
     *   - a leading slash is guaranteed, and an empty path is '/';
     *   - percent-escape hex digits are uppercased (%c3%a9 == %C3%A9);
     *   - trailing slashes are stripped (/about/ == /about), front page '/';
     *   - nothing is percent-decoded and duplicate slashes are not collapsed,
     *     so %2e%2e, // or %00 simply fail to match a marker.
     *
     * @param string $path Request path.
     * @return string
     */
    function mdf_wpsc_normalise_path( $path ) {
        $path = (string) $path;

        $cut  = strcspn( $path, '?#' );
        $path = substr( $path, 0, $cut );

        $path = (string) preg_replace_callback(
            '/%[0-9a-fA-F]{2}/',
            static function ( $matches ) {
                return strtoupper( $matches[0] );
            },
            $path
        );

        if ( '' === $path ) {
            return '/';
        }

        if ( '/' !== $path[0] ) {
            $path = '/' . $path;
        }

        $path = rtrim( $path, '/' );

        return '' === $path ? '/' : $path;
    }
}

if ( ! function_exists( 'mdf_wpsc_send_cached_html_version' ) ) {
    /**
     * Add X-MDF-Version to a cached HTML response whose URL has a servable
     * markdown variant, while the site's claim is confirmed.
     *
     * `header( ..., true )` replaces rather than appends, so a cache-miss
     * render — where the plugin's own code also sends the header — still
     * produces exactly one copy.
     *
     * @param mixed $value Unused cache-action value.
     * @return mixed The value, unchanged.
     */
    function mdf_wpsc_send_cached_html_version( $value = '' ) {
        // Markdown requests bypass the cache and the plugin sends their header.
        if ( mdf_wpsc_is_markdown_request() ) {
            return $value;
        }

        if ( ! isset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ) ) {
            return $value;
        }

        $method = strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return $value;
        }

        $uri = $_SERVER['REQUEST_URI'];
        if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > 2048 ) {
            return $value;
        }

        $dir = mdf_wpsc_marker_dir();

        if ( ! @is_file( $dir . '/confirmed' ) ) {
            return $value;
        }

        $normalised = mdf_wpsc_normalise_path( $uri );

        // The plugin's own discovery documents are answered by PHP, not from
        // the page cache, and have no markdown variant. Never add the header
        // to them.
        if ( '/mdf.json' === $normalised || '/llms.txt' === $normalised ) {
            return $value;
        }

        $key = hash( 'sha256', $normalised );
        if ( ! @is_file( $dir . '/' . $key . '.path' ) ) {
            return $value;
        }

        if ( ! headers_sent() ) {
            // MDF_PROTOCOL_VERSION is 1; the constant is not defined here
            // because the plugin has not loaded.
            header( 'X-MDF-Version: 1', true );
        }

        return $value;
    }
}

add_cacheaction( 'cache_init', 'mdf_wpsc_markdown_bypass' );
add_cacheaction( 'cache_init', 'mdf_wpsc_send_cached_html_version' );
