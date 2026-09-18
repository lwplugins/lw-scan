<?php
/**
 * Accumulated run counters — the `runs.stats` JSON blob.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * Builds/reads the `runs.stats` JSON shape (spec §10.6). A tick rehydrates
 * one of these from the previous tick's stored array (`from_array()`),
 * records what happened this tick, and writes the result back with
 * `to_array()` — so counters and phase timings accumulate across the whole
 * run rather than resetting every tick. `to_array()` always returns the
 * full shape with every field zero-filled, even for a run that hasn't done
 * anything yet, so callers never need `isset()` checks.
 */
final class RunStats {

	/**
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'phases'         => [],
		'files'          => [
			'indexed'       => 0,
			'hashed'        => 0,
			'known_good'    => 0,
			'scanned'       => 0,
			'heuristics'    => 0,
			'large_skipped' => 0,
			'deleted'       => 0,
			'preg_errors'   => 0,
		],
		'db'             => [
			'options'  => 0,
			'posts'    => 0,
			'meta'     => 0,
			'users'    => 0,
			'triggers' => 0,
			'skipped'  => [],
		],
		'vuln'           => [
			'software' => 0,
			'lookups'  => 0,
			'skipped'  => 0,
		],
		'findings'       => [
			'new'        => 0,
			'updated'    => 0,
			'alerts_new' => 0,
			'review_new' => 0,
		],
		'remote'         => [
			'calls'     => 0,
			'errors'    => 0,
			'not_found' => 0,
		],
		'bundle_version' => 0,
		'budget_s'       => 0,
		'ticks'          => 0,
		'peak_memory'    => 0,
	];

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @var array<string, float> In-progress phase_start() timestamps, keyed by phase. Not persisted — a phase_start()/phase_end() pair is expected to happen within one tick's lifetime.
	 */
	private array $started_at = [];

	/**
	 * @param array<string, mixed> $init Previously stored stats (e.g. from `to_array()`), merged onto the zero-filled defaults.
	 */
	public function __construct( array $init = [] ) {
		$this->data = self::deep_merge( self::DEFAULTS, $init );
	}

	/**
	 * @param array<string, mixed> $stats Previous tick's `to_array()` output.
	 */
	public static function from_array( array $stats ): self {
		return new self( $stats );
	}

	public function phase_start( string $phase ): void {
		$this->started_at[ $phase ] = microtime( true );
	}

	public function phase_end( string $phase, int $items = 0 ): void {
		$elapsed_ms = 0;

		if ( isset( $this->started_at[ $phase ] ) ) {
			$elapsed_ms = (int) round( ( microtime( true ) - $this->started_at[ $phase ] ) * 1000 );
			unset( $this->started_at[ $phase ] );
		}

		$current = $this->data['phases'][ $phase ] ?? [
			'ms'    => 0,
			'items' => 0,
		];

		$this->data['phases'][ $phase ] = [
			'ms'    => $current['ms'] + $elapsed_ms,
			'items' => $current['items'] + $items,
		];
	}

	/**
	 * @param string $path Dotted path into the stats shape, e.g. `files.hashed`.
	 * @param int    $by   Amount to add.
	 */
	public function inc( string $path, int $by = 1 ): void {
		$ref = &$this->navigate_to_leaf( $path );
		$ref = ( is_int( $ref ) ? $ref : 0 ) + $by;
	}

	/**
	 * Keeps the larger of what is stored and `$value`. For the figures that
	 * describe a whole run's worst case rather than its latest one:
	 * `memory_get_peak_usage()` is per process, so the last tick's reading
	 * -- usually the quietest, it only finalizes -- would otherwise be all
	 * the run remembered about how close it came to the memory limit.
	 *
	 * @param string $path  Dotted path into the stats shape, e.g. `peak_memory`.
	 * @param int    $value Candidate value.
	 */
	public function max( string $path, int $value ): void {
		$ref = &$this->navigate_to_leaf( $path );
		$ref = max( is_int( $ref ) ? $ref : 0, $value );
	}

	/**
	 * @param string $path  Dotted path into the stats shape, e.g. `bundle_version` or `db.skipped`.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $path, $value ): void {
		$ref = &$this->navigate_to_leaf( $path );
		$ref = $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Walks a dotted path (e.g. `files.hashed`) into `$this->data`,
	 * creating intermediate arrays as needed, and returns a reference to
	 * the leaf so `inc()`/`set()` can read/write it in place.
	 *
	 * @param string $path Dotted path, e.g. `files.hashed`.
	 * @return mixed
	 */
	private function &navigate_to_leaf( string $path ) {
		$segments = explode( '.', $path );
		$last     = array_pop( $segments );
		$ref      = &$this->data;

		foreach ( $segments as $segment ) {
			if ( ! isset( $ref[ $segment ] ) || ! is_array( $ref[ $segment ] ) ) {
				$ref[ $segment ] = [];
			}

			$ref = &$ref[ $segment ];
		}

		if ( ! array_key_exists( $last, $ref ) ) {
			$ref[ $last ] = null;
		}

		return $ref[ $last ];
	}

	/**
	 * Recursively merges `$overrides` onto `$defaults`, keeping any default
	 * key `$overrides` doesn't touch. Both sides must be associative arrays
	 * for a key to recurse; anything else is replaced wholesale.
	 *
	 * @param array<string, mixed> $defaults  Zero-filled base shape.
	 * @param array<string, mixed> $overrides Values to merge on top.
	 * @return array<string, mixed>
	 */
	private static function deep_merge( array $defaults, array $overrides ): array {
		foreach ( $overrides as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
				$defaults[ $key ] = self::deep_merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}

		return $defaults;
	}
}
