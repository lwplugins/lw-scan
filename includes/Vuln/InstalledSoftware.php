<?php
/**
 * Inventory of installed WordPress core, plugins and themes.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Vuln;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WordPress' own plugin/theme APIs (spec §8.1) into a flat list the
 * rest of the scanner (Index\Origin, Index\KnownGood, Vuln\Matcher) can
 * consume without touching WordPress functions directly.
 */
final class InstalledSoftware {

	/**
	 * @return array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	public static function list(): array {
		$list   = [];
		$list[] = self::core_entry();

		foreach ( self::plugin_entries() as $entry ) {
			$list[] = $entry;
		}

		foreach ( self::theme_entries() as $entry ) {
			$list[] = $entry;
		}

		return $list;
	}

	/**
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $list Result of list().
	 * @return array<int,string>
	 */
	public static function plugin_slugs( array $list ): array {
		return self::slugs_of_kind( $list, 'plugin' );
	}

	/**
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $list Result of list().
	 * @return array<int,string>
	 */
	public static function theme_slugs( array $list ): array {
		return self::slugs_of_kind( $list, 'theme' );
	}

	/**
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $list Result of list().
	 * @return string
	 */
	public static function core_version( array $list ): string {
		$core = self::find( $list, 'core', 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- 'wordpress' is the slug constant used by core_entry(), not prose.

		return null !== $core ? $core['version'] : '';
	}

	/**
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $list Result of list().
	 * @param string                                                                                $kind 'core', 'plugin' or 'theme'.
	 * @param string                                                                                $slug Package slug.
	 * @return array{kind:string, slug:string, version:string, name:string, active:bool}|null
	 */
	public static function find( array $list, string $kind, string $slug ): ?array {
		foreach ( $list as $item ) {
			if ( $item['kind'] === $kind && $item['slug'] === $slug ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * @return array{kind:string, slug:string, version:string, name:string, active:bool}
	 */
	private static function core_entry(): array {
		return [
			'kind'    => 'core',
			'slug'    => 'wordpress',
			'version' => (string) get_bloginfo( 'version' ),
			'name'    => 'WordPress',
			'active'  => true,
		];
	}

	/**
	 * @return array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	private static function plugin_entries(): array {
		$entries = [];

		foreach ( self::get_plugins() as $file => $data ) {
			$entries[] = [
				'kind'    => 'plugin',
				'slug'    => self::plugin_slug( (string) $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'name'    => (string) ( $data['Name'] ?? '' ),
				'active'  => is_plugin_active( (string) $file ),
			];
		}

		return $entries;
	}

	/**
	 * @return array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	private static function theme_entries(): array {
		$active_stylesheet = wp_get_theme()->get_stylesheet();

		$entries = [];

		foreach ( wp_get_themes() as $theme ) {
			$stylesheet = $theme->get_stylesheet();

			$entries[] = [
				'kind'    => 'theme',
				'slug'    => $stylesheet,
				'version' => (string) $theme->get( 'Version' ),
				'name'    => (string) $theme->get( 'Name' ),
				'active'  => $stylesheet === $active_stylesheet,
			];
		}

		return $entries;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * @param string $file Plugin file, e.g. `dir/file.php` or `file.php`.
	 * @return string
	 */
	private static function plugin_slug( string $file ): string {
		$slash = strpos( $file, '/' );

		if ( false !== $slash ) {
			return substr( $file, 0, $slash );
		}

		return (string) preg_replace( '/\.php$/', '', $file );
	}

	/**
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $list Result of list().
	 * @param string                                                                                $kind 'plugin' or 'theme'.
	 * @return array<int,string>
	 */
	private static function slugs_of_kind( array $list, string $kind ): array {
		$slugs = [];

		foreach ( $list as $item ) {
			if ( $item['kind'] === $kind ) {
				$slugs[] = $item['slug'];
			}
		}

		return $slugs;
	}
}
