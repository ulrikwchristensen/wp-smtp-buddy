# WP SMTP Buddy

Free WordPress plugin that routes `wp_mail()` through SMTP or a transactional email API.
The roadmap and the decisions behind it are in `../../../smtp-plugin-plan.md` (site root).

## Conventions
- PHP 8.1+, WordPress 6.4+. Namespace `SmtpBuddy\`, PSR-4 in `src/` with our own autoloader (`src/autoload.php`). No runtime Composer dependencies.
- Prefixes: options/hooks/transients `wpsb_`, constants `WPSB_`, text domain `wp-smtp-buddy`.
- WordPress Coding Standards (tabs, `array()`, Yoda conditions). PHPMailer's CamelCase properties are allowed.
- Classic PHP admin (no React). Forms post to `admin-post.php`, handled in `Admin\Actions` (capability check + nonce via `verify()`).

## Architecture
- `Mail\Interceptor` swaps the global `$phpmailer` for `Mail\MailCatcher` on `pre_wp_mail`, and applies From/Return-Path on `phpmailer_init`.
- `MailCatcher::send()` → `Mail\Sender`: selected mailer → `prepare()` → `preSend()` → `send()` → `SendResult`. Failures are written to `Log\DebugEvents`.
- Mailers implement `Mail\MailerInterface`. API mailers extend `AbstractApiMailer`: they implement `build_request(Message)` and `parse_response()`, both pure and unit-tested.
- Settings are one flat array (`wpsb_settings`) with dotted keys (`smtp.host`). `Options::get()` resolves constant → stored → default and decrypts secrets (`Crypto`, libsodium).
- `Log\EmailLogger` listens to `wpsb_mail_sent`/`wpsb_mail_failed` and writes `Log\EmailLog` (table `{base_prefix}wpsb_emails`, scoped by `site_id`). Addresses are stored as `{email, name}` pairs in the `headers` JSON. `Log\Resender` rebuilds a `wp_mail()` call and adds AltBody/attachments on `phpmailer_init`.
- `Mail\Sender` tries the selected mailer, then `backup.mailer` (if different and configured). `wpsb_mail_sent`/`wpsb_mail_failed` receive `$context` (primary_*/backup_*). Logger runs at priority 10 and alerts at 20, so alerts can link to `EmailLogger::$last_id`.
- `Mail\Queue`: `MailCatcher::send()` enqueues when `queue.enabled`. The payload includes base64 attachment contents. The WP-Cron hook `wpsb_process_queue` handles single events, a lock option and the rate window. Set `Queue::$bypass = true` for anything that must send immediately (test email, resend).
- `Alerts\Alerts`: payload builders are static and pure (unit-tested). Real alerts are non-blocking; `test()` is blocking. Never put recipient addresses in alerts.
- OAuth mailers (`Gmail`, `Microsoft365`) extend `Mail\AbstractOAuthMailer`. They provide endpoints, scopes and setup steps; `Auth\OAuthClient` does PKCE, exchange and refresh; `Auth\TokenStore` keeps encrypted tokens in `wpsb_oauth_{slug}`; `Auth\OAuthController` handles start, disconnect and the callback (redirect URI = bare `admin-post.php`, hook `admin_post`).
- Field type `custom` (with a `render` callable) renders its own row and is skipped by `Fields::sanitize()`.
- OAuth can't be tested end to end locally (providers need https redirect URIs). Unit tests mock the token endpoint; integration checks use `pre_http_request`.
- Use `Message::is_html()`, never `ContentType` alone: PHPMailer's `preSend()` rewrites it to `multipart/alternative` when AltBody is set.
- Schema changes: bump `Install::DB_VERSION` and edit the `dbDelta` SQL. Upgrades run on `admin_init`, and `EmailLog::is_ready()` guards writes until then.
- Admin actions that must redirect before output run on `load-toplevel_page_wp-smtp-buddy` (see `Admin\LogScreen::handle_actions()`).
- Multisite: `Options::scope()` returns 'network' in a network context (network admin, or `force_network()`), never while `ms_is_switched()`. Otherwise it's 'site' when the network has no settings, or when overrides are allowed and `site_mode()` is 'custom'. Options are flushed on `switch_blog`.
- `Admin\Context` decides per request whether we act for the network. admin-post.php is never network admin, so network forms send `wpsb_network=1` (via `Context::fields()`), which is honoured only with `manage_network_options`. `Page::url()` and `Page::capability()` follow the context.
- Log and debug queries take `?int $site`: null = current site, 0 = all sites, N = that site (`EmailLog::site_sql()`). Network resend switches to the entry's site first.

## Adding a mailer
1. Create `src/Mail/Mailers/<Name>.php` extending `AbstractApiMailer` (or `AbstractMailer`).
2. Declare `fields()`. Mark credentials `'secret' => true` so they're encrypted.
3. Register it in `Mail\Registry::all()`.
4. Add hints for its common errors in `Support\ErrorHints`, and its DKIM selectors / SPF include in `Support\DomainCheck`.
5. Add payload/response tests in `tests/unit/ApiMailersTest.php` or `MoreApiMailersTest.php`.
- Use `address_object()`/`address_objects()` from `AbstractApiMailer` for provider address shapes. Providers that accept raw MIME (Mailgun, SES) should send `Message::$mime`.
- Amazon SES signs requests with `Support\AwsSigV4`. Its test uses the official AWS SigV4 test vectors, so don't change the expected signatures.

## Commands
Local's PHP isn't on PATH. Use the binary at `~/Library/Application Support/Local/lightning-services/php-8.2.*/bin/darwin-arm64/bin/php`.
- `composer test`: PHPUnit (Brain Monkey, no WordPress needed)
- `composer lint`: PHPCS; `vendor/bin/phpcbf` auto-fixes
- `composer analyse`: PHPStan level 6
- `wp smtp-buddy settings list|get|set`, `export [--include-secrets]`, `import <file>`, `purge [--days=N|--all]`
- `wp smtp-buddy status` / `wp smtp-buddy test <email> [--plain] [--transcript]` / `wp smtp-buddy log [--status=failed]` / `wp smtp-buddy resend <id>...` / `wp smtp-buddy queue [run]` / `wp smtp-buddy alert-test`
- macOS resolves `.local` hosts over mDNS and hangs on DNS lookups. `DomainCheck` skips local TLDs; test DNS against real domains.
- Mailpit for this site: SMTP `localhost:10011`, web UI http://localhost:10010

## Release
- `bin/build.sh` → `dist/wp-smtp-buddy-<version>.zip` (excludes everything in `.distignore`).
- `wp i18n make-pot . languages/wp-smtp-buddy.pot --exclude=vendor,tests,dist,bin` after changing strings.
- Run Plugin Check against the built zip (the scratchpad multisite has the `plugin-check` plugin). The only expected warnings are the "wp" trademark in the name/slug.

## Security rules (from the 1.0 review)
- Any handler that uses or changes credentials, connections or alert channels must call `Actions::guard_network_scope()` (sites that follow the network can't touch network secrets).
- Never copy encrypted secrets between scopes: the crypto key is network-wide, so a copied secret still decrypts. `Actions::shareable()` is the allowlist for site switches.
- Outbound requests to user-supplied URLs use `wp_safe_remote_post`. Alert text goes through `Alerts::redact_emails()` and, for Discord/Teams, link defusing.

## Testing pitfalls
- Multisite: build a throwaway network in the scratchpad (own DB `wpsb_ms` on Local's MySQL socket, core copied from this site, plugin symlinked, `wp core multisite-install`), served with `php -S localhost:8899` and a router that strips the site path. See 0.6 in the plan history.
- Submit admin forms like a browser (parse the page's form and keep checked checkboxes) instead of hand-building POST bodies.
- When posting the settings form from scripts, include every checkbox that is checked by default (`log.enabled`, `alerts.on_backup`…). Omitted checkboxes are saved as off, just like unchecked boxes in a browser.

## Done means
Tests, PHPCS and PHPStan pass. The feature has been tried in the genpress Local site (Mailpit, or mocked HTTP for API mailers). `CHANGELOG.md` is updated.
Never commit or paste real API keys. Put them in `wp-config.php` constants locally.
