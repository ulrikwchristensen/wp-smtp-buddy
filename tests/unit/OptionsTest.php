<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Options;
use SmtpBuddy\Tests\TestCase;

final class OptionsTest extends TestCase {

	public function test_stored_value_then_default(): void {
		$options = $this->options( array( 'smtp.host' => 'mail.example.com' ) );

		$this->assertSame( 'mail.example.com', $options->get( 'smtp.host' ) );
		$this->assertSame( 'php', $options->get( 'mailer' ) );
		$this->assertSame( 'fallback', $options->get( 'unknown.key', 'fallback' ) );
	}

	public function test_encrypted_values_are_decrypted(): void {
		$options = $this->options( array() );
		$secret  = $options->encrypt( 'api-key' );
		$options = $this->options( array( 'sendgrid.api_key' => $secret ) );

		$this->assertSame( 'api-key', $options->get( 'sendgrid.api_key' ) );
	}

	public function test_constant_wins(): void {
		define( 'WPSB_POSTMARK_MESSAGE_STREAM', 'from-constant' );

		$options = $this->options( array( 'postmark.message_stream' => 'stored' ) );

		$this->assertTrue( $options->is_constant( 'postmark.message_stream' ) );
		$this->assertSame( 'from-constant', $options->get( 'postmark.message_stream' ) );
	}

	public function test_constant_name(): void {
		$this->assertSame( 'WPSB_SENDGRID_API_KEY', Options::constant_name( 'sendgrid.api_key' ) );
	}
}
