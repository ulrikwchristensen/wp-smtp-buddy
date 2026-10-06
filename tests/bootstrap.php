<?php
/**
 * PHPUnit bootstrap for unit tests (no WordPress loaded; WP functions are mocked with Brain Monkey).
 *
 * @package SmtpBuddy
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error for unit tests.
	 */
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}
