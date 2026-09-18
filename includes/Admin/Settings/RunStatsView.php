<?php
/**
 * Read-only view over a run row's `stats` blob.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `Run\RunStats` is the writer; this is the reader the admin tables use.
 * Every getter tolerates a missing or half-written blob — a run that
 * failed in its first phase has almost nothing in there.
 */
final class RunStatsView {

	/**
	 * @var array<string, mixed>
	 */
	private array $stats;

	/**
	 * @param array<string, mixed> $stats Decoded `runs.stats`.
	 */
	private function __construct( array $stats ) {
		$this->stats = $stats;
	}

	/**
	 * @param array<string, mixed>|null $run Run row with `stats` already decoded.
	 */
	public static function of( ?array $run ): self {
		$stats = is_array( $run ) && is_array( $run['stats'] ?? null ) ? $run['stats'] : [];

		return new self( $stats );
	}

	public function files_indexed(): int {
		return $this->number( 'files', 'indexed' );
	}

	public function files_scanned(): int {
		return $this->number( 'files', 'scanned' );
	}

	public function alerts_new(): int {
		return $this->number( 'findings', 'alerts_new' );
	}

	public function review_new(): int {
		return $this->number( 'findings', 'review_new' );
	}

	/**
	 * The database rows the `db` phase looked at, across its scanners.
	 */
	public function db_rows(): int {
		$db  = is_array( $this->stats['db'] ?? null ) ? $this->stats['db'] : [];
		$sum = 0;

		foreach ( [ 'options', 'posts', 'meta', 'users', 'triggers' ] as $key ) {
			$sum += (int) ( $db[ $key ] ?? 0 );
		}

		return $sum;
	}

	public function software_checked(): int {
		return $this->number( 'vuln', 'software' );
	}

	/**
	 * "312 files · 241 DB rows · 28 packages" under the Deep-scanned tile.
	 */
	public function deep_scan_breakdown(): string {
		return sprintf(
			/* translators: 1: number of files. 2: number of database rows. 3: number of plugins/themes checked. */
			__( '%1$s files · %2$s DB rows · %3$s packages', 'lw-scan' ),
			Format::number( $this->files_scanned() ),
			Format::number( $this->db_rows() ),
			Format::number( $this->software_checked() )
		);
	}

	private function number( string $group, string $key ): int {
		$values = is_array( $this->stats[ $group ] ?? null ) ? $this->stats[ $group ] : [];

		return (int) ( $values[ $key ] ?? 0 );
	}
}
