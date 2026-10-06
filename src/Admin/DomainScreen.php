<?php
/**
 * Domain Check tab: SPF, DKIM and DMARC for the sending domain.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Admin;

use SmtpBuddy\Mail\Registry;
use SmtpBuddy\Options;
use SmtpBuddy\Support\DomainCheck;
use SmtpBuddy\Support\SiteHealth;

final class DomainScreen {

	public function __construct(
		private Options $options,
		private Registry $registry,
		private DomainCheck $domain_check,
	) {}

	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only DNS lookup.
		$domain = isset( $_GET['domain'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['domain'] ) ) ) : SiteHealth::sending_domain( $this->options );
		$domain = (string) preg_replace( '/[^a-z0-9.\-]/', '', $domain );
		$fresh  = ! empty( $_GET['refresh'] );
		// phpcs:enable

		$mailer = $this->registry->selected();

		echo '<p>' . esc_html__( 'Receiving mail servers check these DNS records to decide whether email from your domain is genuine. Missing records are the most common reason email lands in spam.', 'wp-smtp-buddy' ) . '</p>';

		echo '<form method="get" class="wpsb-domain-form">';
		printf( '<input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="domain"><input type="hidden" name="refresh" value="1">', esc_attr( Page::SLUG ) );
		printf(
			'<label for="wpsb-domain">%s</label> <input type="text" id="wpsb-domain" name="domain" value="%s" class="regular-text"> ',
			esc_html__( 'Domain', 'wp-smtp-buddy' ),
			esc_attr( $domain )
		);
		submit_button( __( 'Check', 'wp-smtp-buddy' ), 'secondary', '', false );
		echo '</form>';

		if ( '' === $domain ) {
			return;
		}

		$result = $this->domain_check->check_cached( $domain, $mailer->slug(), $fresh );
		$labels = array(
			'spf'   => array( 'SPF', __( 'Lists the servers allowed to send email for the domain.', 'wp-smtp-buddy' ) ),
			'dkim'  => array( 'DKIM', __( 'A signature that proves the email wasn\'t changed and came from your domain.', 'wp-smtp-buddy' ) ),
			'dmarc' => array( 'DMARC', __( 'Tells receivers what to do with email that fails SPF and DKIM.', 'wp-smtp-buddy' ) ),
		);
		$icons  = array(
			DomainCheck::PASS => array( 'yes-alt', __( 'OK', 'wp-smtp-buddy' ) ),
			DomainCheck::WARN => array( 'warning', __( 'Needs attention', 'wp-smtp-buddy' ) ),
			DomainCheck::FAIL => array( 'dismiss', __( 'Problem', 'wp-smtp-buddy' ) ),
			DomainCheck::INFO => array( 'info', __( 'Info', 'wp-smtp-buddy' ) ),
		);

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: domain, 2: mailer name */
					__( 'Results for %1$s with %2$s. Results are cached for 12 hours. DNS changes can take a while to appear.', 'wp-smtp-buddy' ),
					$domain,
					$mailer->label()
				)
			)
		);

		echo '<table class="widefat wpsb-domain"><tbody>';

		foreach ( $result as $type => $item ) {
			[ $icon, $status_label ] = $icons[ $item['status'] ] ?? $icons[ DomainCheck::INFO ];

			printf(
				'<tr class="wpsb-dns-%1$s"><th scope="row"><strong>%2$s</strong><br><span class="description">%3$s</span></th><td><span class="dashicons dashicons-%4$s" aria-hidden="true"></span> <strong>%5$s</strong> %6$s%7$s</td></tr>',
				esc_attr( $item['status'] ),
				esc_html( $labels[ $type ][0] ),
				esc_html( $labels[ $type ][1] ),
				esc_attr( $icon ),
				esc_html( $status_label ),
				esc_html( $item['message'] ),
				'' !== $item['record'] ? '<br><code>' . esc_html( $item['record'] ) . '</code>' : ''
			);
		}

		echo '</tbody></table>';
	}
}
