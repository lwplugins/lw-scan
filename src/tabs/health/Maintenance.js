/**
 * WordPress dependencies
 */
import {
	Button,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import { api, errorMessage } from '../../data/api';

/**
 * Signature bundle actions, index rebuild and re-running the checks.
 * Rebuilding the index is destructive (it drops file findings), so it asks first.
 *
 * @param {Object}   props
 * @param {Object}   props.bundle Bundle info ({ describe }).
 * @param {Function} props.onDone Reload the health report.
 */
export default function Maintenance( { bundle, onDone } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const [ confirmIndex, setConfirmIndex ] = useState( false );

	const run = async ( id, call, success ) => {
		setBusy( id );
		setResult( null );
		try {
			const response = await call();
			setResult( [
				response?.status === 'failed' ? 'error' : 'ok',
				response?.message || success,
			] );
			await onDone();
		} catch ( e ) {
			setResult( [ 'error', errorMessage( e ) ] );
		}
		setBusy( '' );
	};

	const button = ( id, label, onClick, props = {} ) => (
		<Button
			__next40pxDefaultSize
			variant="secondary"
			isBusy={ busy === id }
			disabled={ Boolean( busy ) }
			accessibleWhenDisabled
			onClick={ onClick }
			{ ...props }
		>
			{ label }
		</Button>
	);

	return (
		<Section
			title={ __( 'Signature bundle', 'lw-scan' ) }
			description={ bundle.describe }
		>
			<div className="lw-admin-inline">
				{ button( 'check', __( 'Check for updates', 'lw-scan' ), () =>
					run( 'check', () => api.bundle( 'check' ) )
				) }
				{ button(
					'full',
					__( 'Re-download full bundle', 'lw-scan' ),
					() => run( 'full', () => api.bundle( 'full' ) )
				) }
				{ button(
					'index',
					__( 'Rebuild file index', 'lw-scan' ),
					() => setConfirmIndex( true ),
					{ isDestructive: true }
				) }
				{ button( 'health', __( 'Run checks again', 'lw-scan' ), () =>
					run(
						'health',
						async () => ( {} ),
						__( 'Checks updated.', 'lw-scan' )
					)
				) }
			</div>
			{ result && (
				<p
					className={ `lw-admin-testresult is-${ result[ 0 ] }` }
					role={ result[ 0 ] === 'error' ? 'alert' : 'status' }
				>
					{ result[ 1 ] }
				</p>
			) }
			<ConfirmDialog
				isOpen={ confirmIndex }
				confirmButtonText={ __( 'Rebuild file index', 'lw-scan' ) }
				onConfirm={ () => {
					setConfirmIndex( false );
					run(
						'index',
						api.rebuildIndex,
						__(
							'File index cleared. The next scan rebuilds it and re-checks every file.',
							'lw-scan'
						)
					);
				} }
				onCancel={ () => setConfirmIndex( false ) }
			>
				{ __(
					'Rebuild the file index? This clears the index and the file and integrity findings; the next scan re-checks every file, so it takes about as long as the first one.',
					'lw-scan'
				) }
			</ConfirmDialog>
		</Section>
	);
}
