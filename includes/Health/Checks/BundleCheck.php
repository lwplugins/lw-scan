<?php
/**
 * Signature pack availability check for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §5.6/§12: without a signature pack a scan can't run —
 * `Environment::blocking_issue()` runs this check fresh, unconditionally.
 *
 * Deliberately a set of `stat()` calls (via `Store::has_pack()`) and nothing
 * more. Answering this by loading the pack put the whole thing on top of
 * whatever the admin request already held, which is how the Health tab came
 * to return a 500 on a 256M site. A pack that exists and is non-empty is
 * what a scan needs; whether its *contents* are still valid is
 * `Bundle\PackLoader`'s question, asked by the run that actually needs them.
 *
 * Never blocking (spec §5.6): a fresh install has no pack by definition, and
 * the `bundle` phase of the very first scan is what downloads one —
 * blocking there would mean the plugin could never run the scan that fixes
 * the condition. When a stored version's files are missing from storage,
 * the next scan's bundle phase simply re-downloads them (spec §14), which is
 * more use to a site owner than a Start button that refuses.
 */
final class BundleCheck implements CheckInterface {

	/**
	 * @var Store
	 */
	private Store $store;

	public function __construct( ?Store $store = null ) {
		$this->store = $store ?? new Store();
	}

	public function id(): string {
		return 'bundle';
	}

	public function label(): string {
		return __( 'Signature bundle', 'lw-scan' );
	}

	public function run(): array {
		$version = (int) State::get( 'bundle_version', 0 );

		if ( 0 === $version ) {
			return self::row( 'critical', __( 'No signature pack yet — the first scan downloads it.', 'lw-scan' ) );
		}

		if ( ! $this->store->has_pack( $version ) ) {
			/* translators: %d: signature pack version. */
			return self::row( 'critical', sprintf( __( 'The files of signature pack v%d are missing — the next scan downloads them again.', 'lw-scan' ), $version ) );
		}

		$count = (int) State::get( 'bundle_count', 0 );

		/* translators: 1: signature pack version. 2: number of signatures. */
		return self::row( 'ok', sprintf( _n( 'Signature pack v%1$d, %2$s signature.', 'Signature pack v%1$d, %2$s signatures.', $count, 'lw-scan' ), $version, number_format( $count ) ) );
	}

	/**
	 * @param string $status  One of 'critical'|'ok'.
	 * @param string $message Human-readable outcome description.
	 * @return array{status:string, message:string, blocking:bool}
	 */
	private static function row( string $status, string $message ): array {
		return [
			'status'   => $status,
			'message'  => $message,
			'blocking' => false,
		];
	}
}
