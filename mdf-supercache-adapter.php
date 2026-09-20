<?php
/**
 * MDF Analytics — WP Super Cache adapter.
 *
 * Registered with WP Super Cache through its `wpsc_plugins` setting, so WP
 * Super Cache includes this file during its early cache phase
 * (wp-cache-phase1.php) before normal WordPress plugins load. Its only job is
 * to make WP Super Cache treat a request carrying `Accept: text/markdown` as a
 * distinct cache entry and to keep the static supercache file from being
 * served to such a request.
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

if ( ! defined( 'MDF_WPSC_MARKER' ) ) {
    define( 'MDF_WPSC_MARKER', 'mdfmd' );
}

if ( ! function_exists( 'mdf_wpsc_accept_marker' ) ) {
    /**
     * Append a fixed marker to the cookie-derived cache-key component when the
     * request asks for markdown.
     *
     * WP Super Cache concatenates this value into the cache key and also uses
     * it to decide whether a static supercache file may be served. A non-empty
     * value therefore both gives markdown requests their own cache bucket and
     * makes the static-file path stand aside so the request reaches PHP, where
     * MDF Analytics serves the markdown.
     *
     * The marker is a fixed literal. No part of the client-supplied Accept
     * header is ever interpolated into the key.
     *
     * @param string $cookies Cookie-derived cache-key component.
     * @return string Unchanged, or with the markdown marker appended.
     */
    function mdf_wpsc_accept_marker( $cookies ) {
        if ( ! is_string( $cookies ) ) {
            $cookies = '';
        }

        if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) || ! is_string( $_SERVER['HTTP_ACCEPT'] ) ) {
            return $cookies;
        }

        if ( stripos( $_SERVER['HTTP_ACCEPT'], 'text/markdown' ) === false ) {
            return $cookies;
        }

        return $cookies . MDF_WPSC_MARKER;
    }
}

add_cacheaction( 'wp_cache_get_cookies_values', 'mdf_wpsc_accept_marker' );
