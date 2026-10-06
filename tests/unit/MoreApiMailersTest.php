<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Mail\Mailers\AmazonSes;
use SmtpBuddy\Mail\Mailers\Brevo;
use SmtpBuddy\Mail\Mailers\Mailjet;
use SmtpBuddy\Mail\Mailers\Resend;
use SmtpBuddy\Mail\Mailers\Smtp2go;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Tests\TestCase;

final class MoreApiMailersTest extends TestCase {

	private function message(): Message {
		return new Message(
			from: array( 'email' => 'from@example.com', 'name' => 'Shop' ),
			to: array( array( 'email' => 'a@example.com', 'name' => '' ), array( 'email' => 'b@example.com', 'name' => 'B' ) ),
			cc: array( array( 'email' => 'c@example.com', 'name' => '' ) ),
			bcc: array( array( 'email' => 'hidden@example.com', 'name' => '' ) ),
			reply_to: array( array( 'email' => 'reply@example.com', 'name' => 'Support' ) ),
			subject: 'Hello',
			html: '<p>Hi</p>',
			text: 'Hi',
			attachments: array(
				array( 'filename' => 'invoice.pdf', 'content' => 'PDF', 'type' => 'application/pdf', 'disposition' => 'attachment', 'cid' => '' ),
				array( 'filename' => 'logo.png', 'content' => 'PNG', 'type' => 'image/png', 'disposition' => 'inline', 'cid' => 'logo' ),
			),
			headers: array( array( 'name' => 'X-Order', 'value' => '1001' ) ),
			mime: "To: a@example.com\r\nSubject: Hello\r\n\r\nHi",
		);
	}

	public function test_ses_sends_signed_raw_mime(): void {
		$mailer  = new AmazonSes(
			$this->options(
				array(
					'ses.access_key_id'     => 'AKIDEXAMPLE',
					'ses.secret_access_key' => 'secret',
					'ses.region'            => 'eu-west-1',
					'ses.configuration_set' => 'tracking',
				)
			)
		);
		$request = $mailer->build_request( $this->message() );
		$payload = json_decode( $request['body'], true );

		$this->assertSame( 'https://email.eu-west-1.amazonaws.com/v2/email/outbound-emails', $request['url'] );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/', $request['headers']['Authorization'] );
		$this->assertStringContainsString( '/eu-west-1/ses/aws4_request', $request['headers']['Authorization'] );
		$this->assertSame( array( 'hidden@example.com' ), $payload['Destination']['BccAddresses'] );
		$this->assertSame( $this->message()->mime, base64_decode( $payload['Content']['Raw']['Data'] ) );
		$this->assertSame( 'tracking', $payload['ConfigurationSetName'] );
	}

	public function test_ses_unknown_region_falls_back_and_errors_parse(): void {
		$mailer = new AmazonSes( $this->options( array( 'ses.region' => 'mars-1' ) ) );

		$this->assertStringContainsString( 'email.us-east-1.', $mailer->build_request( $this->message() )['url'] );
		$this->assertSame( 'id-1', $mailer->parse_response( 200, '{"MessageId":"id-1"}', array() )->message_id );

		$failure = $mailer->parse_response( 400, '{"message":"Email address is not verified."}', array( 'x-amzn-errortype' => 'MessageRejected:http://internal.amazon.com/' ) );
		$this->assertSame( 'Amazon SES API error (HTTP 400): MessageRejected: Email address is not verified.', $failure->error );
	}

	public function test_brevo_payload(): void {
		$mailer  = new Brevo( $this->options( array( 'brevo.api_key' => 'xkeysib-1' ) ) );
		$request = $mailer->build_request( $this->message() );
		$payload = json_decode( $request['body'], true );

		$this->assertSame( 'xkeysib-1', $request['headers']['api-key'] );
		$this->assertSame( array( 'email' => 'from@example.com', 'name' => 'Shop' ), $payload['sender'] );
		$this->assertSame( array( 'email' => 'reply@example.com', 'name' => 'Support' ), $payload['replyTo'] );
		$this->assertSame( array( 'X-Order' => '1001' ), $payload['headers'] );
		$this->assertCount( 2, $payload['attachment'] );
		$this->assertSame( 'abc@smtp', $mailer->parse_response( 201, '{"messageId":"<abc@smtp>"}', array() )->message_id );
		$this->assertStringContainsString( 'Key not found (unauthorized)', $mailer->parse_response( 401, '{"code":"unauthorized","message":"Key not found"}', array() )->error );
	}

	public function test_mailjet_payload_with_inline_images(): void {
		$mailer  = new Mailjet( $this->options( array( 'mailjet.api_key' => 'key', 'mailjet.secret_key' => 'sec', 'mailjet.region' => 'us' ) ) );
		$request = $mailer->build_request( $this->message() );
		$mail    = json_decode( $request['body'], true )['Messages'][0];

		$this->assertSame( 'https://api.us.mailjet.com/v3.1/send', $request['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'key:sec' ), $request['headers']['Authorization'] );
		$this->assertSame( array( 'Email' => 'b@example.com', 'Name' => 'B' ), $mail['To'][1] );
		$this->assertSame( 'invoice.pdf', $mail['Attachments'][0]['Filename'] );
		$this->assertSame( 'logo', $mail['InlinedAttachments'][0]['ContentID'] );

		$ok = '{"Messages":[{"Status":"success","To":[{"Email":"a@example.com","MessageID":123}]}]}';
		$this->assertSame( '123', $mailer->parse_response( 200, $ok, array() )->message_id );

		$error = '{"Messages":[{"Status":"error","Errors":[{"ErrorMessage":"The sender is not validated","ErrorRelatedTo":["From"]}]}]}';
		$this->assertStringContainsString( 'The sender is not validated (From)', $mailer->parse_response( 400, $error, array() )->error );
	}

	public function test_resend_payload(): void {
		$mailer  = new Resend( $this->options( array( 'resend.api_key' => 're_1' ) ) );
		$payload = json_decode( $mailer->build_request( $this->message() )['body'], true );

		$this->assertSame( '"Shop" <from@example.com>', $payload['from'] );
		$this->assertSame( array( 'a@example.com', '"B" <b@example.com>' ), $payload['to'] );
		$this->assertSame( array( '"Support" <reply@example.com>' ), $payload['reply_to'] );
		$this->assertSame( 'logo', $payload['attachments'][1]['content_id'] );
		$this->assertSame( 'e-1', $mailer->parse_response( 200, '{"id":"e-1"}', array() )->message_id );
		$this->assertFalse( $mailer->parse_response( 422, '{"statusCode":422,"name":"validation_error","message":"Invalid `to` field."}', array() )->ok );
	}

	public function test_smtp2go_payload(): void {
		$mailer  = new Smtp2go( $this->options( array( 'smtp2go.api_key' => 'api-1', 'smtp2go.region' => 'eu' ) ) );
		$request = $mailer->build_request( $this->message() );
		$payload = json_decode( $request['body'], true );

		$this->assertSame( 'https://eu-api.smtp2go.com/v3/email/send', $request['url'] );
		$this->assertSame( 'api-1', $request['headers']['X-Smtp2go-Api-Key'] );
		$this->assertContains( array( 'header' => 'Reply-To', 'value' => '"Support" <reply@example.com>' ), $payload['custom_headers'] );
		$this->assertSame( 'logo', $payload['inlines'][0]['filename'] );
		$this->assertSame( 'invoice.pdf', $payload['attachments'][0]['filename'] );

		$this->assertTrue( $mailer->parse_response( 200, '{"data":{"succeeded":3,"failed":0,"email_id":"x"}}', array() )->ok );
		$this->assertFalse( $mailer->parse_response( 200, '{"data":{"succeeded":0,"failed":1,"failures":["bad"]}}', array() )->ok );
	}
}
