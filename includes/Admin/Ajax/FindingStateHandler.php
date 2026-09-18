<?php
/**
 * `lw_scan_finding_state` — row and bulk state changes from the Findings tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Ajax;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Findings\StateChange;

defined( 'ABSPATH' ) || exit;

/**
 * `ids[]` are absint'd and de-duplicated (a stray `0` is dropped, not
 * treated as a real row id); `state` must be one of the three known
 * finding states or the request is rejected outright. Both rules are
 * shared with the `lw-scan/acknowledge` ability —
 * `Findings\StateChange::normalize_ids()` and `FindingsRepository::STATES`.
 */
final class FindingStateHandler {

	use AjaxGuardTrait;

	public static function register(): void {
		add_action( 'wp_ajax_lw_scan_finding_state', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		self::guard();

		// The `absint` here is what WordPress.Security.ValidatedSanitizedInput
		// needs to see at the point the superglobal is read; StateChange
		// absints again (harmlessly) and adds the shared drop-zero,
		// de-duplicate and reindex policy.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$raw_ids = isset( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['ids'] ) ) : [];
		$ids     = StateChange::normalize_ids( $raw_ids );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by AjaxGuardTrait::guard() above.
		$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';

		if ( ! in_array( $state, FindingsRepository::STATES, true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown finding state.', 'lw-scan' ) ] );
		}

		if ( [] === $ids ) {
			wp_send_json_success( [ 'updated' => 0 ] );
		}

		wp_send_json_success( [ 'updated' => FindingsRepository::set_state( $ids, $state ) ] );
	}
}
