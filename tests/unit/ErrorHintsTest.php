<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Support\ErrorHints;
use SmtpBuddy\Tests\TestCase;

final class ErrorHintsTest extends TestCase {

	public function test_smtp_connection_failure(): void {
		$this->assertStringContainsString( 'block outgoing ports', (string) ErrorHints::explain( 'SMTP Error: Could not connect to SMTP host. Failed to connect to server', 'smtp' ) );
	}

	public function test_smtp_auth_failure(): void {
		$this->assertStringContainsString( 'app password', (string) ErrorHints::explain( 'SMTP Error: Could not authenticate.', 'smtp' ) );
	}

	public function test_hints_are_mailer_specific(): void {
		$this->assertNull( ErrorHints::explain( 'SMTP Error: Could not authenticate.', 'sendgrid' ) );
		$this->assertStringContainsString( 'Region', (string) ErrorHints::explain( 'Mailgun API error (HTTP 404): Domain not found', 'mailgun' ) );
	}

	public function test_generic_api_timeout(): void {
		$this->assertStringContainsString( 'timed out', (string) ErrorHints::explain( 'cURL error 28: Operation timed out after 15000 milliseconds', 'postmark' ) );
	}

	public function test_unknown_error(): void {
		$this->assertNull( ErrorHints::explain( 'Something odd', 'smtp' ) );
	}
}
