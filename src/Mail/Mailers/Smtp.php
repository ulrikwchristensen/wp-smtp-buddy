<?php
/**
 * Any SMTP server.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Mail\AbstractMailer;
use SmtpBuddy\Mail\SendResult;

final class Smtp extends AbstractMailer {

	/**
	 * Receives PHPMailer debug output while set (used by the test email tool).
	 *
	 * @var callable|null
	 */
	public static $debug_output = null;

	public function slug(): string {
		return 'smtp';
	}

	public function label(): string {
		return __( 'Other SMTP', 'wp-smtp-buddy' );
	}

	public function description(): string {
		return __( 'Any SMTP server: your host, Google Workspace with an app password, Office 365, Amazon SES SMTP, and more.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'host'       => array(
				'label'    => __( 'SMTP host', 'wp-smtp-buddy' ),
				'type'     => 'text',
				'required' => true,
			),
			'encryption' => array(
				'label'       => __( 'Encryption', 'wp-smtp-buddy' ),
				'type'        => 'select',
				'default'     => 'tls',
				'options'     => array(
					'none' => __( 'None', 'wp-smtp-buddy' ),
					'ssl'  => __( 'SSL (usually port 465)', 'wp-smtp-buddy' ),
					'tls'  => __( 'TLS / STARTTLS (usually port 587)', 'wp-smtp-buddy' ),
				),
				'description' => __( 'TLS is recommended when your server supports it.', 'wp-smtp-buddy' ),
			),
			'port'       => array(
				'label'    => __( 'SMTP port', 'wp-smtp-buddy' ),
				'type'     => 'number',
				'default'  => 587,
				'min'      => 1,
				'max'      => 65535,
				'required' => true,
			),
			'autotls'    => array(
				'label'       => __( 'Auto TLS', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'With encryption set to None, upgrade to TLS anyway if the server offers it. Turn off only if your server has a broken certificate.', 'wp-smtp-buddy' ),
			),
			'auth'       => array(
				'label'   => __( 'Authentication', 'wp-smtp-buddy' ),
				'type'    => 'checkbox',
				'default' => true,
			),
			'user'       => array(
				'label' => __( 'Username', 'wp-smtp-buddy' ),
				'type'  => 'text',
			),
			'pass'       => array(
				'label'  => __( 'Password', 'wp-smtp-buddy' ),
				'type'   => 'password',
				'secret' => true,
			),
		);
	}

	public function is_configured(): bool {
		if ( ! parent::is_configured() ) {
			return false;
		}

		return ! $this->setting( 'auth' ) || '' !== (string) $this->setting( 'user' );
	}

	public function prepare( PHPMailer $mail ): void {
		$mail->isSMTP();

		$mail->Host       = (string) $this->setting( 'host' );
		$mail->Port       = (int) $this->setting( 'port' );
		$mail->SMTPSecure = match ( $this->setting( 'encryption' ) ) {
			'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
			'tls'   => PHPMailer::ENCRYPTION_STARTTLS,
			default => '',
		};
		$mail->SMTPAutoTLS = (bool) $this->setting( 'autotls' );
		$mail->SMTPAuth    = (bool) $this->setting( 'auth' );
		$mail->Timeout     = (int) apply_filters( 'wpsb_smtp_timeout', 15 );

		if ( $mail->SMTPAuth ) {
			$mail->Username = (string) $this->setting( 'user' );
			$mail->Password = (string) $this->setting( 'pass' );
		}

		if ( null !== self::$debug_output ) {
			$mail->SMTPDebug   = 3;
			$mail->Debugoutput = self::$debug_output;
		} else {
			$mail->SMTPDebug = 0;
		}
	}

	public function send( PHPMailer $mail ): SendResult {
		return $this->post_send( $mail );
	}
}
