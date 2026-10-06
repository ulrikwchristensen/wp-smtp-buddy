<?php
/**
 * Hooks WP SMTP Buddy into wp_mail().
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Options;

final class Interceptor {

	public const CONFLICT_OPTION = 'wpsb_phpmailer_conflict';

	public function __construct(
		private Options $options,
		private Registry $registry,
	) {}

	public function register(): void {
		// pre_wp_mail runs inside wp_mail() just before the global $phpmailer is (re)used,
		// so PHPMailer is only loaded on requests that actually send mail.
		add_filter( 'pre_wp_mail', array( $this, 'install' ), PHP_INT_MAX );
		add_action( 'phpmailer_init', array( $this, 'phpmailer_init' ), PHP_INT_MAX );

		// Also replace the sender before wp_mail() calls setFrom(): when WordPress's default
		// address is invalid (e.g. wordpress@localhost), wp_mail() fails right there, before
		// phpmailer_init would run.
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
	}

	public function filter_from_email( mixed $from ): mixed {
		$configured = trim( (string) $this->options->get( 'from_email' ) );

		if ( '' !== $configured && is_email( $configured ) && $this->replaces_email( (string) $from ) ) {
			return $configured;
		}

		return $from;
	}

	public function filter_from_name( mixed $name ): mixed {
		$configured = (string) $this->options->get( 'from_name' );

		return '' !== $configured && $this->replaces_name( (string) $name ) ? $configured : $name;
	}

	private function replaces_email( string $from ): bool {
		return $this->options->get( 'force_from_email' ) || '' === $from || str_starts_with( strtolower( $from ), 'wordpress@' );
	}

	private function replaces_name( string $name ): bool {
		return $this->options->get( 'force_from_name' ) || '' === $name || 'WordPress' === $name;
	}

	/**
	 * Puts MailCatcher in the global $phpmailer slot, unless another plugin owns it.
	 *
	 * @param null|bool $short_circuit Value from other pre_wp_mail callbacks; passed through.
	 * @return null|bool
	 */
	public function install( $short_circuit ) {
		global $phpmailer;

		if ( null !== $short_circuit || $phpmailer instanceof MailCatcher ) {
			return $short_circuit;
		}

		if ( $phpmailer instanceof PHPMailer && ! in_array( get_class( $phpmailer ), array( PHPMailer::class, 'WP_PHPMailer' ), true ) ) {
			// Another plugin replaced PHPMailer. Don't fight over it; fall back to phpmailer_init.
			if ( get_option( self::CONFLICT_OPTION ) !== get_class( $phpmailer ) ) {
				update_option( self::CONFLICT_OPTION, get_class( $phpmailer ), false );
			}

			return $short_circuit;
		}

		$phpmailer = MailCatcher::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		if ( false !== get_option( self::CONFLICT_OPTION ) ) {
			delete_option( self::CONFLICT_OPTION );
		}

		return $short_circuit;
	}

	/**
	 * Applies sender overrides to every message. When another plugin owns $phpmailer,
	 * SMTP settings are applied here too (API mailers need MailCatcher and can't work then).
	 */
	public function phpmailer_init( PHPMailer $mail ): void {
		$this->apply_from( $mail );

		if ( ! $mail instanceof MailCatcher ) {
			$mailer = $this->registry->selected();

			if ( 'smtp' === $mailer->slug() && $mailer->is_configured() ) {
				$mailer->prepare( $mail );
			}
		}
	}

	private function apply_from( PHPMailer $mail ): void {
		$from_email = trim( (string) $this->options->get( 'from_email' ) );
		$from_name  = (string) $this->options->get( 'from_name' );

		// Without "force", only replace WordPress's defaults (wordpress@domain / "WordPress")
		// so plugins that set their own sender keep it.
		if ( '' !== $from_email && is_email( $from_email ) && $this->replaces_email( (string) $mail->From ) ) {
			$mail->From = $from_email;
		}

		if ( '' !== $from_name && $this->replaces_name( (string) $mail->FromName ) ) {
			$mail->FromName = $from_name;
		}

		if ( $this->options->get( 'return_path' ) ) {
			$mail->Sender = $mail->From;
		}
	}
}
