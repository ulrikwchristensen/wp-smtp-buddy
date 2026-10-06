<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Support\SmtpTranscript;
use SmtpBuddy\Tests\TestCase;

final class SmtpTranscriptTest extends TestCase {

	public function test_auth_login_credentials_are_redacted(): void {
		$transcript = new SmtpTranscript();

		foreach ( array(
			'CLIENT -> SERVER: EHLO example.com',
			'CLIENT -> SERVER: AUTH LOGIN',
			'SERVER -> CLIENT: 334 VXNlcm5hbWU6',
			'CLIENT -> SERVER: dXNlckBleGFtcGxlLmNvbQ==',
			'SERVER -> CLIENT: 334 UGFzc3dvcmQ6',
			'CLIENT -> SERVER: c2VjcmV0',
			'SERVER -> CLIENT: 235 2.7.0 Authentication successful',
			'CLIENT -> SERVER: MAIL FROM:<user@example.com>',
		) as $line ) {
			$transcript( $line );
		}

		$text = $transcript->text();

		$this->assertStringNotContainsString( 'dXNlckBleGFtcGxlLmNvbQ==', $text );
		$this->assertStringNotContainsString( 'c2VjcmV0', $text );
		$this->assertStringContainsString( 'CLIENT -> SERVER: AUTH LOGIN [redacted]', $text );
		$this->assertStringContainsString( 'MAIL FROM:<user@example.com>', $text );
	}

	public function test_auth_plain_inline_credentials_are_redacted(): void {
		$transcript = new SmtpTranscript();
		$transcript( '2026-01-01 CLIENT -> SERVER: AUTH PLAIN AHVzZXIAc2VjcmV0' );
		$transcript( 'SERVER -> CLIENT: 535 5.7.8 Authentication failed' );
		$transcript( 'CLIENT -> SERVER: QUIT' );

		$this->assertStringNotContainsString( 'AHVzZXIAc2VjcmV0', $transcript->text() );
		$this->assertStringContainsString( 'CLIENT -> SERVER: QUIT', $transcript->text() );
	}
}
