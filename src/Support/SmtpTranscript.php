<?php
/**
 * Collects PHPMailer SMTP debug output with credentials redacted.
 *
 * @package SmtpBuddy
 */

namespace SmtpBuddy\Support;

final class SmtpTranscript {

	/**
	 * @var list<string>
	 */
	private array $lines = array();

	private bool $in_auth = false;

	/**
	 * Use as PHPMailer's Debugoutput callable.
	 */
	public function __invoke( string $line, int $level = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- PHPMailer passes the level.
		foreach ( preg_split( '/\R/', rtrim( $line ) ) as $part ) {
			$this->lines[] = $this->redact( $part );
		}
	}

	public function text(): string {
		return implode( "\n", $this->lines );
	}

	/**
	 * Hides everything the client sends from the AUTH command until the server accepts or
	 * rejects it, since those lines carry base64-encoded usernames, passwords and tokens.
	 */
	private function redact( string $line ): string {
		if ( preg_match( '/^(.*CLIENT -> SERVER:\s*AUTH\s+\S+)\s*\S*/i', $line, $match ) ) {
			$this->in_auth = true;

			return $match[1] . ' [redacted]';
		}

		if ( $this->in_auth ) {
			if ( preg_match( '/SERVER -> CLIENT:\s*(235|[45]\d\d)/', $line ) ) {
				$this->in_auth = false;
			} elseif ( str_contains( $line, 'CLIENT -> SERVER:' ) ) {
				return preg_replace( '/(CLIENT -> SERVER:).*/', '$1 [redacted]', $line );
			}
		}

		return $line;
	}
}
