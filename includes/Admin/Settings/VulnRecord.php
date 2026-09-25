<?php
/**
 * Reader for the raw vulnerability records stored on a finding.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A `vulnerability` finding carries the feed's raw records in
 * `meta.records` (spec §8.3). These are third-party data, so everything
 * here is defensive: unknown shapes yield empty strings, and `reference()`
 * and `license_url()` only ever hand back an `https://` URL (spec §13).
 *
 * The Wordfence Intelligence licence asks every copy to link to the record,
 * reproduce Defiant's copyright designation and reproduce the licence
 * itself: `reference()`, `copyright()` and `license()` supply those three,
 * verbatim from the record, since the wording may change upstream.
 */
final class VulnRecord {

	/**
	 * The first patched version the feed knows for this exact package, or
	 * an empty string when there is no fix yet.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function patched_version( array $finding ): string {
		$entry = self::software_entry( $finding );

		if ( null === $entry || true !== ( $entry['patched'] ?? false ) ) {
			return '';
		}

		$versions = is_array( $entry['patched_versions'] ?? null ) ? $entry['patched_versions'] : [];

		return [] === $versions ? '' : (string) reset( $versions );
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function software_name( array $finding ): string {
		$entry = self::software_entry( $finding );
		$name  = null === $entry ? '' : (string) ( $entry['name'] ?? '' );

		return '' !== $name ? $name : self::slug( $finding );
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function title( array $finding ): string {
		$record = self::record( $finding );

		return null === $record ? '' : (string) ( $record['title'] ?? '' );
	}

	/**
	 * The record title for the "Detected by" column, cut to `$max`
	 * characters on a whole word where possible.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @param int                  $max     Maximum characters to keep.
	 */
	public static function short_title( array $finding, int $max ): string {
		$title = self::title( $finding );

		if ( '' === $title || mb_strlen( $title ) <= $max ) {
			return $title;
		}

		return rtrim( mb_substr( $title, 0, $max - 1 ) ) . '…';
	}

	/**
	 * The dimmed line under the package name in the findings table.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function subtitle( array $finding ): string {
		$patched = self::patched_version( $finding );

		return sprintf(
			'%s · %s',
			self::kind( $finding ),
			'' === $patched
				? __( 'no fix available', 'lw-scan' )
				/* translators: %s: version the vulnerability is fixed in. */
				: sprintf( __( 'patched in %s', 'lw-scan' ), $patched )
		);
	}

	/**
	 * The first reference URL, but only when it is an `https://` link.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function reference( array $finding ): string {
		$record     = self::record( $finding );
		$references = is_array( $record['references'] ?? null ) ? $record['references'] : [];

		return self::https_only( [] === $references ? '' : (string) reset( $references ) );
	}

	/**
	 * The feed's required copyright designation, if the record carries one.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function copyright( array $finding ): string {
		return (string) ( self::defiant( $finding )['notice'] ?? '' );
	}

	/**
	 * The licence text the record is distributed under, if it carries one.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function license( array $finding ): string {
		return (string) ( self::defiant( $finding )['license'] ?? '' );
	}

	/**
	 * The licence terms page, but only when it is an `https://` link.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function license_url( array $finding ): string {
		return self::https_only( (string) ( self::defiant( $finding )['license_url'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function kind( array $finding ): string {
		[ $kind ] = self::locator_parts( $finding );

		return $kind;
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function slug( array $finding ): string {
		[ , $slug ] = self::locator_parts( $finding );

		return $slug;
	}

	/**
	 * Splits the `<kind>:<slug>` locator.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @return array{0: string, 1: string}
	 */
	private static function locator_parts( array $finding ): array {
		$locator = (string) ( $finding['locator'] ?? '' );
		$colon   = strpos( $locator, ':' );

		if ( false === $colon ) {
			return [ '', $locator ];
		}

		return [ substr( $locator, 0, $colon ), substr( $locator, $colon + 1 ) ];
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 * @return array<string, mixed>|null
	 */
	private static function record( array $finding ): ?array {
		$meta    = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$records = is_array( $meta['records'] ?? null ) ? $meta['records'] : [];
		$first   = [] === $records ? null : reset( $records );

		return is_array( $first ) ? $first : null;
	}

	/**
	 * The record's `copyrights.defiant` block, or an empty array.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @return array<string, mixed>
	 */
	private static function defiant( array $finding ): array {
		$record     = self::record( $finding );
		$copyrights = is_array( $record['copyrights'] ?? null ) ? $record['copyrights'] : [];

		return is_array( $copyrights['defiant'] ?? null ) ? $copyrights['defiant'] : [];
	}

	/**
	 * `$url` when it is an `https://` link, otherwise an empty string.
	 *
	 * @param string $url Candidate URL from the record.
	 */
	private static function https_only( string $url ): string {
		return 0 === stripos( $url, 'https://' ) ? $url : '';
	}

	/**
	 * The record's `software[]` entry that matches this finding's locator.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @return array<string, mixed>|null
	 */
	private static function software_entry( array $finding ): ?array {
		$record = self::record( $finding );

		if ( null === $record ) {
			return null;
		}

		[ $kind, $slug ] = self::locator_parts( $finding );
		$list            = is_array( $record['software'] ?? null ) ? $record['software'] : [];

		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && ( $entry['type'] ?? '' ) === $kind && ( $entry['slug'] ?? '' ) === $slug ) {
				return $entry;
			}
		}

		return null;
	}
}
