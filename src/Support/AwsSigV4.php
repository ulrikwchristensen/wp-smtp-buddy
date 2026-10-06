<?php
/**
 * AWS Signature Version 4 request signing (no AWS SDK needed).
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

/**
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final class AwsSigV4 {

	public function __construct(
		private string $access_key,
		private string $secret_key,
		private string $region,
		private string $service,
	) {}

	/**
	 * Returns the request headers with Host, X-Amz-Date and Authorization added.
	 *
	 * @param array<string, string> $headers Headers to send (all of them are signed).
	 * @return array<string, string>
	 */
	public function sign( string $method, string $url, array $headers, string $body, ?int $time = null ): array {
		$time     = $time ?? time();
		$amz_date = gmdate( 'Ymd\THis\Z', $time );
		$date     = substr( $amz_date, 0, 8 );
		$parts    = (array) wp_parse_url( $url );
		$host     = (string) ( $parts['host'] ?? '' );

		$headers['Host']       = $host;
		$headers['X-Amz-Date'] = $amz_date;

		$canonical_headers = array();

		foreach ( $headers as $name => $value ) {
			$canonical_headers[ strtolower( $name ) ] = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		}

		ksort( $canonical_headers );

		$signed_headers = implode( ';', array_keys( $canonical_headers ) );
		$header_block   = '';

		foreach ( $canonical_headers as $name => $value ) {
			$header_block .= $name . ':' . $value . "\n";
		}

		$canonical_request = implode(
			"\n",
			array(
				strtoupper( $method ),
				$this->canonical_path( (string) ( $parts['path'] ?? '/' ) ),
				$this->canonical_query( (string) ( $parts['query'] ?? '' ) ),
				$header_block,
				$signed_headers,
				hash( 'sha256', $body ),
			)
		);

		$scope          = "{$date}/{$this->region}/{$this->service}/aws4_request";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );

		$key = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', $this->service, $key, true );
		$key = hash_hmac( 'sha256', 'aws4_request', $key, true );

		$headers['Authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$this->access_key,
			$scope,
			$signed_headers,
			hash_hmac( 'sha256', $string_to_sign, $key )
		);

		return $headers;
	}

	private function canonical_path( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}

		return implode( '/', array_map( 'rawurlencode', array_map( 'rawurldecode', explode( '/', $path ) ) ) );
	}

	private function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}

		$pairs = array();

		foreach ( explode( '&', $query ) as $pair ) {
			[ $name, $value ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$pairs[]          = array( rawurlencode( rawurldecode( $name ) ), rawurlencode( rawurldecode( $value ) ) );
		}

		sort( $pairs );

		return implode( '&', array_map( static fn ( array $pair ): string => $pair[0] . '=' . $pair[1], $pairs ) );
	}
}
