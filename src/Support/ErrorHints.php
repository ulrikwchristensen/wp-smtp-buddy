<?php
/**
 * Turns raw mailer errors into actionable advice.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

final class ErrorHints {

	/**
	 * Returns advice for the first matching known problem, or null.
	 */
	public static function explain( string $error, string $mailer = '' ): ?string {
		foreach ( self::rules() as $rule ) {
			if ( ( '' === $rule[0] || $rule[0] === $mailer ) && preg_match( $rule[1], $error ) ) {
				return $rule[2];
			}
		}

		return null;
	}

	/**
	 * @return list<array{0: string, 1: string, 2: string}> Mailer slug ('' = any), pattern, hint.
	 */
	private static function rules(): array {
		return array(
			// SMTP.
			array( 'smtp', '/certificate|ssl3_get_server_certificate|SSL operation failed|crypto enabling/i', __( 'The TLS/SSL handshake failed. The server certificate may be invalid or self-signed, or the encryption type doesn\'t match the port (SSL ↔ 465, TLS ↔ 587). Try the other encryption option. As a last resort, turn off Auto TLS.', 'wp-smtp-buddy' ) ),
			array( 'smtp', '/Could not authenticate|535|534|Username and Password not accepted|authentication failed/i', __( 'The SMTP server rejected the username or password. Check them for typos. Gmail and Microsoft 365 require an app password when two-step verification is on, and some providers need SMTP access enabled in the account settings.', 'wp-smtp-buddy' ) ),
			array( 'smtp', '/Could not connect to SMTP host|Failed to connect|Connection (refused|timed out)|getaddrinfo|php_network_getaddresses|SMTP connect\(\) failed/i', __( 'WordPress couldn\'t reach the SMTP server. Check the host name and port. Many hosts block outgoing ports 25, 465 and 587. Ask your host to open the port, or switch to an API mailer (such as Amazon SES, Brevo, SendGrid or Postmark), which sends over HTTPS.', 'wp-smtp-buddy' ) ),
			array( 'smtp', '/Sender address rejected|not owned by user|5\.7\.1|550 5\.7/i', __( 'The server refused the From address. Most providers only allow sending from the address (or domain) you authenticate with. Set the From Email to that address and turn on "Force From Email".', 'wp-smtp-buddy' ) ),

			// SendGrid.
			array( 'sendgrid', '/verified Sender Identity|does not match a verified/i', __( 'The From Email isn\'t verified in SendGrid. Verify it under Settings → Sender Authentication (single sender or domain authentication), then try again.', 'wp-smtp-buddy' ) ),
			array( 'sendgrid', '/HTTP 401|HTTP 403|authorization grant is invalid|permissions? (needed|to access)/i', __( 'SendGrid rejected the API key. Make sure you copied the whole key and that it has "Mail Send" permission.', 'wp-smtp-buddy' ) ),

			// Mailgun.
			array( 'mailgun', '/Domain not found|HTTP 404/i', __( 'Mailgun doesn\'t know this domain. Check the sending domain spelling and that the Region (US/EU) matches where the domain was created.', 'wp-smtp-buddy' ) ),
			array( 'mailgun', '/HTTP 401|Forbidden/i', __( 'Mailgun rejected the API key. Use a sending key for this domain or your private API key, and check that the Region (US/EU) is right.', 'wp-smtp-buddy' ) ),

			// Postmark.
			array( 'postmark', '/error code 400|Sender Signature/i', __( 'The From Email has no confirmed sender signature in Postmark. Add and confirm it, or verify the whole domain, under Sender Signatures.', 'wp-smtp-buddy' ) ),
			array( 'postmark', '/error code 412|pending approval/i', __( 'Your Postmark account is pending approval. Until it\'s approved you can only send to addresses on your own domain.', 'wp-smtp-buddy' ) ),
			array( 'postmark', '/HTTP 401|error code 10\b/i', __( 'Postmark rejected the server token. Use a Server API token (not the Account token).', 'wp-smtp-buddy' ) ),

			// MailerSend.
			array( 'mailersend', '/from\.email|domain must be verified|not verified/i', __( 'The From Email must be on a domain verified in MailerSend. Verify the domain under Domains, then use an address on it.', 'wp-smtp-buddy' ) ),
			array( 'mailersend', '/HTTP 401|Unauthenticated/i', __( 'MailerSend rejected the API token. Check that it was copied completely and has Email access.', 'wp-smtp-buddy' ) ),
			array( 'mailersend', '/HTTP 429|limit/i', __( 'MailerSend\'s sending limit or rate limit was reached. Check your plan\'s quota in the MailerSend dashboard.', 'wp-smtp-buddy' ) ),

			// Amazon SES.
			array( 'ses', '/not verified|MessageRejected/i', __( 'Amazon SES rejected the address. Verify the From domain (or address) in SES in the selected region. While your account is in the SES sandbox, recipients must be verified too. Request production access to send to anyone.', 'wp-smtp-buddy' ) ),
			array( 'ses', '/SignatureDoesNotMatch|InvalidClientTokenId|UnrecognizedClient|security token/i', __( 'AWS rejected the access keys. Check the access key ID and secret access key for typos and that the IAM user is active.', 'wp-smtp-buddy' ) ),
			array( 'ses', '/AccessDenied|not authorized/i', __( 'The IAM user isn\'t allowed to send. Attach a policy that allows ses:SendRawEmail (or ses:SendEmail).', 'wp-smtp-buddy' ) ),
			array( 'ses', '/Throttling|TooManyRequests|HTTP 429|sending quota|Maximum sending rate/i', __( 'Your SES sending quota or rate was exceeded. Check the limits under Account dashboard in the SES console and request an increase if needed.', 'wp-smtp-buddy' ) ),
			array( 'ses', '/ConfigurationSet/i', __( 'The configuration set doesn\'t exist in this region. Check its name or leave the field empty.', 'wp-smtp-buddy' ) ),

			// Brevo.
			array( 'brevo', '/HTTP 401|unauthorized|Key not found/i', __( 'Brevo rejected the API key. Use an API key (starts with "xkeysib-"), not the SMTP key, and check that it was copied completely.', 'wp-smtp-buddy' ) ),
			array( 'brevo', '/sender.*(not valid|invalid)|not a valid sender/i', __( 'The From Email isn\'t a verified sender in Brevo. Add it under Senders, Domains & Dedicated IPs, or authenticate the domain.', 'wp-smtp-buddy' ) ),
			array( 'brevo', '/permission_denied|not activated|account.*(suspended|under validation)/i', __( 'Your Brevo account can\'t send transactional email yet. Brevo may need to activate it. Contact Brevo support.', 'wp-smtp-buddy' ) ),

			// Mailjet.
			array( 'mailjet', '/HTTP 401|authentication|authorization failure/i', __( 'Mailjet rejected the API key and secret key. Check both, and that the Region matches your account.', 'wp-smtp-buddy' ) ),
			array( 'mailjet', '/sender.*(not|un)validated|is not an authorized sender|send-as/i', __( 'The From Email isn\'t validated in Mailjet. Add it (or its domain) under Account settings → Sender addresses & domains.', 'wp-smtp-buddy' ) ),

			// Resend.
			array( 'resend', '/domain is not verified|verify a domain|testing emails/i', __( 'The From domain isn\'t verified in Resend. Add and verify it under Domains. Until then, Resend only lets you send to your own address.', 'wp-smtp-buddy' ) ),
			array( 'resend', '/HTTP 401|HTTP 403|API key is invalid|restricted_api_key|missing_api_key/i', __( 'Resend rejected the API key. Use a key with sending access and check that it was copied completely.', 'wp-smtp-buddy' ) ),

			// SMTP2GO.
			array( 'smtp2go', '/sender.*(not|un)verified|unauthorised sender|E_ApiResponseCodes.NON_VALIDATED_SENDER|sender domain/i', __( 'The From Email isn\'t a verified sender in SMTP2GO. Verify the domain or address under Sending → Verified Senders.', 'wp-smtp-buddy' ) ),
			array( 'smtp2go', '/HTTP 401|API_KEY|api key/i', __( 'SMTP2GO rejected the API key. Check that it was copied completely and has permission to send emails.', 'wp-smtp-buddy' ) ),

			// Google Workspace / Gmail.
			array( 'gmail', '/invalid_grant|expired or revoked/i', __( 'Google no longer accepts the saved sign-in. It was revoked, the password changed, or the app is in "Testing" mode (connections expire after 7 days there). Set the app to "In production" and sign in again.', 'wp-smtp-buddy' ) ),
			array( 'gmail', '/Gmail API has not been used|SERVICE_DISABLED|accessNotConfigured/i', __( 'The Gmail API is not enabled in your Google Cloud project. Enable it under APIs & Services → Library → Gmail API, wait a minute, and try again.', 'wp-smtp-buddy' ) ),
			array( 'gmail', '/invalid_client|unauthorized_client/i', __( 'Google rejected the client ID or secret. Copy both again from the Google Cloud console (Clients → your web client).', 'wp-smtp-buddy' ) ),
			array( 'gmail', '/insufficientPermissions|insufficient authentication scopes|ACCESS_TOKEN_SCOPE_INSUFFICIENT/i', __( 'The connection doesn\'t have permission to send email. Add the gmail.send scope to the app, then disconnect and sign in again.', 'wp-smtp-buddy' ) ),
			array( 'gmail', '/failedPrecondition|Mail service not enabled/i', __( 'Gmail is not enabled for this Google account. In Google Workspace, an admin must turn on Gmail for the user.', 'wp-smtp-buddy' ) ),

			// Microsoft 365.
			array( 'microsoft', '/invalid_grant|AADSTS70008|AADSTS700082|AADSTS50173/i', __( 'Microsoft no longer accepts the saved sign-in (expired or revoked, for example after a password change). Sign in again.', 'wp-smtp-buddy' ) ),
			array( 'microsoft', '/AADSTS7000215|AADSTS7000222|invalid_client/i', __( 'Microsoft rejected the client secret. It may have expired. Create a new secret under Certificates & secrets, copy its Value (not the ID), save, and sign in again.', 'wp-smtp-buddy' ) ),
			array( 'microsoft', '/AADSTS65001|ErrorAccessDenied|Access is denied|consent/i', __( 'The app may not send email for this mailbox. Add the Microsoft Graph delegated permission Mail.Send, have an admin grant consent if required, then sign in again.', 'wp-smtp-buddy' ) ),
			array( 'microsoft', '/MailboxNotEnabledForRESTAPI|MailboxNotFound|REST API is not yet supported/i', __( 'This account has no Exchange Online mailbox (or no license that includes one). Use an account with a Microsoft 365 mailbox.', 'wp-smtp-buddy' ) ),
			array( 'microsoft', '/ErrorSendAsDenied|SendAs/i', __( 'The mailbox isn\'t allowed to send as the From Email. Set the From Email to the connected account, or give it "Send As" permission in Exchange.', 'wp-smtp-buddy' ) ),

			// Any API mailer.
			array( '', '/cURL error 28|timed out/i', __( 'The request to the email service timed out. Your server may block outgoing HTTPS requests, or the service is having an outage.', 'wp-smtp-buddy' ) ),
			array( '', '/cURL error 6|Could not resolve host/i', __( 'Your server couldn\'t resolve the email service\'s host name. This is a DNS problem on the server. Contact your host.', 'wp-smtp-buddy' ) ),
			array( '', '/is not connected/i', __( 'Sign in to the account in the mailer settings (Connection → Sign in).', 'wp-smtp-buddy' ) ),
			array( '', '/Invalid address|You must provide at least one recipient/i', __( 'An email address is invalid. Check the From Email setting and the recipient address.', 'wp-smtp-buddy' ) ),
		);
	}
}
