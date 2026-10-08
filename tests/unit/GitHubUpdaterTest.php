<?php

namespace SmtpBuddy\Tests\Unit;

use Brain\Monkey\Functions;
use SmtpBuddy\Support\GitHubUpdater;
use SmtpBuddy\Tests\TestCase;

final class GitHubUpdaterTest extends TestCase {

	public function test_plugin_information_uses_latest_github_release(): void {
		$release = (object) array(
			'tag_name'     => 'v1.2.3',
			'published_at' => '2026-10-08T12:00:00Z',
		);
		$response = (object) array(
			'body' => wp_json_encode( $release ),
		);

		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url, array $args ) use ( $response ): object {
				self::assertSame( 'https://api.github.com/repos/ulrikwchristensen/wp-smtp-buddy/releases/latest', $url );
				self::assertSame( 'application/vnd.github+json', $args['headers']['Accept'] );
				self::assertSame( 5, $args['timeout'] );
				return $response;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( object $response ): string => $response->body
		);

		$updater = new GitHubUpdater();
		$result = $updater->plugins_api_result(
			new \stdClass(),
			'plugin_information',
			array( 'slug' => 'wp-smtp-buddy' )
		);

		$this->assertSame( '1.2.3', $result->version );
		$this->assertSame( 'https://github.com/ulrikwchristensen/wp-smtp-buddy/releases/download/v1.2.3/wp-smtp-buddy-1.2.3.zip', $result->downloadlink );
		$this->assertSame( 'https://github.com/ulrikwchristensen/wp-smtp-buddy/releases/tag/v1.2.3', $result->homepage );
		$this->assertSame( '2026-10-08T12:00:00Z', $result->last_updated );
	}

	public function test_plugin_information_leaves_other_plugins_untouched(): void {
		$updater = new GitHubUpdater();
		$result  = new \stdClass();

		$this->assertSame( $result, $updater->plugins_api_result( $result, 'plugin_information', array( 'slug' => 'other-plugin' ) ) );
	}
}
