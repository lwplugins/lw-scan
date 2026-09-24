/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './boot';

const path = ( route, args ) =>
	addQueryArgs( `/${ NAMESPACE }${ route }`, args );
const post = ( route, data = {} ) =>
	apiFetch( { path: path( route ), method: 'POST', data } );

export const api = {
	scan: () => apiFetch( { path: path( '/scan' ) } ),
	scanStatus: () => apiFetch( { path: path( '/scan/status' ) } ),
	startScan: ( data ) => post( '/scan/start', data ),
	tick: () => post( '/scan/tick' ),
	stopScan: () => post( '/scan/stop' ),

	findings: ( args, signal ) =>
		apiFetch( { path: path( '/findings', args ), signal } ),
	setFindingState: ( ids, state ) =>
		post( '/findings/state', { ids, state } ),
	clearFindings: () => post( '/findings/clear' ),

	health: ( fresh = false ) =>
		apiFetch( { path: path( '/health', fresh ? { fresh: 1 } : {} ) } ),
	bundle: ( op ) => post( '/maintenance/bundle', { op } ),
	rebuildIndex: () => post( '/maintenance/index', { op: 'rebuild' } ),

	settings: () => apiFetch( { path: path( '/settings' ) } ),
	saveSettings: ( patch ) => post( '/settings', patch ),
	notifyTest: () => post( '/notify/test' ),

	statusEndpoint: () => apiFetch( { path: path( '/status-endpoint' ) } ),
	saveStatusEndpoint: ( data ) => post( '/status-endpoint', data ),
	rotateStatusEndpoint: () => post( '/status-endpoint/rotate' ),
};

/**
 * Human message from an apiFetch rejection.
 *
 * @param {*} error Rejection.
 * @return {string} Message.
 */
export const errorMessage = ( error ) =>
	error?.message ||
	'That did not work. Please reload the page and try again.';
