<?php
/**
 * Failure alerts to Slack, Discord and Microsoft Teams.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Alerts;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Admin\Page;
use SmtpBuddy\Log\EmailLogger;
use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Mail\SendResult;
use SmtpBuddy\Options;

/**
 * Alerts carry the subject, mailer and error, but not recipient addresses, since chat
 * channels are often shared more widely than the email log.
 */
final class Alerts {

	public const CHANNELS = array( 'slack', 'discord', 'teams' );

	public function __construct(
		private Options $options,
		private Registry $registry,
		private EmailLogger $logger,
	) {}

	public function register(): void {
		// After the logger (priority 10) so the alert can link to the log entry.
		add_action( 'wpsb_mail_failed', array( $this, 'on_failed' ), 20, 4 );
		add_action( 'wpsb_mail_sent', array( $this, 'on_sent' ), 20, 4 );
	}

	/**
	 * @param array<string, string> $context backup_mailer and backup_error when a backup was tried.
	 */
	public function on_failed( PHPMailer $mail, string $mailer, SendResult $result, array $context = array() ): void {
		$this->notify(
			array(
				'type'    => 'failed',
				'subject' => (string) $mail->Subject,
				'count'   => count( $mail->getToAddresses() ) + count( $mail->getCcAddresses() ) + count( $mail->getBccAddresses() ),
				'mailer'  => $this->label( $mailer ),
				'error'   => $result->error,
				'backup'  => isset( $context['backup_mailer'] ) ? $this->label( $context['backup_mailer'] ) : '',
			),
			$mail
		);
	}

	/**
	 * Alerts when the backup mailer had to step in, since the primary mailer needs fixing.
	 *
	 * @param array<string, string> $context primary_mailer and primary_error when the backup was used.
	 */
	public function on_sent( PHPMailer $mail, string $mailer, SendResult $result, array $context = array() ): void {
		if ( empty( $context['primary_mailer'] ) || ! $this->options->get( 'alerts.on_backup' ) ) {
			return;
		}

		$this->notify(
			array(
				'type'    => 'backup',
				'subject' => (string) $mail->Subject,
				'count'   => count( $mail->getToAddresses() ) + count( $mail->getCcAddresses() ) + count( $mail->getBccAddresses() ),
				'mailer'  => $this->label( $context['primary_mailer'] ),
				'error'   => (string) ( $context['primary_error'] ?? '' ),
				'backup'  => $this->label( $mailer ),
			),
			$mail
		);
	}

	/**
	 * Channels with a webhook URL.
	 *
	 * @return array<string, string> Channel => URL.
	 */
	public function configured(): array {
		$urls = array();

		foreach ( self::CHANNELS as $channel ) {
			$url = trim( (string) $this->options->get( "alerts.{$channel}_webhook" ) );

			if ( '' !== $url ) {
				$urls[ $channel ] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Sends a test alert to every configured channel and waits for the responses.
	 *
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public function test(): array {
		$event   = $this->event(
			array(
				'type'    => 'test',
				'subject' => __( 'Test alert', 'wp-smtp-buddy' ),
				'count'   => 0,
				'mailer'  => $this->registry->selected()->label(),
				'error'   => __( 'This is a test. Alerts from WP SMTP Buddy will look like this.', 'wp-smtp-buddy' ),
				'backup'  => '',
			)
		);
		$results = array();

		foreach ( $this->configured() as $channel => $url ) {
			$response = wp_safe_remote_post( $url, $this->request( $channel, $event, true ) );

			if ( is_wp_error( $response ) ) {
				$results[ $channel ] = array(
					'ok'      => false,
					'message' => $response->get_error_message(),
				);
				continue;
			}

			$code                = (int) wp_remote_retrieve_response_code( $response );
			$results[ $channel ] = array(
				'ok'      => $code >= 200 && $code < 300,
				/* translators: 1: HTTP status code, 2: response body */
				'message' => sprintf( __( 'HTTP %1$d %2$s', 'wp-smtp-buddy' ), $code, wp_strip_all_tags( substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ) ) ),
			);
		}

		return $results;
	}

	/**
	 * Slack incoming webhook payload (Block Kit).
	 *
	 * @param array<string, mixed> $event From event().
	 * @return array<string, mixed>
	 */
	public static function slack_payload( array $event ): array {
		$fields = array();

		foreach ( $event['fields'] as $name => $value ) {
			$fields[] = array(
				'type' => 'mrkdwn',
				'text' => '*' . $name . "*\n" . self::slack_escape( $value ),
			);
		}

		return array(
			'text'   => $event['title'],
			'blocks' => array(
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => '*' . self::slack_escape( $event['title'] ) . "*\n" . self::slack_escape( $event['message'] ),
					),
				),
				array(
					'type'   => 'section',
					'fields' => array_slice( $fields, 0, 10 ),
				),
				array(
					'type'     => 'context',
					'elements' => array(
						array(
							'type' => 'mrkdwn',
							'text' => '<' . $event['url'] . '|' . self::slack_escape( $event['link_text'] ) . '>',
						),
					),
				),
			),
		);
	}

	/**
	 * Discord webhook payload (embed).
	 *
	 * @param array<string, mixed> $event From event().
	 * @return array<string, mixed>
	 */
	public static function discord_payload( array $event ): array {
		$fields = array();

		foreach ( $event['fields'] as $name => $value ) {
			$fields[] = array(
				'name'   => mb_substr( $name, 0, 256 ),
				'value'  => mb_substr( '' !== $value ? self::defuse_links( $value ) : '-', 0, 1024 ),
				'inline' => true,
			);
		}

		return array(
			'username'         => 'WP SMTP Buddy',
			'allowed_mentions' => array( 'parse' => array() ),
			'embeds'           => array(
				array(
					'title'       => mb_substr( $event['title'], 0, 256 ),
					'description' => mb_substr( self::defuse_links( $event['message'] ), 0, 4096 ),
					'url'         => $event['url'],
					'color'       => 'backup' === $event['type'] ? 0xDBA617 : ( 'test' === $event['type'] ? 0x2271B1 : 0xD63638 ),
					'fields'      => array_slice( $fields, 0, 25 ),
				),
			),
		);
	}

	/**
	 * Microsoft Teams payload: an Adaptive Card, as accepted by Teams Workflows
	 * ("Post to a channel when a webhook request is received").
	 *
	 * @param array<string, mixed> $event From event().
	 * @return array<string, mixed>
	 */
	public static function teams_payload( array $event ): array {
		$facts = array();

		foreach ( $event['fields'] as $name => $value ) {
			$facts[] = array(
				'title' => $name,
				'value' => self::defuse_links( $value ),
			);
		}

		return array(
			'type'        => 'message',
			'attachments' => array(
				array(
					'contentType' => 'application/vnd.microsoft.card.adaptive',
					'content'     => array(
						'$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
						'type'    => 'AdaptiveCard',
						'version' => '1.4',
						'body'    => array(
							array(
								'type'   => 'TextBlock',
								'size'   => 'Medium',
								'weight' => 'Bolder',
								'color'  => 'backup' === $event['type'] ? 'Warning' : ( 'test' === $event['type'] ? 'Accent' : 'Attention' ),
								'text'   => $event['title'],
								'wrap'   => true,
							),
							array(
								'type' => 'TextBlock',
								'text' => self::defuse_links( $event['message'] ),
								'wrap' => true,
							),
							array(
								'type'  => 'FactSet',
								'facts' => $facts,
							),
						),
						'actions' => array(
							array(
								'type'  => 'Action.OpenUrl',
								'title' => $event['link_text'],
								'url'   => $event['url'],
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Builds the channel-neutral alert from a raw event.
	 *
	 * @param array{type: string, subject: string, count: int, mailer: string, error: string, backup: string} $raw
	 * @return array{type: string, title: string, message: string, fields: array<string, string>, url: string, link_text: string}
	 */
	public function event( array $raw, int $suppressed = 0 ): array {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$title = match ( $raw['type'] ) {
			/* translators: %s: site name */
			'backup' => sprintf( __( 'Email sent with the backup mailer on %s', 'wp-smtp-buddy' ), $site ),
			/* translators: %s: site name */
			'test'   => sprintf( __( 'Test alert from %s', 'wp-smtp-buddy' ), $site ),
			/* translators: %s: site name */
			default  => sprintf( __( 'Email failed to send on %s', 'wp-smtp-buddy' ), $site ),
		};

		$fields = array(
			__( 'Subject', 'wp-smtp-buddy' ) => '' !== $raw['subject'] ? self::redact_emails( $raw['subject'] ) : __( '(no subject)', 'wp-smtp-buddy' ),
			__( 'Mailer', 'wp-smtp-buddy' )  => $raw['mailer'],
		);

		if ( '' !== $raw['backup'] ) {
			$fields[ __( 'Backup mailer', 'wp-smtp-buddy' ) ] = $raw['backup'];
		}

		if ( $raw['count'] > 0 ) {
			$fields[ __( 'Recipients', 'wp-smtp-buddy' ) ] = (string) $raw['count'];
		}

		$fields[ __( 'Site', 'wp-smtp-buddy' ) ] = home_url();

		// Provider errors often quote addresses; chat channels shouldn't receive them.
		$message = self::redact_emails( $raw['error'] );

		if ( $suppressed > 0 ) {
			$message .= "\n\n" . sprintf(
				/* translators: %d: number of alerts */
				_n( '%d similar alert was held back since the last one.', '%d similar alerts were held back since the last one.', $suppressed, 'wp-smtp-buddy' ),
				$suppressed
			);
		}

		$entry_id = $this->logger->last_id;

		return array(
			'type'      => $raw['type'],
			'title'     => $title,
			'message'   => $message,
			'fields'    => $fields,
			'url'       => $entry_id ? Page::url( 'log', array( 'email' => $entry_id ) ) : Page::url( 'log' ),
			'link_text' => $entry_id ? __( 'View the email in the log', 'wp-smtp-buddy' ) : __( 'Open the email log', 'wp-smtp-buddy' ),
		);
	}

	/**
	 * @param array{type: string, subject: string, count: int, mailer: string, error: string, backup: string} $raw
	 */
	private function notify( array $raw, PHPMailer $mail ): void {
		/**
		 * Filters whether an alert is sent for this email.
		 *
		 * @param bool                 $should_alert Default true.
		 * @param array<string, mixed> $raw          Alert data (type, subject, mailer, error…).
		 * @param PHPMailer            $mail         The message.
		 */
		if ( ! apply_filters( 'wpsb_should_alert', true, $raw, $mail ) ) {
			return;
		}

		$throttle = max( 0, (int) $this->options->get( 'alerts.throttle' ) ) * MINUTE_IN_SECONDS;

		foreach ( $this->configured() as $channel => $url ) {
			$suppressed_key = "wpsb_alert_suppressed_{$channel}";

			if ( $throttle && get_transient( "wpsb_alert_throttle_{$channel}" ) ) {
				update_option( $suppressed_key, (int) get_option( $suppressed_key, 0 ) + 1, false );
				continue;
			}

			$suppressed = (int) get_option( $suppressed_key, 0 );

			if ( $throttle ) {
				set_transient( "wpsb_alert_throttle_{$channel}", 1, $throttle );
			}

			if ( $suppressed ) {
				delete_option( $suppressed_key );
			}

			// Non-blocking: an alert must never slow down or break the request that sent the email.
			wp_safe_remote_post( $url, $this->request( $channel, $this->event( $raw, $suppressed ), false ) );
		}
	}

	/**
	 * @param array<string, mixed> $event
	 * @return array<string, mixed> wp_safe_remote_post() arguments.
	 */
	private function request( string $channel, array $event, bool $blocking ): array {
		$payload = match ( $channel ) {
			'discord' => self::discord_payload( $event ),
			'teams'   => self::teams_payload( $event ),
			default   => self::slack_payload( $event ),
		};

		return array(
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $payload ),
			'timeout'  => $blocking ? 10 : 3,
			'blocking' => $blocking,
		);
	}

	private function label( string $slug ): string {
		$mailer = $this->registry->get( $slug );

		return $mailer ? $mailer->label() : $slug;
	}

	public static function redact_emails( string $text ): string {
		return (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text );
	}

	/**
	 * Breaks markdown link syntax ("[text](url)"), so text from an email (for example a
	 * contact form subject) can't plant disguised links in Discord or Teams.
	 */
	private static function defuse_links( string $text ): string {
		return str_replace( array( '[', ']' ), array( '［', '］' ), $text );
	}

	/**
	 * Escapes the characters Slack's mrkdwn treats as control characters.
	 */
	private static function slack_escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}
}
