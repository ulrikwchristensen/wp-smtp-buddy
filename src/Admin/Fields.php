<?php
/**
 * Settings field definitions, rendering and sanitizing.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Options;

final class Fields {

	public const INPUT = 'wpsb';

	/**
	 * Keys whose submitted value was rejected by the last sanitize() call.
	 *
	 * @var list<string>
	 */
	public array $invalid = array();

	public function __construct(
		private Options $options,
		private Registry $registry,
	) {}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function sender(): array {
		return array(
			'from_email'       => array(
				'label'       => __( 'From Email', 'wp-smtp-buddy' ),
				'type'        => 'email',
				'description' => __( 'The address emails are sent from. Most mailers require an address on a domain you have verified with them.', 'wp-smtp-buddy' ),
			),
			'force_from_email' => array(
				'label'       => __( 'Force From Email', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'description' => __( 'Use the From Email for all emails, even when another plugin sets its own sender. Recommended, because most mailers reject unverified senders.', 'wp-smtp-buddy' ),
			),
			'from_name'        => array(
				'label'       => __( 'From Name', 'wp-smtp-buddy' ),
				'type'        => 'text',
				'description' => __( 'For example, your site or company name.', 'wp-smtp-buddy' ),
			),
			'force_from_name'  => array(
				'label'       => __( 'Force From Name', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'description' => __( 'Use the From Name for all emails, even when another plugin sets its own.', 'wp-smtp-buddy' ),
			),
			'return_path'      => array(
				'label'       => __( 'Return Path', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Set the Return-Path (bounce address) to the From Email.', 'wp-smtp-buddy' ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function log(): array {
		return array(
			'log.enabled'        => array(
				'label'       => __( 'Email log', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Keep a record of every email WordPress sends, with its delivery status.', 'wp-smtp-buddy' ),
			),
			'log.content'        => array(
				'label'       => __( 'What to store', 'wp-smtp-buddy' ),
				'type'        => 'select',
				'default'     => 'full',
				'options'     => array(
					'full'     => __( 'Headers and message content', 'wp-smtp-buddy' ),
					'metadata' => __( 'Recipients, subject and status only', 'wp-smtp-buddy' ),
				),
				'description' => __( 'Storing the content lets you view and resend emails. Only metadata is more privacy-friendly, but resending is then not possible.', 'wp-smtp-buddy' ),
			),
			'log.attachments'    => array(
				'label'       => __( 'Store attachments', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Keep copies of attachments so they are included when an email is resent. Uses disk space.', 'wp-smtp-buddy' ),
			),
			'log.retention_days' => array(
				'label'   => __( 'Keep emails for', 'wp-smtp-buddy' ),
				'type'    => 'number',
				'default' => 30,
				'min'     => 1,
				'max'     => 365,
				'suffix'  => __( 'days', 'wp-smtp-buddy' ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function backup(): array {
		$options = array( '' => __( 'None', 'wp-smtp-buddy' ) );

		foreach ( $this->registry->all() as $slug => $mailer ) {
			if ( 'php' !== $slug ) {
				$options[ $slug ] = $mailer->label();
			}
		}

		return array(
			'backup.mailer' => array(
				'label'       => __( 'Backup mailer', 'wp-smtp-buddy' ),
				'type'        => 'select',
				'default'     => '',
				'options'     => $options,
				'description' => __( 'If the main mailer fails, the email is sent again with this one. Choose a different mailer and fill in its settings below.', 'wp-smtp-buddy' ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function alerts(): array {
		return array(
			'alerts.slack_webhook'   => array(
				'label'       => __( 'Slack webhook URL', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'validate'    => 'https_url',
				'description' => __( 'Create an incoming webhook in a Slack app (api.slack.com/apps → Incoming Webhooks). It starts with https://hooks.slack.com/.', 'wp-smtp-buddy' ),
			),
			'alerts.discord_webhook' => array(
				'label'       => __( 'Discord webhook URL', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'validate'    => 'https_url',
				'description' => __( 'In Discord: channel settings → Integrations → Webhooks → New Webhook → Copy Webhook URL.', 'wp-smtp-buddy' ),
			),
			'alerts.teams_webhook'   => array(
				'label'       => __( 'Microsoft Teams webhook URL', 'wp-smtp-buddy' ),
				'type'        => 'password',
				'secret'      => true,
				'validate'    => 'https_url',
				'description' => __( 'In Teams: channel → Workflows → "Post to a channel when a webhook request is received", then copy the URL.', 'wp-smtp-buddy' ),
			),
			'alerts.on_backup'       => array(
				'label'       => __( 'Alert when the backup is used', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'The email was delivered, but the main mailer needs fixing.', 'wp-smtp-buddy' ),
			),
			'alerts.throttle'        => array(
				'label'       => __( 'At most one alert every', 'wp-smtp-buddy' ),
				'type'        => 'number',
				'default'     => 15,
				'min'         => 0,
				'max'         => 1440,
				'suffix'      => __( 'minutes per channel (0 = no limit)', 'wp-smtp-buddy' ),
				'description' => __( 'Alerts held back during this time are counted in the next alert.', 'wp-smtp-buddy' ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function queue(): array {
		return array(
			'queue.enabled'    => array(
				'label'       => __( 'Send in the background', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Queue emails and send them with WP-Cron, so pages that send email respond faster. Failed emails are retried twice. Requires WP-Cron to run regularly (a real server cron job is recommended on low-traffic sites).', 'wp-smtp-buddy' ),
			),
			'queue.rate_limit' => array(
				'label'       => __( 'Rate limit', 'wp-smtp-buddy' ),
				'type'        => 'number',
				'default'     => 0,
				'min'         => 0,
				'max'         => 10000,
				'suffix'      => __( 'emails per minute (0 = no limit)', 'wp-smtp-buddy' ),
				'description' => __( 'Applies to queued emails. Use it to stay within your provider\'s sending rate.', 'wp-smtp-buddy' ),
			),
		);
	}

	/**
	 * Email Controls: one "send" checkbox per WordPress notification email.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function controls(): array {
		$fields = array();

		foreach ( \SmtpBuddy\Mail\EmailControls::controls() as $key => $control ) {
			$fields[ "controls.{$key}" ] = array(
				'label'          => $control['label'],
				'type'           => 'checkbox',
				'default'        => true,
				'checkbox_label' => __( 'Send', 'wp-smtp-buddy' ),
				'group'          => $control['group'],
			);
		}

		return $fields;
	}

	/**
	 * Network-only settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function network(): array {
		return array(
			'network.allow_override' => array(
				'label'       => __( 'Site settings', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Let site administrators switch to their own mailer and settings. When off, every site uses these network settings and site administrators can\'t change them.', 'wp-smtp-buddy' ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function advanced(): array {
		return array(
			'debug_events_days'   => array(
				'label'   => __( 'Keep debug events for', 'wp-smtp-buddy' ),
				'type'    => 'number',
				'default' => 30,
				'min'     => 1,
				'max'     => 365,
				'suffix'  => __( 'days', 'wp-smtp-buddy' ),
			),
			'delete_on_uninstall' => array(
				'label'       => __( 'Delete data on uninstall', 'wp-smtp-buddy' ),
				'type'        => 'checkbox',
				'description' => __( 'Remove all settings, credentials and logs when the plugin is deleted.', 'wp-smtp-buddy' ),
			),
		);
	}

	/**
	 * Every storable field keyed by its option key, including each mailer's fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$fields = $this->sender() + $this->backup() + $this->alerts() + $this->queue() + $this->log() + $this->controls() + ( Context::network() ? $this->network() : array() ) + $this->advanced();

		foreach ( $this->registry->all() as $slug => $mailer ) {
			foreach ( $mailer->fields() as $name => $field ) {
				$fields[ "{$slug}.{$name}" ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Builds the values to store from submitted input.
	 *
	 * Secret fields left empty keep their saved value; values set by constants are not stored.
	 *
	 * @param array<string, mixed> $input  Unslashed form input.
	 * @param list<string>         $clear  Secret keys the user asked to remove.
	 * @param list<string>|null    $only   Only update these keys and keep all other stored
	 *                                     values (used by the setup wizard's steps).
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input, array $clear = array(), ?array $only = null ): array {
		$stored        = $this->options->stored();
		$values        = array();
		$this->invalid = array();

		$mailer = (string) ( $input['mailer'] ?? '' );

		if ( ! $this->options->is_constant( 'mailer' ) && ( null === $only || in_array( 'mailer', $only, true ) ) ) {
			$values['mailer'] = null !== $this->registry->get( $mailer ) ? $mailer : 'php';
		}

		foreach ( $this->all() as $key => $field ) {
			if ( 'custom' === $field['type'] || $this->options->is_constant( $key ) || ( null !== $only && ! in_array( $key, $only, true ) ) ) {
				continue;
			}

			$raw = $input[ $key ] ?? null;

			if ( ! empty( $field['secret'] ) ) {
				$plain = is_string( $raw ) ? trim( preg_replace( '/[\x00-\x1F\x7F]/', '', $raw ) ) : '';

				if ( '' !== $plain && 'https_url' === ( $field['validate'] ?? '' ) && ! self::is_https_url( $plain ) ) {
					$this->invalid[] = $key;
					$plain           = '';
				}

				if ( '' !== $plain ) {
					$values[ $key ] = $this->options->encrypt( $plain );
				} elseif ( isset( $stored[ $key ] ) && ! in_array( $key, $clear, true ) ) {
					$values[ $key ] = $stored[ $key ];
				}

				continue;
			}

			$values[ $key ] = $this->sanitize_value( $field, $raw );
		}

		// Keep stored values the form didn't submit: keys overridden by constants (so they come
		// back if the constant is removed) and keys this form doesn't manage.
		$fields = $this->all();

		foreach ( $stored as $key => $value ) {
			$untouched = null !== $only
				? ! in_array( $key, $only, true ) || $this->options->is_constant( $key )
				: 'mailer' === $key || ! isset( $fields[ $key ] ) || $this->options->is_constant( $key );

			if ( ! array_key_exists( $key, $values ) && ! in_array( $key, $clear, true ) && $untouched ) {
				$values[ $key ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Renders one table row.
	 */
	public function render_row( string $key, array $field ): void {
		if ( 'custom' === $field['type'] ) {
			echo '<tr><th scope="row">' . esc_html( $field['label'] ) . '</th><td>';
			call_user_func( $field['render'] );
			echo '</td></tr>';

			return;
		}

		$id          = 'wpsb-' . str_replace( '.', '-', $key );
		$name        = self::INPUT . '[' . $key . ']';
		$is_constant = $this->options->is_constant( $key );
		$value       = $this->options->get( $key, $field['default'] ?? '' );
		$disabled    = disabled( $is_constant, true, false );

		// Label every input with its row title, so checkboxes are announced as e.g.
		// "Password changed: notify the user, Send" rather than just "Send".
		echo '<tr><th scope="row">';
		printf( '<label for="%s">%s</label>', esc_attr( $id ), esc_html( $field['label'] ) );
		echo '</th><td>';

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<input type="hidden" name="%1$s" value="0"><label><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s %4$s> %5$s</label>',
					esc_attr( $name ),
					esc_attr( $id ),
					checked( (bool) $value, true, false ),
					$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $field['checkbox_label'] ?? __( 'Enabled', 'wp-smtp-buddy' ) )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s" %s>', esc_attr( $id ), esc_attr( $name ), $disabled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( $field['options'] as $option_value => $option_label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $option_value ), selected( (string) $value, (string) $option_value, false ), esc_html( $option_label ) );
				}
				echo '</select>';
				break;

			case 'password':
				$has_value = $this->options->has( $key );
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="" class="regular-text" autocomplete="new-password" placeholder="%3$s" %4$s%5$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $has_value ? '••••••••  ' . __( '(saved, type to replace)', 'wp-smtp-buddy' ) : '' ),
					$disabled, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$has_value ? ' aria-describedby="' . esc_attr( $id ) . '-saved"' : ''
				);
				if ( $has_value ) {
					printf( '<span id="%s-saved" class="screen-reader-text">%s</span>', esc_attr( $id ), esc_html__( 'A value is saved. Leave empty to keep it, or type a new value to replace it.', 'wp-smtp-buddy' ) );
				}
				if ( $has_value && ! $is_constant ) {
					printf(
						' <label class="wpsb-clear"><input type="checkbox" name="wpsb_clear[]" value="%s"> %s</label>',
						esc_attr( $key ),
						esc_html__( 'Remove', 'wp-smtp-buddy' )
					);
				}
				break;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%d" max="%d" %s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					(int) ( $field['min'] ?? 0 ),
					(int) ( $field['max'] ?? PHP_INT_MAX ),
					$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				if ( ! empty( $field['suffix'] ) ) {
					echo ' ' . esc_html( $field['suffix'] );
				}
				break;

			default:
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="regular-text" %s>',
					'email' === $field['type'] ? 'email' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					$disabled // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
		}

		if ( $is_constant ) {
			printf(
				' <span class="wpsb-badge">%s</span>',
				/* translators: %s: constant name */
				esc_html( sprintf( __( 'Set by %s in wp-config.php', 'wp-smtp-buddy' ), Options::constant_name( $key ) ) )
			);
		}

		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
		}

		echo '</td></tr>';
	}

	public static function is_https_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && ! empty( $parts['host'] ) && esc_url_raw( $url, array( 'https' ) ) === $url;
	}

	private function sanitize_value( array $field, mixed $raw ): mixed {
		$default = $field['default'] ?? '';

		return match ( $field['type'] ) {
			'checkbox' => ! empty( $raw ),
			'email'    => sanitize_email( (string) $raw ),
			'number'   => is_numeric( $raw )
				? max( (int) ( $field['min'] ?? PHP_INT_MIN ), min( (int) ( $field['max'] ?? PHP_INT_MAX ), (int) $raw ) )
				: $default,
			'select'   => array_key_exists( (string) $raw, $field['options'] ) ? (string) $raw : $default,
			default    => sanitize_text_field( (string) $raw ),
		};
	}
}
