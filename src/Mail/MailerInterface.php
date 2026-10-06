<?php
/**
 * Contract every mailer implements.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;

interface MailerInterface {

	public function slug(): string;

	public function label(): string;

	/**
	 * One-sentence description shown in the mailer picker.
	 */
	public function description(): string;

	/**
	 * Settings fields, keyed by field name (stored as "{slug}.{name}").
	 *
	 * Each field: label, type (text|email|number|password|select|checkbox), and optionally
	 * required, secret, default, options (for select), description, min, max.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array;

	public function is_configured(): bool;

	/**
	 * Configures the PHPMailer instance before preSend() builds the MIME message.
	 */
	public function prepare( PHPMailer $mail ): void;

	/**
	 * Delivers the prepared message.
	 */
	public function send( PHPMailer $mail ): SendResult;
}
