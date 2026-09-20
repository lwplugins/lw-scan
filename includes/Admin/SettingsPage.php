<?php
/**
 * The LW Scan admin page: menu entry, settings registration, assets.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin;

use LightweightPlugins\Scan\Admin\Settings\TabFindings;
use LightweightPlugins\Scan\Admin\Settings\TabHealth;
use LightweightPlugins\Scan\Admin\Settings\TabInterface;
use LightweightPlugins\Scan\Admin\Settings\TabNotifications;
use LightweightPlugins\Scan\Admin\Settings\TabScan;
use LightweightPlugins\Scan\Admin\Settings\TabSettings;
use LightweightPlugins\Scan\Admin\Settings\TabStatus;
use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.1: one submenu page under the shared "LW Plugins" menu, six
 * tabs routed by `?tab=`, and the assets loaded on this screen only. The
 * Save button — and the `options.php` form around it — belongs to the
 * `settings` and `notifications` tabs; Scan, Findings and Health are
 * read/act views driven by AJAX, and Status posts its own two small forms
 * to `admin-post.php` (`Admin\Post\StatusEndpointHandler`), as does the
 * Notifications tab's test e-mail (`Admin\Post\NotifyTestHandler`).
 */
final class SettingsPage {

	public const SLUG = 'lw-scan';

	public const SETTINGS_GROUP = 'lw_scan_settings';

	/** Nonce action shared by every admin AJAX endpoint (spec §13). */
	public const NONCE = 'lw_scan_admin';

	/**
	 * Tabs in nav order; the first one is the default.
	 *
	 * @var array<int, TabInterface>
	 */
	private array $tabs;

	public function __construct() {
		$this->tabs = [
			new TabScan(),
			new TabFindings(),
			new TabNotifications(),
			new TabSettings(),
			new TabHealth(),
			new TabStatus(),
		];

		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Hooked from `Plugin::init_components()` on admin requests only.
	 */
	public static function register(): void {
		new self();
	}

	public function add_menu_page(): void {
		ParentPage::maybe_register();

		add_submenu_page(
			ParentPage::SLUG,
			__( 'Scan', 'lw-scan' ),
			__( 'Scan', 'lw-scan' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			Options::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => Options::get_defaults(),
			]
		);
	}

	/**
	 * @param mixed $input Raw submitted option value.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ): array {
		return SettingsSanitizer::sanitize( $input );
	}

	/**
	 * @param string $hook Current admin screen's hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ParentPage::SLUG . '_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'lw-scan-admin',
			LW_SCAN_URL . 'assets/css/admin.css',
			[],
			self::asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'lw-scan-admin',
			LW_SCAN_URL . 'assets/js/admin.js',
			[],
			self::asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_localize_script( 'lw-scan-admin', 'lwScan', self::script_data() );
	}

	/**
	 * Everything `assets/js/admin.js` needs: where to POST, the shared
	 * nonce, and the strings it puts on screen.
	 *
	 * @return array<string, mixed>
	 */
	private static function script_data(): array {
		return [
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( self::NONCE ),
			'tab'         => self::current_tab(),
			'pollMs'      => 1000,
			'assistAfter' => 3,
			'i18n'        => [
				'starting'      => __( 'Starting…', 'lw-scan' ),
				'stopping'      => __( 'Stopping…', 'lw-scan' ),
				'working'       => __( 'Working…', 'lw-scan' ),
				'copied'        => __( 'Copied', 'lw-scan' ),
				'copy'          => __( 'Copy', 'lw-scan' ),
				'failed'        => __( 'That did not work. Please reload the page and try again.', 'lw-scan' ),
				'noSelected'    => __( 'Select at least one finding first.', 'lw-scan' ),
				/* translators: %s: estimated remaining time, e.g. "1 m 20 s". */
				'remaining'     => __( '~%s remaining', 'lw-scan' ),
				'phaseDone'     => __( 'done', 'lw-scan' ),
				'confirmAll'    => __( 'Apply this action to every selected finding?', 'lw-scan' ),
				'confirmClear'  => __( 'Delete every finding from this list? The next scan re-checks every file, so it takes about as long as the first one, and reports again anything that is still on the site.', 'lw-scan' ),
				'confirmRotate' => __( 'The current URL stops working immediately. Continue?', 'lw-scan' ),
			],
		];
	}

	/**
	 * Cache-busting asset version from the file's modification time.
	 *
	 * @param string $relative Plugin-relative asset path.
	 */
	private static function asset_version( string $relative ): string {
		$mtime = @filemtime( LW_SCAN_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file falls back to the plugin version below.

		return false !== $mtime ? (string) $mtime : LW_SCAN_VERSION;
	}

	/**
	 * The requested tab, or the first one for anything unknown.
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter; it selects a panel and changes nothing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return in_array( $tab, [ 'scan', 'findings', 'notifications', 'settings', 'health', 'status' ], true ) ? $tab : 'scan';
	}

	/**
	 * A tab's own URL on this page.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function tab_url( string $tab ): string {
		return add_query_arg(
			[
				'page' => self::SLUG,
				'tab'  => $tab,
			],
			admin_url( 'admin.php' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		( new SettingsRenderer( $this->tabs ) )->render_page( self::SETTINGS_GROUP, self::current_tab() );
	}
}
