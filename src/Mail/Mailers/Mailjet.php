<?php
/**
 * Mailjet Send API v3.1.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class Mailjet extends AbstractApiMailer {

	public function slug(): string {
		return 'mailjet';
	}

	public function label(): string {
		return 'Mailjet';
	}

	public function description(): string {
		return __( 'Mailjet by Sinch. The From address must be a validated sender or on a validated domain.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'api_key'    => array(
				'label'       => __( 'API key', 'wp-smtp-buddy' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Found under Account settings → API Key Management.', 'wp-smtp-buddy' ),
			),
			'secret_key' => array(
				'label'    => __( 'Secret key', 'wp-smtp-buddy' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			'region'     => array(
				'label'       => __( 'Region', 'wp-smtp-buddy' ),
				'type'        => 'select',
				'default'     => 'global',
				'options'     => array(
					'global' => __( 'Global (EU)', 'wp-smtp-buddy' ),
					'us'     => __( 'US', 'wp-smtp-buddy' ),
				),
				'description' => __( 'Choose US only if your account was created on the US infrastructure.', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$mail = array(
			'From'    => $this->address_object( $message->from, 'Email', 'Name' ),
			'To'      => $this->address_objects( $message->to, 'Email', 'Name' ),
			'Subject' => $message->subject,
		);

		foreach ( array(
			'cc'  => 'Cc',
			'bcc' => 'Bcc',
		) as $field => $key ) {
			if ( $message->$field ) {
				$mail[ $key ] = $this->address_objects( $message->$field, 'Email', 'Name' );
			}
		}

		// Mailjet accepts a single reply-to address.
		if ( $message->reply_to ) {
			$mail['ReplyTo'] = $this->address_object( $message->reply_to[0], 'Email', 'Name' );
		}

		if ( '' !== $message->text ) {
			$mail['TextPart'] = $message->text;
		}

		if ( '' !== $message->html ) {
			$mail['HTMLPart'] = $message->html;
		}

		if ( '' === $message->text && '' === $message->html ) {
			$mail['TextPart'] = ' ';
		}

		foreach ( $message->headers as $header ) {
			$mail['Headers'][ $header['name'] ] = $header['value'];
		}

		foreach ( $message->attachments as $attachment ) {
			$item = array(
				'ContentType'   => $attachment['type'],
				'Filename'      => $attachment['filename'],
				'Base64Content' => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);

			if ( '' !== $attachment['cid'] ) {
				$item['ContentID']            = $attachment['cid'];
				$mail['InlinedAttachments'][] = $item;
			} else {
				$mail['Attachments'][] = $item;
			}
		}

		$host = 'us' === $this->setting( 'region' ) ? 'api.us.mailjet.com' : 'api.mailjet.com';

		return array(
			'url'     => "https://{$host}/v3.1/send",
			'headers' => $this->json_headers(
				array( 'Authorization' => 'Basic ' . base64_encode( trim( (string) $this->setting( 'api_key' ) ) . ':' . $this->setting( 'secret_key' ) ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			),
			'body'    => (string) wp_json_encode( array( 'Messages' => array( $mail ) ) ),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data   = json_decode( $body, true );
		$result = is_array( $data ) ? ( $data['Messages'][0] ?? array() ) : array();

		if ( 200 === $status && 'success' === ( $result['Status'] ?? '' ) ) {
			return SendResult::sent( (string) ( $result['To'][0]['MessageID'] ?? '' ) );
		}

		$messages = array();

		foreach ( (array) ( $result['Errors'] ?? array() ) as $error ) {
			if ( ! empty( $error['ErrorMessage'] ) ) {
				$messages[] = $error['ErrorMessage'] . ( empty( $error['ErrorRelatedTo'] ) ? '' : ' (' . implode( ', ', (array) $error['ErrorRelatedTo'] ) . ')' );
			}
		}

		if ( ! $messages && is_array( $data ) && ! empty( $data['ErrorMessage'] ) ) {
			$messages[] = $data['ErrorMessage'];
		}

		return $this->http_failure( $status, $body, implode( ' ', $messages ) );
	}
}
