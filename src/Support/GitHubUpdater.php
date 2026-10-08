<?php
/**
 * Supplies plugin update metadata from the project's GitHub releases.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

final class GitHubUpdater {

	private const REPOSITORY = 'ulrikwchristensen/wp-smtp-buddy';
	private const PLUGIN_SLUG = 'wp-smtp-buddy';

	public function register(): void {
		add_filter( 'plugins_api_result', array( $this, 'plugins_api_result' ), 10, 3 );
	}

	/**
	 * Replace the WordPress.org plugin information response for WP SMTP Buddy.
	 *
	 * @param mixed       $result
	 * @param string      $action
	 * @param array|object $args
	 * @return mixed
	 */
	public function plugins_api_result( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || self::PLUGIN_SLUG !== $this->requested_slug( $args ) ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! is_object( $release ) || empty( $release->tag_name ) ) {
			return $result;
		}

		$version = ltrim( (string) $release->tag_name, 'v' );
		$tag     = rawurlencode( (string) $release->tag_name );
		$archive = self::PLUGIN_SLUG . '-' . $version . '.zip';

		return (object) array(
			'name'          => 'WP SMTP Buddy',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $version,
			'author'        => 'Ulrik',
			'author_url'    => 'https://github.com/ulrikwchristensen',
			'homepage'      => 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . $release->tag_name,
			'downloadlink'  => 'https://github.com/' . self::REPOSITORY . '/releases/download/' . $tag . '/' . $archive,
			'last_updated'  => (string) ( $release->published_at ?? '' ),
			'new_version'   => $version,
			'compatibility' => array(),
		);
	}

	/**
	 * @param array|object $args
	 */
	private function requested_slug( $args ): string {
		if ( is_array( $args ) ) {
			return isset( $args['slug'] ) ? (string) $args['slug'] : '';
		}

		return isset( $args->slug ) ? (string) $args->slug : '';
	}

	/**
	 * @return object|null
	 */
	private function latest_release(): ?object {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WP-SMTP-Buddy/1.0.0',
				),
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) || ! is_object( $response ) || empty( $response->body ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			return null;
		}

		try {
			$release = json_decode( $body, false, 512, JSON_THROW_ON_ERROR );
			return is_object( $release ) ? $release : null;
		} catch ( \JsonException $exception ) {
			return null;
		}
	}
}
