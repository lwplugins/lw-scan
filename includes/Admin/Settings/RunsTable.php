<?php
/**
 * The Scan tab's run history table.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\RunError;

defined( 'ABSPATH' ) || exit;

/**
 * Ten rows of `{prefix}lw_scan_runs`, plus the scope/trigger vocabulary
 * the hero reuses for its "Last scan: … (scheduled, changed files +
 * database)" line, so both places name a scope the same way.
 */
final class RunsTable {

	/**
	 * @param array<int, array<string, mixed>> $runs Run rows, newest first, `stats` decoded.
	 */
	public static function render( array $runs ): void {
		?>
		<div class="lw-scan-card lw-scan-col">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'Recent runs', 'lw-scan' ); ?></h2>
			<?php if ( [] === $runs ) : ?>
				<p class="lw-scan-muted"><?php esc_html_e( 'No scan has run yet.', 'lw-scan' ); ?></p>
			<?php else : ?>
				<table class="lw-scan-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Started', 'lw-scan' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Trigger', 'lw-scan' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Scope', 'lw-scan' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Duration', 'lw-scan' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Indexed', 'lw-scan' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Deep-scanned', 'lw-scan' ); ?></th>
							<th scope="col"><?php esc_html_e( 'New findings', 'lw-scan' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'lw-scan' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $runs as $run ) : ?>
							<?php self::row( $run ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $run One run row.
	 */
	private static function row( array $run ): void {
		$stats    = RunStatsView::of( $run );
		$finished = (int) ( $run['finished_at'] ?? 0 );
		$started  = (int) ( $run['started_at'] ?? 0 );
		$status   = (string) ( $run['status'] ?? '' );
		$measured = isset( $run['stats'] ) && is_array( $run['stats'] ) ? $run['stats'] : [];
		$error    = RunError::label( (string) ( $run['error'] ?? '' ), $measured );
		?>
		<tr>
			<td><?php echo esc_html( Format::datetime( $started ) ); ?></td>
			<td><?php echo esc_html( self::trigger_label( (string) ( $run['trigger_kind'] ?? '' ) ) ); ?></td>
			<td><?php echo esc_html( self::scope_label( (string) ( $run['scope'] ?? '' ) ) ); ?></td>
			<td><?php echo esc_html( $finished > $started ? Format::duration( $finished - $started ) : '—' ); ?></td>
			<td><?php echo esc_html( Format::number( $stats->files_indexed() ) ); ?></td>
			<td><?php echo esc_html( Format::number( $stats->files_scanned() ) ); ?></td>
			<td><?php self::findings( $stats ); ?></td>
			<td>
				<span class="lw-scan-pill lw-scan-pill--<?php echo esc_attr( self::status_variant( $status ) ); ?>"><?php echo esc_html( $status ); ?></span>
				<?php if ( '' !== $error ) : ?>
					<span class="lw-scan-muted lw-scan-small"><?php echo esc_html( $error ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function findings( RunStatsView $stats ): void {
		$alerts = $stats->alerts_new();
		$review = $stats->review_new();

		if ( 0 === $alerts && 0 === $review ) {
			printf( '<span class="lw-scan-muted">%s</span>', esc_html__( 'none', 'lw-scan' ) );

			return;
		}

		if ( $alerts > 0 ) {
			printf(
				'<span class="lw-scan-pill lw-scan-pill--alert">%s</span> ',
				/* translators: %d: number of new alert-severity findings. */
				esc_html( sprintf( _n( '%d alert', '%d alerts', $alerts, 'lw-scan' ), $alerts ) )
			);
		}

		if ( $review > 0 ) {
			printf(
				'<span class="lw-scan-pill lw-scan-pill--review">%s</span>',
				/* translators: %d: number of new review-severity findings. */
				esc_html( sprintf( _n( '%d review', '%d reviews', $review, 'lw-scan' ), $review ) )
			);
		}
	}

	public static function trigger_label( string $trigger ): string {
		$labels = [
			'manual'  => __( 'manual', 'lw-scan' ),
			'cron'    => __( 'scheduled', 'lw-scan' ),
			'catchup' => __( 'catch-up', 'lw-scan' ),
			'cli'     => __( 'WP-CLI', 'lw-scan' ),
			'ability' => __( 'ability', 'lw-scan' ),
		];

		return $labels[ $trigger ] ?? $trigger;
	}

	public static function scope_label( string $scope ): string {
		$labels = [
			'changed' => __( 'changed files + database', 'lw-scan' ),
			'full'    => __( 'full site', 'lw-scan' ),
			'db'      => __( 'database only', 'lw-scan' ),
			'path'    => __( 'one folder', 'lw-scan' ),
		];

		return $labels[ $scope ] ?? $scope;
	}

	/**
	 * "daily · changed files + database" under the Next scheduled tile.
	 */
	public static function schedule_label(): string {
		$schedule = (string) Options::get( 'schedule' );

		if ( 'off' === $schedule ) {
			return __( 'scheduled scans are off', 'lw-scan' );
		}

		return $schedule . ' · ' . self::scope_label( (string) Options::get( 'scope' ) );
	}

	private static function status_variant( string $status ): string {
		$map = [
			'done'    => 'ok',
			'failed'  => 'alert',
			'stopped' => 'muted',
			'running' => 'info',
		];

		return $map[ $status ] ?? 'muted';
	}
}
