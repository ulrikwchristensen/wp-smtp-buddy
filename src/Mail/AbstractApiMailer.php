<?php
/**
 * Base class for mailers that deliver through an HTTP API.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail;

use PHPMailer\PHPMailer\PHPMailer;

abstract class AbstractApiMailer extends AbstractMailer {

	/**
	 * Builds the HTTP request for a message.
	 *
	 * @return array{url: string, headers: array<string, string>, body: string}
	 */
	abstract public function build_request( Message $message ): array;

	/**
	 * Converts the provider's response into a result.
	 *
	 * @param array<string, string> $headers Lower-cased response headers.
	 */
	abstract public function parse_response( int $status, string $body, array $headers ): SendResult;

	/**
	 * SMTP mode makes PHPMailer put To and Subject into the MIME headers and leave Bcc out,
	 * which is what providers expect from a raw message. No SMTP connection is made because
	 * postSend() is never called for API mailers.
	 */
	public function prepare( PHPMailer $mail ): void {
		$mail->isSMTP();
	}

	public function send( PHPMailer $mail ): SendResult {
		$message = Message::from_phpmailer( $mail );

		/**
		 * Filters the HTTP request sent to an API mailer.
		 *
		 * @param array   $request { url, headers, body }.
		 * @param Message $message The message being sent.
		 */
		$request = apply_filters( 'wpsb_request_' . $this->slug(), $this->build_request( $message ), $message );

		$response = wp_remote_post(
			$request['url'],
			array(
				'headers'     => $request['headers'],
				'body'        => $request['body'],
				'timeout'     => (int) apply_filters( 'wpsb_api_timeout', 15, $this->slug() ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return SendResult::failed( $response->get_error_message(), array( 'wp_error' => $response->get_error_code() ) );
		}

		$headers = array_change_key_case( wp_remote_retrieve_headers( $response )->getAll() );

		return $this->parse_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			array_map( static fn ( $value ): string => is_array( $value ) ? (string) reset( $value ) : (string) $value, $headers )
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function json_headers( array $extra ): array {
		return array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			$extra
		);
	}

	/**
	 * Converts an address to the provider's object shape, leaving out an empty name.
	 *
	 * @param array{email: string, name: string} $address
	 * @return array<string, string>
	 */
	protected function address_object( array $address, string $email_key = 'email', string $name_key = 'name' ): array {
		$out = array( $email_key => $address['email'] );

		if ( '' !== $address['name'] ) {
			$out[ $name_key ] = $address['name'];
		}

		return $out;
	}

	/**
	 * @param list<array{email: string, name: string}> $addresses
	 * @return list<array<string, string>>
	 */
	protected function address_objects( array $addresses, string $email_key = 'email', string $name_key = 'name' ): array {
		return array_map( fn ( array $address ): array => $this->address_object( $address, $email_key, $name_key ), $addresses );
	}

	/**
	 * Builds a generic failure from an HTTP response, using the provider's message when present.
	 */
	protected function http_failure( int $status, string $body, string $provider_message = '' ): SendResult {
		if ( '' === $provider_message ) {
			$provider_message = '' !== trim( $body ) ? wp_strip_all_tags( substr( $body, 0, 300 ) ) : 'Empty response.';
		}

		return SendResult::failed(
			sprintf( '%s API error (HTTP %d): %s', $this->label(), $status, $provider_message ),
			array( 'http_status' => $status )
		);
	}

	/**
	 * Encodes form fields and files as multipart/form-data.
	 *
	 * @param array<int, array{name: string, value: string, filename?: string, type?: string}> $parts
	 * @return array{body: string, content_type: string}
	 */
	protected function multipart( array $parts ): array {
		$boundary = 'wpsb-' . bin2hex( random_bytes( 12 ) );
		$body     = '';

		foreach ( $parts as $part ) {
			$body .= "--{$boundary}\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $part['name'] . '"';

			if ( isset( $part['filename'] ) ) {
				$body .= '; filename="' . $part['filename'] . '"';
			}

			$body .= "\r\n";

			if ( isset( $part['type'] ) ) {
				$body .= 'Content-Type: ' . $part['type'] . "\r\n";
			}

			$body .= "\r\n" . $part['value'] . "\r\n";
		}

		return array(
			'body'         => $body . "--{$boundary}--\r\n",
			'content_type' => 'multipart/form-data; boundary=' . $boundary,
		);
	}
}
