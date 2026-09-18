<?php
/**
 * `lw_scan_clear_findings` — the Findings tab's "Clear all findings".
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Health\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes every finding and clears the stored file hashes, so the list
 * starts empty and the next scan re-hashes and re-checks every file.
 * Anything malicious that is still on the site is therefore reported
 * again on that scan instead of staying hidden until the file changes.
 *
 * Refused while a run's cursor exists: the running scan would keep writing
 * findings against hashes this just reset.
 */
final class ClearFindingsHandler {

	use AjaxGuardTrait;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_clear_findings', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();
		self::refuse_if_run_active();

		$cleared = FindingsRepository::clear();
		( new FilesRepository() )->reset_hashes();
		Environment::invalidate();

		wp_send_json_success( [ 'cleared' => $cleared ] );
	}
}
