<?php
/**
 * Builds the `file` finding for one scanned file.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Pure translation from a file row plus its `ScanOutcome` to the single
 * `file` finding that path is entitled to (spec §9) — the file-pipeline
 * counterpart of `DbScan\DbFinding`. Path-signal reasons are passed in
 * rather than computed here, so this class needs neither the checksum
 * provider nor the filesystem.
 */
final class FileFinding {

	/** Reason text for a PHP file that exceeded `max_file_size`. */
	public const LARGE_PHP_REASON = 'PHP file larger than the scan limit; not inspected';

	/** Signature id `Scanner\FileScanner` marks an unread oversized PHP file with. */
	public const LARGE_PHP_SIG = 'skip:large_php';

	/**
	 * @param array<string, mixed> $row            File row (`id`, `path`, `path_signal`).
	 * @param ScanOutcome          $outcome        Scan result with at least one match or heuristic hit.
	 * @param array<int, string>   $signal_reasons Path-signal reason keys for the row.
	 */
	public static function build( array $row, ScanOutcome $outcome, array $signal_reasons ): Finding {
		$tier  = $outcome->max_tier();
		$first = $outcome->matches[0] ?? null;
		$heur  = $outcome->heuristic[0] ?? null;

		$finding = self::base( $row, $tier, $signal_reasons );

		$finding->signature_ids   = self::signature_ids( $outcome );
		$finding->category        = (string) ( null !== $first ? $first->category : ( $heur['category'] ?? '' ) );
		$finding->excerpt         = (string) ( null !== $first ? $first->excerpt : ( $heur['excerpt'] ?? '' ) );
		$finding->line            = (int) ( null !== $first ? $first->line : ( $heur['line'] ?? 0 ) );
		$finding->reason          = self::reason( $outcome, $signal_reasons );
		$finding->meta['matches'] = self::match_rows( $outcome );

		return $finding;
	}

	/**
	 * The review finding for a PHP file too big to inspect (spec §6.6).
	 * Nothing in the file was read, so its path signals alone must never
	 * push it past `review` — an uninspected file is a lead, not an alert.
	 *
	 * @param array<string, mixed> $row            File row (`id`, `path`, `path_signal`).
	 * @param array<int, string>   $signal_reasons Path-signal reason keys for the row.
	 */
	public static function large_php( array $row, array $signal_reasons ): Finding {
		$finding = self::base( $row, 'suspicious', $signal_reasons );

		$finding->signature_ids = [ self::LARGE_PHP_SIG ];
		$finding->category      = 'unknown';
		$finding->reason        = self::LARGE_PHP_REASON;
		$finding->severity      = Severity::REVIEW;

		return $finding;
	}

	/**
	 * @param array<string, mixed> $row            File row.
	 * @param string               $tier           Finding tier.
	 * @param array<int, string>   $signal_reasons Path-signal reason keys.
	 */
	private static function base( array $row, string $tier, array $signal_reasons ): Finding {
		$signal = (int) ( $row['path_signal'] ?? 0 );

		$finding = new Finding( 'file', (string) $row['path'], $tier, Severity::of( $tier, $signal, 'file' ) );

		$finding->file_id = (int) $row['id'];
		$finding->meta    = [
			'signal'         => $signal,
			'signal_reasons' => $signal_reasons,
			'matches'        => [],
		];

		return $finding;
	}

	/**
	 * @param ScanOutcome $outcome Scan result.
	 * @return string[] Signature ids from the matches plus every heuristic's own ids.
	 */
	private static function signature_ids( ScanOutcome $outcome ): array {
		$ids = [];

		foreach ( $outcome->matches as $match ) {
			$ids[] = $match->sig_id;
		}

		foreach ( $outcome->heuristic as $hit ) {
			foreach ( $hit['sig_ids'] as $sig_id ) {
				$ids[] = $sig_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Heuristic reasons first, then the path signals that raised this
	 * file's suspicion score.
	 *
	 * @param ScanOutcome        $outcome        Scan result.
	 * @param array<int, string> $signal_reasons Path-signal reason keys.
	 */
	private static function reason( ScanOutcome $outcome, array $signal_reasons ): string {
		$reasons = [];

		foreach ( $outcome->heuristic as $hit ) {
			$reasons[] = $hit['reason'];
		}

		$reason = implode( '; ', array_filter( $reasons ) );

		if ( [] === $signal_reasons ) {
			return $reason;
		}

		return $reason . ( '' === $reason ? '' : ' ' ) . '| path: ' . implode( ', ', $signal_reasons );
	}

	/**
	 * @param ScanOutcome $outcome Scan result.
	 * @return array<int, array<string, mixed>>
	 */
	private static function match_rows( ScanOutcome $outcome ): array {
		$rows = [];

		foreach ( $outcome->matches as $match ) {
			$rows[] = $match->to_array();
		}

		return $rows;
	}
}
