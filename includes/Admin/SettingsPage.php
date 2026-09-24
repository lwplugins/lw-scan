<?php
/**
 * The LW Scan admin page: menu entry, React mount point, assets.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * One submenu page under the shared "LW Plugins" menu. The page itself is
 * a single mount point: the React app in `build/index.js` renders every
 * tab and talks to the `lw-scan/v1` REST routes (`Rest\Routes`).
 *
 * `window.lwScan` carries what the app needs before its first request:
 * the REST namespace, whether the tables exist, the alert count for the
 * nav badge, and the tab and Findings filters the URL asked for — every
 * one of them whitelisted here.
 */
final class SettingsPage {

	public const SLUG = 'lw-scan';

	/** Script and style handle of the React build. */
	private const HANDLE = 'lw-scan-admin';

	/** Tabs the app knows; the first one is the default. */
	private const TABS = [ 'scan', 'findings', 'notifications', 'settings', 'health', 'status' ];

	/** Values the Findings filters may take from the query string. */
	private const FILTERS = [
		'severity' => [ 'alert', 'review' ],
		'type'     => [ 'file', 'integrity', 'db', 'vulnerability' ],
		'state'    => [ 'new', 'acknowledged', 'ignored' ],
	];

	/** @var string Hook suffix `add_submenu_page()` returned, '' before the menu exists. */
	private string $hook = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Hooked from `Plugin::init_components()` on admin requests only.
	 */
	public static function register(): void {
		new self();
	}

	public function add_menu_page(): void {
		ParentPage::maybe_register();

		$hook = add_submenu_page(
			ParentPage::SLUG,
			__( 'Scan', 'lw-scan' ),
			__( 'Scan', 'lw-scan' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);

		$this->hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * @param string $hook Current admin screen's hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! $this->is_screen( $hook ) || ! is_readable( LW_SCAN_PATH . 'build/index.js' ) ) {
			return;
		}

		$asset = self::asset();

		wp_enqueue_script( self::HANDLE, LW_SCAN_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'lw-scan', LW_SCAN_PATH . 'languages' );
		wp_add_inline_script( self::HANDLE, 'window.lwScan = ' . wp_json_encode( self::script_data() ) . ';', 'before' );

		if ( is_readable( LW_SCAN_PATH . 'build/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, LW_SCAN_URL . 'build/index.css', [ 'wp-components' ], self::file_version( 'build/index.css' ) );
		}
	}

	/**
	 * @param string $classes Space-separated admin body classes.
	 */
	public function body_class( $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! $this->is_screen( (string) $screen->id ) ) {
			return (string) $classes;
		}

		return trim( $classes . ' lw-scan-screen' );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// No core .wrap around the mount point: its margins (10px 20px 0 2px)
		// would keep the full-bleed app shell off the screen edges.
		if ( ! is_readable( LW_SCAN_PATH . 'build/index.js' ) ) {
			printf(
				'<div class="wrap"><div class="notice notice-error"><p>%s</p></div></div>',
				esc_html__( 'The LW Scan admin interface is missing (build/index.js). Reinstall the plugin from a release package, or run "npm run build" in a development checkout.', 'lw-scan' )
			);
			return;
		}

		echo '<div id="lw-scan-root" class="lw-scan-root"></div>';
	}

	/**
	 * Whether a hook suffix / screen id is this page.
	 *
	 * @param string $hook Hook suffix or screen id.
	 */
	private function is_screen( string $hook ): bool {
		return '' !== $hook && ( $hook === $this->hook || ParentPage::SLUG . '_page_' . self::SLUG === $hook );
	}

	/**
	 * Dependencies and version from `build/index.asset.php`, the file
	 * `@wordpress/scripts` writes next to the bundle.
	 *
	 * @return array{dependencies: string[], version: string}
	 */
	private static function asset(): array {
		$file  = LW_SCAN_PATH . 'build/index.asset.php';
		$asset = is_readable( $file ) ? require $file : [];
		$asset = is_array( $asset ) ? $asset : [];

		return [
			'dependencies' => is_array( $asset['dependencies'] ?? null ) ? array_map( 'strval', $asset['dependencies'] ) : [],
			'version'      => isset( $asset['version'] ) ? (string) $asset['version'] : self::file_version( 'build/index.js' ),
		];
	}

	/**
	 * Cache-busting version from the file's modification time. The asset
	 * file's hash only covers the JavaScript, so the stylesheet needs this.
	 *
	 * @param string $relative Plugin-relative path.
	 */
	private static function file_version( string $relative ): string {
		$mtime = @filemtime( LW_SCAN_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version below.

		return false !== $mtime ? (string) $mtime : LW_SCAN_VERSION;
	}

	/**
	 * `window.lwScan`.
	 *
	 * @return array<string, mixed>
	 */
	private static function script_data(): array {
		$schema = Schema::exists();

		return [
			'version'       => LW_SCAN_VERSION,
			'namespace'     => Routes::NAMESPACE,
			'schemaExists'  => $schema,
			'alertCount'    => $schema ? (int) ( FindingsRepository::counts()['state']['new']['alert'] ?? 0 ) : 0,
			'tab'           => self::one_of( 'tab', self::TABS, 'scan' ),
			'findings'      => self::findings_filters(),
			'updateCoreUrl' => admin_url( 'update-core.php' ),
			'docsUrl'       => 'https://lwplugins.com/docs/lw-scan/',
		];
	}

	/**
	 * The Findings filters the URL asked for, whitelisted.
	 *
	 * @return array{severity: string, type: string, state: string, s: string, paged: int}
	 */
	private static function findings_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters that only preselect the Findings view; every value is whitelisted or sanitized.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return [
			'severity' => self::one_of( 'severity', self::FILTERS['severity'], '' ),
			'type'     => self::one_of( 'type', self::FILTERS['type'], '' ),
			'state'    => self::one_of( 'state', self::FILTERS['state'], '' ),
			's'        => $search,
			'paged'    => max( 1, $paged ),
		];
	}

	/**
	 * A query-string value if it is one of `$allowed`, else `$fallback`.
	 *
	 * @param string   $key      Query-string key.
	 * @param string[] $allowed  Accepted values.
	 * @param string   $fallback Value for anything else.
	 */
	private static function one_of( string $key, array $allowed, string $fallback ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, whitelisted on the next line.
		$value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
