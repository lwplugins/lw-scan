/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	caution,
	chartBar,
	cog,
	envelope,
	search,
	tool,
} from '@wordpress/icons';

/**
 * Tab registry. `store` names the save store the top bar drives on that tab
 * (null = read-only tab, no Save button).
 */
export const TABS = [
	{
		id: 'scan',
		label: __( 'Scan', 'lw-scan' ),
		title: __( 'Scan', 'lw-scan' ),
		icon: search,
		store: null,
	},
	{
		id: 'findings',
		label: __( 'Findings', 'lw-scan' ),
		title: __( 'Findings', 'lw-scan' ),
		icon: caution,
		store: null,
	},
	{
		id: 'notifications',
		label: __( 'Notifications', 'lw-scan' ),
		title: __( 'Notifications', 'lw-scan' ),
		icon: envelope,
		store: 'options',
	},
	{
		id: 'settings',
		label: __( 'Settings', 'lw-scan' ),
		title: __( 'Scan Settings', 'lw-scan' ),
		icon: cog,
		store: 'options',
	},
	{
		id: 'health',
		label: __( 'Health', 'lw-scan' ),
		title: __( 'Health', 'lw-scan' ),
		icon: tool,
		store: null,
	},
	{
		id: 'status',
		label: __( 'Status', 'lw-scan' ),
		title: __( 'Status Endpoint', 'lw-scan' ),
		icon: chartBar,
		store: 'status',
	},
];

export const TAB_IDS = TABS.map( ( tab ) => tab.id );
