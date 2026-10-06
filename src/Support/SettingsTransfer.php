<?php
/**
 * Settings export and import (admin Tools and WP-CLI).
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

use SmtpBuddy\Admin\Fields;
use SmtpBuddy\Options;

final class SettingsTransfer {

	public const FORMAT = 'wp-smtp-buddy-settings';

	public function __construct(
		private Options $options,
		private Fields $fields,
	) {}

	/**
	 * Settings of the current scope as a portable array. Secrets (passwords, API keys, webhook
	 * URLs) are left out unless requested; they are then exported in plain text.
	 *
	 * @return array<string, mixed>
	 */
	public function export( bool $include_secrets = false ): array {
		$settings = array( 'mailer' => $this->options->get( 'mailer' ) );
		$secrets  = array();

		foreach ( $this->fields->all() as $key => $field ) {
			if ( 'custom' === $field['type'] || $this->options->is_constant( $key ) ) {
				continue;
			}

			if ( ! empty( $field['secret'] ) ) {
				if ( $include_secrets && $this->options->has( $key ) ) {
					$settings[ $key ] = (string) $this->options->get( $key );
				} elseif ( $this->options->has( $key ) ) {
					$secrets[] = $key;
				}

				continue;
			}

			$settings[ $key ] = $this->options->get( $key, $field['default'] ?? '' );
		}

		return array(
			'format'          => self::FORMAT,
			'version'         => WPSB_VERSION,
			'exported_at'     => gmdate( 'c' ),
			'site'            => home_url(),
			'secrets_omitted' => $secrets,
			'settings'        => $settings,
		);
	}

	/**
	 * Imports settings from an export. Unknown keys are skipped and every value goes through
	 * the same validation as the settings form. Secrets not in the file are kept.
	 *
	 * @param array<string, mixed> $data Decoded export file.
	 * @return array{imported: list<string>, skipped: list<string>}|\WP_Error
	 */
	public function import( array $data ): array|\WP_Error {
		if ( self::FORMAT !== ( $data['format'] ?? '' ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return new \WP_Error( 'wpsb_import_format', __( 'This is not a WP SMTP Buddy settings file.', 'wp-smtp-buddy' ) );
		}

		$known    = $this->fields->all();
		$imported = array();
		$skipped  = array();
		$input    = array();

		foreach ( $data['settings'] as $key => $value ) {
			$key = (string) $key;

			if ( ( 'mailer' !== $key && ! isset( $known[ $key ] ) ) || ( isset( $known[ $key ] ) && 'custom' === $known[ $key ]['type'] ) || $this->options->is_constant( $key ) || ! is_scalar( $value ) ) {
				$skipped[] = $key;
				continue;
			}

			$input[ $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value;
			$imported[]    = $key;
		}

		$this->options->save( $this->fields->sanitize( $input, array(), $imported ) );
		$this->options->flush();

		return array(
			'imported' => $imported,
			'skipped'  => array_merge( $skipped, $this->fields->invalid ),
		);
	}
}
