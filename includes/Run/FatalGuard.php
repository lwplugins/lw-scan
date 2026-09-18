<?php
/**
 * Safety net that catches a fatal error thrown mid-tick.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

defined( 'ABSPATH' ) || exit;

/**
 * A fatal (`E_ERROR` and friends) kills the request without unwinding, so
 * neither the tick's `finally` nor any `catch` ever runs and the run would
 * stay `running` forever (spec §14). This registers one shutdown handler
 * per instance and fires the caller's callback only when the request is
 * dying *while* a tick is armed — an ordinary end-of-request shutdown, or a
 * fatal raised by something else entirely after the tick disarmed, is left
 * alone.
 */
final class FatalGuard {

	/** @var array<int, int> Error types that mean the request died, not ended. */
	private const FATAL_ERRORS = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ];

	/** @var callable Invoked with the fatal's message when one is caught. */
	private $on_fatal;

	/** @var bool True between arm() and disarm(). */
	private bool $armed = false;

	/** @var bool Whether the shutdown handler is already registered. */
	private bool $registered = false;

	/**
	 * @param callable $on_fatal Invoked with the fatal error's message.
	 */
	public function __construct( callable $on_fatal ) {
		$this->on_fatal = $on_fatal;
	}

	/**
	 * Starts watching, registering the shutdown handler on first use.
	 */
	public function arm(): void {
		$this->armed = true;

		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		register_shutdown_function(
			function (): void {
				$this->handle();
			}
		);
	}

	public function disarm(): void {
		$this->armed = false;
	}

	public function armed(): bool {
		return $this->armed;
	}

	private function handle(): void {
		if ( ! $this->armed ) {
			return;
		}

		$error = error_get_last();

		if ( null === $error || ! in_array( $error['type'], self::FATAL_ERRORS, true ) ) {
			return;
		}

		$this->armed = false;

		call_user_func( $this->on_fatal, (string) $error['message'] );
	}
}
