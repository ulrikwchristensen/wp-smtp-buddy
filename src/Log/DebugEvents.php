<?php
/**
 * Short-lived log of send errors and warnings (no message bodies).
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

use SmtpBuddy\Install;

final class DebugEvents {

	public const LEVEL_ERROR   = 'error';
	public const LEVEL_WARNING = 'warning';

	/**
	 * @param array<string, mixed> $context
	 */
	public function record( string $level, string $mailer, string $message, array $context = array() ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Install::debug_events_table(),
			array(
				'site_id'    => get_current_blog_id(),
				'mailer'     => substr( $mailer, 0, 40 ),
				'level'      => $level,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Events, newest first. $site: null = current site, 0 = all sites, N = site N.
	 *
	 * @return list<object{id: string, site_id: string, mailer: string, level: string, message: string, context: ?string, created_at: string}>
	 */
	public function recent( int $limit = 20, int $offset = 0, ?int $site = null ): array {
		global $wpdb;

		$table = Install::debug_events_table();
		$where = EmailLog::site_sql( $site );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is prepared by EmailLog::site_sql().
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$table,
				$limit,
				$offset
			)
		);
	}

	public function count( ?int $site = null ): int {
		global $wpdb;

		$table = Install::debug_events_table();
		$where = EmailLog::site_sql( $site );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is prepared.
	}

	public function latest_error(): ?object {
		global $wpdb;

		$table = Install::debug_events_table();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE site_id = %d AND level = %s ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$table,
				get_current_blog_id(),
				self::LEVEL_ERROR
			)
		);
	}

	public function clear( ?int $site = null ): void {
		global $wpdb;

		$table = Install::debug_events_table();
		$where = EmailLog::site_sql( $site );

		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$where}", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is prepared.
	}

	/**
	 * The current site's events that mention an email address (for privacy export/erase).
	 *
	 * @return list<object>
	 */
	public function find_by_email( string $email ): array {
		global $wpdb;

		$table = Install::debug_events_table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE site_id = %d AND ( context LIKE %s OR message LIKE %s ) LIMIT 500', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$table,
				get_current_blog_id(),
				'%' . $wpdb->esc_like( $email ) . '%',
				'%' . $wpdb->esc_like( $email ) . '%'
			)
		);
	}

	/**
	 * @param list<int> $ids
	 */
	public function delete_ids( array $ids ): int {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		$table = Install::debug_events_table();

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $table, ...$ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d per ID. // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Integers only.
	}

	/**
	 * Deletes the current site's events older than the given number of days.
	 */
	public function purge( int $days ): void {
		global $wpdb;

		$table = Install::debug_events_table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM %i WHERE site_id = %d AND created_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$table,
				get_current_blog_id(),
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS )
			)
		);
	}
}
