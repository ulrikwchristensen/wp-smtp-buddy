<?php
/**
 * Optional send queue, processed by WP-Cron with retries and a per-minute rate limit.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use SmtpBuddy\Install;
use SmtpBuddy\Log\DebugEvents;
use SmtpBuddy\Log\EmailLogger;
use SmtpBuddy\Log\Initiator;
use SmtpBuddy\Options;

/**
 * Messages are stored fully (attachment contents included) when wp_mail() is called, because
 * many plugins delete their temporary attachment files as soon as wp_mail() returns.
 */
final class Queue {

	public const HOOK = 'wpsb_process_queue';

	private const LOCK_OPTION  = 'wpsb_queue_lock';
	private const RATE_OPTION  = 'wpsb_queue_rate';
	private const MAX_ATTEMPTS = 3;
	private const BATCH        = 50;
	private const LOCK_TTL     = 300;

	/**
	 * Set while sending something that must go out immediately (test emails, resends).
	 */
	public static bool $bypass = false;

	private bool $processing = false;

	public function __construct(
		private Options $options,
		private Sender $sender,
		private EmailLogger $logger,
		private DebugEvents $debug_events,
	) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
	}

	public function is_ready(): bool {
		return (int) get_site_option( Install::DB_VERSION_OPTION, 0 ) >= 3;
	}

	public function should_queue(): bool {
		return ! self::$bypass && ! $this->processing && $this->options->get( 'queue.enabled' ) && $this->is_ready();
	}

	/**
	 * Stores the message for sending. Returns false if it couldn't be queued (send directly then).
	 */
	public function enqueue( PHPMailer $mail ): bool {
		global $wpdb;

		$payload = wp_json_encode( self::payload( $mail ) );

		if ( false === $payload ) {
			return false;
		}

		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Install::queue_table(),
			array(
				'site_id'      => get_current_blog_id(),
				'payload'      => $payload,
				'available_at' => $now,
				'created_at'   => $now,
			)
		);

		if ( false === $inserted ) {
			return false;
		}

		$this->schedule( time() );

		return true;
	}

	/**
	 * WP-Cron callback.
	 */
	public function run(): void {
		$this->process();
	}

	/**
	 * Sends due messages, as many as the rate limit allows.
	 *
	 * @return array{sent: int, failed: int, retrying: int}
	 */
	public function process(): array {
		$stats = array(
			'sent'     => 0,
			'failed'   => 0,
			'retrying' => 0,
		);

		if ( ! $this->is_ready() || ! $this->lock() ) {
			return $stats;
		}

		$this->processing = true;

		try {
			$allowance = $this->allowance();

			if ( $allowance > 0 ) {
				foreach ( $this->due( min( $allowance, self::BATCH ) ) as $row ) {
					++$stats[ $this->send_row( $row ) ];
				}
			}

			$this->schedule_next();
		} finally {
			$this->processing = false;
			delete_option( self::LOCK_OPTION );
		}

		return $stats;
	}

	/**
	 * Makes sure a run is scheduled while messages are waiting (e.g. after a cron event was lost).
	 */
	public function ensure_scheduled(): void {
		if ( $this->is_ready() && ! wp_next_scheduled( self::HOOK ) && $this->status()['pending'] > 0 ) {
			$this->schedule( time() );
		}
	}

	/**
	 * @return array{pending: int, oldest: string, next_run: int}
	 */
	public function status(): array {
		global $wpdb;

		if ( ! $this->is_ready() ) {
			return array(
				'pending'  => 0,
				'oldest'   => '',
				'next_run' => 0,
			);
		}

		$table = Install::queue_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) AS pending, MIN(created_at) AS oldest FROM %i WHERE site_id = %d', $table, get_current_blog_id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'pending'  => (int) ( $row->pending ?? 0 ),
			'oldest'   => (string) ( $row->oldest ?? '' ),
			'next_run' => (int) wp_next_scheduled( self::HOOK ),
		);
	}

	/**
	 * Everything needed to rebuild the message later, after wp_mail() and phpmailer_init ran.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload( PHPMailer $mail ): array {
		$attachments = array();

		foreach ( $mail->getAttachments() as $attachment ) {
			$content = $attachment[5] ? (string) $attachment[0] : ( is_readable( (string) $attachment[0] ) ? (string) file_get_contents( (string) $attachment[0] ) : null ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( null === $content ) {
				continue;
			}

			$attachments[] = array(
				'content'     => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'name'        => (string) ( '' !== (string) $attachment[2] ? $attachment[2] : $attachment[1] ),
				'encoding'    => (string) $attachment[3],
				'type'        => (string) $attachment[4],
				'disposition' => (string) $attachment[6],
				'cid'         => (string) $attachment[7],
			);
		}

		return array(
			'from'         => (string) $mail->From,
			'from_name'    => (string) $mail->FromName,
			'sender'       => (string) $mail->Sender,
			'to'           => $mail->getToAddresses(),
			'cc'           => $mail->getCcAddresses(),
			'bcc'          => $mail->getBccAddresses(),
			'reply_to'     => array_values( $mail->getReplyToAddresses() ),
			'subject'      => (string) $mail->Subject,
			'body'         => (string) $mail->Body,
			'alt_body'     => (string) $mail->AltBody,
			'content_type' => (string) $mail->ContentType,
			'charset'      => (string) $mail->CharSet,
			'encoding'     => (string) $mail->Encoding,
			'headers'      => $mail->getCustomHeaders(),
			'attachments'  => $attachments,
			'initiator'    => Initiator::detect(),
		);
	}

	/**
	 * Rebuilds a message from a stored payload.
	 *
	 * @param array<string, mixed> $payload
	 * @throws \PHPMailer\PHPMailer\Exception When an address or attachment is invalid.
	 */
	public static function hydrate( array $payload ): MailCatcher {
		$mail = MailCatcher::create();

		$mail->CharSet     = (string) ( $payload['charset'] ?? 'UTF-8' );
		$mail->ContentType = (string) ( $payload['content_type'] ?? 'text/plain' );
		$mail->Encoding    = (string) ( $payload['encoding'] ?? '8bit' );
		$mail->From        = (string) ( $payload['from'] ?? '' );
		$mail->FromName    = (string) ( $payload['from_name'] ?? '' );
		$mail->Sender      = (string) ( $payload['sender'] ?? '' );
		$mail->Subject     = (string) ( $payload['subject'] ?? '' );
		$mail->Body        = (string) ( $payload['body'] ?? '' );
		$mail->AltBody     = (string) ( $payload['alt_body'] ?? '' );

		foreach ( array(
			'to'       => 'addAddress',
			'cc'       => 'addCC',
			'bcc'      => 'addBCC',
			'reply_to' => 'addReplyTo',
		) as $key => $method ) {
			foreach ( (array) ( $payload[ $key ] ?? array() ) as $address ) {
				$mail->$method( (string) $address[0], (string) ( $address[1] ?? '' ) );
			}
		}

		foreach ( (array) ( $payload['headers'] ?? array() ) as $header ) {
			$mail->addCustomHeader( (string) $header[0], (string) $header[1] );
		}

		foreach ( (array) ( $payload['attachments'] ?? array() ) as $item ) {
			$content = (string) base64_decode( (string) $item['content'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( 'inline' === $item['disposition'] && '' !== $item['cid'] ) {
				$mail->addStringEmbeddedImage( $content, $item['cid'], $item['name'], $item['encoding'] ?: PHPMailer::ENCODING_BASE64, $item['type'], 'inline' );
			} else {
				$mail->addStringAttachment( $content, $item['name'], $item['encoding'] ?: PHPMailer::ENCODING_BASE64, $item['type'], $item['disposition'] ?: 'attachment' );
			}
		}

		return $mail;
	}

	/**
	 * @return 'sent'|'failed'|'retrying'
	 */
	private function send_row( object $row ): string {
		global $wpdb;

		$payload  = json_decode( (string) $row->payload, true );
		$attempts = (int) $row->attempts + 1;
		$final    = $attempts >= self::MAX_ATTEMPTS;

		if ( ! is_array( $payload ) ) {
			$this->delete( (int) $row->id );

			return 'failed';
		}

		// Failures are only logged and alerted on the last attempt, so retries don't create noise.
		$quiet_log   = static fn ( bool $should, PHPMailer $mail, SendResult $result ): bool => $should && $result->ok;
		$quiet_alert = static fn ( bool $should, array $raw ): bool => $should && 'failed' !== $raw['type'];

		if ( ! $final ) {
			add_filter( 'wpsb_should_log', $quiet_log, 99, 3 );
			add_filter( 'wpsb_should_alert', $quiet_alert, 99, 2 );
		}

		$this->logger->context = array(
			'parent_id' => 0,
			'initiator' => (string) ( $payload['initiator'] ?? '' ),
		);

		try {
			$result = $this->sender->send( self::hydrate( $payload ) );
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			// The stored message can't be rebuilt (e.g. an invalid address), so retrying won't help.
			$result = SendResult::failed( $e->getMessage() );
			$final  = true;

			$this->debug_events->record(
				DebugEvents::LEVEL_ERROR,
				'queue',
				'A queued email was dropped because it could not be rebuilt: ' . $e->getMessage(),
				array( 'subject' => (string) ( $payload['subject'] ?? '' ) )
			);
		} finally {
			remove_filter( 'wpsb_should_log', $quiet_log, 99 );
			remove_filter( 'wpsb_should_alert', $quiet_alert, 99 );
			$this->logger->context = null;
		}

		$this->count_send();

		if ( $result->ok || $final ) {
			$this->delete( (int) $row->id );

			return $result->ok ? 'sent' : 'failed';
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Install::queue_table(),
			array(
				'attempts'     => $attempts,
				'last_error'   => $result->error,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS * $attempts ),
			),
			array( 'id' => (int) $row->id )
		);

		return 'retrying';
	}

	/**
	 * @return list<object>
	 */
	private function due( int $limit ): array {
		global $wpdb;

		$table = Install::queue_table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE site_id = %d AND available_at <= %s ORDER BY id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$table,
				get_current_blog_id(),
				current_time( 'mysql', true ),
				$limit
			)
		);
	}

	private function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( Install::queue_table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Schedules the next run: right away if messages are due and the rate limit allows,
	 * otherwise when the next message becomes due or the next rate window opens.
	 */
	private function schedule_next(): void {
		global $wpdb;

		$table = Install::queue_table();
		$next  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT MIN(available_at) FROM %i WHERE site_id = %d', $table, get_current_blog_id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( null === $next ) {
			return;
		}

		$when = max( time(), (int) strtotime( $next . ' UTC' ) );

		if ( $this->allowance() <= 0 ) {
			$when = max( $when, ( intdiv( time(), 60 ) + 1 ) * 60 );
		}

		$this->schedule( $when, true );
	}

	/**
	 * @param bool $exact Replace an earlier scheduled run (used when the rate limit says "not yet").
	 */
	private function schedule( int $when, bool $exact = false ): void {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( false !== $scheduled && ( $scheduled === $when || ( ! $exact && $scheduled <= $when ) ) ) {
			return;
		}

		if ( false !== $scheduled ) {
			wp_unschedule_event( $scheduled, self::HOOK );
		}

		wp_schedule_single_event( $when, self::HOOK );
	}

	/**
	 * How many messages may still be sent in the current one-minute window.
	 */
	private function allowance(): int {
		$limit = (int) $this->options->get( 'queue.rate_limit' );

		if ( $limit <= 0 ) {
			return self::BATCH;
		}

		$rate = get_option( self::RATE_OPTION );

		if ( ! is_array( $rate ) || (int) ( $rate['window'] ?? 0 ) !== intdiv( time(), 60 ) ) {
			return $limit;
		}

		return max( 0, $limit - (int) $rate['count'] );
	}

	private function count_send(): void {
		$window = intdiv( time(), 60 );
		$rate   = get_option( self::RATE_OPTION );
		$count  = is_array( $rate ) && (int) ( $rate['window'] ?? 0 ) === $window ? (int) $rate['count'] : 0;

		update_option(
			self::RATE_OPTION,
			array(
				'window' => $window,
				'count'  => $count + 1,
			),
			false
		);
	}

	/**
	 * Prevents overlapping runs. A lock older than LOCK_TTL is considered stale.
	 */
	private function lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION );

		if ( $locked_at < time() - self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, time(), false );

			return true;
		}

		return false;
	}
}
