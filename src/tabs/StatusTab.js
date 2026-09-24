/**
 * WordPress dependencies
 */
import {
	Button,
	__experimentalConfirmDialog as ConfirmDialog,
	SelectControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { dateI18n, getSettings } from '@wordpress/date';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import CopyField from '../components/CopyField';
import FormSkeleton from '../components/FormSkeleton';
import LoadError from '../components/LoadError';
import Section from '../components/Section';
import SettingRow from '../components/SettingRow';
import StatusBadge from '../components/StatusBadge';
import ToggleRow from '../components/ToggleRow';
import { api, errorMessage } from '../data/api';

/**
 * Read-only monitoring endpoint: on/off, URL (copy, rotate), cache time.
 *
 * @param {Object} props
 * @param {Object} props.stores App stores.
 */
export default function StatusTab( { stores } ) {
	const store = stores.status;
	const [ confirmRotate, setConfirmRotate ] = useState( false );
	const [ rotating, setRotating ] = useState( false );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	if ( store.error ) {
		return <LoadError message={ store.error } onRetry={ store.reload } />;
	}
	if ( store.isLoading ) {
		return <FormSkeleton />;
	}
	const { data, update } = store;
	const { formats } = getSettings();

	const rotate = async () => {
		setConfirmRotate( false );
		setRotating( true );
		try {
			const next = await api.rotateStatusEndpoint();
			// Keep unsaved edits to the other fields; the key is not editable.
			store.replace( {
				...next,
				enabled: data.enabled,
				cache_ttl: data.cache_ttl,
			} );
			createSuccessNotice(
				__(
					'New URL generated. The previous URL no longer works.',
					'lw-scan'
				),
				{ type: 'snackbar' }
			);
		} catch ( e ) {
			createErrorNotice( errorMessage( e ), { type: 'snackbar' } );
		}
		setRotating( false );
	};

	return (
		<>
			<Section
				title={ __( 'Status endpoint', 'lw-scan' ) }
				description={ __(
					"A read-only report on this site's malware-scan status for an external monitoring service. It only reads; it changes nothing. Enabled by default.",
					'lw-scan'
				) }
			>
				<ToggleRow
					title={ __( 'Endpoint', 'lw-scan' ) }
					checked={ data.enabled }
					onChange={ ( enabled ) => update( { enabled } ) }
					onText={ __( 'Enabled', 'lw-scan' ) }
					offText={ __( 'Off', 'lw-scan' ) }
				/>
			</Section>

			<Section
				title={ __( 'Access', 'lw-scan' ) }
				description={ __(
					'Give this address to your monitoring service.',
					'lw-scan'
				) }
				className={ data.enabled ? '' : 'is-off' }
			>
				<SettingRow
					title={ __( 'Status URL', 'lw-scan' ) }
					help={ __(
						'The key inside it is the only protection — anyone who knows the address can read the report.',
						'lw-scan'
					) }
				>
					{ ! data.enabled && (
						<p className="lw-admin-muted">
							<StatusBadge status="idle">
								{ __( 'Off', 'lw-scan' ) }
							</StatusBadge>{ ' ' }
							{ __(
								'The endpoint is switched off: this address answers "not found" until you switch it on and save.',
								'lw-scan'
							) }
						</p>
					) }
					<CopyField text={ data.url } />
					<p className="lw-admin-hint lw-admin-inline">
						{ sprintf(
							/* translators: %s: date and time the URL was created. */
							__( 'Created: %s', 'lw-scan' ),
							data.key_set_at
								? dateI18n(
										`${ formats.date } ${ formats.time }`,
										data.key_set_at * 1000
									)
								: '—'
						) }
						<Button
							variant="link"
							isDestructive
							isBusy={ rotating }
							onClick={ () => setConfirmRotate( true ) }
						>
							{ __( 'Generate new URL', 'lw-scan' ) }
						</Button>
					</p>
				</SettingRow>
				<SettingRow
					title={ __( 'Reuse the result for', 'lw-scan' ) }
					help={ __(
						'A stored result answers requests for this long; after that the next request runs the check again.',
						'lw-scan'
					) }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Reuse the result for', 'lw-scan' ) }
						hideLabelFromVision
						value={ String( data.cache_ttl ) }
						options={ data.ttl_choices.map( ( ttl ) => ( {
							value: String( ttl ),
							label: sprintf(
								/* translators: %d: number of minutes. */
								_n(
									'%d minute',
									'%d minutes',
									ttl / 60,
									'lw-scan'
								),
								ttl / 60
							),
						} ) ) }
						onChange={ ( ttl ) =>
							update( { cache_ttl: Number( ttl ) } )
						}
					/>
				</SettingRow>
			</Section>

			<ConfirmDialog
				isOpen={ confirmRotate }
				confirmButtonText={ __( 'Generate new URL', 'lw-scan' ) }
				onConfirm={ rotate }
				onCancel={ () => setConfirmRotate( false ) }
			>
				{ __(
					'The current URL stops working immediately. Continue?',
					'lw-scan'
				) }
			</ConfirmDialog>
		</>
	);
}
