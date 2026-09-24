/**
 * WordPress dependencies
 */
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import FormSkeleton from '../components/FormSkeleton';
import LoadError from '../components/LoadError';
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import SettingRow from '../components/SettingRow';
import ToggleRow from '../components/ToggleRow';
import { api, errorMessage } from '../data/api';

const TEST_RESULT = {
	sent: [
		'ok',
		__(
			'Test e-mail sent. If it does not arrive, the site cannot deliver mail — check the server or an SMTP plugin.',
			'lw-scan'
		),
	],
	failed: [
		'error',
		__(
			'WordPress could not send the test e-mail. The site has no working mail transport.',
			'lw-scan'
		),
	],
	norecipients: [
		'error',
		__(
			'There is nobody to send to: the recipients field is empty and this site has no admin e-mail address.',
			'lw-scan'
		),
	],
};

/**
 * Comma-separated recipients. Keeps the typed text locally (so ", " while
 * typing is not reformatted) and reports the parsed list.
 *
 * @param {Object}   props
 * @param {Array}    props.value    Addresses.
 * @param {Function} props.onChange Receives the list.
 */
function Recipients( { value, onChange } ) {
	const [ text, setText ] = useState( value.join( ', ' ) );
	const joined = value.join( ', ' );

	useEffect( () => {
		const parsed = text
			.split( /[\s,]+/ )
			.filter( Boolean )
			.join( ', ' );
		if ( parsed !== joined ) {
			setText( joined );
		}
		// Resync only when the stored list changes from outside (load, discard).
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ joined ] );

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Recipients', 'lw-scan' ) }
			hideLabelFromVision
			type="text"
			value={ text }
			placeholder="admin@example.com, security@example.com"
			onChange={ ( next ) => {
				setText( next );
				onChange( next.split( /[\s,]+/ ).filter( Boolean ) );
			} }
		/>
	);
}

/**
 * E-mail and admin-notice preferences (saved through the top bar).
 *
 * @param {Object} props
 * @param {Object} props.stores App stores.
 */
export default function NotificationsTab( { stores } ) {
	const store = stores.options;
	const [ test, setTest ] = useState( { busy: false, result: null } );

	if ( store.error ) {
		return <LoadError message={ store.error } onRetry={ store.reload } />;
	}
	if ( store.isLoading ) {
		return <FormSkeleton />;
	}
	const { data, update } = store;
	const { admin_email: adminEmail, baseline_pending: baseline } = data.meta;

	let recipientsHelp = __( 'Comma-separated.', 'lw-scan' );
	if ( ! data.notify_emails.length ) {
		recipientsHelp = adminEmail
			? sprintf(
					/* translators: %s: the site's admin e-mail address. */
					__(
						"Comma-separated. Empty, as now, means the site's admin e-mail address: %s",
						'lw-scan'
					),
					adminEmail
				)
			: __(
					"Comma-separated. Empty means the site's admin e-mail address, which this site has not set.",
					'lw-scan'
				);
	}

	const sendTest = async () => {
		setTest( { busy: true, result: null } );
		try {
			const response = await api.notifyTest();
			setTest( {
				busy: false,
				result: TEST_RESULT[ response.result ] || TEST_RESULT.failed,
			} );
		} catch ( e ) {
			setTest( { busy: false, result: [ 'error', errorMessage( e ) ] } );
		}
	};

	return (
		<>
			{ baseline && (
				<Callout>
					<strong>
						{ __( 'First scan: a baseline.', 'lw-scan' ) }
					</strong>{ ' ' }
					{ __(
						'The first scan reports everything already on the site, so no e-mail was sent for it. Go through those findings on the Findings tab; from the next scan on, only what is new is mailed.',
						'lw-scan'
					) }
				</Callout>
			) }

			<Section title={ __( 'E-mail', 'lw-scan' ) }>
				<SettingRow
					title={ __( 'Send e-mail', 'lw-scan' ) }
					help={ __(
						'Off sends no scan e-mail at all — the warning about repeatedly failing scheduled scans included. There are no "all clear" e-mails either way.',
						'lw-scan'
					) }
				>
					<Segmented
						label={ __( 'Send e-mail', 'lw-scan' ) }
						options={ {
							off: __( 'Off', 'lw-scan' ),
							alert: __( 'New alerts only', 'lw-scan' ),
							review: __(
								'New alerts + review items',
								'lw-scan'
							),
						} }
						value={ data.notify_send }
						onChange={ ( notify_send ) =>
							update( { notify_send } )
						}
					/>
				</SettingRow>
				<SettingRow
					title={ __( 'Recipients', 'lw-scan' ) }
					help={ recipientsHelp }
				>
					<Recipients
						value={ data.notify_emails }
						onChange={ ( notify_emails ) =>
							update( { notify_emails } )
						}
					/>
				</SettingRow>
				<SettingRow
					title={ __( 'Maximum items per e-mail', 'lw-scan' ) }
					help={ __(
						'A scan that finds more than this lists the first few and links to the Findings tab for the rest. The subject line always carries the true total.',
						'lw-scan'
					) }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Maximum items per e-mail', 'lw-scan' ) }
						hideLabelFromVision
						value={ String( data.notify_limit ) }
						options={ data.meta.notify_limit_choices.map(
							( n ) => ( {
								value: String( n ),
								label:
									n === 0
										? __( 'All', 'lw-scan' )
										: String( n ),
							} )
						) }
						onChange={ ( n ) =>
							update( { notify_limit: Number( n ) } )
						}
					/>
				</SettingRow>
				<SettingRow
					title={ __( 'Test', 'lw-scan' ) }
					help={ __(
						'Sends a short message to the saved recipients and reports whether WordPress accepted it.',
						'lw-scan'
					) }
				>
					<div className="lw-admin-inline">
						<Button
							__next40pxDefaultSize
							variant="secondary"
							isBusy={ test.busy }
							disabled={ test.busy }
							accessibleWhenDisabled
							onClick={ sendTest }
						>
							{ __( 'Send a test e-mail', 'lw-scan' ) }
						</Button>
					</div>
					{ store.hasEdits && (
						<Callout>
							{ __(
								'You have unsaved changes: the test uses the saved recipients. Save first if you just edited the list.',
								'lw-scan'
							) }
						</Callout>
					) }
					{ test.result && (
						<p
							className={ `lw-admin-testresult is-${ test.result[ 0 ] }` }
							role={
								test.result[ 0 ] === 'error'
									? 'alert'
									: 'status'
							}
						>
							{ test.result[ 1 ] }
						</p>
					) }
				</SettingRow>
			</Section>

			<Section title={ __( 'In the admin', 'lw-scan' ) }>
				<ToggleRow
					title={ __( 'Admin notice', 'lw-scan' ) }
					help={ __(
						'Shown to administrators on every admin screen until the findings are acknowledged or ignored. Independent of the e-mail switch above.',
						'lw-scan'
					) }
					checked={ data.admin_notice }
					onChange={ ( admin_notice ) => update( { admin_notice } ) }
					onText={ __(
						'Show a persistent notice while new alerts exist',
						'lw-scan'
					) }
					offText={ __( 'Off', 'lw-scan' ) }
				/>
			</Section>
		</>
	);
}
