<?php
/**
 * Contract for a single Health\Environment report row.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Each check answers one yes/no-ish question about whether the environment
 * can run a scan (spec §12). `Health\Environment` runs every check and
 * turns the result into a report row; `blocking_issue()` runs a fixed
 * subset (storage, bundle) and stops a scan from starting when `blocking`
 * is true.
 */
interface CheckInterface {

	/**
	 * Stable row id, e.g. `storage`, `pcre`.
	 */
	public function id(): string;

	/**
	 * Human-readable row label.
	 */
	public function label(): string;

	/**
	 * @return array{status:string, message:string, blocking:bool, details?:array<string,mixed>} `status` is one of ok|warning|critical|info. `details` is optional, structured data behind the message (e.g. `TablesCheck`'s per-table row counts) for callers that want more than the human-readable string.
	 */
	public function run(): array;
}
