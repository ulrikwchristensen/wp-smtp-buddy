<?php
/**
 * Resend email API.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class Resend extends AbstractApiMailer {

	public function slug(): string {
		return 'resend';
	}

	public function label(): string {
		return 'Resend';
	}

	public function description(): string {
		return __( 'Resend (resend.com). The From address must be on a domain verified in Resend.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'API key', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'Create one under API Keys with "Sending access". It starts with "re_".', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$list = static fn ( array $addresses ): array => array_map( array( Message::class, 'format_address' ), $addresses );

		$payload = array(
			'from'    => Message::format_address( $message->from ),
			'to'      => $list( $message->to ),
			'subject' => $message->subject,
		);

		foreach ( array( 'cc', 'bcc', 'reply_to' ) as $field ) {
			if ( $message->$field ) {
				$payload[ $field ] = $list( $message->$field );
			}
		}

		if ( '' !== $message->html ) {
			$payload['html'] = $message->html;
		}

		if ( '' !== $message->text || '' === $message->html ) {
			$payload['text'] = $message->text;
		}

		foreach ( $message->headers as $header ) {
			$payload['headers'][ $header['name'] ] = $header['value'];
		}

		foreach ( $message->attachments as $attachment ) {
			$item = array(
				'filename'     => $attachment['filename'],
				'content'      => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'content_type' => $attachment['type'],
			);

			if ( '' !== $attachment['cid'] ) {
				$item['content_id'] = $attachment['cid'];
			}

			$payload['attachments'][] = $item;
		}

		return array(
			'url'     => 'https://api.resend.com/emails',
			'headers' => $this->json_headers( array( 'Authorization' => 'Bearer ' . $this->setting( 'api_key' ) ) ),
			'body'    => (string) wp_json_encode( $payload ),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data = json_decode( $body, true );

		if ( 200 === $status && ! empty( $data['id'] ) ) {
			return SendResult::sent( (string) $data['id'] );
		}

		$message = is_array( $data ) ? trim( (string) ( $data['message'] ?? '' ) . ( isset( $data['name'] ) ? ' (' . $data['name'] . ')' : '' ) ) : '';

		return $this->http_failure( $status, $body, $message );
	}
}
