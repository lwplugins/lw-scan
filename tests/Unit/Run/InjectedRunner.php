<?php
/**
 * Runner subclass that lets RunnerTest substitute the phase pipeline.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use LightweightPlugins\Scan\Run\Runner;

/**
 * `Runner::phases()` is the seam the real pipeline is built behind; this
 * subclass replaces it with scripted FakePhase doubles so the tick loop can
 * be driven without touching the filesystem, the bundle or the network.
 */
final class InjectedRunner extends Runner {

	/** @var array<string, \LightweightPlugins\Scan\Run\Phase\PhaseInterface> */
	private array $injected = [];

	/**
	 * @param array<string, \LightweightPlugins\Scan\Run\Phase\PhaseInterface> $phases Phase name => double.
	 */
	public function set_phases( array $phases ): void {
		$this->injected = $phases;
	}

	/**
	 * @return array<string, \LightweightPlugins\Scan\Run\Phase\PhaseInterface>
	 */
	protected function phases(): array {
		return $this->injected;
	}
}
