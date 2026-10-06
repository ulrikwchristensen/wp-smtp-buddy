<?php
/**
 * Copies of logged attachments, kept so emails can be resent with them.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Log;

/**
 * Files live in a randomly named folder in uploads (one subfolder per log entry). The folder
 * has deny rules for Apache and an index.php; the random name protects it on servers that
 * ignore .htaccess (such as nginx).
 */
final class AttachmentStore {

	private const DIR_OPTION = 'wpsb_attachment_dir';

	/**
	 * Saves an attachment and returns its absolute path, or null on failure.
	 */
	public function store( int $entry_id, int $index, string $content ): ?string {
		$dir = $this->entry_dir( $entry_id );

		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		$this->protect( dirname( $dir ) );

		// Neutral name: the original name (kept in the log) could end in .php, and .htaccess
		// rules don't apply on every server. Resending uses the logged name.
		$path = $dir . '/' . $index . '.bin';

		return false === file_put_contents( $path, $content ) ? null : $path; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public function delete( int $entry_id ): void {
		$base = get_site_option( self::DIR_OPTION );

		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}

		$dir = $base . '/' . $entry_id;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( (string) $file );
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Whether a path points into the attachment folder (guards against tampered log rows).
	 */
	public function owns( string $path ): bool {
		$base = get_site_option( self::DIR_OPTION );

		return is_string( $base ) && '' !== $base && str_starts_with( wp_normalize_path( $path ), wp_normalize_path( $base ) . '/' ) && ! str_contains( $path, '..' );
	}

	private function entry_dir( int $entry_id ): string {
		$base = get_site_option( self::DIR_OPTION );

		if ( ! is_string( $base ) || '' === $base ) {
			$uploads = wp_upload_dir( null, false );
			$base    = wp_normalize_path( $uploads['basedir'] ) . '/wp-smtp-buddy-' . strtolower( wp_generate_password( 20, false ) );
			update_site_option( self::DIR_OPTION, $base );
		}

		return $base . '/' . $entry_id;
	}

	private function protect( string $base ): void {
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! file_exists( $base . '/index.php' ) ) {
			file_put_contents( $base . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
