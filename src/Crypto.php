<?php
/**
 * Encryption of secrets (passwords, API keys) at rest.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy;

/**
 * Encrypts values with libsodium secretbox.
 *
 * The key is derived from the WPSB_ENCRYPTION_KEY constant when defined. Otherwise it is
 * derived from a random key stored in the database combined with AUTH_KEY, so a database
 * dump alone is not enough to decrypt the secrets.
 */
final class Crypto {

	private const PREFIX     = 'wpsb1:';
	private const KEY_OPTION = 'wpsb_crypto_key';

	private ?string $key;

	/**
	 * @param string|null $key 32-byte key. Null resolves the key lazily from config.
	 */
	public function __construct( ?string $key = null ) {
		$this->key = $key;
	}

	public function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Returns the plain text, or an empty string when the value can't be decrypted
	 * (wrong key, tampered value).
	 */
	public function decrypt( string $value ): string {
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key()
		);

		return false === $plain ? '' : $plain;
	}

	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	private function key(): string {
		if ( null !== $this->key ) {
			return $this->key;
		}

		if ( defined( 'WPSB_ENCRYPTION_KEY' ) && '' !== (string) WPSB_ENCRYPTION_KEY ) {
			$material = (string) WPSB_ENCRYPTION_KEY;
		} else {
			// Network-wide so secrets saved in network settings decrypt on every site.
			$stored = get_site_option( self::KEY_OPTION );

			if ( ! is_string( $stored ) || '' === $stored ) {
				$stored = bin2hex( random_bytes( 32 ) );
				update_site_option( self::KEY_OPTION, $stored );
			}

			$material = $stored . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' );
		}

		$this->key = sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		return $this->key;
	}
}
