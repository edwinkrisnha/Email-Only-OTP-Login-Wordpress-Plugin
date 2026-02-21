<?php
defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────
// RATE LIMITING
// ─────────────────────────────────────────────────────────────

/**
 * Return the best available client IP address.
 */
function otp_login_get_client_ip() {
    foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            // X-Forwarded-For can be a comma-separated list; use the first entry.
            if ( strpos( $ip, ',' ) !== false ) {
                $ip = trim( explode( ',', $ip )[0] );
            }
            return $ip;
        }
    }
    return '';
}

/**
 * Returns true if the current IP is within the allowed rate limit, false if blocked.
 */
function otp_login_check_rate_limit() {
    $s   = otp_login_settings();
    $max = (int) $s['otp_rate_limit'];
    $key = 'otp_rl_' . md5( otp_login_get_client_ip() );

    return (int) get_transient( $key ) < $max;
}

/**
 * Increment the OTP request counter for the current IP.
 */
function otp_login_increment_rate_limit() {
    $s      = otp_login_settings();
    $window = (int) $s['otp_rate_window'];
    $key    = 'otp_rl_' . md5( otp_login_get_client_ip() );
    $count  = (int) get_transient( $key );
    set_transient( $key, $count + 1, $window * MINUTE_IN_SECONDS );
}

// ─────────────────────────────────────────────────────────────
// XML-RPC PROTECTION
// ─────────────────────────────────────────────────────────────

add_filter( 'xmlrpc_enabled', 'otp_login_maybe_disable_xmlrpc' );
function otp_login_maybe_disable_xmlrpc( $enabled ) {
    $s = otp_login_settings();
    return empty( $s['disable_xmlrpc'] ) ? $enabled : false;
}

// ─────────────────────────────────────────────────────────────
// REST API PROTECTION
// ─────────────────────────────────────────────────────────────

/**
 * Block Authorization-header-based authentication (Basic Auth, Application Passwords)
 * when block_passwords is enabled. Cookie-authenticated sessions (from OTP login) are unaffected.
 */
add_filter( 'rest_authentication_errors', 'otp_login_protect_rest_api' );
function otp_login_protect_rest_api( $result ) {
    // If another plugin has already resolved auth, respect it.
    if ( null !== $result ) {
        return $result;
    }

    $s = otp_login_settings();
    if ( empty( $s['block_passwords'] ) ) {
        return $result;
    }

    $has_auth_header = ! empty( $_SERVER['HTTP_AUTHORIZATION'] )
                    || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

    if ( $has_auth_header ) {
        return new WP_Error(
            'otp_rest_auth_blocked',
            __( 'Password-based REST API authentication is disabled. Please log in via the OTP login form.', 'otp-login' ),
            [ 'status' => 401 ]
        );
    }

    return $result;
}
