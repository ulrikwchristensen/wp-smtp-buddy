<?php
/**
 * Shared mailer behaviour.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Options;

abstract class AbstractMailer implements MailerInterface {

	public function __construct( protected Options $options ) {}

	public function description(): string {
		return '';
	}

	public function fields(): array {
		return array();
	}

	public function is_configured(): bool {
		foreach ( $this->fields() as $name => $field ) {
			if ( ! empty( $field['required'] ) && '' === trim( (string) $this->setting( $name ) ) ) {
				return false;
			}
		}

		return true;
	}

	public function prepare( PHPMailer $mail ): void {}

	/**
	 * Reads one of this mailer's settings, falling back to the field default.
	 */
	protected function setting( string $name ): mixed {
		return $this->options->get( $this->slug() . '.' . $name, $this->fields()[ $name ]['default'] ?? '' );
	}

	/**
	 * Runs PHPMailer's own transport (mail() or SMTP) and converts the outcome.
	 */
	protected function post_send( PHPMailer $mail ): SendResult {
		try {
			return $mail->postSend() ? SendResult::sent() : SendResult::failed( $mail->ErrorInfo );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			return SendResult::failed( $e->getMessage() );
		}
	}
}
