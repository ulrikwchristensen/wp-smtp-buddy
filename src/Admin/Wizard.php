<?php
/**
 * Setup wizard: mailer → settings → sender → test → done.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Auth\OAuthController;
use SmtpBuddy\Mail\AbstractOAuthMailer;
use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Options;
use SmtpBuddy\Support\TestEmail;

final class Wizard {

	public const SLUG            = 'wp-smtp-buddy-setup';
	public const ACTION          = 'wpsb_wizard';
	public const REDIRECT_OPTION = 'wpsb_activation_redirect';

	public function __construct(
		private Options $options,
		private Registry $registry,
		private Fields $fields,
		private TestEmail $test_email,
	) {}

	/**
	 * @return array<string, string> Step slug => title.
	 */
	public static function steps(): array {
		return array(
			'mailer'    => __( 'Mailer', 'wp-smtp-buddy' ),
			'configure' => __( 'Connect', 'wp-smtp-buddy' ),
			'sender'    => __( 'Sender', 'wp-smtp-buddy' ),
			'test'      => __( 'Test', 'wp-smtp-buddy' ),
			'done'      => __( 'Done', 'wp-smtp-buddy' ),
		);
	}

	public static function url( string $step = 'mailer', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'step' => $step,
				),
				$args
			),
			Context::network() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' )
		);
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	public function add_page(): void {
		// Hidden page (no menu entry); reached from the settings page and after activation.
		$hook = add_submenu_page( '', __( 'WP SMTP Buddy setup', 'wp-smtp-buddy' ), '', is_network_admin() ? 'manage_network_options' : Page::capability(), self::SLUG, array( $this, 'render' ) );

		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue' ) );
		}
	}

	public function enqueue(): void {
		wp_enqueue_style( 'wpsb-admin', WPSB_URL . 'assets/admin.css', array(), WPSB_VERSION );
		wp_enqueue_script( 'wpsb-admin', WPSB_URL . 'assets/admin.js', array(), WPSB_VERSION, array( 'in_footer' => true ) );
	}

	/**
	 * Opens the wizard once after the plugin is activated, while no mailer is set up.
	 */
	public function maybe_redirect(): void {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return;
		}

		delete_option( self::REDIRECT_OPTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads core's bulk-activation flag.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( Page::capability() ) || ! $this->editable() ) {
			return;
		}

		if ( 'php' === $this->registry->selected()->slug() ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/**
	 * Whether the current context may change settings (not a network site that follows the network).
	 */
	private function editable(): bool {
		return ! is_multisite() || Context::network() || 'site' === $this->options->scope();
	}

	public function render(): void {
		$steps = self::steps();
		$step  = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'mailer'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step  = isset( $steps[ $step ] ) ? $step : 'mailer';

		echo '<div class="wrap wpsb wpsb-wizard">';
		echo '<h1>' . esc_html__( 'Set up WP SMTP Buddy', 'wp-smtp-buddy' ) . '</h1>';

		if ( ! $this->editable() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This site uses the network email settings, so there is nothing to set up here.', 'wp-smtp-buddy' ) . '</p></div></div>';

			return;
		}

		echo '<ol class="wpsb-steps">';
		$reached = true;
		foreach ( $steps as $slug => $title ) {
			printf(
				'<li class="%s"%s>%s</li>',
				esc_attr( $slug === $step ? 'is-current' : ( $reached ? 'is-done' : '' ) ),
				$slug === $step ? ' aria-current="step"' : '',
				esc_html( $title )
			);
			$reached = $reached && $slug !== $step;
		}
		echo '</ol><div class="wpsb-wizard-card">';

		$this->render_notice();

		match ( $step ) {
			'configure' => $this->render_configure(),
			'sender'    => $this->render_sender(),
			'test'      => $this->render_test(),
			'done'      => $this->render_done(),
			default     => $this->render_mailer(),
		};

		echo '</div>';
		printf( '<p class="wpsb-wizard-exit"><a href="%s">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Exit setup and go to the settings', 'wp-smtp-buddy' ) );
		echo '</div>';
	}

	/**
	 * Handles every step's form.
	 */
	public function handle(): void {
		$step = isset( $_POST['step'] ) ? sanitize_key( $_POST['step'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.

		if ( ! current_user_can( Page::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP SMTP Buddy.', 'wp-smtp-buddy' ), 403 );
		}

		check_admin_referer( self::ACTION . '_' . $step );

		if ( ! $this->editable() ) {
			wp_die( esc_html__( 'This site uses the network email settings.', 'wp-smtp-buddy' ), 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field in Fields::sanitize().
		$input = isset( $_POST[ Fields::INPUT ] ) && is_array( $_POST[ Fields::INPUT ] ) ? wp_unslash( $_POST[ Fields::INPUT ] ) : array();
		// phpcs:enable

		[ $next, $notice ] = match ( $step ) {
			'mailer'    => $this->save_mailer( $input ),
			'configure' => $this->save_configure( $input ),
			'sender'    => $this->save_sender( $input ),
			'test'      => $this->send_test(),
			default     => array( 'mailer', '' ),
		};

		$this->go( $next, $notice );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{0: string, 1: string} Next step and notice.
	 */
	private function save_mailer( array $input ): array {
		$this->options->save( $this->fields->sanitize( $input, array(), array( 'mailer' ) ) );

		return array( $this->registry->selected()->fields() ? 'configure' : 'sender', '' );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{0: string, 1: string}
	 */
	private function save_configure( array $input ): array {
		$mailer = $this->registry->selected();
		$keys   = array_map( static fn ( string $name ): string => $mailer->slug() . '.' . $name, array_keys( $mailer->fields() ) );

		$this->options->save( $this->fields->sanitize( $input, array(), $keys ) );
		$this->options->flush();

		if ( $this->fields->invalid ) {
			return array( 'configure', 'invalid' );
		}

		if ( $mailer instanceof AbstractOAuthMailer && ! $mailer->oauth()->connection( $mailer )['connected'] ) {
			return array( 'configure', '' !== $mailer->client_id() && '' !== $mailer->client_secret() ? 'connect' : 'missing' );
		}

		return $mailer->is_configured() ? array( 'sender', '' ) : array( 'configure', 'missing' );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{0: string, 1: string}
	 */
	private function save_sender( array $input ): array {
		$this->options->save( $this->fields->sanitize( $input, array(), array_keys( $this->fields->sender() ) ) );

		return array( 'test', '' );
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function send_test(): array {
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		set_transient( Actions::test_result_key(), $this->test_email->send( is_email( $to ) ? $to : wp_get_current_user()->user_email ), 5 * MINUTE_IN_SECONDS );

		return array( 'test', '' );
	}

	/**
	 * @return never
	 */
	private function go( string $step, string $notice = '' ): void {
		wp_safe_redirect( self::url( $step, '' !== $notice ? array( 'wpsb_wizard_notice' => $notice ) : array() ) );
		exit;
	}

	private function render_notice(): void {
		$notice   = isset( $_GET['wpsb_wizard_notice'] ) ? sanitize_key( $_GET['wpsb_wizard_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'missing' => array( 'warning', __( 'Some required settings are still missing. Fill them in to continue.', 'wp-smtp-buddy' ) ),
			'connect' => array( 'info', __( 'Saved. Now sign in with the button below to connect your account.', 'wp-smtp-buddy' ) ),
			'invalid' => array( 'warning', __( 'A value was not valid and was not saved. Check the fields below.', 'wp-smtp-buddy' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	private function form_start( string $step ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
		wp_nonce_field( self::ACTION . '_' . $step );
		Context::fields();
	}

	private function buttons( string $label, string $back = '' ): void {
		echo '<p class="wpsb-wizard-buttons">';

		if ( '' !== $back ) {
			printf( '<a href="%s" class="button">%s</a> ', esc_url( self::url( $back ) ), esc_html__( 'Back', 'wp-smtp-buddy' ) );
		}

		printf( '<button type="submit" class="button button-primary">%s</button></p>', esc_html( $label ) );
	}

	private function render_mailer(): void {
		$selected = $this->registry->selected()->slug();
		$selected = 'php' === $selected ? '' : $selected;

		echo '<h2 id="wpsb-wizard-mailer-title">' . esc_html__( 'How should WordPress send email?', 'wp-smtp-buddy' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pick the service you use. If you\'re not sure, choose "Other SMTP" and use the SMTP details from your email or hosting provider.', 'wp-smtp-buddy' ) . '</p>';

		$this->form_start( 'mailer' );
		echo '<div class="wpsb-mailers" role="radiogroup" aria-labelledby="wpsb-wizard-mailer-title">';

		foreach ( $this->registry->all() as $slug => $mailer ) {
			if ( 'php' === $slug ) {
				continue;
			}

			printf(
				'<label class="wpsb-mailer%1$s"><input type="radio" name="%2$s[mailer]" value="%3$s" %4$s required aria-describedby="wpsb-desc-%3$s"><strong>%5$s</strong><span id="wpsb-desc-%3$s">%6$s</span></label>',
				$slug === $selected ? ' is-selected' : '',
				esc_attr( Fields::INPUT ),
				esc_attr( $slug ),
				checked( $slug, $selected, false ),
				esc_html( $mailer->label() ),
				esc_html( $mailer->description() )
			);
		}

		echo '</div>';
		$this->buttons( __( 'Continue', 'wp-smtp-buddy' ) );
		echo '</form>';
	}

	private function render_configure(): void {
		$mailer = $this->registry->selected();

		/* translators: %s: mailer name */
		echo '<h2>' . esc_html( sprintf( __( 'Connect %s', 'wp-smtp-buddy' ), $mailer->label() ) ) . '</h2>';

		if ( ! $mailer->fields() ) {
			echo '<p>' . esc_html__( 'This mailer needs no settings.', 'wp-smtp-buddy' ) . '</p>';
		}

		if ( $mailer instanceof AbstractOAuthMailer ) {
			OAuthController::$return_to = 'wizard';
		}

		$this->form_start( 'configure' );
		echo '<table class="form-table" role="presentation">';
		foreach ( $mailer->fields() as $name => $field ) {
			$this->fields->render_row( $mailer->slug() . '.' . $name, $field );
		}
		echo '</table>';

		$this->buttons( $mailer instanceof AbstractOAuthMailer && ! $mailer->is_configured() ? __( 'Save', 'wp-smtp-buddy' ) : __( 'Continue', 'wp-smtp-buddy' ), 'mailer' );
		echo '</form>';
	}

	private function render_sender(): void {
		echo '<h2>' . esc_html__( 'Who should emails come from?', 'wp-smtp-buddy' ) . '</h2>';
		echo '<p>' . esc_html__( 'Use an address on a domain your mailer is allowed to send for. Otherwise emails are rejected or land in spam.', 'wp-smtp-buddy' ) . '</p>';

		$this->form_start( 'sender' );
		echo '<table class="form-table" role="presentation">';
		foreach ( $this->fields->sender() as $key => $field ) {
			if ( 'from_email' === $key && '' === (string) $this->options->get( 'from_email' ) ) {
				$field['description'] = trim( ( $field['description'] ?? '' ) . ' ' . $this->suggested_from() );
			}

			$this->fields->render_row( $key, $field );
		}
		echo '</table>';

		$this->buttons( __( 'Continue', 'wp-smtp-buddy' ), 'configure' );
		echo '</form>';
	}

	private function render_test(): void {
		$result = get_transient( Actions::test_result_key() );

		echo '<h2>' . esc_html__( 'Send a test email', 'wp-smtp-buddy' ) . '</h2>';

		if ( is_array( $result ) ) {
			delete_transient( Actions::test_result_key() );

			if ( $result['ok'] ) {
				/* translators: %s: recipient */
				echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( __( 'Sent to %s. Check the inbox (and the spam folder), then continue.', 'wp-smtp-buddy' ), $result['to'] ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'The test email could not be sent.', 'wp-smtp-buddy' ) . '</strong></p><p><code>' . esc_html( $result['error'] ) . '</code></p>';

				if ( ! empty( $result['hint'] ) ) {
					echo '<p class="wpsb-hint"><strong>' . esc_html__( 'How to fix it:', 'wp-smtp-buddy' ) . '</strong> ' . esc_html( $result['hint'] ) . '</p>';
				}

				printf( '<p><a href="%s">%s</a></p></div>', esc_url( self::url( 'configure' ) ), esc_html__( 'Go back and change the settings', 'wp-smtp-buddy' ) );
			}
		}

		$this->form_start( 'test' );
		printf(
			'<p><label for="wpsb-wizard-to">%s</label><br><input type="email" id="wpsb-wizard-to" name="to" value="%s" class="regular-text" required></p>',
			esc_html__( 'Send to', 'wp-smtp-buddy' ),
			esc_attr( wp_get_current_user()->user_email )
		);
		echo '<p class="wpsb-wizard-buttons">';
		printf( '<a href="%s" class="button">%s</a> ', esc_url( self::url( 'sender' ) ), esc_html__( 'Back', 'wp-smtp-buddy' ) );
		printf( '<button type="submit" class="button %s">%s</button> ', is_array( $result ) && $result['ok'] ? '' : 'button-primary', esc_html__( 'Send Test Email', 'wp-smtp-buddy' ) );

		if ( is_array( $result ) && $result['ok'] ) {
			printf( '<a href="%s" class="button button-primary">%s</a>', esc_url( self::url( 'done' ) ), esc_html__( 'Continue', 'wp-smtp-buddy' ) );
		} else {
			printf( '<a href="%s">%s</a>', esc_url( self::url( 'done' ) ), esc_html__( 'Skip', 'wp-smtp-buddy' ) );
		}

		echo '</p></form>';
	}

	private function render_done(): void {
		echo '<h2>' . esc_html__( 'You\'re all set', 'wp-smtp-buddy' ) . '</h2>';
		/* translators: %s: mailer name */
		echo '<p>' . esc_html( sprintf( __( 'WordPress now sends email with %s. A few more things you may want to set up:', 'wp-smtp-buddy' ), $this->registry->selected()->label() ) ) . '</p><ul class="wpsb-next">';

		foreach ( array(
			array( __( 'A backup mailer that takes over if this one fails', 'wp-smtp-buddy' ), Page::url( 'settings' ) . '#wpsb-backup-mailer' ),
			array( __( 'Alerts in Slack, Discord or Teams when an email fails', 'wp-smtp-buddy' ), Page::url( 'settings' ) . '#wpsb-alerts-slack_webhook' ),
			array( __( 'Check your domain\'s SPF, DKIM and DMARC records', 'wp-smtp-buddy' ), Page::url( 'domain' ) ),
			array( __( 'See every email WordPress sends in the email log', 'wp-smtp-buddy' ), Page::url( 'log' ) ),
		) as [ $label, $url ] ) {
			printf( '<li><a href="%s">%s</a></li>', esc_url( $url ), esc_html( $label ) );
		}

		echo '</ul>';
		printf( '<p><a href="%s" class="button button-primary">%s</a></p>', esc_url( Page::url() ), esc_html__( 'Go to the settings', 'wp-smtp-buddy' ) );
	}

	private function suggested_from(): string {
		$mailer = $this->registry->selected();

		if ( $mailer instanceof AbstractOAuthMailer ) {
			$account = $mailer->oauth()->connection( $mailer )['account'];

			if ( '' !== $account ) {
				/* translators: %s: email address */
				return sprintf( __( 'Your connected account is %s.', 'wp-smtp-buddy' ), $account );
			}
		}

		return '';
	}
}
