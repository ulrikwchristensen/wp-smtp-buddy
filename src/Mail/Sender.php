<?php
/**
 * Send pipeline: selected mailer → backup mailer on failure → debug events / hooks.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Log\DebugEvents;
use SmtpBuddy\Options;

final class Sender {

	public function __construct(
		private Options $options,
		private Registry $registry,
		private DebugEvents $debug_events,
	) {}

	public function send( PHPMailer $mail ): SendResult {
		$primary = $this->registry->selected();

		if ( ! $primary->is_configured() ) {
			$this->warn_unconfigured( $primary );
			$primary = $this->registry->get( 'php' ) ?? $primary;
		}

		$result = $this->attempt( $mail, $primary );

		if ( $result->ok ) {
			$this->sent( $mail, $primary, $result, array() );

			return $result;
		}

		$this->record_failure( $mail, $primary, $result );

		$backup = $this->backup_for( $primary );

		if ( null === $backup ) {
			$this->failed( $mail, $primary, $result, array() );

			return $result;
		}

		$backup_result = $this->attempt( $mail, $backup );

		if ( $backup_result->ok ) {
			$this->sent(
				$mail,
				$backup,
				$backup_result,
				array(
					'primary_mailer' => $primary->slug(),
					'primary_error'  => $result->error,
				)
			);

			return $backup_result;
		}

		$this->record_failure( $mail, $backup, $backup_result );

		$combined = SendResult::failed(
			sprintf( '%s Backup mailer %s also failed: %s', rtrim( $result->error, '.' ) . '.', $backup->label(), $backup_result->error ),
			$result->details
		);

		$this->failed(
			$mail,
			$primary,
			$combined,
			array(
				'backup_mailer' => $backup->slug(),
				'backup_error'  => $backup_result->error,
			)
		);

		return $combined;
	}

	/**
	 * The configured backup mailer, unless it is missing, unconfigured or the same as the primary.
	 */
	public function backup_for( MailerInterface $primary ): ?MailerInterface {
		$slug = (string) $this->options->get( 'backup.mailer' );

		if ( '' === $slug || $slug === $primary->slug() ) {
			return null;
		}

		$backup = $this->registry->get( $slug );

		return null !== $backup && $backup->is_configured() ? $backup : null;
	}

	private function attempt( PHPMailer $mail, MailerInterface $mailer ): SendResult {
		/**
		 * Fires before a message is handed to a mailer (also before a backup attempt).
		 *
		 * @param PHPMailer       $mail   The message.
		 * @param MailerInterface $mailer The mailer about to send it.
		 */
		do_action( 'wpsb_before_send', $mail, $mailer );

		try {
			$mailer->prepare( $mail );
			$result = $mail->preSend() ? $mailer->send( $mail ) : SendResult::failed( $mail->ErrorInfo );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$result = SendResult::failed( $e->getMessage() );
		}

		if ( ! $result->ok && 'smtp' === $mail->Mailer ) {
			// Don't reuse a half-open SMTP connection for the next attempt or message.
			$mail->smtpClose();
		}

		return $result;
	}

	/**
	 * @param array<string, string> $context primary_mailer and primary_error when the backup was used.
	 */
	private function sent( PHPMailer $mail, MailerInterface $mailer, SendResult $result, array $context ): void {
		/**
		 * Fires after a message was accepted by a mailer.
		 *
		 * @param PHPMailer             $mail    The message.
		 * @param string                $mailer  Slug of the mailer that sent it.
		 * @param SendResult            $result  Result, including the provider message ID when available.
		 * @param array<string, string> $context primary_mailer and primary_error when the backup mailer was used.
		 */
		do_action( 'wpsb_mail_sent', $mail, $mailer->slug(), $result, $context );
	}

	/**
	 * @param array<string, string> $context backup_mailer and backup_error when a backup was tried.
	 */
	private function failed( PHPMailer $mail, MailerInterface $mailer, SendResult $result, array $context ): void {
		/**
		 * Fires after a message failed to send (after the backup mailer, if any, also failed).
		 *
		 * @param PHPMailer             $mail    The message.
		 * @param string                $mailer  Slug of the primary mailer.
		 * @param SendResult            $result  Result with the error.
		 * @param array<string, string> $context backup_mailer and backup_error when a backup was tried.
		 */
		do_action( 'wpsb_mail_failed', $mail, $mailer->slug(), $result, $context );
	}

	private function record_failure( PHPMailer $mail, MailerInterface $mailer, SendResult $result ): void {
		$this->debug_events->record(
			DebugEvents::LEVEL_ERROR,
			$mailer->slug(),
			$result->error,
			array(
				'to'      => array_column( $mail->getToAddresses(), 0 ),
				'subject' => $mail->Subject,
				'details' => $result->details,
			)
		);
	}

	/**
	 * Records at most one warning per hour so a misconfiguration doesn't flood the log.
	 */
	private function warn_unconfigured( MailerInterface $mailer ): void {
		if ( get_transient( 'wpsb_unconfigured_warned' ) ) {
			return;
		}

		set_transient( 'wpsb_unconfigured_warned', 1, HOUR_IN_SECONDS );

		$this->debug_events->record(
			DebugEvents::LEVEL_WARNING,
			$mailer->slug(),
			sprintf( '%s is selected but not fully configured, so email was sent with PHP mail() instead.', $mailer->label() )
		);
	}
}
