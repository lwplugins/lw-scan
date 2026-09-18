<?php
/**
 * Contract for the FileIndexer-facing slice of FilesRepository.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Db;

defined( 'ABSPATH' ) || exit;

/**
 * `FilesRepository` is `final` (like the plugin's other single-table
 * repositories), so it can't be mocked directly with Mockery; the file
 * pipeline — `Index\FileIndexer` and the `Run\Phase\*` phases — type-hints
 * this narrower interface instead so tests can substitute a mock. Declares
 * only the methods that pipeline calls, not the full FilesRepository API.
 */
interface FilesRepositoryInterface {

	/**
	 * @param array<int, array{path:string,size:int,mtime:int,path_signal:int,origin:string}> $rows   Stat rows from the walker.
	 * @param int                                                                             $run_id Current run id, stored as `seen_run`.
	 * @return void
	 */
	public function upsert_stat_batch( array $rows, int $run_id ): void;

	/**
	 * @param int $after_id Cursor: only rows with a greater id.
	 * @param int $limit    Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function next_unhashed( int $after_id, int $limit ): array;

	/**
	 * @param int                  $id     Row id.
	 * @param array<string, mixed> $fields Column => value; whitelist is enforced by the implementation.
	 * @return void
	 */
	public function update_hashed( int $id, array $fields ): void;

	/**
	 * @param int    $bundle_version Current signature bundle version.
	 * @param int    $after_id       Cursor: only rows with a greater id.
	 * @param int    $limit          Max rows.
	 * @param string $path_prefix    Optional path prefix filter (scope=path).
	 * @return array<int, array<string, mixed>>
	 */
	public function queue_for_scan( int $bundle_version, int $after_id, int $limit, string $path_prefix = '' ): array;

	/**
	 * @param int    $bundle_version Current signature bundle version.
	 * @param string $path_prefix    Optional path prefix filter (scope=path).
	 * @return int Files still queued for a content scan at this bundle version.
	 */
	public function count_queue( int $bundle_version, string $path_prefix = '' ): int;

	/**
	 * @param int $id             Row id.
	 * @param int $bundle_version Bundle version just scanned against.
	 * @return void
	 */
	public function mark_scanned( int $id, int $bundle_version ): void;

	/**
	 * @param string $path_prefix Optional path prefix filter.
	 * @return void
	 */
	public function reset_scanned( string $path_prefix = '' ): void;

	/**
	 * @param int $run_id Current run id.
	 * @return int[] Deleted row ids.
	 */
	public function delete_unseen( int $run_id ): array;
}
