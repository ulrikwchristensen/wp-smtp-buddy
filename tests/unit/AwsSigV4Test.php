<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Support\AwsSigV4;
use SmtpBuddy\Tests\TestCase;

/**
 * Uses the "get-vanilla" and "post-vanilla" cases from the AWS Signature Version 4 test suite.
 */
final class AwsSigV4Test extends TestCase {

	private AwsSigV4 $signer;

	protected function setUp(): void {
		parent::setUp();
		$this->signer = new AwsSigV4( 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service' );
	}

	public function test_get_vanilla(): void {
		$headers = $this->signer->sign( 'GET', 'https://example.amazonaws.com/', array(), '', gmmktime( 12, 36, 0, 8, 30, 2015 ) );

		$this->assertSame( '20150830T123600Z', $headers['X-Amz-Date'] );
		$this->assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
			$headers['Authorization']
		);
	}

	public function test_post_vanilla(): void {
		$headers = $this->signer->sign( 'POST', 'https://example.amazonaws.com/', array(), '', gmmktime( 12, 36, 0, 8, 30, 2015 ) );

		$this->assertStringEndsWith( 'Signature=5da7c1a2acd57cee7505fc6676e4e544621c30862966e37dddb68e92efbe5d6b', $headers['Authorization'] );
	}

	public function test_extra_headers_are_signed(): void {
		$headers = $this->signer->sign( 'POST', 'https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', array( 'Content-Type' => 'application/json' ), '{}' );

		$this->assertStringContainsString( 'SignedHeaders=content-type;host;x-amz-date,', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
	}
}
