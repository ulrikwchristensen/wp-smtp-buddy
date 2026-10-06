<?php
/**
 * Removes plugin data when "Delete data on uninstall" is enabled.
 *
 * @package SmtpBuddy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Network settings win on multisite; otherwise use the main site's settings.
$wpsb_settings = ( is_multisite() ? get_site_option( 'wpsb_settings' ) : false ) ?: get_option( 'wpsb_settings', array() );
$wpsb_delete   = defined( 'WPSB_DELETE_ON_UNINSTALL' ) ? WPSB_DELETE_ON_UNINSTALL : ! empty( $wpsb_settings['delete_on_uninstall'] );

if ( ! $wpsb_delete ) {
	return;
}

global $wpdb;

$wpsb_site_ids = is_multisite() ? get_sites(
	array(
		'fields' => 'ids',
		'number' => 0,
	)
) : array( get_current_blog_id() );

foreach ( $wpsb_site_ids as $wpsb_site_id ) {
	if ( is_multisite() ) {
		switch_to_blog( $wpsb_site_id );
	}

	delete_option( 'wpsb_settings' );
	delete_option( 'wpsb_phpmailer_conflict' );
	delete_transient( 'wpsb_unconfigured_warned' );
	wp_clear_scheduled_hook( 'wpsb_daily_cleanup' );
	wp_clear_scheduled_hook( 'wpsb_process_queue' );

	foreach ( array( 'wpsb_oauth_gmail', 'wpsb_oauth_microsoft', 'wpsb_queue_lock', 'wpsb_queue_rate', 'wpsb_alert_suppressed_slack', 'wpsb_alert_suppressed_discord', 'wpsb_alert_suppressed_teams' ) as $wpsb_option ) {
		delete_option( $wpsb_option );
	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}

delete_site_option( 'wpsb_settings' );
delete_site_option( 'wpsb_crypto_key' );
delete_site_option( 'wpsb_oauth_gmail' );
delete_site_option( 'wpsb_oauth_microsoft' );
delete_site_option( 'wpsb_db_version' );

// Stored attachment copies.
$wpsb_attachment_dir = get_site_option( 'wpsb_attachment_dir' );

if ( is_string( $wpsb_attachment_dir ) && str_contains( $wpsb_attachment_dir, '/wp-smtp-buddy-' ) && is_dir( $wpsb_attachment_dir ) ) {
	$wpsb_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wpsb_attachment_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

	foreach ( $wpsb_files as $wpsb_file ) {
		$wpsb_file->isDir() ? rmdir( $wpsb_file->getPathname() ) : wp_delete_file( $wpsb_file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	rmdir( $wpsb_attachment_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

delete_site_option( 'wpsb_attachment_dir' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}wpsb_debug_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}wpsb_emails" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}wpsb_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
