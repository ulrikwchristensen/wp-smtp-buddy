<?php
/**
 * Sends a diagnostic test email (used by the admin Test tab and WP-CLI).
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

use SmtpBuddy\Mail\Mailers\Smtp;
use SmtpBuddy\Mail\Queue;
use SmtpBuddy\Mail\Registry;

final class TestEmail {

	public function __construct( private Registry $registry ) {}

	/**
	 * @return array{ok: bool, to: string, mailer: string, mailer_label: string, error: string, hint: ?string, transcript: string}
	 */
	public function send( string $to, bool $html = true ): array {
		$mailer = $this->registry->selected();

		if ( ! $mailer->is_configured() ) {
			$mailer = $this->registry->get( 'php' ) ?? $mailer;
		}

		$transcript         = new SmtpTranscript();
		$error              = '';
		$capture_error      = static function ( \WP_Error $wp_error ) use ( &$error ): void {
			$error = $wp_error->get_error_message();
		};
		Smtp::$debug_output = $transcript;
		Queue::$bypass      = true;

		add_action( 'wp_mail_failed', $capture_error );

		try {
			$ok = wp_mail(
				$to,
				/* translators: %s: site name */
				sprintf( __( 'WP SMTP Buddy test email from %s', 'wp-smtp-buddy' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				$html ? $this->html_body( $mailer->label() ) : $this->text_body( $mailer->label() ),
				$html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array()
			);
		} finally {
			Smtp::$debug_output = null;
			Queue::$bypass      = false;
			remove_action( 'wp_mail_failed', $capture_error );
		}

		return array(
			'ok'           => (bool) $ok,
			'to'           => $to,
			'mailer'       => $mailer->slug(),
			'mailer_label' => $mailer->label(),
			'error'        => $error,
			'hint'         => '' !== $error ? ErrorHints::explain( $error, $mailer->slug() ) : null,
			'transcript'   => $transcript->text(),
		);
	}

	private function text_body( string $mailer ): string {
		return sprintf(
			/* translators: 1: mailer name, 2: site URL, 3: date and time */
			__( "Congratulations! WP SMTP Buddy is working.\n\nThis test email was sent with: %1\$s\nSite: %2\$s\nSent: %3\$s", 'wp-smtp-buddy' ),
			$mailer,
			home_url(),
			wp_date( 'Y-m-d H:i:s T' )
		);
	}

	private function html_body( string $mailer ): string {
		$rows = array(
			__( 'Mailer', 'wp-smtp-buddy' ) => $mailer,
			__( 'Site', 'wp-smtp-buddy' )   => home_url(),
			__( 'Sent', 'wp-smtp-buddy' )   => wp_date( 'Y-m-d H:i:s T' ),
		);

		$table = '';

		foreach ( $rows as $label => $value ) {
			$table .= sprintf(
				'<tr><td style="padding:6px 16px 6px 0;color:#646970;">%s</td><td style="padding:6px 0;">%s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		return '<!doctype html><html><body style="margin:0;padding:32px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:#1d2327;">'
			. '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:8px;padding:32px;">'
			. '<h1 style="margin:0 0 12px;font-size:22px;">' . esc_html__( 'It works!', 'wp-smtp-buddy' ) . '</h1>'
			. '<p style="margin:0 0 20px;line-height:1.5;">' . esc_html__( 'WP SMTP Buddy delivered this test email. Your WordPress site can send email.', 'wp-smtp-buddy' ) . '</p>'
			. '<table style="border-collapse:collapse;font-size:14px;">' . $table . '</table>'
			. '</div></body></html>';
	}
}
