<?php
/**
 * OAuth 2.0 authorization code flow with PKCE, and token refresh.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Auth;

use SmtpBuddy\Mail\AbstractOAuthMailer;

final class OAuthClient {

	/**
	 * Refresh this many seconds before the access token expires.
	 */
	private const LEEWAY = 120;

	public function __construct( private TokenStore $tokens ) {}

	/**
	 * Where the provider sends the user back. No query string: Microsoft discourages it, and
	 * admin-post.php without an action fires the generic "admin_post" hook.
	 */
	public static function redirect_uri(): string {
		// Network settings are connected from the main site, so every site shares one URI.
		if ( is_multisite() && \SmtpBuddy\Admin\Context::network() ) {
			return get_admin_url( get_main_site_id(), 'admin-post.php' );
		}

		return admin_url( 'admin-post.php' );
	}

	public static function code_challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	public static function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function authorize_url( AbstractOAuthMailer $mailer, string $state, string $verifier ): string {
		$params = array_merge(
			array(
				'client_id'             => $mailer->client_id(),
				'redirect_uri'          => self::redirect_uri(),
				'response_type'         => 'code',
				'scope'                 => implode( ' ', $mailer->scopes() ),
				'state'                 => $state,
				'code_challenge'        => self::code_challenge( $verifier ),
				'code_challenge_method' => 'S256',
			),
			$mailer->authorize_params()
		);

		return $mailer->authorize_endpoint() . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchanges an authorization code for tokens and stores them.
	 */
	public function exchange( AbstractOAuthMailer $mailer, string $code, string $verifier ): bool|\WP_Error {
		$response = $this->token_request(
			$mailer,
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::redirect_uri(),
				'code_verifier' => $verifier,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['refresh_token'] ) ) {
			return new \WP_Error( 'wpsb_no_refresh_token', __( 'The provider did not return a refresh token, so the connection would stop working within an hour. Remove the app\'s access from your account and connect again.', 'wp-smtp-buddy' ) );
		}

		$this->tokens->save(
			$mailer->slug(),
			array(
				'access_token'  => (string) ( $response['access_token'] ?? '' ),
				'refresh_token' => (string) $response['refresh_token'],
				'expires_at'    => time() + (int) ( $response['expires_in'] ?? 3600 ),
				'account'       => self::account_from_id_token( (string) ( $response['id_token'] ?? '' ) ),
				'client_id'     => $mailer->client_id(),
				'connected_at'  => time(),
				'error'         => '',
			)
		);

		return true;
	}

	/**
	 * A valid access token, refreshed when needed.
	 */
	public function access_token( AbstractOAuthMailer $mailer ): string|\WP_Error {
		$record = $this->tokens->get( $mailer->slug() );

		if ( ! $this->is_connected( $mailer, $record ) ) {
			/* translators: %s: mailer name */
			return new \WP_Error( 'wpsb_not_connected', sprintf( __( '%s is not connected. Open the WP SMTP Buddy settings and connect your account.', 'wp-smtp-buddy' ), $mailer->label() ) );
		}

		if ( '' !== (string) ( $record['access_token'] ?? '' ) && (int) ( $record['expires_at'] ?? 0 ) > time() + self::LEEWAY ) {
			return (string) $record['access_token'];
		}

		$response = $this->token_request(
			$mailer,
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => (string) $record['refresh_token'],
			)
		);

		if ( is_wp_error( $response ) ) {
			// invalid_grant means the refresh token was revoked or expired: the user must reconnect.
			if ( 'invalid_grant' === $response->get_error_code() ) {
				$record['error'] = $response->get_error_message();
				$this->tokens->save( $mailer->slug(), $record );
			}

			return $response;
		}

		$record['access_token'] = (string) ( $response['access_token'] ?? '' );
		$record['expires_at']   = time() + (int) ( $response['expires_in'] ?? 3600 );
		$record['error']        = '';

		// Microsoft rotates refresh tokens; Google keeps the original.
		if ( ! empty( $response['refresh_token'] ) ) {
			$record['refresh_token'] = (string) $response['refresh_token'];
		}

		$this->tokens->save( $mailer->slug(), $record );

		return $record['access_token'];
	}

	/**
	 * @return array{connected: bool, account: string, connected_at: int, error: string}
	 */
	public function connection( AbstractOAuthMailer $mailer ): array {
		$record = $this->tokens->get( $mailer->slug() );

		return array(
			'connected'    => $this->is_connected( $mailer, $record ),
			'account'      => (string) ( $record['account'] ?? '' ),
			'connected_at' => (int) ( $record['connected_at'] ?? 0 ),
			'error'        => (string) ( $record['error'] ?? '' ),
		);
	}

	public function disconnect( AbstractOAuthMailer $mailer ): void {
		$this->tokens->delete( $mailer->slug() );
	}

	/**
	 * Reads the account email from an ID token. The token comes straight from the provider's
	 * token endpoint over TLS, so its signature doesn't need to be verified here.
	 */
	public static function account_from_id_token( string $id_token ): string {
		$parts = explode( '.', $id_token );

		if ( 3 !== count( $parts ) ) {
			return '';
		}

		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( ! is_array( $claims ) ) {
			return '';
		}

		return (string) ( $claims['email'] ?? $claims['preferred_username'] ?? $claims['upn'] ?? '' );
	}

	/**
	 * A connection is valid when it has a refresh token issued for the current client ID
	 * and the last refresh didn't fail permanently.
	 *
	 * @param array<string, mixed> $record
	 */
	private function is_connected( AbstractOAuthMailer $mailer, array $record ): bool {
		return '' !== (string) ( $record['refresh_token'] ?? '' )
			&& (string) ( $record['client_id'] ?? '' ) === $mailer->client_id()
			&& '' === (string) ( $record['error'] ?? '' );
	}

	/**
	 * @param array<string, string> $params
	 * @return array<string, mixed>|\WP_Error
	 */
	private function token_request( AbstractOAuthMailer $mailer, array $params ): array|\WP_Error {
		$response = wp_remote_post(
			$mailer->token_endpoint(),
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array_merge(
					array(
						'client_id'     => $mailer->client_id(),
						'client_secret' => $mailer->client_secret(),
						'scope'         => implode( ' ', $mailer->scopes() ),
					),
					$params
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['access_token'] ) ) {
			return $data;
		}

		$error       = is_array( $data ) ? (string) ( $data['error'] ?? 'token_error' ) : 'token_error';
		$description = is_array( $data ) ? (string) ( $data['error_description'] ?? '' ) : '';

		return new \WP_Error(
			$error,
			/* translators: 1: provider name, 2: error code, 3: error description */
			trim( sprintf( __( '%1$s sign-in error: %2$s. %3$s', 'wp-smtp-buddy' ), $mailer->provider_name(), $error, $description ) )
		);
	}
}
