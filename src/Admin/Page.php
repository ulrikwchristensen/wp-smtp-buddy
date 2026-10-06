<?php
/**
 * Admin page and its tabs.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Alerts\Alerts;
use SmtpBuddy\Log\DebugEvents;
use SmtpBuddy\Mail\Queue;
use SmtpBuddy\Mail\Sender;
use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Options;
use SmtpBuddy\Support\ErrorHints;

final class Page {

	public const SLUG = 'wp-smtp-buddy';

	private const PER_PAGE = 20;

	private string $hook_suffix = '';

	public function __construct(
		private Options $options,
		private Registry $registry,
		private Fields $fields,
		private DebugEvents $debug_events,
		private LogScreen $log_screen,
		private DomainScreen $domain_screen,
		private Sender $sender,
		private Queue $queue,
		private Alerts $alerts,
	) {}

	public static function capability(): string {
		if ( Context::network() ) {
			return 'manage_network_options';
		}

		/**
		 * Filters the capability needed to manage WP SMTP Buddy.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'wpsb_capability', 'manage_options' );
	}

	public static function url( string $tab = 'settings', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			Context::network() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPSB_FILE ), array( $this, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( WPSB_FILE ), array( $this, 'action_links' ) );
	}

	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'WP SMTP Buddy', 'wp-smtp-buddy' ),
			__( 'SMTP Buddy', 'wp-smtp-buddy' ),
			self::capability(),
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-email-alt'
		);

		add_action( 'load-' . $this->hook_suffix, array( $this->log_screen, 'handle_actions' ) );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wpsb-admin', WPSB_URL . 'assets/admin.css', array(), WPSB_VERSION );
		wp_enqueue_script( 'wpsb-admin', WPSB_URL . 'assets/admin.js', array(), WPSB_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * @param array<string, string> $links
	 * @return array<string, string>
	 */
	public function action_links( array $links ): array {
		return array_merge(
			array( 'settings' => sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'wp-smtp-buddy' ) ) ),
			$links
		);
	}

	public function render(): void {
		$tabs = array(
			'settings' => __( 'Settings', 'wp-smtp-buddy' ),
			'log'      => __( 'Email Log', 'wp-smtp-buddy' ),
			'test'     => __( 'Send Test', 'wp-smtp-buddy' ),
			'domain'   => __( 'Domain Check', 'wp-smtp-buddy' ),
			'debug'    => __( 'Debug Events', 'wp-smtp-buddy' ),
		);

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'settings';

		echo '<div class="wrap wpsb">';
		echo '<h1 class="wp-heading-inline">' . esc_html( Context::network() ? __( 'WP SMTP Buddy: Network', 'wp-smtp-buddy' ) : __( 'WP SMTP Buddy', 'wp-smtp-buddy' ) ) . '</h1>';

		if ( ! is_multisite() || Context::network() || 'site' === $this->options->scope() ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( Wizard::url() ), esc_html__( 'Setup wizard', 'wp-smtp-buddy' ) );
		}

		echo '<hr class="wp-header-end">';

		$this->render_flash();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s"%s>%s</a>',
				esc_url( self::url( $slug ) ),
				$slug === $tab ? 'nav-tab-active' : '',
				$slug === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		match ( $tab ) {
			'log'    => $this->log_screen->render(),
			'test'   => $this->render_test(),
			'domain' => $this->domain_screen->render(),
			'debug'  => $this->render_debug(),
			default  => $this->render_settings(),
		};

		echo '</div>';
	}

	private function render_flash(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['wpsb_notice'] ) ? sanitize_key( $_GET['wpsb_notice'] ) : '';
		$count  = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		$failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
		// phpcs:enable

		$oauth_mailer = isset( $_GET['mailer'] ) ? $this->registry->get( sanitize_key( $_GET['mailer'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$oauth_label  = $oauth_mailer ? $oauth_mailer->label() : '';

		if ( 'import_error' === $notice ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( (string) get_transient( 'wpsb_import_error_' . get_current_user_id() ) ) );
			delete_transient( 'wpsb_import_error_' . get_current_user_id() );
		}

		if ( 'oauth_error' === $notice ) {
			$error = (string) get_transient( \SmtpBuddy\Auth\OAuthController::error_key() );
			$hint  = ErrorHints::explain( $error, 'gmail' ) ?? ErrorHints::explain( $error, 'microsoft' );
			delete_transient( \SmtpBuddy\Auth\OAuthController::error_key() );

			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Sign-in failed.', 'wp-smtp-buddy' ) . '</strong> ' . esc_html( $error ) . '</p>';

			if ( null !== $hint ) {
				echo '<p class="wpsb-hint">' . esc_html( $hint ) . '</p>';
			}

			echo '</div>';
		}

		$messages = array(
			/* translators: %s: mailer name */
			'oauth_connected'    => sprintf( __( '%s is connected. Send a test email to check it.', 'wp-smtp-buddy' ), $oauth_label ),
			/* translators: %s: mailer name */
			'oauth_disconnected' => sprintf( __( '%s was disconnected.', 'wp-smtp-buddy' ), $oauth_label ),
			'saved'              => __( 'Settings saved.', 'wp-smtp-buddy' ),
			'cleared'            => __( 'Debug events cleared.', 'wp-smtp-buddy' ),
			'log_cleared'        => __( 'Email log cleared.', 'wp-smtp-buddy' ),
			/* translators: %s: number of settings */
			'imported'           => sprintf( __( 'Imported %s settings. Secrets that were not in the file were kept.', 'wp-smtp-buddy' ), number_format_i18n( $count ) ),
			'site_custom'        => __( 'This site now uses its own email settings. Choose a mailer and enter its credentials below (sender and log preferences were copied from the network).', 'wp-smtp-buddy' ),
			'site_network'       => __( 'This site now uses the network email settings.', 'wp-smtp-buddy' ),
			/* translators: %s: number of emails */
			'deleted'            => sprintf( _n( '%s email deleted.', '%s emails deleted.', $count, 'wp-smtp-buddy' ), number_format_i18n( $count ) ),
			/* translators: %s: number of emails */
			'resent'             => sprintf( _n( '%s email resent.', '%s emails resent.', $count, 'wp-smtp-buddy' ), number_format_i18n( $count ) ),
		);

		if ( isset( $messages[ $notice ] ) && ( 'resent' !== $notice || $count > 0 ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
		}

		$invalid = isset( $_GET['wpsb_invalid'] ) ? array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_GET['wpsb_invalid'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$all     = $this->fields->all();
		$labels  = array_filter( array_map( static fn ( string $key ): string => (string) ( $all[ $key ]['label'] ?? '' ), $invalid ) );

		if ( $labels ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: list of field names */
						__( 'These values were not saved because they are not valid https:// URLs: %s.', 'wp-smtp-buddy' ),
						implode( ', ', $labels )
					)
				)
			);
		}

		if ( 'resent' === $notice && $failed > 0 ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of emails */
						_n( '%s email could not be resent. See the newest log entries for the error.', '%s emails could not be resent. See the newest log entries for the errors.', $failed, 'wp-smtp-buddy' ),
						number_format_i18n( $failed )
					)
				)
			);
		}
	}

	private function render_settings(): void {
		$selected        = $this->registry->selected()->slug();
		$mailer_constant = $this->options->is_constant( 'mailer' );
		$backup_slug     = (string) $this->options->get( 'backup.mailer' );

		if ( is_multisite() && ! Context::network() && $this->options->has_network_settings() && ! $this->render_site_mode() ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpsb-settings">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::SAVE ) . '">';
		wp_nonce_field( Actions::SAVE );
		Context::fields();

		echo '<h2 id="wpsb-mailer-title">' . esc_html__( 'Mailer', 'wp-smtp-buddy' ) . '</h2>';

		if ( $mailer_constant ) {
			printf(
				'<p><span class="wpsb-badge">%s</span></p>',
				/* translators: %s: constant name */
				esc_html( sprintf( __( 'Set by %s in wp-config.php', 'wp-smtp-buddy' ), Options::constant_name( 'mailer' ) ) )
			);
		}

		echo '<div class="wpsb-mailers" role="radiogroup" aria-labelledby="wpsb-mailer-title">';
		foreach ( $this->registry->all() as $slug => $mailer ) {
			printf(
				'<label class="wpsb-mailer%1$s"><input type="radio" name="%2$s[mailer]" value="%3$s" aria-describedby="wpsb-desc-%3$s" %4$s %5$s><strong>%6$s</strong><span id="wpsb-desc-%3$s">%7$s</span></label>',
				$slug === $selected ? ' is-selected' : '',
				esc_attr( Fields::INPUT ),
				esc_attr( $slug ),
				checked( $slug, $selected, false ),
				disabled( $mailer_constant, true, false ),
				esc_html( $mailer->label() ),
				esc_html( $mailer->description() )
			);
		}
		echo '</div>';

		foreach ( $this->registry->all() as $slug => $mailer ) {
			$fields = $mailer->fields();

			if ( ! $fields ) {
				continue;
			}

			printf( '<div class="wpsb-mailer-fields" data-mailer="%s">', esc_attr( $slug ) );
			/* translators: %s: mailer name */
			echo '<h2>' . esc_html( sprintf( __( '%s settings', 'wp-smtp-buddy' ), $mailer->label() ) ) . '</h2>';

			if ( $slug === $selected && ! $mailer->is_configured() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This mailer is missing required settings. Until they are filled in, email is sent with PHP mail().', 'wp-smtp-buddy' ) . '</p></div>';
			} elseif ( $slug === $backup_slug && $slug !== $selected && ! $mailer->is_configured() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This mailer is set as the backup but is missing required settings, so it won\'t be used.', 'wp-smtp-buddy' ) . '</p></div>';
			}

			echo '<table class="form-table" role="presentation">';
			foreach ( $fields as $name => $field ) {
				$this->fields->render_row( "{$slug}.{$name}", $field );
			}
			echo '</table></div>';
		}

		$this->render_section( __( 'Sender', 'wp-smtp-buddy' ), $this->fields->sender() );

		$backup_note = '';

		if ( '' !== $backup_slug && $backup_slug === $selected ) {
			$backup_note = __( 'The backup mailer is the same as the main mailer, so it won\'t be used. Choose a different one.', 'wp-smtp-buddy' );
		}

		$this->render_section( __( 'Backup Connection', 'wp-smtp-buddy' ), $this->fields->backup(), $backup_note );
		$this->render_section( __( 'Failure Alerts', 'wp-smtp-buddy' ), $this->fields->alerts(), __( 'Get a message in a chat channel when an email fails to send. Alerts include the subject and error. Recipients and other email addresses are left out.', 'wp-smtp-buddy' ) );

		$status      = $this->queue->status();
		$queue_intro = '';

		if ( $status['pending'] > 0 ) {
			$queue_intro = sprintf(
				/* translators: %s: number of emails */
				_n( '%s email is waiting to be sent.', '%s emails are waiting to be sent.', $status['pending'], 'wp-smtp-buddy' ),
				number_format_i18n( $status['pending'] )
			);
		}

		$this->render_section( __( 'Background Sending', 'wp-smtp-buddy' ), $this->fields->queue(), $queue_intro );
		$this->render_section( __( 'Email Log', 'wp-smtp-buddy' ), $this->fields->log() );
		$this->render_section( __( 'Email Controls', 'wp-smtp-buddy' ), $this->fields->controls(), __( 'Turn off WordPress notification emails you don\'t need. Emails from other plugins are not affected.', 'wp-smtp-buddy' ) );

		if ( Context::network() ) {
			$this->render_section( __( 'Sites', 'wp-smtp-buddy' ), $this->fields->network(), __( 'These settings apply to every site in the network that doesn\'t use its own settings.', 'wp-smtp-buddy' ) );
		}

		$this->render_section( __( 'Advanced', 'wp-smtp-buddy' ), $this->fields->advanced() );

		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: example constant */
				__( 'Tip: any setting can be locked in wp-config.php with a constant, for example %s.', 'wp-smtp-buddy' ),
				"<code>define( 'WPSB_SENDGRID_API_KEY', '…' );</code>"
			),
			array( 'code' => array() )
		) . '</p>';

		submit_button();
		echo '</form>';

		$this->render_transfer();
	}

	/**
	 * Export and import forms (separate from the settings form).
	 */
	private function render_transfer(): void {
		echo '<h2>' . esc_html__( 'Export & Import', 'wp-smtp-buddy' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy these settings to another site. Passwords, API keys and webhook URLs are left out unless you include them.', 'wp-smtp-buddy' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpsb-transfer">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::EXPORT ) . '">';
		wp_nonce_field( Actions::EXPORT );
		Context::fields();
		echo '<label><input type="checkbox" name="include_secrets" value="1"> ' . esc_html__( 'Include passwords, API keys and webhook URLs (in plain text)', 'wp-smtp-buddy' ) . '</label> ';
		submit_button( __( 'Export Settings', 'wp-smtp-buddy' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="wpsb-transfer">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::IMPORT ) . '">';
		wp_nonce_field( Actions::IMPORT );
		Context::fields();
		echo '<label for="wpsb-import-file">' . esc_html__( 'Settings file', 'wp-smtp-buddy' ) . '</label> <input type="file" id="wpsb-import-file" name="settings_file" accept=".json,application/json" required> ';
		submit_button( __( 'Import Settings', 'wp-smtp-buddy' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * On a network site: explains where the settings come from and offers to switch.
	 *
	 * @return bool Whether the site's own settings form should be shown.
	 */
	private function render_site_mode(): bool {
		$mailer = $this->registry->selected();

		if ( ! $this->options->allows_override() ) {
			printf(
				'<div class="notice notice-info inline wpsb-site-mode"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: mailer name */
						__( 'Email for this site is managed by the network administrator. Mailer: %s.', 'wp-smtp-buddy' ),
						$mailer->label()
					)
				)
			);

			return false;
		}

		$custom = 'custom' === $this->options->site_mode();

		echo '<div class="notice notice-info inline wpsb-site-mode"><p>' . esc_html(
			$custom
				? __( 'This site uses its own email settings instead of the network settings.', 'wp-smtp-buddy' )
				/* translators: %s: mailer name */
				: sprintf( __( 'This site uses the network email settings (mailer: %s).', 'wp-smtp-buddy' ), $mailer->label() )
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::SITE_MODE ) . '">';
		echo '<input type="hidden" name="mode" value="' . esc_attr( $custom ? 'network' : 'custom' ) . '">';
		wp_nonce_field( Actions::SITE_MODE );
		submit_button(
			$custom ? __( 'Use the network settings', 'wp-smtp-buddy' ) : __( 'Use custom settings for this site', 'wp-smtp-buddy' ),
			'secondary',
			'submit',
			false
		);
		echo '</form></div>';

		return $custom;
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function render_section( string $title, array $fields, string $intro = '' ): void {
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( '' !== $intro ) {
			echo '<p>' . esc_html( $intro ) . '</p>';
		}

		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $field ) {
			$this->fields->render_row( $key, $field );
		}
		echo '</table>';
	}

	private function render_test(): void {
		$result = get_transient( Actions::test_result_key() );

		if ( is_array( $result ) ) {
			delete_transient( Actions::test_result_key() );
			$this->render_test_result( $result );
		}

		$mailer = $this->registry->selected();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::TEST ) . '">';
		wp_nonce_field( Actions::TEST );
		Context::fields();

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: mailer name */
				__( 'Send a test email with the current mailer: %s.', 'wp-smtp-buddy' ),
				$mailer->is_configured() ? $mailer->label() : $this->registry->get( 'php' )?->label()
			)
		) . '</p>';

		echo '<table class="form-table" role="presentation">';
		printf(
			'<tr><th scope="row"><label for="wpsb-test-to">%s</label></th><td><input type="email" id="wpsb-test-to" name="to" value="%s" class="regular-text" required></td></tr>',
			esc_html__( 'Send to', 'wp-smtp-buddy' ),
			esc_attr( wp_get_current_user()->user_email )
		);
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="html" value="1" checked> %s</label></td></tr>',
			esc_html__( 'HTML', 'wp-smtp-buddy' ),
			esc_html__( 'Send as HTML', 'wp-smtp-buddy' )
		);
		echo '</table>';

		submit_button( __( 'Send Test Email', 'wp-smtp-buddy' ) );
		echo '</form>';

		$backup = $this->sender->backup_for( $mailer->is_configured() ? $mailer : ( $this->registry->get( 'php' ) ?? $mailer ) );

		if ( null !== $backup ) {
			/* translators: %s: mailer name */
			echo '<p class="description">' . esc_html( sprintf( __( 'If it fails, the backup mailer (%s) is tried as well.', 'wp-smtp-buddy' ), $backup->label() ) ) . '</p>';
		}

		$this->render_alert_test();
	}

	private function render_alert_test(): void {
		echo '<h2>' . esc_html__( 'Test Alerts', 'wp-smtp-buddy' ) . '</h2>';

		$results = get_transient( Actions::alert_result_key() );

		if ( is_array( $results ) ) {
			delete_transient( Actions::alert_result_key() );

			foreach ( $results as $channel => $result ) {
				printf(
					'<div class="notice notice-%s inline"><p><strong>%s:</strong> %s</p></div>',
					$result['ok'] ? 'success' : 'error',
					esc_html( ucfirst( $channel ) ),
					esc_html( $result['ok'] ? __( 'Test alert delivered.', 'wp-smtp-buddy' ) : $result['message'] )
				);
			}
		}

		$channels = array_keys( $this->alerts->configured() );

		if ( ! $channels ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'No alert channels are set up yet.', 'wp-smtp-buddy' ),
				esc_url( self::url() ),
				esc_html__( 'Add a webhook URL in Settings → Failure Alerts.', 'wp-smtp-buddy' )
			);

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::TEST_ALERTS ) . '">';
		wp_nonce_field( Actions::TEST_ALERTS );
		Context::fields();
		/* translators: %s: list of channels */
		echo '<p>' . esc_html( sprintf( __( 'Send a test alert to: %s.', 'wp-smtp-buddy' ), implode( ', ', array_map( 'ucfirst', $channels ) ) ) ) . '</p>';
		submit_button( __( 'Send Test Alert', 'wp-smtp-buddy' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $result From TestEmail::send().
	 */
	private function render_test_result( array $result ): void {
		if ( $result['ok'] ) {
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: recipient, 2: mailer name */
						__( 'Test email sent to %1$s with %2$s. Check the inbox (and the spam folder).', 'wp-smtp-buddy' ),
						$result['to'],
						$result['mailer_label']
					)
				)
			);
		} else {
			echo '<div class="notice notice-error wpsb-test-error">';
			/* translators: %s: mailer name */
			echo '<p><strong>' . esc_html( sprintf( __( 'The test email could not be sent with %s.', 'wp-smtp-buddy' ), $result['mailer_label'] ) ) . '</strong></p>';
			echo '<p><code>' . esc_html( '' !== $result['error'] ? $result['error'] : __( 'Unknown error.', 'wp-smtp-buddy' ) ) . '</code></p>';

			if ( ! empty( $result['hint'] ) ) {
				echo '<p class="wpsb-hint"><strong>' . esc_html__( 'How to fix it:', 'wp-smtp-buddy' ) . '</strong> ' . esc_html( $result['hint'] ) . '</p>';
			}

			echo '</div>';
		}

		if ( '' !== $result['transcript'] ) {
			echo '<details class="wpsb-transcript"' . ( $result['ok'] ? '' : ' open' ) . '><summary>' . esc_html__( 'SMTP conversation (passwords removed)', 'wp-smtp-buddy' ) . '</summary>';
			echo '<pre>' . esc_html( $result['transcript'] ) . '</pre></details>';
		}
	}

	private function render_debug(): void {
		$site  = Context::network() ? 0 : null;
		$total = $this->debug_events->count( $site );
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$items = $this->debug_events->recent( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $site );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: number of days */
				__( 'Errors and warnings from the last %d days. Message content is not stored.', 'wp-smtp-buddy' ),
				(int) $this->options->get( 'debug_events_days' )
			)
		) . '</p>';

		if ( ! $items ) {
			echo '<p><em>' . esc_html__( 'No events. Everything is running smoothly.', 'wp-smtp-buddy' ) . '</em></p>';

			return;
		}

		echo '<table class="widefat striped wpsb-events"><thead><tr>';
		$headings = array( __( 'Date', 'wp-smtp-buddy' ), __( 'Mailer', 'wp-smtp-buddy' ), __( 'Event', 'wp-smtp-buddy' ), __( 'Email', 'wp-smtp-buddy' ) );

		if ( Context::network() ) {
			array_splice( $headings, 1, 0, array( __( 'Site', 'wp-smtp-buddy' ) ) );
		}

		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $items as $item ) {
			$context = $item->context ? json_decode( $item->context, true ) : array();
			$mailer  = $this->registry->get( $item->mailer );
			$hint    = ErrorHints::explain( $item->message, $item->mailer );

			echo '<tr>';
			echo '<td>' . esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $item->created_at . ' UTC' ) ) ) . '</td>';

			if ( Context::network() ) {
				echo '<td>' . esc_html( Context::site_name( (int) $item->site_id ) ) . '</td>';
			}

			echo '<td>' . esc_html( $mailer ? $mailer->label() : $item->mailer ) . '</td>';
			printf(
				'<td><span class="wpsb-level wpsb-level-%1$s">%1$s</span> %2$s%3$s</td>',
				esc_attr( $item->level ),
				esc_html( $item->message ),
				$hint ? '<br><span class="wpsb-hint">' . esc_html( $hint ) . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			echo '<td>';
			if ( ! empty( $context['to'] ) ) {
				echo esc_html( implode( ', ', (array) $context['to'] ) ) . '<br>';
			}
			if ( ! empty( $context['subject'] ) ) {
				echo '<em>' . esc_html( $context['subject'] ) . '</em>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%', self::url( 'debug' ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => (int) ceil( $total / self::PER_PAGE ),
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Actions::CLEAR_EVENTS ) . '">';
		wp_nonce_field( Actions::CLEAR_EVENTS );
		Context::fields();
		submit_button( __( 'Clear All Events', 'wp-smtp-buddy' ), 'secondary' );
		echo '</form>';
	}
}
