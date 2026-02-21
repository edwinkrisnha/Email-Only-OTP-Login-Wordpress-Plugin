<?php
defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────
// TABLE CREATION & UPGRADE
// ─────────────────────────────────────────────────────────────

function otp_login_log_create_table() {
    global $wpdb;

    $table   = $wpdb->prefix . 'otp_login_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id           bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        attempted_at datetime            NOT NULL,
        identifier   varchar(255)        NOT NULL DEFAULT '',
        ip           varchar(45)         NOT NULL DEFAULT '',
        event        varchar(30)         NOT NULL DEFAULT '',
        user_id      bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY event        (event),
        KEY attempted_at (attempted_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'otp_login_db_version', OTP_LOGIN_DB_VERSION );
}

add_action( 'plugins_loaded', 'otp_login_maybe_create_log_table' );
function otp_login_maybe_create_log_table() {
    if ( get_option( 'otp_login_db_version' ) !== OTP_LOGIN_DB_VERSION ) {
        otp_login_log_create_table();
    }
}

// ─────────────────────────────────────────────────────────────
// LOG A SINGLE EVENT
// ─────────────────────────────────────────────────────────────

/**
 * @param string $identifier Username or email address that was submitted.
 * @param string $event       One of: otp_sent, otp_verified, otp_failed, otp_expired,
 *                            otp_exhausted, magic_used, magic_expired,
 *                            rate_limited, password_blocked.
 * @param int    $user_id     Resolved WP user ID, or 0 if unknown.
 */
function otp_login_log_event( $identifier, $event, $user_id = 0 ) {
    global $wpdb;

    $wpdb->insert(
        $wpdb->prefix . 'otp_login_log',
        [
            'attempted_at' => current_time( 'mysql', true ), // UTC
            'identifier'   => $identifier,
            'ip'           => otp_login_get_client_ip(),
            'event'        => $event,
            'user_id'      => (int) $user_id,
        ],
        [ '%s', '%s', '%s', '%s', '%d' ]
    );

    otp_login_log_prune_maybe();
}

// ─────────────────────────────────────────────────────────────
// PRUNE OLD ENTRIES (once per day)
// ─────────────────────────────────────────────────────────────

function otp_login_log_prune_maybe() {
    if ( get_transient( 'otp_log_pruned' ) ) {
        return;
    }

    $s    = otp_login_settings();
    $days = max( 1, (int) ( $s['otp_log_retention_days'] ?? 30 ) );

    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}otp_login_log WHERE attempted_at < %s",
        gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
    ) );

    set_transient( 'otp_log_pruned', 1, DAY_IN_SECONDS );
}

// ─────────────────────────────────────────────────────────────
// ADMIN MENU
// ─────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'otp_login_log_admin_menu' );
function otp_login_log_admin_menu() {
    add_submenu_page(
        'options-general.php',
        __( 'OTP Login Log', 'otp-login' ),
        __( 'OTP Login Log', 'otp-login' ),
        'manage_options',
        'otp-login-log',
        'otp_login_log_page'
    );
}

// ─────────────────────────────────────────────────────────────
// ADMIN PAGE
// ─────────────────────────────────────────────────────────────

function otp_login_log_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'otp_login_log';

    // Handle clear log.
    if ( isset( $_POST['otp_login_clear_log'] ) && check_admin_referer( 'otp_login_clear_log' ) ) {
        $wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__( 'Log cleared.', 'otp-login' )
            . '</p></div>';
    }

    $per_page    = 25;
    $current     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $offset      = ( $current - 1 ) * $per_page;
    $total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
    $rows        = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}otp_login_log ORDER BY attempted_at DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ) );
    $total_pages = (int) ceil( $total_items / $per_page );

    // Event badge config.
    $event_meta = [
        'otp_sent'         => [ 'label' => __( 'OTP sent', 'otp-login' ),          'color' => '#2271b1' ],
        'otp_verified'     => [ 'label' => __( 'OTP verified', 'otp-login' ),       'color' => '#00a32a' ],
        'otp_failed'       => [ 'label' => __( 'OTP failed', 'otp-login' ),         'color' => '#dba617' ],
        'otp_expired'      => [ 'label' => __( 'OTP expired', 'otp-login' ),        'color' => '#787c82' ],
        'otp_exhausted'    => [ 'label' => __( 'Attempts exhausted', 'otp-login' ), 'color' => '#d63638' ],
        'magic_used'       => [ 'label' => __( 'Magic link used', 'otp-login' ),    'color' => '#00a32a' ],
        'magic_expired'    => [ 'label' => __( 'Magic link expired', 'otp-login' ), 'color' => '#787c82' ],
        'rate_limited'     => [ 'label' => __( 'Rate limited', 'otp-login' ),       'color' => '#d63638' ],
        'password_blocked' => [ 'label' => __( 'Password blocked', 'otp-login' ),   'color' => '#d63638' ],
    ];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'OTP Login Log', 'otp-login' ); ?></h1>
        <p>
            <?php
            printf(
                /* translators: %d: total log entries */
                esc_html__( '%d entries total.', 'otp-login' ),
                $total_items
            );
            ?>
            &nbsp;
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=otp-login' ) ); ?>">
                &larr; <?php esc_html_e( 'Back to Settings', 'otp-login' ); ?>
            </a>
        </p>

        <?php if ( $total_items > 0 ) : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:1em;">
            <thead>
                <tr>
                    <th style="width:160px;"><?php esc_html_e( 'Date / Time', 'otp-login' ); ?></th>
                    <th><?php esc_html_e( 'Identifier', 'otp-login' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'IP Address', 'otp-login' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( 'Event', 'otp-login' ); ?></th>
                    <th><?php esc_html_e( 'User', 'otp-login' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) :
                    $meta  = $event_meta[ $row->event ] ?? [ 'label' => $row->event, 'color' => '#787c82' ];
                    $udata = $row->user_id ? get_userdata( (int) $row->user_id ) : null;
                    $ts    = strtotime( $row->attempted_at . ' UTC' );
                ?>
                <tr>
                    <td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $ts ) ); ?></td>
                    <td><?php echo esc_html( $row->identifier ); ?></td>
                    <td><code><?php echo esc_html( $row->ip ); ?></code></td>
                    <td>
                        <span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $meta['color'] ); ?>;">
                            <?php echo esc_html( $meta['label'] ); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ( $udata ) : ?>
                            <?php echo esc_html( $udata->display_name ); ?>
                            <span style="color:#787c82;">(<?php echo esc_html( $udata->user_login ); ?>)</span>
                        <?php else : ?>
                            <span style="color:#787c82;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom" style="margin-top:.5em;">
            <div class="tablenav-pages">
                <?php
                echo paginate_links( [
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $current,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <hr>
        <h2><?php esc_html_e( 'Clear Log', 'otp-login' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'otp_login_clear_log' ); ?>
            <?php submit_button( __( 'Clear all log entries', 'otp-login' ), 'delete', 'otp_login_clear_log', false ); ?>
        </form>
    </div>
    <?php
}
