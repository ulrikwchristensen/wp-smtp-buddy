<?php
/**
 * Amazon SES API v2, sending the raw MIME message so attachments, inline images and custom
 * headers are delivered exactly as WordPress built them.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Mail\Mailers;

use SmtpBuddy\Mail\AbstractApiMailer;
use SmtpBuddy\Mail\Message;
use SmtpBuddy\Mail\SendResult;
use SmtpBuddy\Support\AwsSigV4;

final class AmazonSes extends AbstractApiMailer {

	private const REGIONS = array(
		'us-east-1'      => 'US East (N. Virginia)',
		'us-east-2'      => 'US East (Ohio)',
		'us-west-1'      => 'US West (N. California)',
		'us-west-2'      => 'US West (Oregon)',
		'ca-central-1'   => 'Canada (Central)',
		'sa-east-1'      => 'South America (São Paulo)',
		'eu-west-1'      => 'Europe (Ireland)',
		'eu-west-2'      => 'Europe (London)',
		'eu-west-3'      => 'Europe (Paris)',
		'eu-central-1'   => 'Europe (Frankfurt)',
		'eu-central-2'   => 'Europe (Zurich)',
		'eu-north-1'     => 'Europe (Stockholm)',
		'eu-south-1'     => 'Europe (Milan)',
		'il-central-1'   => 'Israel (Tel Aviv)',
		'me-south-1'     => 'Middle East (Bahrain)',
		'me-central-1'   => 'Middle East (UAE)',
		'af-south-1'     => 'Africa (Cape Town)',
		'ap-south-1'     => 'Asia Pacific (Mumbai)',
		'ap-southeast-1' => 'Asia Pacific (Singapore)',
		'ap-southeast-2' => 'Asia Pacific (Sydney)',
		'ap-southeast-3' => 'Asia Pacific (Jakarta)',
		'ap-northeast-1' => 'Asia Pacific (Tokyo)',
		'ap-northeast-2' => 'Asia Pacific (Seoul)',
		'ap-northeast-3' => 'Asia Pacific (Osaka)',
	);

	public function slug(): string {
		return 'ses';
	}

	public function label(): string {
		return 'Amazon SES';
	}

	public function description(): string {
		return __( 'Amazon Simple Email Service. Verify your domain in SES and request production access to send to any address.', 'wp-smtp-buddy' );
	}

	public function fields(): array {
		return array(
			'access_key_id'     => array(
				'label'       => __( 'Access key ID', 'wp-smtp-buddy' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'From an IAM user that only has the ses:SendRawEmail permission.', 'wp-smtp-buddy' ),
			),
			'secret_access_key' => array(
				'label'    => __( 'Secret access key', 'wp-smtp-buddy' ),
				'type'     => 'password',
				'secret'   => true,
				'required' => true,
			),
			'region'            => array(
				'label'       => __( 'Region', 'wp-smtp-buddy' ),
				'type'        => 'select',
				'default'     => 'us-east-1',
				'options'     => self::REGIONS,
				'description' => __( 'The region where your domain is verified.', 'wp-smtp-buddy' ),
			),
			'configuration_set' => array(
				'label'       => __( 'Configuration set', 'wp-smtp-buddy' ),
				'type'        => 'text',
				'description' => __( 'Optional. For event publishing (bounces, opens) set up in SES.', 'wp-smtp-buddy' ),
			),
		);
	}

	public function build_request( Message $message ): array {
		$emails = static fn ( array $addresses ): array => array_column( $addresses, 'email' );

		$payload = array(
			'FromEmailAddress' => $message->from['email'],
			'Destination'      => array_filter(
				array(
					'ToAddresses'  => $emails( $message->to ),
					'CcAddresses'  => $emails( $message->cc ),
					'BccAddresses' => $emails( $message->bcc ),
				)
			),
			'Content'          => array(
				'Raw' => array(
					'Data' => base64_encode( $message->mime ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			),
		);

		$configuration_set = trim( (string) $this->setting( 'configuration_set' ) );

		if ( '' !== $configuration_set ) {
			$payload['ConfigurationSetName'] = $configuration_set;
		}

		$region = (string) $this->setting( 'region' );
		$region = isset( self::REGIONS[ $region ] ) ? $region : 'us-east-1';
		$url    = "https://email.{$region}.amazonaws.com/v2/email/outbound-emails"; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- SES API endpoint, not offloaded assets.
		$body   = (string) wp_json_encode( $payload );
		$signer = new AwsSigV4( trim( (string) $this->setting( 'access_key_id' ) ), (string) $this->setting( 'secret_access_key' ), $region, 'ses' );

		return array(
			'url'     => $url,
			'headers' => $signer->sign( 'POST', $url, $this->json_headers( array() ), $body ),
			'body'    => $body,
		);
	}

	public function parse_response( int $status, string $body, array $headers ): SendResult {
		$data = json_decode( $body, true );

		if ( 200 === $status ) {
			return SendResult::sent( (string) ( $data['MessageId'] ?? '' ) );
		}

		$type    = (string) preg_replace( '/:.*$/', '', $headers['x-amzn-errortype'] ?? '' );
		$message = is_array( $data ) ? (string) ( $data['message'] ?? $data['Message'] ?? '' ) : '';

		return $this->http_failure( $status, $body, trim( ( '' !== $type ? $type . ': ' : '' ) . $message ) );
	}
}
