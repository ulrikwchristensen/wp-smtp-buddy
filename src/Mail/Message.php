<?php
/**
 * Provider-neutral representation of an email, built from a prepared PHPMailer instance.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Addresses are lists of `['email' => string, 'name' => string]`.
 * Attachments are lists of `['filename', 'content' (raw bytes), 'type', 'disposition', 'cid']`.
 * Headers are lists of `['name' => string, 'value' => string]`.
 */
final class Message {

	public function __construct(
		public readonly array $from,
		public readonly array $to,
		public readonly array $cc = array(),
		public readonly array $bcc = array(),
		public readonly array $reply_to = array(),
		public readonly string $subject = '',
		public readonly string $html = '',
		public readonly string $text = '',
		public readonly array $attachments = array(),
		public readonly array $headers = array(),
		public readonly string $mime = '',
	) {}

	/**
	 * Builds a message from a PHPMailer instance. Call after preSend() so `$mime` is populated.
	 */
	public static function from_phpmailer( PHPMailer $mail ): self {
		$is_html = self::is_html( (string) $mail->ContentType, (string) $mail->AltBody );

		return new self(
			from: array(
				'email' => (string) $mail->From,
				'name'  => (string) $mail->FromName,
			),
			to: self::addresses( $mail->getToAddresses() ),
			cc: self::addresses( $mail->getCcAddresses() ),
			bcc: self::addresses( $mail->getBccAddresses() ),
			reply_to: self::addresses( array_values( $mail->getReplyToAddresses() ) ),
			subject: (string) $mail->Subject,
			html: $is_html ? (string) $mail->Body : '',
			text: $is_html ? (string) $mail->AltBody : (string) $mail->Body,
			attachments: self::attachments( $mail->getAttachments() ),
			headers: array_map(
				static fn ( array $header ): array => array(
					'name'  => (string) $header[0],
					'value' => trim( (string) $header[1] ),
				),
				$mail->getCustomHeaders()
			),
			mime: $mail->getSentMIMEMessage(),
		);
	}

	/**
	 * Whether PHPMailer treats Body as HTML. preSend() rewrites ContentType to
	 * multipart/alternative when AltBody is set, and Body is then always the HTML part.
	 */
	public static function is_html( string $content_type, string $alt_body ): bool {
		return in_array( strtolower( $content_type ), array( 'text/html', 'multipart/alternative' ), true ) || '' !== $alt_body;
	}

	/**
	 * The MIME message with a Bcc header added. PHPMailer leaves Bcc out of the headers in SMTP
	 * mode, but APIs that read recipients from the raw message (Gmail, Microsoft Graph) need it;
	 * they remove it before delivery.
	 */
	public function mime_with_bcc(): string {
		if ( ! $this->bcc ) {
			return $this->mime;
		}

		return 'Bcc: ' . implode( ",\r\n ", array_map( array( self::class, 'format_address' ), $this->bcc ) ) . "\r\n" . $this->mime;
	}

	/**
	 * All recipients (to, cc and bcc) without duplicates, in that order.
	 *
	 * @return list<string>
	 */
	public function all_recipient_emails(): array {
		$emails = array();

		foreach ( array( $this->to, $this->cc, $this->bcc ) as $list ) {
			foreach ( $list as $address ) {
				$emails[ strtolower( $address['email'] ) ] ??= $address['email'];
			}
		}

		return array_values( $emails );
	}

	/**
	 * Formats an address as `"Name" <email>` (or just the email when there is no name).
	 */
	public static function format_address( array $address ): string {
		if ( '' === $address['name'] ) {
			return $address['email'];
		}

		return sprintf( '"%s" <%s>', addcslashes( $address['name'], '"\\' ), $address['email'] );
	}

	private static function addresses( array $addresses ): array {
		return array_map(
			static fn ( array $address ): array => array(
				'email' => (string) $address[0],
				'name'  => (string) ( $address[1] ?? '' ),
			),
			$addresses
		);
	}

	/**
	 * Converts PHPMailer's numerically indexed attachment arrays:
	 * 0 path or content, 1 filename, 2 name, 3 encoding, 4 type, 5 is string, 6 disposition, 7 cid.
	 */
	private static function attachments( array $items ): array {
		$attachments = array();

		foreach ( $items as $attachment ) {
			$content = $attachment[5] ? (string) $attachment[0] : self::read_file( (string) $attachment[0] );

			if ( null === $content ) {
				continue;
			}

			$attachments[] = array(
				'filename'    => (string) ( '' !== (string) $attachment[2] ? $attachment[2] : $attachment[1] ),
				'content'     => $content,
				'type'        => (string) ( $attachment[4] ?: 'application/octet-stream' ),
				'disposition' => 'inline' === $attachment[6] ? 'inline' : 'attachment',
				'cid'         => 'inline' === $attachment[6] ? (string) $attachment[7] : '',
			);
		}

		return $attachments;
	}

	private static function read_file( string $path ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return false === $content ? null : $content;
	}
}
