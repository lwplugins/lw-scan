/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import Notices from './components/Notices';
import { api } from './data/api';
import { INITIAL_ALERTS, INITIAL_TAB } from './data/boot';
import useDraftStore from './data/useDraftStore';
import Footer from './shell/Footer';
import SideNav from './shell/SideNav';
import TopBar from './shell/TopBar';
import { TABS, TAB_IDS } from './shell/tabs';
import useSaveShortcut from './shell/useSaveShortcut';
import useTab from './shell/useTab';
import useUnsavedWarning from './shell/useUnsavedWarning';
import FindingsTab from './tabs/findings/FindingsTab';
import HealthTab from './tabs/health/HealthTab';
import NotificationsTab from './tabs/NotificationsTab';
import ScanTab from './tabs/scan/ScanTab';
import SettingsTab from './tabs/SettingsTab';
import StatusTab from './tabs/StatusTab';

const OPTION_KEYS = [
	'schedule',
	'schedule_hour',
	'scope',
	'bundle_auto_update',
	'heuristics',
	'max_file_size',
	'excluded_paths',
	'follow_symlinks',
	'notify_send',
	'notify_emails',
	'notify_limit',
	'admin_notice',
];
const STATUS_KEYS = [ 'enabled', 'cache_ttl' ];

const VIEWS = {
	scan: ScanTab,
	findings: FindingsTab,
	notifications: NotificationsTab,
	settings: SettingsTab,
	health: HealthTab,
	status: StatusTab,
};

/**
 * Shell: sidebar | top bar + tab content + footer. Two save stores: the
 * scan options (Notifications + Settings tabs) and the status endpoint.
 */
export default function App() {
	const tab = useTab( TAB_IDS, INITIAL_TAB );
	const current = TABS.find( ( item ) => item.id === tab );
	const [ alerts, setAlerts ] = useState( INITIAL_ALERTS );
	const stores = {
		options: useDraftStore( api.settings, api.saveSettings, OPTION_KEYS ),
		status: useDraftStore(
			api.statusEndpoint,
			api.saveStatusEndpoint,
			STATUS_KEYS
		),
	};
	const store = current.store ? stores[ current.store ] : null;
	const View = VIEWS[ tab ];

	useUnsavedWarning( stores.options.hasEdits || stores.status.hasEdits );
	useSaveShortcut(
		() => store?.save(),
		Boolean( store?.hasEdits && ! store.isSaving )
	);

	return (
		<>
			<div className="lw-admin-shell">
				<SideNav
					tabs={ TABS }
					current={ tab }
					meta={ {
						findings:
							alerts > 0 ? (
								<span className="lw-admin-count">
									{ alerts }
								</span>
							) : null,
					} }
				/>
				<div className="lw-admin-main">
					<TopBar title={ current.title } store={ store } />
					<main className="lw-admin-scroll">
						<div className="lw-admin-content">
							<View stores={ stores } onAlerts={ setAlerts } />
						</div>
					</main>
					<Footer />
				</div>
			</div>
			<Notices />
		</>
	);
}
