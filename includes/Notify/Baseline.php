<?php
/**
 * Whether this site has taken its first-scan baseline yet.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Notify;

use LightweightPlugins\Scan\Db\RunsRepository;
use LightweightPlugins\Scan\Db\Schema;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * The first scan on an existing site surfaces the whole backlog at once —
 * every vulnerable plugin, every library that calls `eval()`, every checksum
 * mismatch that has been there for a year. That is a report to read, not an
 * incident to mail, so the first completed run records its findings and
 * sends nothing; every run after it mails as configured.
 *
 * The flag lives in `State` (per-site runtime state, not a setting), and
 * `Run\Phase\FinalizePhase` writes it in the same `State::merge()` that
 * closes the run out, so a finished run still costs one state write.
 *
 * The migration is the part that matters. A site that has been running since
 * 1.0 has no flag, and treating "no flag" as "fresh install" would silence
 * its next scan — exactly the failure mode this class exists to avoid. So
 * "has this site ever finished a run?" is asked before the flag is trusted
 * to mean anything: `State.last_success_at` first, because every finished
 * run since 1.0 has written it and reading it costs nothing here, then the
 * runs table, which answers for a site whose state option was lost but whose
 * history was not. Either one means the baseline is long past.
 */
final class Baseline {

	/** State key: this site has taken its baseline scan. */
	public const DONE_KEY = 'notify_baseline_done';

	/** State key: the run id the baseline came from. */
	public const RUN_KEY = 'notify_baseline_run';

	/** @var callable(): bool Reports whether a run ever finished on this site. */
	private $prior_run_probe;

	/** @var bool|null Memoized answer, so the probe runs at most once per instance. */
	private ?bool $taken = null;

	/**
	 * @param callable(): bool|null $prior_run_probe Reports whether a run ever finished here; the runs table when null.
	 */
	public function __construct( ?callable $prior_run_probe = null ) {
		$this->prior_run_probe = $prior_run_probe ?? [ self::class, 'has_prior_run' ];
	}

	/**
	 * Whether the baseline is behind this site — either because a run took
	 * it under 1.3.0+, or because the site was already scanning (and
	 * mailing) before the baseline existed.
	 */
	public function taken(): bool {
		if ( null === $this->taken ) {
			// Short-circuit on purpose: the flag settles it, and the probe
			// (a table read) only ever runs on a site that has none.
			$this->taken = (bool) State::get( self::DONE_KEY, false ) || (bool) call_user_func( $this->prior_run_probe );
		}

		return $this->taken;
	}

	/**
	 * Whether the baseline run's findings are still the newest thing that
	 * happened here — what the admin notice and the Notifications tab say
	 * is waiting to be read.
	 */
	public function pending_review(): bool {
		$run_id = $this->run_id();

		return $run_id > 0 && (int) State::get( 'last_run_id', 0 ) === $run_id;
	}

	/**
	 * The run the baseline was taken from, 0 when none was.
	 */
	public function run_id(): int {
		return (int) State::get( self::RUN_KEY, 0 );
	}

	/**
	 * What a run that has just finished should merge into `State` — the
	 * flag and the run it came from on the very first one, nothing
	 * afterwards.
	 *
	 * @param int $run_id The run that just finished.
	 * @return array<string, mixed> Values for State::merge(); empty when the baseline is already behind us.
	 */
	public function record_finished_run( int $run_id ): array {
		if ( $this->taken() ) {
			return [];
		}

		return [
			self::DONE_KEY => true,
			self::RUN_KEY  => $run_id,
		];
	}

	/**
	 * Whether this site ever finished a run before the baseline flag
	 * existed. Only asked when the flag is absent, so at most once per
	 * site, on the run that would otherwise be mistaken for the first one.
	 */
	private static function has_prior_run(): bool {
		if ( (int) State::get( 'last_success_at', 0 ) > 0 ) {
			return true;
		}

		if ( ! Schema::exists() ) {
			return false;
		}

		return null !== RunsRepository::last_successful();
	}
}
