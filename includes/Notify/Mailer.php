<?php
/**
 * Sends run notification e-mails.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Notify;

use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Run\RunError;

defined( 'ABSPATH' ) || exit;

/**
 * Two triggers (spec §10.8): a batch of new findings after a run, and a
 * scheduled-run failure streak. Both share recipient resolution and the
 * link to the Findings tab; `format_finding_line()` is the pure, testable
 * piece that turns one finding row into a plain-text body line.
 *
 * The body line's fields are separated by `·`, as spec §10.8 writes them.
 * An em dash would read better were it not already inside the fields: a
 * vulnerability finding's reason is "Some Plugin XSS — installed 1.0,
 * patched in 1.1", so a line separated the same way cannot be split back
 * into its parts by eye or by anything else.
 */
final class Mailer {

	private const EXCERPT_MAX = 120;

	/**
	 * Mails the recipients about the new findings from one run, filtered
	 * by `options['notify_level']`. Sends nothing (and returns false) when
	 * the filtered list, or the recipient list, is empty.
	 *
	 * @param array<string, mixed>             $run      Run row (unused; kept for interface symmetry with send_failure_streak()).
	 * @param array<int, array<string, mixed>> $findings New findings, as returned by FindingsRepository::new_since().
	 * @param array<string, mixed>             $options  Plugin options (Options::all()).
	 * @return bool Whether wp_mail() reported success.
	 */
	public static function send_new_findings( array $run, array $findings, array $options ): bool {
		unset( $run );

		$level    = (string) ( $options['notify_level'] ?? 'alert' );
		$filtered = self::filter_by_level( $findings, $level );

		if ( [] === $filtered ) {
			return false;
		}

		$to = self::recipients( $options );

		if ( [] === $to ) {
			return false;
		}

		$count   = count( $filtered );
		$subject = self::subject( (string) get_bloginfo( 'name' ), $level, $count );

		$lines = array_map( [ self::class, 'format_finding_line' ], $filtered );
		$body  = implode( "\n", $lines ) . "\n\n" . self::findings_link();

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Mails the recipients that scheduled scans have failed three times in
	 * a row.
	 *
	 * @param array{error?: string, id?: int} $run     `['error' => ..., 'id' => ...]`, per FailureStreak::record_failure().
	 * @param array<string, mixed>            $options Plugin options (Options::all()).
	 * @return bool Whether wp_mail() reported success.
	 */
	public static function send_failure_streak( array $run, array $options ): bool {
		$to = self::recipients( $options );

		if ( [] === $to ) {
			return false;
		}

		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] LW Scan: scheduled scans are failing', 'lw-scan' ),
			(string) get_bloginfo( 'name' )
		);
		// The stored value is a machine code for some failures
		// (`out_of_memory`, `bundle_missing`); FailureStreak passes the code
		// alone, so this is the sentence without the run's own figures.
		$error = RunError::label( (string) ( $run['error'] ?? '' ) );

		$body = "Automated LW Scan runs have failed three times in a row. Notifications are paused until the next successful run.\n\n"
			. "Last error: {$error}\n\n"
			. self::findings_link();

		return (bool) wp_mail( $to, $subject, $body );
	}

	/**
	 * Builds one plain-text body line for a finding row. Pure — no WP
	 * calls — so it is directly unit-testable.
	 *
	 * @param array<string, mixed> $f A findings-table row (raw, as returned by new_since()).
	 * @return string
	 */
	public static function format_finding_line( array $f ): string {
		$severity = strtoupper( (string) ( $f['severity'] ?? '' ) );
		$type     = (string) ( $f['type'] ?? '' );
		$locator  = (string) ( $f['locator'] ?? '' );
		$tier     = (string) ( $f['tier'] ?? '' );
		$category = (string) ( $f['category'] ?? '' );

		$classification = '' === $category ? $tier : $tier . '/' . $category;

		$middle = 'vulnerability' === $type
			? (string) ( $f['reason'] ?? '' )
			: self::first_signature( $f );

		$excerpt = self::clean_excerpt( (string) ( $f['excerpt'] ?? '' ) );

		return sprintf( '[%s] %s · %s · %s · %s · %s', $severity, $type, $locator, $classification, $middle, $excerpt );
	}

	/**
	 * The first signature/heuristic id for a finding, decoding a
	 * JSON-encoded `signature_ids` column defensively (raw DB rows carry
	 * it as a string; already-decoded callers may pass an array). Falls
	 * back to the finding's reason when there is no signature id at all.
	 *
	 * @param array<string, mixed> $f Findings-table row.
	 */
	private static function first_signature( array $f ): string {
		$ids = $f['signature_ids'] ?? [];

		if ( is_string( $ids ) ) {
			$decoded = json_decode( $ids, true );
			$ids     = is_array( $decoded ) ? $decoded : [];
		}

		if ( ! is_array( $ids ) || [] === $ids ) {
			return (string) ( $f['reason'] ?? '' );
		}

		return (string) reset( $ids );
	}

	/**
	 * Strips control characters (including newlines/tabs) and caps the
	 * excerpt at EXCERPT_MAX bytes. Deliberately not HTML-escaped — the
	 * mail is plain text and may contain raw malware source.
	 *
	 * @param string $excerpt Raw excerpt.
	 */
	private static function clean_excerpt( string $excerpt ): string {
		$clean = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $excerpt );
		$clean = trim( $clean );

		return substr( $clean, 0, self::EXCERPT_MAX );
	}

	/**
	 * @param array<int, array<string, mixed>> $findings New findings.
	 * @param string                           $level    'alert'|'review'.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_by_level( array $findings, string $level ): array {
		if ( 'review' === $level ) {
			return array_values( $findings );
		}

		return array_values(
			array_filter(
				$findings,
				static function ( $f ) {
					return Severity::ALERT === ( $f['severity'] ?? '' );
				}
			)
		);
	}

	/**
	 * "[Example Site] LW Scan: 3 new alerts". Two `_n()` forms rather than a
	 * noun glued onto a sentence: a plural is not "add an s" in every
	 * language the plugin is translated into, and the two notify levels are
	 * reporting different things — alerts, or everything worth a look.
	 *
	 * @param string $site  Site name.
	 * @param string $level 'alert'|'review'.
	 * @param int    $count Number of findings in the mail.
	 */
	private static function subject( string $site, string $level, int $count ): string {
		if ( 'review' === $level ) {
			return sprintf(
				/* translators: 1: site name. 2: number of new findings. */
				_n( '[%1$s] LW Scan: %2$d new finding', '[%1$s] LW Scan: %2$d new findings', $count, 'lw-scan' ),
				$site,
				$count
			);
		}

		return sprintf(
			/* translators: 1: site name. 2: number of new alerts. */
			_n( '[%1$s] LW Scan: %2$d new alert', '[%1$s] LW Scan: %2$d new alerts', $count, 'lw-scan' ),
			$site,
			$count
		);
	}

	/**
	 * The recipient list: `options['notify_emails']` when non-empty,
	 * otherwise the site's admin_email, filtered down to addresses
	 * is_email() accepts.
	 *
	 * @param array<string, mixed> $options Plugin options.
	 * @return string[]
	 */
	private static function recipients( array $options ): array {
		$emails = $options['notify_emails'] ?? [];

		if ( ! is_array( $emails ) || [] === $emails ) {
			$admin  = get_option( 'admin_email' );
			$emails = false !== $admin ? [ $admin ] : [];
		}

		$valid = [];
		foreach ( $emails as $email ) {
			$email = (string) $email;

			if ( is_email( $email ) ) {
				$valid[] = $email;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	private static function findings_link(): string {
		return (string) admin_url( 'admin.php?page=lw-scan&tab=findings' );
	}
}
