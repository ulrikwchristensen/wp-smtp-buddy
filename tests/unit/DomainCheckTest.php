<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Support\DomainCheck;
use SmtpBuddy\Tests\TestCase;

final class DomainCheckTest extends TestCase {

	/**
	 * @param array<string, list<string>> $txt   TXT values by host.
	 * @param array<string, string>       $cname CNAME targets by host.
	 */
	private function checker( array $txt, array $cname = array() ): DomainCheck {
		return new DomainCheck(
			static function ( string $host, int $type ) use ( $txt, $cname ): array {
				if ( DNS_TXT === $type ) {
					return array_map( static fn ( string $value ): array => array( 'entries' => array( $value ) ), $txt[ $host ] ?? array() );
				}

				return isset( $cname[ $host ] ) ? array( array( 'target' => $cname[ $host ] ) ) : array();
			}
		);
	}

	public function test_fully_configured_domain(): void {
		$result = $this->checker(
			array(
				'example.com'        => array( 'v=spf1 include:_spf.google.com ~all', 'google-site-verification=x' ),
				'_dmarc.example.com' => array( 'v=DMARC1; p=quarantine; rua=mailto:d@example.com' ),
			),
			array( 's1._domainkey.example.com' => 's1.domainkey.u123.wl.sendgrid.net' )
		)->check( 'Example.com', 'sendgrid' );

		$this->assertSame( DomainCheck::PASS, $result['spf']['status'] );
		$this->assertSame( DomainCheck::PASS, $result['dkim']['status'] );
		$this->assertStringContainsString( 'sendgrid.net', $result['dkim']['record'] );
		$this->assertSame( DomainCheck::PASS, $result['dmarc']['status'] );
		$this->assertStringContainsString( 'quarantine', $result['dmarc']['message'] );
		$this->assertSame( DomainCheck::PASS, DomainCheck::worst( $result ) );
	}

	public function test_missing_records(): void {
		$result = $this->checker( array() )->check( 'example.com', 'smtp' );

		$this->assertSame( DomainCheck::WARN, $result['spf']['status'] );
		$this->assertSame( DomainCheck::WARN, $result['dkim']['status'] );
		$this->assertSame( DomainCheck::WARN, $result['dmarc']['status'] );
		$this->assertSame( DomainCheck::WARN, DomainCheck::worst( $result ) );
	}

	public function test_multiple_spf_records_fail(): void {
		$result = $this->checker( array( 'example.com' => array( 'v=spf1 a ~all', 'v=spf1 mx ~all' ) ) )->check( 'example.com', 'smtp' );

		$this->assertSame( DomainCheck::FAIL, $result['spf']['status'] );
		$this->assertSame( DomainCheck::FAIL, DomainCheck::worst( $result ) );
	}

	public function test_dkim_txt_record_and_mailgun_spf_include(): void {
		$result = $this->checker(
			array(
				'example.com'                 => array( 'v=spf1 include:_spf.google.com ~all' ),
				'mailo._domainkey.example.com' => array( 'k=rsa; p=MIGfMA0' ),
			)
		)->check( 'example.com', 'mailgun' );

		$this->assertSame( DomainCheck::INFO, $result['spf']['status'] );
		$this->assertStringContainsString( 'include:mailgun.org', $result['spf']['message'] );
		$this->assertSame( DomainCheck::PASS, $result['dkim']['status'] );
	}

	public function test_postmark_dkim_is_informational(): void {
		$this->assertSame( DomainCheck::INFO, $this->checker( array() )->check( 'example.com', 'postmark' )['dkim']['status'] );
	}

	public function test_local_domains_are_not_looked_up(): void {
		$checker = new DomainCheck(
			function (): array {
				$this->fail( 'DNS should not be queried for local domains.' );
			}
		);

		foreach ( array( 'genpress.local', 'site.test', 'localhost' ) as $domain ) {
			$result = $checker->check( $domain, 'smtp' );

			$this->assertSame( DomainCheck::INFO, $result['spf']['status'] );
			$this->assertSame( DomainCheck::INFO, DomainCheck::worst( $result ) );
		}
	}
}
