<?php
defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────
// 1. REPLACE THE DEFAULT LOGIN FORM
// ─────────────────────────────────────────────────────────────

add_action( 'login_form_login',     'otp_login_handle_page' );
add_action( 'login_form_otp_magic', 'otp_login_handle_magic_action' );

function otp_login_handle_page() {
    $action = isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login';
    if ( $action !== 'login' ) {
        return;
    }

    if ( isset( $_POST['otp_login_verify'] ) ) {
        otp_login_verify_otp();
        return;
    }

    if ( isset( $_POST['otp_login_resend'] ) ) {
        otp_login_resend_otp();
        return;
    }

    if ( isset( $_POST['otp_login_request'] ) ) {
        otp_login_request_otp();
        return;
    }

    otp_login_render_username_form();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. HANDLE OTP REQUEST
// ─────────────────────────────────────────────────────────────

function otp_login_request_otp() {
    $s          = otp_login_settings();
    $email_only = ! empty( $s['email_only_login'] );
    $method     = $s['login_method']; // 'otp', 'magic', 'both'
    $username   = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';

    if ( empty( $username ) ) {
        $empty_msg = $email_only
            ? __( 'Please enter an email address.', 'otp-login' )
            : __( 'Please enter a username.', 'otp-login' );
        otp_login_render_username_form( $empty_msg );
        exit;
    }

    if ( $email_only && ! is_email( $username ) ) {
        otp_login_render_username_form( __( 'Please enter a valid email address.', 'otp-login' ) );
        exit;
    }

    // Rate limit check — before any user lookup to avoid enumeration timing differences.
    if ( ! otp_login_check_rate_limit() ) {
        otp_login_log_event( $username, 'rate_limited' );
        otp_login_render_username_form( __( 'Too many requests. Please wait before requesting another code.', 'otp-login' ) );
        exit;
    }

    $user = null;
    if ( ! $email_only ) {
        $user = get_user_by( 'login', $username );
    }
    if ( ! $user ) {
        $user = get_user_by( 'email', $username );
    }

    // Increment counter regardless of whether the user exists (prevents timing-based enumeration).
    otp_login_increment_rate_limit();

    // User not found — generic message, no enumeration.
    if ( ! $user ) {
        otp_login_render_otp_sent_message( $method );
        exit;
    }

    // Domain check — generic message if blocked, no enumeration.
    if ( ! otp_login_is_allowed_domain( $user->user_email ) ) {
        otp_login_render_otp_sent_message( $method );
        exit;
    }

    // Generate OTP and/or magic link based on the chosen login method.
    $otp       = '';
    $token     = '';
    $magic_url = '';

    if ( in_array( $method, [ 'otp', 'both' ], true ) ) {
        $otp   = otp_login_generate_otp( (int) $s['otp_length'] );
        $token = otp_login_store_otp( $user->ID, $otp, (int) $s['otp_expiry_minutes'] );
    }

    if ( in_array( $method, [ 'magic', 'both' ], true ) ) {
        $magic_token = otp_login_store_magic_link( $user->ID, (int) $s['otp_expiry_minutes'] );
        $magic_url   = otp_login_magic_url( $magic_token );
    }

    $sent = otp_login_send_otp_email( $user, $otp, $magic_url );

    if ( ! $sent ) {
        otp_login_render_username_form( __( 'Could not send OTP email. Please try again.', 'otp-login' ) );
        exit;
    }

    otp_login_log_event( $user->user_email, 'otp_sent', $user->ID );

    // Magic-only: no code to enter, just tell the user to check email.
    if ( $method === 'magic' ) {
        otp_login_render_otp_sent_message( $method );
    } else {
        otp_login_render_otp_form( $token );
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. HANDLE OTP VERIFICATION
// ─────────────────────────────────────────────────────────────

function otp_login_verify_otp() {
    $submitted_otp = isset( $_POST['otp_code'] )  ? sanitize_text_field( wp_unslash( $_POST['otp_code'] ) )  : '';
    $token         = isset( $_POST['otp_token'] ) ? sanitize_text_field( wp_unslash( $_POST['otp_token'] ) ) : '';

    if ( empty( $submitted_otp ) || empty( $token ) ) {
        otp_login_render_otp_form( $token, __( 'Please enter the OTP code.', 'otp-login' ) );
        exit;
    }

    // Peek at the transient now — validate may delete it before we can log.
    $peek       = get_transient( 'otp_login_' . $token );
    $log_uid    = isset( $peek['user_id'] ) ? (int) $peek['user_id'] : 0;
    $log_ident  = $log_uid ? ( get_userdata( $log_uid )->user_email ?? '' ) : '';

    $result = otp_login_validate_otp( $token, $submitted_otp );

    if ( is_wp_error( $result ) ) {
        $code  = $result->get_error_code();
        $event = 'otp_failed';
        if ( $code === 'otp_max_attempts' ) {
            $event = 'otp_exhausted';
        } elseif ( $code === 'otp_expired' ) {
            $event = 'otp_expired';
        }
        otp_login_log_event( $log_ident, $event, $log_uid );

        if ( $code === 'otp_max_attempts' ) {
            otp_login_render_username_form( $result->get_error_message() );
        } else {
            otp_login_render_otp_form( $token, $result->get_error_message() );
        }
        exit;
    }

    otp_login_log_event( $log_ident, 'otp_verified', (int) $result );
    otp_login_complete_login( (int) $result );
}

// ─────────────────────────────────────────────────────────────
// 4. HANDLE RESEND
// ─────────────────────────────────────────────────────────────

function otp_login_resend_otp() {
    $token = isset( $_POST['otp_token'] ) ? sanitize_text_field( wp_unslash( $_POST['otp_token'] ) ) : '';

    if ( empty( $token ) ) {
        otp_login_render_username_form( __( 'Session expired. Please start over.', 'otp-login' ) );
        exit;
    }

    $data = get_transient( 'otp_login_' . $token );
    if ( ! $data ) {
        otp_login_render_username_form( __( 'Session expired. Please start over.', 'otp-login' ) );
        exit;
    }

    $user_id  = (int) $data['user_id'];
    $s        = otp_login_settings();
    $cooldown = (int) $s['otp_resend_cooldown'];
    $cd_key   = 'otp_resend_cd_' . $user_id;
    $cd_exp   = get_transient( $cd_key );

    if ( $cd_exp && time() < (int) $cd_exp ) {
        $remaining = (int) $cd_exp - time();
        otp_login_render_otp_form(
            $token,
            sprintf(
                /* translators: %d: seconds remaining */
                _n( 'Please wait %d second before requesting a new code.', 'Please wait %d seconds before requesting a new code.', $remaining, 'otp-login' ),
                $remaining
            )
        );
        exit;
    }

    $user = get_userdata( $user_id );
    if ( ! $user ) {
        otp_login_render_username_form( __( 'User not found. Please start over.', 'otp-login' ) );
        exit;
    }

    // Delete the old token and arm the cooldown before sending, to prevent double-sends.
    delete_transient( 'otp_login_' . $token );
    set_transient( $cd_key, time() + $cooldown, $cooldown );

    $method    = $s['login_method'];
    $otp       = '';
    $new_token = '';
    $magic_url = '';

    if ( in_array( $method, [ 'otp', 'both' ], true ) ) {
        $otp       = otp_login_generate_otp( (int) $s['otp_length'] );
        $new_token = otp_login_store_otp( $user_id, $otp, (int) $s['otp_expiry_minutes'] );
    }

    if ( in_array( $method, [ 'magic', 'both' ], true ) ) {
        $magic_token = otp_login_store_magic_link( $user_id, (int) $s['otp_expiry_minutes'] );
        $magic_url   = otp_login_magic_url( $magic_token );
    }

    $sent = otp_login_send_otp_email( $user, $otp, $magic_url );

    if ( ! $sent ) {
        otp_login_render_otp_form( $new_token, __( 'Could not send OTP email. Please try again.', 'otp-login' ) );
        exit;
    }

    otp_login_log_event( $user->user_email, 'otp_sent', $user_id );
    otp_login_render_otp_form( $new_token, '', __( 'A new code has been sent to your email.', 'otp-login' ) );
    exit;
}

// ─────────────────────────────────────────────────────────────
// 5. HANDLE MAGIC LINK
// ─────────────────────────────────────────────────────────────

function otp_login_handle_magic_action() {
    $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

    if ( empty( $token ) ) {
        otp_login_render_username_form( __( 'Invalid login link.', 'otp-login' ) );
        exit;
    }

    $data = get_transient( 'otp_magic_' . $token );

    if ( ! $data || time() > (int) $data['expires'] ) {
        delete_transient( 'otp_magic_' . $token );
        otp_login_log_event( '', 'magic_expired' );
        otp_login_render_username_form( __( 'This login link has expired. Please request a new one.', 'otp-login' ) );
        exit;
    }

    // Consume the token immediately — magic links are single-use.
    delete_transient( 'otp_magic_' . $token );

    $user_id  = (int) $data['user_id'];
    $userdata = get_userdata( $user_id );
    if ( ! $userdata ) {
        otp_login_render_username_form( __( 'User not found. Please try again.', 'otp-login' ) );
        exit;
    }

    otp_login_log_event( $userdata->user_email, 'magic_used', $user_id );
    otp_login_complete_login( $user_id );
}

// ─────────────────────────────────────────────────────────────
// 6. OTP HELPERS
// ─────────────────────────────────────────────────────────────

function otp_login_generate_otp( $length = 6 ) {
    $max = str_repeat( '9', $length );
    return str_pad( (string) wp_rand( 0, (int) $max ), $length, '0', STR_PAD_LEFT );
}

function otp_login_store_otp( $user_id, $otp, $expiry_minutes = 10 ) {
    $token  = wp_generate_password( 32, false );
    $expiry = $expiry_minutes * MINUTE_IN_SECONDS;
    $data   = [
        'otp'      => wp_hash_password( $otp ),
        'user_id'  => $user_id,
        'expires'  => time() + $expiry,
        'attempts' => 0,
    ];
    set_transient( 'otp_login_' . $token, $data, $expiry );
    return $token;
}

function otp_login_validate_otp( $token, $submitted_otp ) {
    $s    = otp_login_settings();
    $max  = (int) $s['otp_max_attempts'];
    $data = get_transient( 'otp_login_' . $token );

    if ( ! $data || time() > $data['expires'] ) {
        delete_transient( 'otp_login_' . $token );
        return new WP_Error( 'otp_expired', __( 'OTP has expired. Please request a new one.', 'otp-login' ) );
    }

    $attempts = isset( $data['attempts'] ) ? (int) $data['attempts'] : 0;

    if ( $attempts >= $max ) {
        delete_transient( 'otp_login_' . $token );
        return new WP_Error( 'otp_max_attempts', __( 'Too many incorrect attempts. Please request a new code.', 'otp-login' ) );
    }

    if ( ! wp_check_password( $submitted_otp, $data['otp'] ) ) {
        $data['attempts'] = $attempts + 1;

        if ( $data['attempts'] >= $max ) {
            delete_transient( 'otp_login_' . $token );
            return new WP_Error( 'otp_max_attempts', __( 'Too many incorrect attempts. Please request a new code.', 'otp-login' ) );
        }

        // Persist updated attempt count, preserving the remaining TTL.
        $remaining_ttl = max( 1, $data['expires'] - time() );
        set_transient( 'otp_login_' . $token, $data, $remaining_ttl );

        $remaining = $max - $data['attempts'];
        return new WP_Error(
            'otp_invalid',
            sprintf(
                _n( 'Invalid code. %d attempt remaining.', 'Invalid code. %d attempts remaining.', $remaining, 'otp-login' ),
                $remaining
            )
        );
    }

    delete_transient( 'otp_login_' . $token );
    return (int) $data['user_id'];
}

// ─────────────────────────────────────────────────────────────
// 7. MAGIC LINK HELPERS
// ─────────────────────────────────────────────────────────────

function otp_login_store_magic_link( $user_id, $expiry_minutes = 10 ) {
    $token  = wp_generate_password( 48, false );
    $expiry = $expiry_minutes * MINUTE_IN_SECONDS;
    set_transient( 'otp_magic_' . $token, [
        'user_id' => $user_id,
        'expires' => time() + $expiry,
    ], $expiry );
    return $token;
}

function otp_login_magic_url( $token ) {
    return add_query_arg( [ 'action' => 'otp_magic', 'token' => $token ], wp_login_url() );
}

// ─────────────────────────────────────────────────────────────
// 8. EMAIL
// ─────────────────────────────────────────────────────────────

/**
 * Build the email parts (subject, HTML body, plain-text fallback) for an OTP email.
 *
 * @param object $user      User object with user_email and display_name.
 * @param string $otp       One-time code. Empty string when method is 'magic'.
 * @param string $magic_url Magic-link URL. Empty string when method is 'otp'.
 * @return array { subject: string, html: string, text: string }
 */
function otp_login_render_email( $user, $otp, $magic_url = '' ) {
    $s              = otp_login_settings();
    $site_name      = get_bloginfo( 'name' );
    $expiry_minutes = (int) $s['otp_expiry_minutes'];

    $placeholders = [
        '{site_name}'      => $site_name,
        '{display_name}'   => $user->display_name,
        '{otp}'            => $otp,
        '{magic_link}'     => $magic_url,
        '{expiry_minutes}' => $expiry_minutes,
    ];

    $subject = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $s['email_subject'] );
    $text    = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $s['email_body'] );

    // Render the HTML template — expose only the variables the template needs.
    $display_name = $user->display_name;
    $login_method = $s['login_method'];
    $template     = plugin_dir_path( OTP_LOGIN_PLUGIN_FILE ) . 'templates/email-otp.php';

    ob_start();
    include $template;
    $html = ob_get_clean();

    return compact( 'subject', 'html', 'text' );
}

function otp_login_send_otp_email( $user, $otp, $magic_url = '' ) {
    $parts    = otp_login_render_email( $user, $otp, $magic_url );
    $alt_body = $parts['text'];

    // Attach the plain-text alternative body via PHPMailer before wp_mail() sends.
    $set_alt = static function ( $phpmailer ) use ( $alt_body ) {
        $phpmailer->AltBody = $alt_body;
    };

    add_action( 'phpmailer_init', $set_alt );
    $result = wp_mail( $user->user_email, $parts['subject'], $parts['html'], [ 'Content-Type: text/html; charset=UTF-8' ] );
    remove_action( 'phpmailer_init', $set_alt );

    return $result;
}

// ─────────────────────────────────────────────────────────────
// 9. LOGIN COMPLETION (shared by OTP verify and magic link)
// ─────────────────────────────────────────────────────────────

function otp_login_complete_login( $user_id ) {
    $userdata = get_userdata( $user_id );
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, false );
    do_action( 'wp_login', $userdata->user_login, $userdata );

    $redirect_to = apply_filters( 'login_redirect', admin_url(), '', $userdata );
    wp_safe_redirect( $redirect_to );
    exit;
}

// ─────────────────────────────────────────────────────────────
// 10. RENDER HELPERS
// ─────────────────────────────────────────────────────────────

function otp_login_render_username_form( $error = '' ) {
    $s          = otp_login_settings();
    $email_only = ! empty( $s['email_only_login'] );
    login_header( __( 'Log In', 'otp-login' ) );
    ?>
    <form name="loginform" id="loginform" action="<?php echo esc_url( wp_login_url() ); ?>" method="post">
        <?php if ( $error ) : ?>
            <div id="login_error" class="notice notice-error">
                <strong><?php echo esc_html( $error ); ?></strong>
            </div>
        <?php endif; ?>
        <p>
            <label for="user_login">
                <?php echo $email_only ? esc_html__( 'Email Address', 'otp-login' ) : esc_html__( 'Username or Email Address', 'otp-login' ); ?>
            </label>
            <input type="<?php echo $email_only ? 'email' : 'text'; ?>" name="log" id="user_login" class="input" value="" size="20"
                   autocapitalize="off" autocomplete="<?php echo $email_only ? 'email' : 'username'; ?>" required />
        </p>
        <p class="submit">
            <input type="submit" name="otp_login_request" id="wp-submit"
                   class="button button-primary button-large"
                   value="<?php esc_attr_e( 'Send One-Time Code', 'otp-login' ); ?>" />
        </p>
    </form>
    <?php
    login_footer();
    exit;
}

/**
 * @param string $token   OTP session token.
 * @param string $error   Error message to display (red notice).
 * @param string $message Success message to display (green notice), e.g. after resend.
 */
function otp_login_render_otp_form( $token, $error = '', $message = '' ) {
    $s        = otp_login_settings();
    $method   = $s['login_method'];
    $cooldown = (int) $s['otp_resend_cooldown'];
    $show_otp = in_array( $method, [ 'otp', 'both' ], true );

    login_header( __( 'Enter One-Time Code', 'otp-login' ) );
    ?>
    <p class="message">
        <?php
        if ( $method === 'both' ) {
            esc_html_e( 'A login code and a one-click link have been sent to your email. Enter the code below or click the link in the email.', 'otp-login' );
        } else {
            esc_html_e( 'A one-time login code has been sent to your email address. Enter it below to log in.', 'otp-login' );
        }
        ?>
    </p>

    <?php if ( $message ) : ?>
        <p class="message" style="border-left-color:#00a32a;"><?php echo esc_html( $message ); ?></p>
    <?php endif; ?>

    <form name="otpform" id="otpform" action="<?php echo esc_url( wp_login_url() ); ?>" method="post">
        <?php if ( $error ) : ?>
            <div id="login_error" class="notice notice-error">
                <strong><?php echo esc_html( $error ); ?></strong>
            </div>
        <?php endif; ?>
        <input type="hidden" name="otp_token" value="<?php echo esc_attr( $token ); ?>" />
        <?php if ( $show_otp ) : ?>
        <p>
            <label for="otp_code"><?php esc_html_e( 'One-Time Code', 'otp-login' ); ?></label>
            <input type="text" name="otp_code" id="otp_code" class="input" value="" size="20"
                   inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                   maxlength="<?php echo (int) $s['otp_length']; ?>" autofocus required />
        </p>
        <p class="submit">
            <input type="submit" name="otp_login_verify" id="wp-submit"
                   class="button button-primary button-large"
                   value="<?php esc_attr_e( 'Log In', 'otp-login' ); ?>" />
        </p>
        <?php endif; ?>
    </form>

    <?php if ( $show_otp ) : ?>
    <form id="resendform" action="<?php echo esc_url( wp_login_url() ); ?>" method="post" style="text-align:center; margin-top:1em;">
        <input type="hidden" name="otp_token" value="<?php echo esc_attr( $token ); ?>" />
        <button type="submit" name="otp_login_resend" id="otp-resend-btn" class="button button-secondary" disabled>
            <?php
            printf(
                /* translators: %d: cooldown in seconds */
                esc_html__( 'Resend code (%ds)', 'otp-login' ),
                $cooldown
            );
            ?>
        </button>
    </form>
    <script>
    (function () {
        var btn      = document.getElementById('otp-resend-btn');
        var seconds  = <?php echo (int) $cooldown; ?>;
        var label    = <?php echo wp_json_encode( __( 'Resend code', 'otp-login' ) ); ?>;
        var interval = setInterval( function () {
            seconds--;
            if ( seconds <= 0 ) {
                clearInterval( interval );
                btn.disabled    = false;
                btn.textContent = label;
            } else {
                btn.textContent = label + ' (' + seconds + 's)';
            }
        }, 1000 );
    })();
    </script>
    <?php endif; ?>

    <p style="text-align:center; margin-top:1em;">
        <a href="<?php echo esc_url( wp_login_url() ); ?>">
            &larr; <?php esc_html_e( 'Back to login', 'otp-login' ); ?>
        </a>
    </p>
    <?php
    login_footer();
    exit;
}

function otp_login_render_otp_sent_message( $method = 'otp' ) {
    login_header( __( 'Check your email', 'otp-login' ) );

    if ( $method === 'magic' ) {
        $msg = __( 'If an account exists for that address, a login link has been sent to your email. Click the link to log in.', 'otp-login' );
    } elseif ( $method === 'both' ) {
        $msg = __( 'If an account exists for that address, a login code and a one-click link have been sent to your email.', 'otp-login' );
    } else {
        $msg = __( 'If an account exists for that username, a one-time login code has been sent to the associated email address.', 'otp-login' );
    }
    ?>
    <p class="message"><?php echo esc_html( $msg ); ?></p>
    <p style="text-align:center;">
        <a href="<?php echo esc_url( wp_login_url() ); ?>">
            &larr; <?php esc_html_e( 'Back to login', 'otp-login' ); ?>
        </a>
    </p>
    <?php
    login_footer();
    exit;
}

// ─────────────────────────────────────────────────────────────
// 11. BLOCK PASSWORD AUTH (respects the settings toggle)
// ─────────────────────────────────────────────────────────────

add_filter( 'authenticate', 'otp_login_block_password_auth', 30, 3 );
function otp_login_block_password_auth( $user, $username, $password ) {
    $s = otp_login_settings();
    if ( ! empty( $s['block_passwords'] ) && ! empty( $password ) ) {
        otp_login_log_event( $username, 'password_blocked' );
        return new WP_Error(
            'otp_required',
            __( 'Password login is disabled. Please use the one-time code sent to your email.', 'otp-login' )
        );
    }
    return $user;
}

// ─────────────────────────────────────────────────────────────
// 12. BLOCK REGISTRATION & PROFILE SAVES FOR DISALLOWED DOMAINS
// ─────────────────────────────────────────────────────────────

add_filter( 'registration_errors', 'otp_login_check_registration_domain', 10, 3 );
function otp_login_check_registration_domain( $errors, $sanitized_user_login, $user_email ) {
    if ( ! otp_login_is_allowed_domain( $user_email ) ) {
        $domains = implode( ', ', otp_login_allowed_domains() );
        $errors->add(
            'otp_domain_not_allowed',
            sprintf(
                __( '<strong>Error:</strong> Registrations are only accepted from: %s', 'otp-login' ),
                esc_html( $domains )
            )
        );
    }
    return $errors;
}

add_action( 'user_profile_update_errors', 'otp_login_check_profile_domain', 10, 3 );
function otp_login_check_profile_domain( $errors, $update, $user ) {
    if ( isset( $user->user_email ) && ! otp_login_is_allowed_domain( $user->user_email ) ) {
        $domains = implode( ', ', otp_login_allowed_domains() );
        $errors->add(
            'otp_domain_not_allowed',
            sprintf(
                __( '<strong>Error:</strong> Email addresses must belong to one of these domains: %s', 'otp-login' ),
                esc_html( $domains )
            )
        );
    }
}
