<?php
/**
 * Admin notices about configuration problems.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Mail\AbstractOAuthMailer;
use SmtpBuddy\Mail\Interceptor;
use SmtpBuddy\Mail\Registry;

final class Notices {

	/**
	 * Other plugins that also take over wp_mail().
	 */
	private const CONFLICTING_PLUGINS = array(
		'wp-mail-smtp/wp_mail_smtp.php'     => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
		'post-smtp/postman-smtp.php'        => 'Post SMTP',
		'fluent-smtp/fluent-smtp.php'       => 'FluentSMTP',
		'easy-wp-smtp/easy-wp-smtp.php'     => 'Easy WP SMTP',
		'smtp-mailer/main.php'              => 'SMTP Mailer',
		'gmail-smtp/main.php'               => 'Gmail SMTP',
	);

	public function __construct( private Registry $registry ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'network_admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		$screen = get_current_screen();

		$base = $screen ? (string) preg_replace( '/-network$/', '', $screen->base ) : '';

		if ( ! current_user_can( Page::capability() ) || ! in_array( $base, array( 'dashboard', 'plugins', 'toplevel_page_' . Page::SLUG ), true ) ) {
			return;
		}

		// Sites that follow the network settings can't fix them; the network admin is told instead.
		if ( is_multisite() && ! Context::network() && 'network' === \SmtpBuddy\Plugin::instance()->options()->scope() ) {
			return;
		}

		$on_own_page = 'toplevel_page_' . Page::SLUG === $base;
		$mailer      = $this->registry->selected();

		if ( 'php' === $mailer->slug() && ! $on_own_page ) {
			$this->notice(
				'info',
				__( 'WP SMTP Buddy is active, but email is still sent with PHP mail(). Choose a mailer to improve deliverability.', 'wp-smtp-buddy' ),
				Wizard::url(),
				__( 'Run the setup wizard', 'wp-smtp-buddy' )
			);
		} elseif ( ! $mailer->is_configured() && ! $on_own_page ) {
			$this->notice(
				'warning',
				/* translators: %s: mailer name */
				sprintf( __( 'WP SMTP Buddy: %s is missing required settings, so email is sent with PHP mail().', 'wp-smtp-buddy' ), $mailer->label() ),
				Page::url()
			);
		}

		foreach ( array_filter( array( $mailer, $this->registry->get( (string) \SmtpBuddy\Plugin::instance()->options()->get( 'backup.mailer' ) ) ) ) as $oauth_mailer ) {
			if ( $oauth_mailer instanceof AbstractOAuthMailer && '' !== $oauth_mailer->oauth()->connection( $oauth_mailer )['error'] ) {
				$this->notice(
					'error',
					/* translators: %s: mailer name */
					sprintf( __( 'WP SMTP Buddy: the %s connection stopped working. Sign in again to keep sending email.', 'wp-smtp-buddy' ), $oauth_mailer->label() ),
					Page::url()
				);
			}
		}

		$conflict = get_option( Interceptor::CONFLICT_OPTION );

		if ( $conflict ) {
			$this->notice(
				'warning',
				/* translators: %s: PHP class name */
				sprintf( __( 'WP SMTP Buddy: another plugin replaced WordPress\'s mailer (%s). Only the SMTP mailer works in this situation. Deactivate the other email plugin to use the API mailers.', 'wp-smtp-buddy' ), $conflict )
			);
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::CONFLICTING_PLUGINS as $basename => $name ) {
			if ( is_plugin_active( $basename ) ) {
				$this->notice(
					'warning',
					/* translators: %s: plugin name */
					sprintf( __( 'WP SMTP Buddy: %s is also active. Two email plugins will conflict. Deactivate one of them.', 'wp-smtp-buddy' ), $name )
				);
			}
		}
	}

	private function notice( string $type, string $message, string $link = '', string $link_text = '' ): void {
		printf( '<div class="notice notice-%s"><p>%s', esc_attr( $type ), esc_html( $message ) );

		if ( '' !== $link ) {
			printf( ' <a href="%s">%s</a>', esc_url( $link ), esc_html( '' !== $link_text ? $link_text : __( 'Open settings', 'wp-smtp-buddy' ) ) );
		}

		echo '</p></div>';
	}
}
