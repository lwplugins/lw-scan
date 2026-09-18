<?php
/**
 * The expandable panel under a findings-table row.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A plain `<details>` element, so the panel opens without JavaScript and
 * stays keyboard-reachable. Shows the matched excerpt, why the finding was
 * raised, what to do about it, and — for vulnerabilities — the feed's
 * reference link and its required attribution line.
 */
final class FindingDetail {

	/** Columns in the findings table, for the panel's colspan. */
	private const COLUMNS = 7;

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	public static function render( array $finding ): void {
		$advice = FindingRow::what_to_do( $finding );
		$reason = (string) ( $finding['reason'] ?? '' );

		if ( '' === $advice && '' === $reason && '' === (string) ( $finding['excerpt'] ?? '' ) ) {
			return;
		}
		?>
		<tr class="lw-scan-detail-row">
			<td colspan="<?php echo esc_attr( (string) self::COLUMNS ); ?>">
				<details class="lw-scan-details">
					<summary><?php esc_html_e( 'Details', 'lw-scan' ); ?></summary>
					<div class="lw-scan-detail">
						<?php self::excerpt( $finding ); ?>
						<div class="lw-scan-col">
							<?php self::why( $finding, $reason ); ?>
							<?php self::advice( $advice, self::copyable_locator( $finding ) ); ?>
						</div>
					</div>
					<?php self::vulnerability( $finding ); ?>
				</details>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function excerpt( array $finding ): void {
		$excerpt = (string) ( $finding['excerpt'] ?? '' );

		if ( '' === $excerpt ) {
			echo '<div></div>';

			return;
		}

		$meta    = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$matches = is_array( $meta['matches'] ?? null ) ? $meta['matches'] : [];
		$line    = (int) ( $finding['line'] ?? 0 );
		?>
		<div>
			<div class="lw-scan-k">
				<?php
				printf(
					/* translators: %d: line number the signature matched on. */
					esc_html__( 'Match · line %d', 'lw-scan' ),
					(int) $line
				);
				?>
			</div>
			<div class="lw-scan-excerpt">
				<?php
				// The excerpt is escaped inside highlighted_excerpt(); only the
				// <mark> around the already-escaped match is added markup.
				echo wp_kses_post( FindingRow::highlighted_excerpt( $excerpt, $matches ) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 * @param string               $reason  Stored reason text.
	 */
	private static function why( array $finding, string $reason ): void {
		$meta    = is_array( $finding['meta'] ?? null ) ? $finding['meta'] : [];
		$signals = is_array( $meta['signal_reasons'] ?? null ) ? array_map( 'strval', $meta['signal_reasons'] ) : [];
		$text    = trim( $reason . ( [] === $signals ? '' : ' · ' . implode( ', ', $signals ) ), " \t·" );

		if ( '' === $text ) {
			return;
		}

		$heading = 'alert' === (string) ( $finding['severity'] ?? '' )
			? __( 'Why it is an alert', 'lw-scan' )
			: __( 'Why it is flagged', 'lw-scan' );
		?>
		<div>
			<div class="lw-scan-k"><?php echo esc_html( $heading ); ?></div>
			<?php echo esc_html( $text ); ?>
		</div>
		<?php
	}

	/**
	 * @param string $advice  Per-type advice text.
	 * @param string $locator What the "Copy path" button puts on the clipboard.
	 */
	private static function advice( string $advice, string $locator ): void {
		if ( '' === $advice ) {
			return;
		}
		?>
		<div>
			<div class="lw-scan-k"><?php esc_html_e( 'What to do', 'lw-scan' ); ?></div>
			<?php echo esc_html( $advice ); ?>
			<?php if ( '' !== $locator ) : ?>
				<button type="button" class="button-link lw-scan-copy-btn lw-scan-copy-btn--inline" data-copy="<?php echo esc_attr( $locator ); ?>"><?php esc_html_e( 'Copy path', 'lw-scan' ); ?></button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Only path-like locators are worth copying; a vulnerability's
	 * `<kind>:<slug>` pair is not.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function copyable_locator( array $finding ): string {
		$type = (string) ( $finding['type'] ?? '' );

		return in_array( $type, [ 'file', 'integrity' ], true ) ? (string) ( $finding['locator'] ?? '' ) : '';
	}

	/**
	 * The feed's own line: what the record is called, where to read it, and
	 * the attribution its licence requires.
	 *
	 * @param array<string, mixed> $finding Findings-table row.
	 */
	private static function vulnerability( array $finding ): void {
		if ( 'vulnerability' !== (string) ( $finding['type'] ?? '' ) ) {
			return;
		}

		$title     = VulnRecord::title( $finding );
		$reference = VulnRecord::reference( $finding );
		$notice    = VulnRecord::copyright( $finding );

		if ( '' === $title && '' === $reference && '' === $notice ) {
			return;
		}
		?>
		<div class="lw-scan-detail lw-scan-detail--wide">
			<div class="lw-scan-small lw-scan-muted">
				<?php echo esc_html( $title ); ?>
				<?php if ( '' !== $reference ) : ?>
					· <a href="<?php echo esc_url( $reference ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Record and license', 'lw-scan' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $notice ) : ?>
					· <?php echo esc_html( $notice ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
