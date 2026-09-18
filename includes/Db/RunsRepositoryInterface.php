<?php
/**
 * Contract for the Runner-facing slice of RunsRepository.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Db;

defined( 'ABSPATH' ) || exit;

/**
 * `RunsRepository` is `final` (like the plugin's other single-table
 * repositories), so it can't be mocked directly with Mockery; `Run\Runner`
 * and the run phases type-hint this narrower interface instead so tests can
 * substitute a mock. Declares only the methods the run pipeline calls, not
 * the full RunsRepository API. Methods stay `static` to match
 * RunsRepository's own (static, `global $wpdb`-based) signatures; callers
 * still reach them through an injected instance
 * (`$this->runs->update(...)`), which PHP permits and which is what a
 * Mockery mock of this interface intercepts.
 */
interface RunsRepositoryInterface {

	/**
	 * @param string $trigger        manual|cron|catchup|cli.
	 * @param string $scope          full|changed|db|path.
	 * @param string $path           Scope path (scope=path only), else ''.
	 * @param int    $bundle_version Signature bundle version at start.
	 * @return int New run id.
	 */
	public static function create( string $trigger, string $scope, string $path, int $bundle_version ): int;

	/**
	 * @param int                  $id     Run id.
	 * @param array<string, mixed> $fields Column => value; the implementation enforces its own whitelist.
	 * @return void
	 */
	public static function update( int $id, array $fields ): void;

	/**
	 * @param int                  $id     Run id.
	 * @param string               $status done|stopped|failed.
	 * @param array<string, mixed> $stats  Final RunStats.
	 * @param string               $error  Error message (status=failed only).
	 * @return void
	 */
	public static function finish( int $id, string $status, array $stats, string $error = '' ): void;

	/**
	 * @param int $id Run id.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array;

	/**
	 * @return array<string, mixed>|null The most recent run of any status.
	 */
	public static function last(): ?array;

	/**
	 * @param int $keep Number of most-recent runs to retain.
	 * @return void
	 */
	public static function prune( int $keep = 50 ): void;
}
