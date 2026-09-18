<?php
/**
 * Tests for DbScan\OptionsScanner.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\DbScan;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\DbScan\OptionsScanner;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;
use Mockery;

require_once __DIR__ . '/../Db/FakeWpdb.php';

final class OptionsScannerTest extends MonkeyTestCase {

	/**
	 * @param PackBuilder $builder Rules to wrap.
	 */
	private static function built( PackBuilder $builder ): Signatures {
		$meta = $builder->meta();

		return new Signatures(
			$builder->pack(),
			static function () use ( $meta ): PackMeta {
				return $meta;
			},
			NewSignatures::none()
		);
	}

	private function signatures(): Signatures {
		$builder = new PackBuilder();
		$builder->sig( 0, 'suspicious', 'regex', 'backdoor' );
		$builder->db( 'db_option', 0, '/<script[^>]*src=\/\/[a-z0-9.-]+/i', '%script%' );

		return self::built( $builder );
	}

	public function test_scan_returns_immediately_when_there_are_no_db_option_rules(): void {
		$wpdb     = new \wpdb();
		$findings = Mockery::mock( FindingsRepositoryInterface::class );

		$signatures = self::built( new PackBuilder() );

		$scanner = new OptionsScanner( $wpdb, $signatures, $findings );
		$result  = $scanner->scan( 10 );

		$this->assertSame(
			[
				'last_id'  => 10,
				'done'     => true,
				'rows'     => 0,
				'findings' => 0,
			],
			$result
		);
		$this->assertSame( [], $wpdb->prepared_queries, 'an empty rule set must not run a query' );
	}

	public function test_scan_queries_autoload_and_the_like_prefilter(): void {
		$wpdb                 = new \wpdb();
		$wpdb->results_queue[] = [
			[
				'option_id'    => 42,
				'option_name'  => 'widget_foo',
				'option_value' => '<script src=//linkangood.x></script>',
			],
		];

		$findings = Mockery::mock( FindingsRepositoryInterface::class );
		$findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( Finding $finding ): bool {
						return 'db' === $finding->type && 'options:option_value:42' === $finding->locator;
					}
				)
			)
			->andReturn(
				[
					'id'      => 1,
					'created' => true,
					'changed' => true,
				]
			);

		$scanner = new OptionsScanner( $wpdb, $this->signatures(), $findings );
		$result  = $scanner->scan( 0 );

		$this->assertCount( 1, $wpdb->prepared_queries );
		$sql = $wpdb->prepared_queries[0];

		$this->assertStringContainsString( 'autoload IN', $sql );
		$this->assertStringContainsString( '(option_value LIKE %s)', $sql );
		$this->assertStringNotContainsString( 'option_name LIKE %s', $sql );
		$this->assertContains( '%script%', $wpdb->prepared_args[0][0] );

		$this->assertSame( 42, $result['last_id'] );
		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 1, $result['findings'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_the_prefilter_targets_option_value_so_a_literal_hidden_in_the_value_survives(): void {
		$wpdb                  = new \wpdb();
		$wpdb->results_queue[] = [
			[
				'option_id'    => 7,
				'option_name'  => 'harmless_probe',
				'option_value' => 'harmless probe <script src=//linkangood.x></script> tail',
			],
		];

		$findings = Mockery::mock( FindingsRepositoryInterface::class );
		$findings->shouldReceive( 'upsert' )
			->once()
			->andReturn(
				[
					'id'      => 1,
					'created' => true,
					'changed' => true,
				]
			);

		$scanner = new OptionsScanner( $wpdb, $this->signatures(), $findings );
		$result  = $scanner->scan( 0 );

		$sql = $wpdb->prepared_queries[0];

		// The LIKE prefilter must target the same column RowMatcher::match()
		// is fed (option_value); prefiltering option_name discarded every row.
		$this->assertStringContainsString( '(option_value LIKE %s)', $sql );
		$this->assertSame( 1, $result['findings'] );
	}
}
