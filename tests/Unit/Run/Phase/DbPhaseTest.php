<?php
/**
 * Tests for Run\Phase\DbPhase.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run\Phase;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Phase\DbPhase;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;
use Mockery;
use RuntimeException;

require_once dirname( __DIR__, 2 ) . '/Db/FakeWpdb.php';

/**
 * The sub-scanners are exercised by their own tests; these cover the
 * phase's own job — running them in order, keeping `{scanner, last_id}` in
 * the cursor, and giving the tick back when the budget is spent. An empty
 * bundle means the paged scanners return `done` without touching the
 * database, which keeps the focus on the sequencing.
 */
final class DbPhaseTest extends MonkeyTestCase {

	private const RUN_ID = 42;

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $option_store = [];

	/** @var FindingsRepositoryInterface&\Mockery\MockInterface */
	private $findings;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wpdb'] = new \wpdb();

		PackLoader::use_store( null );

		$this->option_store = [];
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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		PackLoader::use_store( null );
		parent::tearDown();
	}

	private function context(): Context {
		$cursor = Cursor::fresh( self::RUN_ID, 'db', '', Phases::for_scope( 'db' ) );
		$cursor->set_phase( 'db' );

		$this->findings = Mockery::mock( FindingsRepositoryInterface::class );

		$ctx = new Context(
			$cursor,
			new RunStats(),
			[],
			Mockery::mock( FilesRepositoryInterface::class ),
			$this->findings,
			Mockery::mock( RunsRepositoryInterface::class ),
			[]
		);

		$ctx->use_signatures( self::empty_signatures() );

		return $ctx;
	}

	/**
	 * A context that never had `use_signatures()` called on it — as on any
	 * tick but the one that runs `BundlePhase` — with `bundle_version` at 0,
	 * so `PackLoader::signatures()` (and so `Context::signatures()`) answers
	 * null without touching the filesystem.
	 */
	private function context_without_signatures(): Context {
		$cursor = Cursor::fresh( self::RUN_ID, 'db', '', Phases::for_scope( 'db' ) );
		$cursor->set_phase( 'db' );

		$this->findings = Mockery::mock( FindingsRepositoryInterface::class );

		return new Context(
			$cursor,
			new RunStats(),
			[],
			Mockery::mock( FilesRepositoryInterface::class ),
			$this->findings,
			Mockery::mock( RunsRepositoryInterface::class ),
			[]
		);
	}

	/**
	 * An empty pack: enough for the sub-scanners to see no db rules and
	 * finish paged scans in a single `done` call, without touching the
	 * database.
	 */
	private static function empty_signatures(): Signatures {
		$builder = new PackBuilder();
		$meta    = $builder->meta();

		return new Signatures(
			$builder->pack(),
			static function () use ( $meta ): PackMeta {
				return $meta;
			},
			NewSignatures::none()
		);
	}

	public function test_budget_spent_between_sub_scanners_leaves_the_next_one_in_the_cursor(): void {
		$ctx = $this->context();

		$complete = ( new DbPhase() )->run(
			$ctx,
			static function (): bool {
				return true;
			}
		);

		$this->assertFalse( $complete );

		$db = (array) $ctx->cursor->get( 'db', [] );
		$this->assertSame( 'posts', $db['scanner'] );
		$this->assertSame( 0, $db['last_id'] );
	}

	public function test_a_full_pass_runs_every_sub_scanner_and_reports_complete(): void {
		$ctx = $this->context();

		$complete = ( new DbPhase() )->run(
			$ctx,
			static function (): bool {
				return false;
			}
		);

		$this->assertTrue( $complete );

		$db = (array) $ctx->cursor->get( 'db', [] );
		$this->assertSame( 'triggers', $db['scanner'] );
		$this->assertSame( [], $ctx->stats->to_array()['db']['skipped'] );
	}

	public function test_every_sub_scanner_counts_the_rows_it_examined(): void {
		// Two administrators on the site, both already in the baseline: no
		// finding, but two rows the db phase did look at. `phases.db.items`
		// reading 0 has to mean "nothing was examined", which is how a run
		// that quietly scanned nothing gives itself away.
		$GLOBALS['wpdb']->results_queue[] = [ [ 'user_id' => '5' ], [ 'user_id' => '9' ] ];
		$this->option_store['lw_scan_state'] = [ 'admin_user_ids' => [ 5, 9 ] ];

		$ctx = $this->context();

		( new DbPhase() )->run(
			$ctx,
			static function (): bool {
				return false;
			}
		);

		$stats = $ctx->stats->to_array();

		$this->assertSame( 2, $stats['db']['users'] );
		$this->assertSame( 0, $stats['db']['findings'] );
		$this->assertSame( 2, $ctx->items );
	}

	public function test_a_new_administrator_is_counted_as_a_database_finding(): void {
		$GLOBALS['wpdb']->results_queue[] = [ [ 'user_id' => '5' ], [ 'user_id' => '9' ] ];
		$GLOBALS['wpdb']->row_queue[]     = [
			'user_login'      => 'evil',
			'user_registered' => '2026-01-01 00:00:00',
		];
		$this->option_store['lw_scan_state'] = [ 'admin_user_ids' => [ 5 ] ];

		$ctx = $this->context();

		$this->findings->shouldReceive( 'upsert' )->once()->andReturn(
			[
				'id'      => 1,
				'created' => true,
				'changed' => true,
			]
		);

		( new DbPhase() )->run(
			$ctx,
			static function (): bool {
				return false;
			}
		);

		$stats = $ctx->stats->to_array();

		$this->assertSame( 2, $stats['db']['users'], 'the stat counts rows looked at, not findings' );
		$this->assertSame( 1, $stats['db']['findings'] );
	}

	public function test_a_trigger_permission_failure_is_recorded_as_skipped(): void {
		$GLOBALS['wpdb']->last_error = 'SHOW TRIGGERS command denied';

		$ctx = $this->context();

		( new DbPhase() )->run(
			$ctx,
			static function (): bool {
				return false;
			}
		);

		$this->assertSame( [ 'triggers' ], $ctx->stats->to_array()['db']['skipped'] );
	}

	public function test_db_scanner_without_signatures_is_bundle_missing(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'bundle_missing' );

		( new DbPhase() )->run(
			$this->context_without_signatures(),
			static function (): bool {
				return false;
			}
		);
	}
}
