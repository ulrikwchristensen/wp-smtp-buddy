<?php

namespace SmtpBuddy\Tests\Unit;

use Brain\Monkey\Functions;
use SmtpBuddy\Admin\Fields;
use SmtpBuddy\Alerts\Alerts;
use SmtpBuddy\Tests\TestCase;

final class AlertsTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function event( string $type = 'failed' ): array {
		return array(
			'type'      => $type,
			'title'     => 'Email failed to send on Shop',
			'message'   => 'SMTP Error: <could not> connect & fail',
			'fields'    => array(
				'Subject' => 'Order #1',
				'Mailer'  => 'Other SMTP',
				'Empty'   => '',
			),
			'url'       => 'https://shop.example/wp-admin/admin.php?page=wp-smtp-buddy&tab=log&email=5',
			'link_text' => 'View the email in the log',
		);
	}

	public function test_slack_payload_escapes_control_characters(): void {
		$payload = Alerts::slack_payload( $this->event() );

		$this->assertSame( 'Email failed to send on Shop', $payload['text'] );
		$this->assertStringContainsString( '&lt;could not&gt; connect &amp; fail', $payload['blocks'][0]['text']['text'] );
		$this->assertSame( "*Subject*\nOrder #1", $payload['blocks'][1]['fields'][0]['text'] );
		$this->assertStringStartsWith( '<https://shop.example/', $payload['blocks'][2]['elements'][0]['text'] );
	}

	public function test_discord_payload(): void {
		$payload = Alerts::discord_payload( $this->event( 'backup' ) );
		$embed   = $payload['embeds'][0];

		$this->assertSame( array( 'parse' => array() ), $payload['allowed_mentions'] );
		$this->assertSame( 0xDBA617, $embed['color'] );
		$this->assertSame( '-', $embed['fields'][2]['value'], 'Discord rejects empty field values.' );
		$this->assertSame( $this->event()['url'], $embed['url'] );
	}

	public function test_teams_adaptive_card(): void {
		$card = Alerts::teams_payload( $this->event() )['attachments'][0];

		$this->assertSame( 'application/vnd.microsoft.card.adaptive', $card['contentType'] );
		$this->assertSame( 'AdaptiveCard', $card['content']['type'] );
		$this->assertSame( 'Attention', $card['content']['body'][0]['color'] );
		$this->assertSame( array( 'title' => 'Subject', 'value' => 'Order #1' ), $card['content']['body'][2]['facts'][0] );
		$this->assertSame( 'Action.OpenUrl', $card['content']['actions'][0]['type'] );
	}

	public function test_webhook_urls_must_be_https(): void {
		Functions\when( 'esc_url_raw' )->returnArg();

		$this->assertTrue( Fields::is_https_url( 'https://hooks.slack.com/services/T/B/x' ) );
		$this->assertFalse( Fields::is_https_url( 'http://hooks.slack.com/services/T/B/x' ) );
		$this->assertFalse( Fields::is_https_url( 'javascript:alert(1)' ) );
		$this->assertFalse( Fields::is_https_url( 'not a url' ) );
	}

	public function test_email_addresses_are_redacted(): void {
		$this->assertSame(
			'SMTP Error: The following recipients failed: [email], [email]',
			Alerts::redact_emails( 'SMTP Error: The following recipients failed: jane.doe+shop@example.co.uk, Bob@Example.com' )
		);
	}

	public function test_markdown_links_are_defused_for_discord_and_teams(): void {
		$event            = $this->event();
		$event['message'] = 'Click [your invoice](https://evil.example)';

		$this->assertStringNotContainsString( '[your invoice](', Alerts::discord_payload( $event )['embeds'][0]['description'] );
		$this->assertStringNotContainsString( '[your invoice](', Alerts::teams_payload( $event )['attachments'][0]['content']['body'][1]['text'] );
	}

	public function test_sites_only_copy_non_sensitive_network_settings(): void {
		$copy = \SmtpBuddy\Admin\Actions::shareable(
			array(
				'mailer'                 => 'smtp',
				'smtp.host'              => 'mail.example.com',
				'smtp.user'              => 'network-user',
				'smtp.pass'              => 'wpsb1:encrypted',
				'sendgrid.api_key'       => 'wpsb1:encrypted',
				'alerts.slack_webhook'   => 'wpsb1:encrypted',
				'backup.mailer'          => 'sendgrid',
				'network.allow_override' => true,
				'from_email'             => 'hello@example.com',
				'log.retention_days'     => 30,
				'controls.core_update'   => false,
				'queue.enabled'          => true,
			)
		);

		$this->assertSame( array( 'from_email', 'log.retention_days', 'controls.core_update', 'queue.enabled' ), array_keys( $copy ) );
	}
}
