<?php
/**
 * Storage for the email log.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

use SmtpBuddy\Install;

/**
 * Methods that take ?int $site read or delete for: null = the current site, 0 = every site
 * in the network (network admin), or a specific site ID.
 */
final class EmailLog {

	public const STATUS_SENT   = 'sent';
	public const STATUS_FAILED = 'failed';

	public function __construct( private AttachmentStore $attachments ) {}

	/**
	 * Whether the table exists in the current schema (it is created on the next admin request
	 * after an update, so logging waits until then).
	 */
	public function is_ready(): bool {
		return (int) get_site_option( Install::DB_VERSION_OPTION, 0 ) >= 2;
	}

	/**
	 * @param array<string, mixed> $row Column values (site_id and created_at are filled in).
	 * @return int The new entry ID, or 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'site_id'    => get_current_blog_id(),
				'created_at' => current_time( 'mysql', true ),
			),
			$row
		);

		return false === $wpdb->insert( Install::emails_table(), $row ) ? 0 : (int) $wpdb->insert_id; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	public function update( int $id, array $row ): void {
		global $wpdb;

		$wpdb->update( Install::emails_table(), $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function get( int $id, ?int $site = null ): ?Entry {
		global $wpdb;

		$table = Install::emails_table();
		$where = self::site_sql( $site );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is prepared by site_sql().
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND {$where}", $table, $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			ARRAY_A
		);

		return $row ? new Entry( $row ) : null;
	}

	/**
	 * @param array{status?: string, search?: string, per_page?: int, page?: int, site?: int|null} $args
	 * @return array{items: list<Entry>, total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table    = Install::emails_table();
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where    = array( self::site_sql( $args['site'] ?? null ) );

		if ( ! empty( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(subject LIKE %s OR recipients LIKE %s)', $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is built from prepared fragments.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", $table ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, site_id, status, mailer, from_email, to_email, subject, error, initiator, parent_id, primary_mailer, created_at FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				$table,
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => array_map( static fn ( array $row ): Entry => new Entry( $row ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * @return array<string, int> Count per status.
	 */
	public function counts( ?int $site = null ): array {
		global $wpdb;

		$table = Install::emails_table();
		$where = self::site_sql( $site );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS total FROM %i WHERE {$where} GROUP BY status", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is prepared.

		return array_map( 'intval', array_column( $rows, 'total', 'status' ) );
	}

	/**
	 * The most recent entry for the current site, or null.
	 */
	public function latest(): ?Entry {
		$result = $this->query( array( 'per_page' => 1 ) );

		return $result['items'][0] ?? null;
	}

	/**
	 * @param list<int> $ids
	 * @return int Number of deleted entries.
	 */
	public function delete( array $ids, ?int $site = null ): int {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		$table        = Install::emails_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$where        = self::site_sql( $site ) . ' AND ' . $wpdb->prepare( "id IN ({$placeholders})", ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per ID.

		return $this->delete_where( $table, $where );
	}

	/**
	 * Entries where the address is a recipient or the sender (for privacy export/erase).
	 *
	 * @return list<Entry>
	 */
	public function find_by_email( string $email, int $limit, int $offset ): array {
		global $wpdb;

		$table = Install::emails_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE site_id = %d AND (recipients LIKE %s OR from_email = %s) ORDER BY id ASC LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$table,
				get_current_blog_id(),
				'%,' . $wpdb->esc_like( strtolower( $email ) ) . ',%',
				strtolower( $email ),
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array_map( static fn ( array $row ): Entry => new Entry( $row ), $rows );
	}

	/**
	 * Deletes the current site's entries older than the given number of days. Each site
	 * purges its own entries, so sites can keep different retention periods.
	 */
	public function purge( int $days ): int {
		global $wpdb;

		return $this->delete_where(
			Install::emails_table(),
			self::site_sql( null ) . ' AND ' . $wpdb->prepare( 'created_at < %s', gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) )
		);
	}

	public function clear( ?int $site = null ): int {
		return $this->delete_where( Install::emails_table(), self::site_sql( $site ) );
	}

	/**
	 * Site IDs that have log entries (for the network site filter).
	 *
	 * @return list<int>
	 */
	public function site_ids(): array {
		global $wpdb;

		$table = Install::emails_table();

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT site_id FROM %i ORDER BY site_id', $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * SQL condition for a site filter: null = current site, 0 = all sites, N = site N.
	 */
	public static function site_sql( ?int $site ): string {
		global $wpdb;

		if ( 0 === $site ) {
			return '1=1';
		}

		return $wpdb->prepare( 'site_id = %d', $site ?? get_current_blog_id() );
	}

	/**
	 * Deletes matching rows and their stored attachment files.
	 *
	 * @param string $where Prepared SQL condition.
	 */
	private function delete_where( string $table, string $where ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is prepared by the caller.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE {$where} AND attachments LIKE %s", $table, '%"stored":true%' ) );

		foreach ( $ids as $id ) {
			$this->attachments->delete( (int) $id );
		}

		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$where}", $table ) );
		// phpcs:enable

		return (int) $deleted;
	}
}
