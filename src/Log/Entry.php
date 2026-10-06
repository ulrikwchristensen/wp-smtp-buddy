<?php
/**
 * One email log entry.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

final class Entry {

	public readonly int $id;
	public readonly int $site_id;
	public readonly string $status;
	public readonly string $mailer;
	public readonly string $from_email;
	public readonly string $to_email;
	public readonly string $subject;
	public readonly string $content_type;
	public readonly ?string $body;
	public readonly string $alt_body;
	public readonly string $error;
	public readonly string $message_id;
	public readonly string $initiator;
	public readonly int $parent_id;
	public readonly string $primary_mailer;
	public readonly string $primary_error;
	public readonly string $created_at;

	/**
	 * Decoded JSON: from_name, to, cc, bcc, reply_to (lists of {email, name}),
	 * custom (list of [name, value]) and charset.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $headers;

	/**
	 * Decoded JSON: list of {name, type, disposition, cid, size, stored, path?}.
	 *
	 * @var list<array<string, mixed>>
	 */
	public readonly array $attachments;

	/**
	 * @param array<string, mixed> $row Database row (list queries load a subset of columns).
	 */
	public function __construct( array $row ) {
		$this->id             = (int) $row['id'];
		$this->site_id        = (int) ( $row['site_id'] ?? 1 );
		$this->status         = (string) ( $row['status'] ?? '' );
		$this->mailer         = (string) ( $row['mailer'] ?? '' );
		$this->from_email     = (string) ( $row['from_email'] ?? '' );
		$this->to_email       = (string) ( $row['to_email'] ?? '' );
		$this->subject        = (string) ( $row['subject'] ?? '' );
		$this->content_type   = (string) ( $row['content_type'] ?? 'text/plain' );
		$this->body           = isset( $row['body'] ) ? (string) $row['body'] : null;
		$this->alt_body       = (string) ( $row['alt_body'] ?? '' );
		$this->error          = (string) ( $row['error'] ?? '' );
		$this->message_id     = (string) ( $row['message_id'] ?? '' );
		$this->initiator      = (string) ( $row['initiator'] ?? '' );
		$this->parent_id      = (int) ( $row['parent_id'] ?? 0 );
		$this->primary_mailer = (string) ( $row['primary_mailer'] ?? '' );
		$this->primary_error  = (string) ( $row['primary_error'] ?? '' );
		$this->created_at     = (string) ( $row['created_at'] ?? '' );
		$this->headers        = self::decode( $row['headers'] ?? null );
		$this->attachments    = array_values( self::decode( $row['attachments'] ?? null ) );
	}

	/**
	 * Whether the message content was stored, which is required to view or resend it.
	 */
	public function has_content(): bool {
		return null !== $this->body;
	}

	public function is_html(): bool {
		return 'text/html' === strtolower( $this->content_type );
	}

	/**
	 * Addresses stored under a header key (to, cc, bcc, reply_to).
	 *
	 * @return list<array{email: string, name: string}>
	 */
	public function addresses( string $key ): array {
		$addresses = array();

		foreach ( (array) ( $this->headers[ $key ] ?? array() ) as $address ) {
			if ( is_array( $address ) && ! empty( $address['email'] ) ) {
				$addresses[] = array(
					'email' => (string) $address['email'],
					'name'  => (string) ( $address['name'] ?? '' ),
				);
			}
		}

		return $addresses;
	}

	/**
	 * @return array<mixed>
	 */
	private static function decode( mixed $json ): array {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}
}
