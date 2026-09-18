<?php
/**
 * Tests for Admin\Ajax\IndexHandler.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin\Ajax;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\Ajax\IndexHandler;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use RuntimeException;

require_once dirname( __DIR__, 2 ) . '/Db/FakeWpdb.php';

/**
 * Drives the real handler against the FakeWpdb stub: the rebuild's own
 * truncates are covered by the repository tests, so what matters here is
 * what the handler does around them.
 */
final class IndexHandlerTest extends MonkeyTestCase {

	/** @var array<int,string> Transient names passed to delete_transient(), in call order. */
	private array $deleted_transients = [];

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wpdb'] = new \wpdb();

		$deleted                  = &$this->deleted_transients;
		$this->deleted_transients = [];

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_send_json_success' )->justReturn( null );
		// The real wp_send_json_error() ends the request; without that the
		// handler would fall through to the truncates it just refused.
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
		unset( $GLOBALS['wpdb'], $_POST['op'] );
		parent::tearDown();
	}

	public function test_a_rebuild_empties_the_index_and_drops_the_health_cache(): void {
		$_POST['op'] = 'rebuild';

		IndexHandler::handle();

		$queries = implode( ' | ', $GLOBALS['wpdb']->queries );

		$this->assertStringContainsString( 'TRUNCATE TABLE', $queries );
		// The health report counts indexed files and caches the answer for
		// 10 minutes; after a rebuild that answer is wrong.
		$this->assertContains( 'lw_scan_health', $this->deleted_transients );
	}

	public function test_an_unknown_operation_changes_nothing(): void {
		$_POST['op'] = 'nonsense';

		try {
			IndexHandler::handle();
			$this->fail( 'The handler must refuse an unknown operation.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error', $e->getMessage() );
		}

		$this->assertSame( [], $GLOBALS['wpdb']->queries );
		$this->assertSame( [], $this->deleted_transients );
	}
}
