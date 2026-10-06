<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Crypto;
use SmtpBuddy\Tests\TestCase;

final class CryptoTest extends TestCase {

	private Crypto $crypto;

	protected function setUp(): void {
		parent::setUp();
		$this->crypto = new Crypto( str_repeat( 'a', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
	}

	public function test_round_trip(): void {
		$encrypted = $this->crypto->encrypt( 'p@ss<word>%20' );

		$this->assertTrue( Crypto::is_encrypted( $encrypted ) );
		$this->assertStringNotContainsString( 'p@ss', $encrypted );
		$this->assertSame( 'p@ss<word>%20', $this->crypto->decrypt( $encrypted ) );
	}

	public function test_each_encryption_uses_a_new_nonce(): void {
		$this->assertNotSame( $this->crypto->encrypt( 'same' ), $this->crypto->encrypt( 'same' ) );
	}

	public function test_wrong_key_returns_empty_string(): void {
		$other = new Crypto( str_repeat( 'b', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );

		$this->assertSame( '', $other->decrypt( $this->crypto->encrypt( 'secret' ) ) );
	}

	public function test_tampered_value_returns_empty_string(): void {
		$encrypted = $this->crypto->encrypt( 'secret' );

		$this->assertSame( '', $this->crypto->decrypt( substr( $encrypted, 0, -4 ) . 'AAAA' ) );
	}

	public function test_plain_values_pass_through(): void {
		$this->assertSame( 'not-encrypted', $this->crypto->decrypt( 'not-encrypted' ) );
		$this->assertSame( '', $this->crypto->encrypt( '' ) );
	}
}
