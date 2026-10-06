<?php
/**
 * Database schema and activation.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy;

final class Install {

	public const DB_VERSION        = 3;
	public const DB_VERSION_OPTION = 'wpsb_db_version';
	public const CLEANUP_HOOK      = 'wpsb_daily_cleanup';

	public static function activate(): void {
		self::maybe_upgrade();

		// Open the setup wizard on the next admin page load (once).
		add_option( Admin\Wizard::REDIRECT_OPTION, 1 );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Creates or updates the tables when the stored schema version is behind.
	 * Tables use the network base prefix with a site_id column, so they are created once per network.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_site_option( self::DB_VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::debug_events_table();
		$emails          = self::emails_table();

		dbDelta(
			"CREATE TABLE {$emails} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site_id bigint(20) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'sent',
				mailer varchar(40) NOT NULL DEFAULT '',
				from_email varchar(255) NOT NULL DEFAULT '',
				to_email text NOT NULL,
				recipients text NOT NULL,
				subject text NOT NULL,
				content_type varchar(100) NOT NULL DEFAULT 'text/plain',
				headers longtext NULL,
				body longtext NULL,
				alt_body longtext NULL,
				attachments longtext NULL,
				error text NULL,
				message_id varchar(255) NOT NULL DEFAULT '',
				initiator varchar(255) NOT NULL DEFAULT '',
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				primary_mailer varchar(40) NOT NULL DEFAULT '',
				primary_error text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY site_created (site_id,created_at),
				KEY site_status (site_id,status)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site_id bigint(20) unsigned NOT NULL DEFAULT 1,
				mailer varchar(40) NOT NULL DEFAULT '',
				level varchar(20) NOT NULL DEFAULT 'error',
				message text NOT NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY site_created (site_id,created_at)
			) {$charset_collate};"
		);

		$queue = self::queue_table();

		dbDelta(
			"CREATE TABLE {$queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				site_id bigint(20) unsigned NOT NULL DEFAULT 1,
				payload longtext NOT NULL,
				attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				available_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY site_available (site_id,available_at)
			) {$charset_collate};"
		);

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function queue_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'wpsb_queue';
	}

	public static function emails_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'wpsb_emails';
	}

	public static function debug_events_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'wpsb_debug_events';
	}
}
