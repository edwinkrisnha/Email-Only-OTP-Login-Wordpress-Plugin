<?php
/**
 * Plugin Name: Email Only OTP Login
 * Description: Replaces the default WordPress login with a username-only + email OTP flow. Configurable via Settings → OTP Login.
 * Version:     1.7.0
 * Author:      <a href="mailto:edwin.krisnha@gmail.com">Edwin Krisnha</a>
 */

defined( 'ABSPATH' ) || exit;

define( 'OTP_LOGIN_PLUGIN_FILE', __FILE__ );
define( 'OTP_LOGIN_DB_VERSION',  '1.0' );

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/includes/otp.php';

register_activation_hook( __FILE__, 'otp_login_activate' );
function otp_login_activate() {
    otp_login_log_create_table();
}
