<?php
/**
 * Tracks consecutive scheduled-run failures and notifies once.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Notify;

use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §10.8: on the third consecutive failure, mail once and remember it
 * (`State.failure_notified`) so later failures in the same streak don't
 * mail again; any success resets both counters.
 */
final class FailureStreak {

	private const THRESHOLD = 3;

	/**
	 * Called from Run\Runner when a run ends `failed`.
	 *
	 * @param string $error The run's failure message.
	 */
	public static function record_failure( string $error ): void {
		$streak = (int) State::get( 'failure_streak', 0 ) + 1;

		State::set( 'failure_streak', $streak );

		if ( self::THRESHOLD !== $streak || (bool) State::get( 'failure_notified', false ) ) {
			return;
		}

		$run = [
			'error' => $error,
			'id'    => (int) State::get( 'last_run_id', 0 ),
		];

		Mailer::send_failure_streak( $run, Options::all() );

		State::set( 'failure_notified', true );
	}

	/**
	 * Called from Run\Phase\FinalizePhase when a run ends `done`.
	 */
	public static function record_success(): void {
		State::merge(
			[
				'failure_streak'   => 0,
				'failure_notified' => false,
			]
		);
	}
}
