/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import LoadError from '../../components/LoadError';
import {
	SkeletonBlock,
	SkeletonRegion,
	SkeletonRows,
	SkeletonSection,
	SkeletonText,
	SkeletonTiles,
} from '../../components/skeleton';
import { api } from '../../data/api';
import useRemote from '../../data/useRemote';
import Hero from './Hero';
import RecentRuns from './RecentRuns';
import RunProgress from './RunProgress';
import Starter from './Starter';

export const missingTables = (
	<Notice status="warning" isDismissible={ false }>
		{ __(
			'LW Scan cannot find its database tables. Deactivate and re-activate the plugin to create them.',
			'lw-scan'
		) }
	</Notice>
);

const ScanSkeleton = () => (
	<SkeletonRegion className="lw-skel-tab">
		<SkeletonSection description={ false }>
			<SkeletonText width="45%" size="xl" />
			<SkeletonText width="70%" size="sm" />
		</SkeletonSection>
		<SkeletonTiles count={ 4 } />
		<SkeletonSection description={ false }>
			<SkeletonBlock height={ 40 } />
			<SkeletonBlock width={ 140 } height={ 40 } />
		</SkeletonSection>
		<SkeletonSection description={ false }>
			<SkeletonRows count={ 4 } />
		</SkeletonSection>
	</SkeletonRegion>
);

/**
 * Idle: verdict, tiles, starter, recent runs. Running: live progress.
 *
 * @param {Object}   props
 * @param {Function} props.onAlerts Update the sidebar alert count.
 */
export default function ScanTab( { onAlerts } ) {
	const load = useCallback( () => api.scan(), [] );
	const scan = useRemote( load );
	const data = scan.data;

	useEffect( () => {
		if ( data?.hero ) {
			onAlerts( data.hero.alerts );
		}
	}, [ data, onAlerts ] );

	if ( scan.error ) {
		return (
			<LoadError message={ scan.error.message } onRetry={ scan.reload } />
		);
	}
	if ( ! data ) {
		return <ScanSkeleton />;
	}
	if ( ! data.schema_exists ) {
		return missingTables;
	}
	if ( data.progress.status === 'running' ) {
		return (
			<RunProgress
				initial={ data }
				onFinish={ scan.reload }
				onAlerts={ onAlerts }
			/>
		);
	}

	return (
		<>
			<Hero data={ data } />
			<Starter starter={ data.starter } onStarted={ scan.reload } />
			<RecentRuns runs={ data.runs } />
		</>
	);
}
