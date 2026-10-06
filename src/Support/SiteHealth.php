<?php
/**
 * Site Health tests and debug information.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

use SmtpBuddy\Admin\Page;
use SmtpBuddy\Install;
use SmtpBuddy\Log\DebugEvents;
use SmtpBuddy\Log\EmailLog;
use SmtpBuddy\Mail\Queue;
use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Options;

final class SiteHealth {

	public function __construct(
		private Options $options,
		private Registry $registry,
		private EmailLog $log,
		private DebugEvents $debug_events,
		private DomainCheck $domain_check,
		private Queue $queue,
	) {}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * DNS lookups can be slow, so the domain test runs asynchronously through this route.
	 */
	public function register_route(): void {
		register_rest_route(
			'wpsb/v1',
			'/site-health/domain',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'test_domain' ),
				'permission_callback' => static fn (): bool => current_user_can( 'view_site_health_checks' ),
			)
		);
	}

	/**
	 * The domain the site sends from: the From Email's domain, or the site's own.
	 */
	public static function sending_domain( Options $options ): string {
		$from = (string) $options->get( 'from_email' );

		if ( str_contains( $from, '@' ) ) {
			return strtolower( substr( strrchr( $from, '@' ), 1 ) );
		}

		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * @param array<string, array<string, mixed>> $tests
	 * @return array<string, array<string, mixed>>
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['wpsb_mailer']   = array(
			'label' => __( 'Email mailer', 'wp-smtp-buddy' ),
			'test'  => array( $this, 'test_mailer' ),
		);
		$tests['direct']['wpsb_delivery'] = array(
			'label' => __( 'Email delivery', 'wp-smtp-buddy' ),
			'test'  => array( $this, 'test_delivery' ),
		);
		$tests['direct']['wpsb_queue']    = array(
			'label' => __( 'Email queue', 'wp-smtp-buddy' ),
			'test'  => array( $this, 'test_queue' ),
		);
		$tests['async']['wpsb_domain']    = array(
			'label'             => __( 'Email domain authentication', 'wp-smtp-buddy' ),
			'test'              => rest_url( 'wpsb/v1/site-health/domain' ),
			'has_rest'          => true,
			'async_direct_test' => array( $this, 'test_domain' ),
		);

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_mailer(): array {
		$mailer = $this->registry->selected();

		if ( 'php' === $mailer->slug() ) {
			return $this->result(
				'wpsb_mailer',
				'recommended',
				__( 'Email is sent with PHP mail()', 'wp-smtp-buddy' ),
				__( 'PHP mail() is unauthenticated, so emails from this site often end up in spam or never arrive. Choose an SMTP server or an email service in WP SMTP Buddy.', 'wp-smtp-buddy' )
			);
		}

		if ( ! $mailer->is_configured() ) {
			return $this->result(
				'wpsb_mailer',
				'critical',
				/* translators: %s: mailer name */
				sprintf( __( '%s is not fully configured', 'wp-smtp-buddy' ), $mailer->label() ),
				__( 'Required settings are missing, so email is sent with PHP mail() instead.', 'wp-smtp-buddy' )
			);
		}

		return $this->result(
			'wpsb_mailer',
			'good',
			/* translators: %s: mailer name */
			sprintf( __( 'Email is sent with %s', 'wp-smtp-buddy' ), $mailer->label() ),
			__( 'WordPress email goes through an authenticated mailer.', 'wp-smtp-buddy' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_delivery(): array {
		$latest = $this->options->get( 'log.enabled' ) && $this->log->is_ready() ? $this->log->latest() : null;

		if ( null !== $latest && EmailLog::STATUS_FAILED === $latest->status ) {
			return $this->result(
				'wpsb_delivery',
				'critical',
				__( 'The most recent email failed to send', 'wp-smtp-buddy' ),
				/* translators: 1: email subject, 2: error message */
				sprintf( __( '"%1$s" could not be sent: %2$s', 'wp-smtp-buddy' ), $latest->subject, $latest->error ),
				Page::url( 'log' )
			);
		}

		$error = $this->debug_events->latest_error();

		if ( null !== $error && strtotime( $error->created_at . ' UTC' ) > time() - DAY_IN_SECONDS && ( null === $latest || strtotime( $latest->created_at . ' UTC' ) < strtotime( $error->created_at . ' UTC' ) ) ) {
			return $this->result(
				'wpsb_delivery',
				'recommended',
				__( 'An email failed to send in the last 24 hours', 'wp-smtp-buddy' ),
				$error->message,
				Page::url( 'debug' )
			);
		}

		return $this->result(
			'wpsb_delivery',
			'good',
			__( 'No recent email delivery errors', 'wp-smtp-buddy' ),
			__( 'Recent emails were accepted by the mailer.', 'wp-smtp-buddy' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_queue(): array {
		$status = $this->queue->status();
		$age    = '' !== $status['oldest'] ? time() - (int) strtotime( $status['oldest'] . ' UTC' ) : 0;

		if ( $status['pending'] > 0 && $age > 15 * MINUTE_IN_SECONDS ) {
			return $this->result(
				'wpsb_queue',
				'critical',
				__( 'Queued emails are not being sent', 'wp-smtp-buddy' ),
				sprintf(
					/* translators: 1: number of emails, 2: time span */
					__( '%1$d emails are waiting, the oldest for %2$s. WP-Cron may not be running: check that DISABLE_WP_CRON isn\'t set without a server cron job, or set one up to call wp-cron.php every minute.', 'wp-smtp-buddy' ),
					$status['pending'],
					human_time_diff( time() - $age )
				)
			);
		}

		return $this->result(
			'wpsb_queue',
			'good',
			$this->options->get( 'queue.enabled' ) ? __( 'The email queue is running', 'wp-smtp-buddy' ) : __( 'Emails are sent immediately', 'wp-smtp-buddy' ),
			/* translators: %d: number of emails */
			sprintf( __( '%d emails are waiting in the queue.', 'wp-smtp-buddy' ), $status['pending'] )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function test_domain(): array {
		$domain = self::sending_domain( $this->options );
		$result = $this->domain_check->check_cached( $domain, $this->registry->selected()->slug() );
		$worst  = DomainCheck::worst( $result );
		$lines  = array();

		foreach ( $result as $type => $item ) {
			$lines[] = strtoupper( $type ) . ': ' . $item['message'];
		}

		if ( DomainCheck::INFO === $worst ) {
			return $this->result(
				'wpsb_domain',
				'good',
				/* translators: %s: domain name */
				sprintf( __( 'Email authentication can\'t be checked for %s', 'wp-smtp-buddy' ), $domain ),
				implode( ' ', $lines ),
				Page::url( 'domain' )
			);
		}

		return $this->result(
			'wpsb_domain',
			DomainCheck::PASS === $worst ? 'good' : 'recommended',
			DomainCheck::PASS === $worst
				/* translators: %s: domain name */
				? sprintf( __( 'Email authentication is set up for %s', 'wp-smtp-buddy' ), $domain )
				/* translators: %s: domain name */
				: sprintf( __( 'Email authentication for %s could be improved', 'wp-smtp-buddy' ), $domain ),
			implode( ' ', $lines ),
			Page::url( 'domain' )
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $info
	 * @return array<string, array<string, mixed>>
	 */
	public function debug_information( array $info ): array {
		$mailer    = $this->registry->selected();
		$constants = array_filter(
			array_keys( get_defined_constants( true )['user'] ?? array() ),
			static fn ( string $name ): bool => str_starts_with( $name, 'WPSB_' ) && ! in_array( $name, array( 'WPSB_VERSION', 'WPSB_FILE', 'WPSB_PATH', 'WPSB_URL' ), true )
		);

		$info['wp-smtp-buddy'] = array(
			'label'  => __( 'WP SMTP Buddy', 'wp-smtp-buddy' ),
			'fields' => array(
				'version'    => array(
					'label' => __( 'Version', 'wp-smtp-buddy' ),
					'value' => WPSB_VERSION,
				),
				'mailer'     => array(
					'label' => __( 'Mailer', 'wp-smtp-buddy' ),
					'value' => $mailer->label() . ( $mailer->is_configured() ? '' : ' (' . __( 'not configured', 'wp-smtp-buddy' ) . ')' ),
					'debug' => $mailer->slug(),
				),
				'from'       => array(
					'label'   => __( 'From Email', 'wp-smtp-buddy' ),
					'value'   => (string) $this->options->get( 'from_email' ) . ( $this->options->get( 'force_from_email' ) ? ' (forced)' : '' ),
					'private' => true,
				),
				'log'        => array(
					'label' => __( 'Email log', 'wp-smtp-buddy' ),
					'value' => $this->options->get( 'log.enabled' ) ? (string) $this->options->get( 'log.content' ) : __( 'Off', 'wp-smtp-buddy' ),
				),
				'backup'     => array(
					'label' => __( 'Backup mailer', 'wp-smtp-buddy' ),
					'value' => (string) $this->options->get( 'backup.mailer' ) ?: __( 'None', 'wp-smtp-buddy' ),
				),
				'queue'      => array(
					'label' => __( 'Background sending', 'wp-smtp-buddy' ),
					'value' => $this->options->get( 'queue.enabled' ) ? sprintf( 'on, %d pending, limit %d/min', $this->queue->status()['pending'], (int) $this->options->get( 'queue.rate_limit' ) ) : __( 'Off', 'wp-smtp-buddy' ),
				),
				'scope'      => array(
					'label' => __( 'Settings scope', 'wp-smtp-buddy' ),
					'value' => $this->options->scope(),
				),
				'constants'  => array(
					'label' => __( 'Constants in wp-config.php', 'wp-smtp-buddy' ),
					'value' => $constants ? implode( ', ', $constants ) : __( 'None', 'wp-smtp-buddy' ),
				),
				'db_version' => array(
					'label' => __( 'Database version', 'wp-smtp-buddy' ),
					'value' => (string) get_site_option( Install::DB_VERSION_OPTION, 0 ),
				),
			),
		);

		return $info;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function result( string $test, string $status, string $label, string $description, string $link = '' ): array {
		return array(
			'test'        => $test,
			'status'      => $status,
			'label'       => $label,
			'badge'       => array(
				'label' => __( 'Email', 'wp-smtp-buddy' ),
				'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ),
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( '' !== $link ? $link : Page::url() ),
				esc_html__( 'Open WP SMTP Buddy', 'wp-smtp-buddy' )
			),
		);
	}
}
