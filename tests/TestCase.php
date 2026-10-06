<?php
/**
 * Base test case with Brain Monkey.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use SmtpBuddy\Crypto;
use SmtpBuddy\Options;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubs(
			array(
				'wp_json_encode' => static fn ( $data ) => json_encode( $data ),
				'is_multisite'   => false,
				'wp_parse_url'   => static fn ( $url, $component = -1 ) => parse_url( $url, $component ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Options backed by an in-memory settings array.
	 *
	 * @param array<string, mixed> $settings
	 */
	protected function options( array $settings ): Options {
		Functions\when( 'get_option' )->justReturn( $settings );

		return new Options( new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
	}
}
