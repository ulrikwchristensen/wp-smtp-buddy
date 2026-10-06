<?php
/**
 * Email Log tab: list, single entry view, and resend/delete actions.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Log\EmailLog;
use SmtpBuddy\Log\Entry;
use SmtpBuddy\Log\Initiator;
use SmtpBuddy\Log\Resender;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Options;
use SmtpBuddy\Support\ErrorHints;

final class LogScreen {

	private const NONCE = 'bulk-emails';

	/**
	 * Resending sends real email, so one request handles at most this many.
	 */
	private const MAX_RESEND = 50;

	public function __construct(
		private Options $options,
		private Registry $registry,
		private EmailLog $log,
		private Resender $resender,
	) {}

	public static function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			Page::url(
				'log',
				array(
					'action' => $action,
					'email'  => array( $id ),
				)
			),
			self::NONCE
		);
	}

	/**
	 * Runs on load-{page}, before output, so actions can redirect.
	 */
	public function handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked below before acting.
		if ( 'log' !== sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );

		if ( '' === $action || '-1' === $action ) {
			$action = sanitize_key( wp_unslash( $_GET['action2'] ?? '' ) );
		}

		if ( ! in_array( $action, array( 'resend', 'delete' ), true ) ) {
			return;
		}

		$ids = array_map( 'absint', (array) ( $_GET['email'] ?? array() ) );
		// phpcs:enable

		if ( ! current_user_can( Page::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP SMTP Buddy.', 'wp-smtp-buddy' ), 403 );
		}

		check_admin_referer( self::NONCE );

		if ( 'delete' === $action ) {
			$deleted = $this->log->delete( $ids, Context::network() ? 0 : null );

			wp_safe_redirect(
				Page::url(
					'log',
					array(
						'wpsb_notice' => 'deleted',
						'n'           => $deleted,
					)
				)
			);
			exit;
		}

		$sent   = 0;
		$failed = 0;

		foreach ( array_slice( $ids, 0, self::MAX_RESEND ) as $id ) {
			$this->resend_in_site( $id ) ? ++$sent : ++$failed;
		}

		wp_safe_redirect(
			Page::url(
				'log',
				array(
					'wpsb_notice' => 'resent',
					'n'           => $sent,
					'failed'      => $failed,
				)
			)
		);
		exit;
	}

	/**
	 * Resends an entry with the settings of the site it was sent from (in the network admin
	 * that means switching to that site first).
	 */
	private function resend_in_site( int $id ): bool {
		if ( ! Context::network() ) {
			return $this->resender->resend( $id )['ok'];
		}

		$entry = $this->log->get( $id, 0 );

		if ( null === $entry ) {
			return false;
		}

		switch_to_blog( $entry->site_id );

		try {
			return $this->resender->resend( $id )['ok'];
		} finally {
			restore_current_blog();
		}
	}

	public function render(): void {
		$id = isset( $_GET['email'] ) && ! is_array( $_GET['email'] ) ? absint( $_GET['email'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id ) {
			$entry = $this->log->get( $id, Context::network() ? 0 : null );

			if ( null !== $entry ) {
				$this->render_entry( $entry );

				return;
			}

			echo '<div class="notice notice-error"><p>' . esc_html__( 'Email not found. It may have been deleted.', 'wp-smtp-buddy' ) . '</p></div>';
		}

		if ( ! $this->log->is_ready() ) {
			echo '<p>' . esc_html__( 'The email log is being set up. Reload this page.', 'wp-smtp-buddy' ) . '</p>';

			return;
		}

		if ( ! $this->options->get( 'log.enabled' ) ) {
			printf(
				'<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'The email log is turned off, so new emails are not recorded.', 'wp-smtp-buddy' ),
				esc_url( Page::url() ),
				esc_html__( 'Turn it on in Settings.', 'wp-smtp-buddy' )
			);
		}

		$table = new EmailLogTable( $this->log, $this->registry );
		$table->prepare_items();
		$table->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="log">', esc_attr( Page::SLUG ) );

		if ( Context::network() ) {
			echo '<input type="hidden" name="wpsb_network" value="1">';
		}

		if ( ! empty( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<input type="hidden" name="status" value="%s">', esc_attr( sanitize_key( $_GET['status'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$table->search_box( __( 'Search emails', 'wp-smtp-buddy' ), 'wpsb-email' );
		$table->display();
		echo '</form>';

		printf(
			'<form method="post" action="%s" class="wpsb-clear-log" onsubmit="return confirm(\'%s\');">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_js( __( 'Delete every email in the log? This cannot be undone.', 'wp-smtp-buddy' ) )
		);
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::CLEAR_LOG ) . '">';
		wp_nonce_field( Actions::CLEAR_LOG );
		Context::fields();
		submit_button( __( 'Delete All Emails', 'wp-smtp-buddy' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function render_entry( Entry $entry ): void {
		$mailer = $this->registry->get( $entry->mailer );

		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( Page::url( 'log' ) ), esc_html__( 'Back to the email log', 'wp-smtp-buddy' ) );
		echo '<h2>' . esc_html( '' !== $entry->subject ? $entry->subject : __( '(no subject)', 'wp-smtp-buddy' ) ) . '</h2>';

		if ( EmailLog::STATUS_FAILED === $entry->status ) {
			$hint = ErrorHints::explain( $entry->error, $entry->mailer );

			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'This email failed to send.', 'wp-smtp-buddy' ) . '</strong></p>';
			echo '<p><code>' . esc_html( $entry->error ) . '</code></p>';

			if ( null !== $hint ) {
				echo '<p class="wpsb-hint"><strong>' . esc_html__( 'How to fix it:', 'wp-smtp-buddy' ) . '</strong> ' . esc_html( $hint ) . '</p>';
			}

			echo '</div>';
		}

		if ( '' !== $entry->primary_mailer ) {
			$primary = $this->registry->get( $entry->primary_mailer );

			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html(
				sprintf(
					/* translators: 1: primary mailer, 2: backup mailer */
					__( 'Sent with the backup mailer (%2$s) because %1$s failed:', 'wp-smtp-buddy' ),
					$primary ? $primary->label() : $entry->primary_mailer,
					$mailer ? $mailer->label() : $entry->mailer
				)
			) . '</strong></p><p><code>' . esc_html( $entry->primary_error ) . '</code></p></div>';
		}

		$from = Message::format_address(
			array(
				'email' => $entry->from_email,
				'name'  => (string) ( $entry->headers['from_name'] ?? '' ),
			)
		);

		$rows = array(
			__( 'Status', 'wp-smtp-buddy' ) => EmailLog::STATUS_SENT === $entry->status ? __( 'Sent', 'wp-smtp-buddy' ) : __( 'Failed', 'wp-smtp-buddy' ),
			__( 'Date', 'wp-smtp-buddy' )   => wp_date( 'Y-m-d H:i:s T', (int) strtotime( $entry->created_at . ' UTC' ) ),
			__( 'Mailer', 'wp-smtp-buddy' ) => $mailer ? $mailer->label() : $entry->mailer,
		) + ( Context::network() ? array( __( 'Site', 'wp-smtp-buddy' ) => Context::site_name( $entry->site_id ) ) : array() ) + array(
			__( 'Sent by', 'wp-smtp-buddy' ) => Initiator::label( $entry->initiator ),
			__( 'From', 'wp-smtp-buddy' )    => $from,
			__( 'To', 'wp-smtp-buddy' )      => $entry->to_email,
		);

		foreach ( array(
			'cc'       => __( 'Cc', 'wp-smtp-buddy' ),
			'bcc'      => __( 'Bcc', 'wp-smtp-buddy' ),
			'reply_to' => __( 'Reply-To', 'wp-smtp-buddy' ),
		) as $key => $label ) {
			$addresses = $entry->addresses( $key );

			if ( $addresses ) {
				$rows[ $label ] = implode( ', ', array_map( array( Message::class, 'format_address' ), $addresses ) );
			}
		}

		if ( '' !== $entry->message_id ) {
			$rows[ __( 'Provider message ID', 'wp-smtp-buddy' ) ] = $entry->message_id;
		}

		echo '<table class="widefat striped wpsb-entry"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}

		if ( $entry->parent_id ) {
			printf(
				'<tr><th scope="row">%s</th><td><a href="%s">#%d</a></td></tr>',
				esc_html__( 'Resend of', 'wp-smtp-buddy' ),
				esc_url( Page::url( 'log', array( 'email' => $entry->parent_id ) ) ),
				(int) $entry->parent_id
			);
		}

		if ( $entry->attachments ) {
			$items = array_map(
				static fn ( array $item ): string => esc_html( (string) $item['name'] ) . ' (' . esc_html( size_format( (int) ( $item['size'] ?? 0 ) ) ?: '0 B' ) . ')' . ( empty( $item['stored'] ) ? '' : ' ✓' ),
				$entry->attachments
			);

			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html__( 'Attachments', 'wp-smtp-buddy' ), implode( '<br>', $items ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		$custom = (array) ( $entry->headers['custom'] ?? array() );

		if ( $custom ) {
			$lines = array_map( static fn ( array $header ): string => esc_html( $header[0] . ': ' . $header[1] ), array_filter( $custom, 'is_array' ) );
			printf( '<tr><th scope="row">%s</th><td><code>%s</code></td></tr>', esc_html__( 'Other headers', 'wp-smtp-buddy' ), implode( '<br>', $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		echo '</tbody></table>';

		if ( $entry->has_content() ) {
			echo '<h3>' . esc_html__( 'Message', 'wp-smtp-buddy' ) . '</h3>';

			if ( $entry->is_html() ) {
				// Sandboxed with no scripts. Remote images (often tracking pixels) are blocked unless
				// the viewer asks for them, so opening an email doesn't reveal the admin's IP.
				$remote = ! empty( $_GET['images'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display toggle.
				$csp    = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:' . ( $remote ? ' https: http:' : '' ) . '; style-src \'unsafe-inline\'; font-src data:' . ( $remote ? ' https:' : '' ) . '">';

				if ( ! $remote ) {
					printf(
						'<p class="description">%s <a href="%s">%s</a></p>',
						esc_html__( 'Remote images are blocked.', 'wp-smtp-buddy' ),
						esc_url( add_query_arg( 'images', '1' ) ),
						esc_html__( 'Load remote images', 'wp-smtp-buddy' )
					);
				}

				printf( '<iframe class="wpsb-preview" sandbox="" referrerpolicy="no-referrer" title="%s" srcdoc="%s"></iframe>', esc_attr__( 'Message preview', 'wp-smtp-buddy' ), esc_attr( $csp . (string) $entry->body ) );
			} else {
				echo '<pre class="wpsb-text-body">' . esc_html( (string) $entry->body ) . '</pre>';
			}

			if ( '' !== $entry->alt_body ) {
				echo '<details><summary>' . esc_html__( 'Plain-text version', 'wp-smtp-buddy' ) . '</summary><pre class="wpsb-text-body">' . esc_html( $entry->alt_body ) . '</pre></details>';
			}

			echo '<p class="wpsb-entry-actions">';
			printf( '<a href="%s" class="button button-primary">%s</a> ', esc_url( self::action_url( 'resend', $entry->id ) ), esc_html__( 'Resend', 'wp-smtp-buddy' ) );
		} else {
			echo '<p class="description">' . esc_html__( 'The message content was not stored (the log is set to store recipients, subject and status only), so it can\'t be shown or resent.', 'wp-smtp-buddy' ) . '</p>';
			echo '<p class="wpsb-entry-actions">';
		}

		printf( '<a href="%s" class="button">%s</a>', esc_url( self::action_url( 'delete', $entry->id ) ), esc_html__( 'Delete', 'wp-smtp-buddy' ) );
		echo '</p>';
	}
}
