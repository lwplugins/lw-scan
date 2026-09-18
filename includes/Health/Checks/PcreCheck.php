<?php
/**
 * PCRE runtime tuning check for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

use LightweightPlugins\Scan\Scanner\RegexLayer;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12: `pcre.jit` and whether `pcre.backtrack_limit`/`pcre.recursion_limit`
 * could be raised (a locked-down host returns `false` from `ini_set()`).
 * Calls `RegexLayer::configure_pcre()` so the report reflects the same
 * tuning the scanner itself relies on. The `ini_get` reader is injectable
 * so tests can simulate a disabled JIT without touching the real php.ini
 * value for the rest of the process (flipping `pcre.jit` for real, like
 * `RegexLayerTest` does, would leak into every test that runs afterwards).
 */
final class PcreCheck implements CheckInterface {

	/**
	 * @var callable(string): (string|false)
	 */
	private $ini_get;

	/**
	 * @param callable(string): (string|false) $ini_get Reads one ini setting; defaults to the real `ini_get()`.
	 */
	public function __construct( ?callable $ini_get = null ) {
		$this->ini_get = $ini_get ?? 'ini_get';
	}

	public function id(): string {
		return 'pcre';
	}

	public function label(): string {
		return __( 'PCRE engine', 'lw-scan' );
	}

	public function run(): array {
		RegexLayer::configure_pcre();
		$tuned = RegexLayer::pcre_configured();

		if ( ! $tuned['backtrack'] || ! $tuned['recursion'] ) {
			return [
				'status'   => 'warning',
				'message'  => __( 'The host does not allow raising pcre.backtrack_limit/pcre.recursion_limit — very large files may silently fail to match.', 'lw-scan' ),
				'blocking' => false,
			];
		}

		if ( ! $this->jit_enabled() ) {
			return [
				'status'   => 'warning',
				'message'  => __( 'PCRE JIT is disabled — regex matching will be slower on large sites.', 'lw-scan' ),
				'blocking' => false,
			];
		}

		return [
			'status'   => 'ok',
			'message'  => __( 'PCRE limits tuned and JIT enabled.', 'lw-scan' ),
			'blocking' => false,
		];
	}

	private function jit_enabled(): bool {
		$jit = ( $this->ini_get )( 'pcre.jit' );

		return false !== $jit && '' !== $jit && '0' !== $jit;
	}
}
