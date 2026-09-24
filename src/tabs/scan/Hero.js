/**
 * WordPress dependencies
 */
import { dateI18n } from '@wordpress/date';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import StatTile from '../../components/StatTile';
import StatusIcon from '../../components/StatusIcon';
import {
	formatDatetime,
	formatDuration,
	formatNumber,
} from '../../data/format';
import { SCHEDULES, SCOPES, TRIGGERS } from '../../data/labels';

function verdict( { alerts, review, last_run: lastRun } ) {
	if ( alerts > 0 ) {
		return [
			'critical',
			sprintf(
				/* translators: 1: number of alerts, 2: number of items to review. */
				__( 'Action needed — %1$d alerts, %2$d to review', 'lw-scan' ),
				alerts,
				review
			),
		];
	}
	if ( ! lastRun ) {
		return [ 'warning', __( 'Never scanned', 'lw-scan' ) ];
	}
	if ( review > 0 ) {
		return [
			'warning',
			sprintf(
				/* translators: %d: number of items to review. */
				_n(
					'No alerts — %d item to review',
					'No alerts — %d items to review',
					review,
					'lw-scan'
				),
				review
			),
		];
	}
	return [ 'ok', __( 'No findings — last scan clean', 'lw-scan' ) ];
}

/**
 * Verdict card + the four summary tiles.
 *
 * @param {Object} props
 * @param {Object} props.data GET /scan payload.
 */
export default function Hero( { data } ) {
	const { hero, tiles, progress } = data;
	const [ status, text ] = verdict( hero );
	const run = hero.last_run;
	const off = tiles.schedule === 'off' || ! tiles.next_due;

	return (
		<>
			<Section>
				<div className={ `lw-admin-verdict is-${ status }` }>
					<StatusIcon status={ status } size={ 32 } />
					<div>
						<strong>{ text }</strong>
						<span>
							{ run
								? sprintf(
										/* translators: 1: date, 2: "trigger, scope", 3: duration, 4: signature bundle description. */
										__(
											'Last scan: %1$s (%2$s) · finished in %3$s · %4$s',
											'lw-scan'
										),
										formatDatetime( run.started_at ),
										`${ TRIGGERS[ run.trigger ] || run.trigger }, ${ SCOPES[ run.scope ] || run.scope }`,
										formatDuration(
											run.duration ?? progress.elapsed
										),
										hero.bundle.describe
									)
								: `${ __( 'No scan has run yet.', 'lw-scan' ) } ${ hero.bundle.describe }` }
						</span>
					</div>
				</div>
			</Section>

			<div className="lw-admin-tiles">
				<StatTile
					label={ __( 'Files indexed', 'lw-scan' ) }
					value={ formatNumber( tiles.files_total ) }
					detail={ sprintf(
						/* translators: %s: number of files. */
						__( '%s queued for the next scan', 'lw-scan' ),
						formatNumber( tiles.queued )
					) }
				/>
				<StatTile
					label={ __( 'Known-good excluded', 'lw-scan' ) }
					value={ formatNumber( tiles.known_good ) }
					detail={ sprintf(
						/* translators: %s: percentage. */
						__( '%s matched wp.org checksums', 'lw-scan' ),
						`${ Number( tiles.known_good_pct ).toFixed( 1 ) } %`
					) }
				/>
				<StatTile
					label={ __( 'Deep-scanned', 'lw-scan' ) }
					value={ formatNumber( tiles.deep_scanned ) }
					detail={ sprintf(
						/* translators: 1: files, 2: database rows, 3: packages. */
						__(
							'%1$s files · %2$s DB rows · %3$s packages',
							'lw-scan'
						),
						formatNumber( tiles.deep_breakdown.files ),
						formatNumber( tiles.deep_breakdown.db_rows ),
						formatNumber( tiles.deep_breakdown.packages )
					) }
				/>
				<StatTile
					label={ __( 'Next scheduled', 'lw-scan' ) }
					value={
						off
							? __( 'off', 'lw-scan' )
							: dateI18n( 'H:i', tiles.next_due * 1000 )
					}
					detail={
						off
							? __( 'scheduled scans are off', 'lw-scan' )
							: `${ ( SCHEDULES[ tiles.schedule ] || tiles.schedule ).toLowerCase() } · ${ SCOPES[ tiles.schedule_scope ] || tiles.schedule_scope }`
					}
				/>
			</div>
		</>
	);
}
