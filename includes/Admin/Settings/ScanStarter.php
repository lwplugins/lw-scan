<?php
/**
 * The Scan tab's "Start a scan" bar.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Db\FilesRepository;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Cursor;

defined( 'ABSPATH' ) || exit;

/**
 * Scope picker, heuristics switch, Start button and the queue-size
 * estimate. Nothing here posts a form: `assets/js/admin.js` reads the
 * controls and calls `lw_scan_start`, so the page can say "you can leave
 * this page" and mean it.
 *
 * A stopped run leaves its cursor behind (`Run\Starter`), so this is also
 * where "Resume" appears.
 *
 * A fresh install has no signature bundle yet and the first scan is what
 * downloads one (`Health\Checks\BundleCheck` no longer blocks that start),
 * so the bar says so rather than letting the first run look stuck on its
 * bundle phase.
 */
final class ScanStarter {

	/** Spec §11.1: the estimate assumes 30 ms of work per queued file. */
	private const MS_PER_FILE = 30;

	public static function render(): void {
		$resumable = null !== Cursor::load();
		?>
		<div class="lw-scan-card lw-scan-col" data-lw-scan-starter="1">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'Start a scan', 'lw-scan' ); ?></h2>

			<div class="lw-scan-row lw-scan-row--wrap">
				<div class="lw-scan-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Scan scope', 'lw-scan' ); ?>">
					<?php self::scopes(); ?>
				</div>

				<label class="lw-scan-path-field" hidden>
					<span class="screen-reader-text"><?php esc_html_e( 'Folder to scan', 'lw-scan' ); ?></span>
					<input type="text" class="regular-text lw-scan-path" placeholder="wp-content/uploads" />
				</label>

				<label class="lw-scan-toggle">
					<input type="checkbox" class="lw-scan-switch lw-scan-heuristics" <?php checked( (bool) Options::get( 'heuristics' ), true ); ?> />
					<span><?php esc_html_e( 'Heuristic layer', 'lw-scan' ); ?></span>
				</label>

				<button type="button" class="button button-primary lw-scan-start"><?php esc_html_e( 'Start scan', 'lw-scan' ); ?></button>

				<?php if ( $resumable ) : ?>
					<button type="button" class="button lw-scan-start" data-resume="1"><?php esc_html_e( 'Resume stopped scan', 'lw-scan' ); ?></button>
				<?php endif; ?>

				<span class="lw-scan-muted lw-scan-small lw-scan-estimate"><?php echo esc_html( self::estimate() ); ?></span>
			</div>

			<?php if ( 0 === BundleInfo::version() ) : ?>
				<p class="lw-scan-muted lw-scan-small"><?php esc_html_e( 'The first scan downloads the signature bundle (about 1 MB).', 'lw-scan' ); ?></p>
			<?php endif; ?>

			<p class="lw-scan-feedback" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	private static function scopes(): void {
		$current = (string) Options::get( 'scope' );
		$scopes  = [
			'changed' => RunsTable::scope_label( 'changed' ),
			'full'    => RunsTable::scope_label( 'full' ),
			'db'      => RunsTable::scope_label( 'db' ),
			'path'    => RunsTable::scope_label( 'path' ),
		];

		foreach ( $scopes as $value => $label ) {
			printf(
				'<label class="%3$s"><input type="radio" name="lw_scan_scope" value="%1$s" %4$s /> %2$s</label>',
				esc_attr( $value ),
				esc_html( $label ),
				esc_attr( $current === $value ? 'is-on' : '' ),
				checked( $current, $value, false )
			);
		}
	}

	/**
	 * "Estimated ~10 s for 312 changed files. Runs in the background; you
	 * can leave this page." — the queue count times 30 ms.
	 */
	private static function estimate(): string {
		$queued = ( new FilesRepository() )->count_queue( BundleInfo::version() );

		if ( $queued <= 0 ) {
			return __( 'Runs in the background; you can leave this page.', 'lw-scan' );
		}

		return sprintf(
			/* translators: 1: estimated duration. 2: number of files waiting to be scanned. */
			__( 'Estimated ~%1$s for %2$s changed files. Runs in the background; you can leave this page.', 'lw-scan' ),
			Format::duration( (int) round( ( $queued * self::MS_PER_FILE ) / 1000 ) ),
			Format::number( $queued )
		);
	}
}
