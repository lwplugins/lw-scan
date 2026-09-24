/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Translated UI strings for `@lwplugins/data-table` (it has no text domain).
 *
 * @return {Object} Labels.
 */
export function tableLabels() {
	return {
		search: __( 'Search', 'lw-scan' ),
		filter: __( 'Filter', 'lw-scan' ),
		clear: __( 'Clear', 'lw-scan' ),
		clearAll: __( 'Clear all filters', 'lw-scan' ),
		all: __( 'All', 'lw-scan' ),
		empty: __( 'No findings match these filters.', 'lw-scan' ),
		emptyAll: __(
			'No findings. Everything scanned so far looks clean.',
			'lw-scan'
		),
		loading: __( 'Loading…', 'lw-scan' ),
		previous: __( 'Previous page', 'lw-scan' ),
		next: __( 'Next page', 'lw-scan' ),
		perPage: __( 'Rows per page', 'lw-scan' ),
		selectAll: __( 'Select all findings on this page', 'lw-scan' ),
		clearSelection: __( 'Clear selection', 'lw-scan' ),
		bulkActions: __( 'Bulk actions', 'lw-scan' ),
		entries: ( n ) =>
			sprintf(
				/* translators: %d: number of rows. */ _n(
					'%d entry',
					'%d entries',
					n,
					'lw-scan'
				),
				n
			),
		results: ( n ) =>
			sprintf(
				/* translators: %d: number of results. */ _n(
					'%d result',
					'%d results',
					n,
					'lw-scan'
				),
				n
			),
		page: ( p, t ) =>
			sprintf(
				/* translators: 1: current page, 2: total pages. */ __(
					'Page %1$d of %2$d',
					'lw-scan'
				),
				p,
				t
			),
		selectRow: ( label ) =>
			sprintf(
				/* translators: %s: finding location. */ __(
					'Select: %s',
					'lw-scan'
				),
				label
			),
		selected: ( n, onPage ) =>
			n === onPage
				? sprintf(
						/* translators: %d: number of selected findings. */
						_n( '%d selected', '%d selected', n, 'lw-scan' ),
						n
					)
				: sprintf(
						/* translators: 1: selected findings, 2: of those, on this page. */
						__( '%1$d selected, %2$d on this page', 'lw-scan' ),
						n,
						onPage
					),
		eligible: ( e, n ) =>
			sprintf(
				/* translators: 1: findings the action applies to, 2: selected findings on this page. */ __(
					'applies to %1$d of %2$d',
					'lw-scan'
				),
				e,
				n
			),
	};
}
