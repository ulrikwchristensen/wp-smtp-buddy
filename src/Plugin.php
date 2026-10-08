<?php
/**
 * Bootstrap and service container.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy;

use SmtpBuddy\Alerts\Alerts;
use SmtpBuddy\Log\AttachmentStore;
use SmtpBuddy\Log\DebugEvents;
use SmtpBuddy\Log\EmailLog;
use SmtpBuddy\Log\EmailLogger;
use SmtpBuddy\Log\Privacy;
use SmtpBuddy\Log\Resender;
use SmtpBuddy\Mail\Interceptor;
use SmtpBuddy\Mail\Queue;
use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Mail\Sender;
use SmtpBuddy\Support\DomainCheck;
use SmtpBuddy\Support\GitHubUpdater;
use SmtpBuddy\Support\SiteHealth;
use SmtpBuddy\Support\TestEmail;

final class Plugin {

	private static ?self $instance = null;

	private Options $options;
	private Registry $registry;
	private DebugEvents $debug_events;
	private Sender $sender;
	private AttachmentStore $attachments;
	private EmailLog $email_log;
	private EmailLogger $email_logger;
	private Queue $queue;
	private Alerts $alerts;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->options      = new Options( new Crypto() );
		$this->registry     = new Registry( $this->options );
		$this->debug_events = new DebugEvents();
		$this->sender       = new Sender( $this->options, $this->registry, $this->debug_events );
		$this->attachments  = new AttachmentStore();
		$this->email_log    = new EmailLog( $this->attachments );
		$this->email_logger = new EmailLogger( $this->options, $this->email_log, $this->attachments );
		$this->queue        = new Queue( $this->options, $this->sender, $this->email_logger, $this->debug_events );
		$this->alerts       = new Alerts( $this->options, $this->registry, $this->email_logger );
	}

	public function boot(): void {
		( new GitHubUpdater() )->register();
		( new Interceptor( $this->options, $this->registry ) )->register();
		$this->email_logger->register();
		$this->queue->register();
		$this->alerts->register();
		( new Mail\EmailControls( $this->options ) )->register();
		( new Privacy( $this->email_log, $this->debug_events ) )->register();
		( new SiteHealth( $this->options, $this->registry, $this->email_log, $this->debug_events, new DomainCheck(), $this->queue ) )->register();

		add_action( Install::CLEANUP_HOOK, array( $this, 'cleanup' ) );

		// Each site purges its own log rows (sites can keep different retention periods).
		if ( is_multisite() ) {
			add_action( 'init', array( $this, 'ensure_cron' ) );
		}

		// Cached settings belong to one site.
		add_action( 'switch_blog', array( $this->options, 'flush' ) );

		if ( is_admin() ) {
			// Before admin menus are built (they need to know the context).
			add_action( 'init', fn () => Admin\Context::init( $this->options ), 0 );
			add_action( 'admin_init', array( Install::class, 'maybe_upgrade' ) );
			add_action( 'admin_init', array( $this, 'ensure_cron' ) );

			$fields     = new Admin\Fields( $this->options, $this->registry );
			$log_screen = new Admin\LogScreen( $this->options, $this->registry, $this->email_log, $this->resender() );

			( new Admin\Page( $this->options, $this->registry, $fields, $this->debug_events, $log_screen, new Admin\DomainScreen( $this->options, $this->registry, new DomainCheck() ), $this->sender, $this->queue, $this->alerts ) )->register();
			( new Admin\Actions( $this->options, $fields, $this->test_email(), $this->debug_events, $this->email_log, $this->alerts ) )->register();
			( new Admin\Notices( $this->registry ) )->register();
			( new Admin\Wizard( $this->options, $this->registry, $fields, $this->test_email() ) )->register();
			( new Auth\OAuthController( $this->registry ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'smtp-buddy', new Cli\Command( $this->options, $this->registry, $this->test_email(), $this->email_log, $this->resender(), $this->queue, $this->alerts, new Admin\Fields( $this->options, $this->registry ), $this->debug_events ) );
		}
	}

	public function cleanup(): void {
		$this->debug_events->purge( (int) $this->options->get( 'debug_events_days' ) );

		if ( $this->email_log->is_ready() ) {
			$this->email_log->purge( (int) $this->options->get( 'log.retention_days' ) );
		}
	}

	public function ensure_cron(): void {
		if ( ! wp_next_scheduled( Install::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Install::CLEANUP_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Install::CLEANUP_HOOK );
		wp_clear_scheduled_hook( Queue::HOOK );
	}

	public function options(): Options {
		return $this->options;
	}

	public function registry(): Registry {
		return $this->registry;
	}

	public function sender(): Sender {
		return $this->sender;
	}

	public function debug_events(): DebugEvents {
		return $this->debug_events;
	}

	public function email_log(): EmailLog {
		return $this->email_log;
	}

	public function queue(): Queue {
		return $this->queue;
	}

	public function resender(): Resender {
		return new Resender( $this->email_log, $this->email_logger, $this->attachments );
	}

	private function test_email(): TestEmail {
		return new TestEmail( $this->registry );
	}
}
