# Email Only OTP Login

[GitHub](https://github.com/edwinkrisnha/Email-Only-OTP-Login-Wordpress-Plugin)

A WordPress plugin that replaces the default password-based login with a secure email OTP (One-Time Password) flow.

## Features

- **OTP login flow** — Users enter their username or email, receive a time-limited numeric code via email, then enter the code to log in.
- **Magic link login** — Optionally send a one-click login URL instead of (or alongside) a numeric code.
- **Resend cooldown** — A "Resend code" button with a configurable cooldown prevents email spam.
- **Email-only mode** — Optionally restrict the login field to email addresses only (no usernames).
- **Block password login** — Disable password-based authentication entirely, forcing OTP-only login.
- **Domain allow-list** — Restrict login and registration to specific email domains.
- **Rate limiting** — Limit OTP requests per IP to prevent abuse.
- **Max OTP attempts** — Invalidate codes after a configurable number of wrong guesses.
- **XML-RPC protection** — Optionally disable XML-RPC, which requires password auth and bypasses OTP.
- **REST API protection** — Block Authorization-header-based auth (Basic Auth, Application Passwords) when password login is disabled.
- **Login attempt log** — Admin table showing every login event: timestamp, identifier, IP, event type, and resolved user.
- **HTML email template** — Emails are sent as styled HTML (with a plain-text fallback) using a built-in minimal template. The OTP appears in a prominent code block; magic-link login renders a clickable button.
- **Test email tool** — Send a preview OTP email from the admin panel to verify your mail configuration.

## Requirements

- WordPress 5.6+
- PHP 7.4+
- A working `wp_mail()` configuration (SMTP plugin recommended)

## Installation

1. Upload the `email-only-otp-login` folder to `/wp-content/plugins/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. Configure via **Settings → OTP Login**.

## Configuration

Navigate to **Settings → OTP Login** in the WordPress admin.

### General

| Setting | Default | Description |
|---|---|---|
| OTP Length | 6 | Number of digits in the one-time code (4–10). |
| OTP Expiry | 10 min | How long a code or magic link remains valid (1–60 min). |
| Block Password Login | On | Disables password-based authentication. Uncheck for WP-CLI or admin recovery. |
| Email-Only Login | Off | When on, the login field only accepts email addresses. |
| Login Method | OTP code only | Controls what is sent in the email: OTP code, magic link, or both. |
| Resend Cooldown | 60 s | Seconds a user must wait before using the Resend button (30–300). |

### Security

| Setting | Default | Description |
|---|---|---|
| Max OTP Attempts | 3 | Wrong guesses allowed before the code is invalidated (1–10). |
| Rate Limit | 5 requests | Max OTP requests per IP within the rate limit window (1–20). |
| Rate Limit Window | 15 min | Time window for rate limiting (1–60 min). |
| Disable XML-RPC | On | Recommended — XML-RPC requires password auth and bypasses OTP. |

### Login Log

Navigate to **Settings → OTP Login Log** to view recent login activity. Also accessible via the **"Log"** shortcut on the Plugins list row.

| Column | Description |
|---|---|
| Date / Time | Timestamp in the site's configured timezone. |
| Identifier | Username or email address that was submitted. |
| IP Address | Client IP at the time of the attempt. |
| Event | Colour-coded badge (see table below). |
| User | Resolved WordPress display name and username, if known. |

**Event types:**

| Event | Meaning |
|---|---|
| OTP sent | OTP or magic link email was sent successfully. |
| OTP verified | Correct code entered — login completed. |
| Magic link used | Magic link clicked — login completed. |
| OTP failed | Wrong code entered (attempts remaining). |
| OTP expired | Token expired before a correct code was entered. |
| Attempts exhausted | Max wrong guesses reached — token invalidated. |
| Magic link expired | Magic link clicked after it expired. |
| Rate limited | Request blocked by the IP rate limiter. |
| Password blocked | Password login attempted while it is disabled. |

**Log Retention** (Settings → OTP Login → Login Log section): configure how many days to keep entries (default 30). Old entries are pruned automatically once per day.

### Allowed Email Domains

Enter one domain per line (e.g. `example.com`). Leave blank to allow all domains. Users whose email does not match will see a generic "code sent" message (no enumeration).

### Email Template

OTP emails are sent as **HTML** using the built-in styled template (`templates/email-otp.php`). The HTML version automatically shows or hides the OTP code block and magic-link button based on your Login Method setting.

The **Subject** and **Plain-text fallback** fields are customizable in Settings → OTP Login. The plain-text fallback is used by email clients that cannot render HTML. Available placeholders for both fields:

| Placeholder | Value |
|---|---|
| `{site_name}` | Your WordPress site name |
| `{display_name}` | The user's display name |
| `{otp}` | The one-time code (empty when Login Method is "Magic link only") |
| `{magic_link}` | The one-click login URL (empty when Login Method is "OTP code only") |
| `{expiry_minutes}` | Code/link expiry in minutes |

> **Tip:** Include `{magic_link}` in the plain-text fallback when Login Method is "Magic link" or "Both".

## Login Methods

| Method | Behavior |
|---|---|
| OTP code only | User enters a numeric code on the login page (default). |
| Magic link only | User clicks a one-click URL in the email — no code entry needed. |
| Both | Email contains both the code and the link; user can use either. |

Magic links are single-use and expire after the same duration as OTP codes.

## File Structure

```
email-only-otp-login/
├── email-only-otp-login.php   # Plugin bootstrap
├── includes/
│   ├── settings.php           # Admin settings, field renderers
│   ├── otp.php                # Login flow, OTP helpers, email, render forms
│   ├── security.php           # Rate limiting, XML-RPC, REST API protection
│   └── log.php                # Login attempt log (DB table, admin page)
├── templates/
│   └── email-otp.php          # HTML email template (OTP code block + magic link)
├── CHANGELOG.md
├── LICENSE
└── README.md
```

## Security Notes

- OTP codes are hashed with `wp_hash_password()` before storage.
- Tokens are stored as WordPress transients and deleted immediately after use or expiry.
- Magic links are single-use — the token is consumed on first click.
- User enumeration is prevented — invalid usernames, blocked domains, and non-existent accounts all produce the same generic response.
- Rate limiting counters are stored as transients, keyed by a hash of the client IP.
- Resend cooldowns are enforced server-side per user, independent of the client-side countdown.
- Login events are stored in a dedicated `{prefix}otp_login_log` database table with timestamps in UTC.

## License

GPLv2 or later. See [LICENSE](LICENSE).
