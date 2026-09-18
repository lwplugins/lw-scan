<?php
/**
 * Persisted, resumable progress through the scan phase pipeline.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Array-backed cursor persisted as JSON under `State::get('run')` (spec
 * §10.3). Besides the spec fields it also carries the phase list captured
 * at `fresh()` time — so `next_phase()` can walk it across ticks/requests
 * without recomputing `Phases::for_scope()` — and an opaque `resume` slot
 * that `Scanner\FileScanner` fills in and reads back verbatim (see
 * `Scanner\ScanOutcome::to_resume()`/`from_resume()`); Cursor never
 * inspects `resume`'s contents.
 */
final class Cursor {

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @param array<string, mixed> $data Stored cursor fields.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Starts a fresh cursor on the first phase of `$phases`.
	 *
	 * @param int                $run_id Run id from `Db\RunsRepository::create()`.
	 * @param string             $scope  full|changed|db|path.
	 * @param string             $path   Scope path (scope=path only), else ''.
	 * @param array<int, string> $phases Ordered phase list for this run's scope (`Phases::for_scope()`).
	 */
	public static function fresh( int $run_id, string $scope, string $path, array $phases ): self {
		$phases = array_values( $phases );

		return new self(
			[
				'run_id'         => $run_id,
				'scope'          => $scope,
				'path'           => $path,
				'phases'         => $phases,
				'phase'          => $phases[0] ?? '',
				'file_id'        => null,
				'chunk_index'    => 0,
				'regex_index'    => 0,
				'resume'         => [],
				'db'             => self::fresh_db_subcursor(),
				'vuln_index'     => 0,
				'index_done'     => false,
				'hash_last_id'   => 0,
				'files_total'    => 0,
				'files_done'     => 0,
				'started_at'     => time(),
				'stop_requested' => false,
			]
		);
	}

	/**
	 * Loads the cursor stored under `State::get('run')`, or null when no
	 * run is currently in progress.
	 */
	public static function load(): ?self {
		$data = State::get( 'run' );

		return is_array( $data ) ? new self( $data ) : null;
	}

	public function save(): void {
		State::set( 'run', $this->data );
	}

	/**
	 * Removes whatever cursor is currently stored.
	 */
	public static function clear(): void {
		State::set( 'run', null );
	}

	public function run_id(): int {
		return (int) ( $this->data['run_id'] ?? 0 );
	}

	public function scope(): string {
		return (string) ( $this->data['scope'] ?? '' );
	}

	public function path(): string {
		return (string) ( $this->data['path'] ?? '' );
	}

	public function started_at(): int {
		return (int) ( $this->data['started_at'] ?? 0 );
	}

	public function stop_requested(): bool {
		return (bool) ( $this->data['stop_requested'] ?? false );
	}

	public function phase(): string {
		return (string) ( $this->data['phase'] ?? '' );
	}

	/**
	 * Sets the current phase and resets the phase-specific sub-cursors —
	 * `file_id`, `chunk_index`, `regex_index`, `resume`, `db`,
	 * `vuln_index` — so the new phase starts from a clean slate. Fields
	 * shared across the whole run (`hash_last_id`, `index_done`,
	 * `files_total`/`files_done`, `started_at`, `stop_requested`, and the
	 * run identity fields) are left untouched.
	 *
	 * @param string $phase Phase name from the stored `phases` list.
	 */
	public function set_phase( string $phase ): void {
		$this->data['phase']       = $phase;
		$this->data['file_id']     = null;
		$this->data['chunk_index'] = 0;
		$this->data['regex_index'] = 0;
		$this->data['resume']      = [];
		$this->data['db']          = self::fresh_db_subcursor();
		$this->data['vuln_index']  = 0;
	}

	/**
	 * Advances to the next phase in the stored `phases` list, resetting the
	 * phase-specific sub-cursors as `set_phase()` does.
	 *
	 * @return string|null The new phase name, or null when the current
	 *                      phase was already the last one — the cursor's
	 *                      phase is left unchanged in that case.
	 */
	public function next_phase(): ?string {
		/** @var array<int, string> $phases */
		$phases = $this->data['phases'] ?? [];
		$index  = array_search( $this->phase(), $phases, true );
		$next   = ( false === $index ) ? 0 : $index + 1;

		if ( ! isset( $phases[ $next ] ) ) {
			return null;
		}

		$this->set_phase( $phases[ $next ] );

		return $phases[ $next ];
	}

	/**
	 * @param string $key     Cursor field name.
	 * @param mixed  $default Fallback value when the key is missing.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
	}

	/**
	 * @param string $key   Cursor field name.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $key, $value ): void {
		$this->data[ $key ] = $value;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * @return array{scanner: string|null, last_id: int}
	 */
	private static function fresh_db_subcursor(): array {
		return [
			'scanner' => null,
			'last_id' => 0,
		];
	}
}
