<?php
/**
 * PSR-4 autoloader for the SmtpBuddy namespace (no runtime Composer dependency).
 *
 * @package SmtpBuddy
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SmtpBuddy\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
