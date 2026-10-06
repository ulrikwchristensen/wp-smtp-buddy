<?php
/**
 * Outcome of a send attempt.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

final class SendResult {

	/**
	 * @param array<string, mixed> $details Extra diagnostic data (HTTP status, provider error codes…).
	 */
	private function __construct(
		public readonly bool $ok,
		public readonly string $error = '',
		public readonly string $message_id = '',
		public readonly array $details = array(),
	) {}

	public static function sent( string $message_id = '' ): self {
		return new self( true, '', $message_id );
	}

	/**
	 * @param array<string, mixed> $details
	 */
	public static function failed( string $error, array $details = array() ): self {
		return new self( false, '' !== $error ? $error : 'Unknown error.', '', $details );
	}
}
