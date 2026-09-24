/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import CopyField from '../../components/CopyField';
import LoadError from '../../components/LoadError';
import Section from '../../components/Section';
import StatTile from '../../components/StatTile';
import StatusBadge from '../../components/StatusBadge';
import StatusIcon from '../../components/StatusIcon';
import {
	SkeletonBlock,
	SkeletonRegion,
	SkeletonRows,
	SkeletonSection,
	SkeletonTiles,
} from '../../components/skeleton';
import { api } from '../../data/api';
import { formatDatetime, formatNumber } from '../../data/format';
import useRemote from '../../data/useRemote';
import Maintenance from './Maintenance';

const VARIANT = {
	critical: 'critical',
	warning: 'warning',
	info: 'info',
	ok: 'ok',
};
const ICON = { critical: 'critical', warning: 'warning', ok: 'ok' };
const STATUS_LABEL = {
	critical: __( 'Critical', 'lw-scan' ),
	warning: __( 'Warning', 'lw-scan' ),
	info: __( 'Info', 'lw-scan' ),
	ok: __( 'OK', 'lw-scan' ),
};

function verdictText( report ) {
	const warnings = report.rows.filter(
		( row ) => row.status === 'warning'
	).length;
	if ( report.verdict === 'critical' ) {
		return __( 'Scanning is blocked', 'lw-scan' );
	}
	if ( report.verdict === 'warning' ) {
		return sprintf(
			/* translators: %d: number of warnings. */
			_n(
				'Ready, with %d warning',
				'Ready, with %d warnings',
				warnings,
				'lw-scan'
			),
			warnings
		);
	}
	return __( 'Ready', 'lw-scan' );
}

const HealthSkeleton = () => (
	<SkeletonRegion
		className="lw-skel-tab"
		label={ __( 'Running environment checks…', 'lw-scan' ) }
	>
		<SkeletonSection>
			<SkeletonBlock height={ 64 } />
		</SkeletonSection>
		<SkeletonTiles count={ 3 } />
		<SkeletonSection description={ false }>
			<SkeletonRows count={ 6 } />
		</SkeletonSection>
	</SkeletonRegion>
);

/**
 * Environment checks, cached data, cron help and signature maintenance.
 */
export default function HealthTab() {
	const load = useCallback( () => api.health( true ), [] );
	const health = useRemote( load );

	if ( health.error ) {
		return (
			<LoadError
				message={ health.error.message }
				onRetry={ health.reload }
			/>
		);
	}
	if ( ! health.data ) {
		return <HealthSkeleton />;
	}
	const report = health.data;
	const first = report.rows.find( ( row ) => row.status === report.verdict );
	const cronOk =
		report.rows.find( ( row ) => row.id === 'cron' )?.status === 'ok';

	return (
		<>
			<Section title={ __( 'Health', 'lw-scan' ) }>
				<div
					className={ `lw-admin-verdict is-${ report.verdict === 'critical' ? 'critical' : report.verdict }` }
				>
					<StatusIcon
						status={ ICON[ report.verdict ] || 'ok' }
						size={ 32 }
					/>
					<div>
						<strong>{ verdictText( report ) }</strong>
						<span>
							{ report.verdict === 'ok'
								? __(
										'Scanning is enabled. Nothing needs your attention here.',
										'lw-scan'
									)
								: first?.message }
						</span>
					</div>
				</div>
			</Section>

			<div className="lw-admin-tiles">
				<StatTile
					label={ __( 'Signatures', 'lw-scan' ) }
					value={
						report.tiles.bundle.version || __( 'none', 'lw-scan' )
					}
					detail={ sprintf(
						/* translators: 1: number of rules, 2: when the bundle was checked. */
						__( '%1$s rules · checked %2$s', 'lw-scan' ),
						formatNumber( report.tiles.bundle.count ),
						formatDatetime( report.tiles.bundle.checked_at )
					) }
				/>
				<StatTile
					label={ __( 'Checksums cached', 'lw-scan' ) }
					value={ formatNumber( report.tiles.checksums ) }
					detail={ __(
						'wordpress.org package checksums',
						'lw-scan'
					) }
				/>
				<StatTile
					label={ __( 'Vulnerability data', 'lw-scan' ) }
					value={ formatNumber( report.tiles.vuln ) }
					detail={ __( 'lookups cached · 24 h', 'lw-scan' ) }
				/>
			</div>

			<Section title={ __( 'Checks', 'lw-scan' ) }>
				<ul className="lw-admin-checks">
					{ report.rows.map( ( row ) => (
						<li key={ row.id }>
							<span className="lw-admin-checks__label">
								{ row.label }
							</span>
							<span className="lw-admin-checks__detail">
								{ row.message }
							</span>
							<StatusBadge
								status={ VARIANT[ row.status ] || 'idle' }
							>
								{ STATUS_LABEL[ row.status ] || row.status }
							</StatusBadge>
						</li>
					) ) }
				</ul>
			</Section>

			<Section
				title={
					cronOk
						? __( 'Run scans from a system cron', 'lw-scan' )
						: __( 'Recommended fix for the warning', 'lw-scan' )
				}
				description={ __(
					"Run the scan from a system cron so it does not depend on site traffic. Paste one of these into the server's crontab:",
					'lw-scan'
				) }
			>
				{ report.cron_lines.map( ( line ) => (
					<CopyField key={ line } text={ line } />
				) ) }
			</Section>

			<Maintenance bundle={ report.bundle } onDone={ health.reload } />
		</>
	);
}
