<?php
/**
 * Admin endpoints for the OAuth flow: start, callback and disconnect.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Auth;

use SmtpBuddy\Admin\Context;
use SmtpBuddy\Admin\Page;
use SmtpBuddy\Plugin;
use SmtpBuddy\Mail\AbstractOAuthMailer;
use SmtpBuddy\Mail\Registry;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- start/disconnect check nonces; the callback is verified with the one-time state value.

final class OAuthController {

	private const STATE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Set to 'wizard' while the setup wizard renders, so sign-in returns there.
	 */
	public static string $return_to = '';

	public function __construct( private Registry $registry ) {}

	public function register(): void {
		add_action( 'admin_post_wpsb_oauth_start', array( $this, 'start' ) );
		add_action( 'admin_post_wpsb_oauth_disconnect', array( $this, 'disconnect' ) );
		// The redirect URI is admin-post.php without an action, which fires "admin_post".
		add_action( 'admin_post', array( $this, 'callback' ) );
	}

	public static function url( string $action, string $mailer ): string {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'       => 'wpsb_oauth_' . $action,
						'mailer'       => $mailer,
						'wpsb_network' => Context::network() ? '1' : '',
						'wpsb_return'  => 'start' === $action ? self::$return_to : '',
					)
				),
				admin_url( 'admin-post.php' )
			),
			'wpsb_oauth_' . $action . '_' . $mailer
		);
	}

	public static function error_key(): string {
		return 'wpsb_oauth_error_' . get_current_user_id();
	}

	public function start(): void {
		$mailer = $this->verify( 'start' );

		$state    = wp_generate_password( 32, false );
		$verifier = OAuthClient::base64url( random_bytes( 48 ) );

		set_transient(
			'wpsb_oauth_state_' . $state,
			array(
				'mailer'   => $mailer->slug(),
				'user_id'  => get_current_user_id(),
				'verifier' => $verifier,
				'network'  => Context::network(),
				'return'   => isset( $_GET['wpsb_return'] ) && 'wizard' === $_GET['wpsb_return'] ? 'wizard' : '',
			),
			self::STATE_TTL
		);

		// External redirect to the provider's sign-in page.
		wp_redirect( $mailer->oauth()->authorize_url( $mailer, $state, $verifier ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public function callback(): void {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( '' === $state || ! preg_match( '/^[A-Za-z0-9]{32}$/', $state ) ) {
			return; // Not our callback.
		}

		$stored = get_transient( 'wpsb_oauth_state_' . $state );
		delete_transient( 'wpsb_oauth_state_' . $state );

		if ( ! is_array( $stored ) || get_current_user_id() !== (int) $stored['user_id'] || ! current_user_can( Page::capability() ) ) {
			$this->fail( __( 'The sign-in link expired or was not started by you. Please try again.', 'wp-smtp-buddy' ) );
		}

		// A sign-in started in the network admin connects the network settings.
		if ( ! empty( $stored['network'] ) ) {
			if ( ! current_user_can( 'manage_network_options' ) ) {
				$this->fail( __( 'Only network administrators can connect the network account.', 'wp-smtp-buddy' ) );
			}

			Context::set( true, Plugin::instance()->options() );
		}

		// A site that follows the network can't replace the network's connection.
		\SmtpBuddy\Admin\Actions::guard_network_scope( Plugin::instance()->options() );

		$mailer = $this->registry->get( (string) $stored['mailer'] );

		if ( ! $mailer instanceof AbstractOAuthMailer ) {
			$this->fail( __( 'Unknown mailer.', 'wp-smtp-buddy' ) );
		}

		if ( isset( $_GET['error'] ) ) {
			$error       = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';

			$this->fail( 'access_denied' === $error ? __( 'Sign-in was cancelled or access was not granted.', 'wp-smtp-buddy' ) . ( '' !== $description ? ' ' . $description : '' ) : trim( $error . ': ' . $description ) );
		}

		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$result = '' !== $code ? $mailer->oauth()->exchange( $mailer, $code, (string) $stored['verifier'] ) : new \WP_Error( 'no_code', __( 'The provider did not return an authorization code.', 'wp-smtp-buddy' ) );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}

		wp_safe_redirect(
			'wizard' === ( $stored['return'] ?? '' )
				? \SmtpBuddy\Admin\Wizard::url( 'sender' )
				: Page::url(
					'settings',
					array(
						'wpsb_notice' => 'oauth_connected',
						'mailer'      => $mailer->slug(),
					)
				)
		);
		exit;
	}

	public function disconnect(): void {
		$mailer = $this->verify( 'disconnect' );

		$mailer->oauth()->disconnect( $mailer );

		wp_safe_redirect(
			Page::url(
				'settings',
				array(
					'wpsb_notice' => 'oauth_disconnected',
					'mailer'      => $mailer->slug(),
				)
			)
		);
		exit;
	}

	private function verify( string $action ): AbstractOAuthMailer {
		$slug = isset( $_GET['mailer'] ) ? sanitize_key( $_GET['mailer'] ) : '';

		if ( ! current_user_can( Page::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP SMTP Buddy.', 'wp-smtp-buddy' ), 403 );
		}

		check_admin_referer( 'wpsb_oauth_' . $action . '_' . $slug );
		\SmtpBuddy\Admin\Actions::guard_network_scope( Plugin::instance()->options() );

		$mailer = $this->registry->get( $slug );

		if ( ! $mailer instanceof AbstractOAuthMailer ) {
			wp_die( esc_html__( 'Unknown mailer.', 'wp-smtp-buddy' ), 400 );
		}

		return $mailer;
	}

	/**
	 * @return never
	 */
	private function fail( string $message ): void {
		set_transient( self::error_key(), $message, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( Page::url( 'settings', array( 'wpsb_notice' => 'oauth_error' ) ) );
		exit;
	}
}
