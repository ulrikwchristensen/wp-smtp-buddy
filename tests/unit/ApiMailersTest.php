<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Mail\Mailers\Mailgun;
use SmtpBuddy\Mail\Mailers\MailerSend;
use SmtpBuddy\Mail\Mailers\Postmark;
use SmtpBuddy\Mail\Mailers\SendGrid;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Tests\TestCase;

final class ApiMailersTest extends TestCase {

	private function message( array $overrides = array() ): Message {
		return new Message(
			...array_merge(
				array(
					'from'        => array( 'email' => 'from@example.com', 'name' => 'Site "Name"' ),
					'to'          => array( array( 'email' => 'a@example.com', 'name' => '' ), array( 'email' => 'b@example.com', 'name' => 'B' ) ),
					'cc'          => array( array( 'email' => 'A@example.com', 'name' => '' ) ),
					'bcc'         => array( array( 'email' => 'hidden@example.com', 'name' => '' ) ),
					'reply_to'    => array( array( 'email' => 'reply@example.com', 'name' => '' ) ),
					'subject'     => 'Hello',
					'html'        => '<p>Hi</p>',
					'text'        => 'Hi',
					'attachments' => array( array( 'filename' => 'logo.png', 'content' => 'PNG', 'type' => 'image/png', 'disposition' => 'inline', 'cid' => 'logo' ) ),
					'headers'     => array( array( 'name' => 'X-Custom', 'value' => '1' ), array( 'name' => 'Content-Type', 'value' => 'x' ) ),
					'mime'        => "To: a@example.com\r\nSubject: Hello\r\n\r\nHi",
				),
				$overrides
			)
		);
	}

	public function test_html_detection_survives_presend(): void {
		$this->assertTrue( Message::is_html( 'text/html', '' ) );
		$this->assertTrue( Message::is_html( 'multipart/alternative', 'Plain' ) );
		$this->assertTrue( Message::is_html( 'text/plain', 'Plain' ) );
		$this->assertFalse( Message::is_html( 'text/plain', '' ) );
	}

	public function test_sendgrid_payload(): void {
		$mailer  = new SendGrid( $this->options( array( 'sendgrid.api_key' => 'SG.x' ) ) );
		$request = $mailer->build_request( $this->message() );
		$payload = json_decode( $request['body'], true );

		$this->assertSame( 'https://api.sendgrid.com/v3/mail/send', $request['url'] );
		$this->assertSame( 'Bearer SG.x', $request['headers']['Authorization'] );
		// a@example.com appears in to and (as A@example.com) in cc: SendGrid needs it once.
		$this->assertArrayNotHasKey( 'cc', $payload['personalizations'][0] );
		$this->assertSame( 'text/plain', $payload['content'][0]['type'] );
		$this->assertSame( array( 'X-Custom' => '1' ), $payload['headers'] );
		$this->assertSame( 'logo', $payload['attachments'][0]['content_id'] );
		$this->assertSame( base64_encode( 'PNG' ), $payload['attachments'][0]['content'] );
	}

	public function test_sendgrid_eu_region_and_response(): void {
		$mailer = new SendGrid( $this->options( array( 'sendgrid.region' => 'eu' ) ) );

		$this->assertStringStartsWith( 'https://api.eu.sendgrid.com/', $mailer->build_request( $this->message() )['url'] );
		$this->assertSame( 'id-1', $mailer->parse_response( 202, '', array( 'x-message-id' => 'id-1' ) )->message_id );

		$failure = $mailer->parse_response( 401, '{"errors":[{"message":"Bad key"}]}', array() );
		$this->assertFalse( $failure->ok );
		$this->assertStringContainsString( 'HTTP 401', $failure->error );
		$this->assertStringContainsString( 'Bad key', $failure->error );
	}

	public function test_postmark_payload(): void {
		$mailer  = new Postmark( $this->options( array( 'postmark.server_token' => 'pm' ) ) );
		$payload = json_decode( $mailer->build_request( $this->message() )['body'], true );

		$this->assertSame( '"Site \"Name\"" <from@example.com>', $payload['From'] );
		$this->assertSame( 'a@example.com, "B" <b@example.com>', $payload['To'] );
		$this->assertSame( 'outbound', $payload['MessageStream'] );
		$this->assertSame( 'cid:logo', $payload['Attachments'][0]['ContentID'] );
		$this->assertTrue( $mailer->parse_response( 200, '{"ErrorCode":0,"MessageID":"m1"}', array() )->ok );
		$this->assertFalse( $mailer->parse_response( 422, '{"ErrorCode":300,"Message":"Invalid"}', array() )->ok );
	}

	public function test_mailersend_payload_text_only(): void {
		$mailer  = new MailerSend( $this->options( array( 'mailersend.api_key' => 'ms' ) ) );
		$payload = json_decode( $mailer->build_request( $this->message( array( 'html' => '', 'attachments' => array() ) ) )['body'], true );

		$this->assertSame( 'Hi', $payload['text'] );
		$this->assertArrayNotHasKey( 'html', $payload );
		$this->assertArrayNotHasKey( 'headers', $payload );
		$this->assertSame( array( 'email' => 'reply@example.com' ), $payload['reply_to'] );
	}

	public function test_mailgun_sends_raw_mime_to_all_recipients(): void {
		$mailer  = new Mailgun( $this->options( array( 'mailgun.api_key' => 'key', 'mailgun.domain' => 'mg.example.com', 'mailgun.region' => 'eu' ) ) );
		$request = $mailer->build_request( $this->message() );

		$this->assertSame( 'https://api.eu.mailgun.net/v3/mg.example.com/messages.mime', $request['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'api:key' ), $request['headers']['Authorization'] );
		$this->assertStringContainsString( "name=\"to\"\r\n\r\na@example.com,b@example.com,hidden@example.com\r\n", $request['body'] );
		$this->assertStringContainsString( "Subject: Hello", $request['body'] );
		$this->assertSame( 'abc', $mailer->parse_response( 200, '{"id":"<abc>"}', array() )->message_id );
	}
}
