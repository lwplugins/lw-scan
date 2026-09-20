<?php
/**
 * Tests for Run\Phase\FinalizePhase.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run\Phase;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Notify\Baseline;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Phase\FinalizePhase;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use Mockery;

final class FinalizePhaseTest extends MonkeyTestCase {

	private const RUN_ID = 42;

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var FilesRepositoryInterface&\Mockery\MockInterface */
	private $files;

	/** @var FindingsRepositoryInterface&\Mockery\MockInterface */
	private $findings;

	/** @var RunsRepositoryInterface&\Mockery\MockInterface */
	private $runs;

	/** @var array<int, array<string, mixed>> What findings->new_since() reports for this test. */
	private array $new_findings = [];

	protected function setUp(): void {
		parent::setUp();

		$this->option_store = [];
		$this->new_findings = [];
		$store              = &$this->option_store;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value ) use ( &$store ) {
				$store[ $name ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$store ) {
				unset( $store[ $name ] );

				return true;
			}
		);

		// The mailer really runs from this phase, so the WordPress calls it
		// makes have to exist; each notification test says what it expects
		// of wp_mail() itself.
		Functions\stubTranslationFunctions();
		Functions\when( 'is_email' )->alias(
			static fn ( $email ) => (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL )
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Site' );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ) => 'https://example.test/wp-admin/' . $path );

		$this->files    = Mockery::mock( FilesRepositoryInterface::class );
		$this->findings = Mockery::mock( FindingsRepositoryInterface::class );
		$this->runs     = Mockery::mock( RunsRepositoryInterface::class );

		// `new_since()` is static on the interface, so Mockery resolves it
		// through the class, not the instance: a second mock of the same
		// interface would not override this. Tests vary the result through
		// $this->new_findings instead.
		$new_findings = &$this->new_findings;

		$this->findings->shouldReceive( 'new_since' )->andReturnUsing(
			static function () use ( &$new_findings ) {
				return $new_findings;
			}
		);
	}

	/**
	 * Lets the run-closing calls happen without asserting on them — a
	 * blanket expectation declared in setUp() would swallow the stricter
	 * ones the closing test itself declares.
	 */
	private function allow_run_close(): void {
		$this->runs->shouldReceive( 'finish' )->andReturnNull();
		$this->runs->shouldReceive( 'prune' )->andReturnNull();
	}

	/**
	 * @param string $scope   Scan scope.
	 * @param string $trigger Run trigger, as stored on the run row.
	 */
	private function context( string $scope, string $trigger = 'manual' ): Context {
		$cursor = Cursor::fresh( self::RUN_ID, $scope, '', Phases::for_scope( $scope ) );
		$cursor->set( 'trigger', $trigger );
		$cursor->set_phase( 'finalize' );
		$cursor->save();

		return new Context(
			$cursor,
			new RunStats(),
			Options::all(),
			$this->files,
			$this->findings,
			$this->runs,
			[]
		);
	}

	private static function never_due(): callable {
		return static function (): bool {
			return false;
		};
	}

	/**
	 * A phase whose baseline is already behind it: what every test that is
	 * not about the baseline wants, and what keeps `Baseline`'s own
	 * runs-table probe — which needs a database — out of their way.
	 *
	 * @param callable|null $next_due Overrides the next-due computation.
	 */
	private static function phase( ?callable $next_due = null ): FinalizePhase {
		return new FinalizePhase( $next_due, self::baseline( true ) );
	}

	public function test_full_scope_deletes_unseen_files_and_their_findings(): void {
		$this->allow_run_close();
		$this->files->shouldReceive( 'delete_unseen' )->once()->with( self::RUN_ID )->andReturn( [ 5, 6 ] );
		$this->findings->shouldReceive( 'delete_for_files' )->once()->with( [ 5, 6 ] )->andReturn( 2 );

		$ctx = $this->context( 'full' );

		$this->assertTrue( self::phase()->run( $ctx, self::never_due() ) );
		$this->assertSame( 2, $ctx->stats->to_array()['files']['deleted'] );
	}

	public function test_path_scope_never_deletes_unseen_files(): void {
		$this->allow_run_close();
		$this->files->shouldReceive( 'delete_unseen' )->never();
		$this->findings->shouldReceive( 'delete_for_files' )->never();

		$this->assertTrue( self::phase()->run( $this->context( 'path' ), self::never_due() ) );
	}

	public function test_finalize_finishes_the_run_prunes_history_and_clears_the_cursor(): void {
		$this->files->shouldReceive( 'delete_unseen' )->andReturn( [] );

		$this->runs->shouldReceive( 'finish' )->once()->with( self::RUN_ID, 'done', Mockery::type( 'array' ) )->andReturnNull();
		$this->runs->shouldReceive( 'prune' )->once()->with( 50 )->andReturnNull();

		self::phase()->run( $this->context( 'changed' ), self::never_due() );

		$this->assertNull( Cursor::load() );
		$this->assertSame( self::RUN_ID, State::get( 'last_run_id' ) );
		$this->assertIsInt( State::get( 'last_success_at' ) );
	}

	public function test_finalize_counts_every_new_finding_not_just_the_file_ones(): void {
		$this->new_findings = [
			[
				'type'     => 'file',
				'severity' => 'alert',
			],
			[
				'type'     => 'integrity',
				'severity' => 'alert',
			],
			[
				'type'     => 'db',
				'severity' => 'review',
			],
		];

		$this->allow_run_close();
		$this->runs->shouldReceive( 'get' )->andReturn( [] );
		$this->files->shouldReceive( 'delete_unseen' )->andReturn( [] );

		$ctx = $this->context( 'changed' );
		// What FilesPhase counted while the run was going: file findings only.
		$ctx->stats->inc( 'findings.new' );
		$ctx->stats->inc( 'findings.alerts_new' );

		self::phase()->run( $ctx, self::never_due() );

		$findings = $ctx->stats->to_array()['findings'];

		$this->assertSame( 3, $findings['new'], 'integrity/db/vulnerability findings were excluded, so the run stats disagreed with `wp lw-scan status`.' );
		$this->assertSame( 2, $findings['alerts_new'] );
		$this->assertSame( 1, $findings['review_new'] );
	}

	/**
	 * @param bool $answer What the injected baseline probe reports.
	 */
	private static function baseline( bool $answer ): Baseline {
		return new Baseline(
			static function () use ( $answer ): bool {
				return $answer;
			}
		);
	}

	/**
	 * Two alert findings and a recipient to mail them to, so the only thing
	 * left deciding whether wp_mail() runs is the baseline.
	 */
	private function with_mailable_findings(): void {
		$this->new_findings = [
			[
				'type'     => 'file',
				'severity' => 'alert',
				'locator'  => 'wp-content/uploads/a.php',
			],
			[
				'type'     => 'file',
				'severity' => 'alert',
				'locator'  => 'wp-content/uploads/b.php',
			],
		];

		$this->option_store[ Options::OPTION_NAME ] = [ 'notify_emails' => [ 'ops@example.test' ] ];

		$this->allow_run_close();
		$this->runs->shouldReceive( 'get' )->andReturn( [] );
		$this->files->shouldReceive( 'delete_unseen' )->andReturn( [] );
	}

	public function test_the_first_finished_run_mails_nothing_and_records_the_baseline(): void {
		Functions\expect( 'wp_mail' )->never();
		$this->with_mailable_findings();

		( new FinalizePhase( null, self::baseline( false ) ) )->run( $this->context( 'changed' ), self::never_due() );

		$this->assertTrue( State::get( Baseline::DONE_KEY ) );
		$this->assertSame( self::RUN_ID, State::get( Baseline::RUN_KEY ) );
	}

	public function test_a_run_after_the_baseline_mails_as_configured(): void {
		Functions\expect( 'wp_mail' )->once()->andReturn( true );
		$this->with_mailable_findings();

		( new FinalizePhase( null, self::baseline( true ) ) )->run( $this->context( 'changed' ), self::never_due() );
	}

	/**
	 * The migration: an install that has been mailing since 1.0 has no
	 * baseline flag, and must not be silenced by the upgrade that
	 * introduced one.
	 */
	public function test_an_install_with_an_earlier_finished_run_keeps_mailing_through_the_upgrade(): void {
		Functions\expect( 'wp_mail' )->once()->andReturn( true );
		$this->with_mailable_findings();

		State::set( 'last_success_at', 1789000000 );

		// No injected probe: this is Baseline's own State-first migration.
		( new FinalizePhase() )->run( $this->context( 'changed' ), self::never_due() );

		$this->assertNull( State::get( Baseline::RUN_KEY ), 'An already-mailing site has no baseline run to record.' );
	}

	public function test_next_due_is_refreshed_for_a_cron_trigger(): void {
		$this->allow_run_close();
		$this->files->shouldReceive( 'delete_unseen' )->andReturn( [] );

		$phase = self::phase(
			static function (): int {
				return 1234;
			}
		);

		$phase->run( $this->context( 'changed', 'cron' ), self::never_due() );

		$this->assertSame( 1234, Options::get( 'next_due' ) );
		$this->assertIsInt( Options::get( 'last_auto_run' ) );
		$this->assertGreaterThan( 0, Options::get( 'last_auto_run' ) );
	}

	public function test_next_due_is_left_alone_for_a_manual_trigger(): void {
		$this->allow_run_close();
		$this->files->shouldReceive( 'delete_unseen' )->andReturn( [] );

		$phase = self::phase(
			static function (): int {
				return 1234;
			}
		);

		$phase->run( $this->context( 'changed', 'manual' ), self::never_due() );

		$this->assertSame( 0, Options::get( 'next_due' ) );
		$this->assertSame( 0, Options::get( 'last_auto_run' ) );
	}
}
