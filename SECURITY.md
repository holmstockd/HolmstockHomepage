# Security Policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Use GitHub's private reporting instead:
**Security → Report a vulnerability** on this repository.

I'll acknowledge within a few days and aim to ship a fix promptly.

## Before you deploy

This dashboard is designed to run on a machine you control. A few things matter:

1. **Serve it over HTTPS.** Auth cookies are marked `Secure` automatically when
   HTTPS is detected. Over plain HTTP the session cookie travels in the clear.

2. **Never commit `dash_config.php` or `dash_secret.php`.** Both are gitignored.
   `dash_config.php` holds your admin password hash and database credentials.
   `dash_secret.php` is the per-install key used to sign login cookies —
   if it leaks, login tokens for that install can be forged.

3. **Think carefully before exposing it to the internet.** It's a personal
   homepage that reads your disks and can embed camera streams. A VPN or
   local-network-only setup is safer than a public port.

4. **File permissions.** The web server needs write access to the dashboard
   directory to save settings. Don't make it world-writable.

## Scope

In scope: authentication bypass, privilege escalation between users,
path traversal, stored XSS, SQL injection.

Out of scope: issues that need physical access, and anything requiring
an already-compromised server account.
