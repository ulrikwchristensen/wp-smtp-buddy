<?php
/**
 * MailerSend email API.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class MailerSend extends AbstractApiMailer {

	public function slug(): string {
		return 'mailersend';
	}

	public function label(): string {
		return 'MailerSend';
	}

	public function description(): string {
		return __( 'MailerSend by MailerLite. The From address must be on a verified domain.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'API token', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'Create one under Integrations → API tokens with "Email" full access.', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$payload = array(
			'from'    => $this->address( $message->from ),
			'to'      => array_map( array( $this, 'address' ), $message->to ),
			'subject' => $message->subject,
		);

		foreach ( array( 'cc', 'bcc' ) as $field ) {
			if ( $message->$field ) {
				$payload[ $field ] = array_map( array( $this, 'address' ), $message->$field );
			}
		}

		// MailerSend accepts a single reply-to address.
		if ( $message->reply_to ) {
			$payload['reply_to'] = $this->address( $message->reply_to[0] );
		}

		if ( '' !== $message->html ) {
			$payload['html'] = $message->html;
		}

		if ( '' !== $message->text || '' === $message->html ) {
			$payload['text'] = '' !== $message->text ? $message->text : ' ';
		}

		// Custom headers are only available on paid MailerSend plans, so they are not sent.

		foreach ( $message->attachments as $attachment ) {
			$item = array(
				'content'     => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'filename'    => $attachment['filename'],
				'disposition' => $attachment['disposition'],
			);

			if ( '' !== $attachment['cid'] ) {
				$item['id'] = $attachment['cid'];
			}

			$payload['attachments'][] = $item;
		}

		return array(
			'url'     => 'https://api.mailersend.com/v1/email',
			'headers' => $this->json_headers( array( 'Authorization' => 'Bearer ' . $this->setting( 'api_key' ) ) ),
			'body'    => (string) wp_json_encode( $payload ),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		if ( 202 === $status ) {
			return SendResult::sent( $headers['x-message-id'] ?? '' );
		}

		$data    = json_decode( $body, true );
		$message = is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '';

		if ( is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$details = array();

			foreach ( $data['errors'] as $field => $errors ) {
				$details[] = $field . ': ' . implode( ' ', (array) $errors );
			}

			$message .= ' ' . implode( '; ', $details );
		}

		return $this->http_failure( $status, $body, trim( $message ) );
	}

	private function address( array $address ): array {
		$out = array( 'email' => $address['email'] );

		if ( '' !== $address['name'] ) {
			$out['name'] = $address['name'];
		}

		return $out;
	}
}
