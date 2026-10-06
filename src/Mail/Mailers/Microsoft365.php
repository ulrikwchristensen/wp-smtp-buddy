<?php
/**
 * Microsoft 365 / Outlook through Microsoft Graph.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractOAuthMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

/**
 * Sends the raw MIME message to /me/sendMail (Mail.Send scope). Microsoft is retiring
 * password-based SMTP for Exchange Online, so this is the long-term way to send from 365.
 */
final class Microsoft365 extends AbstractOAuthMailer {

	public function slug(): string {
		return 'microsoft';
	}

	public function label(): string {
		return 'Microsoft 365 / Outlook';
	}

	public function description(): string {
		return __( 'Send through your Microsoft 365 or Outlook.com account with secure sign-in (no password stored). Email is sent from the connected mailbox.', 'wp-smtp-buddy' );
	}

	public function provider_name(): string {
		return 'Microsoft';
	}

	protected function extra_fields(): array {
		return array(
			'tenant' => array(
				'label'       => __( 'Tenant', 'wp-smtp-buddy' ),
				'type'        => 'text',
				'default'     => 'common',
				'description' => __( 'Leave as "common" unless the app is single-tenant. Then enter your Directory (tenant) ID.', 'wp-smtp-buddy' ),
			),
		);
	}

	public function authorize_endpoint(): string {
		return 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant() ) . '/oauth2/v2.0/authorize';
	}

	public function token_endpoint(): string {
		return 'https://login.microsoftonline.com/' . rawurlencode( $this->tenant() ) . '/oauth2/v2.0/token';
	}

	public function scopes(): array {
		return array( 'https://graph.microsoft.com/Mail.Send', 'offline_access', 'openid', 'email' );
	}

	public function authorize_params(): array {
		return array( 'prompt' => 'select_account' );
	}

	protected function setup_steps(): array {
		return array(
			__( 'Go to entra.microsoft.com → Applications → App registrations → New registration.', 'wp-smtp-buddy' ),
			__( 'Supported account types: your organization only (single tenant) or any organization. Choose "any organization and personal accounts" for Outlook.com.', 'wp-smtp-buddy' ),
			__( 'Redirect URI: platform "Web", and paste the redirect URI shown above.', 'wp-smtp-buddy' ),
			__( 'API permissions → Add a permission → Microsoft Graph → Delegated → Mail.Send (offline_access, openid and email are added automatically at sign-in). Your admin may need to grant consent.', 'wp-smtp-buddy' ),
			__( 'Certificates & secrets → New client secret. Copy the secret Value (not the ID). Note when it expires: you\'ll need a new one then.', 'wp-smtp-buddy' ),
			__( 'Copy the Application (client) ID and the secret into the fields above. For a single-tenant app, also enter the Directory (tenant) ID. Save and click "Sign in with Microsoft".', 'wp-smtp-buddy' ),
		);
	}

	public function build_request( Message $message ): array {
		return array(
			'url'     => 'https://graph.microsoft.com/v1.0/me/sendMail',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Content-Type'  => 'text/plain',
			),
			'body'    => base64_encode( $message->mime_with_bcc() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		if ( 202 === $status ) {
			return SendResult::sent( $headers['request-id'] ?? '' );
		}

		$data    = json_decode( $body, true );
		$error   = is_array( $data ) ? (array) ( $data['error'] ?? array() ) : array();
		$message = trim( (string) ( $error['message'] ?? '' ) . ( isset( $error['code'] ) ? ' (' . $error['code'] . ')' : '' ) );

		return $this->http_failure( $status, $body, $message );
	}

	private function tenant(): string {
		$tenant = trim( (string) $this->setting( 'tenant' ) );

		return '' !== $tenant && preg_match( '/^[A-Za-z0-9.\-]+$/', $tenant ) ? $tenant : 'common';
	}
}
