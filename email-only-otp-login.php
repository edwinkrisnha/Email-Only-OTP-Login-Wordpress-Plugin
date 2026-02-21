<?php
/**
 * Plugin Name: Email Only OTP Login
 * Description: Replaces the default WordPress login with a username-only + email OTP flow. Configurable via Settings → OTP Login.
 * Version:     1.4.0
 * Author:      <a href="mailto:edwin.krisnha@gmail.com">Edwin Krisnha</a>
 */

defined( 'ABSPATH' ) || exit;

define( 'OTP_LOGIN_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/otp.php';
