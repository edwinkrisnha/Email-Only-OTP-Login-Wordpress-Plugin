<?php
/**
 * HTML email template for OTP login emails.
 *
 * Variables available (set by otp_login_render_email()):
 *   string $site_name
 *   string $display_name
 *   string $otp            Empty string when login_method is 'magic'.
 *   string $magic_url      Empty string when login_method is 'otp'.
 *   int    $expiry_minutes
 *   string $login_method   'otp' | 'magic' | 'both'
 */
defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $site_name ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f5;padding:40px 16px;">
  <tr>
    <td align="center">

      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;">

        <!-- Card -->
        <tr>
          <td style="background-color:#ffffff;border-radius:8px;border:1px solid #e4e4e7;overflow:hidden;">

            <!-- Header -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:28px 40px 24px;border-bottom:1px solid #f0f0f0;">
                  <span style="font-size:15px;font-weight:600;color:#18181b;letter-spacing:-0.01em;">
                    <?php echo esc_html( $site_name ); ?>
                  </span>
                </td>
              </tr>
            </table>

            <!-- Body -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:32px 40px;">

                  <p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#3f3f46;">
                    Hi <?php echo esc_html( $display_name ); ?>,
                  </p>

                  <?php if ( $otp ) : ?>

                  <p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#3f3f46;">
                    Your one-time login code is:
                  </p>

                  <!-- OTP code block -->
                  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                    <tr>
                      <td align="center">
                        <div style="display:inline-block;background-color:#fafafa;border:1px solid #e4e4e7;border-radius:6px;padding:18px 36px;">
                          <span style="font-size:34px;font-weight:700;letter-spacing:10px;color:#18181b;font-family:'Courier New',Courier,monospace;">
                            <?php echo esc_html( $otp ); ?>
                          </span>
                        </div>
                      </td>
                    </tr>
                  </table>

                  <p style="margin:0 0 24px;font-size:13px;line-height:1.5;color:#71717a;">
                    This code expires in <strong><?php echo (int) $expiry_minutes; ?> minutes</strong>.
                  </p>

                  <?php endif; ?>

                  <?php if ( $magic_url ) : ?>

                  <?php if ( $otp ) : ?>
                  <p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#71717a;">
                    Or log in instantly with one click:
                  </p>
                  <?php else : ?>
                  <p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#3f3f46;">
                    Click the button below to log in — no code needed:
                  </p>
                  <?php endif; ?>

                  <!-- Magic link button -->
                  <table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                    <tr>
                      <td style="border-radius:5px;background-color:#2271b1;">
                        <a href="<?php echo esc_url( $magic_url ); ?>"
                           style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:5px;line-height:1.4;">
                          Log In Now &rarr;
                        </a>
                      </td>
                    </tr>
                  </table>

                  <?php if ( ! $otp ) : ?>
                  <p style="margin:0 0 20px;font-size:13px;line-height:1.5;color:#71717a;">
                    Link expires in <strong><?php echo (int) $expiry_minutes; ?> minutes</strong>.
                  </p>
                  <?php endif; ?>

                  <?php endif; ?>

                  <p style="margin:0;font-size:13px;line-height:1.5;color:#a1a1aa;">
                    If you didn't request this, you can safely ignore this email.
                  </p>

                </td>
              </tr>
            </table>

            <!-- Footer -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="padding:20px 40px;background-color:#fafafa;border-top:1px solid #f0f0f0;">
                  <p style="margin:0;font-size:12px;color:#a1a1aa;">
                    &mdash; <?php echo esc_html( $site_name ); ?>
                  </p>
                </td>
              </tr>
            </table>

          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>

</body>
</html>
