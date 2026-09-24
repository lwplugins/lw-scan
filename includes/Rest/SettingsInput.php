<?php
/**
 * Partial settings saves from the React app.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Admin\SettingsSanitizer;
use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /settings` may carry any subset of the editable keys. The body is
 * laid over the *stored* option first and only then handed to
 * `SettingsSanitizer::sanitize()`, so a key the client did not send keeps
 * its stored value. Sending the raw body alone would not: the sanitizer
 * reads an absent `excluded_paths` or `notify_emails` as an empty list —
 * which is how saving one tab of the classic screen used to empty the
 * other tab's lists.
 *
 * Only the keys below are accepted; `next_due`, `last_auto_run`,
 * `plugin_version`, `notify_enabled`/`notify_level` (reached through
 * `notify_send`) and anything unknown are dropped. A `null` value counts
 * as "not sent".
 */
final class SettingsInput {

	/** Keys a client may change. */
	public const EDITABLE = [
		'schedule',
		'schedule_hour',
		'scope',
		'bundle_auto_update',
		'heuristics',
		'max_file_size',
		'excluded_paths',
		'follow_symlinks',
		SettingsSanitizer::SEND_FIELD,
		'notify_emails',
		'notify_limit',
		'admin_notice',
	];

	/**
	 * The value to store: stored option + sent keys, sanitized.
	 *
	 * @param array<string, mixed> $input Request body.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		return SettingsSanitizer::sanitize( self::merge( Options::stored(), $input ) );
	}

	/**
	 * Pure: the sent editable keys laid over the stored values.
	 *
	 * @param array<string, mixed> $stored `Options::stored()` output.
	 * @param array<string, mixed> $input  Request body.
	 * @return array<string, mixed>
	 */
	public static function merge( array $stored, array $input ): array {
		foreach ( self::EDITABLE as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				$stored[ $key ] = $input[ $key ];
			}
		}

		return $stored;
	}
}
