# Email Only OTP Login

A WordPress plugin that replaces the default password-based login with a secure email OTP (One-Time Password) flow.

## Features

- **OTP login flow** — Users enter their username or email, receive a time-limited numeric code via email, then enter the code to log in.
- **Email-only mode** — Optionally restrict the login field to email addresses only (no usernames).
- **Block password login** — Disable password-based authentication entirely, forcing OTP-only login.
- **Domain allow-list** — Restrict login and registration to specific email domains.
- **Rate limiting** — Limit OTP requests per IP to prevent abuse.
- **Max OTP attempts** — Invalidate codes after a configurable number of wrong guesses.
- **XML-RPC protection** — Optionally disable XML-RPC, which requires password auth and bypasses OTP.
- **REST API protection** — Block Authorization-header-based auth (Basic Auth, Application Passwords) when password login is disabled.
- **Customizable email template** — Edit the subject and body with placeholder support.
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
| OTP Expiry | 10 min | How long a code remains valid (1–60 min). |
| Block Password Login | On | Disables password-based authentication. Uncheck for WP-CLI or admin recovery. |
| Email-Only Login | Off | When on, the login field only accepts email addresses. |

### Security

| Setting | Default | Description |
|---|---|---|
| Max OTP Attempts | 3 | Wrong guesses allowed before the code is invalidated (1–10). |
| Rate Limit | 5 requests | Max OTP requests per IP within the rate limit window (1–20). |
| Rate Limit Window | 15 min | Time window for rate limiting (1–60 min). |
| Disable XML-RPC | On | Recommended — XML-RPC requires password auth and bypasses OTP. |

### Allowed Email Domains

Enter one domain per line (e.g. `example.com`). Leave blank to allow all domains. Users whose email does not match will see a generic "code sent" message (no enumeration).

### Email Template

Customize the OTP email subject and body. Available placeholders:

| Placeholder | Value |
|---|---|
| `{site_name}` | Your WordPress site name |
| `{display_name}` | The user's display name |
| `{otp}` | The one-time code |
| `{expiry_minutes}` | Code expiry in minutes |

## File Structure

```
email-only-otp-login/
├── email-only-otp-login.php   # Plugin bootstrap
├── includes/
│   ├── settings.php           # Admin settings, field renderers
│   ├── otp.php                # Login flow, OTP helpers, render forms
│   └── security.php           # Rate limiting, XML-RPC, REST API protection
├── CHANGELOG.md
├── LICENSE
└── README.md
```

## Security Notes

- OTP codes are hashed with `wp_hash_password()` before storage.
- Tokens are stored as WordPress transients and deleted immediately after use or expiry.
- User enumeration is prevented — invalid usernames, blocked domains, and non-existent accounts all produce the same generic response.
- Rate limiting counters are stored as transients, keyed by a hash of the client IP.

## License

GPLv2 or later. See [LICENSE](LICENSE).
