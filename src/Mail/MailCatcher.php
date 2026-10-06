<?php
/**
 * PHPMailer subclass that WP SMTP Buddy puts in the global $phpmailer slot.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PHPMailer\PHPMailer\Exception;
use SmtpBuddy\Plugin;

require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

// WP_PHPMailer (WordPress 6.8+) adds translated error messages; extend it when available.
if ( ! class_exists( BasePHPMailer::class, false ) ) {
	if ( is_readable( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
		require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
	}

	class_alias( class_exists( 'WP_PHPMailer', false ) ? 'WP_PHPMailer' : \PHPMailer\PHPMailer\PHPMailer::class, BasePHPMailer::class );
}

/**
 * Filled by wp_mail() as usual. Only send() changes: it hands the message to the
 * plugin's send pipeline instead of PHP's mail().
 */
class MailCatcher extends BasePHPMailer {

	public static function create(): self {
		$mailer = new self( true );

		// Same validator wp_mail() sets on the instance it creates.
		$mailer::$validator = static fn ( $email ): bool => (bool) is_email( $email );

		return $mailer;
	}

	/**
	 * @throws Exception When sending fails and exceptions are enabled (as in wp_mail()).
	 */
	public function send() {
		$plugin = Plugin::instance();

		if ( $plugin->queue()->should_queue() && $plugin->queue()->enqueue( $this ) ) {
			return true;
		}

		$result = $plugin->sender()->send( $this );

		if ( $result->ok ) {
			return true;
		}

		$this->mailHeader = '';
		$this->setError( $result->error );

		if ( $this->exceptions ) {
			throw new Exception( $result->error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught by wp_mail(), never printed as HTML.
		}

		return false;
	}
}
