<?php
/**
 * Tests for Scanner\RegexLayer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Scanner\Prefilter;
use LightweightPlugins\Scan\Scanner\RegexLayer;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

final class RegexLayerTest extends MonkeyTestCase {

	private Signatures $signatures;

	protected function setUp(): void {
		parent::setUp();

		$this->signatures = FixturePack::signatures( 'mini' );
	}

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

	/**
	 * Regex index of the mini fixture's `test:re:plain` rule.
	 */
	private function plain_index(): int {
		$pack = $this->signatures->pack();

		for ( $i = 0; $i < $pack->regex_count(); $i++ ) {
			if ( 'test:re:plain' === $this->signatures->meta()->id( $pack->regex_sig( $i ) ) ) {
				return $i;
			}
		}

		$this->fail( 'fixture precondition: test:re:plain must be a regex in the mini pack' );
	}

	/**
	 * @return array<int, true>
	 */
	private function presence( string $content ): array {
		return Prefilter::presence( strtolower( $content ), $this->signatures->pack() );
	}

	/**
	 * @param array<int,\LightweightPlugins\Scan\Scanner\MatchResult> $matches
	 * @return string[]
	 */
	private static function sig_ids( array $matches ): array {
		return array_map(
			static function ( $match ): string {
				return $match->sig_id;
			},
			$matches
		);
	}

	public function test_match_on_a_php_sample_gives_the_expected_sig_ids(): void {
		$content = "define('DISABLE_WP_CRON', true);\nmalicious_marker_007\n";
		$indexes = RegexLayer::indexes_for( $this->signatures, [ 'file_php' ] );
		$result  = RegexLayer::match( $this->signatures, $indexes, $content, true, 0, $this->presence( $content ), false );

		$this->assertSame( 0, $result['errors'] );
		$this->assertSame( [ 'test:re:anchored-a', 'test:re:plain' ], self::sig_ids( $result['matches'] ) );
	}

	public function test_anchored_regex_does_not_run_on_first_chunk_false(): void {
		$content  = "eval(base64_decode('X'));";
		$indexes  = RegexLayer::indexes_for( $this->signatures, [ 'file_php' ] );
		$presence = $this->presence( $content );

		$first  = RegexLayer::match( $this->signatures, $indexes, $content, true, 0, $presence, false );
		$second = RegexLayer::match( $this->signatures, $indexes, $content, false, 0, $presence, false );

		$this->assertContains( 'test:re:anchored-caret', self::sig_ids( $first['matches'] ) );
		$this->assertNotContains( 'test:re:anchored-caret', self::sig_ids( $second['matches'] ) );
		$this->assertContains( 'test:re:two-common', self::sig_ids( $second['matches'] ), 'a non-anchored regex on the same target must still run on a later chunk' );
	}

	public function test_prefilter_false_suppresses_gated_regexes_but_not_ungated_ones(): void {
		$content = "eval(base64_decode('X'));\nmalicious_marker_9\n";
		$indexes = RegexLayer::indexes_for( $this->signatures, [ 'file_php' ] );

		$result = RegexLayer::match( $this->signatures, $indexes, $content, true, 0, [], false );

		$this->assertSame(
			[ 'test:re:plain' ],
			self::sig_ids( $result['matches'] ),
			'an empty presence map must suppress every regex gated by common_strings ("eval", "base64_decode("), while a regex with no common_strings (empty lits) still runs and matches'
		);
	}

	public function test_skip_suspicious_is_applied_after_an_infected_hit(): void {
		$content = "eval(base64_decode('X'));\nmalicious_marker_9\n";
		$indexes = RegexLayer::indexes_for( $this->signatures, [ 'file_php' ] );
		$result  = RegexLayer::match( $this->signatures, $indexes, $content, true, 0, $this->presence( $content ), false );

		$sig_ids = self::sig_ids( $result['matches'] );

		$this->assertTrue( $result['infected'] );
		$this->assertContains( 'test:re:anchored-caret', $sig_ids );
		$this->assertNotContains( 'test:re:plain', $sig_ids, 'a suspicious-tier regex must be skipped once an infected match was already found' );
	}

	public function test_catastrophic_pattern_produces_a_pcre_error_instead_of_an_exception(): void {
		RegexLayer::configure_pcre();

		// phpcs:ignore WordPress.PHP.IniSet.Risky -- test-only: force the JIT off so the classic backtracking engine reliably hits pcre.backtrack_limit instead of finishing fast.
		$original_jit = ini_get( 'pcre.jit' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- see above.
		ini_set( 'pcre.jit', '0' );

		try {
			$signatures = self::built(
				( new PackBuilder() )
					->sig( 0, 'suspicious', 'regex', 'unknown' )
					->regex( 0, '/^(\w+\s?)*$/', 'file_php', [], true )
			);

			$subject = str_repeat( 'a', 40 ) . '!';

			$result = RegexLayer::match( $signatures, [ 0 ], $subject, true, 0, [], false );

			$this->assertSame( [], $result['matches'] );
			$this->assertSame( 1, $result['errors'] );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- restoring the JIT setting this test forced off above.
			ini_set( 'pcre.jit', false === $original_jit ? '1' : $original_jit );
		}
	}

	public function test_deadline_already_past_returns_immediately(): void {
		$deadline = static function (): bool {
			return true;
		};

		$result = RegexLayer::match( $this->signatures, [ 0, 1, 2 ], 'irrelevant', true, 0, [], false, 1, $deadline );

		$this->assertSame( [], $result['matches'] );
		$this->assertSame( 0, $result['errors'] );
		$this->assertSame( 1, $result['stopped_at'] );
	}

	public function test_deadline_true_at_the_second_batch_sets_stopped_at(): void {
		$indexes  = array_fill( 0, 120, $this->plain_index() );
		$calls    = 0;
		$deadline = static function () use ( &$calls ): bool {
			++$calls;

			return $calls >= 2;
		};

		$result = RegexLayer::match( $this->signatures, $indexes, 'nothing interesting here', true, 0, [], false, 0, $deadline );

		$this->assertSame( [], $result['matches'] );
		$this->assertSame( 0, $result['errors'] );
		$this->assertSame( 50, $result['stopped_at'] );
		$this->assertSame( 2, $calls );
	}

	public function test_indexes_for_returns_the_sorted_deduped_union_of_the_requested_targets(): void {
		$php_only = RegexLayer::indexes_for( $this->signatures, [ 'file_php' ] );

		$this->assertSame( $php_only, self::sorted_unique( $php_only ), 'must already be sorted ascending with no duplicates' );
		$this->assertCount( 4, $php_only );

		$union = RegexLayer::indexes_for( $this->signatures, [ 'file_php', 'file_js' ] );

		$this->assertSame( $union, self::sorted_unique( $union ) );
		$this->assertCount( 5, $union );
		$this->assertSame( self::sorted_unique( array_merge( $php_only, $this->signatures->pack()->target_regexes( 'file_js' ) ) ), $union );
	}

	public function test_indexes_for_only_new_intersects_with_the_new_regex_set(): void {
		$plain      = $this->plain_index();
		$signatures = FixturePack::signatures(
			'mini',
			NewSignatures::from_array(
				[
					'version' => 2,
					'since'   => 1,
					'regex'   => [ $plain ],
					'literal' => [],
					'hash'    => false,
				]
			)
		);

		$all_new = RegexLayer::indexes_for( $signatures, [ 'file_php' ], true );

		$this->assertSame( [ $plain ], $all_new );

		foreach ( $all_new as $index ) {
			$this->assertSame( 'test:re:plain', $signatures->meta()->id( $signatures->pack()->regex_sig( $index ) ) );
		}

		$this->assertSame( [], RegexLayer::indexes_for( $signatures, [ 'file_js', 'htaccess' ], true ), 'a new rule on another target is not pulled in' );
	}

	public function test_indexes_for_sorts_a_target_list_the_pack_stored_out_of_order(): void {
		// Pack load range-checks per-target lists but does not require them
		// ascending, so the walk order must not depend on how they were stored.
		$builder = ( new PackBuilder() )->sig( 0, 'suspicious' );
		$builder->regex( 0, '/a/' )->regex( 0, '/b/' )->regex( 0, '/c/', 'file_js' );

		$data                        = $builder->pack_array();
		$data['targets']['file_php'] = base64_encode( pack( 'V*', 1, 0 ) );
		$data['targets']['file_js']  = base64_encode( pack( 'V*', 2, 0 ) );
		$pack                        = Pack::from_array( $data );

		$this->assertNotNull( $pack, 'fixture precondition: an unordered target list still loads' );
		$this->assertSame( [ 1, 0 ], $pack->target_regexes( 'file_php' ) );

		$meta       = $builder->meta();
		$signatures = new Signatures(
			$pack,
			static function () use ( $meta ): PackMeta {
				return $meta;
			},
			NewSignatures::none()
		);

		$this->assertSame( [ 0, 1, 2 ], RegexLayer::indexes_for( $signatures, [ 'file_php', 'file_js' ] ) );
	}

	/**
	 * @param int[] $indexes
	 * @return int[]
	 */
	private static function sorted_unique( array $indexes ): array {
		$unique = array_values( array_unique( $indexes ) );

		sort( $unique );

		return $unique;
	}
}
