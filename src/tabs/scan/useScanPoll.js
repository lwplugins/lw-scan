/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { api, errorMessage } from '../../data/api';

const POLL_MS = 1000;
const ASSIST_AFTER_S = 3;
const MAX_FAILURES = 5;

/**
 * Polls /scan/status while a run is active. When the run has not ticked for
 * a few seconds (WP-Cron not driving it), the browser fires a tick itself.
 * Stops once the status is no longer "running" and calls onFinish.
 *
 * @param {Object}   initial  First status (from GET /scan).
 * @param {Function} onFinish Called once when the run ends.
 * @return {{status: Object, error: string}} Latest status and poll error.
 */
export default function useScanPoll( initial, onFinish ) {
	const [ status, setStatus ] = useState( initial );
	const [ error, setError ] = useState( '' );
	const assist = useRef( {
		baseline: initial.last_tick_at || Date.now() / 1000,
		last: 0,
	} );

	useEffect( () => {
		let timer;
		let failures = 0;
		let stopped = false;

		const assistTick = ( next ) => {
			const now = Date.now() / 1000;
			const since = next.last_tick_at || assist.current.baseline;
			if (
				now - since > ASSIST_AFTER_S &&
				now - assist.current.last > ASSIST_AFTER_S
			) {
				assist.current = { baseline: now, last: now };
				api.tick().catch( () => {} );
			}
		};

		const poll = async () => {
			try {
				const next = await api.scanStatus();
				if ( stopped ) {
					return;
				}
				failures = 0;
				setError( '' );
				setStatus( next );
				if ( next.status === 'running' ) {
					assistTick( next );
					timer = setTimeout( poll, POLL_MS );
				} else {
					onFinish( next );
				}
			} catch ( e ) {
				if ( stopped ) {
					return;
				}
				failures++;
				setError( errorMessage( e ) );
				if ( failures < MAX_FAILURES ) {
					timer = setTimeout( poll, POLL_MS * failures );
				}
			}
		};

		timer = setTimeout( poll, POLL_MS );
		return () => {
			stopped = true;
			clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return { status, error };
}
