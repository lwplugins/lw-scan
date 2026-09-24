<?php
/**
 * Tests for Rest\HealthController's index maintenance.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Rest\HealthController;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use WP_Error;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/Db/FakeWpdb.php';
require_once dirname( __DIR__ ) . '/WpErrorStub.php';
require_once dirname( __DIR__ ) . '/RestStubs.php';

/**
 * Drives the real controller against the FakeWpdb stub: the rebuild's own
 * truncates are covered by the repository tests, so what matters here is
 * what the controller does around them.
 */
final class HealthControllerTest extends MonkeyTestCase {

	/** @var array<string, mixed> What get_option( 'lw_scan_state' ) returns. */
	private array $state = [];

	/** @var array<int,string> Transient names passed to delete_transient(), in call order. */
	private array $deleted_transients = [];

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wpdb'] = new \wpdb();

		$this->state              = [];
		$this->deleted_transients = [];

		$state   = &$this->state;
		$deleted = &$this->deleted_transients;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$state ) {
				return 'lw_scan_state' === $name ? $state : $fallback;
			}
		);
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\stubTranslationFunctions();
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( &$deleted ) {
				$deleted[] = $name;

				return true;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_a_rebuild_empties_the_index_and_drops_the_health_cache(): void {
		$result = ( new HealthController() )->index( new WP_REST_Request( [], [ 'op' => 'rebuild' ] ) );

		$queries = implode( ' | ', $GLOBALS['wpdb']->queries );

		$this->assertSame( [ 'ok' => true ], $result );
		$this->assertStringContainsString( 'TRUNCATE TABLE', $queries );
		// The health report counts indexed files and caches the answer for
		// 10 minutes; after a rebuild that answer is wrong.
		$this->assertContains( 'lw_scan_health', $this->deleted_transients );
	}

	public function test_an_unknown_operation_changes_nothing(): void {
		$result = ( new HealthController() )->index( new WP_REST_Request( [], [ 'op' => 'nonsense' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lw_scan_bad_op', $result->get_error_code() );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
		$this->assertSame( [], $this->deleted_transients );
	}

	public function test_a_rebuild_is_refused_while_a_scan_is_running(): void {
		$this->state = [ 'run' => [ 'run_id' => 7 ] ];

		$result = ( new HealthController() )->index( new WP_REST_Request( [], [ 'op' => 'rebuild' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lw_scan_busy', $result->get_error_code() );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}
}
