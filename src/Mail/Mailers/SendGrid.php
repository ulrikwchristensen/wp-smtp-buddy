<?php
/**
 * SendGrid Web API v3.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class SendGrid extends AbstractApiMailer {

	/**
	 * Headers SendGrid sets itself and rejects in the `headers` object.
	 */
	private const RESERVED_HEADERS = array( 'x-sg-id', 'x-sg-eid', 'received', 'dkim-signature', 'content-type', 'content-transfer-encoding', 'to', 'from', 'subject', 'reply-to', 'cc', 'bcc' );

	public function slug(): string {
		return 'sendgrid';
	}

	public function label(): string {
		return 'SendGrid';
	}

	public function description(): string {
		return __( 'Twilio SendGrid. The From address must be a verified Sender Identity or on an authenticated domain.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'api_key' => array(
				'label'       => __( 'API key', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'Create one under Settings → API Keys with at least "Mail Send" access.', 'wp-smtp-buddy' ),
			),
			'region'  => array(
				'label'   => __( 'Region', 'wp-smtp-buddy' ),
				'type'    => 'select',
				'default' => 'global',
				'options' => array(
					'global' => __( 'Global', 'wp-smtp-buddy' ),
					'eu'     => __( 'EU (regional subuser)', 'wp-smtp-buddy' ),
				),
			),
		);
	}

	public function build_request( Message $message ): array {
		$seen            = array();
		$personalization = array();

		// SendGrid rejects an address that appears more than once across to, cc and bcc.
		foreach ( array( 'to', 'cc', 'bcc' ) as $field ) {
			$list = array();

			foreach ( $message->$field as $address ) {
				$key = strtolower( $address['email'] );

				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$list[]       = $this->address( $address );
				}
			}

			if ( $list ) {
				$personalization[ $field ] = $list;
			}
		}

		$payload = array(
			'personalizations' => array( $personalization ),
			'from'             => $this->address( $message->from ),
			'subject'          => $message->subject,
			'content'          => array(),
		);

		// text/plain must come before text/html.
		if ( '' !== $message->text ) {
			$payload['content'][] = array(
				'type'  => 'text/plain',
				'value' => $message->text,
			);
		}

		if ( '' !== $message->html ) {
			$payload['content'][] = array(
				'type'  => 'text/html',
				'value' => $message->html,
			);
		}

		if ( ! $payload['content'] ) {
			$payload['content'][] = array(
				'type'  => 'text/plain',
				'value' => ' ',
			);
		}

		if ( 1 === count( $message->reply_to ) ) {
			$payload['reply_to'] = $this->address( $message->reply_to[0] );
		} elseif ( count( $message->reply_to ) > 1 ) {
			$payload['reply_to_list'] = array_map( array( $this, 'address' ), $message->reply_to );
		}

		foreach ( $message->headers as $header ) {
			if ( ! in_array( strtolower( $header['name'] ), self::RESERVED_HEADERS, true ) ) {
				$payload['headers'][ $header['name'] ] = $header['value'];
			}
		}

		foreach ( $message->attachments as $attachment ) {
			$item = array(
				'content'     => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'filename'    => $attachment['filename'],
				'type'        => $attachment['type'],
				'disposition' => $attachment['disposition'],
			);

			if ( '' !== $attachment['cid'] ) {
				$item['content_id'] = $attachment['cid'];
			}

			$payload['attachments'][] = $item;
		}

		$host = 'eu' === $this->setting( 'region' ) ? 'api.eu.sendgrid.com' : 'api.sendgrid.com';

		return array(
			'url'     => "https://{$host}/v3/mail/send",
			'headers' => $this->json_headers( array( 'Authorization' => 'Bearer ' . $this->setting( 'api_key' ) ) ),
			'body'    => (string) wp_json_encode( $payload ),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		if ( 202 === $status || 200 === $status ) {
			return SendResult::sent( $headers['x-message-id'] ?? '' );
		}

		$data     = json_decode( $body, true );
		$messages = array();

		foreach ( (array) ( $data['errors'] ?? array() ) as $error ) {
			if ( ! empty( $error['message'] ) ) {
				$messages[] = $error['message'];
			}
		}

		return $this->http_failure( $status, $body, implode( ' ', $messages ) );
	}

	private function address( array $address ): array {
		$out = array( 'email' => $address['email'] );

		if ( '' !== $address['name'] ) {
			$out['name'] = $address['name'];
		}

		return $out;
	}
}
