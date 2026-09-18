<?php
/**
 * Tests for Admin\Ajax\ClearFindingsHandler.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin\Ajax;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\Ajax\ClearFindingsHandler;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use RuntimeException;

require_once dirname( __DIR__, 2 ) . '/Db/FakeWpdb.php';

/**
 * Drives the real handler against the FakeWpdb stub.
 */
final class ClearFindingsHandlerTest extends MonkeyTestCase {

	/** @var array<string, mixed> What get_option( 'lw_scan_state' ) returns. */
	private array $state = [];

	/** @var array<int, string> Transient names passed to delete_transient(). */
	private array $deleted_transients = [];

	/** @var array<int, mixed> Payloads passed to wp_send_json_success(). */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wpdb'] = new \wpdb();

		$this->state              = [];
		$this->deleted_transients = [];
		$this->sent               = [];

		$state   = &$this->state;
		$deleted = &$this->deleted_transients;
		$sent    = &$this->sent;

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$state ) {
				return 'lw_scan_state' === $name ? $state : $fallback;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) use ( &$sent ): void {
				$sent[] = $data;
			}
		);
		// The real wp_send_json_error() ends the request; without that the
		// handler would fall through to the deletes it just refused.
		Functions\when( 'wp_send_json_error' )->alias(
			static function (): void {
				throw new RuntimeException( 'wp_send_json_error' );
			}
		);
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

	public function test_clearing_deletes_every_finding_and_requeues_every_file(): void {
		ClearFindingsHandler::handle();

		$queries = $GLOBALS['wpdb']->queries;

		$this->assertCount( 2, $queries );
		$this->assertMatchesRegularExpression( '/^DELETE FROM \S*lw_scan_findings$/', $queries[0] );
		// Without the hash reset a changed-files scan would never look at an
		// unchanged infected file again, and its finding would stay gone.
		$this->assertStringContainsString( "SET md5 = '', sha256 = '', known_good = 0, scanned_bundle = 0", $queries[1] );
		$this->assertStringContainsString( 'lw_scan_files', $queries[1] );
		$this->assertContains( 'lw_scan_health', $this->deleted_transients );
		$this->assertSame( [ [ 'cleared' => 1 ] ], $this->sent );
	}

	public function test_clearing_is_refused_while_a_scan_is_running(): void {
		$this->state = [
			'run' => [
				'run_id' => 7,
				'phase'  => 'files',
			],
		];

		try {
			ClearFindingsHandler::handle();
			$this->fail( 'The handler must refuse while a run cursor exists.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
		}

		$this->assertSame( [], $GLOBALS['wpdb']->queries );
		$this->assertSame( [], $this->sent );
	}
}
