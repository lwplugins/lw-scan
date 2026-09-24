/**
 * Server-provided boot data (SettingsPage inline script).
 */
const boot = window.lwScan || {};

export const VERSION = boot.version || '';
export const NAMESPACE = boot.namespace || 'lw-scan/v1';
export const SCHEMA_EXISTS = boot.schemaExists !== false;
export const INITIAL_ALERTS = Number( boot.alertCount || 0 );
export const INITIAL_TAB = boot.tab || 'scan';
export const INITIAL_FINDINGS = boot.findings || {};
export const UPDATE_CORE_URL = boot.updateCoreUrl || '';
export const DOCS_URL = boot.docsUrl || 'https://lwplugins.com/docs/lw-scan/';
