<?php
/**
 * The Health tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Health\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.1/§12: the environment report, the crontab lines worth copying
 * when WP-Cron is unreliable, and the bundle/index maintenance buttons.
 *
 * Opening this tab is the one place allowed to build a *fresh* report —
 * that is what lets `Health\CronCheck` fire its loopback probe.
 */
final class TabHealth implements TabInterface {

	public function slug(): string {
		return 'health';
	}

	public function label(): string {
		return __( 'Health', 'lw-scan' );
	}

	public function icon(): string {
		return 'dashicons-heart';
	}

	public function has_save(): bool {
		return false;
	}

	public function render(): void {
		$report = Environment::report( true );
		$rows   = $report['rows'];

		echo '<div class="lw-scan-stack" data-lw-scan-tab="health">';
		self::hero( $report['verdict'], $rows, $report['tiles'] );
		self::checks( $rows );
		self::cron_card( $rows );
		self::bundle_card();
		echo '</div>';
	}

	/**
	 * @param string                           $verdict ok|warning|critical.
	 * @param array<int, array<string, mixed>> $rows    Report rows.
	 * @param array<string, mixed>             $tiles   Report tiles.
	 */
	private static function hero( string $verdict, array $rows, array $tiles ): void {
		$bundle = is_array( $tiles['bundle'] ?? null ) ? $tiles['bundle'] : [];
		?>
		<div class="lw-scan-card lw-scan-hero">
			<div class="lw-scan-hero-main">
				<div class="lw-scan-hero-verdict lw-scan-verdict--<?php echo esc_attr( self::verdict_variant( $verdict ) ); ?>">
					<?php Icons::render( Icons::for_status( $verdict ) ); ?>
					<span><?php echo esc_html( self::verdict_text( $verdict, $rows ) ); ?></span>
				</div>
				<div class="lw-scan-hero-sub"><?php echo esc_html( self::verdict_detail( $verdict, $rows ) ); ?></div>
			</div>
			<div class="lw-scan-tiles lw-scan-tiles--three">
				<?php
				self::tile(
					__( 'Signatures', 'lw-scan' ),
					0 === (int) ( $bundle['version'] ?? 0 ) ? __( 'none', 'lw-scan' ) : (string) $bundle['version'],
					sprintf(
						/* translators: 1: number of signature rules. 2: when the backend was last checked. */
						__( '%1$s rules · checked %2$s', 'lw-scan' ),
						Format::number( (int) ( $bundle['count'] ?? 0 ) ),
						Format::datetime( (int) ( $bundle['checked_at'] ?? 0 ) )
					)
				);
				self::tile(
					__( 'Checksums cached', 'lw-scan' ),
					Format::number( (int) ( $tiles['checksums'] ?? 0 ) ),
					__( 'wordpress.org package checksums', 'lw-scan' )
				);
				self::tile(
					__( 'Vulnerability data', 'lw-scan' ),
					Format::number( (int) ( $tiles['vuln'] ?? 0 ) ),
					__( 'lookups cached · 24 h', 'lw-scan' )
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Report rows.
	 */
	private static function checks( array $rows ): void {
		?>
		<div class="lw-scan-card lw-scan-card--flush">
			<div class="lw-scan-hl lw-scan-hl--head">
				<span></span>
				<span><?php esc_html_e( 'Check', 'lw-scan' ); ?></span>
				<span><?php esc_html_e( 'Result', 'lw-scan' ); ?></span>
				<span><?php esc_html_e( 'Status', 'lw-scan' ); ?></span>
			</div>
			<?php foreach ( $rows as $row ) : ?>
				<?php $status = (string) ( $row['status'] ?? 'ok' ); ?>
				<div class="lw-scan-hl lw-scan-hl--<?php echo esc_attr( self::verdict_variant( $status ) ); ?>">
					<?php Icons::render( Icons::for_status( $status ) ); ?>
					<span><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></span>
					<span class="lw-scan-muted"><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></span>
					<span class="lw-scan-pill lw-scan-pill--<?php echo esc_attr( self::verdict_variant( $status ) ); ?>"><?php echo esc_html( $status ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Report rows.
	 */
	private static function cron_card( array $rows ): void {
		$cron_ok = 'ok' === self::row_status( $rows, 'cron' );
		?>
		<div class="lw-scan-card lw-scan-col">
			<h2 class="lw-scan-section-title">
				<?php echo esc_html( $cron_ok ? __( 'Run scans from a system cron', 'lw-scan' ) : __( 'Recommended fix for the warning', 'lw-scan' ) ); ?>
			</h2>
			<p class="lw-scan-muted lw-scan-nomargin"><?php esc_html_e( "Run the scan from a system cron so it does not depend on site traffic. Paste one of these into the server's crontab:", 'lw-scan' ); ?></p>
			<?php foreach ( Environment::cron_command_lines() as $line ) : ?>
				<div class="lw-scan-copy">
					<code><?php echo esc_html( $line ); ?></code>
					<button type="button" class="lw-scan-copy-btn" data-copy="<?php echo esc_attr( $line ); ?>"><?php esc_html_e( 'Copy', 'lw-scan' ); ?></button>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function bundle_card(): void {
		?>
		<div class="lw-scan-card lw-scan-row lw-scan-row--between lw-scan-row--wrap">
			<div>
				<strong><?php esc_html_e( 'Signature bundle', 'lw-scan' ); ?></strong>
				<div class="lw-scan-muted lw-scan-small"><?php echo esc_html( BundleInfo::describe() ); ?></div>
			</div>
			<div class="lw-scan-row lw-scan-row--wrap" data-lw-scan-maintenance="1">
				<button type="button" class="button lw-scan-bundle" data-op="check"><?php esc_html_e( 'Check for updates', 'lw-scan' ); ?></button>
				<button type="button" class="button lw-scan-bundle" data-op="full"><?php esc_html_e( 'Re-download full bundle', 'lw-scan' ); ?></button>
				<button type="button" class="button lw-scan-index" data-op="rebuild"><?php esc_html_e( 'Rebuild file index', 'lw-scan' ); ?></button>
				<button type="button" class="button lw-scan-health"><?php esc_html_e( 'Run checks again', 'lw-scan' ); ?></button>
				<span class="lw-scan-feedback" role="status" aria-live="polite"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string                           $verdict ok|warning|critical.
	 * @param array<int, array<string, mixed>> $rows    Report rows.
	 */
	private static function verdict_text( string $verdict, array $rows ): string {
		if ( 'critical' === $verdict ) {
			return __( 'Scanning is blocked', 'lw-scan' );
		}

		if ( 'warning' !== $verdict ) {
			return __( 'Ready', 'lw-scan' );
		}

		$warnings = count( array_filter( $rows, static fn ( array $row ): bool => 'warning' === ( $row['status'] ?? '' ) ) );

		return sprintf(
			/* translators: %d: number of health checks that returned a warning. */
			_n( 'Ready, with %d warning', 'Ready, with %d warnings', $warnings, 'lw-scan' ),
			$warnings
		);
	}

	/**
	 * @param string                           $verdict ok|warning|critical.
	 * @param array<int, array<string, mixed>> $rows    Report rows.
	 */
	private static function verdict_detail( string $verdict, array $rows ): string {
		if ( 'ok' === $verdict ) {
			return __( 'Scanning is enabled. Nothing needs your attention here.', 'lw-scan' );
		}

		foreach ( $rows as $row ) {
			if ( ( $row['status'] ?? '' ) === $verdict ) {
				return (string) ( $row['message'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Report rows.
	 * @param string                           $id   Check id.
	 */
	private static function row_status( array $rows, string $id ): string {
		foreach ( $rows as $row ) {
			if ( ( $row['id'] ?? '' ) === $id ) {
				return (string) ( $row['status'] ?? 'ok' );
			}
		}

		return 'ok';
	}

	private static function verdict_variant( string $status ): string {
		if ( 'critical' === $status ) {
			return 'alert';
		}

		return 'warning' === $status ? 'review' : 'ok';
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
