/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import StatusBadge from '../../components/StatusBadge';
import { formatDatetime } from '../../data/format';
import { SEVERITY, TYPES } from '../../data/labels';

const signatures = ( ids = [] ) =>
	ids.length > 2
		? `${ ids.slice( 0, 2 ).join( ', ' ) } (+${ ids.length - 2 })`
		: ids.join( ', ' );

/**
 * Findings table columns.
 *
 * @param {Object}   handlers
 * @param {Function} handlers.onState   ( finding, state ) => void.
 * @param {Function} handlers.onDetails ( finding ) => void.
 * @param {number}   handlers.busyId    Row being updated.
 * @return {Array} Columns.
 */
export function findingColumns( { onState, onDetails, busyId } ) {
	return [
		{
			id: 'severity',
			label: __( 'Severity', 'lw-scan' ),
			render: ( f ) =>
				f.state === 'new' ? (
					<StatusBadge status={ SEVERITY[ f.severity ] || 'idle' }>
						{ f.severity }
					</StatusBadge>
				) : (
					<StatusBadge status="idle">{ f.state }</StatusBadge>
				),
		},
		{
			id: 'type',
			label: __( 'Type', 'lw-scan' ),
			render: ( f ) => TYPES[ f.type ] || f.type,
		},
		{
			id: 'where',
			label: __( 'Where', 'lw-scan' ),
			render: ( f ) => (
				<span className="lw-admin-stack">
					<code className="lw-admin-code">{ f.title }</code>
					{ f.subtitle && (
						<span className="lw-admin-hint">{ f.subtitle }</span>
					) }
				</span>
			),
		},
		{
			id: 'detected_by',
			label: __( 'Detected by', 'lw-scan' ),
			render: ( f ) => (
				<span className="lw-admin-stack">
					{ f.type === 'vulnerability' ? (
						<span>{ f.detected_by }</span>
					) : (
						<span className="lw-admin-inline">
							<StatusBadge
								status={
									f.tier_variant === 'alert'
										? 'critical'
										: 'warning'
								}
							>
								{ f.tier }
							</StatusBadge>
							<span>{ f.detected_by }</span>
						</span>
					) }
					{ f.signature_ids?.length > 0 && (
						<span className="lw-admin-hint lw-admin-mono">
							{ signatures( f.signature_ids ) }
						</span>
					) }
				</span>
			),
		},
		{
			id: 'last_seen',
			label: __( 'Last seen', 'lw-scan' ),
			render: ( f ) => (
				<span className="lw-admin-stack">
					<span>{ formatDatetime( f.last_seen ) }</span>
					<span className="lw-admin-hint">
						{ sprintf(
							/* translators: %s: date first seen. */
							__( 'first %s', 'lw-scan' ),
							formatDatetime( f.first_seen )
						) }
					</span>
				</span>
			),
		},
		{
			id: 'actions',
			label: __( 'Actions', 'lw-scan' ),
			render: ( f ) => (
				<span className="lw-admin-inline lw-admin-actions">
					{ f.update_url && (
						<Button
							size="compact"
							variant="primary"
							href={ f.update_url }
						>
							{ __( 'Update plugin', 'lw-scan' ) }
						</Button>
					) }
					{ f.state === 'new' ? (
						<>
							<Button
								size="compact"
								variant="secondary"
								isBusy={ busyId === f.id }
								onClick={ () => onState( f, 'acknowledged' ) }
							>
								{ __( 'Acknowledge', 'lw-scan' ) }
							</Button>
							<Button
								size="compact"
								variant="tertiary"
								onClick={ () => onState( f, 'ignored' ) }
							>
								{ __( 'Ignore', 'lw-scan' ) }
							</Button>
						</>
					) : (
						<Button
							size="compact"
							variant="secondary"
							isBusy={ busyId === f.id }
							onClick={ () => onState( f, 'new' ) }
						>
							{ __( 'Reopen', 'lw-scan' ) }
						</Button>
					) }
					<Button
						size="compact"
						variant="link"
						onClick={ () => onDetails( f ) }
					>
						{ __( 'Details', 'lw-scan' ) }
					</Button>
				</span>
			),
		},
	];
}
