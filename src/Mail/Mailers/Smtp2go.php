<?php
/**
 * SMTP2GO email API.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class Smtp2go extends AbstractApiMailer {

	private const HOSTS = array(
		'global' => 'api.smtp2go.com',
		'us'     => 'us-api.smtp2go.com',
		'eu'     => 'eu-api.smtp2go.com',
		'au'     => 'au-api.smtp2go.com',
	);

	public function slug(): string {
		return 'smtp2go';
	}

	public function label(): string {
		return 'SMTP2GO';
	}

	public function description(): string {
		return __( 'SMTP2GO. The From address must be a verified sender or on a verified sender domain.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'API key', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'Create one under Sending → API Keys with the "Emails" permission. It starts with "api-".', 'wp-smtp-buddy' ),
			),
			'region'  => array(
				'label'       => __( 'Endpoint', 'wp-smtp-buddy' ),
				'type'        => 'select',
				'default'     => 'global',
				'options'     => array(
					'global' => __( 'Global (nearest)', 'wp-smtp-buddy' ),
					'us'     => __( 'US', 'wp-smtp-buddy' ),
					'eu'     => __( 'EU', 'wp-smtp-buddy' ),
					'au'     => __( 'Australia', 'wp-smtp-buddy' ),
				),
				'description' => __( 'Pick a region to keep data in that region.', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$list = static fn ( array $addresses ): array => array_map( array( Message::class, 'format_address' ), $addresses );

		$payload = array(
			'sender'  => Message::format_address( $message->from ),
			'to'      => $list( $message->to ),
			'subject' => $message->subject,
		);

		foreach ( array( 'cc', 'bcc' ) as $field ) {
			if ( $message->$field ) {
				$payload[ $field ] = $list( $message->$field );
			}
		}

		if ( '' !== $message->html ) {
			$payload['html_body'] = $message->html;
		}

		if ( '' !== $message->text || '' === $message->html ) {
			$payload['text_body'] = $message->text;
		}

		$custom = $message->headers;

		if ( $message->reply_to ) {
			$custom[] = array(
				'name'  => 'Reply-To',
				'value' => implode( ', ', $list( $message->reply_to ) ),
			);
		}

		foreach ( $custom as $header ) {
			$payload['custom_headers'][] = array(
				'header' => $header['name'],
				'value'  => $header['value'],
			);
		}

		// Inline images are referenced by filename, so the content ID is used as the filename.
		foreach ( $message->attachments as $attachment ) {
			$is_inline = '' !== $attachment['cid'];

			$payload[ $is_inline ? 'inlines' : 'attachments' ][] = array(
				'filename' => $is_inline ? $attachment['cid'] : $attachment['filename'],
				'fileblob' => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'mimetype' => $attachment['type'],
			);
		}

		$host = self::HOSTS[ (string) $this->setting( 'region' ) ] ?? self::HOSTS['global'];

		return array(
			'url'     => "https://{$host}/v3/email/send",
			'headers' => $this->json_headers( array( 'X-Smtp2go-Api-Key' => (string) $this->setting( 'api_key' ) ) ),
			'body'    => (string) wp_json_encode( $payload ),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data   = json_decode( $body, true );
		$result = is_array( $data ) ? (array) ( $data['data'] ?? array() ) : array();

		if ( 200 === $status && (int) ( $result['succeeded'] ?? 0 ) > 0 && 0 === (int) ( $result['failed'] ?? 0 ) ) {
			return SendResult::sent( (string) ( $result['email_id'] ?? '' ) );
		}

		$message = (string) ( $result['error'] ?? '' );

		if ( ! empty( $result['failures'] ) ) {
			$message = trim( $message . ' ' . implode( ' ', array_map( 'strval', (array) $result['failures'] ) ) );
		}

		if ( ! empty( $result['error_code'] ) ) {
			$message .= ' (' . $result['error_code'] . ')';
		}

		return $this->http_failure( $status, $body, $message );
	}
}
