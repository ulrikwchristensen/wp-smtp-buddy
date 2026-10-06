<?php
/**
 * WP-CLI commands.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Cli;

use SmtpBuddy\Alerts\Alerts;
use SmtpBuddy\Log\EmailLog;
use SmtpBuddy\Log\Entry;
use SmtpBuddy\Log\Initiator;
use SmtpBuddy\Log\Resender;
use SmtpBuddy\Mail\Queue;
use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Admin\Fields;
use SmtpBuddy\Log\DebugEvents;
use SmtpBuddy\Options;
use SmtpBuddy\Support\SettingsTransfer;
use SmtpBuddy\Support\TestEmail;
use WP_CLI;

/**
 * Manage WP SMTP Buddy.
 */
final class Command {

	public function __construct(
		private Options $options,
		private Registry $registry,
		private TestEmail $test_email,
		private EmailLog $log,
		private Resender $resender,
		private Queue $queue,
		private Alerts $alerts,
		private Fields $fields,
		private DebugEvents $debug_events,
	) {}

	/**
	 * Shows the active mailer and whether it is configured.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy status
	 */
	public function status(): void {
		$mailer = $this->registry->selected();

		WP_CLI::line( 'Mailer:      ' . $mailer->label() . ' (' . $mailer->slug() . ')' );
		WP_CLI::line( 'Configured:  ' . ( $mailer->is_configured() ? 'yes' : 'no (falls back to PHP mail)' ) );
		WP_CLI::line( 'From Email:  ' . ( $this->options->get( 'from_email' ) ?: '(WordPress default)' ) . ( $this->options->get( 'force_from_email' ) ? ' [forced]' : '' ) );
		WP_CLI::line( 'From Name:   ' . ( $this->options->get( 'from_name' ) ?: '(WordPress default)' ) . ( $this->options->get( 'force_from_name' ) ? ' [forced]' : '' ) );
		WP_CLI::line( 'Settings:    ' . $this->options->scope() . ( is_multisite() ? ' (network settings: ' . ( $this->options->has_network_settings() ? 'yes' : 'none' ) . ', site overrides ' . ( $this->options->allows_override() ? 'allowed' : 'not allowed' ) . ')' : '' ) );
	}

	/**
	 * Sends a test email with the active mailer.
	 *
	 * ## OPTIONS
	 *
	 * <to>
	 * : Recipient address.
	 *
	 * [--plain]
	 * : Send plain text instead of HTML.
	 *
	 * [--transcript]
	 * : Print the SMTP conversation (passwords removed).
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy test you@example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function test( array $args, array $assoc_args ): void {
		if ( ! is_email( $args[0] ) ) {
			WP_CLI::error( 'Invalid email address.' );
		}

		$result = $this->test_email->send( $args[0], empty( $assoc_args['plain'] ) );

		if ( ! empty( $assoc_args['transcript'] ) && '' !== $result['transcript'] ) {
			WP_CLI::line( $result['transcript'] );
		}

		if ( $result['ok'] ) {
			WP_CLI::success( sprintf( 'Test email sent to %s with %s.', $result['to'], $result['mailer_label'] ) );

			return;
		}

		if ( $result['hint'] ) {
			WP_CLI::warning( $result['hint'] );
		}

		WP_CLI::error( sprintf( 'Sending with %s failed: %s', $result['mailer_label'], $result['error'] ?: 'Unknown error.' ) );
	}

	/**
	 * Lists the most recent emails in the log.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only show emails with this status.
	 * ---
	 * options:
	 *   - sent
	 *   - failed
	 * ---
	 *
	 * [--search=<text>]
	 * : Match subject or recipient.
	 *
	 * [--limit=<number>]
	 * : How many emails to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--network]
	 * : On multisite, list emails from all sites.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy log --status=failed
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function log( array $args, array $assoc_args ): void {
		$result = $this->log->query(
			array(
				'status'   => (string) ( $assoc_args['status'] ?? '' ),
				'search'   => (string) ( $assoc_args['search'] ?? '' ),
				'per_page' => (int) ( $assoc_args['limit'] ?? 20 ),
				'site'     => is_multisite() && ! empty( $assoc_args['network'] ) ? 0 : null,
			)
		);

		$rows = array_map(
			static fn ( Entry $entry ): array => array(
				'id'      => $entry->id,
				'site'    => $entry->site_id,
				'date'    => $entry->created_at,
				'status'  => $entry->status,
				'mailer'  => $entry->mailer,
				'to'      => $entry->to_email,
				'subject' => $entry->subject,
				'sent_by' => Initiator::label( $entry->initiator ),
				'error'   => $entry->error,
			),
			$result['items']
		);

		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array_merge( array( 'id' ), is_multisite() ? array( 'site' ) : array(), array( 'date', 'status', 'mailer', 'to', 'subject', 'sent_by', 'error' ) ) );
	}

	/**
	 * Resends emails from the log with the current mailer.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more log entry IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy resend 42 43
	 *
	 * @param array $args Positional arguments.
	 */
	public function resend( array $args ): void {
		$failed = 0;

		foreach ( $args as $id ) {
			$result = $this->resender->resend( (int) $id );

			if ( $result['ok'] ) {
				WP_CLI::success( sprintf( 'Resent #%d.', $id ) );
			} else {
				++$failed;
				WP_CLI::warning( sprintf( 'Could not resend #%d: %s', $id, $result['error'] ?: 'Unknown error.' ) );
			}
		}

		if ( $failed ) {
			WP_CLI::error( sprintf( '%d of %d emails could not be resent.', $failed, count( $args ) ) );
		}
	}

	/**
	 * Shows or processes the email queue.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : "status" (default) or "run" to send due emails now.
	 * ---
	 * default: status
	 * options:
	 *   - status
	 *   - run
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy queue run
	 *
	 * @param array $args Positional arguments.
	 */
	public function queue( array $args ): void {
		if ( 'run' === ( $args[0] ?? 'status' ) ) {
			$stats = $this->queue->process();
			WP_CLI::success( sprintf( 'Sent %d, failed %d, retrying %d.', $stats['sent'], $stats['failed'], $stats['retrying'] ) );
		}

		$status = $this->queue->status();

		WP_CLI::line( 'Enabled:   ' . ( $this->options->get( 'queue.enabled' ) ? 'yes' : 'no' ) );
		WP_CLI::line( 'Pending:   ' . $status['pending'] );
		WP_CLI::line( 'Oldest:    ' . ( $status['oldest'] ?: '-' ) );
		WP_CLI::line( 'Next run:  ' . ( $status['next_run'] ? gmdate( 'Y-m-d H:i:s', $status['next_run'] ) . ' UTC' : '-' ) );
	}

	/**
	 * Sends a test alert to every configured channel.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy alert-test
	 *
	 * @subcommand alert-test
	 */
	public function alert_test(): void {
		$results = $this->alerts->test();

		if ( ! $results ) {
			WP_CLI::error( 'No alert channels are configured.' );
		}

		foreach ( $results as $channel => $result ) {
			$result['ok'] ? WP_CLI::success( "{$channel}: delivered." ) : WP_CLI::warning( "{$channel}: {$result['message']}" );
		}
	}

	/**
	 * Lists, reads or changes settings.
	 *
	 * Secrets (passwords, API keys, webhook URLs) are shown as "(set)" and are encrypted when set.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, get or set.
	 * ---
	 * options:
	 *   - list
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<key>]
	 * : Setting key, e.g. smtp.host or from_email.
	 *
	 * [<value>]
	 * : New value (for set). Use 1/0 for on/off settings.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy settings list
	 *     wp smtp-buddy settings get mailer
	 *     wp smtp-buddy settings set mailer sendgrid
	 *     wp smtp-buddy settings set sendgrid.api_key SG.xxxx
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function settings( array $args, array $assoc_args ): void {
		$action = $args[0];
		$key    = (string) ( $args[1] ?? '' );
		$fields = array( 'mailer' => array( 'type' => 'select' ) ) + $this->fields->all();

		if ( 'list' === $action ) {
			$rows = array();

			foreach ( $fields as $name => $field ) {
				if ( 'custom' !== $field['type'] ) {
					$rows[] = array(
						'key'    => $name,
						'value'  => $this->display( $name, $field ),
						'source' => $this->options->is_constant( $name ) ? 'constant' : $this->options->scope(),
					);
				}
			}

			\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'key', 'value', 'source' ) );

			return;
		}

		if ( ! isset( $fields[ $key ] ) || 'custom' === $fields[ $key ]['type'] ) {
			WP_CLI::error( "Unknown setting '{$key}'. Run 'wp smtp-buddy settings list' to see all keys." );
		}

		if ( 'get' === $action ) {
			WP_CLI::line( $this->display( $key, $fields[ $key ] ) );

			return;
		}

		if ( ! isset( $args[2] ) ) {
			WP_CLI::error( 'Missing value.' );
		}

		if ( $this->options->is_constant( $key ) ) {
			WP_CLI::error( "'{$key}' is set by " . Options::constant_name( $key ) . ' in wp-config.php.' );
		}

		$this->options->save( $this->fields->sanitize( array( $key => (string) $args[2] ), array(), array( $key ) ) );
		$this->options->flush();

		if ( $this->fields->invalid ) {
			WP_CLI::error( "The value for '{$key}' is not valid." );
		}

		WP_CLI::success( "{$key} = " . $this->display( $key, $fields[ $key ] ) );
	}

	/**
	 * Exports the settings as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--include-secrets]
	 * : Also export passwords, API keys and webhook URLs, in plain text. Keep the file safe.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy export > smtp-buddy.json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		WP_CLI::line( (string) wp_json_encode( $this->transfer()->export( ! empty( $assoc_args['include-secrets'] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Imports settings from a JSON export.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the export file, or - for standard input.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy import smtp-buddy.json
	 *
	 * @param array $args Positional arguments.
	 */
	public function import( array $args ): void {
		$json = '-' === $args[0] ? stream_get_contents( STDIN ) : ( is_readable( $args[0] ) ? file_get_contents( $args[0] ) : false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $json ) {
			WP_CLI::error( "Can't read {$args[0]}." );
		}

		$result = $this->transfer()->import( (array) json_decode( (string) $json, true ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( $result['skipped'] ) {
			WP_CLI::warning( 'Skipped: ' . implode( ', ', $result['skipped'] ) );
		}

		WP_CLI::success( sprintf( 'Imported %d settings.', count( $result['imported'] ) ) );
	}

	/**
	 * Deletes email log entries and debug events.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Delete entries older than this many days. Default: the retention setting.
	 *
	 * [--all]
	 * : Delete everything for this site.
	 *
	 * [--yes]
	 * : Skip the confirmation for --all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp-buddy purge --days=7
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function purge( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['all'] ) ) {
			WP_CLI::confirm( 'Delete the entire email log and all debug events for this site?', $assoc_args );
			$deleted = $this->log->clear();
			$this->debug_events->clear();
			WP_CLI::success( "Deleted {$deleted} emails and all debug events." );

			return;
		}

		$days    = isset( $assoc_args['days'] ) ? max( 1, (int) $assoc_args['days'] ) : (int) $this->options->get( 'log.retention_days' );
		$deleted = $this->log->purge( $days );
		$this->debug_events->purge( $days );

		WP_CLI::success( "Deleted {$deleted} emails (and debug events) older than {$days} days." );
	}

	private function transfer(): SettingsTransfer {
		return new SettingsTransfer( $this->options, $this->fields );
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function display( string $key, array $field ): string {
		if ( ! empty( $field['secret'] ) ) {
			return $this->options->has( $key ) ? '(set)' : '';
		}

		$value = $this->options->get( $key, $field['default'] ?? '' );

		return is_bool( $value ) ? ( $value ? 'on' : 'off' ) : (string) $value;
	}
}
