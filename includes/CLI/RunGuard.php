<?php
/**
 * Refuses a state-mutating CLI command while a scan is in progress.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Admin\Ajax\AjaxGuard;
use LightweightPlugins\Scan\Run\Cursor;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The CLI counterpart of `Admin\Ajax\AjaxGuardTrait::refuse_if_run_active()`
 * — and it reuses the same predicate, so both entry points agree on what
 * "a run is in progress" means. Swapping the signature pack or emptying
 * the file index under a live cursor would invalidate state a running
 * phase already captured, so `bundle update`, `bundle force-full` and
 * `index rebuild` all refuse (exit code 2) unless `--force` says the
 * operator knows.
 */
final class RunGuard {

	/**
	 * @param array<string, mixed> $assoc The command's named arguments; `--force` overrides the refusal.
	 * @return void
	 */
	public static function refuse_if_run_active( array $assoc ): void {
		if ( (bool) Utils\get_flag_value( $assoc, 'force', false ) ) {
			return;
		}

		if ( AjaxGuard::run_active( Cursor::load() ) ) {
			WP_CLI::error( 'A scan is in progress. Stop it first or pass --force.', 2 );
		}
	}
}
