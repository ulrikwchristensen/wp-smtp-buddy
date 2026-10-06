<?php

namespace SmtpBuddy\Tests\Unit;

use Brain\Monkey\Functions;
use SmtpBuddy\Auth\OAuthClient;
use SmtpBuddy\Auth\TokenStore;
use SmtpBuddy\Crypto;
use SmtpBuddy\Mail\Mailers\Gmail;
use SmtpBuddy\Mail\Mailers\Microsoft365;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Options;
use SmtpBuddy\Tests\TestCase;

final class OAuthTest extends TestCase {

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $db = array();

	/**
	 * Requests sent to the token endpoint.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $requests = array();

	/**
	 * Next token endpoint response: [status, body].
	 *
	 * @var array{0: int, 1: array<string, mixed>}
	 */
	private array $next = array( 200, array() );

	private Options $options;

	protected function setUp(): void {
		parent::setUp();

		$this->db = array(
			Options::OPTION => array(
				'gmail.client_id'     => 'client-1',
				'gmail.client_secret' => 'secret-1',
			),
		);

		Functions\when( 'get_option' )->alias( fn ( $key, $fallback = false ) => $this->db[ $key ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->db[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->db[ $key ] );

				return true;
			}
		);
		Functions\when( 'is_network_admin' )->justReturn( false );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->requests[] = array( 'url' => $url ) + $args;

				return array(
					'status' => $this->next[0],
					'body'   => json_encode( $this->next[1] ),
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $response ) => $response['status'] );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ) => $thing instanceof \WP_Error );

		$this->options = new Options( new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
	}

	private function gmail(): Gmail {
		return new Gmail( $this->options, new OAuthClient( new TokenStore( $this->options ) ) );
	}

	private function id_token( array $claims ): string {
		return 'header.' . OAuthClient::base64url( json_encode( $claims ) ) . '.signature';
	}

	public function test_pkce_challenge_matches_rfc_7636_example(): void {
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', OAuthClient::code_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}

	public function test_authorize_url(): void {
		$url = $this->gmail()->oauth()->authorize_url( $this->gmail(), 'STATE', 'verifier' );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/v2/auth?', $url );
		$this->assertSame( 'client-1', $query['client_id'] );
		$this->assertSame( 'https://example.com/wp-admin/admin-post.php', $query['redirect_uri'] );
		$this->assertSame( 'https://www.googleapis.com/auth/gmail.send openid email', $query['scope'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( 'offline', $query['access_type'] );
		$this->assertSame( 'STATE', $query['state'] );
	}

	public function test_exchange_stores_encrypted_tokens_and_account(): void {
		$gmail      = $this->gmail();
		$this->next = array(
			200,
			array(
				'access_token'  => 'at-1',
				'refresh_token' => 'rt-1',
				'expires_in'    => 3599,
				'id_token'      => $this->id_token( array( 'email' => 'me@example.com' ) ),
			),
		);

		$this->assertTrue( $gmail->oauth()->exchange( $gmail, 'code-1', 'verifier-1' ) );
		$this->assertSame( 'verifier-1', $this->requests[0]['body']['code_verifier'] );
		$this->assertStringNotContainsString( 'rt-1', (string) $this->db['wpsb_oauth_gmail'], 'Tokens must be stored encrypted.' );

		$connection = $gmail->oauth()->connection( $gmail );
		$this->assertTrue( $connection['connected'] );
		$this->assertSame( 'me@example.com', $connection['account'] );
		$this->assertTrue( $gmail->is_configured() );
	}

	public function test_exchange_without_refresh_token_fails(): void {
		$gmail      = $this->gmail();
		$this->next = array( 200, array( 'access_token' => 'at-1' ) );

		$this->assertInstanceOf( \WP_Error::class, $gmail->oauth()->exchange( $gmail, 'code', 'v' ) );
		$this->assertFalse( $gmail->is_configured() );
	}

	public function test_access_token_is_cached_then_refreshed(): void {
		$gmail = $this->gmail();
		$store = new TokenStore( $this->options );
		$store->save(
			'gmail',
			array(
				'access_token'  => 'fresh',
				'refresh_token' => 'rt-1',
				'expires_at'    => time() + 3000,
				'client_id'     => 'client-1',
				'error'         => '',
			)
		);

		$this->assertSame( 'fresh', $gmail->oauth()->access_token( $gmail ) );
		$this->assertCount( 0, $this->requests );

		$record               = $store->get( 'gmail' );
		$record['expires_at'] = time() + 30;
		$store->save( 'gmail', $record );
		$this->next = array( 200, array( 'access_token' => 'renewed', 'expires_in' => 3600, 'refresh_token' => 'rt-2' ) );

		$this->assertSame( 'renewed', $gmail->oauth()->access_token( $gmail ) );
		$this->assertSame( 'refresh_token', $this->requests[0]['body']['grant_type'] );
		$this->assertSame( 'rt-1', $this->requests[0]['body']['refresh_token'] );
		$this->assertSame( 'rt-2', $store->get( 'gmail' )['refresh_token'], 'Rotated refresh tokens must be kept.' );
	}

	public function test_revoked_refresh_token_marks_connection_broken(): void {
		$gmail = $this->gmail();
		( new TokenStore( $this->options ) )->save(
			'gmail',
			array(
				'access_token'  => '',
				'refresh_token' => 'rt-1',
				'expires_at'    => 0,
				'client_id'     => 'client-1',
				'error'         => '',
			)
		);
		$this->next = array( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );

		$token = $gmail->oauth()->access_token( $gmail );

		$this->assertInstanceOf( \WP_Error::class, $token );
		$this->assertSame( 'invalid_grant', $token->get_error_code() );
		$this->assertFalse( $gmail->oauth()->connection( $gmail )['connected'] );
		$this->assertStringContainsString( 'expired or revoked', $gmail->oauth()->connection( $gmail )['error'] );
	}

	public function test_changing_client_id_disconnects(): void {
		$gmail = $this->gmail();
		( new TokenStore( $this->options ) )->save( 'gmail', array( 'refresh_token' => 'rt', 'client_id' => 'old-client', 'error' => '' ) );

		$this->assertFalse( $gmail->oauth()->connection( $gmail )['connected'] );
	}

	public function test_account_from_id_token(): void {
		$this->assertSame( 'a@example.com', OAuthClient::account_from_id_token( $this->id_token( array( 'email' => 'a@example.com' ) ) ) );
		$this->assertSame( 'b@contoso.com', OAuthClient::account_from_id_token( $this->id_token( array( 'preferred_username' => 'b@contoso.com' ) ) ) );
		$this->assertSame( '', OAuthClient::account_from_id_token( 'garbage' ) );
	}

	public function test_gmail_and_graph_requests_include_bcc_in_mime(): void {
		$message = new Message(
			from: array( 'email' => 'me@example.com', 'name' => '' ),
			to: array( array( 'email' => 'a@example.com', 'name' => '' ) ),
			bcc: array( array( 'email' => 'x@example.com', 'name' => '' ), array( 'email' => 'y@example.com', 'name' => 'Y' ) ),
			mime: "To: a@example.com\r\nSubject: Hi\r\n\r\nBody",
		);

		$gmail = $this->gmail();
		$gmail->set_access_token( 'tok' );
		$request = $gmail->build_request( $message );

		$this->assertSame( 'Bearer tok', $request['headers']['Authorization'] );
		$this->assertSame( 'message/rfc822', $request['headers']['Content-Type'] );
		$this->assertStringStartsWith( "Bcc: x@example.com,\r\n \"Y\" <y@example.com>\r\nTo: a@example.com", $request['body'] );

		$graph = new Microsoft365( $this->options );
		$graph->set_access_token( 'tok' );
		$request = $graph->build_request( $message );

		$this->assertSame( 'https://graph.microsoft.com/v1.0/me/sendMail', $request['url'] );
		$this->assertStringStartsWith( 'Bcc: x@example.com', base64_decode( $request['body'] ) );
		$this->assertTrue( $graph->parse_response( 202, '', array( 'request-id' => 'r1' ) )->ok );
		$this->assertStringContainsString( 'ErrorAccessDenied', $graph->parse_response( 403, '{"error":{"code":"ErrorAccessDenied","message":"Access is denied."}}', array() )->error );
	}

	public function test_microsoft_tenant_is_sanitized(): void {
		$this->db[ Options::OPTION ]['microsoft.tenant'] = '../evil?x=1';
		$this->assertStringStartsWith( 'https://login.microsoftonline.com/common/', ( new Microsoft365( $this->options ) )->token_endpoint() );

		$this->db[ Options::OPTION ]['microsoft.tenant'] = 'contoso.onmicrosoft.com';
		$this->options->flush();
		$this->assertStringStartsWith( 'https://login.microsoftonline.com/contoso.onmicrosoft.com/', ( new Microsoft365( $this->options ) )->token_endpoint() );
	}
}
