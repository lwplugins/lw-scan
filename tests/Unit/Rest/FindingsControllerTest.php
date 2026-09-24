<?php
/**
 * Tests for Rest\FindingsController's writes.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Rest\FindingsController;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use WP_Error;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/Db/FakeWpdb.php';
require_once dirname( __DIR__ ) . '/WpErrorStub.php';
require_once dirname( __DIR__ ) . '/RestStubs.php';

/**
 * Drives the real controller against the FakeWpdb stub.
 */
final class FindingsControllerTest extends MonkeyTestCase {

	/** @var array<string, mixed> What get_option( 'lw_scan_state' ) returns. */
	private array $state = [];

	/** @var array<int, string> Transient names passed to delete_transient(). */
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
		Functions\stubTranslationFunctions();
		Functions\when( 'sanitize_key' )->alias( static fn ( $value ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ) );
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
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

	public function test_clearing_deletes_every_finding_and_requeues_every_file(): void {
		$result = ( new FindingsController() )->clear();

		$queries = $GLOBALS['wpdb']->queries;

		$this->assertCount( 2, $queries );
		$this->assertMatchesRegularExpression( '/^DELETE FROM \S*lw_scan_findings$/', $queries[0] );
		// Without the hash reset a changed-files scan would never look at an
		// unchanged infected file again, and its finding would stay gone.
		$this->assertStringContainsString( "SET md5 = '', sha256 = '', known_good = 0, scanned_bundle = 0", $queries[1] );
		$this->assertStringContainsString( 'lw_scan_files', $queries[1] );
		$this->assertContains( 'lw_scan_health', $this->deleted_transients );
		$this->assertSame( [ 'cleared' => 1 ], $result );
	}

	public function test_clearing_is_refused_with_409_while_a_scan_is_running(): void {
		$this->state = [
			'run' => [
				'run_id' => 7,
				'phase'  => 'files',
			],
		];

		$result = ( new FindingsController() )->clear();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lw_scan_busy', $result->get_error_code() );
		$this->assertSame( [ 'status' => 409 ], $result->error_data['lw_scan_busy'] );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}

	public function test_an_unknown_state_is_refused_with_400(): void {
		$result = ( new FindingsController() )->set_state( new WP_REST_Request( [], [ 'ids' => [ 1 ], 'state' => 'deleted' ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lw_scan_bad_state', $result->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $result->error_data['lw_scan_bad_state'] );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}

	public function test_no_usable_ids_update_nothing(): void {
		$result = ( new FindingsController() )->set_state( new WP_REST_Request( [], [ 'ids' => [ 0, 'x', [ 3 ] ], 'state' => 'ignored' ] ) );

		$this->assertSame( [ 'updated' => 0 ], $result );
		$this->assertSame( [], $GLOBALS['wpdb']->queries );
	}
}
