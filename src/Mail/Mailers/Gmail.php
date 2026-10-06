<?php
/**
 * Google Workspace / Gmail through the Gmail API.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractOAuthMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

/**
 * Uses only the gmail.send scope (a "sensitive" scope; full mailbox access would be a
 * "restricted" scope requiring a security assessment). The raw MIME message is uploaded,
 * which allows messages up to 35 MB.
 */
final class Gmail extends AbstractOAuthMailer {

	public function slug(): string {
		return 'gmail';
	}

	public function label(): string {
		return 'Google Workspace / Gmail';
	}

	public function description(): string {
		return __( 'Send through your Google account with secure sign-in (no password stored). Email is sent from the connected account or one of its "Send mail as" aliases.', 'wp-smtp-buddy' );
	}

	public function provider_name(): string {
		return 'Google';
	}

	public function authorize_endpoint(): string {
		return 'https://accounts.google.com/o/oauth2/v2/auth';
	}

	public function token_endpoint(): string {
		return 'https://oauth2.googleapis.com/token';
	}

	public function scopes(): array {
		return array( 'https://www.googleapis.com/auth/gmail.send', 'openid', 'email' );
	}

	public function authorize_params(): array {
		// offline + consent: always return a refresh token, also when reconnecting.
		return array(
			'access_type' => 'offline',
			'prompt'      => 'consent',
		);
	}

	protected function setup_steps(): array {
		return array(
			__( 'Go to console.cloud.google.com and create a project (or pick an existing one).', 'wp-smtp-buddy' ),
			__( 'APIs & Services → Library: enable the "Gmail API".', 'wp-smtp-buddy' ),
			__( 'Google Auth Platform → Branding: fill in the app name and support email. Audience: choose "Internal" for Google Workspace, or "External" for a personal Gmail account.', 'wp-smtp-buddy' ),
			__( 'For "External" apps: set the publishing status to "In production". In "Testing" mode Google expires the connection after 7 days. You can ignore the "unverified app" warning when you sign in yourself.', 'wp-smtp-buddy' ),
			__( 'Data Access: add the scope ".../auth/gmail.send".', 'wp-smtp-buddy' ),
			__( 'Clients → Create client → "Web application". Add the redirect URI shown above under "Authorized redirect URIs".', 'wp-smtp-buddy' ),
			__( 'Copy the client ID and client secret into the fields above, save, and click "Sign in with Google".', 'wp-smtp-buddy' ),
		);
	}

	public function build_request( Message $message ): array {
		return array(
			'url'     => 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send?uploadType=media',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Content-Type'  => 'message/rfc822',
				'Accept'        => 'application/json',
			),
			'body'    => $message->mime_with_bcc(),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data = json_decode( $body, true );

		if ( 200 === $status && ! empty( $data['id'] ) ) {
			return SendResult::sent( (string) $data['id'] );
		}

		$error   = is_array( $data ) ? (array) ( $data['error'] ?? array() ) : array();
		$reason  = (string) ( $error['errors'][0]['reason'] ?? $error['status'] ?? '' );
		$message = trim( (string) ( $error['message'] ?? '' ) . ( '' !== $reason ? ' (' . $reason . ')' : '' ) );

		return $this->http_failure( $status, $body, $message );
	}
}
