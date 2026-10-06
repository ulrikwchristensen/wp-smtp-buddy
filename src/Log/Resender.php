<?php
/**
 * Resends logged emails through wp_mail(), so the current mailer, From overrides and
 * logging all apply as for a new email.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Mail\Queue;

final class Resender {

	public function __construct(
		private EmailLog $log,
		private EmailLogger $logger,
		private AttachmentStore $attachments,
	) {}

	/**
	 * @return array{ok: bool, error: string}
	 */
	public function resend( int $id ): array {
		$entry = $this->log->get( $id );

		if ( null === $entry ) {
			return array(
				'ok'    => false,
				'error' => __( 'Email not found.', 'wp-smtp-buddy' ),
			);
		}

		if ( ! $entry->has_content() ) {
			return array(
				'ok'    => false,
				'error' => __( 'The content of this email was not stored, so it cannot be resent.', 'wp-smtp-buddy' ),
			);
		}

		$to = array_map( array( $this, 'header_address' ), $entry->addresses( 'to' ) );

		if ( ! $to ) {
			return array(
				'ok'    => false,
				'error' => __( 'This email has no recipients.', 'wp-smtp-buddy' ),
			);
		}

		$from_name = (string) ( $entry->headers['from_name'] ?? '' );
		$charset   = (string) ( $entry->headers['charset'] ?? '' );
		$headers   = array(
			'Content-Type: ' . $entry->content_type . '; charset=' . ( '' !== $charset ? $charset : 'UTF-8' ),
			'From: ' . $this->header_address(
				array(
					'email' => $entry->from_email,
					'name'  => $from_name,
				)
			),
		);

		foreach ( array(
			'cc'       => 'Cc',
			'bcc'      => 'Bcc',
			'reply_to' => 'Reply-To',
		) as $key => $name ) {
			foreach ( $entry->addresses( $key ) as $address ) {
				$headers[] = $name . ': ' . $this->header_address( $address );
			}
		}

		foreach ( (array) ( $entry->headers['custom'] ?? array() ) as $header ) {
			if ( is_array( $header ) && isset( $header[0], $header[1] ) ) {
				$headers[] = $header[0] . ': ' . $header[1];
			}
		}

		// wp_mail() can't set the plain-text alternative or attachment dispositions, so they
		// are added directly to PHPMailer for this one message.
		$alt_body    = $entry->alt_body;
		$attachments = array_filter(
			$entry->attachments,
			fn ( array $item ): bool => ! empty( $item['stored'] ) && is_string( $item['path'] ?? null ) && $this->attachments->owns( $item['path'] ) && is_readable( $item['path'] )
		);
		$add_extras  = static function ( PHPMailer $mail ) use ( $alt_body, $attachments ): void {
			$mail->AltBody = $alt_body;

			foreach ( $attachments as $item ) {
				if ( 'inline' === $item['disposition'] && '' !== $item['cid'] ) {
					$mail->addEmbeddedImage( $item['path'], $item['cid'], $item['name'], PHPMailer::ENCODING_BASE64, (string) $item['type'] );
				} else {
					$mail->addAttachment( $item['path'], $item['name'], PHPMailer::ENCODING_BASE64, (string) $item['type'] );
				}
			}
		};

		$error         = '';
		$capture_error = static function ( \WP_Error $wp_error ) use ( &$error ): void {
			$error = $wp_error->get_error_message();
		};

		// Resends go out immediately so the result can be reported.
		Queue::$bypass = true;
		add_action( 'phpmailer_init', $add_extras, 5 );
		add_action( 'wp_mail_failed', $capture_error );
		$this->logger->context = array(
			'parent_id' => $entry->id,
			'initiator' => $entry->initiator,
		);

		try {
			$ok = wp_mail( $to, $entry->subject, (string) $entry->body, $headers );
		} finally {
			remove_action( 'phpmailer_init', $add_extras, 5 );
			remove_action( 'wp_mail_failed', $capture_error );
			$this->logger->context = null;
			Queue::$bypass         = false;
		}

		return array(
			'ok'    => (bool) $ok,
			'error' => $error,
		);
	}

	/**
	 * Formats an address the way wp_mail() parses it: no quotes, and no commas in the name
	 * (wp_mail() splits address lists on commas).
	 *
	 * @param array{email: string, name: string} $address
	 */
	private function header_address( array $address ): string {
		$name = trim( (string) preg_replace( '/\s+/', ' ', str_replace( array( ',', '"', '<', '>' ), ' ', $address['name'] ) ) );

		return '' !== $name ? $name . ' <' . $address['email'] . '>' : $address['email'];
	}
}
