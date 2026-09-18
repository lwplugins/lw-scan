<?php
/**
 * Tests for SiteManager\AbilityCallbacks.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\SiteManager;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Run\RunnerInterface;
use LightweightPlugins\Scan\SiteManager\AbilityCallbacks;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use Mockery;
use WP_Error;

require_once dirname( __DIR__ ) . '/WpErrorStub.php';

final class AbilityCallbacksTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		AbilityCallbacks::set_runner_factory( null );
	}

	protected function tearDown(): void {
		AbilityCallbacks::set_runner_factory( null );
		parent::tearDown();
	}

	/**
	 * Injects a Mockery double for the RunnerInterface the callbacks use.
	 *
	 * @return \Mockery\MockInterface&RunnerInterface
	 */
	private function inject_runner() {
		$runner = Mockery::mock( RunnerInterface::class );

		AbilityCallbacks::set_runner_factory(
			static function () use ( $runner ) {
				return $runner;
			}
		);

		return $runner;
	}

	/**
	 * `Scheduler::kick()` with no tick pending schedules exactly one.
	 *
	 * `spawn_cron()` is stubbed rather than left undefined: another test in
	 * the same process may already have defined it through Brain Monkey,
	 * which would make `Scheduler::nudge_cron()`'s `function_exists()` true
	 * here too and blow up on the missing expectation.
	 */
	private function expect_kick(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'spawn_cron' )->justReturn( true );
		Functions\expect( 'wp_schedule_single_event' )->once();
	}

	public function test_run_starts_the_scan_and_returns_the_run_id(): void {
		$this->inject_runner()->shouldReceive( 'start' )->once()->andReturn( 17 );
		$this->expect_kick();

		$result = AbilityCallbacks::run( [ 'scope' => 'changed' ] );

		$this->assertSame(
			[
				'run_id'  => 17,
				'started' => true,
			],
			$result
		);
	}

	public function test_run_opens_the_run_with_the_ability_trigger(): void {
		$this->inject_runner()->shouldReceive( 'start' )->once()->with( 'ability', 'changed', '', false )->andReturn( 3 );
		$this->expect_kick();

		AbilityCallbacks::run( [ 'scope' => 'changed' ] );
	}

	public function test_run_passes_the_path_and_resume_flag_through(): void {
		$this->inject_runner()
			->shouldReceive( 'start' )
			->once()
			->with( 'ability', 'path', 'wp-content/plugins/acme', true )
			->andReturn( 4 );
		$this->expect_kick();

		AbilityCallbacks::run(
			[
				'scope'  => 'path',
				'path'   => 'wp-content/plugins/acme',
				'resume' => true,
			]
		);
	}

	public function test_run_returns_the_runners_error_untouched_and_does_not_kick(): void {
		$error = new WP_Error( 'lw_scan_busy', 'A scan is already in progress.' );
		$this->inject_runner()->shouldReceive( 'start' )->once()->andReturn( $error );
		Functions\expect( 'wp_schedule_single_event' )->never();

		$this->assertSame( $error, AbilityCallbacks::run( [ 'scope' => 'full' ] ) );
	}

	public function test_run_refuses_an_unknown_scope_without_touching_the_runner(): void {
		$this->inject_runner()->shouldReceive( 'start' )->never();
		Functions\expect( 'wp_schedule_single_event' )->never();

		$result = AbilityCallbacks::run( [ 'scope' => 'everything' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lw_scan_bad_scope', $result->get_error_code() );
	}

	public function test_run_refuses_a_missing_scope(): void {
		$this->inject_runner()->shouldReceive( 'start' )->never();

		$this->assertInstanceOf( WP_Error::class, AbilityCallbacks::run( [] ) );
	}

	public function test_findings_query_defaults_to_the_first_page_of_fifty(): void {
		$this->assertSame(
			[
				'filters'  => [],
				'page'     => 1,
				'per_page' => 50,
			],
			AbilityCallbacks::findings_query( [] )
		);
	}

	public function test_findings_query_keeps_the_known_filters_only(): void {
		$query = AbilityCallbacks::findings_query(
			[
				'severity' => 'alert',
				'type'     => 'file',
				'state'    => 'new',
				'search'   => 'eval(',
			]
		);

		$this->assertSame(
			[
				'severity' => 'alert',
				'type'     => 'file',
				'state'    => 'new',
			],
			$query['filters']
		);
	}

	public function test_findings_query_drops_empty_filters(): void {
		$query = AbilityCallbacks::findings_query(
			[
				'severity' => '',
				'state'    => 'ignored',
			]
		);

		$this->assertSame( [ 'state' => 'ignored' ], $query['filters'] );
	}

	public function test_findings_query_caps_per_page_at_two_hundred(): void {
		$this->assertSame( 200, AbilityCallbacks::findings_query( [ 'per_page' => 5000 ] )['per_page'] );
	}

	public function test_findings_query_floors_page_and_per_page_at_one(): void {
		$query = AbilityCallbacks::findings_query(
			[
				'page'     => 0,
				'per_page' => 0,
			]
		);

		$this->assertSame( 1, $query['page'] );
		$this->assertSame( 1, $query['per_page'] );
	}

	public function test_acknowledge_refuses_an_unknown_state(): void {
		$result = AbilityCallbacks::acknowledge(
			[
				'ids'   => [ 1, 2 ],
				'state' => 'resolved',
			]
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lw_scan_bad_state', $result->get_error_code() );
	}

	public function test_acknowledge_without_ids_updates_nothing(): void {
		$result = AbilityCallbacks::acknowledge(
			[
				'ids'   => [ 0 ],
				'state' => 'acknowledged',
			]
		);

		$this->assertSame( [ 'updated' => 0 ], $result );
	}
}
