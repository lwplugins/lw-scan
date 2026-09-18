<?php
/**
 * Scripted PhaseInterface double for RunnerTest.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Phase\PhaseInterface;
use Throwable;

/**
 * Returns a fixed completion verdict (or throws a fixed exception) and
 * records how often it ran, so the Runner's phase sequencing can be
 * asserted without any real scan work.
 */
final class FakePhase implements PhaseInterface {

	public int $calls = 0;

	/** @var bool Verdict run() returns when it isn't throwing. */
	private bool $complete;

	/** @var Throwable|null Thrown instead of returning, when set. */
	private ?Throwable $throw;

	/** @var callable|null Extra side effect invoked with the Context before returning. */
	private $side_effect;

	/**
	 * @param bool           $complete    Verdict run() returns.
	 * @param Throwable|null $throw       Thrown instead of returning, when set.
	 * @param callable|null  $side_effect Invoked with the Context before returning.
	 */
	public function __construct( bool $complete = true, ?Throwable $throw = null, ?callable $side_effect = null ) {
		$this->complete    = $complete;
		$this->throw       = $throw;
		$this->side_effect = $side_effect;
	}

	public function run( Context $ctx, callable $deadline ): bool {
		++$this->calls;

		if ( null !== $this->throw ) {
			throw $this->throw;
		}

		if ( null !== $this->side_effect ) {
			call_user_func( $this->side_effect, $ctx, $deadline );
		}

		return $this->complete;
	}
}
