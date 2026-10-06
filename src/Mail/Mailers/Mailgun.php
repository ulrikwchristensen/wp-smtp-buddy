<?php
/**
 * Mailgun HTTP API, using the raw MIME endpoint so attachments, inline images and
 * custom headers are delivered exactly as WordPress built them.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class Mailgun extends AbstractApiMailer {

	public function slug(): string {
		return 'mailgun';
	}

	public function label(): string {
		return 'Mailgun';
	}

	public function description(): string {
		return __( 'Mailgun by Sinch. Use a sending domain you have verified in Mailgun.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'Sending API key', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'A domain sending key or your private API key.', 'wp-smtp-buddy' ),
			),
			'domain'  => array(
				'label'       => __( 'Sending domain', 'wp-smtp-buddy' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'For example mg.example.com.', 'wp-smtp-buddy' ),
			),
			'region'  => array(
				'label'       => __( 'Region', 'wp-smtp-buddy' ),
				'type'        => 'select',
				'default'     => 'us',
				'options'     => array(
					'us' => __( 'US', 'wp-smtp-buddy' ),
					'eu' => __( 'EU', 'wp-smtp-buddy' ),
				),
				'description' => __( 'Must match the region the domain was created in.', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$multipart = $this->multipart(
			array(
				array(
					'name'  => 'to',
					'value' => implode( ',', $message->all_recipient_emails() ),
				),
				array(
					'name'     => 'message',
					'value'    => $message->mime,
					'filename' => 'message.mime',
					'type'     => 'message/rfc822',
				),
			)
		);

		$host   = 'eu' === $this->setting( 'region' ) ? 'api.eu.mailgun.net' : 'api.mailgun.net';
		$domain = rawurlencode( trim( (string) $this->setting( 'domain' ) ) );

		return array(
			'url'     => "https://{$host}/v3/{$domain}/messages.mime",
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $this->setting( 'api_key' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => $multipart['content_type'],
				'Accept'        => 'application/json',
			),
			'body'    => $multipart['body'],
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data = json_decode( $body, true );

		if ( 200 === $status ) {
			return SendResult::sent( trim( (string) ( $data['id'] ?? '' ), '<>' ) );
		}

		return $this->http_failure( $status, $body, is_array( $data ) ? (string) ( $data['message'] ?? '' ) : '' );
	}
}
