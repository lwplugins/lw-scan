<?php
/**
 * Admin tab contract.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

defined( 'ABSPATH' ) || exit;

interface TabInterface {

	/** The `?tab=` value that routes to this panel. */
	public function slug(): string;

	/** The side-nav caption. */
	public function label(): string;

	/** Dashicons class shown before the caption in the side nav. */
	public function icon(): string;

	/**
	 * Whether the panel's fields belong to the settings form — only the
	 * tab that answers true is wrapped in the `options.php` form and gets
	 * a Save button.
	 */
	public function has_save(): bool;

	public function render(): void;
}
