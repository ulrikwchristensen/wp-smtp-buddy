<?php
/**
 * List table for the email log.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmtpBuddy\Log\EmailLog;
use SmtpBuddy\Log\Entry;
use SmtpBuddy\Log\Initiator;
use SmtpBuddy\Mail\Registry;

if ( ! class_exists( \WP_List_Table::class, false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.

final class EmailLogTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct(
		private EmailLog $log,
		private Registry $registry,
	) {
		parent::__construct(
			array(
				'singular' => 'email',
				'plural'   => 'emails',
				'ajax'     => false,
				'screen'   => 'toplevel_page_' . Page::SLUG,
			)
		);
	}

	public function prepare_items(): void {
		$result = $this->log->query(
			array(
				'status'   => $this->current_status(),
				'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'per_page' => self::PER_PAGE,
				'page'     => $this->get_pagenum(),
				'site'     => Context::log_site(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'subject' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox">',
			'subject' => __( 'Subject', 'wp-smtp-buddy' ),
		) + ( Context::network() ? array( 'site' => __( 'Site', 'wp-smtp-buddy' ) ) : array() ) + array(
			'to'        => __( 'To', 'wp-smtp-buddy' ),
			'status'    => __( 'Status', 'wp-smtp-buddy' ),
			'mailer'    => __( 'Mailer', 'wp-smtp-buddy' ),
			'initiator' => __( 'Sent by', 'wp-smtp-buddy' ),
			'date'      => __( 'Date', 'wp-smtp-buddy' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'resend' => __( 'Resend', 'wp-smtp-buddy' ),
			'delete' => __( 'Delete', 'wp-smtp-buddy' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$counts  = $this->log->counts( Context::log_site() );
		$site    = Context::log_site() ? array( 'site' => Context::log_site() ) : array();
		$current = $this->current_status();
		$views   = array(
			''                      => array( __( 'All', 'wp-smtp-buddy' ), array_sum( $counts ) ),
			EmailLog::STATUS_SENT   => array( __( 'Sent', 'wp-smtp-buddy' ), $counts[ EmailLog::STATUS_SENT ] ?? 0 ),
			EmailLog::STATUS_FAILED => array( __( 'Failed', 'wp-smtp-buddy' ), $counts[ EmailLog::STATUS_FAILED ] ?? 0 ),
		);

		$links = array();

		foreach ( $views as $status => [ $label, $count ] ) {
			$links[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( Page::url( 'log', $site + ( '' === $status ? array() : array( 'status' => $status ) ) ) ),
				$status === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $links;
	}

	/**
	 * Site filter in the network admin.
	 *
	 * @param string $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( ! Context::network() || 'top' !== $which ) {
			return;
		}

		echo '<div class="alignleft actions"><label class="screen-reader-text" for="wpsb-site-filter">' . esc_html__( 'Filter by site', 'wp-smtp-buddy' ) . '</label>';
		echo '<select name="site" id="wpsb-site-filter"><option value="0">' . esc_html__( 'All sites', 'wp-smtp-buddy' ) . '</option>';

		foreach ( $this->log->site_ids() as $site_id ) {
			printf( '<option value="%d" %s>%s</option>', (int) $site_id, selected( Context::log_site(), $site_id, false ), esc_html( Context::site_name( $site_id ) ) );
		}

		echo '</select>';
		submit_button( __( 'Filter', 'wp-smtp-buddy' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * @param Entry $item
	 */
	protected function column_site( $item ): string {
		return esc_html( Context::site_name( $item->site_id ) );
	}

	public function no_items(): void {
		esc_html_e( 'No emails logged yet.', 'wp-smtp-buddy' );
	}

	/**
	 * @param Entry $item
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="email[]" value="%d">', $item->id );
	}

	/**
	 * @param Entry $item
	 */
	protected function column_subject( $item ): string {
		$view    = Page::url( 'log', array( 'email' => $item->id ) );
		$actions = array(
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'wp-smtp-buddy' ) ),
			'resend' => sprintf( '<a href="%s">%s</a>', esc_url( LogScreen::action_url( 'resend', $item->id ) ), esc_html__( 'Resend', 'wp-smtp-buddy' ) ),
			'delete' => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( LogScreen::action_url( 'delete', $item->id ) ), esc_html__( 'Delete', 'wp-smtp-buddy' ) ),
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $view ),
			esc_html( '' !== $item->subject ? $item->subject : __( '(no subject)', 'wp-smtp-buddy' ) ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * @param Entry $item
	 */
	protected function column_status( $item ): string {
		$label = EmailLog::STATUS_SENT === $item->status ? __( 'Sent', 'wp-smtp-buddy' ) : __( 'Failed', 'wp-smtp-buddy' );
		$html  = sprintf( '<span class="wpsb-status wpsb-status-%s">%s</span>', esc_attr( $item->status ), esc_html( $label ) );

		if ( '' !== $item->primary_mailer ) {
			$html .= '<br><small>' . esc_html__( 'via backup mailer', 'wp-smtp-buddy' ) . '</small>';
		}

		if ( $item->parent_id ) {
			/* translators: %d: log entry ID */
			$html .= '<br><small>' . esc_html( sprintf( __( 'Resend of #%d', 'wp-smtp-buddy' ), $item->parent_id ) ) . '</small>';
		}

		return $html;
	}

	/**
	 * @param Entry $item
	 */
	protected function column_mailer( $item ): string {
		$mailer = $this->registry->get( $item->mailer );

		return esc_html( $mailer ? $mailer->label() : $item->mailer );
	}

	/**
	 * @param Entry $item
	 */
	protected function column_to( $item ): string {
		return esc_html( $item->to_email );
	}

	/**
	 * @param Entry $item
	 */
	protected function column_initiator( $item ): string {
		return esc_html( Initiator::label( $item->initiator ) );
	}

	/**
	 * @param Entry $item
	 */
	protected function column_date( $item ): string {
		$timestamp = (int) strtotime( $item->created_at . ' UTC' );

		return sprintf(
			'<time datetime="%s" title="%s">%s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( wp_date( 'Y-m-d H:i:s', $timestamp ) ),
			esc_html(
				$timestamp > time() - DAY_IN_SECONDS
					/* translators: %s: human-readable time difference */
					? sprintf( __( '%s ago', 'wp-smtp-buddy' ), human_time_diff( $timestamp ) )
					: wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp )
			)
		);
	}

	private function current_status(): string {
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

		return in_array( $status, array( EmailLog::STATUS_SENT, EmailLog::STATUS_FAILED ), true ) ? $status : '';
	}
}
