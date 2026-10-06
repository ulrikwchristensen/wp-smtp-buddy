<?php
/**
 * Base class for API mailers that sign in with OAuth (bring your own app).
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Auth\OAuthClient;
use SmtpBuddy\Auth\OAuthController;
use SmtpBuddy\Auth\TokenStore;
use SmtpBuddy\Options;

abstract class AbstractOAuthMailer extends AbstractApiMailer {

	protected string $access_token = '';

	private ?OAuthClient $oauth;

	public function __construct( Options $options, ?OAuthClient $oauth = null ) {
		parent::__construct( $options );
		$this->oauth = $oauth;
	}

	abstract public function provider_name(): string;

	abstract public function authorize_endpoint(): string;

	abstract public function token_endpoint(): string;

	/**
	 * @return list<string>
	 */
	abstract public function scopes(): array;

	/**
	 * Step-by-step instructions for creating the app, shown under the connection field.
	 *
	 * @return list<string>
	 */
	abstract protected function setup_steps(): array;

	/**
	 * Extra query parameters for the authorization URL.
	 *
	 * @return array<string, string>
	 */
	public function authorize_params(): array {
		return array();
	}

	/**
	 * Fields shown between the client credentials and the connection.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function extra_fields(): array {
		return array();
	}

	public function fields(): array {
		return array(
			'client_id'     => array(
				'label'    => __( 'Client ID', 'wp-smtp-buddy' ),
				'type'     => 'text',
				'required' => true,
			),
			'client_secret' => array(
				'label'    => __( 'Client secret', 'wp-smtp-buddy' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
		) + $this->extra_fields() + array(
			'connection' => array(
				'label'  => __( 'Connection', 'wp-smtp-buddy' ),
				'type'   => 'custom',
				'render' => array( $this, 'render_connection' ),
			),
		);
	}

	public function is_configured(): bool {
		return parent::is_configured() && $this->oauth()->connection( $this )['connected'];
	}

	public function client_id(): string {
		return trim( (string) $this->setting( 'client_id' ) );
	}

	public function client_secret(): string {
		return (string) $this->setting( 'client_secret' );
	}

	public function send( PHPMailer $mail ): SendResult {
		$token = $this->oauth()->access_token( $this );

		if ( is_wp_error( $token ) ) {
			return SendResult::failed( $token->get_error_message(), array( 'oauth_error' => $token->get_error_code() ) );
		}

		$this->access_token = $token;

		return parent::send( $mail );
	}

	/**
	 * Sets the access token directly (used by tests).
	 */
	public function set_access_token( string $token ): void {
		$this->access_token = $token;
	}

	public function oauth(): OAuthClient {
		return $this->oauth ??= new OAuthClient( new TokenStore( $this->options ) );
	}

	/**
	 * Connection status, Connect/Disconnect buttons, redirect URI and setup steps.
	 */
	public function render_connection(): void {
		$connection = $this->oauth()->connection( $this );
		$has_client = '' !== $this->client_id() && '' !== $this->client_secret();

		if ( $connection['connected'] ) {
			printf(
				'<p><span class="dashicons dashicons-yes-alt wpsb-ok" aria-hidden="true"></span> %s</p>',
				esc_html(
					'' !== $connection['account']
						/* translators: 1: account email, 2: date */
						? sprintf( __( 'Connected as %1$s since %2$s.', 'wp-smtp-buddy' ), $connection['account'], wp_date( get_option( 'date_format' ), $connection['connected_at'] ) )
						/* translators: %s: date */
						: sprintf( __( 'Connected since %s.', 'wp-smtp-buddy' ), wp_date( get_option( 'date_format' ), $connection['connected_at'] ) )
				)
			);
			printf( '<p><a href="%s" class="button">%s</a></p>', esc_url( OAuthController::url( 'disconnect', $this->slug() ) ), esc_html__( 'Disconnect', 'wp-smtp-buddy' ) );
		} else {
			if ( '' !== $connection['error'] ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The connection stopped working and must be renewed:', 'wp-smtp-buddy' ) . ' <code>' . esc_html( $connection['error'] ) . '</code></p></div>';
			}

			if ( $has_client ) {
				printf(
					'<p><a href="%s" class="button button-primary">%s</a></p>',
					esc_url( OAuthController::url( 'start', $this->slug() ) ),
					/* translators: %s: provider name (Google, Microsoft) */
					esc_html( sprintf( __( 'Sign in with %s', 'wp-smtp-buddy' ), $this->provider_name() ) )
				);
			} else {
				echo '<p class="description">' . esc_html__( 'Enter the client ID and client secret, save the settings, and then sign in here.', 'wp-smtp-buddy' ) . '</p>';
			}
		}

		printf(
			'<p class="description">%s<br><code class="wpsb-copy">%s</code></p>',
			esc_html__( 'Redirect URI to add to your app:', 'wp-smtp-buddy' ),
			esc_html( OAuthClient::redirect_uri() )
		);

		if ( ! str_starts_with( OAuthClient::redirect_uri(), 'https://' ) && ! str_contains( OAuthClient::redirect_uri(), '://localhost' ) ) {
			echo '<p class="description wpsb-warning">' . esc_html__( 'This site doesn\'t use https. Providers only accept https redirect URIs (except for localhost), so signing in won\'t work until the site uses https.', 'wp-smtp-buddy' ) . '</p>';
		}

		/* translators: %s: provider name */
		echo '<details class="wpsb-setup"><summary>' . esc_html( sprintf( __( 'How to create the %s app', 'wp-smtp-buddy' ), $this->provider_name() ) ) . '</summary><ol>';
		foreach ( $this->setup_steps() as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ol></details>';
	}
}
