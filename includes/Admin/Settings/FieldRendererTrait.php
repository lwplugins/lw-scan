<?php
/**
 * Settings field renderers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Form controls for the Settings tab, all posting into the single
 * `lw_scan_options[...]` array `SettingsSanitizer` validates.
 */
trait FieldRendererTrait {

	protected function field_name( string $key ): string {
		return Options::OPTION_NAME . '[' . $key . ']';
	}

	/**
	 * @param string                   $key         Option key.
	 * @param array<array-key, string> $choices  Value => label; numeric keys stay usable.
	 * @param string                   $description Optional help text.
	 */
	protected function render_select( string $key, array $choices, string $description = '' ): void {
		$current = $this->current( $key );
		printf( '<select id="%1$s" name="%2$s">', esc_attr( $key ), esc_attr( $this->field_name( $key ) ) );

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s" %3$s>%2$s</option>',
				esc_attr( (string) $value ),
				esc_html( $label ),
				selected( $current, (string) $value, false )
			);
		}

		echo '</select>';
		$this->render_description( $description );
	}

	/**
	 * The mockup's segmented control: a radio group styled as one strip.
	 *
	 * @param string                   $key         Option key.
	 * @param array<array-key, string> $choices  Value => label; numeric keys stay usable.
	 * @param string                   $description Optional help text.
	 */
	protected function render_segment( string $key, array $choices, string $description = '' ): void {
		$current = $this->current( $key );
		echo '<div class="lw-scan-seg">';

		foreach ( $choices as $value => $label ) {
			printf(
				'<label class="%4$s"><input type="radio" name="%1$s" value="%2$s" %5$s /> %3$s</label>',
				esc_attr( $this->field_name( $key ) ),
				esc_attr( (string) $value ),
				esc_html( $label ),
				esc_attr( $current === (string) $value ? 'is-on' : '' ),
				checked( $current, (string) $value, false )
			);
		}

		echo '</div>';
		$this->render_description( $description );
	}

	protected function render_toggle( string $key, string $label, string $description = '' ): void {
		// The hidden companion makes an unchecked box post "0" — see Admin\SettingsSanitizer on why absent must mean "not this form's".
		printf(
			'<input type="hidden" name="%1$s" value="0" /><label class="lw-scan-toggle"><input type="checkbox" class="lw-scan-switch" name="%1$s" value="1" %3$s /> <span>%2$s</span></label>',
			esc_attr( $this->field_name( $key ) ),
			esc_html( $label ),
			checked( (bool) Options::get( $key ), true, false )
		);
		$this->render_description( $description );
	}

	protected function render_textarea( string $key, string $value, int $rows, string $description = '' ): void {
		printf(
			'<textarea id="%1$s" name="%2$s" rows="%3$d" class="lw-scan-textarea large-text code">%4$s</textarea>',
			esc_attr( $key ),
			esc_attr( $this->field_name( $key ) ),
			(int) $rows,
			esc_textarea( $value )
		);
		$this->render_description( $description );
	}

	protected function render_text( string $key, string $value, string $description = '' ): void {
		printf(
			'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text lw-scan-wide" />',
			esc_attr( $key ),
			esc_attr( $this->field_name( $key ) ),
			esc_attr( $value )
		);
		$this->render_description( $description );
	}

	private function current( string $key ): string {
		// Booleans become "1"/"0" so a select can offer them as two choices.
		$value = Options::get( $key );

		return is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
	}

	private function render_description( string $description ): void {
		if ( '' === $description ) {
			return;
		}

		printf( '<p class="description">%s</p>', esc_html( $description ) );
	}
}
