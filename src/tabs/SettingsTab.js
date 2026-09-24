/**
 * WordPress dependencies
 */
import { SelectControl, TextareaControl } from '@wordpress/components';
import { dateI18n } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import FormSkeleton from '../components/FormSkeleton';
import LoadError from '../components/LoadError';
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import SettingRow from '../components/SettingRow';
import ToggleRow from '../components/ToggleRow';
import { formatBytes } from '../data/format';
import { SCHEDULES, SCOPES } from '../data/labels';

const HOURS = Array.from( { length: 24 }, ( _, h ) => ( {
	value: String( h ),
	label: `${ String( h ).padStart( 2, '0' ) }:00`,
} ) );

/**
 * Scheduled scan + scan depth options (saved through the top bar).
 *
 * @param {Object} props
 * @param {Object} props.stores App stores.
 */
export default function SettingsTab( { stores } ) {
	const store = stores.options;
	if ( store.error ) {
		return <LoadError message={ store.error } onRetry={ store.reload } />;
	}
	if ( store.isLoading ) {
		return <FormSkeleton />;
	}
	const { data, update } = store;
	const off = data.schedule === 'off' || ! data.next_due;

	return (
		<>
			<Section title={ __( 'Scheduled scan', 'lw-scan' ) }>
				<SettingRow
					title={ __( 'Frequency', 'lw-scan' ) }
					help={
						off
							? __(
									'Scheduled scans are off. Start one from the Scan tab whenever you need it.',
									'lw-scan'
								)
							: sprintf(
									/* translators: %s: date and time of the next scheduled scan. */
									__(
										'Next run: %s. If WP-Cron misses it, the next page load catches up in the background.',
										'lw-scan'
									),
									dateI18n( 'M j, H:i', data.next_due * 1000 )
								)
					}
				>
					<div className="lw-admin-inline">
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Frequency', 'lw-scan' ) }
							hideLabelFromVision
							value={ data.schedule }
							options={ Object.entries( SCHEDULES ).map(
								( [ value, label ] ) => ( { value, label } )
							) }
							onChange={ ( schedule ) => update( { schedule } ) }
						/>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Time of day', 'lw-scan' ) }
							hideLabelFromVision
							value={ String( data.schedule_hour ) }
							options={ HOURS }
							onChange={ ( hour ) =>
								update( { schedule_hour: Number( hour ) } )
							}
						/>
						<span className="lw-admin-muted">
							{ sprintf(
								/* translators: %s: site timezone, e.g. Europe/Budapest. */
								__( 'site time (%s)', 'lw-scan' ),
								data.meta.timezone
							) }
						</span>
					</div>
				</SettingRow>
				<SettingRow
					title={ __( 'Scope', 'lw-scan' ) }
					help={ __(
						'Changed-files mode re-scans only files whose size or modification time changed, plus every non-known-good file when new signatures arrive.',
						'lw-scan'
					) }
				>
					<Segmented
						label={ __( 'Scope', 'lw-scan' ) }
						options={ {
							changed: SCOPES.changed,
							full: SCOPES.full,
							db: SCOPES.db,
						} }
						value={ data.scope }
						onChange={ ( scope ) => update( { scope } ) }
					/>
				</SettingRow>
				<SettingRow title={ __( 'Signature updates', 'lw-scan' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Signature updates', 'lw-scan' ) }
						hideLabelFromVision
						value={ data.bundle_auto_update ? '1' : '0' }
						options={ [
							{
								value: '1',
								label: __(
									'Automatically before each scheduled scan',
									'lw-scan'
								),
							},
							{
								value: '0',
								label: __( 'Manually (Health tab)', 'lw-scan' ),
							},
						] }
						onChange={ ( value ) =>
							update( { bundle_auto_update: value === '1' } )
						}
					/>
				</SettingRow>
			</Section>

			<Section title={ __( 'Scan depth', 'lw-scan' ) }>
				<ToggleRow
					title={ __( 'Heuristic layer', 'lw-scan' ) }
					help={ __(
						'Token-level analysis of unknown PHP files: input to dangerous call pairs, identifier entropy, static decoding, disguise headers. Adds "review" findings only, never "alert" on its own.',
						'lw-scan'
					) }
					checked={ data.heuristics }
					onChange={ ( heuristics ) => update( { heuristics } ) }
					onText={ __( 'Enabled', 'lw-scan' ) }
					offText={ __( 'Disabled', 'lw-scan' ) }
				/>
				<SettingRow
					title={ __( 'Per-file size limit', 'lw-scan' ) }
					help={ __(
						'Larger files get type detection and hash matching only. A large PHP file skipped this way is listed as "review".',
						'lw-scan'
					) }
				>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Per-file size limit', 'lw-scan' ) }
						hideLabelFromVision
						value={ String( data.max_file_size ) }
						options={ data.meta.max_file_size_choices.map(
							( size ) => ( {
								value: String( size ),
								label: formatBytes( size ),
							} )
						) }
						onChange={ ( size ) =>
							update( { max_file_size: Number( size ) } )
						}
					/>
				</SettingRow>
				<SettingRow
					title={ __( 'Excluded paths', 'lw-scan' ) }
					help={ __(
						'One pattern per line, relative to the WordPress root. * matches any characters. wp-content/lw-scan is always excluded.',
						'lw-scan'
					) }
				>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Excluded paths', 'lw-scan' ) }
						hideLabelFromVision
						className="lw-admin-mono"
						rows={ 6 }
						value={ data.excluded_paths.join( '\n' ) }
						onChange={ ( text ) =>
							update( { excluded_paths: text.split( '\n' ) } )
						}
					/>
				</SettingRow>
				<ToggleRow
					title={ __( 'Follow symlinks', 'lw-scan' ) }
					help={ __(
						'Off is safer: symlinked directories outside the site are not scanned.',
						'lw-scan'
					) }
					checked={ data.follow_symlinks }
					onChange={ ( follow_symlinks ) =>
						update( { follow_symlinks } )
					}
					onText={ __( 'Follow symlinked directories', 'lw-scan' ) }
					offText={ __( 'Off', 'lw-scan' ) }
				/>
			</Section>
			<p className="lw-admin-hint">
				{ __(
					'Settings affect scanning and notifications only. Nothing here modifies files or database rows.',
					'lw-scan'
				) }
			</p>
		</>
	);
}
