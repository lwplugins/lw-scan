<?php
/**
 * Storage directory writability check for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

use LightweightPlugins\Scan\Bundle\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12/§14: without a writable `wp-content/lw-scan/` directory the
 * signature pack can't be downloaded, so a scan can't start —
 * `Environment::blocking_issue()` runs this check fresh, unconditionally.
 * `ensure_dir()` both creates the directory (and its `index.php`/`.htaccess`
 * guard files) if missing and reports whether it ended up writable, so a
 * first-activation site with no directory yet isn't wrongly blocked.
 */
final class StorageCheck implements CheckInterface {

	/**
	 * @var Store
	 */
	private Store $store;

	public function __construct( ?Store $store = null ) {
		$this->store = $store ?? new Store();
	}

	public function id(): string {
		return 'storage';
	}

	public function label(): string {
		return __( 'Storage directory', 'lw-scan' );
	}

	public function run(): array {
		$writable = $this->store->ensure_dir();

		if ( ! $writable ) {
			return [
				'status'   => 'critical',
				/* translators: %s: absolute storage directory path. */
				'message'  => sprintf( __( '%s is not writable — the signature bundle cannot be stored and scans cannot start.', 'lw-scan' ), $this->store->dir() ),
				'blocking' => true,
			];
		}

		return [
			'status'   => 'ok',
			/* translators: 1: absolute storage directory path. 2: human-readable size, e.g. "4 MB". */
			'message'  => sprintf( __( '%1$s is writable, guard files in place (%2$s used).', 'lw-scan' ), $this->store->dir(), size_format( $this->store->size_bytes() ) ),
			'blocking' => false,
		];
	}
}
