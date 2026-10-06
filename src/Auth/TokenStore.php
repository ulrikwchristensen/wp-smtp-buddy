<?php
/**
 * Encrypted storage for OAuth tokens.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Auth;

use SmtpBuddy\Options;

/**
 * One record per mailer, stored in its own option (not autoloaded) wherever the settings
 * come from: a site option for network settings, otherwise a regular option. Reads and
 * writes use the same scope, so a token refreshed during a cron run lands in the right place.
 *
 * Record: access_token, refresh_token, expires_at, account, client_id, connected_at, error.
 */
final class TokenStore {

	public function __construct( private Options $options ) {}

	/**
	 * @return array<string, mixed> Empty array when not connected.
	 */
	public function get( string $mailer ): array {
		$raw = 'network' === $this->options->scope() ? get_site_option( self::key( $mailer ) ) : get_option( self::key( $mailer ) );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$data = json_decode( $this->options->decrypt( $raw ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public function save( string $mailer, array $record ): void {
		$value = $this->options->encrypt( (string) wp_json_encode( $record ) );

		if ( 'network' === $this->options->scope() ) {
			update_site_option( self::key( $mailer ), $value );
		} else {
			update_option( self::key( $mailer ), $value, false );
		}
	}

	public function delete( string $mailer ): void {
		if ( 'network' === $this->options->scope() ) {
			delete_site_option( self::key( $mailer ) );
		} else {
			delete_option( self::key( $mailer ) );
		}
	}

	public static function key( string $mailer ): string {
		return 'wpsb_oauth_' . $mailer;
	}
}
