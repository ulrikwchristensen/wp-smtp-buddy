<?php
/**
 * Turns off selected WordPress notification emails.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use SmtpBuddy\Options;

/**
 * Each control is a setting "controls.{key}" (true = send, the default) mapped to the core
 * filter that decides whether WordPress sends that email.
 */
final class EmailControls {

	public function __construct( private Options $options ) {}

	/**
	 * @return array<string, array{group: string, label: string, filter: string}>
	 */
	public static function controls(): array {
		return array(
			'comment_author'     => array(
				'group'  => __( 'Comments', 'wp-smtp-buddy' ),
				'label'  => __( 'New comment: notify the post author', 'wp-smtp-buddy' ),
				'filter' => 'notify_post_author',
			),
			'comment_moderation' => array(
				'group'  => __( 'Comments', 'wp-smtp-buddy' ),
				'label'  => __( 'Comment awaiting moderation: notify the moderators', 'wp-smtp-buddy' ),
				'filter' => 'notify_moderator',
			),
			'new_user_admin'     => array(
				'group'  => __( 'Users', 'wp-smtp-buddy' ),
				'label'  => __( 'New user registered: notify the site admin', 'wp-smtp-buddy' ),
				'filter' => 'wp_send_new_user_notification_to_admin',
			),
			'new_user_user'      => array(
				'group'  => __( 'Users', 'wp-smtp-buddy' ),
				'label'  => __( 'New user registered: send the user their login details', 'wp-smtp-buddy' ),
				'filter' => 'wp_send_new_user_notification_to_user',
			),
			'password_changed'   => array(
				'group'  => __( 'Users', 'wp-smtp-buddy' ),
				'label'  => __( 'Password changed: notify the user', 'wp-smtp-buddy' ),
				'filter' => 'send_password_change_email',
			),
			'email_changed'      => array(
				'group'  => __( 'Users', 'wp-smtp-buddy' ),
				'label'  => __( 'Email address changed: notify the user', 'wp-smtp-buddy' ),
				'filter' => 'send_email_change_email',
			),
			'core_update'        => array(
				'group'  => __( 'Automatic updates', 'wp-smtp-buddy' ),
				'label'  => __( 'WordPress core was updated automatically', 'wp-smtp-buddy' ),
				'filter' => 'auto_core_update_send_email',
			),
			'plugin_update'      => array(
				'group'  => __( 'Automatic updates', 'wp-smtp-buddy' ),
				'label'  => __( 'Plugins were updated automatically', 'wp-smtp-buddy' ),
				'filter' => 'auto_plugin_update_send_email',
			),
			'theme_update'       => array(
				'group'  => __( 'Automatic updates', 'wp-smtp-buddy' ),
				'label'  => __( 'Themes were updated automatically', 'wp-smtp-buddy' ),
				'filter' => 'auto_theme_update_send_email',
			),
		);
	}

	public function register(): void {
		foreach ( self::controls() as $key => $control ) {
			// Late priority so the setting wins over other plugins' defaults.
			add_filter( $control['filter'], fn ( $send ) => $this->options->get( "controls.{$key}", true ) ? $send : false, PHP_INT_MAX );
		}
	}
}
