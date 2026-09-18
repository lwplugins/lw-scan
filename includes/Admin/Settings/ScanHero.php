<?php
/**
 * The Scan tab's verdict hero.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * "Where does this site stand": the one-line verdict, what the last run
 * did, and four counters. Every number is read once, here, so the tab
 * itself stays free of queries.
 */
final class ScanHero {

	/**
	 * @param array<string, mixed> $progress `Runner::progress()` snapshot.
	 */
	public static function render( array $progress ): void {
		$counts = FindingsRepository::counts();
		$alerts = (int) ( $counts['state']['new']['alert'] ?? 0 );
		$review = (int) ( $counts['state']['new']['review'] ?? 0 );
		$last   = RunsRepository::last();
		?>
		<div class="lw-scan-card lw-scan-hero">
			<div class="lw-scan-hero-main">
				<div class="lw-scan-hero-verdict lw-scan-verdict--<?php echo esc_attr( self::verdict_variant( $alerts, $review, $last ) ); ?>">
					<?php Icons::render( self::verdict_icon( $alerts, $review, $last ) ); ?>
					<span><?php echo esc_html( self::verdict( $alerts, $review, $last ) ); ?></span>
				</div>
				<div class="lw-scan-hero-sub"><?php echo esc_html( self::summary( $last, $progress ) ); ?></div>
				<?php self::tiles( $last ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Shown before the first activation finished creating the tables.
	 */
	public static function render_missing_tables(): void {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'LW Scan cannot find its database tables. Deactivate and re-activate the plugin to create them.', 'lw-scan' )
		);
	}

	/**
	 * @param int                       $alerts New alert-severity findings.
	 * @param int                       $review New review-severity findings.
	 * @param array<string, mixed>|null $last   The most recent run row.
	 */
	private static function verdict( int $alerts, int $review, ?array $last ): string {
		if ( $alerts > 0 ) {
			return sprintf(
				/* translators: 1: number of new alerts. 2: number of new review items. */
				__( 'Action needed — %1$d alerts, %2$d to review', 'lw-scan' ),
				$alerts,
				$review
			);
		}

		if ( null === $last ) {
			return __( 'Never scanned', 'lw-scan' );
		}

		if ( $review > 0 ) {
			return sprintf(
				/* translators: %d: number of new review items. */
				_n( 'No alerts — %d item to review', 'No alerts — %d items to review', $review, 'lw-scan' ),
				$review
			);
		}

		return __( 'No findings — last scan clean', 'lw-scan' );
	}

	/**
	 * @param int                       $alerts New alert-severity findings.
	 * @param int                       $review New review-severity findings.
	 * @param array<string, mixed>|null $last   The most recent run row.
	 */
	private static function verdict_variant( int $alerts, int $review, ?array $last ): string {
		if ( $alerts > 0 ) {
			return 'alert';
		}

		return ( null === $last || $review > 0 ) ? 'review' : 'ok';
	}

	/**
	 * @param int                       $alerts New alert-severity findings.
	 * @param int                       $review New review-severity findings.
	 * @param array<string, mixed>|null $last   The most recent run row.
	 */
	private static function verdict_icon( int $alerts, int $review, ?array $last ): string {
		if ( $alerts > 0 ) {
			return 'alert';
		}

		return ( null === $last || $review > 0 ) ? 'warning' : 'ok';
	}

	/**
	 * The dimmed line: what the last run was, plus the signature bundle.
	 *
	 * @param array<string, mixed>|null $last     The most recent run row.
	 * @param array<string, mixed>      $progress `Runner::progress()` snapshot.
	 */
	private static function summary( ?array $last, array $progress ): string {
		$bundle = BundleInfo::describe();

		if ( null === $last ) {
			return trim( __( 'No scan has run yet.', 'lw-scan' ) . ' ' . $bundle );
		}

		$elapsed = max( 0, (int) ( $last['finished_at'] ?? 0 ) - (int) ( $last['started_at'] ?? 0 ) );
		$elapsed = $elapsed > 0 ? $elapsed : (int) ( $progress['elapsed'] ?? 0 );

		return sprintf(
			/* translators: 1: when the last scan ran. 2: trigger and scope, e.g. "scheduled, changed files + database". 3: duration. 4: signature bundle summary. */
			__( 'Last scan: %1$s (%2$s) · finished in %3$s · %4$s', 'lw-scan' ),
			Format::datetime( (int) ( $last['started_at'] ?? 0 ) ),
			RunsTable::trigger_label( (string) ( $last['trigger_kind'] ?? '' ) ) . ', ' . RunsTable::scope_label( (string) ( $last['scope'] ?? '' ) ),
			Format::duration( $elapsed ),
			$bundle
		);
	}

	/**
	 * @param array<string, mixed>|null $last The most recent run row.
	 */
	private static function tiles( ?array $last ): void {
		$files = new FilesRepository();
		$stats = $files->stats();
		$run   = RunStatsView::of( $last );
		$total = max( 1, (int) $stats['total'] );
		?>
		<div class="lw-scan-tiles">
			<?php
			self::tile(
				__( 'Files indexed', 'lw-scan' ),
				Format::number( (int) $stats['total'] ),
				sprintf(
					/* translators: %s: number of files waiting to be deep-scanned. */
					__( '%s queued for the next scan', 'lw-scan' ),
					Format::number( $files->count_queue( BundleInfo::version() ) )
				)
			);
			self::tile(
				__( 'Known-good excluded', 'lw-scan' ),
				Format::number( (int) $stats['known_good'] ),
				sprintf(
					/* translators: %s: share of indexed files that matched a wordpress.org checksum. */
					__( '%s matched wp.org checksums', 'lw-scan' ),
					number_format_i18n( ( (int) $stats['known_good'] / $total ) * 100, 1 ) . ' %'
				)
			);
			self::tile(
				__( 'Deep-scanned', 'lw-scan' ),
				Format::number( $run->files_scanned() ),
				$run->deep_scan_breakdown()
			);
			self::tile(
				__( 'Next scheduled', 'lw-scan' ),
				self::next_run(),
				RunsTable::schedule_label()
			);
			?>
		</div>
		<?php
	}

	private static function next_run(): string {
		$due = (int) Options::get( 'next_due', 0 );

		if ( 'off' === (string) Options::get( 'schedule' ) || $due <= 0 ) {
			return __( 'off', 'lw-scan' );
		}

		return (string) wp_date( 'H:i', $due );
	}

	private static function tile( string $key, string $value, string $hint ): void {
		printf(
			'<div class="lw-scan-tile"><span class="lw-scan-tile-k">%1$s</span><span class="lw-scan-tile-v">%2$s</span><span class="lw-scan-tile-h">%3$s</span></div>',
			esc_html( $key ),
			esc_html( $value ),
			esc_html( $hint )
		);
	}
}
