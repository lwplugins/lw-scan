<?php
/**
 * One findings-table row.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the summary `<tr>` of a single finding (the expandable panel
 * beneath it is `FindingDetail`'s job) and owns the two pure strings the
 * detail panel needs: the per-type "what to do" advice and the excerpt
 * with the match wrapped in `<mark>`.
 *
 * `highlighted_excerpt()` is the one place in the plugin that composes
 * HTML from scanner output: it escapes the whole excerpt first and only
 * then inserts the `<mark>` around an already-escaped needle, so hostile
 * file contents can never introduce markup (spec §13).
 */
final class FindingRow {

	/** How many signature ids are listed before the "(+N)" suffix. */
	private const SIGNATURE_PREVIEW = 2;

	/** Characters of a vulnerability title the "Detected by" column keeps. */
	private const TITLE_MAX = 80;

	/**
	 * @param array<string, mixed> $finding Findings-table row, `meta` and `signature_ids` already decoded.
	 */
	public static function render( array $finding ): void {
		$state    = (string) ( $finding['state'] ?? 'new' );
		$type     = (string) ( $finding['type'] ?? 'file' );
		$id       = (int) ( $finding['id'] ?? 0 );
		$subtitle = self::subtitle( $finding );
		?>
		<tr class="lw-scan-finding lw-scan-finding--<?php echo esc_attr( $state ); ?>">
			<td class="check-column">
				<label class="screen-reader-text" for="lw-scan-cb-<?php echo esc_attr( (string) $id ); ?>"><?php esc_html_e( 'Select finding', 'lw-scan' ); ?></label>
				<input type="checkbox" id="lw-scan-cb-<?php echo esc_attr( (string) $id ); ?>" class="lw-scan-cb" value="<?php echo esc_attr( (string) $id ); ?>" />
			</td>
			<td><?php self::state_pill( $finding ); ?></td>
			<td class="lw-scan-type">
				<?php Icons::render( Icons::for_type( $type ) ); ?>
				<?php echo esc_html( self::type_label( $type ) ); ?>
			</td>
			<td class="lw-scan-mono">
				<?php echo esc_html( self::title( $finding ) ); ?>
				<?php if ( '' !== $subtitle ) : ?>
					<div class="lw-scan-sub lw-scan-muted lw-scan-small"><?php echo esc_html( $subtitle ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( 'vulnerability' === $type ) : ?>
					<?php echo esc_html( VulnRecord::short_title( $finding, self::TITLE_MAX ) ); ?>
				<?php else : ?>
					<span class="lw-scan-pill lw-scan-pill--<?php echo esc_attr( self::tier_variant( (string) ( $finding['tier'] ?? '' ) ) ); ?>"><?php echo esc_html( (string) ( $finding['tier'] ?? '' ) ); ?></span>
					<?php echo esc_html( (string) ( $finding['category'] ?? '' ) ); ?>
				<?php endif; ?>
				<div class="lw-scan-small lw-scan-muted"><?php echo esc_html( self::signatures( $finding ) ); ?></div>
			</td>
			<td>
				<?php echo esc_html( Format::datetime( (int) ( $finding['last_seen'] ?? 0 ) ) ); ?>
				<div class="lw-scan-small lw-scan-muted">
					<?php
					printf(
						/* translators: %s: date a finding was first seen. */
						esc_html__( 'first %s', 'lw-scan' ),
						esc_html( Format::datetime( (int) ( $finding['first_seen'] ?? 0 ) ) )
					);
					?>
				</div>
			</td>
			<td class="lw-scan-actions"><?php self::actions( $finding ); ?></td>
		</tr>
		<?php
		FindingDetail::render( $finding );
	}

	/**
	 * Per-type advice, shown under "What to do" in the detail panel.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function what_to_do( array $finding ): string {
		return match ( (string) ( $finding['type'] ?? '' ) ) {
			'file' => __( 'Remove or restore the file. LW Scan never deletes files itself.', 'lw-scan' ),
			'integrity' => self::integrity_advice( $finding ),
			'db' => __( 'Inspect and clean the row in the database (e.g. via WP-CLI or phpMyAdmin).', 'lw-scan' ),
			'vulnerability' => self::vulnerability_advice( $finding ),
			default => '',
		};
	}

	/**
	 * The excerpt, HTML-escaped, with the first signature match wrapped in
	 * `<mark>`. Both halves are escaped before the tag is inserted, so the
	 * result is safe for `wp_kses_post()`.
	 *
	 * @param string                           $excerpt Stored excerpt.
	 * @param array<int, array<string, mixed>> $matches `meta.matches` rows.
	 */
	public static function highlighted_excerpt( string $excerpt, array $matches ): string {
		$escaped = esc_html( $excerpt );
		$needle  = (string) ( $matches[0]['excerpt'] ?? '' );

		if ( '' === $needle ) {
			return $escaped;
		}

		$needle   = esc_html( $needle );
		$position = strpos( $escaped, $needle );

		if ( false === $position ) {
			return $escaped;
		}

		return substr( $escaped, 0, $position )
			. '<mark>' . $needle . '</mark>'
			. substr( $escaped, $position + strlen( $needle ) );
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function integrity_advice( array $finding ): string {
		$meta    = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$package = self::package_label( (string) ( $meta['package'] ?? '' ) );
		$version = (string) ( $meta['version'] ?? '' );

		if ( '' === $package ) {
			return __( 'Re-install the affected package from wordpress.org to restore the original file.', 'lw-scan' );
		}

		return sprintf(
			/* translators: 1: package name, e.g. WordPress or a plugin slug. 2: package version. */
			__( 'Re-install %1$s %2$s from wordpress.org to restore the original file.', 'lw-scan' ),
			$package,
			$version
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
	 * A `new` finding shows its severity; anything else shows its state.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function state_pill( array $finding ): void {
		$state    = (string) ( $finding['state'] ?? 'new' );
		$severity = (string) ( $finding['severity'] ?? 'review' );
		$label    = 'new' === $state ? $severity : $state;
		$variant  = 'new' === $state ? $severity : 'muted';

		printf(
			'<span class="lw-scan-pill lw-scan-pill--%1$s">%2$s</span>',
			esc_attr( $variant ),
			esc_html( $label )
		);
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function actions( array $finding ): void {
		$id    = (string) ( $finding['id'] ?? 0 );
		$state = (string) ( $finding['state'] ?? 'new' );

		if ( 'vulnerability' === (string) ( $finding['type'] ?? '' ) && '' !== VulnRecord::patched_version( $finding ) ) {
			printf(
				'<a class="button button-primary button-small" href="%1$s">%2$s</a> ',
				esc_url( admin_url( 'update-core.php' ) ),
				esc_html__( 'Update plugin', 'lw-scan' )
			);
		}

		$buttons = 'new' === $state
			? [
				'acknowledged' => __( 'Acknowledge', 'lw-scan' ),
				'ignored'      => __( 'Ignore', 'lw-scan' ),
			]
			: [ 'new' => __( 'Reopen', 'lw-scan' ) ];

		foreach ( $buttons as $target => $label ) {
			printf(
				'<button type="button" class="button button-small lw-scan-state" data-id="%1$s" data-state="%2$s">%3$s</button> ',
				esc_attr( $id ),
				esc_attr( (string) $target ),
				esc_html( $label )
			);
		}
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function title( array $finding ): string {
		if ( 'vulnerability' !== (string) ( $finding['type'] ?? '' ) ) {
			return (string) ( $finding['locator'] ?? '' );
		}

		$meta    = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$version = (string) ( $meta['installed_version'] ?? '' );

		return trim( VulnRecord::software_name( $finding ) . ' ' . $version );
	}

	/**
	 * The dimmed line under the locator, per type.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function subtitle( array $finding ): string {
		$meta = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$type = (string) ( $finding['type'] ?? '' );

		if ( 'integrity' === $type ) {
			return trim( self::package_label( (string) ( $meta['package'] ?? '' ) ) . ' ' . (string) ( $meta['version'] ?? '' ) . ' · ' . __( 'checksum mismatch', 'lw-scan' ) );
		}

		if ( 'db' === $type ) {
			return (string) ( $meta['label'] ?? '' );
		}

		if ( 'vulnerability' === $type ) {
			return VulnRecord::subtitle( $finding );
		}

		$reasons = is_array( $meta['signal_reasons'] ?? null ) ? $meta['signal_reasons'] : [];

		return implode( ' · ', array_map( 'strval', $reasons ) );
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function signatures( array $finding ): string {
		$ids = is_array( $finding['signature_ids'] ?? null ) ? array_map( 'strval', $finding['signature_ids'] ) : [];

		if ( [] === $ids ) {
			return '';
		}

		$shown = array_slice( $ids, 0, self::SIGNATURE_PREVIEW );
		$rest  = count( $ids ) - count( $shown );

		return implode( ', ', $shown ) . ( $rest > 0 ? sprintf( ' (+%d)', $rest ) : '' );
	}

	private static function type_label( string $type ): string {
		$labels = [
			'file'          => __( 'file', 'lw-scan' ),
			'integrity'     => __( 'integrity', 'lw-scan' ),
			'db'            => __( 'database', 'lw-scan' ),
			'vulnerability' => __( 'vulnerability', 'lw-scan' ),
		];

		return $labels[ $type ] ?? $type;
	}

	private static function tier_variant( string $tier ): string {
		return in_array( $tier, [ 'infected', 'integrity' ], true ) ? 'alert' : 'review';
	}
}
