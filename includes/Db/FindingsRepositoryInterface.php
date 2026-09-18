<?php
/**
 * Contract for the findings-writing slice of FindingsRepository shared by
 * FileIndexer, Vuln\Matcher and the Run\Phase\* phases.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Db;

use LightweightPlugins\Scan\Findings\Finding;

defined( 'ABSPATH' ) || exit;

/**
 * `FindingsRepository` is `final` (like the plugin's other single-table
 * repositories), so it can't be mocked directly with Mockery; consumers
 * (`FileIndexer`, `Vuln\Matcher`) type-hint this narrower interface instead
 * so tests can substitute a mock. Declares only the methods those consumers
 * call, not the full FindingsRepository API. Methods stay `static` to match
 * FindingsRepository's own (static, `global $wpdb`-based) signatures;
 * callers still reach them through an injected instance (`$this->findings->upsert(...)`),
 * which PHP permits and which is what a Mockery mock of this interface intercepts.
 */
interface FindingsRepositoryInterface {

	/**
	 * @param Finding $finding Freshly built finding from a scanner/matcher.
	 * @return array{id:int, created:bool, changed:bool}
	 */
	public static function upsert( Finding $finding ): array;

	/**
	 * @param string $type    Finding type.
	 * @param string $locator Locator string.
	 * @return int Rows deleted (0 or 1).
	 */
	public static function delete_by_locator( string $type, string $locator ): int;

	/**
	 * @param string   $type     Finding type.
	 * @param string[] $locators Locators to look up.
	 * @return string[] The subset of `$locators` that already have a finding.
	 */
	public static function existing_locators( string $type, array $locators ): array;

	/**
	 * @param string[] $locators Locators still current.
	 * @return int Rows deleted.
	 */
	public static function delete_vuln_not_in( array $locators ): int;

	/**
	 * @param int[] $file_ids File ids whose findings should go.
	 * @return int Rows deleted.
	 */
	public static function delete_for_files( array $file_ids ): int;

	/**
	 * @param int $ts Unix timestamp (typically the run's started_at).
	 * @return array<int, array<string, mixed>> Findings first seen (or reopened) since `$ts`.
	 */
	public static function new_since( int $ts ): array;
}
