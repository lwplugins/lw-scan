<?php
/**
 * Findings-tab filter chips and search box.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The chips are plain links carrying `severity`/`type`/`state` in the
 * query string, and the search box is a plain GET form — so the whole
 * filter bar works with JavaScript switched off and every state is
 * bookmarkable. Picking a state chip keeps the chosen type and vice versa.
 */
final class FindingsFilters {

	/**
	 * @param array<string, string> $filters Active filters (severity, type, state, search).
	 * @param array<string, mixed>  $counts  `FindingsRepository::counts()`.
	 */
	public static function render( array $filters, array $counts ): void {
		?>
		<div class="lw-scan-row lw-scan-row--between lw-scan-row--wrap">
			<div class="lw-scan-chips">
				<?php self::state_chips( $filters, $counts ); ?>
				<span class="lw-scan-muted lw-scan-chip-sep" aria-hidden="true">|</span>
				<?php self::type_chips( $filters, $counts ); ?>
			</div>
			<?php self::search( $filters ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, string> $filters Active filters.
	 * @param array<string, mixed>  $counts  `FindingsRepository::counts()`.
	 */
	private static function state_chips( array $filters, array $counts ): void {
		$states = is_array( $counts['state'] ?? null ) ? $counts['state'] : [];
		$new    = is_array( $states['new'] ?? null ) ? $states['new'] : [];

		$chips = [
			[
				'label' => __( 'All', 'lw-scan' ),
				'count' => (int) ( $counts['total'] ?? 0 ),
				'args'  => [
					'state'    => '',
					'severity' => '',
				],
			],
			[
				'label' => __( 'Alerts', 'lw-scan' ),
				'count' => (int) ( $new['alert'] ?? 0 ),
				'args'  => [
					'state'    => 'new',
					'severity' => 'alert',
				],
			],
			[
				'label' => __( 'Review', 'lw-scan' ),
				'count' => (int) ( $new['review'] ?? 0 ),
				'args'  => [
					'state'    => 'new',
					'severity' => 'review',
				],
			],
			[
				'label' => __( 'Acknowledged', 'lw-scan' ),
				'count' => (int) array_sum( is_array( $states['acknowledged'] ?? null ) ? $states['acknowledged'] : [] ),
				'args'  => [
					'state'    => 'acknowledged',
					'severity' => '',
				],
			],
			[
				'label' => __( 'Ignored', 'lw-scan' ),
				'count' => (int) array_sum( is_array( $states['ignored'] ?? null ) ? $states['ignored'] : [] ),
				'args'  => [
					'state'    => 'ignored',
					'severity' => '',
				],
			],
		];

		foreach ( $chips as $chip ) {
			$active = $filters['state'] === $chip['args']['state'] && $filters['severity'] === $chip['args']['severity'];

			self::chip(
				(string) $chip['label'],
				(int) $chip['count'],
				$active,
				array_merge(
					$chip['args'],
					[
						'type' => $filters['type'],
						's'    => $filters['search'],
					]
				)
			);
		}
	}

	/**
	 * @param array<string, string> $filters Active filters.
	 * @param array<string, mixed>  $counts  `FindingsRepository::counts()`.
	 */
	private static function type_chips( array $filters, array $counts ): void {
		$types  = is_array( $counts['type'] ?? null ) ? $counts['type'] : [];
		$labels = [
			'file'          => __( 'Files', 'lw-scan' ),
			'integrity'     => __( 'Integrity', 'lw-scan' ),
			'db'            => __( 'Database', 'lw-scan' ),
			'vulnerability' => __( 'Vulnerabilities', 'lw-scan' ),
		];

		foreach ( $labels as $type => $label ) {
			$active = $filters['type'] === $type;

			self::chip(
				$label,
				(int) ( $types[ $type ] ?? 0 ),
				$active,
				[
					'type'     => $active ? '' : $type,
					'state'    => $filters['state'],
					'severity' => $filters['severity'],
					's'        => $filters['search'],
				]
			);
		}
	}

	/**
	 * @param string                $label  Chip caption.
	 * @param int                   $count  Badge number.
	 * @param bool                  $active Whether the chip is the current filter.
	 * @param array<string, string> $args   Query args the chip links to.
	 */
	private static function chip( string $label, int $count, bool $active, array $args ): void {
		printf(
			'<a class="lw-scan-chip %1$s" href="%2$s">%3$s <span class="lw-scan-chip-n">%4$s</span></a>',
			esc_attr( $active ? 'is-on' : '' ),
			esc_url( self::url( $args ) ),
			esc_html( $label ),
			esc_html( Format::number( $count ) )
		);
	}

	/**
	 * @param array<string, string> $filters Active filters.
	 */
	private static function search( array $filters ): void {
		?>
		<form method="get" class="lw-scan-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( SettingsPage::SLUG ); ?>" />
			<input type="hidden" name="tab" value="findings" />
			<?php foreach ( [ 'severity', 'type', 'state' ] as $key ) : ?>
				<?php if ( '' !== $filters[ $key ] ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $filters[ $key ] ); ?>" />
				<?php endif; ?>
			<?php endforeach; ?>
			<label class="screen-reader-text" for="lw-scan-search"><?php esc_html_e( 'Search findings', 'lw-scan' ); ?></label>
			<input type="search" id="lw-scan-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
				placeholder="<?php esc_attr_e( 'Search path, signature, plugin…', 'lw-scan' ); ?>" />
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'lw-scan' ); ?></button>
		</form>
		<?php
	}

	/**
	 * A findings-tab URL with the given filters, always back on page one.
	 *
	 * @param array<string, string> $args Filter values; empty ones are dropped.
	 */
	public static function url( array $args ): string {
		$url = SettingsPage::tab_url( 'findings' );

		foreach ( $args as $key => $value ) {
			if ( '' !== $value ) {
				$url = add_query_arg( $key, $value, $url );
			}
		}

		return $url;
	}
}
