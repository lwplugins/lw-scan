/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export const SCOPES = {
	changed: __( 'changed files + database', 'lw-scan' ),
	full: __( 'full site', 'lw-scan' ),
	db: __( 'database only', 'lw-scan' ),
	path: __( 'one folder', 'lw-scan' ),
};

export const TRIGGERS = {
	manual: __( 'manual', 'lw-scan' ),
	cron: __( 'scheduled', 'lw-scan' ),
	catchup: __( 'catch-up', 'lw-scan' ),
	cli: 'WP-CLI',
	ability: __( 'ability', 'lw-scan' ),
};

export const PHASES = {
	bundle: __( 'Signatures', 'lw-scan' ),
	index: __( 'Index', 'lw-scan' ),
	hash: __( 'Hashes', 'lw-scan' ),
	files: __( 'Files', 'lw-scan' ),
	db: __( 'Database', 'lw-scan' ),
	vuln: __( 'Vulnerabilities', 'lw-scan' ),
	finalize: __( 'Finalize', 'lw-scan' ),
};

export const TYPES = {
	file: __( 'file', 'lw-scan' ),
	integrity: __( 'integrity', 'lw-scan' ),
	db: __( 'database', 'lw-scan' ),
	vulnerability: __( 'vulnerability', 'lw-scan' ),
};

export const SCHEDULES = {
	daily: __( 'Daily', 'lw-scan' ),
	hourly: __( 'Hourly', 'lw-scan' ),
	weekly: __( 'Weekly', 'lw-scan' ),
	off: __( 'Off', 'lw-scan' ),
};

// StatusBadge status for a run status.
export const RUN_STATUS = {
	done: 'ok',
	failed: 'critical',
	stopped: 'idle',
	running: 'info',
};

// StatusBadge status for a finding severity.
export const SEVERITY = { alert: 'critical', review: 'warning' };
