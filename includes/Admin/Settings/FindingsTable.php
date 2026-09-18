<?php
/**
 * The findings list table.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Header, rows (each drawn by `FindingRow`), the bulk-action bar and the
 * pager. State changes go through `assets/js/admin.js` and the
 * `lw_scan_finding_state` endpoint, so the table itself is not a form —
 * the checkboxes only carry ids for that script.
 */
final class FindingsTable {

	/** Rows per page (spec §11.1). */
	public const PER_PAGE = 50;

	/**
	 * @param array<int, array<string, mixed>> $items Findings rows for this page.
	 * @param int                              $total Total rows matching the filters.
	 * @param int                              $page  Current 1-based page.
	 */
	public static function render( array $items, int $total, int $page ): void {
		if ( [] === $items ) {
			printf(
				'<div class="lw-scan-card"><p class="lw-scan-muted">%s</p></div>',
				esc_html__( 'No findings match these filters.', 'lw-scan' )
			);

			return;
		}
		?>
		<table class="lw-scan-table lw-scan-findings" data-lw-scan-findings="1">
			<thead>
				<tr>
					<th scope="col" class="manage-column column-cb check-column">
						<label class="screen-reader-text" for="lw-scan-cb-all"><?php esc_html_e( 'Select all findings', 'lw-scan' ); ?></label>
						<input type="checkbox" id="lw-scan-cb-all" class="lw-scan-cb-all" />
					</th>
					<th scope="col"><?php esc_html_e( 'Severity', 'lw-scan' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'lw-scan' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where', 'lw-scan' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detected by', 'lw-scan' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last seen', 'lw-scan' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'lw-scan' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $finding ) : ?>
					<?php FindingRow::render( $finding ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="lw-scan-row lw-scan-row--between lw-scan-row--wrap">
			<?php self::bulk_bar(); ?>
			<?php self::pager( $total, $page ); ?>
		</div>
		<p class="lw-scan-muted lw-scan-small"><?php esc_html_e( 'Ignored findings stay hidden until a new signature matches the same file. Clearing empties this list; the next scan re-checks every file and reports again whatever is still there. Nothing here modifies site files or content.', 'lw-scan' ); ?></p>
		<?php
	}

	private static function bulk_bar(): void {
		?>
		<div class="lw-scan-row lw-scan-bulk">
			<label class="screen-reader-text" for="lw-scan-bulk"><?php esc_html_e( 'Bulk action', 'lw-scan' ); ?></label>
			<select id="lw-scan-bulk" class="lw-scan-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk actions', 'lw-scan' ); ?></option>
				<option value="acknowledged"><?php esc_html_e( 'Acknowledge', 'lw-scan' ); ?></option>
				<option value="ignored"><?php esc_html_e( 'Ignore', 'lw-scan' ); ?></option>
				<option value="new"><?php esc_html_e( 'Reopen', 'lw-scan' ); ?></option>
			</select>
			<button type="button" class="button lw-scan-bulk-apply"><?php esc_html_e( 'Apply', 'lw-scan' ); ?></button>
			<button type="button" class="button lw-scan-clear">
				<?php Icons::render( 'trash' ); ?>
				<?php esc_html_e( 'Clear all findings', 'lw-scan' ); ?>
			</button>
			<span class="lw-scan-feedback" role="status" aria-live="polite"></span>
		</div>
		<?php
	}

	/**
	 * @param int $total Total rows matching the filters.
	 * @param int $page  Current 1-based page.
	 */
	private static function pager( int $total, int $page ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			[
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => max( 1, $page ),
				'total'     => $pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'type'      => 'array',
			]
		);

		echo '<div class="tablenav-pages lw-scan-pager">';

		foreach ( $links as $link ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns core-built, already escaped anchor markup.
			echo $link;
		}

		echo '</div>';
	}
}
