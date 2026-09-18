<?php
/**
 * The Settings tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Every option of spec §4.4, in the mockup's three groups. This is the
 * only tab wrapped in the `options.php` form, so it is the only one with a
 * Save button; `Admin\SettingsSanitizer` validates what it posts.
 */
final class TabSettings implements TabInterface {

	use FieldRendererTrait;

	public function slug(): string {
		return 'settings';
	}

	public function label(): string {
		return __( 'Settings', 'lw-scan' );
	}

	public function icon(): string {
		return 'dashicons-admin-settings';
	}

	public function has_save(): bool {
		return true;
	}

	public function render(): void {
		$this->schedule_card();
		$this->depth_card();
		$this->notifications_card();
	}

	private function schedule_card(): void {
		?>
		<div class="lw-scan-card">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'Scheduled scan', 'lw-scan' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="schedule"><?php esc_html_e( 'Frequency', 'lw-scan' ); ?></label></th>
					<td>
						<?php
						$this->render_select(
							'schedule',
							[
								'daily'  => __( 'Daily', 'lw-scan' ),
								'hourly' => __( 'Hourly', 'lw-scan' ),
								'weekly' => __( 'Weekly', 'lw-scan' ),
								'off'    => __( 'Off', 'lw-scan' ),
							]
						);
						?>
						<span class="lw-scan-muted"><?php esc_html_e( 'at', 'lw-scan' ); ?></span>
						<?php $this->render_select( 'schedule_hour', self::hours() ); ?>
						<span class="lw-scan-muted lw-scan-small"><?php echo esc_html( self::timezone_note() ); ?></span>
						<p class="description"><?php echo esc_html( self::next_run_note() ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Scope', 'lw-scan' ); ?></th>
					<td>
						<?php
						$this->render_segment(
							'scope',
							[
								'changed' => RunsTable::scope_label( 'changed' ),
								'full'    => RunsTable::scope_label( 'full' ),
								'db'      => RunsTable::scope_label( 'db' ),
							],
							__( 'Changed-files mode re-scans only files whose size or modification time changed, plus every non-known-good file when new signatures arrive.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bundle_auto_update"><?php esc_html_e( 'Signature updates', 'lw-scan' ); ?></label></th>
					<td>
						<?php
						$this->render_select(
							'bundle_auto_update',
							[
								'1' => __( 'Automatically before each scheduled scan', 'lw-scan' ),
								'0' => __( 'Manually (Health tab)', 'lw-scan' ),
							]
						);
						?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	private function depth_card(): void {
		?>
		<div class="lw-scan-card">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'Scan depth', 'lw-scan' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Heuristic layer', 'lw-scan' ); ?></th>
					<td>
						<?php
						$this->render_toggle(
							'heuristics',
							__( 'Enabled', 'lw-scan' ),
							__( 'Token-level analysis of unknown PHP files: input to dangerous call pairs, identifier entropy, static decoding, disguise headers. Adds "review" findings only, never "alert" on its own.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="max_file_size"><?php esc_html_e( 'Per-file size limit', 'lw-scan' ); ?></label></th>
					<td>
						<?php
						$this->render_select(
							'max_file_size',
							self::file_sizes(),
							__( 'Larger files get type detection and hash matching only. A large PHP file skipped this way is listed as "review".', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="excluded_paths"><?php esc_html_e( 'Excluded paths', 'lw-scan' ); ?></label></th>
					<td>
						<?php
						$this->render_textarea( 'excluded_paths', implode( "\n", (array) Options::get( 'excluded_paths', [] ) ), 5 );
						printf(
							'<p class="description">%1$s <code>*</code> %2$s <code>wp-content/lw-scan</code> %3$s</p>',
							esc_html__( 'One pattern per line, relative to the WordPress root.', 'lw-scan' ),
							esc_html__( 'matches any characters.', 'lw-scan' ),
							esc_html__( 'is always excluded.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Follow symlinks', 'lw-scan' ); ?></th>
					<td>
						<?php
						$this->render_toggle(
							'follow_symlinks',
							__( 'Follow symlinked directories', 'lw-scan' ),
							__( 'Off is safer: symlinked directories outside the site are not scanned.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	private function notifications_card(): void {
		?>
		<div class="lw-scan-card">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'Notifications', 'lw-scan' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="notify_emails"><?php esc_html_e( 'Recipients', 'lw-scan' ); ?></label></th>
					<td>
						<?php
						$this->render_text(
							'notify_emails',
							implode( ', ', (array) Options::get( 'notify_emails', [] ) ),
							__( 'Comma-separated. Leave empty to use the site admin e-mail.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Send e-mail for', 'lw-scan' ); ?></th>
					<td>
						<?php
						$this->render_segment(
							'notify_level',
							[
								'alert'  => __( 'New alerts only', 'lw-scan' ),
								'review' => __( 'New alerts + review items', 'lw-scan' ),
							],
							__( 'No "all clear" e-mails. After three consecutive failed runs one warning is sent, then silence until a run succeeds.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin notice', 'lw-scan' ); ?></th>
					<td><?php $this->render_toggle( 'admin_notice', __( 'Show a persistent notice while new alerts exist', 'lw-scan' ) ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * @return array<array-key, string>
	 */
	private static function hours(): array {
		$hours = [];

		for ( $hour = 0; $hour < 24; $hour++ ) {
			$hours[ (string) $hour ] = sprintf( '%02d:00', $hour );
		}

		return $hours;
	}

	/**
	 * @return array<array-key, string>
	 */
	private static function file_sizes(): array {
		$sizes = [];

		foreach ( [ 524288, 1048576, 2097152, 5242880, 10485760 ] as $bytes ) {
			$sizes[ (string) $bytes ] = (string) size_format( $bytes );
		}

		return $sizes;
	}

	private static function timezone_note(): string {
		return sprintf(
			/* translators: %s: the site's timezone, e.g. Europe/Budapest. */
			__( 'site time (%s)', 'lw-scan' ),
			(string) wp_timezone_string()
		);
	}

	private static function next_run_note(): string {
		$due = (int) Options::get( 'next_due', 0 );

		if ( 'off' === (string) Options::get( 'schedule' ) || $due <= 0 ) {
			return __( 'Scheduled scans are off. Start one from the Scan tab whenever you need it.', 'lw-scan' );
		}

		return sprintf(
			/* translators: %s: date and time of the next scheduled run. */
			__( 'Next run: %s. If WP-Cron misses it, the next page load catches up in the background.', 'lw-scan' ),
			Format::datetime( $due )
		);
	}
}
