<?php
/**
 * Available mailers.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use SmtpBuddy\Options;

final class Registry {

	/**
	 * @var array<string, MailerInterface>|null
	 */
	private ?array $mailers = null;

	public function __construct( private Options $options ) {}

	/**
	 * @return array<string, MailerInterface>
	 */
	public function all(): array {
		if ( null !== $this->mailers ) {
			return $this->mailers;
		}

		/**
		 * Filters the mailer classes. Each class must implement MailerInterface and accept
		 * Options as its only constructor argument.
		 *
		 * @param array<string, class-string<MailerInterface>> $classes Keyed by slug.
		 */
		$classes = apply_filters(
			'wpsb_mailers',
			array(
				'php'        => Mailers\PhpMail::class,
				'smtp'       => Mailers\Smtp::class,
				'ses'        => Mailers\AmazonSes::class,
				'brevo'      => Mailers\Brevo::class,
				'gmail'      => Mailers\Gmail::class,
				'mailgun'    => Mailers\Mailgun::class,
				'mailersend' => Mailers\MailerSend::class,
				'mailjet'    => Mailers\Mailjet::class,
				'microsoft'  => Mailers\Microsoft365::class,
				'postmark'   => Mailers\Postmark::class,
				'resend'     => Mailers\Resend::class,
				'sendgrid'   => Mailers\SendGrid::class,
				'smtp2go'    => Mailers\Smtp2go::class,
			)
		);

		$this->mailers = array();

		foreach ( $classes as $slug => $class ) {
			if ( is_subclass_of( $class, MailerInterface::class ) ) {
				$this->mailers[ $slug ] = new $class( $this->options );
			}
		}

		return $this->mailers;
	}

	public function get( string $slug ): ?MailerInterface {
		return $this->all()[ $slug ] ?? null;
	}

	/**
	 * The mailer selected in settings, or PHP mail when the selection is unknown.
	 */
	public function selected(): MailerInterface {
		return $this->get( (string) $this->options->get( 'mailer' ) ) ?? $this->all()['php'];
	}
}
