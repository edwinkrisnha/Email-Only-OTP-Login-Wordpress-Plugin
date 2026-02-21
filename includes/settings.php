<?php
defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────
// HELPERS — read live settings from the DB (fall back to safe defaults)
// ─────────────────────────────────────────────────────────────

/**
 * Return plugin settings merged with defaults.
 */
function otp_login_settings() {
    $defaults = [
        'otp_length'         => 6,
        'otp_expiry_minutes' => 10,
        'allowed_domains'    => "edwin.com\newin123.com",
        'block_passwords'    => 1,
        'email_only_login'   => 0,
        'otp_max_attempts'   => 3,
        'otp_rate_limit'     => 5,
        'otp_rate_window'    => 15,
        'disable_xmlrpc'     => 1,
        'email_subject'      => '[{site_name}] Your one-time login code',
        'email_body'         => "Hi {display_name},\n\nYour one-time login code is:\n\n    {otp}\n\nThis code will expire in {expiry_minutes} minutes.\n\nIf you did not request this code, you can safely ignore this email.\n\n— {site_name}",
    ];

    $saved = get_option( 'otp_login_settings', [] );

    return wp_parse_args( $saved, $defaults );
}

/**
 * Return the allowed domains as a clean array (lowercase, no empties).
 */
function otp_login_allowed_domains() {
    $s       = otp_login_settings();
    $raw     = isset( $s['allowed_domains'] ) ? $s['allowed_domains'] : '';
    $lines   = preg_split( '/[\r\n,]+/', $raw );
    $domains = [];
    foreach ( $lines as $line ) {
        $d = strtolower( trim( $line, " \t@" ) );
        if ( $d !== '' ) {
            $domains[] = $d;
        }
    }
    return $domains;
}

/**
 * Check whether an email address belongs to an allowed domain.
 * Returns true (allow all) when the domain list is empty.
 */
function otp_login_is_allowed_domain( $email ) {
    $allowed = otp_login_allowed_domains();
    if ( empty( $allowed ) ) {
        return true;
    }
    $parts  = explode( '@', strtolower( trim( $email ) ) );
    $domain = isset( $parts[1] ) ? $parts[1] : '';
    return in_array( $domain, $allowed, true );
}

// ─────────────────────────────────────────────────────────────
// ADMIN SETTINGS PAGE
// ─────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'otp_login_admin_menu' );
function otp_login_admin_menu() {
    add_options_page(
        __( 'OTP Login Settings', 'otp-login' ),
        __( 'OTP Login', 'otp-login' ),
        'manage_options',
        'otp-login',
        'otp_login_settings_page'
    );
}

add_action( 'admin_init', 'otp_login_register_settings' );
function otp_login_register_settings() {
    register_setting(
        'otp_login_settings_group',
        'otp_login_settings',
        'otp_login_sanitize_settings'
    );

    // ── Section: General ──────────────────────────────────────
    add_settings_section( 'otp_login_section_general', __( 'General', 'otp-login' ), '__return_false', 'otp-login' );

    add_settings_field( 'otp_length',         __( 'OTP Length', 'otp-login' ),          'otp_login_field_otp_length',       'otp-login', 'otp_login_section_general' );
    add_settings_field( 'otp_expiry_minutes', __( 'OTP Expiry (minutes)', 'otp-login' ), 'otp_login_field_otp_expiry',       'otp-login', 'otp_login_section_general' );
    add_settings_field( 'block_passwords',    __( 'Block Password Login', 'otp-login' ), 'otp_login_field_block_passwords',  'otp-login', 'otp_login_section_general' );
    add_settings_field( 'email_only_login',   __( 'Email-Only Login', 'otp-login' ),     'otp_login_field_email_only_login', 'otp-login', 'otp_login_section_general' );

    // ── Section: Security ─────────────────────────────────────
    add_settings_section(
        'otp_login_section_security',
        __( 'Security', 'otp-login' ),
        function () {
            echo '<p>' . esc_html__( 'Configure brute-force and abuse protections.', 'otp-login' ) . '</p>';
        },
        'otp-login'
    );

    add_settings_field( 'otp_max_attempts', __( 'Max OTP Attempts', 'otp-login' ),           'otp_login_field_max_attempts',   'otp-login', 'otp_login_section_security' );
    add_settings_field( 'otp_rate_limit',   __( 'Rate Limit (requests)', 'otp-login' ),       'otp_login_field_rate_limit',     'otp-login', 'otp_login_section_security' );
    add_settings_field( 'otp_rate_window',  __( 'Rate Limit Window (minutes)', 'otp-login' ), 'otp_login_field_rate_window',    'otp-login', 'otp_login_section_security' );
    add_settings_field( 'disable_xmlrpc',   __( 'Disable XML-RPC', 'otp-login' ),             'otp_login_field_disable_xmlrpc', 'otp-login', 'otp_login_section_security' );

    // ── Section: Domain Allow-list ────────────────────────────
    add_settings_section(
        'otp_login_section_domains',
        __( 'Allowed Email Domains', 'otp-login' ),
        function () {
            echo '<p>' . esc_html__( 'Only users whose registered email belongs to one of these domains can log in. Enter one domain per line (e.g. example.com). Leave blank to allow all domains.', 'otp-login' ) . '</p>';
        },
        'otp-login'
    );

    add_settings_field( 'allowed_domains', __( 'Domains', 'otp-login' ), 'otp_login_field_allowed_domains', 'otp-login', 'otp_login_section_domains' );

    // ── Section: Email Template ───────────────────────────────
    add_settings_section(
        'otp_login_section_email',
        __( 'Email Template', 'otp-login' ),
        function () {
            echo '<p>'
                . esc_html__( 'Customize the OTP email. Available placeholders: ', 'otp-login' )
                . '<code>{site_name}</code>, <code>{display_name}</code>, <code>{otp}</code>, <code>{expiry_minutes}</code>'
                . '</p>';
        },
        'otp-login'
    );

    add_settings_field( 'email_subject', __( 'Subject', 'otp-login' ), 'otp_login_field_email_subject', 'otp-login', 'otp_login_section_email' );
    add_settings_field( 'email_body',    __( 'Body', 'otp-login' ),    'otp_login_field_email_body',    'otp-login', 'otp_login_section_email' );
}

// ── Sanitisation ──────────────────────────────────────────────

function otp_login_sanitize_settings( $input ) {
    $clean = [];

    $clean['otp_length']         = min( 10, max( 4, (int) ( $input['otp_length'] ?? 6 ) ) );
    $clean['otp_expiry_minutes'] = min( 60, max( 1, (int) ( $input['otp_expiry_minutes'] ?? 10 ) ) );
    $clean['block_passwords']    = empty( $input['block_passwords'] ) ? 0 : 1;
    $clean['email_only_login']   = empty( $input['email_only_login'] ) ? 0 : 1;
    $clean['otp_max_attempts']   = min( 10, max( 1, (int) ( $input['otp_max_attempts'] ?? 3 ) ) );
    $clean['otp_rate_limit']     = min( 20, max( 1, (int) ( $input['otp_rate_limit'] ?? 5 ) ) );
    $clean['otp_rate_window']    = min( 60, max( 1, (int) ( $input['otp_rate_window'] ?? 15 ) ) );
    $clean['disable_xmlrpc']     = empty( $input['disable_xmlrpc'] ) ? 0 : 1;

    // Domains: one per line, basic sanity check.
    $raw_domains = sanitize_textarea_field( $input['allowed_domains'] ?? '' );
    $lines       = preg_split( '/[\r\n,]+/', $raw_domains );
    $safe_lines  = [];
    foreach ( $lines as $line ) {
        $d = strtolower( trim( $line, " \t@" ) );
        if ( $d !== '' && preg_match( '/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?\.[a-z]{2,}$/', $d ) ) {
            $safe_lines[] = $d;
        }
    }
    $clean['allowed_domains'] = implode( "\n", $safe_lines );

    $clean['email_subject'] = sanitize_text_field( $input['email_subject'] ?? '' );
    $clean['email_body']    = sanitize_textarea_field( $input['email_body'] ?? '' );

    return $clean;
}

// ── Field renderers ───────────────────────────────────────────

function otp_login_field_otp_length() {
    $s = otp_login_settings();
    printf(
        '<input type="number" name="otp_login_settings[otp_length]" value="%d" min="4" max="10" class="small-text" /><p class="description">%s</p>',
        (int) $s['otp_length'],
        esc_html__( 'Number of digits in the one-time code (4–10).', 'otp-login' )
    );
}

function otp_login_field_otp_expiry() {
    $s = otp_login_settings();
    printf(
        '<input type="number" name="otp_login_settings[otp_expiry_minutes]" value="%d" min="1" max="60" class="small-text" /><p class="description">%s</p>',
        (int) $s['otp_expiry_minutes'],
        esc_html__( 'How long the OTP remains valid (1–60 minutes).', 'otp-login' )
    );
}

function otp_login_field_block_passwords() {
    $s = otp_login_settings();
    printf(
        '<label><input type="checkbox" name="otp_login_settings[block_passwords]" value="1" %s /> %s</label><p class="description">%s</p>',
        checked( 1, (int) $s['block_passwords'], false ),
        esc_html__( 'Disable password-based authentication', 'otp-login' ),
        esc_html__( 'When checked, users cannot log in with a password — only via OTP. Uncheck to allow password login as a fallback (e.g. for WP-CLI or admin recovery).', 'otp-login' )
    );
}

function otp_login_field_email_only_login() {
    $s = otp_login_settings();
    printf(
        '<label><input type="checkbox" name="otp_login_settings[email_only_login]" value="1" %s /> %s</label><p class="description">%s</p>',
        checked( 1, (int) $s['email_only_login'], false ),
        esc_html__( 'Require an email address (disable username login)', 'otp-login' ),
        esc_html__( 'When checked, the login field only accepts email addresses. Usernames will be rejected.', 'otp-login' )
    );
}

function otp_login_field_max_attempts() {
    $s = otp_login_settings();
    printf(
        '<input type="number" name="otp_login_settings[otp_max_attempts]" value="%d" min="1" max="10" class="small-text" /><p class="description">%s</p>',
        (int) $s['otp_max_attempts'],
        esc_html__( 'Wrong OTP guesses allowed before the code is invalidated and a new one must be requested (1–10).', 'otp-login' )
    );
}

function otp_login_field_rate_limit() {
    $s = otp_login_settings();
    printf(
        '<input type="number" name="otp_login_settings[otp_rate_limit]" value="%d" min="1" max="20" class="small-text" /><p class="description">%s</p>',
        (int) $s['otp_rate_limit'],
        esc_html__( 'Maximum OTP requests allowed per IP within the rate limit window (1–20).', 'otp-login' )
    );
}

function otp_login_field_rate_window() {
    $s = otp_login_settings();
    printf(
        '<input type="number" name="otp_login_settings[otp_rate_window]" value="%d" min="1" max="60" class="small-text" /><p class="description">%s</p>',
        (int) $s['otp_rate_window'],
        esc_html__( 'Time window in minutes for the rate limit (1–60).', 'otp-login' )
    );
}

function otp_login_field_disable_xmlrpc() {
    $s = otp_login_settings();
    printf(
        '<label><input type="checkbox" name="otp_login_settings[disable_xmlrpc]" value="1" %s /> %s</label><p class="description">%s</p>',
        checked( 1, (int) $s['disable_xmlrpc'], false ),
        esc_html__( 'Disable XML-RPC', 'otp-login' ),
        esc_html__( 'Recommended. XML-RPC requires password authentication, which bypasses OTP. Disable it unless a specific integration requires it.', 'otp-login' )
    );
}

function otp_login_field_allowed_domains() {
    $s = otp_login_settings();
    printf(
        '<textarea name="otp_login_settings[allowed_domains]" rows="6" cols="40" class="large-text code">%s</textarea><p class="description">%s</p>',
        esc_textarea( $s['allowed_domains'] ),
        esc_html__( 'One domain per line, e.g. example.com — Leave blank to allow all domains.', 'otp-login' )
    );
}

function otp_login_field_email_subject() {
    $s = otp_login_settings();
    printf(
        '<input type="text" name="otp_login_settings[email_subject]" value="%s" class="large-text" />',
        esc_attr( $s['email_subject'] )
    );
}

function otp_login_field_email_body() {
    $s = otp_login_settings();
    printf(
        '<textarea name="otp_login_settings[email_body]" rows="10" cols="60" class="large-text code">%s</textarea>',
        esc_textarea( $s['email_body'] )
    );
}

// ── Settings page HTML ────────────────────────────────────────

function otp_login_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'OTP Login Settings', 'otp-login' ); ?></h1>

        <?php settings_errors( 'otp_login_settings' ); ?>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'otp_login_settings_group' );
            do_settings_sections( 'otp-login' );
            submit_button();
            ?>
        </form>

        <hr>

        <h2><?php esc_html_e( 'Send a Test OTP Email', 'otp-login' ); ?></h2>
        <p><?php esc_html_e( 'Send a preview of the OTP email to verify your template and mail configuration.', 'otp-login' ); ?></p>

        <?php otp_login_handle_test_email(); ?>

        <form method="post">
            <?php wp_nonce_field( 'otp_login_test_email', 'otp_login_test_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="otp_test_email"><?php esc_html_e( 'Recipient Email', 'otp-login' ); ?></label>
                    </th>
                    <td>
                        <input type="email" id="otp_test_email" name="otp_test_email"
                               value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>"
                               class="regular-text" required />
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Send Test Email', 'otp-login' ), 'secondary', 'otp_login_send_test' ); ?>
        </form>
    </div>
    <?php
}

// ── Handle test email submission ──────────────────────────────

function otp_login_handle_test_email() {
    if ( ! isset( $_POST['otp_login_send_test'] ) ) {
        return;
    }
    if ( ! check_admin_referer( 'otp_login_test_email', 'otp_login_test_nonce' ) ) {
        return;
    }

    $to = sanitize_email( wp_unslash( $_POST['otp_test_email'] ?? '' ) );
    if ( ! is_email( $to ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid email address.', 'otp-login' ) . '</p></div>';
        return;
    }

    $current_user = wp_get_current_user();
    $fake_user    = (object) [
        'user_email'   => $to,
        'display_name' => $current_user->display_name ?: 'Test User',
    ];

    $s        = otp_login_settings();
    $test_otp = otp_login_generate_otp( (int) $s['otp_length'] );
    $sent     = otp_login_send_otp_email( $fake_user, $test_otp );

    if ( $sent ) {
        printf(
            '<div class="notice notice-success"><p>%s <strong>%s</strong>. %s <code>%s</code></p></div>',
            esc_html__( 'Test email sent to', 'otp-login' ),
            esc_html( $to ),
            esc_html__( 'OTP used in the preview:', 'otp-login' ),
            esc_html( $test_otp )
        );
    } else {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'wp_mail() returned false. Check your mail configuration.', 'otp-login' ) . '</p></div>';
    }
}

// ── "Settings" shortcut on Plugins list ──────────────────────

add_filter( 'plugin_action_links_' . plugin_basename( OTP_LOGIN_PLUGIN_FILE ), 'otp_login_plugin_action_links' );
function otp_login_plugin_action_links( $links ) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'options-general.php?page=otp-login' ) ),
        esc_html__( 'Settings', 'otp-login' )
    );
    array_unshift( $links, $settings_link );
    return $links;
}
