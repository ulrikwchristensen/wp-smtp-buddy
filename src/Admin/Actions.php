<?php
/**
 * Form handlers for admin-post.php.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Alerts\Alerts;
use SmtpBuddy\Log\DebugEvents;
use SmtpBuddy\Log\EmailLog;
use SmtpBuddy\Options;
use SmtpBuddy\Support\TestEmail;

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Every handler calls verify() first.

final class Actions {

	public const SAVE         = 'wpsb_save_settings';
	public const TEST         = 'wpsb_send_test';
	public const CLEAR_EVENTS = 'wpsb_clear_debug_events';
	public const CLEAR_LOG    = 'wpsb_clear_email_log';
	public const TEST_ALERTS  = 'wpsb_test_alerts';
	public const SITE_MODE    = 'wpsb_site_mode';
	public const EXPORT       = 'wpsb_export';
	public const IMPORT       = 'wpsb_import';

	public function __construct(
		private Options $options,
		private Fields $fields,
		private TestEmail $test_email,
		private DebugEvents $debug_events,
		private EmailLog $log,
		private Alerts $alerts,
	) {}

	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ) );
		add_action( 'admin_post_' . self::TEST, array( $this, 'test' ) );
		add_action( 'admin_post_' . self::CLEAR_EVENTS, array( $this, 'clear_events' ) );
		add_action( 'admin_post_' . self::CLEAR_LOG, array( $this, 'clear_log' ) );
		add_action( 'admin_post_' . self::TEST_ALERTS, array( $this, 'test_alerts' ) );
		add_action( 'admin_post_' . self::SITE_MODE, array( $this, 'site_mode' ) );
		add_action( 'admin_post_' . self::EXPORT, array( $this, 'export' ) );
		add_action( 'admin_post_' . self::IMPORT, array( $this, 'import' ) );
	}

	public static function alert_result_key(): string {
		return 'wpsb_alert_result_' . get_current_user_id();
	}

	public static function test_result_key(): string {
		return 'wpsb_test_result_' . get_current_user_id();
	}

	public function save(): void {
		$this->verify( self::SAVE );

		self::guard_network_scope( $this->options );

		$input = isset( $_POST[ Fields::INPUT ] ) && is_array( $_POST[ Fields::INPUT ] ) ? wp_unslash( $_POST[ Fields::INPUT ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field in Fields::sanitize().
		$clear = isset( $_POST['wpsb_clear'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['wpsb_clear'] ) ) : array();

		$this->options->save( $this->fields->sanitize( $input, $clear ) );

		$args = array( 'wpsb_notice' => 'saved' );

		if ( $this->fields->invalid ) {
			$args['wpsb_invalid'] = implode( ',', $this->fields->invalid );
		}

		wp_safe_redirect( Page::url( 'settings', $args ) );
		exit;
	}

	public function test(): void {
		$this->verify( self::TEST );

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';

		if ( ! is_email( $to ) ) {
			$to = wp_get_current_user()->user_email;
		}

		set_transient( self::test_result_key(), $this->test_email->send( $to, ! empty( $_POST['html'] ) ), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( Page::url( 'test' ) );
		exit;
	}

	public function clear_events(): void {
		$this->verify( self::CLEAR_EVENTS );

		$this->debug_events->clear( Context::network() ? 0 : null );

		wp_safe_redirect( Page::url( 'debug', array( 'wpsb_notice' => 'cleared' ) ) );
		exit;
	}

	public function clear_log(): void {
		$this->verify( self::CLEAR_LOG );

		$this->log->clear( Context::network() ? 0 : null );

		wp_safe_redirect( Page::url( 'log', array( 'wpsb_notice' => 'log_cleared' ) ) );
		exit;
	}

	/**
	 * Switches a network site between the network settings and its own.
	 */
	public function site_mode(): void {
		$this->verify( self::SITE_MODE );

		if ( ! is_multisite() || Context::network() || ! $this->options->allows_override() ) {
			wp_die( esc_html__( 'The network administrator doesn\'t allow sites to use their own settings.', 'wp-smtp-buddy' ), 403 );
		}

		$mode = isset( $_POST['mode'] ) && 'custom' === $_POST['mode'] ? 'custom' : 'network';

		// Start from the network's general preferences, but never its mailer or credentials:
		// a copied (encrypted) password would still work here, and a site admin could send it
		// to a server of their choice by changing the SMTP host.
		if ( 'custom' === $mode && empty( get_option( Options::OPTION ) ) ) {
			update_option( Options::OPTION, self::shareable( $this->options->network_settings() ), false );
		}

		$this->options->set_site_mode( $mode );

		wp_safe_redirect( Page::url( 'settings', array( 'wpsb_notice' => 'site_' . $mode ) ) );
		exit;
	}

	/**
	 * Downloads the settings as a JSON file.
	 */
	public function export(): void {
		$this->verify( self::EXPORT );

		$data = ( new \SmtpBuddy\Support\SettingsTransfer( $this->options, $this->fields ) )->export( ! empty( $_POST['include_secrets'] ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wp-smtp-buddy-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Imports settings from an uploaded export file.
	 */
	public function import(): void {
		$this->verify( self::IMPORT );

		if ( is_multisite() && ! Context::network() && 'network' === $this->options->scope() ) {
			wp_die( esc_html__( 'This site uses the network email settings. Switch to custom settings first.', 'wp-smtp-buddy' ), 403 );
		}

		$file = $_FILES['settings_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only tmp_name/size/error are used; the content is validated below.
		$data = null;

		if ( is_array( $file ) && UPLOAD_ERR_OK === (int) $file['error'] && (int) $file['size'] <= 512 * KB_IN_BYTES && is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$data = json_decode( (string) file_get_contents( (string) $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$result = is_array( $data )
			? ( new \SmtpBuddy\Support\SettingsTransfer( $this->options, $this->fields ) )->import( $data )
			: new \WP_Error( 'wpsb_import_file', __( 'Choose a WP SMTP Buddy settings file (.json, at most 512 KB).', 'wp-smtp-buddy' ) );

		if ( is_wp_error( $result ) ) {
			set_transient( 'wpsb_import_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( Page::url( 'settings', array( 'wpsb_notice' => 'import_error' ) ) );
			exit;
		}

		wp_safe_redirect(
			Page::url(
				'settings',
				array(
					'wpsb_notice' => 'imported',
					'n'           => count( $result['imported'] ),
				)
			)
		);
		exit;
	}

	/**
	 * Network settings a site may start from when it switches to its own settings.
	 *
	 * @param array<string, mixed> $network
	 * @return array<string, mixed>
	 */
	public static function shareable( array $network ): array {
		$allowed = '/^(from_email|from_name|force_from_email|force_from_name|return_path|debug_events_days|log\..+|queue\..+|controls\..+)$/';

		return array_filter( $network, static fn ( string $key ): bool => 1 === preg_match( $allowed, $key ), ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Sites that follow the network settings must not use or change the network's
	 * credentials, connections or alert channels.
	 */
	public static function guard_network_scope( Options $options ): void {
		if ( is_multisite() && ! Context::network() && 'network' === $options->scope() ) {
			wp_die( esc_html__( 'This site uses the network email settings, which only network administrators can change.', 'wp-smtp-buddy' ), 403 );
		}
	}

	public function test_alerts(): void {
		$this->verify( self::TEST_ALERTS );
		self::guard_network_scope( $this->options );

		set_transient( self::alert_result_key(), $this->alerts->test(), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( Page::url( 'test' ) );
		exit;
	}

	private function verify( string $action ): void {
		if ( ! current_user_can( Page::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP SMTP Buddy.', 'wp-smtp-buddy' ), 403 );
		}

		check_admin_referer( $action );
	}
}
