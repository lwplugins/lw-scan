<?php
/**
 * Contract for a settings tab that needs markup outside the settings form.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Forms cannot nest, so a tab inside the `options.php` form cannot open a
 * second one for a write that does not belong to the settings save. A tab
 * that implements this gets a slot right after the settings form closes,
 * where that form can live; a button inside the card reaches it through its
 * `form` attribute, the same way the Status tab's "Generate new URL" does.
 */
interface AfterFormInterface {

	/** Markup rendered as a sibling of the settings form, just after it. */
	public function after_form(): void;
}
