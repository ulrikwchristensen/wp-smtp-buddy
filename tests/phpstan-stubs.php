<?php
/**
 * Stubs for symbols PHPStan can't discover statically.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail {
	/**
	 * Runtime alias of WP_PHPMailer (WordPress 6.8+) or PHPMailer, created in MailCatcher.php.
	 */
	class BasePHPMailer extends \PHPMailer\PHPMailer\PHPMailer {}
}

namespace {
	/**
	 * The WP-CLI methods this plugin uses.
	 */
	class WP_CLI {
		public static function add_command( string $name, object|callable|string $callable ): bool {}
		public static function line( string $message = '' ): void {}
		public static function success( string $message ): void {}
		public static function warning( string $message ): void {}
		/** @return never */
		public static function error( string $message ): void {}
		/**
		 * @param array<string, mixed> $assoc_args
		 */
		public static function confirm( string $question, array $assoc_args = array() ): void {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string>               $fields
	 */
	function format_items( string $format, array $items, array $fields ): void {}
}
