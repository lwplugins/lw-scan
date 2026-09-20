<?php
/**
 * Tests for Notify\Baseline.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Notify;

use LightweightPlugins\Scan\Notify\Baseline;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;

/**
 * The migration is what these tests are really about: a site that has been
 * mailing since 1.0 must not be handed a fresh baseline by the upgrade and
 * go quiet for a scan.
 */
final class BaselineTest extends MonkeyTestCase {

	use FakeStore;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_store();
	}

	/**
	 * @param bool $answer What the prior-run probe reports.
	 * @return callable(): bool
	 */
	private static function probe( bool $answer ): callable {
		return static function () use ( $answer ): bool {
			return $answer;
		};
	}

	public function test_a_fresh_install_has_no_baseline_yet(): void {
		$this->assertFalse( ( new Baseline( self::probe( false ) ) )->taken() );
	}

	public function test_the_state_flag_alone_settles_it(): void {
		State::set( Baseline::DONE_KEY, true );

		$baseline = new Baseline(
			static function (): bool {
				throw new \LogicException( 'The probe must not run once the flag is set.' );
			}
		);

		$this->assertTrue( $baseline->taken() );
	}

	public function test_an_install_with_a_finished_run_counts_as_migrated(): void {
		$this->assertTrue(
			( new Baseline( self::probe( true ) ) )->taken(),
			'A site that has been mailing since 1.0 has no flag yet; suppressing its next scan mail would be a regression.'
		);
	}

	/**
	 * Baseline's own probe, not an injected one: a recorded successful run
	 * settles it before the runs table is ever asked, which is what lets
	 * this run without a database.
	 */
	public function test_the_default_probe_reads_a_recorded_successful_run_from_state(): void {
		State::set( 'last_success_at', 1789000000 );

		$this->assertTrue( ( new Baseline() )->taken() );
	}

	public function test_a_migrated_install_records_nothing_new(): void {
		$this->assertSame( [], ( new Baseline( self::probe( true ) ) )->record_finished_run( 12 ) );
	}

	public function test_a_first_finished_run_records_the_flag_and_its_run_id(): void {
		$this->assertSame(
			[
				Baseline::DONE_KEY => true,
				Baseline::RUN_KEY  => 7,
			],
			( new Baseline( self::probe( false ) ) )->record_finished_run( 7 )
		);
	}

	public function test_the_probe_is_asked_once_even_when_taken_is_read_repeatedly(): void {
		$calls = 0;

		$baseline = new Baseline(
			static function () use ( &$calls ): bool {
				++$calls;

				return false;
			}
		);

		$baseline->taken();
		$baseline->taken();
		$baseline->record_finished_run( 3 );

		$this->assertSame( 1, $calls );
	}

	public function test_the_baseline_run_awaits_review_until_a_later_run_finishes(): void {
		State::merge(
			[
				Baseline::DONE_KEY => true,
				Baseline::RUN_KEY  => 4,
				'last_run_id'      => 4,
			]
		);

		$this->assertTrue( ( new Baseline( self::probe( true ) ) )->pending_review() );

		State::set( 'last_run_id', 5 );

		$this->assertFalse( ( new Baseline( self::probe( true ) ) )->pending_review() );
	}

	public function test_nothing_awaits_review_on_a_site_that_never_took_a_baseline(): void {
		$this->assertFalse( ( new Baseline( self::probe( true ) ) )->pending_review() );
	}
}
