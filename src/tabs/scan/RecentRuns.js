/**
 * External dependencies
 */
import { DataTable, useTableState } from '@lwplugins/data-table';
import '@lwplugins/data-table/style.css';

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import StatusBadge from '../../components/StatusBadge';
import {
	formatDatetime,
	formatDuration,
	formatNumber,
} from '../../data/format';
import { RUN_STATUS, SCOPES, TRIGGERS } from '../../data/labels';
import { tableLabels } from '../findings/tableLabels';

const COLUMNS = [
	{
		id: 'started_at',
		label: __( 'Started', 'lw-scan' ),
		render: ( run ) => formatDatetime( run.started_at ),
	},
	{
		id: 'trigger',
		label: __( 'Trigger', 'lw-scan' ),
		render: ( run ) => TRIGGERS[ run.trigger ] || run.trigger,
	},
	{
		id: 'scope',
		label: __( 'Scope', 'lw-scan' ),
		render: ( run ) => SCOPES[ run.scope ] || run.scope,
	},
	{
		id: 'duration',
		label: __( 'Duration', 'lw-scan' ),
		render: ( run ) =>
			run.finished_at
				? formatDuration( run.finished_at - run.started_at )
				: '—',
	},
	{
		id: 'indexed',
		label: __( 'Indexed', 'lw-scan' ),
		align: 'end',
		render: ( run ) => formatNumber( run.indexed ),
	},
	{
		id: 'scanned',
		label: __( 'Deep-scanned', 'lw-scan' ),
		align: 'end',
		render: ( run ) => formatNumber( run.scanned ),
	},
	{
		id: 'found',
		label: __( 'New findings', 'lw-scan' ),
		render: ( run ) =>
			! run.alerts_new && ! run.review_new ? (
				<span className="lw-admin-muted">
					{ __( 'none', 'lw-scan' ) }
				</span>
			) : (
				<span className="lw-admin-inline">
					{ run.alerts_new > 0 && (
						<StatusBadge status="critical">
							{ sprintf(
								/* translators: %d: number of alerts. */
								_n(
									'%d alert',
									'%d alerts',
									run.alerts_new,
									'lw-scan'
								),
								run.alerts_new
							) }
						</StatusBadge>
					) }
					{ run.review_new > 0 && (
						<StatusBadge status="warning">
							{ sprintf(
								/* translators: %d: number of review items. */
								_n(
									'%d review',
									'%d reviews',
									run.review_new,
									'lw-scan'
								),
								run.review_new
							) }
						</StatusBadge>
					) }
				</span>
			),
	},
	{
		id: 'status',
		label: __( 'Status', 'lw-scan' ),
		render: ( run ) => (
			<span className="lw-admin-stack">
				<StatusBadge status={ RUN_STATUS[ run.status ] || 'idle' }>
					{ run.status }
				</StatusBadge>
				{ run.error_label && (
					<span className="lw-admin-hint">{ run.error_label }</span>
				) }
			</span>
		),
	},
];

/**
 * The latest 10 runs.
 *
 * @param {Object} props
 * @param {Array}  props.runs Runs.
 */
export default function RecentRuns( { runs } ) {
	const table = useTableState( runs, { perPage: 10 } );

	return (
		<Section title={ __( 'Recent runs', 'lw-scan' ) }>
			<DataTable
				columns={ COLUMNS }
				table={ table }
				searchable={ false }
				caption={ __( 'Recent runs', 'lw-scan' ) }
				labels={ {
					...tableLabels(),
					emptyAll: __( 'No scan has run yet.', 'lw-scan' ),
				} }
			/>
		</Section>
	);
}
