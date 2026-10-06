<?php
/**
 * Plugin Name:       WP SMTP Buddy
 * Description:       Send all WordPress email through authenticated SMTP or a transactional email API (Amazon SES, Brevo, Gmail / Google Workspace, Mailgun, MailerSend, Mailjet, Microsoft 365, Postmark, Resend, SendGrid, SMTP2GO).
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Ulrik
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-smtp-buddy
 * Domain Path:       /languages
 *
 * @package SmtpBuddy
 */

// This file must stay parseable on old PHP versions so the version guard can run.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPSB_VERSION', '1.0.0' );
define( 'WPSB_FILE', __FILE__ );
define( 'WPSB_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPSB_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WP SMTP Buddy requires PHP 8.1 or newer. The plugin is inactive until PHP is upgraded.', 'wp-smtp-buddy' );
			echo '</p></div>';
		}
	);
	return;
}

require_once WPSB_PATH . 'src/autoload.php';

register_activation_hook( __FILE__, array( 'SmtpBuddy\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SmtpBuddy\\Plugin', 'deactivate' ) );

SmtpBuddy\Plugin::instance()->boot();
