<?php
/**
 * LW Plugins Parent Page.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the LW Plugins parent menu page.
 */
final class ParentPage {

	public const SLUG = 'lw-plugins';

	private const REGISTRY_URL = 'https://raw.githubusercontent.com/lwplugins/registry/main/plugins.json';

	private const CACHE_KEY = 'lw_plugins_registry';

	private const CACHE_TTL = 43200;

	public static function get_plugins_registry(): array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$remote = self::fetch_remote_registry();

		if ( $remote ) {
			set_transient( self::CACHE_KEY, $remote, self::CACHE_TTL );
			return $remote;
		}

		return self::get_local_fallback();
	}

	private static function fetch_remote_registry(): ?array {
		$response = wp_remote_get( self::REGISTRY_URL, [ 'timeout' => 5 ] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && ! empty( $data ) ? $data : null;
	}

	private static function get_local_fallback(): array {
		return [
			'lw-scan' => [
				'name'          => 'LW Scan',
				'description'   => 'Lightweight malware scanner — files, database and vulnerable software.',
				'icon'          => 'dashicons-shield',
				'icon_color'    => '#d63638',
				'constant'      => 'LW_SCAN_VERSION',
				'settings_page' => 'lw-scan',
				'github'        => 'https://github.com/lwplugins/lw-scan',
			],
		];
	}

	public static function maybe_register(): void {
		global $admin_page_hooks;

		if ( ! empty( $admin_page_hooks[ self::SLUG ] ) ) {
			return;
		}

		add_menu_page(
			__( 'LW Plugins', 'lw-scan' ),
			__( 'LW Plugins', 'lw-scan' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'render' ],
			'dashicons-superhero-alt',
			80
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap lw-plugins-overview">
			<h1><?php esc_html_e( 'LW Plugins', 'lw-scan' ); ?></h1>
			<p><?php esc_html_e( 'Lightweight plugins for WordPress - minimal footprint, maximum impact.', 'lw-scan' ); ?></p>

			<div class="lw-plugins-cards" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
				<?php self::render_all_plugin_cards(); ?>
				<?php do_action( 'lw_plugins_overview_cards' ); ?>
			</div>

			<div class="lw-plugins-footer" style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ccd0d4;">
				<p>
					<a href="https://github.com/lwplugins" target="_blank">GitHub</a> |
					<a href="https://lwplugins.com" target="_blank">Website</a>
				</p>
			</div>
		</div>
		<?php
	}

	private static function render_all_plugin_cards(): void {
		foreach ( self::get_plugins_registry() as $plugin ) {
			self::render_plugin_card( $plugin );
		}
	}

	private static function render_plugin_card( array $plugin ): void {
		// The registry can come from a remote JSON file — normalize it so a
		// missing key cannot throw (defined(null) is a TypeError under
		// strict_types) and a hostile icon_color cannot break out of the
		// inline style.
		$plugin = array_merge(
			[
				'constant'      => '',
				'icon'          => 'dashicons-admin-plugins',
				'icon_color'    => '#00a876',
				'name'          => '',
				'description'   => '',
				'github'        => '',
				'settings_page' => '',
			],
			$plugin
		);

		$icon_color = preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $plugin['icon_color'] ) ? (string) $plugin['icon_color'] : '#00a876';
		$is_active  = '' !== (string) $plugin['constant'] && defined( (string) $plugin['constant'] );
		?>
		<div class="lw-plugin-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; width: 300px;">
			<h2 style="margin-top: 0;">
				<span class="dashicons <?php echo esc_attr( (string) $plugin['icon'] ); ?>" style="color: <?php echo esc_attr( $icon_color ); ?>;"></span>
				<?php echo esc_html( (string) $plugin['name'] ); ?>
				<?php if ( $is_active ) : ?>
					<span style="display: inline-block; background: #00a32a; color: #fff; font-size: 11px; padding: 2px 6px; border-radius: 3px; margin-left: 8px; vertical-align: middle;"><?php esc_html_e( 'Active', 'lw-scan' ); ?></span>
				<?php endif; ?>
			</h2>
			<p><?php echo esc_html( (string) $plugin['description'] ); ?></p>
			<p>
				<?php if ( $is_active ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $plugin['settings_page'] ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Settings', 'lw-scan' ); ?>
					</a>
				<?php else : ?>
					<a href="<?php echo esc_url( (string) $plugin['github'] ); ?>" class="button" target="_blank">
						<?php esc_html_e( 'Get Plugin', 'lw-scan' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
