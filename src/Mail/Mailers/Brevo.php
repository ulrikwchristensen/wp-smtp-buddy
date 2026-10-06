<?php
/**
 * Brevo (formerly Sendinblue) transactional email API.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class Brevo extends AbstractApiMailer {

	public function slug(): string {
		return 'brevo';
	}

	public function label(): string {
		return 'Brevo';
	}

	public function description(): string {
		return __( 'Brevo, formerly Sendinblue. The From address must be a verified sender or on an authenticated domain.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'API key', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'Create one under SMTP & API → API Keys. It starts with "xkeysib-".', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$payload = array(
			'sender'  => $this->address_object( $message->from ),
			'to'      => $this->address_objects( $message->to ),
			'subject' => $message->subject,
		);

		foreach ( array( 'cc', 'bcc' ) as $field ) {
			if ( $message->$field ) {
				$payload[ $field ] = $this->address_objects( $message->$field );
			}
		}

		// Brevo accepts a single reply-to address.
		if ( $message->reply_to ) {
			$payload['replyTo'] = $this->address_object( $message->reply_to[0] );
		}

		if ( '' !== $message->html ) {
			$payload['htmlContent'] = $message->html;
		}

		if ( '' !== $message->text || '' === $message->html ) {
			$payload['textContent'] = '' !== $message->text ? $message->text : ' ';
		}

		foreach ( $message->headers as $header ) {
			$payload['headers'][ $header['name'] ] = $header['value'];
		}

		// Brevo has no inline (cid) attachments, so inline images are sent as regular attachments.
		foreach ( $message->attachments as $attachment ) {
			$payload['attachment'][] = array(
				'name'    => $attachment['filename'],
				'content' => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);
		}

		return array(
			'url'     => 'https://api.brevo.com/v3/smtp/email',
			'headers' => $this->json_headers( array( 'api-key' => (string) $this->setting( 'api_key' ) ) ),
			'body'    => (string) wp_json_encode( $payload ),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data = json_decode( $body, true );

		if ( $status >= 200 && $status < 300 ) {
			return SendResult::sent( trim( (string) ( $data['messageId'] ?? '' ), '<>' ) );
		}

		$message = is_array( $data ) ? trim( (string) ( $data['message'] ?? '' ) . ( isset( $data['code'] ) ? ' (' . $data['code'] . ')' : '' ) ) : '';

		return $this->http_failure( $status, $body, $message );
	}
}
