<?php
/**
 * The Scan tab while a run is in flight.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Phases;

defined( 'ABSPATH' ) || exit;

/**
 * Phase tiles, progress bar, Stop and the live findings feed. Rendered
 * server-side from the same `Runner::progress()` snapshot the polling
 * endpoint returns, so the first paint is already correct and
 * `assets/js/admin.js` only has to keep it current.
 *
 * The `data-lw-scan-*` attributes are the contract with that script;
 * `data-last-tick` seeds its assist timer, so a cursor that has not ticked
 * yet does not make every poll fire an assist.
 */
final class ScanProgress {

	/** How many of the run's own findings the feed shows (spec §11.1). */
	private const FEED_LIMIT = 20;

	/**
	 * Phase captions, in pipeline order (`Run\Phases::ALL`).
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'bundle'   => __( 'Signatures', 'lw-scan' ),
			'index'    => __( 'Index', 'lw-scan' ),
			'hash'     => __( 'Hashes', 'lw-scan' ),
			'files'    => __( 'Files', 'lw-scan' ),
			'db'       => __( 'Database', 'lw-scan' ),
			'vuln'     => __( 'Vulnerabilities', 'lw-scan' ),
			'finalize' => __( 'Finalize', 'lw-scan' ),
		];
	}

	/**
	 * @param array<string, mixed> $progress `Runner::progress()` snapshot.
	 */
	public static function render( array $progress ): void {
		$cursor  = Cursor::load();
		$scope   = null === $cursor ? 'full' : $cursor->scope();
		$started = null === $cursor ? 0 : $cursor->started_at();
		$done    = (int) ( $progress['done'] ?? 0 );
		$total   = (int) ( $progress['total'] ?? 0 );
		?>
		<div class="lw-scan-card lw-scan-col lw-scan-run"
			data-lw-scan-run="1"
			data-run-id="<?php echo esc_attr( (string) ( $progress['run_id'] ?? 0 ) ); ?>"
			data-last-tick="<?php echo esc_attr( (string) ( $progress['last_tick_at'] ?? 0 ) ); ?>">
			<div class="lw-scan-row lw-scan-row--between">
				<h2 class="lw-scan-section-title">
					<?php Icons::render( 'spinner', 'lw-scan-ic lw-scan-spin' ); ?>
					<span>
						<?php
						printf(
							/* translators: 1: scan scope. 2: start time. */
							esc_html__( 'Scanning… %1$s · started %2$s', 'lw-scan' ),
							esc_html( RunsTable::scope_label( $scope ) ),
							esc_html( Format::datetime( $started ) )
						);
						?>
						· <?php esc_html_e( 'elapsed', 'lw-scan' ); ?>
						<span data-lw-scan-elapsed><?php echo esc_html( Format::clock( (int) ( $progress['elapsed'] ?? 0 ) ) ); ?></span>
					</span>
				</h2>
				<div class="lw-scan-row">
					<span class="lw-scan-muted lw-scan-small" data-lw-scan-eta><?php echo esc_html( self::eta( $done, $total, (int) ( $progress['elapsed'] ?? 0 ) ) ); ?></span>
					<button type="button" class="button lw-scan-stop"><?php esc_html_e( 'Stop', 'lw-scan' ); ?></button>
				</div>
			</div>

			<?php self::phases( $scope, (string) ( $progress['phase'] ?? '' ) ); ?>

			<div class="lw-scan-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Scan progress', 'lw-scan' ); ?>"
				aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) self::percent( $done, $total ) ); ?>">
				<span data-lw-scan-bar style="width:<?php echo esc_attr( (string) self::percent( $done, $total ) ); ?>%"></span>
			</div>

			<div class="lw-scan-row lw-scan-row--between lw-scan-small lw-scan-muted">
				<span>
					<span data-lw-scan-done><?php echo esc_html( Format::number( $done ) ); ?></span>
					/
					<span data-lw-scan-total><?php echo esc_html( Format::number( $total ) ); ?></span>
					<?php esc_html_e( 'items in this phase', 'lw-scan' ); ?>
				</span>
				<span><?php esc_html_e( 'Now:', 'lw-scan' ); ?> <code data-lw-scan-phase-name><?php echo esc_html( self::phase_label( (string) ( $progress['phase'] ?? '' ) ) ); ?></code></span>
			</div>

			<p class="lw-scan-feedback" role="status" aria-live="polite"></p>
		</div>

		<div class="lw-scan-card lw-scan-col">
			<h2 class="lw-scan-section-title">
				<?php esc_html_e( 'Findings so far', 'lw-scan' ); ?>
				<span class="lw-scan-pill lw-scan-pill--<?php echo esc_attr( (int) $progress['findings_new'] > 0 ? 'alert' : 'muted' ); ?>" data-lw-scan-found><?php echo esc_html( Format::number( (int) $progress['findings_new'] ) ); ?></span>
			</h2>
			<div class="lw-scan-feed" data-lw-scan-feed><?php self::feed( $started ); ?></div>
			<p class="lw-scan-muted lw-scan-small"><?php esc_html_e( 'Findings appear as they are found. Notification e-mail goes out only for new alerts when the run finishes.', 'lw-scan' ); ?></p>
		</div>
		<?php
	}

	/**
	 * @param string $scope   Run scope, which decides the phase subset.
	 * @param string $current Phase the cursor sits on.
	 */
	private static function phases( string $scope, string $current ): void {
		$phases = Phases::for_scope( $scope );
		$phases = [] === $phases ? Phases::ALL : $phases;
		$labels = self::labels();
		$index  = array_search( $current, $phases, true );
		$index  = false === $index ? 0 : (int) $index;

		echo '<div class="lw-scan-phases">';

		foreach ( $phases as $position => $phase ) {
			$state = $position < $index ? 'is-done' : ( $position === $index ? 'is-active' : '' );

			printf(
				'<div class="lw-scan-phase %1$s" data-lw-scan-phase="%2$s"><span class="lw-scan-phase-n">%3$s</span><span class="lw-scan-phase-d" data-lw-scan-phase-d>%4$s</span></div>',
				esc_attr( $state ),
				esc_attr( $phase ),
				esc_html( $labels[ $phase ] ?? $phase ),
				esc_html( 'is-done' === $state ? __( 'done', 'lw-scan' ) : '' )
			);
		}

		echo '</div>';
	}

	/**
	 * The last findings this run raised, newest first.
	 *
	 * @param int $started Run start timestamp.
	 */
	private static function feed( int $started ): void {
		$findings = array_slice( FindingsRepository::new_since( $started ), 0, self::FEED_LIMIT );

		if ( [] === $findings ) {
			printf( '<div class="lw-scan-feed-empty lw-scan-muted">%s</div>', esc_html__( 'Nothing found yet.', 'lw-scan' ) );

			return;
		}

		foreach ( $findings as $finding ) {
			$severity = (string) ( $finding['severity'] ?? 'review' );
			$type     = (string) ( $finding['type'] ?? 'file' );
			?>
			<div class="lw-scan-feed-row">
				<span class="lw-scan-pill lw-scan-pill--<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( $severity ); ?></span>
				<span><?php Icons::render( Icons::for_type( $type ) ); ?> <?php echo esc_html( $type ); ?></span>
				<span class="lw-scan-mono"><?php echo esc_html( (string) ( $finding['locator'] ?? '' ) ); ?></span>
				<span class="lw-scan-muted"><?php echo esc_html( (string) ( $finding['reason'] ?? '' ) ); ?></span>
			</div>
			<?php
		}
	}

	private static function phase_label( string $phase ): string {
		$labels = self::labels();

		return $labels[ $phase ] ?? $phase;
	}

	/**
	 * "~1 m 20 s remaining", once there is enough progress to extrapolate.
	 *
	 * @param int $done    Items finished in the current phase.
	 * @param int $total   Items the current phase has to get through.
	 * @param int $elapsed Seconds since the run started.
	 */
	private static function eta( int $done, int $total, int $elapsed ): string {
		if ( $done < 1 || $total <= $done || $elapsed < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: estimated remaining time. */
			__( '~%s remaining', 'lw-scan' ),
			Format::duration( (int) round( ( $elapsed / $done ) * ( $total - $done ) ) )
		);
	}

	private static function percent( int $done, int $total ): int {
		return $total > 0 ? (int) min( 100, round( ( $done / $total ) * 100 ) ) : 0;
	}
}
