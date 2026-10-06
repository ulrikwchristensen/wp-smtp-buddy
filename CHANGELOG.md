# Changelog

## 1.0.0 (unreleased)
- Setup wizard: choose mailer → connect → sender → test → done. It opens once after activation while no mailer is set up, and can always be opened from the settings page. Sign-in with Google or Microsoft returns to the wizard.
- Email Controls: turn off WordPress notification emails (comment notifications, new-user, password and email-change notices, automatic update reports).
- Settings export and import (admin and WP-CLI). Secrets are left out unless you include them. Imports go through the same validation as the settings form.
- WP-CLI: `settings list|get|set`, `export`, `import`, `purge`.
- Accessibility: every setting is labelled with its row title, the mailer picker is a labelled radio group with linked descriptions, saved secrets are announced to screen readers, and the active tab and wizard step are marked.
- Security (independent review before release):
  - A site switching to its own settings no longer copies the network's credentials, which could have exposed the network SMTP password.
  - OAuth connect/disconnect and test alerts are refused on sites that follow the network settings.
  - Stored attachment copies use neutral file names.
  - Webhooks use `wp_safe_remote_post`.
  - Email addresses are removed from alerts, and markdown links are neutralised in Discord/Teams alerts.
  - The email preview blocks remote images (tracking pixels) unless you allow them.
  - Debug events are included in personal data export and erasure.
- WordPress.org readiness:
  - SQL table names use `%i` placeholders.
  - Translations load automatically (no `load_plugin_textdomain`).
  - `.pot` file and a `bin/build.sh` release build (`.distignore`).
  - Plugin Check is clean except for the plugin name (see below).

## 0.6.0 (unreleased)
- Multisite: Network Admin → SMTP Buddy with network-wide settings for every site that doesn't use its own.
- "Site settings" switch: let site administrators use their own mailer (they start from a copy of the network settings and can switch back), or lock all sites to the network settings.
- Sites that had their own settings before network settings existed keep them. Without network settings, each site configures itself as before.
- Network-wide email log with a Site column, site filter and search. Resending from the network log uses the original site's settings and logs the copy under that site.
- Network-wide debug events with a Site column. Network test email, test alert and Domain Check use the network settings.
- Google/Microsoft sign-in from the network admin connects the network account (one redirect URI on the main site).
- Each site purges its own log and debug events with its own retention period.
- WP-CLI: `wp smtp-buddy log --network`, and `status` shows where the settings come from.
- Fix: the configured From Email is now also applied through `wp_mail_from`/`wp_mail_from_name`, so email works when WordPress's default sender is invalid (e.g. `wordpress@localhost`). Before, wp_mail() failed before the plugin could replace it.

## 0.5.0 (unreleased)
- New mailers: Google Workspace / Gmail (Gmail API, `gmail.send` scope only) and Microsoft 365 / Outlook (Microsoft Graph `/me/sendMail`, `Mail.Send` scope). No passwords are stored.
- Bring your own app: enter the client ID and secret from Google Cloud or Microsoft Entra, then "Sign in with Google/Microsoft". Step-by-step setup instructions and the redirect URI are shown in the settings.
- OAuth 2.0 authorization code flow with PKCE (S256) and a one-time `state` bound to the user. Tokens are stored encrypted in their own non-autoloaded option.
- Access tokens refresh automatically. Microsoft's rotated refresh tokens are kept. A revoked or expired sign-in is detected, shown in the settings and as an admin notice, and mail falls back to PHP mail until you reconnect.
- Raw MIME sending (Gmail upload endpoint up to 35 MB) with Bcc preserved.
- Error hints for common Google/Microsoft problems (Testing-mode 7-day expiry, API not enabled, expired client secret, missing consent, no Exchange mailbox, Send As denied).
- Domain Check knows Google's and Microsoft's SPF includes and DKIM selectors.

## 0.4.0 (unreleased)
- Backup connection: if the main mailer fails, the email is retried with a second mailer. The log shows "via backup mailer" with the main mailer's error.
- Failure alerts to Slack (Block Kit), Discord (embed) and Microsoft Teams (Adaptive Card for Teams Workflows). Optional alert when the backup is used. Per-channel throttling, with a count of held-back alerts. Alerts never include recipient addresses and are sent without blocking the request.
- Background sending: an optional WP-Cron queue with its own table. Attachments are captured at send time (safe when plugins delete temp files). Two retries with backoff, with failures logged and alerted only on the final attempt. Optional per-minute rate limit. Test emails and resends always go out immediately.
- Send Test tab: send a test alert to every configured channel.
- Site Health: a stalled-queue test, plus backup and queue in the debug information.
- WP-CLI: `wp smtp-buddy queue [status|run]`, `wp smtp-buddy alert-test`.
- Hooks: `wpsb_mail_sent` / `wpsb_mail_failed` get a 4th `$context` argument (backup info). `wpsb_should_log` gets the `SendResult`. New `wpsb_should_alert` filter.
- Webhook URLs are stored encrypted and must be https.

## 0.3.0 (unreleased)
- New mailers: Amazon SES (API v2 with raw MIME, so attachments, inline images and headers are kept exactly), Brevo, Mailjet (Global/US), Resend and SMTP2GO (Global/US/EU/AU endpoints).
- Built-in AWS Signature Version 4 signer, verified against the AWS test suite. No AWS SDK needed.
- Error hints for the new mailers (unverified senders, SES sandbox, bad keys, quotas).
- Domain Check knows the new mailers' DKIM selectors and SPF requirements.
- Mailer picker sorted: Default, Other SMTP, then the email services alphabetically.

## 0.2.0 (unreleased)
- Email log: every email with status, mailer, recipients, the plugin or theme that sent it, and (by default) headers and content. Search, filter by status, HTML preview in a sandboxed frame, 30-day default retention.
- Resend single emails or in bulk (up to 50 per request), including the plain-text version, attachments and inline images when attachment storage is on.
- Optional attachment storage in a randomly named, protected uploads folder.
- "Metadata only" log mode for privacy-sensitive sites.
- Personal data export and erasure (Tools → Export/Erase Personal Data) and privacy policy text.
- Domain Check tab: SPF, DKIM and DMARC lookup for the sending domain, with provider-specific hints.
- Site Health: mailer, recent delivery failures, and domain authentication (async) tests, plus a debug information section.
- WP-CLI: `wp smtp-buddy log`, `wp smtp-buddy resend <id>...`.
- Fix: HTML emails with a plain-text alternative were sent as plain text by the API mailers (PHPMailer rewrites ContentType in preSend()).

## 0.1.0 (unreleased)
- Mailers: Default (PHP mail), Other SMTP, SendGrid, Mailgun, Postmark, MailerSend.
- From Email / From Name with "force" options; Return-Path set to From Email.
- Test email tool with plain-language error hints and a redacted SMTP transcript.
- Debug events log of send errors (30-day default retention, daily cleanup).
- All settings can be set with `WPSB_*` constants in wp-config.php.
- Credentials are encrypted at rest (libsodium).
- Notices for unconfigured mailers and conflicting email plugins.
- WP-CLI: `wp smtp-buddy status`, `wp smtp-buddy test`.
