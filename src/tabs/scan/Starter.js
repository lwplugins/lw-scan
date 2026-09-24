/**
 * WordPress dependencies
 */
import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../../components/Callout';
import Section from '../../components/Section';
import Segmented from '../../components/Segmented';
import SettingRow from '../../components/SettingRow';
import ToggleRow from '../../components/ToggleRow';
import { api, errorMessage } from '../../data/api';
import { formatDuration, formatNumber } from '../../data/format';
import { SCOPES } from '../../data/labels';

/**
 * Scope, folder, heuristics and the Start / Resume buttons.
 *
 * @param {Object}   props
 * @param {Object}   props.starter   GET /scan starter block.
 * @param {Function} props.onStarted Reload the tab (it switches to progress).
 */
export default function Starter( { starter, onStarted } ) {
	const [ scope, setScope ] = useState( starter.scope );
	const [ path, setPath ] = useState( '' );
	const [ heuristics, setHeuristics ] = useState( starter.heuristics );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const start = async ( resume ) => {
		setBusy( resume ? 'resume' : 'start' );
		setError( '' );
		try {
			await api.startScan( { scope, path, heuristics, resume } );
			await onStarted();
		} catch ( e ) {
			setError( errorMessage( e ) );
			setBusy( '' );
		}
	};

	const background = __(
		'Runs in the background; you can leave this page.',
		'lw-scan'
	);
	const estimate =
		starter.queued > 0
			? sprintf(
					/* translators: 1: estimated duration, 2: number of changed files. */
					__( 'Estimated ~%1$s for %2$s changed files.', 'lw-scan' ),
					formatDuration( starter.estimate_ms / 1000 ),
					formatNumber( starter.queued )
				) +
				' ' +
				background
			: background;

	return (
		<Section title={ __( 'Start a scan', 'lw-scan' ) }>
			<SettingRow title={ __( 'Scope', 'lw-scan' ) }>
				<Segmented
					label={ __( 'Scan scope', 'lw-scan' ) }
					options={ SCOPES }
					value={ scope }
					onChange={ setScope }
				/>
				{ scope === 'path' && (
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Folder to scan', 'lw-scan' ) }
						className="lw-admin-mono"
						placeholder="wp-content/uploads"
						value={ path }
						onChange={ setPath }
					/>
				) }
			</SettingRow>
			<ToggleRow
				title={ __( 'Heuristic layer', 'lw-scan' ) }
				help={ __(
					'Token-level analysis of unknown PHP files. Adds "review" findings only.',
					'lw-scan'
				) }
				checked={ heuristics }
				onChange={ setHeuristics }
				onText={ __( 'Enabled', 'lw-scan' ) }
				offText={ __( 'Disabled', 'lw-scan' ) }
			/>
			<div className="lw-admin-inline">
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ busy === 'start' }
					disabled={
						Boolean( busy ) || ( scope === 'path' && ! path.trim() )
					}
					accessibleWhenDisabled
					onClick={ () => start( false ) }
				>
					{ busy === 'start'
						? __( 'Starting…', 'lw-scan' )
						: __( 'Start scan', 'lw-scan' ) }
				</Button>
				{ starter.can_resume && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isBusy={ busy === 'resume' }
						disabled={ Boolean( busy ) }
						accessibleWhenDisabled
						onClick={ () => start( true ) }
					>
						{ __( 'Resume stopped scan', 'lw-scan' ) }
					</Button>
				) }
				<span className="lw-admin-muted">{ estimate }</span>
			</div>
			{ ! starter.bundle_version && (
				<Callout>
					{ __(
						'The first scan downloads the signature bundle (about 1 MB).',
						'lw-scan'
					) }
				</Callout>
			) }
			{ error && (
				<p className="lw-admin-testresult is-error" role="alert">
					{ error }
				</p>
			) }
		</Section>
	);
}
