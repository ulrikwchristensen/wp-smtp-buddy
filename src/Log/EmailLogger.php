<?php
/**
 * Writes every sent or failed email to the log.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;
use SmtpBuddy\Options;

final class EmailLogger {

	/**
	 * Overrides for the next entries, set while resending or processing the queue:
	 * parent_id and the original initiator.
	 *
	 * @var array{parent_id: int, initiator: string}|null
	 */
	public ?array $context = null;

	/**
	 * ID of the most recent entry written in this request (0 if none).
	 */
	public int $last_id = 0;

	public function __construct(
		private Options $options,
		private EmailLog $log,
		private AttachmentStore $attachments,
	) {}

	public function register(): void {
		add_action( 'wpsb_mail_sent', array( $this, 'log' ), 10, 4 );
		add_action( 'wpsb_mail_failed', array( $this, 'log' ), 10, 4 );
	}

	/**
	 * @param array<string, string> $send_context From Sender: primary_mailer/primary_error when the backup was used.
	 */
	public function log( PHPMailer $mail, string $mailer, SendResult $result, array $send_context = array() ): void {
		$this->last_id = 0;

		if ( ! $this->options->get( 'log.enabled' ) || ! $this->log->is_ready() ) {
			return;
		}

		/**
		 * Filters whether an email is written to the log.
		 *
		 * @param bool       $should_log Default true.
		 * @param PHPMailer  $mail       The message.
		 * @param SendResult $result     The send result.
		 */
		if ( ! apply_filters( 'wpsb_should_log', true, $mail, $result ) ) {
			return;
		}

		$full = 'full' === $this->options->get( 'log.content' );
		$to   = $this->addresses( $mail->getToAddresses() );
		$cc   = $this->addresses( $mail->getCcAddresses() );
		$bcc  = $this->addresses( $mail->getBccAddresses() );

		$recipient_emails = array_unique(
			array_map(
				'strtolower',
				array_merge( array_column( $mail->getToAddresses(), 0 ), array_column( $mail->getCcAddresses(), 0 ), array_column( $mail->getBccAddresses(), 0 ) )
			)
		);

		$headers = array(
			'from_name' => (string) $mail->FromName,
			'to'        => $to,
			'cc'        => $cc,
			'bcc'       => $bcc,
			'reply_to'  => $this->addresses( array_values( $mail->getReplyToAddresses() ) ),
			'charset'   => (string) $mail->CharSet,
		);

		if ( $full ) {
			$headers['custom'] = $mail->getCustomHeaders();
		}

		$attachments = array();

		foreach ( $mail->getAttachments() as $attachment ) {
			$attachments[] = array(
				'name'        => (string) ( '' !== (string) $attachment[2] ? $attachment[2] : $attachment[1] ),
				'type'        => (string) $attachment[4],
				'disposition' => (string) $attachment[6],
				'cid'         => (string) $attachment[7],
				'size'        => $attachment[5] ? strlen( (string) $attachment[0] ) : (int) @filesize( (string) $attachment[0] ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'stored'      => false,
			);
		}

		$context = $this->context ?? array(
			'parent_id' => 0,
			'initiator' => Initiator::detect(),
		);

		$id = $this->log->insert(
			array(
				'status'         => $result->ok ? EmailLog::STATUS_SENT : EmailLog::STATUS_FAILED,
				'mailer'         => $mailer,
				'from_email'     => strtolower( (string) $mail->From ),
				'to_email'       => implode( ', ', array_map( array( Message::class, 'format_address' ), $to ) ),
				'recipients'     => ',' . implode( ',', $recipient_emails ) . ',',
				'subject'        => (string) $mail->Subject,
				'content_type'   => Message::is_html( (string) $mail->ContentType, (string) $mail->AltBody ) ? 'text/html' : 'text/plain',
				'headers'        => wp_json_encode( $headers ),
				'body'           => $full ? (string) $mail->Body : null,
				'alt_body'       => $full ? (string) $mail->AltBody : null,
				'attachments'    => wp_json_encode( $attachments ),
				'error'          => $result->ok ? null : $result->error,
				'message_id'     => $result->message_id,
				'initiator'      => $context['initiator'],
				'parent_id'      => $context['parent_id'],
				'primary_mailer' => (string) ( $send_context['primary_mailer'] ?? '' ),
				'primary_error'  => $send_context['primary_error'] ?? null,
			)
		);

		$this->last_id = $id;

		if ( $id && $full && $attachments && $this->options->get( 'log.attachments' ) ) {
			$this->store_attachments( $id, $mail->getAttachments(), $attachments );
		}
	}

	/**
	 * @param array<int, array<int, mixed>>   $raw  PHPMailer attachment arrays.
	 * @param list<array<string, mixed>> $meta Logged attachment metadata, same order.
	 */
	private function store_attachments( int $id, array $raw, array $meta ): void {
		foreach ( array_values( $raw ) as $index => $attachment ) {
			$content = $attachment[5] ? (string) $attachment[0] : ( is_readable( (string) $attachment[0] ) ? (string) file_get_contents( (string) $attachment[0] ) : null ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( null === $content ) {
				continue;
			}

			$path = $this->attachments->store( $id, $index, $content );

			if ( null !== $path ) {
				$meta[ $index ]['stored'] = true;
				$meta[ $index ]['path']   = $path;
			}
		}

		$this->log->update( $id, array( 'attachments' => wp_json_encode( $meta ) ) );
	}

	/**
	 * @param array<int, array<int, string>> $addresses PHPMailer address pairs.
	 * @return list<array{email: string, name: string}>
	 */
	private function addresses( array $addresses ): array {
		return array_map(
			static fn ( array $address ): array => array(
				'email' => (string) $address[0],
				'name'  => (string) ( $address[1] ?? '' ),
			),
			array_values( $addresses )
		);
	}
}
