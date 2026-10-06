<?php
/**
 * Checks the From domain's SPF, DKIM and DMARC records.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

final class DomainCheck {

	public const PASS = 'pass';
	public const WARN = 'warn';
	public const FAIL = 'fail';
	public const INFO = 'info';

	/**
	 * SPF include each provider asks for (empty when the provider handles SPF on its own
	 * return-path subdomain, so the root record doesn't need it).
	 */
	private const SPF_INCLUDES = array(
		'sendgrid'   => '',
		'mailgun'    => 'mailgun.org',
		'postmark'   => '',
		'mailersend' => '',
		'ses'        => '',
		'brevo'      => '',
		'mailjet'    => 'spf.mailjet.com',
		'resend'     => '',
		'smtp2go'    => '',
		'gmail'      => '_spf.google.com',
		'microsoft'  => 'spf.protection.outlook.com',
	);

	/**
	 * DKIM selectors to try per mailer. Postmark uses dated selectors that can't be guessed.
	 */
	private const DKIM_SELECTORS = array(
		'sendgrid'   => array( 's1', 's2' ),
		'mailgun'    => array( 'smtp', 'mailo', 'k1', 'krs', 'pic', 'mx', 'email' ),
		'postmark'   => array(),
		'mailersend' => array( 'mlsend', 'mlsend2' ),
		'ses'        => array(),
		'brevo'      => array( 'brevo1', 'brevo2', 'mail' ),
		'mailjet'    => array( 'mailjet' ),
		'resend'     => array( 'resend' ),
		'smtp2go'    => array(),
		'gmail'      => array( 'google' ),
		'microsoft'  => array( 'selector1', 'selector2' ),
		'smtp'       => array( 'default', 'google', 'selector1', 'selector2', 'k1', 'dkim', 'mail', 's1', 'smtp' ),
		'php'        => array( 'default', 'google', 'selector1', 'selector2', 'k1', 'dkim', 'mail' ),
	);

	/**
	 * Reserved and commonly used development TLDs that never have public DNS.
	 */
	private const LOCAL_TLDS = array( 'local', 'localhost', 'test', 'example', 'invalid', 'lan', 'internal', 'home', 'localdomain' );

	/**
	 * @var callable(string, int): list<array<string, mixed>>
	 */
	private $resolver;

	/**
	 * @param callable|null $resolver fn( string $host, int $type ): list of records shaped like dns_get_record().
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver ?? static function ( string $host, int $type ): array {
			$records = @dns_get_record( $host, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Lookup failures are expected.

			return is_array( $records ) ? $records : array();
		};
	}

	/**
	 * @return array<string, array{status: string, message: string, record: string}> Keyed spf, dkim, dmarc.
	 */
	public function check( string $domain, string $mailer ): array {
		$domain = strtolower( trim( $domain ) );

		if ( ! str_contains( $domain, '.' ) || in_array( substr( (string) strrchr( $domain, '.' ), 1 ), self::LOCAL_TLDS, true ) ) {
			$local = $this->result( self::INFO, __( 'This is a local or development domain without public DNS, so it can\'t be checked. Use the domain you send from in production.', 'wp-smtp-buddy' ) );

			return array(
				'spf'   => $local,
				'dkim'  => $local,
				'dmarc' => $local,
			);
		}

		return array(
			'spf'   => $this->spf( $domain, $mailer ),
			'dkim'  => $this->dkim( $domain, $mailer ),
			'dmarc' => $this->dmarc( $domain ),
		);
	}

	/**
	 * Like check(), but cached for 12 hours.
	 *
	 * @return array<string, array{status: string, message: string, record: string}>
	 */
	public function check_cached( string $domain, string $mailer, bool $refresh = false ): array {
		$key    = 'wpsb_dns_' . md5( strtolower( $domain ) . '|' . $mailer );
		$cached = $refresh ? false : get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = $this->check( $domain, $mailer );
		set_transient( $key, $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * The worst status in a check result. INFO means nothing could be verified either way.
	 *
	 * @param array<string, array{status: string, message: string, record: string}> $result
	 */
	public static function worst( array $result ): string {
		$statuses = array_column( $result, 'status' );

		foreach ( array( self::FAIL, self::WARN ) as $status ) {
			if ( in_array( $status, $statuses, true ) ) {
				return $status;
			}
		}

		return in_array( self::PASS, $statuses, true ) ? self::PASS : self::INFO;
	}

	/**
	 * @return array{status: string, message: string, record: string}
	 */
	private function spf( string $domain, string $mailer ): array {
		$records = array_values( array_filter( $this->txt( $domain ), static fn ( string $txt ): bool => str_starts_with( strtolower( $txt ), 'v=spf1' ) ) );

		if ( ! $records ) {
			return $this->result( self::WARN, __( 'No SPF record found. Add a TXT record starting with "v=spf1" that lists the servers allowed to send for this domain.', 'wp-smtp-buddy' ) );
		}

		if ( count( $records ) > 1 ) {
			return $this->result( self::FAIL, __( 'There is more than one SPF record. Receivers treat this as an error. Merge them into a single TXT record.', 'wp-smtp-buddy' ), implode( "\n", $records ) );
		}

		$include = self::SPF_INCLUDES[ $mailer ] ?? '';

		if ( '' !== $include && ! str_contains( strtolower( $records[0] ), 'include:' . $include ) ) {
			return $this->result(
				self::INFO,
				/* translators: %s: SPF include mechanism */
				sprintf( __( 'An SPF record exists but doesn\'t include %s. If you send from this domain (not a subdomain), add it as your provider\'s setup instructions describe.', 'wp-smtp-buddy' ), 'include:' . $include ),
				$records[0]
			);
		}

		return $this->result( self::PASS, __( 'SPF record found.', 'wp-smtp-buddy' ), $records[0] );
	}

	/**
	 * @return array{status: string, message: string, record: string}
	 */
	private function dkim( string $domain, string $mailer ): array {
		$selectors = self::DKIM_SELECTORS[ $mailer ] ?? self::DKIM_SELECTORS['smtp'];

		if ( ! $selectors ) {
			return $this->result( self::INFO, __( 'This provider uses DKIM selectors that can\'t be checked automatically. Check that the domain shows as verified in the provider\'s dashboard.', 'wp-smtp-buddy' ) );
		}

		foreach ( $selectors as $selector ) {
			$host = $selector . '._domainkey.' . $domain;

			foreach ( $this->txt( $host ) as $txt ) {
				if ( str_contains( strtolower( $txt ), 'p=' ) ) {
					/* translators: %s: DKIM selector */
					return $this->result( self::PASS, sprintf( __( 'DKIM key found (selector "%s").', 'wp-smtp-buddy' ), $selector ), $host );
				}
			}

			foreach ( $this->lookup( $host, DNS_CNAME ) as $record ) {
				if ( ! empty( $record['target'] ) ) {
					/* translators: %s: DKIM selector */
					return $this->result( self::PASS, sprintf( __( 'DKIM record found (selector "%s").', 'wp-smtp-buddy' ), $selector ), $host . ' → ' . $record['target'] );
				}
			}
		}

		return $this->result( self::WARN, __( 'No DKIM record found at the usual selectors. Without DKIM, many receivers send email to spam. Follow your provider\'s domain authentication steps. If you already did, your provider may use a selector this check doesn\'t know.', 'wp-smtp-buddy' ) );
	}

	/**
	 * @return array{status: string, message: string, record: string}
	 */
	private function dmarc( string $domain ): array {
		foreach ( $this->txt( '_dmarc.' . $domain ) as $txt ) {
			if ( str_starts_with( strtolower( $txt ), 'v=dmarc1' ) ) {
				$policy = preg_match( '/\bp\s*=\s*(\w+)/i', $txt, $match ) ? strtolower( $match[1] ) : 'none';

				return $this->result(
					self::PASS,
					/* translators: %s: DMARC policy (none, quarantine or reject) */
					sprintf( __( 'DMARC record found (policy: %s).', 'wp-smtp-buddy' ), $policy ),
					$txt
				);
			}
		}

		return $this->result( self::WARN, __( 'No DMARC record found. Gmail and Yahoo expect one. Start with a TXT record at _dmarc with the value "v=DMARC1; p=none;".', 'wp-smtp-buddy' ) );
	}

	/**
	 * @return list<string>
	 */
	private function txt( string $host ): array {
		$values = array();

		foreach ( $this->lookup( $host, DNS_TXT ) as $record ) {
			if ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
				$values[] = implode( '', $record['entries'] );
			} elseif ( isset( $record['txt'] ) ) {
				$values[] = (string) $record['txt'];
			}
		}

		return $values;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function lookup( string $host, int $type ): array {
		return ( $this->resolver )( $host, $type );
	}

	/**
	 * @return array{status: string, message: string, record: string}
	 */
	private function result( string $status, string $message, string $record = '' ): array {
		return array(
			'status'  => $status,
			'message' => $message,
			'record'  => $record,
		);
	}
}
