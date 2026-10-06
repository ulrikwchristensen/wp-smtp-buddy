<?php
/**
 * Hooks the email log into WordPress's personal data export and erasure tools.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

final class Privacy {

	private const PER_PAGE = 50;

	public function __construct(
		private EmailLog $log,
		private DebugEvents $debug_events,
	) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['wp-smtp-buddy'] = array(
			'exporter_friendly_name' => __( 'WP SMTP Buddy email log', 'wp-smtp-buddy' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['wp-smtp-buddy'] = array(
			'eraser_friendly_name' => __( 'WP SMTP Buddy email log', 'wp-smtp-buddy' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$entries = $this->log->is_ready() ? $this->log->find_by_email( $email, self::PER_PAGE, ( max( 1, $page ) - 1 ) * self::PER_PAGE ) : array();
		$data    = array();

		foreach ( $entries as $entry ) {
			$fields = array(
				__( 'Date', 'wp-smtp-buddy' )    => $entry->created_at . ' UTC',
				__( 'Status', 'wp-smtp-buddy' )  => $entry->status,
				__( 'From', 'wp-smtp-buddy' )    => $entry->from_email,
				__( 'To', 'wp-smtp-buddy' )      => $entry->to_email,
				__( 'Subject', 'wp-smtp-buddy' ) => $entry->subject,
			);

			if ( $entry->has_content() ) {
				$fields[ __( 'Message', 'wp-smtp-buddy' ) ] = $entry->is_html() ? wp_strip_all_tags( (string) $entry->body ) : (string) $entry->body;
			}

			$data[] = array(
				'group_id'    => 'wpsb-email-log',
				'group_label' => __( 'Emails sent by the site', 'wp-smtp-buddy' ),
				'item_id'     => 'wpsb-email-' . $entry->id,
				'data'        => array_map(
					static fn ( string $name, string $value ): array => array(
						'name'  => $name,
						'value' => $value,
					),
					array_keys( $fields ),
					array_values( $fields )
				),
			);
		}

		$done = count( $entries ) < self::PER_PAGE;

		// Debug events (send errors) can mention the address too; add them with the last page.
		if ( $done ) {
			foreach ( $this->debug_events->find_by_email( $email ) as $event ) {
				$data[] = array(
					'group_id'    => 'wpsb-debug-events',
					'group_label' => __( 'Email sending errors', 'wp-smtp-buddy' ),
					'item_id'     => 'wpsb-debug-' . $event->id,
					'data'        => array(
						array(
							'name'  => __( 'Date', 'wp-smtp-buddy' ),
							'value' => $event->created_at . ' UTC',
						),
						array(
							'name'  => __( 'Error', 'wp-smtp-buddy' ),
							'value' => $event->message,
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the eraser callback signature.
		// Always the first batch: the previous batch was deleted.
		$entries  = $this->log->is_ready() ? $this->log->find_by_email( $email, self::PER_PAGE, 0 ) : array();
		$removed  = $this->log->delete( array_map( static fn ( Entry $entry ): int => $entry->id, $entries ) );
		$removed += $this->debug_events->delete_ids( array_map( static fn ( object $event ): int => (int) $event->id, $this->debug_events->find_by_email( $email ) ) );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $entries ) < self::PER_PAGE,
		);
	}

	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'WP SMTP Buddy', 'wp-smtp-buddy' ),
			wp_kses_post(
				'<p>' . __( 'When this site sends an email (for example an order confirmation, a password reset or a contact form notification), a copy is kept in an email log: the recipient addresses, subject, delivery status and, depending on the settings, the message content. Log entries are deleted automatically after the retention period set by the site owner. You can ask for an export or deletion of the emails sent to you.', 'wp-smtp-buddy' ) . '</p>'
			)
		);
	}
}
