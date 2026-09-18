<?php
/**
 * Inline SVG icon set for the admin UI.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data class: the approved mockup's line icons, as static SVG path
 * data. Dashicons has no stable equivalent for several of these across the
 * whole WP 6.0+ range (the `database` family only landed later), so the UI
 * draws its own — no emoji, no bare glyphs (plan §Global Constraints).
 *
 * Every string here is a literal constant; nothing user-supplied ever
 * reaches the markup, which is why `render()` may echo it unescaped.
 */
final class Icons {

	/**
	 * Path data keyed by icon name; all drawn on a 24x24 viewBox with
	 * `currentColor`, so CSS alone colors them.
	 *
	 * @var array<string, string>
	 */
	private const PATHS = [
		'shield'        => '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
		'alert'         => '<path d="M12 3L2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/>',
		'warning'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
		'ok'            => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
		'file'          => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z"/><path d="M14 3v5h5"/>',
		'integrity'     => '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3z"/><path d="M12 9v4M12 16h.01"/>',
		'database'      => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
		'vulnerability' => '<path d="M8 9a4 4 0 0 1 8 0v6a4 4 0 0 1-8 0V9z"/><path d="M12 5V3M5 12H3M21 12h-2M6 7l-2-2M18 7l2-2M6 17l-2 2M18 17l2 2"/>',
		'spinner'       => '<path d="M12 3a9 9 0 1 0 9 9"/>',
		'search'        => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
		'trash'         => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/><path d="M10 11v6M14 11v6"/>',
	];

	/**
	 * @param string $name  Icon name; an unknown name draws nothing.
	 * @param string $class CSS class for the `<svg>` element.
	 */
	public static function get( string $name, string $class = 'lw-scan-ic' ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
			esc_attr( $class ),
			self::PATHS[ $name ]
		);
	}

	/**
	 * @param string $name  Icon name.
	 * @param string $class CSS class for the `<svg>` element.
	 */
	public static function render( string $name, string $class = 'lw-scan-ic' ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG built from class constants; the only interpolated value ($class) is esc_attr()'d in get().
		echo self::get( $name, $class );
	}

	/**
	 * The icon that stands for a finding type in the list.
	 *
	 * @param string $type file|integrity|db|vulnerability.
	 */
	public static function for_type( string $type ): string {
		$map = [
			'file'          => 'file',
			'integrity'     => 'integrity',
			'db'            => 'database',
			'vulnerability' => 'vulnerability',
		];

		return $map[ $type ] ?? 'file';
	}

	/**
	 * The icon that stands for a health-row / verdict status.
	 *
	 * @param string $status ok|warning|critical.
	 */
	public static function for_status( string $status ): string {
		if ( 'critical' === $status ) {
			return 'alert';
		}

		return 'warning' === $status ? 'warning' : 'ok';
	}
}
