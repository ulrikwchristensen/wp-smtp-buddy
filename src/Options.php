<?php
/**
 * Settings storage and resolution.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy;

/**
 * Resolves settings in this order:
 *
 * 1. A constant in wp-config.php (e.g. `smtp.host` → WPSB_SMTP_HOST).
 * 2. On multisite: the site's own settings, when the network allows overrides
 *    (or has no settings of its own), otherwise the network settings.
 * 3. The site's settings on a single site.
 * 4. The built-in default.
 *
 * Settings are stored as one flat array keyed by dotted names. Encrypted values are
 * decrypted transparently on read.
 */
final class Options {

	public const OPTION      = 'wpsb_settings';
	public const MODE_OPTION = 'wpsb_site_mode';

	private const DEFAULTS = array(
		'mailer'                 => 'php',
		'from_email'             => '',
		'from_name'              => '',
		'force_from_email'       => false,
		'force_from_name'        => false,
		'return_path'            => true,
		'debug_events_days'      => 30,
		'log.enabled'            => true,
		'log.content'            => 'full',
		'log.attachments'        => false,
		'log.retention_days'     => 30,
		'backup.mailer'          => '',
		'alerts.on_backup'       => true,
		'alerts.throttle'        => 15,
		'queue.enabled'          => false,
		'queue.rate_limit'       => 0,
		'delete_on_uninstall'    => false,
		'network.allow_override' => true,
	);

	private ?array $stored = null;

	private bool $force_network = false;

	public function __construct( private Crypto $crypto ) {}

	public function get( string $key, mixed $fallback = null ): mixed {
		$constant = self::constant_name( $key );

		if ( defined( $constant ) ) {
			return constant( $constant );
		}

		$stored = $this->stored();

		if ( array_key_exists( $key, $stored ) ) {
			$value = $stored[ $key ];

			return is_string( $value ) && Crypto::is_encrypted( $value ) ? $this->crypto->decrypt( $value ) : $value;
		}

		return self::DEFAULTS[ $key ] ?? $fallback;
	}

	public function is_constant( string $key ): bool {
		return defined( self::constant_name( $key ) );
	}

	/**
	 * Whether a value exists for the key (stored or constant), without decrypting it.
	 */
	public function has( string $key ): bool {
		return $this->is_constant( $key ) || ( array_key_exists( $key, $this->stored() ) && '' !== $this->stored()[ $key ] );
	}

	/**
	 * Raw stored values for the current scope (secrets stay encrypted).
	 *
	 * @return array<string, mixed>
	 */
	public function stored(): array {
		if ( null === $this->stored ) {
			$this->stored = $this->read_scope();
		}

		return $this->stored;
	}

	/**
	 * Replaces the stored settings of the current scope: the network settings in a network
	 * context, otherwise the current site's settings.
	 *
	 * @param array<string, mixed> $values Already sanitized; secrets already encrypted.
	 */
	public function save( array $values ): void {
		if ( $this->in_network_context() ) {
			update_site_option( self::OPTION, $values );
		} else {
			update_option( self::OPTION, $values, false );
		}

		$this->stored = null;
	}

	public function encrypt( string $plain ): string {
		return $this->crypto->encrypt( $plain );
	}

	public function decrypt( string $value ): string {
		return $this->crypto->decrypt( $value );
	}

	/**
	 * Treat this request as a network admin request. Used for admin-post.php requests sent from
	 * the network admin (WordPress doesn't consider those network admin requests itself).
	 */
	public function force_network( bool $network ): void {
		$this->force_network = $network;
		$this->stored        = null;
	}

	/**
	 * Where the current site's settings come from: 'site' or 'network'.
	 *
	 * In a network context it is always 'network'. On a site it is 'site' when the network
	 * has no settings (each site configures itself), or when the network allows overrides
	 * and the site chose its own settings. Otherwise the site uses the network settings.
	 */
	public function scope(): string {
		if ( ! is_multisite() ) {
			return 'site';
		}

		if ( $this->in_network_context() ) {
			return 'network';
		}

		if ( ! $this->has_network_settings() ) {
			return 'site';
		}

		return $this->allows_override() && 'custom' === $this->site_mode() ? 'site' : 'network';
	}

	public function has_network_settings(): bool {
		return is_multisite() && ! empty( get_site_option( self::OPTION, array() ) );
	}

	/**
	 * Whether the network lets sites use their own settings.
	 */
	public function allows_override(): bool {
		$network = get_site_option( self::OPTION, array() );

		return (bool) ( is_array( $network ) ? ( $network['network.allow_override'] ?? self::DEFAULTS['network.allow_override'] ) : self::DEFAULTS['network.allow_override'] );
	}

	/**
	 * 'custom' when the site uses its own settings, 'network' when it follows the network.
	 * Sites that saved their own settings before the network had any keep them.
	 */
	public function site_mode(): string {
		$mode = get_option( self::MODE_OPTION );

		if ( in_array( $mode, array( 'custom', 'network' ), true ) ) {
			return $mode;
		}

		return empty( get_option( self::OPTION, array() ) ) ? 'network' : 'custom';
	}

	public function set_site_mode( string $mode ): void {
		update_option( self::MODE_OPTION, 'custom' === $mode ? 'custom' : 'network', false );
		$this->stored = null;
	}

	/**
	 * Raw network settings (secrets stay encrypted).
	 *
	 * @return array<string, mixed>
	 */
	public function network_settings(): array {
		$value = get_site_option( self::OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	public function flush(): void {
		$this->stored = null;
	}

	/**
	 * A network admin page, or a request flagged as network context — but never while
	 * switched to another site, where that site's own effective settings apply.
	 */
	private function in_network_context(): bool {
		return is_multisite() && ! ms_is_switched() && ( $this->force_network || is_network_admin() );
	}

	public static function constant_name( string $key ): string {
		return 'WPSB_' . strtoupper( str_replace( array( '.', '-' ), '_', $key ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_scope(): array {
		$value = 'network' === $this->scope()
			? get_site_option( self::OPTION, array() )
			: get_option( self::OPTION, array() );

		return is_array( $value ) ? $value : array();
	}
}
