=== WP SMTP Buddy ===
Contributors: ulrik
Tags: smtp, email, amazon ses, sendgrid, mailgun
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable WordPress email: send through SMTP, Gmail, Microsoft 365, Amazon SES, SendGrid, Mailgun, Postmark, Brevo and more.

== Description ==

WordPress sends email with PHP's mail() function by default. Many hosts don't set it up properly, so password resets, order confirmations and contact form notifications end up in spam or never arrive.

WP SMTP Buddy sends every email WordPress sends through a real, authenticated mail service. You don't need to change anything in your other plugins or theme.

= Mailers =

* **Google Workspace / Gmail** and **Microsoft 365 / Outlook**: secure sign-in, no password stored
* **Amazon SES, Brevo, Mailgun, MailerSend, Mailjet, Postmark, Resend, SendGrid, SMTP2GO**: API keys, sent over HTTPS
* **Other SMTP**: any SMTP server, such as your host or email provider

= Features =

* **Setup wizard** that gets you from install to a delivered test email in a few minutes
* **Email log** with search, HTML preview and one-click resend (single or bulk)
* **Backup connection**: a second mailer takes over if the first one fails
* **Failure alerts** to Slack, Discord or Microsoft Teams
* **Background sending** queue with retries and rate limiting
* **Test email tool** that explains errors in plain language
* **Domain check** for SPF, DKIM and DMARC
* **Email Controls** to turn off WordPress notification emails you don't need
* **Multisite**: network-wide settings, optional per-site settings, network-wide email log
* **Privacy**: personal data export and erasure, configurable retention, a "metadata only" log mode
* **Developer friendly**: lock settings with constants in wp-config.php, WP-CLI commands, hooks
* Credentials are encrypted in the database. The plugin never contacts any service except the mailer, alert channels and Google/Microsoft sign-in you configure yourself.

== Installation ==

1. Install and activate the plugin.
2. The setup wizard opens. Choose your mail service, enter its settings (or sign in with Google/Microsoft), set the From address and send a test email.
3. Optional: in **SMTP Buddy → Settings**, add a backup mailer, failure alerts and Email Controls.

On multisite, network-activate the plugin and open **Network Admin → SMTP Buddy**.

== Frequently Asked Questions ==

= Which mailer should I choose? =

If you already use Google Workspace or Microsoft 365, choose that. For high volumes or shops, a transactional service (Amazon SES, Postmark, SendGrid, Brevo…) is usually the most reliable. "Other SMTP" works with almost any email provider or host.

= My test email failed. What now? =

The test shows the error together with a plain-language explanation of how to fix it. The **Debug Events** tab keeps recent errors, and **Domain Check** shows missing SPF, DKIM or DMARC records, the most common reason for email landing in spam.

= How do I set credentials in wp-config.php? =

Each setting has a constant: `WPSB_` plus the setting key in upper case, with dots replaced by underscores. For example:

`define( 'WPSB_MAILER', 'sendgrid' );`
`define( 'WPSB_SENDGRID_API_KEY', 'SG.xxxxx' );`
`define( 'WPSB_SMTP_HOST', 'smtp.example.com' );`

Run `wp smtp-buddy settings list` to see every key.

= Is the content of my emails stored? =

By default, the email log keeps recipients, subject, headers and content for 30 days so you can view and resend emails. You can store only metadata instead, shorten the retention period or turn the log off. Attachments are only stored if you enable it.

= How does it work on multisite? =

Network-activate the plugin and open Network Admin → SMTP Buddy. Settings saved there apply to every site that doesn't use its own. Under "Sites" you choose whether site administrators may switch to their own settings. The network email log shows every site's emails. Until you save network settings, each site is configured on its own.

= Why does Gmail disconnect after 7 days? =

Your Google Cloud app is in "Testing" mode. Google expires sign-ins from testing apps after 7 days. Set the publishing status to "In production" (Google Auth Platform → Audience) and sign in again. For Google Workspace, choosing "Internal" avoids this entirely.

= Where is the encryption key? =

By default, a random key is stored in the database and combined with your AUTH_KEY salt. You can define your own with `define( 'WPSB_ENCRYPTION_KEY', '…' );`. If AUTH_KEY or the key changes, re-enter your passwords and API keys.

= Does it work with WooCommerce, contact form plugins and so on? =

Yes. It works with any plugin that sends email through WordPress's standard wp_mail() function, which almost all do.

== Changelog ==

= 1.0.0 =
* Setup wizard, Email Controls, settings export/import, more WP-CLI commands, accessibility and security improvements.

= 0.6.0 =
* Multisite network settings, per-site overrides, network-wide email log.

= 0.5.0 =
* Gmail / Google Workspace and Microsoft 365 with OAuth sign-in.

= 0.4.0 =
* Backup connection, failure alerts (Slack, Discord, Teams), background sending queue.

= 0.3.0 =
* New mailers: Amazon SES, Brevo, Mailjet, Resend, SMTP2GO.

= 0.2.0 =
* Email log with resend, domain check, Site Health tests, privacy tools.

= 0.1.0 =
* First release.
