/**
 * WordPress dependencies
 */
import { Button, ProgressBar } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import StatusBadge from '../../components/StatusBadge';
import { api, errorMessage } from '../../data/api';
import {
	formatClock,
	formatDatetime,
	formatDuration,
	formatNumber,
} from '../../data/format';
import { PHASES, SCOPES, SEVERITY, TYPES } from '../../data/labels';
import useScanPoll from './useScanPoll';

/**
 * Live view of a running scan: phases, progress, ETA, stop, findings feed.
 *
 * @param {Object}   props
 * @param {Object}   props.initial  GET /scan payload (progress + feed).
 * @param {Function} props.onFinish Reload the Scan tab when the run ends.
 * @param {Function} props.onAlerts Update the sidebar alert count.
 */
export default function RunProgress( { initial, onFinish, onAlerts } ) {
	const [ stopping, setStopping ] = useState( false );
	const [ stopError, setStopError ] = useState( '' );
	const { status, error } = useScanPoll(
		{ ...initial.progress, feed: initial.feed },
		( last ) => {
			onAlerts( last.counts?.state?.new?.alert ?? 0 );
			onFinish();
		}
	);

	const phases = status.phases || initial.progress.phases;
	const activeIndex = phases.indexOf( status.phase );
	const percent =
		status.total > 0
			? Math.min(
					100,
					Math.round( ( status.done / status.total ) * 100 )
				)
			: 0;
	const eta =
		status.done >= 1 && status.total > status.done && status.elapsed >= 1
			? ( status.elapsed / status.done ) * ( status.total - status.done )
			: null;

	const stop = async () => {
		setStopping( true );
		setStopError( '' );
		try {
			await api.stopScan();
		} catch ( e ) {
			setStopError( errorMessage( e ) );
			setStopping( false );
		}
	};

	return (
		<>
			<Section
				title={ sprintf(
					/* translators: 1: scan scope, 2: start time. */
					__( 'Scanning… %1$s · started %2$s', 'lw-scan' ),
					SCOPES[ status.scope || initial.progress.scope ] || '',
					formatDatetime(
						status.started_at || initial.progress.started_at
					)
				) }
				badge={
					<StatusBadge status="info">
						{ formatClock( status.elapsed ) }
					</StatusBadge>
				}
				actions={
					<Button
						variant="secondary"
						isDestructive
						isBusy={ stopping }
						disabled={ stopping }
						accessibleWhenDisabled
						onClick={ stop }
					>
						{ stopping
							? __( 'Stopping…', 'lw-scan' )
							: __( 'Stop', 'lw-scan' ) }
					</Button>
				}
			>
				<ol className="lw-admin-phases">
					{ phases.map( ( id, index ) => {
						let state = '';
						let detail = '';
						if ( index < activeIndex ) {
							state = 'is-done';
							detail = __( 'done', 'lw-scan' );
						} else if ( index === activeIndex ) {
							state = 'is-active';
							detail =
								status.total > 0
									? `${ formatNumber( status.done ) } / ${ formatNumber( status.total ) }`
									: '';
						}
						return (
							<li
								key={ id }
								className={ `lw-admin-phase ${ state }` }
								aria-current={
									index === activeIndex ? 'step' : undefined
								}
							>
								<span className="lw-admin-phase__name">
									{ PHASES[ id ] || id }
								</span>
								<span className="lw-admin-phase__detail">
									{ detail }
								</span>
							</li>
						);
					} ) }
				</ol>
				<div className="lw-admin-run-progress">
					<ProgressBar
						value={ percent }
						aria-label={ __( 'Scan progress', 'lw-scan' ) }
					/>
					<span>
						{ sprintf(
							/* translators: 1: items done, 2: items total, 3: current phase name. */
							__(
								'%1$s / %2$s items in this phase · Now: %3$s',
								'lw-scan'
							),
							formatNumber( status.done ),
							formatNumber( status.total ),
							PHASES[ status.phase ] || status.phase || '—'
						) }
						{ eta !== null &&
							` · ${ sprintf( /* translators: %s: duration. */ __( '~%s remaining', 'lw-scan' ), formatDuration( eta ) ) }` }
					</span>
				</div>
				{ ( error || stopError ) && (
					<p className="lw-admin-testresult is-error" role="alert">
						{ stopError || error }
					</p>
				) }
			</Section>

			<Section
				title={ __( 'Findings so far', 'lw-scan' ) }
				badge={
					<StatusBadge
						status={ status.findings_new > 0 ? 'critical' : 'idle' }
					>
						{ String( status.findings_new || 0 ) }
					</StatusBadge>
				}
				description={ __(
					'Findings appear as they are found. Notification e-mail goes out only for new alerts when the run finishes.',
					'lw-scan'
				) }
			>
				{ status.feed?.length ? (
					<ul className="lw-admin-feed">
						{ status.feed.map( ( item, index ) => (
							<li key={ `${ item.locator }-${ index }` }>
								<StatusBadge
									status={
										SEVERITY[ item.severity ] || 'idle'
									}
								>
									{ item.severity }
								</StatusBadge>
								<span className="lw-admin-feed__name">
									{ TYPES[ item.type ] || item.type } ·{ ' ' }
									{ item.locator }
								</span>
								<span className="lw-admin-feed__detail">
									{ item.reason }
								</span>
							</li>
						) ) }
					</ul>
				) : (
					<p className="lw-admin-muted">
						{ __( 'Nothing found yet.', 'lw-scan' ) }
					</p>
				) }
			</Section>
		</>
	);
}
