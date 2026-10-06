<?php
/**
 * Whether the current admin request manages the network or a single site.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Options;

/**
 * Network admin pages are detected by WordPress, but admin-post.php requests (form saves,
 * test emails, OAuth) never count as network admin. Forms on network pages therefore send
 * wpsb_network=1, which is honoured only for users who can manage network options.
 */
final class Context {

	private static bool $network = false;

	public static function init( Options $options ): void {
		$flagged = ! empty( $_REQUEST['wpsb_network'] ) && current_user_can( 'manage_network_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which settings the (nonce-checked) handler acts on.

		self::set( is_multisite() && ( is_network_admin() || $flagged ), $options );
	}

	public static function set( bool $network, Options $options ): void {
		self::$network = $network;
		$options->force_network( $network );
	}

	public static function network(): bool {
		return self::$network;
	}

	/**
	 * Which site's log to show: null = the current site; in the network admin 0 = all sites,
	 * or the site picked in the filter.
	 */
	public static function log_site(): ?int {
		if ( ! self::$network ) {
			return null;
		}

		return isset( $_GET['site'] ) ? absint( $_GET['site'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- List filter.
	}

	/**
	 * Display name of a site in the network.
	 */
	public static function site_name( int $site_id ): string {
		$details = get_blog_details( $site_id );

		return $details ? $details->blogname . ' (' . untrailingslashit( $details->domain . $details->path ) . ')' : '#' . $site_id;
	}

	/**
	 * Hidden field for forms that post to admin-post.php.
	 */
	public static function fields(): void {
		if ( self::$network ) {
			echo '<input type="hidden" name="wpsb_network" value="1">';
		}
	}
}
