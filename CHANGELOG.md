# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.7.2] - 2026-03-12

### Security
- **Fix: IP spoofing bypassed rate limiting** — `otp_login_get_client_ip()` previously trusted `X-Forwarded-For` and `CF-Connecting-IP` headers unconditionally. Any client could rotate these values to bypass all IP-based rate limiting entirely. The function now defaults to `REMOTE_ADDR` (the real TCP connection IP, unforgeable). Proxy headers are only consulted when the new **Trust Proxy Headers** setting is explicitly enabled by the admin. (`includes/security.php`)
- **Fix: OTP attempt counter race condition** — The previous read-increment-write pattern on the attempt counter transient allowed concurrent HTTP requests to each read `attempts=N` and write back `attempts=N+1`, effectively doubling the allowed brute-force attempts. The wrong-OTP path now uses `delete_transient()` as an atomic compare-and-delete — only the single caller that gets `true` back continues; all racing callers are rejected. (`includes/otp.php`)
- **Fix: Magic link token consumed by email scanners** — Magic links were previously consumed on any `GET` request to the login URL, including those made by email security scanners (Microsoft Defender SafeLinks, Proofpoint, etc.) before the user clicks the link. The magic link flow is now two-phase: `GET` shows a confirmation page without touching the token; `POST` (triggered by the user clicking "Log In") verifies a nonce, then consumes the token and completes login. (`includes/otp.php`)

### Added
- **Trust Proxy Headers** setting — New checkbox in Settings → Security (default: unchecked). Must be enabled explicitly when the site sits behind a trusted reverse proxy or Cloudflare; explains the risk in the field description. (`includes/settings.php`)

## [1.7.1] - 2026-02-21

### Security
- **CSRF protection on login forms** — All three login forms (username/OTP request, OTP verify, resend) now include WordPress nonces (`wp_nonce_field()`). Each handler verifies the nonce with `wp_verify_nonce()` before processing. Prevents cross-site request forgery attacks that could silently trigger OTP emails to victims.

### Fixed
- **License file** — `LICENSE` now contains the GPLv2 text to match the `GPLv2 or later` declaration in the plugin header and README.

## [1.7.0] - 2026-02-21

### Added
- **HTML email template** — OTP emails are now sent as `multipart/alternative` (HTML + plain-text). The HTML version uses a minimal, clean layout with a styled OTP code block, a conditional magic-link button, and inline CSS for broad email-client compatibility.
- `templates/email-otp.php` — PHP template file rendered via `ob_start()` / `include`. Receives scoped variables (`$site_name`, `$display_name`, `$otp`, `$magic_url`, `$expiry_minutes`, `$login_method`) and conditionally shows the OTP code block and/or the magic-link button based on the active login method.
- `otp_login_render_email()` — New helper in `includes/otp.php` that builds and returns `['subject', 'html', 'text']`. All three send paths (initial request, resend, test email) use the same render function — no duplication.

### Changed
- **Admin UI consolidated into a single tabbed page** — The two separate menu entries (Settings → OTP Login and Settings → OTP Login Log) are replaced by a single **Settings → Email Only OTP Login** page with **Settings** and **Login Log** tabs. All internal links updated accordingly.
- `otp_login_send_otp_email()` — Now calls `otp_login_render_email()`, attaches the plain-text body as `AltBody` via a `phpmailer_init` action, and sends with `Content-Type: text/html`. The action is added and immediately removed around the `wp_mail()` call to prevent leaking into other mail sends.
- Email Template admin section — Description updated to explain the HTML template is built-in and the body field is now the plain-text fallback. "Body" field label renamed to "Plain-text fallback".
- `otp_login_render_email()` — Template path now resolved via `locate_template()` so themes can override the HTML template by placing a file at `email-only-otp-login/email-otp.php` inside the active theme directory.
- `otp_login_render_email()` — Rendered HTML is passed through the `otp_login_email_html` filter before use, allowing developers to replace or post-process the email body in code.

## [1.6.0] - 2026-02-21

### Added
- **Login attempt log** — New `wp_otp_login_log` database table records every login event. Viewable via Settings → OTP Login Log (also linked from the Plugins list "Log" shortcut and the Settings page).
- **Log admin page** — Paginated table showing timestamp (in site timezone), identifier, IP address, event badge, and resolved user. Includes a "Clear log" button.
- **Log retention setting** — Configurable number of days to keep entries (1–365, default 30). Pruning runs automatically once per day.
- Events logged: `otp_sent`, `otp_verified`, `otp_failed`, `otp_expired`, `otp_exhausted`, `magic_used`, `magic_expired`, `rate_limited`, `password_blocked`.
- Plugin activation hook to create the log table on first activation.
- `plugins_loaded` check to create/upgrade the table if it doesn't exist (handles manual plugin copies).
- "Log" shortcut link added to the Plugins list row alongside "Settings".

### Changed
- `includes/log.php` added as a new include file (required between security.php and otp.php).
- Main plugin file now defines `OTP_LOGIN_DB_VERSION` constant and registers the activation hook.

## [1.5.0] - 2026-02-21

### Added
- **Resend cooldown** — A "Resend code" button is now shown on the OTP form. It is disabled for a configurable cooldown period (default 60s) with a live JavaScript countdown. The cooldown is also enforced server-side per user. Configurable via Settings → General → Resend Cooldown.
- **Magic link login** — New "Login Method" setting with three options: OTP code only (default), Magic link only, or Both. When magic links are enabled, a secure single-use login URL is generated and included in the email via the `{magic_link}` placeholder. The link is handled via `?action=otp_magic` on the WordPress login page.
- `{magic_link}` placeholder now available in the email body template.
- `otp_login_complete_login()` helper extracted to share login logic between OTP verify and magic link flows.

### Changed
- `otp_login_render_otp_sent_message()` now accepts a `$method` parameter and adjusts its copy for magic link and both modes.
- `otp_login_render_otp_form()` now accepts a `$message` parameter for success notices (e.g. "A new code has been sent").
- `otp_login_send_otp_email()` now accepts an optional `$magic_url` parameter.
- Test email in the admin panel sends a placeholder magic URL when magic link mode is active, so the template renders correctly.
- Login method dropdown and resend cooldown field added to the General settings section.

## [1.4.0] - 2026-02-21

### Added
- **Rate limiting** — OTP requests are now limited per IP. Configurable max requests and time window via Settings → Security.
- **Max OTP attempts** — Codes are invalidated after a configurable number of wrong guesses. Remaining attempts are shown to the user on each failed entry.
- **XML-RPC protection** — New setting to disable XML-RPC entirely (on by default), preventing password-based authentication through that endpoint.
- **REST API protection** — Authorization-header-based auth (Basic Auth, Application Passwords) is now blocked when "Block Password Login" is enabled. Cookie-authenticated sessions from OTP login are unaffected.
- **Security settings section** — New "Security" section in the admin settings page for all the above options.

### Changed
- Plugin code split into `includes/settings.php`, `includes/otp.php`, and `includes/security.php` for maintainability.
- Main plugin file (`email-only-otp-login.php`) is now a lightweight bootstrap.
- `otp_login_store_otp()` now stores an `attempts` counter alongside each token.
- `otp_login_validate_otp()` now enforces attempt limits and persists the counter with the remaining TTL intact.
- Rate limit counter is incremented regardless of whether the submitted username exists, preventing timing-based user enumeration.
- When max attempts are exceeded on the OTP form, the user is redirected back to the username form instead of staying on the OTP form with a dead token.

## [1.3.0] - 2026-02-21

### Added
- **Email-Only Login setting** — When enabled, the login field accepts only email addresses. Usernames are rejected server-side with a clear error message.
- Login form input changes dynamically based on the setting: label, `type`, and `autocomplete` attributes all update accordingly.

## [1.2.0] - Initial release

### Added
- Replaces the default WordPress login form with a two-step OTP flow (username/email → email code).
- OTP codes are hashed with `wp_hash_password()` before storage and deleted after use.
- Codes are stored as WordPress transients with configurable length (4–10 digits) and expiry (1–60 minutes).
- Block Password Login setting to disable all password-based authentication.
- Domain allow-list to restrict login and registration to specific email domains.
- User enumeration protection — unknown users and blocked domains produce a generic "code sent" response.
- Customizable OTP email subject and body with `{site_name}`, `{display_name}`, `{otp}`, and `{expiry_minutes}` placeholders.
- Test Email tool in the admin panel.
- "Settings" shortcut link on the Plugins list page.
- Registration and profile update domain enforcement.
