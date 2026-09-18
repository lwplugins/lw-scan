<?php
/**
 * Tests for DbScan\PostsScanner.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\DbScan;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\DbScan\PostsScanner;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;
use Mockery;

require_once __DIR__ . '/../Db/FakeWpdb.php';

final class PostsScannerTest extends MonkeyTestCase {

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
		$builder->sig( 0, 'suspicious', 'regex', 'spam' );
		$builder->db( 'db_post', 0, '/<script[^>]*src=\/\/[a-z0-9.-]+/i', '%script%' );

		return self::built( $builder );
	}

	public function test_the_prefilter_covers_every_column_the_rules_are_matched_against(): void {
		$wpdb                  = new \wpdb();
		$wpdb->results_queue[] = [];

		$findings = Mockery::mock( FindingsRepositoryInterface::class );

		$scanner = new PostsScanner( $wpdb, $this->signatures(), $findings );
		$scanner->scan( 0 );

		$sql = $wpdb->prepared_queries[0];

		// scan() matches post_content AND post_excerpt, so a row whose only
		// hit is in the excerpt must survive the prefilter.
		$this->assertStringContainsString( 'post_content LIKE %s', $sql );
		$this->assertStringContainsString( 'post_excerpt LIKE %s', $sql );
		$this->assertContains( '%script%', $wpdb->prepared_args[0][0] );
	}

	public function test_a_match_in_the_excerpt_alone_produces_a_finding(): void {
		$wpdb                  = new \wpdb();
		$wpdb->results_queue[] = [
			[
				'ID'           => 12,
				'post_type'    => 'post',
				'post_title'   => 'Hello',
				'post_content' => 'clean body',
				'post_excerpt' => '<script src=//linkangood.x></script>',
			],
		];

		$findings = Mockery::mock( FindingsRepositoryInterface::class );
		$findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( Finding $finding ): bool {
						return 'db' === $finding->type && 'posts:post_excerpt:12' === $finding->locator;
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

		$scanner = new PostsScanner( $wpdb, $this->signatures(), $findings );
		$result  = $scanner->scan( 0 );

		$this->assertSame( 1, $result['findings'] );
		$this->assertSame( 12, $result['last_id'] );
	}
}
