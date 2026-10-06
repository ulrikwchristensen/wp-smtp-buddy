<?php
/**
 * Postmark email API.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;

final class Postmark extends AbstractApiMailer {

	public function slug(): string {
		return 'postmark';
	}

	public function label(): string {
		return 'Postmark';
	}

	public function description(): string {
		return __( 'Postmark by ActiveCampaign. The From address needs a confirmed sender signature or verified domain.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'server_token'   => array(
				'label'       => __( 'Server API token', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'required'    => true,
				'description' => __( 'Found under your server → API Tokens.', 'wp-smtp-buddy' ),
			),
			'message_stream' => array(
				'label'       => __( 'Message stream', 'wp-smtp-buddy' ),
				'type'        => 'text',
				'default'     => 'outbound',
				'description' => __( 'Leave as "outbound" unless you created a custom transactional stream.', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$list = static fn ( array $addresses ): string => implode( ', ', array_map( array( Message::class, 'format_address' ), $addresses ) );

		$payload = array(
			'From'          => Message::format_address( $message->from ),
			'To'            => $list( $message->to ),
			'Subject'       => $message->subject,
			'MessageStream' => (string) ( $this->setting( 'message_stream' ) ?: 'outbound' ),
		);

		foreach ( array(
			'Cc'      => $message->cc,
			'Bcc'     => $message->bcc,
			'ReplyTo' => $message->reply_to,
		) as $key => $addresses ) {
			if ( $addresses ) {
				$payload[ $key ] = $list( $addresses );
			}
		}

		if ( '' !== $message->html ) {
			$payload['HtmlBody'] = $message->html;
		}

		if ( '' !== $message->text || '' === $message->html ) {
			$payload['TextBody'] = $message->text;
		}

		foreach ( $message->headers as $header ) {
			$payload['Headers'][] = array(
				'Name'  => $header['name'],
				'Value' => $header['value'],
			);
		}

		foreach ( $message->attachments as $attachment ) {
			$item = array(
				'Name'        => $attachment['filename'],
				'Content'     => base64_encode( $attachment['content'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'ContentType' => $attachment['type'],
			);

			if ( '' !== $attachment['cid'] ) {
				$item['ContentID'] = 'cid:' . $attachment['cid'];
			}

			$payload['Attachments'][] = $item;
		}

		return array(
			'url'     => 'https://api.postmarkapp.com/email',
			'headers' => $this->json_headers( array( 'X-Postmark-Server-Token' => (string) $this->setting( 'server_token' ) ) ),
			'body'    => (string) wp_json_encode( $payload ),
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data = json_decode( $body, true );

		if ( 200 === $status && 0 === (int) ( $data['ErrorCode'] ?? -1 ) ) {
			return SendResult::sent( (string) ( $data['MessageID'] ?? '' ) );
		}

		$message = is_array( $data ) ? (string) ( $data['Message'] ?? '' ) : '';

		if ( is_array( $data ) && isset( $data['ErrorCode'] ) ) {
			$message = sprintf( '%s (Postmark error code %d)', $message, (int) $data['ErrorCode'] );
		}

		return $this->http_failure( $status, $body, $message );
	}
}
