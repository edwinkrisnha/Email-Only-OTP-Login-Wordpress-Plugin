# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
