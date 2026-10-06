<?php
/**
 * Works out which plugin, theme or core file called wp_mail().
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

final class Initiator {

	/**
	 * Returns "plugin:<folder>", "mu-plugin:<file>", "theme:<folder>", "core" or "" (unknown).
	 */
	public static function detect(): string {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( 'wp_mail' === $frame['function'] && ! isset( $frame['class'] ) && isset( $frame['file'] ) ) {
				return self::from_file( $frame['file'] );
			}
		}

		return '';
	}

	public static function from_file( string $file ): string {
		$file  = wp_normalize_path( $file );
		$roots = array(
			'plugin'    => WP_PLUGIN_DIR,
			'mu-plugin' => WPMU_PLUGIN_DIR,
			'theme'     => get_theme_root(),
		);

		foreach ( $roots as $type => $root ) {
			$root = trailingslashit( wp_normalize_path( $root ) );

			if ( str_starts_with( $file, $root ) ) {
				return $type . ':' . strtok( substr( $file, strlen( $root ) ), '/' );
			}
		}

		return str_starts_with( $file, wp_normalize_path( ABSPATH ) ) ? 'core' : '';
	}

	/**
	 * Human-readable name for a stored initiator.
	 */
	public static function label( string $initiator ): string {
		if ( '' === $initiator ) {
			return __( 'Unknown', 'wp-smtp-buddy' );
		}

		if ( 'core' === $initiator ) {
			return __( 'WordPress core', 'wp-smtp-buddy' );
		}

		[ $type, $slug ] = array_pad( explode( ':', $initiator, 2 ), 2, '' );

		if ( 'plugin' === $type ) {
			return self::plugin_name( $slug ) ?? $slug;
		}

		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $slug );

			return $theme->exists() ? (string) $theme->get( 'Name' ) : $slug;
		}

		return $slug;
	}

	private static function plugin_name( string $folder ): ?string {
		static $names = null;

		if ( null === $names ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$names = array();

			foreach ( get_plugins() as $basename => $data ) {
				$names[ strtok( $basename, '/' ) ] = $data['Name'];
			}
		}

		return $names[ $folder ] ?? null;
	}
}
