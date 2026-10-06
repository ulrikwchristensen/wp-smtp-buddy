<?php

namespace SmtpBuddy\Tests\Unit;

use Brain\Monkey\Functions;
use SmtpBuddy\Crypto;
use SmtpBuddy\Options;
use SmtpBuddy\Tests\TestCase;

final class MultisiteScopeTest extends TestCase {

	private array $site    = array();
	private array $network = array();
	private bool $network_admin = false;
	private bool $switched      = false;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'is_network_admin' )->alias( fn () => $this->network_admin );
		Functions\when( 'ms_is_switched' )->alias( fn () => $this->switched );
		Functions\when( 'get_option' )->alias( fn ( $key, $fallback = false ) => $this->site[ $key ] ?? $fallback );
		Functions\when( 'get_site_option' )->alias( fn ( $key, $fallback = false ) => $this->network[ $key ] ?? $fallback );
	}

	private function make_options(): Options {
		return new Options( new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
	}

	public function test_without_network_settings_each_site_configures_itself(): void {
		$this->site[ Options::OPTION ] = array( 'from_email' => 'site@example.com' );

		$this->assertSame( 'site', $this->make_options()->scope() );
		$this->assertSame( 'site@example.com', $this->make_options()->get( 'from_email' ) );
	}

	public function test_sites_without_own_settings_follow_the_network(): void {
		$this->network[ Options::OPTION ] = array( 'from_email' => 'network@example.com' );

		$this->assertSame( 'network', $this->make_options()->scope() );
		$this->assertSame( 'network@example.com', $this->make_options()->get( 'from_email' ) );
	}

	public function test_existing_site_settings_are_kept_when_overrides_are_allowed(): void {
		$this->network[ Options::OPTION ] = array( 'from_email' => 'network@example.com' );
		$this->site[ Options::OPTION ]    = array( 'from_email' => 'site@example.com' );

		$this->assertSame( 'custom', $this->make_options()->site_mode() );
		$this->assertSame( 'site@example.com', $this->make_options()->get( 'from_email' ) );
	}

	public function test_site_mode_network_wins_over_own_settings(): void {
		$this->network[ Options::OPTION ]   = array( 'from_email' => 'network@example.com' );
		$this->site[ Options::OPTION ]      = array( 'from_email' => 'site@example.com' );
		$this->site[ Options::MODE_OPTION ] = 'network';

		$this->assertSame( 'network@example.com', $this->make_options()->get( 'from_email' ) );
	}

	public function test_locked_network_ignores_site_settings(): void {
		$this->network[ Options::OPTION ]   = array(
			'from_email'             => 'network@example.com',
			'network.allow_override' => false,
		);
		$this->site[ Options::OPTION ]      = array( 'from_email' => 'site@example.com' );
		$this->site[ Options::MODE_OPTION ] = 'custom';

		$this->assertFalse( $this->make_options()->allows_override() );
		$this->assertSame( 'network', $this->make_options()->scope() );
		$this->assertSame( 'network@example.com', $this->make_options()->get( 'from_email' ) );
	}

	public function test_network_context_and_switching(): void {
		$this->site[ Options::OPTION ] = array( 'from_email' => 'site@example.com' );

		$options = $this->make_options();
		$options->force_network( true );
		$this->assertSame( 'network', $options->scope(), 'admin-post requests flagged as network use network settings.' );

		$this->switched = true;
		$options->flush();
		$this->assertSame( 'site', $options->scope(), 'While switched to a site, its own effective settings apply.' );

		$this->switched      = false;
		$this->network_admin = true;
		$this->assertSame( 'network', $this->make_options()->scope() );
	}
}
