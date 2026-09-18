<?php
/**
 * Settings sanitizer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin;

use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Validates every submitted settings key against the shape declared in
 * `Options::get_defaults()` (spec §4.4). Anything unknown or out of range
 * falls back to the currently stored value rather than to the default, so a
 * hand-crafted POST can never silently reset a configured site. That
 * fallback comes from `Options::stored()`, not `Options::all()`: this is a
 * writer, and a runtime `lw_scan_options` filter must not be baked into
 * the database by a Settings save.
 *
 * `next_due` and `last_auto_run` are runtime bookkeeping with no form
 * fields, so the settings form never submits them and they are carried
 * over. They must NOT be pinned to the stored value unconditionally: once
 * `register_setting()` has run, this callback fires on *every*
 * `update_option( 'lw_scan_options', ... )` — including `Run\Scheduler`'s
 * and `Run\Phase\FinalizePhase`'s own `Options::update()` during an
 * admin-assisted run — and reverting them there would stop the schedule
 * from ever moving forward. Present in the input means "a writer meant
 * this"; absent means "the form posted, keep what we had".
 *
 * `plugin_version` is different again: it is `Upgrader`'s marker, and no
 * writer other than `Upgrader` has any business setting it, so it is
 * carried over from the stored value whatever the input says. That is safe
 * because `Upgrader` runs from `Plugin::init_components()` during plugin
 * load — long before `admin_init` registers this callback — so its own
 * write never passes through here, while the carry guarantees a settings
 * save can never drop the marker and send the site through an upgrade it
 * has already done. Moving the `Upgrader` call to a later hook would break
 * that: the carry would swallow its write.
 */
final class SettingsSanitizer {

	/** Values `schedule` may take. */
	private const SCHEDULES = [ 'off', 'hourly', 'daily', 'weekly' ];

	/** Values `scope` may take from the settings form (spec §4.4). */
	private const SCOPES = [ 'changed', 'full', 'db' ];

	/** Values `notify_level` may take. */
	private const NOTIFY_LEVELS = [ 'alert', 'review' ];

	/** Per-file byte limits offered by the form. */
	private const FILE_SIZES = [ 524288, 1048576, 2097152, 5242880, 10485760 ];

	/** Runtime bookkeeping: no form fields, written by the scheduler/finalize. */
	private const BOOKKEEPING = [ 'next_due', 'last_auto_run' ];

	/** No form field and no writer but `Upgrader`: always kept as stored. */
	private const CARRIED = [ 'plugin_version' ];

	/**
	 * @param mixed $input Submitted option value (the settings form's array).
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input     = is_array( $input ) ? $input : [];
		$current   = Options::stored();
		$sanitized = [];

		foreach ( Options::get_defaults() as $key => $default ) {
			$fallback = array_key_exists( $key, $current ) ? $current[ $key ] : $default;

			if ( in_array( $key, self::CARRIED, true ) ) {
				$sanitized[ $key ] = (string) $fallback;

				continue;
			}

			if ( in_array( $key, self::BOOKKEEPING, true ) ) {
				$sanitized[ $key ] = array_key_exists( $key, $input ) ? absint( $input[ $key ] ) : (int) $fallback;

				continue;
			}

			$sanitized[ $key ] = self::value( $key, $default, $fallback, $input[ $key ] ?? null );
		}

		return $sanitized;
	}

	/**
	 * @param string $key      Option key.
	 * @param mixed  $default  Default value, which also declares the type.
	 * @param mixed  $fallback Currently stored value.
	 * @param mixed  $value    Submitted value, or null when absent.
	 * @return mixed
	 */
	private static function value( string $key, $default, $fallback, $value ) {
		if ( is_bool( $default ) ) {
			return ! empty( $value );
		}

		if ( 'excluded_paths' === $key ) {
			return self::paths( $value );
		}

		if ( 'notify_emails' === $key ) {
			return self::emails( $value );
		}

		if ( 'schedule_hour' === $key ) {
			return null === $value ? (int) $fallback : max( 0, min( 23, (int) $value ) );
		}

		if ( 'max_file_size' === $key ) {
			$size = absint( $value );

			return in_array( $size, self::FILE_SIZES, true ) ? $size : (int) $fallback;
		}

		return self::enum( $key, $fallback, $value );
	}

	/**
	 * The three string options that are closed sets.
	 *
	 * @param string $key      Option key.
	 * @param mixed  $fallback Currently stored value.
	 * @param mixed  $value    Submitted value, or null when absent.
	 */
	private static function enum( string $key, $fallback, $value ): string {
		$allowed = [
			'schedule'     => self::SCHEDULES,
			'scope'        => self::SCOPES,
			'notify_level' => self::NOTIFY_LEVELS,
		];

		$submitted = null === $value ? '' : sanitize_text_field( (string) $value );

		if ( isset( $allowed[ $key ] ) && in_array( $submitted, $allowed[ $key ], true ) ) {
			return $submitted;
		}

		return (string) $fallback;
	}

	/**
	 * Exclusion patterns, from the textarea (one per line) or an array.
	 * Normalized exactly as `Options::excluded_paths()` reads them —
	 * backslashes folded to `/`, surrounding whitespace and slashes
	 * trimmed, blanks dropped, deduplicated — and any entry containing
	 * `..` rejected outright (spec §13: no traversal in path input).
	 *
	 * @param mixed $value Submitted textarea string or array of patterns.
	 * @return string[]
	 */
	private static function paths( $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );
		$out   = [];

		foreach ( (array) $lines as $line ) {
			$path = trim( str_replace( '\\', '/', sanitize_text_field( (string) $line ) ), " \t/" );

			if ( '' === $path || false !== strpos( $path, '..' ) ) {
				continue;
			}

			$out[] = $path;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Notification recipients, split on commas and newlines; only what
	 * `is_email()` accepts survives.
	 *
	 * @param mixed $value Submitted string or array of addresses.
	 * @return string[]
	 */
	private static function emails( $value ): array {
		$parts = is_array( $value ) ? $value : preg_split( '/[\r\n,]+/', (string) $value );
		$out   = [];

		foreach ( (array) $parts as $part ) {
			$email = trim( sanitize_text_field( (string) $part ) );

			if ( '' !== $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
