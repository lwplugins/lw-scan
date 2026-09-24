/**
 * External dependencies
 */
import { DataTable } from '@lwplugins/data-table';
import '@lwplugins/data-table/style.css';

/**
 * WordPress dependencies
 */
import {
	Button,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import { api, errorMessage } from '../../data/api';
import { INITIAL_FINDINGS, SCHEMA_EXISTS } from '../../data/boot';
import { missingTables } from '../scan/ScanTab';
import { findingColumns } from './columns';
import FindingDetails from './FindingDetails';
import {
	TYPE_LABELS,
	VIEW_LABELS,
	chipCounts,
	initialQuery,
	toArgs,
} from './query';
import { tableLabels } from './tableLabels';

const EMPTY = { items: [], total: 0, total_pages: 1, counts: null };

/**
 * Server-paged findings list: view + type chips, search, row and bulk state
 * changes, details, clear all.
 *
 * @param {Object}   props
 * @param {Function} props.onAlerts Update the sidebar alert count.
 */
export default function FindingsTab( { onAlerts } ) {
	const [ query, setQuery ] = useState( () =>
		initialQuery( INITIAL_FINDINGS )
	);
	const [ result, setResult ] = useState( EMPTY );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ details, setDetails ] = useState( null );
	const [ busyId, setBusyId ] = useState( 0 );
	const [ confirmClear, setConfirmClear ] = useState( false );
	const [ version, setVersion ] = useState( 0 );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	useEffect( () => {
		if ( ! SCHEMA_EXISTS ) {
			return;
		}
		// Abort the previous request: a slow response must never overwrite a newer one.
		const controller = new AbortController();
		setLoading( true );
		api.findings( toArgs( query ), controller.signal ).then(
			( data ) => {
				setResult( data );
				setError( null );
				setLoading( false );
				onAlerts( data.counts?.state?.new?.alert || 0 );
			},
			( e ) => {
				if ( e?.name !== 'AbortError' && ! controller.signal.aborted ) {
					setError( errorMessage( e ) );
					setLoading( false );
				}
			}
		);
		return () => controller.abort();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ query, version ] );

	if ( ! SCHEMA_EXISTS ) {
		return missingTables;
	}

	const refresh = () => setVersion( ( v ) => v + 1 );

	const setState = async ( ids, state ) => {
		try {
			const { updated } = await api.setFindingState( ids, state );
			createSuccessNotice(
				sprintf(
					/* translators: %d: number of findings updated. */
					_n(
						'%d finding updated.',
						'%d findings updated.',
						updated,
						'lw-scan'
					),
					updated
				),
				{ type: 'snackbar' }
			);
			setSelected( ( prev ) =>
				prev.filter( ( id ) => ! ids.includes( id ) )
			);
			refresh();
		} catch ( e ) {
			createErrorNotice( errorMessage( e ), { type: 'snackbar' } );
		}
	};

	const counts = chipCounts( result.counts );
	const withCount = ( labels ) =>
		Object.entries( labels ).map( ( [ value, label ] ) => ( {
			value,
			label: `${ label } · ${ counts[ value ] || 0 }`,
		} ) );

	const columns = findingColumns( {
		busyId,
		onDetails: setDetails,
		onState: async ( finding, state ) => {
			setBusyId( finding.id );
			await setState( [ finding.id ], state );
			setBusyId( 0 );
		},
	} );

	const clearAll = async () => {
		setConfirmClear( false );
		try {
			await api.clearFindings();
			createSuccessNotice( __( 'All findings cleared.', 'lw-scan' ), {
				type: 'snackbar',
			} );
			setSelected( [] );
			refresh();
		} catch ( e ) {
			createErrorNotice( errorMessage( e ), { type: 'snackbar' } );
		}
	};

	return (
		<Section>
			<DataTable
				columns={ columns }
				rows={ result.items }
				total={ result.total }
				totalPages={ result.total_pages }
				query={ query }
				onQueryChange={ ( next ) => {
					// A different list: drop the selection instead of keeping hidden rows selected.
					if (
						JSON.stringify( next.filters ) !==
							JSON.stringify( query.filters ) ||
						next.search !== query.search
					) {
						setSelected( [] );
					}
					setQuery( next );
				} }
				isLoading={ loading }
				error={ error }
				errorAction={
					<Button variant="secondary" onClick={ refresh }>
						{ __( 'Try again', 'lw-scan' ) }
					</Button>
				}
				filters={ [
					{
						field: 'view',
						label: __( 'Show', 'lw-scan' ),
						options: withCount( {
							alerts: VIEW_LABELS.alerts,
							review: VIEW_LABELS.review,
							acknowledged: VIEW_LABELS.acknowledged,
							ignored: VIEW_LABELS.ignored,
						} ),
						multiple: false,
					},
					{
						field: 'type',
						label: __( 'Type', 'lw-scan' ),
						options: withCount( TYPE_LABELS ),
						multiple: false,
					},
				] }
				toolbar={
					<Button
						size="compact"
						variant="tertiary"
						isDestructive
						icon={ trash }
						onClick={ () => setConfirmClear( true ) }
						disabled={ ! counts.all }
						accessibleWhenDisabled
					>
						{ __( 'Clear all findings', 'lw-scan' ) }
					</Button>
				}
				pagination="both"
				caption={ __( 'Findings', 'lw-scan' ) }
				getRowLabel={ ( f ) => f.title }
				selection={ { selected, onChange: setSelected } }
				bulkActions={ [
					{
						id: 'acknowledged',
						label: __( 'Acknowledge', 'lw-scan' ),
						isEligible: ( f ) => f.state !== 'acknowledged',
						onClick: ( rows ) =>
							setState(
								rows.map( ( f ) => f.id ),
								'acknowledged'
							),
					},
					{
						id: 'ignored',
						label: __( 'Ignore', 'lw-scan' ),
						isEligible: ( f ) => f.state !== 'ignored',
						onClick: ( rows ) =>
							setState(
								rows.map( ( f ) => f.id ),
								'ignored'
							),
					},
					{
						id: 'new',
						label: __( 'Reopen', 'lw-scan' ),
						isEligible: ( f ) => f.state !== 'new',
						onClick: ( rows ) =>
							setState(
								rows.map( ( f ) => f.id ),
								'new'
							),
					},
				] }
				labels={ {
					...tableLabels(),
					search: __( 'Search path, signature, plugin…', 'lw-scan' ),
				} }
			/>
			<p className="lw-admin-hint">
				{ __(
					'Ignored findings stay hidden until a new signature matches the same file. Clearing empties this list; the next scan re-checks every file and reports again whatever is still there. Nothing here modifies site files or content.',
					'lw-scan'
				) }
			</p>
			{ details && (
				<FindingDetails
					finding={ details }
					onClose={ () => setDetails( null ) }
				/>
			) }
			<ConfirmDialog
				isOpen={ confirmClear }
				confirmButtonText={ __( 'Clear all findings', 'lw-scan' ) }
				onConfirm={ clearAll }
				onCancel={ () => setConfirmClear( false ) }
			>
				{ __(
					'Delete every finding from this list? The next scan re-checks every file, so it takes about as long as the first one, and reports again anything that is still on the site.',
					'lw-scan'
				) }
			</ConfirmDialog>
		</Section>
	);
}
