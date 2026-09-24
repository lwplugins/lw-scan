/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

// The combined "view" chips of the classic Findings tab.
export const VIEWS = {
	all: { state: '', severity: '' },
	alerts: { state: 'new', severity: 'alert' },
	review: { state: 'new', severity: 'review' },
	acknowledged: { state: 'acknowledged', severity: '' },
	ignored: { state: 'ignored', severity: '' },
};

export const VIEW_LABELS = {
	all: __( 'All', 'lw-scan' ),
	alerts: __( 'Alerts', 'lw-scan' ),
	review: __( 'Review', 'lw-scan' ),
	acknowledged: __( 'Acknowledged', 'lw-scan' ),
	ignored: __( 'Ignored', 'lw-scan' ),
};

export const TYPE_LABELS = {
	file: __( 'Files', 'lw-scan' ),
	integrity: __( 'Integrity', 'lw-scan' ),
	db: __( 'Database', 'lw-scan' ),
	vulnerability: __( 'Vulnerabilities', 'lw-scan' ),
};

/**
 * Initial table query from the classic ?severity&type&state&s&paged URL
 * (notification e-mails still link there).
 *
 * @param {Object} params Server-sanitized params.
 * @return {Object} data-table query.
 */
export function initialQuery( params ) {
	const view =
		Object.keys( VIEWS ).find(
			( key ) =>
				key !== 'all' &&
				VIEWS[ key ].state === ( params.state || '' ) &&
				VIEWS[ key ].severity === ( params.severity || '' )
		) || '';
	return {
		search: params.s || '',
		filters: {
			view: view ? [ view ] : [],
			type: params.type ? [ params.type ] : [],
		},
		sort: undefined,
		page: Math.max( 1, Number( params.paged ) || 1 ),
		perPage: 50,
	};
}

/**
 * REST args for GET /findings.
 *
 * @param {Object} query data-table query.
 * @return {Object} Args.
 */
export function toArgs( query ) {
	const view = VIEWS[ query.filters.view?.[ 0 ] ] || VIEWS.all;
	return {
		state: view.state || undefined,
		severity: view.severity || undefined,
		type: query.filters.type?.[ 0 ] || undefined,
		search: query.search || undefined,
		page: query.page,
		per_page: query.perPage,
	};
}

/**
 * Chip counts from counts().
 *
 * @param {Object} counts FindingsRepository::counts().
 * @return {Object} Count per view id and per type.
 */
export function chipCounts( counts ) {
	const sum = ( state ) =>
		Object.values( counts?.state?.[ state ] || {} ).reduce(
			( a, b ) => a + b,
			0
		);
	return {
		all: counts?.total || 0,
		alerts: counts?.state?.new?.alert || 0,
		review: counts?.state?.new?.review || 0,
		acknowledged: sum( 'acknowledged' ),
		ignored: sum( 'ignored' ),
		...counts?.type,
	};
}
