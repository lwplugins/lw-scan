<?php
/**
 * The settings payload of `GET /settings`.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest\Presenter;

use LightweightPlugins\Scan\Admin\SettingsSanitizer;
use LightweightPlugins\Scan\Notify\Baseline;
use LightweightPlugins\Scan\Notify\Preferences;
use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The editable keys straight from the stored option (not `Options::all()`:
 * a form edits what is stored, and a runtime `lw_scan_options` filter must
 * not show up as a setting), the Notifications tab's three-state
 * `notify_send`, the scheduler's `next_due`, and a `meta` block of facts
 * the forms describe but cannot change.
 */
final class SettingsView {

	/**
	 * @return array<string, mixed>
	 */
	public static function current(): array {
		$stored = Options::stored();
		$prefs  = new Preferences( $stored );

		return array_merge(
			self::fields( $stored ),
			[
				'meta' => [
					'timezone'              => (string) wp_timezone_string(),
					'admin_email'           => $prefs->admin_email(),
					'baseline_pending'      => ( new Baseline() )->pending_review(),
					'max_file_size_choices' => SettingsSanitizer::FILE_SIZES,
					'notify_limit_choices'  => Preferences::LIMIT_CHOICES,
				],
			]
		);
	}

	/**
	 * Pure (bar `is_email()` inside `Preferences::configured()`): the stored
	 * option mapped onto the payload's editable keys and `next_due`.
	 *
	 * @param array<string, mixed> $stored `Options::stored()` output.
	 * @return array<string, mixed>
	 */
	public static function fields( array $stored ): array {
		$prefs = new Preferences( $stored );
		$paths = is_array( $stored['excluded_paths'] ?? null ) ? $stored['excluded_paths'] : [];

		return [
			'schedule'           => (string) ( $stored['schedule'] ?? 'daily' ),
			'schedule_hour'      => (int) ( $stored['schedule_hour'] ?? 3 ),
			'scope'              => (string) ( $stored['scope'] ?? 'changed' ),
			'bundle_auto_update' => ! empty( $stored['bundle_auto_update'] ),
			'heuristics'         => ! empty( $stored['heuristics'] ),
			'max_file_size'      => (int) ( $stored['max_file_size'] ?? 0 ),
			'excluded_paths'     => array_values( array_map( 'strval', $paths ) ),
			'follow_symlinks'    => ! empty( $stored['follow_symlinks'] ),
			'notify_send'        => $prefs->enabled() ? $prefs->level() : 'off',
			'notify_emails'      => $prefs->configured(),
			'notify_limit'       => $prefs->limit(),
			'admin_notice'       => ! empty( $stored['admin_notice'] ),
			'next_due'           => ScanOverview::next_due( $stored ),
		];
	}
}
