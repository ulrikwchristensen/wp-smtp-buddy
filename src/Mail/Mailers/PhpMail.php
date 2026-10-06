<?php
/**
 * WordPress default: PHP's mail() function.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Mail\AbstractMailer;
use SmtpBuddy\Mail\SendResult;

final class PhpMail extends AbstractMailer {

	public function slug(): string {
		return 'php';
	}

	public function label(): string {
		return __( 'Default (PHP mail)', 'wp-smtp-buddy' );
	}

	public function description(): string {
		return __( 'WordPress default. Unauthenticated, so messages often land in spam. Not recommended.', 'wp-smtp-buddy' );
	}

	public function prepare( PHPMailer $mail ): void {
		$mail->isMail();
	}

	public function send( PHPMailer $mail ): SendResult {
		return $this->post_send( $mail );
	}
}
