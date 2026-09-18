<?php
/**
 * `lw_scan_index` — Health tab file-index maintenance (rebuild).
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
 * `op=rebuild` empties the file index and every finding derived purely from
 * it (`file`, `integrity`) so the next scan starts from a clean slate; it
 * never touches `db`/`vulnerability` findings, which aren't file-index
 * derived.
 *
 * Refused outright while a run's cursor exists: a live `FilesPhase` cursor
 * points at rows this would delete out from under it.
 */
final class IndexHandler {

	use AjaxGuardTrait;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_index', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();
		self::refuse_if_run_active();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		if ( 'rebuild' !== $op ) {
			wp_send_json_error( [ 'message' => __( 'Unknown index operation.', 'lw-scan' ) ] );
		}

		( new FilesRepository() )->truncate();
		FindingsRepository::truncate_type( 'file' );
		FindingsRepository::truncate_type( 'integrity' );
		Environment::invalidate();

		wp_send_json_success();
	}
}
