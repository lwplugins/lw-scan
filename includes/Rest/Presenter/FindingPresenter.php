<?php
/**
 * One finding as the React Findings list shows it.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest\Presenter;

use LightweightPlugins\Scan\Admin\Settings\VulnRecord;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a findings-table row (`meta` and `signature_ids` already decoded
 * by `FindingsRepository::list()`) into the `GET /findings` item shape:
 * every column the list and its detail panel need, plus the display
 * strings the classic PHP table used to compose — title, subtitle, "what
 * to do" advice, "detected by" — so the React side renders them as is.
 *
 * Nothing here is HTML. The excerpt goes out raw (it is scanner output
 * from a possibly hostile file) and the client renders it as text.
 *
 * `$update_url` is injected (the controller passes
 * `admin_url( 'update-core.php' )`) so the presenter itself calls no URL
 * functions.
 */
final class FindingPresenter {

	/** Characters of a vulnerability title the "Detected by" column keeps. */
	private const TITLE_MAX = 80;

	/** @var string Where "Update plugin" points. */
	private string $update_url;

	/**
	 * @param string $update_url Target of the vulnerability "Update" action.
	 */
	public function __construct( string $update_url ) {
		$this->update_url = $update_url;
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row, `meta` and `signature_ids` decoded.
	 * @return array<string, mixed>
	 */
	public function present( array $finding ): array {
		$type    = (string) ( $finding['type'] ?? '' );
		$meta    = self::meta( $finding );
		$excerpt = self::excerpt( $finding, $meta );
		$patched = 'vulnerability' === $type ? VulnRecord::patched_version( $finding ) : '';

		return [
			'id'             => (int) ( $finding['id'] ?? 0 ),
			'type'           => $type,
			'severity'       => (string) ( $finding['severity'] ?? '' ),
			'state'          => (string) ( $finding['state'] ?? '' ),
			'tier'           => (string) ( $finding['tier'] ?? '' ),
			'category'       => (string) ( $finding['category'] ?? '' ),
			'locator'        => (string) ( $finding['locator'] ?? '' ),
			'title'          => self::title( $finding, $meta ),
			'subtitle'       => self::subtitle( $finding, $meta ),
			'detected_by'    => 'vulnerability' === $type ? VulnRecord::short_title( $finding, self::TITLE_MAX ) : (string) ( $finding['category'] ?? '' ),
			'tier_variant'   => self::tier_variant( (string) ( $finding['tier'] ?? '' ) ),
			'signature_ids'  => self::strings( $finding['signature_ids'] ?? [] ),
			'first_seen'     => (int) ( $finding['first_seen'] ?? 0 ),
			'last_seen'      => (int) ( $finding['last_seen'] ?? 0 ),
			'reason'         => (string) ( $finding['reason'] ?? '' ),
			'signal_reasons' => self::strings( $meta['signal_reasons'] ?? [] ),
			'excerpt'        => $excerpt['text'],
			'excerpt_line'   => $excerpt['line'],
			'advice'         => self::what_to_do( $finding ),
			'can_copy_path'  => in_array( $type, [ 'file', 'integrity' ], true ),
			'update_url'     => '' !== $patched ? $this->update_url : null,
			'vuln'           => 'vulnerability' === $type ? self::vuln( $finding ) : null,
		];
	}

	/**
	 * Per-type advice, shown under "What to do".
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function what_to_do( array $finding ): string {
		return match ( (string) ( $finding['type'] ?? '' ) ) {
			'file' => __( 'Remove or restore the file. LW Scan never deletes files itself.', 'lw-scan' ),
			'integrity' => self::integrity_advice( self::meta( $finding ) ),
			'db' => __( 'Inspect and clean the row in the database (e.g. via WP-CLI or phpMyAdmin).', 'lw-scan' ),
			'vulnerability' => self::vulnerability_advice( $finding ),
			default => '',
		};
	}

	/**
	 * The first signature match's excerpt and line; the row's own
	 * `excerpt`/`line` columns when the match carries none.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @param array<string, mixed> $meta    Decoded meta.
	 * @return array{text: string|null, line: int}
	 */
	private static function excerpt( array $finding, array $meta ): array {
		$matches = is_array( $meta['matches'] ?? null ) ? $meta['matches'] : [];
		$first   = is_array( $matches[0] ?? null ) ? $matches[0] : [];
		$text    = (string) ( $first['excerpt'] ?? '' );

		if ( '' !== $text ) {
			return [
				'text' => $text,
				'line' => (int) ( $first['line'] ?? 0 ),
			];
		}

		$text = (string) ( $finding['excerpt'] ?? '' );

		return [
			'text' => '' === $text ? null : $text,
			'line' => (int) ( $finding['line'] ?? 0 ),
		];
	}

	/**
	 * @param array<string, mixed> $meta Decoded meta.
	 */
	private static function integrity_advice( array $meta ): string {
		$package = self::package_label( (string) ( $meta['package'] ?? '' ) );

		if ( '' === $package ) {
			return __( 'Re-install the affected package from wordpress.org to restore the original file.', 'lw-scan' );
		}

		return sprintf(
			/* translators: 1: package name, e.g. WordPress or a plugin slug. 2: package version. */
			__( 'Re-install %1$s %2$s from wordpress.org to restore the original file.', 'lw-scan' ),
			$package,
			(string) ( $meta['version'] ?? '' )
		);
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function vulnerability_advice( array $finding ): string {
		$patched = VulnRecord::patched_version( $finding );

		if ( '' === $patched ) {
			return __( 'No fix is available yet; consider disabling the plugin.', 'lw-scan' );
		}

		return sprintf(
			/* translators: %s: version the vulnerability is fixed in. */
			__( 'Update to %s.', 'lw-scan' ),
			$patched
		);
	}

	private static function package_label( string $package ): string {
		if ( 'core' === $package ) {
			return __( 'WordPress', 'lw-scan' );
		}

		$colon = strpos( $package, ':' );

		return false === $colon ? $package : substr( $package, $colon + 1 );
	}

	/**
	 * The locator; for a vulnerability, "{name} {installed version}".
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @param array<string, mixed> $meta    Decoded meta.
	 */
	private static function title( array $finding, array $meta ): string {
		if ( 'vulnerability' !== (string) ( $finding['type'] ?? '' ) ) {
			return (string) ( $finding['locator'] ?? '' );
		}

		return trim( VulnRecord::software_name( $finding ) . ' ' . (string) ( $meta['installed_version'] ?? '' ) );
	}

	/**
	 * The dimmed line under the title, per type.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @param array<string, mixed> $meta    Decoded meta.
	 */
	private static function subtitle( array $finding, array $meta ): string {
		switch ( (string) ( $finding['type'] ?? '' ) ) {
			case 'integrity':
				return trim( self::package_label( (string) ( $meta['package'] ?? '' ) ) . ' ' . (string) ( $meta['version'] ?? '' ) . ' · ' . __( 'checksum mismatch', 'lw-scan' ) );

			case 'db':
				return (string) ( $meta['label'] ?? '' );

			case 'vulnerability':
				return VulnRecord::subtitle( $finding );

			default:
				return implode( ' · ', self::strings( $meta['signal_reasons'] ?? [] ) );
		}
	}

	/**
	 * The feed's own lines: the record title, its https reference, and the
	 * copyright notice, licence text and licence link its licence requires
	 * a copy to carry; null when the record has none of them.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 * @return array{title: string, reference: string, notice: string, license: string, license_url: string}|null
	 */
	private static function vuln( array $finding ): ?array {
		$vuln = [
			'title'       => VulnRecord::title( $finding ),
			'reference'   => VulnRecord::reference( $finding ),
			'notice'      => VulnRecord::copyright( $finding ),
			'license'     => VulnRecord::license( $finding ),
			'license_url' => VulnRecord::license_url( $finding ),
		];

		return '' === implode( '', $vuln ) ? null : $vuln;
	}

	private static function tier_variant( string $tier ): string {
		return in_array( $tier, [ 'infected', 'integrity' ], true ) ? 'alert' : 'review';
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 * @return array<string, mixed>
	 */
	private static function meta( array $finding ): array {
		return is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
	}

	/**
	 * @param mixed $list Candidate list.
	 * @return string[]
	 */
	private static function strings( $list ): array {
		return is_array( $list ) ? array_values( array_map( 'strval', array_filter( $list, 'is_scalar' ) ) ) : [];
	}
}
