<?php
/**
 * Tests for Scanner\FileScanner.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Scanner\FileScanner;
use LightweightPlugins\Scan\Scanner\RegexLayer;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;

final class FileScannerTest extends MonkeyTestCase {

	/** Sig id PackBuilder gives the overlap marker in dedup_signatures(). */
	private const DEDUP_SIG = 'test:1';

	private Signatures $signatures;

	/**
	 * @var string[]
	 */
	private array $temp_files = [];

	protected function setUp(): void {
		parent::setUp();

		$this->signatures = FixturePack::signatures( 'mini' );
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				unlink( $path );
			}
		}

		$this->temp_files = [];

		parent::tearDown();
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
	 * A suspicious `file_php` literal (`zzdedupmarkerzz`, sig DEDUP_SIG) plus
	 * one regex that never matches the padding: the regex sweep is the only
	 * place a scan asks the deadline, so a deadline test needs one to run.
	 */
	private static function dedup_signatures(): Signatures {
		return self::built(
			( new PackBuilder() )
				->sig( 0, 'suspicious', 'regex', 'unknown' )
				->sig( 1, 'suspicious', 'literal', 'unknown' )
				->regex( 0, '/malicious_marker_[0-9]+/' )
				->plain_literal( 1, 'zzdedupmarkerzz', 'file_php' )
		);
	}

	/**
	 * Position in `$indexes` of the mini pack regex that belongs to `$sig_id`.
	 *
	 * @param int[]  $indexes Regex indexes to search, e.g. RegexLayer::indexes_for()'s walk.
	 * @param string $sig_id  Rule id to look for.
	 */
	private function position_of( array $indexes, string $sig_id ): ?int {
		$pack = $this->signatures->pack();

		foreach ( $indexes as $position => $regex_index ) {
			if ( $sig_id === $this->signatures->meta()->id( $pack->regex_sig( $regex_index ) ) ) {
				return $position;
			}
		}

		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function default_config(): array {
		return [
			'max_file_size' => 10485760,
			'heuristics'    => false,
			'memory_limit'  => -1,
		];
	}

	private function write_temp_file( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'lwscan-fs-' );

		$this->assertNotFalse( $path, 'tempnam() must succeed' );

		file_put_contents( (string) $path, $content );
		$this->temp_files[] = (string) $path;

		return (string) $path;
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

	public function test_allowlisted_file_is_reported_clean_without_reading_content(): void {
		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => 'd41d8cd98f00b204e9800998ecf8427e',
			'sha256' => '',
			'size'   => 50,
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, '/nonexistent/path/must-not-be-read.php' );

		$this->assertTrue( $outcome->clean );
		$this->assertSame( [], $outcome->matches );
		$this->assertFalse( $outcome->has_findings() );
	}

	public function test_hash_hit_matches_a_binary_file_without_content_scanning(): void {
		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => 'aabbccddeeff00112233445566778899',
			'sha256' => '',
			'size'   => 999999,
			'kind'   => 'binary',
		];

		$outcome = $scanner->scan( $file_row, '/nonexistent/path/must-not-be-read.bin' );

		$this->assertTrue( $outcome->infected );
		$this->assertSame( [ 'test:md5:1' ], self::sig_ids( $outcome->matches ) );
		$this->assertFalse( $outcome->clean );
		$this->assertFalse( $outcome->large_php );
	}

	public function test_large_php_file_is_flagged_and_not_content_scanned(): void {
		$config             = self::default_config();
		$config['max_file_size'] = 10;

		$scanner  = new FileScanner( $this->signatures, $config );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => 50,
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, '/nonexistent/path/must-not-be-read.php' );

		$this->assertTrue( $outcome->large_php );
		$this->assertSame( [ 'skip:large_php' ], self::sig_ids( $outcome->matches ) );
		$this->assertFalse( $outcome->infected );
		$this->assertSame( 'unknown', $outcome->matches[0]->category, 'the 4th MatchResult ctor argument is category, not severity — severity is computed later by Severity::of()' );
	}

	public function test_binary_file_is_skipped_after_hash_layer_finds_nothing(): void {
		$path = $this->write_temp_file( "MALWARE_TOKEN\nmalware_token\n" );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => '00000000000000000000000000000000',
			'sha256' => '',
			'size'   => filesize( $path ),
			'kind'   => 'binary',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$this->assertFalse( $outcome->has_findings(), 'binary kind must never be content-scanned, even though the content contains a literal signature match' );
		$this->assertFalse( $outcome->infected );
	}

	public function test_archive_file_is_skipped_after_hash_layer_finds_nothing(): void {
		$path = $this->write_temp_file( "PK\x03\x04MALWARE_TOKEN\nmalware_token\n" );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => '00000000000000000000000000000000',
			'sha256' => '',
			'size'   => filesize( $path ),
			'kind'   => 'archive',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$this->assertFalse( $outcome->has_findings(), 'archive kind is compressed bytes: sweeping it with literal/regex layers only burns time' );
		$this->assertFalse( $outcome->infected );
	}

	public function test_infected_match_suppresses_subsequent_suspicious_matches(): void {
		$content = "eval(base64_decode('X'));\nmalicious_marker_9\n";
		$path    = $this->write_temp_file( $content );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$sig_ids = self::sig_ids( $outcome->matches );

		$this->assertTrue( $outcome->infected );
		$this->assertContains( 'test:re:anchored-caret', $sig_ids );
		$this->assertNotContains( 'test:re:plain', $sig_ids, 'a suspicious-tier regex must be skipped once an infected match was already found earlier in the file' );
	}

	public function test_only_new_restricts_matching_to_flagged_signatures(): void {
		$content = "define('DISABLE_WP_CRON', true);\nmalicious_marker_007\n";
		$path    = $this->write_temp_file( $content );

		$plain    = $this->position_of( range( 0, $this->signatures->pack()->regex_count() - 1 ), 'test:re:plain' );
		$new      = NewSignatures::from_array(
			[
				'version' => 2,
				'since'   => 1,
				'regex'   => [ (int) $plain ],
				'literal' => [],
				'hash'    => false,
			]
		);
		$scanner  = new FileScanner( FixturePack::signatures( 'mini', $new ), self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		$full_scan = $scanner->scan( $file_row, $path, false );
		$new_scan  = $scanner->scan( $file_row, $path, true );

		$this->assertContains( 'test:re:anchored-a', self::sig_ids( $full_scan->matches ) );
		$this->assertContains( 'test:re:plain', self::sig_ids( $full_scan->matches ) );

		$this->assertNotContains( 'test:re:anchored-a', self::sig_ids( $new_scan->matches ), 'only_new must exclude a signature not flagged new' );
		$this->assertContains( 'test:re:plain', self::sig_ids( $new_scan->matches ), 'only_new must still include the signature flagged new' );
	}

	public function test_deadline_exhausted_returns_partial_with_resume_position(): void {
		$path = $this->write_temp_file( "hello world\n" );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => 12,
			'kind'   => 'php',
		];

		$deadline = static function (): bool {
			return true;
		};

		$outcome = $scanner->scan( $file_row, $path, false, $deadline );

		$this->assertTrue( $outcome->partial );
		$this->assertSame(
			[
				'chunk'     => 0,
				'regex'     => 0,
				'infected'  => false,
				'errors'    => 0,
				'matches'   => [],
				'heuristic' => [],
				'prev'      => [
					'offset' => 0,
					'sigs'   => [],
				],
			],
			$outcome->resume,
			'a partial outcome must expose the full self-contained resume token, not just {chunk, regex}'
		);
	}

	public function test_resume_continues_from_the_given_regex_index_and_skips_earlier_regexes(): void {
		$content = "define('DISABLE_WP_CRON', true);\nmalicious_marker_007\n";
		$path    = $this->write_temp_file( $content );

		// FileScanner::scan() unions the full kind=php target set (spec §6.6
		// step 5), not just 'file_php' — the resume position must be computed
		// against that same union or it will point at the wrong regex entry.
		$indexes      = RegexLayer::indexes_for( $this->signatures, FileScanner::targets_for( 'php', 'irrelevant.php' ) );
		$resume_regex = $this->position_of( $indexes, 'test:re:plain' );

		$this->assertNotNull( $resume_regex, 'fixture precondition: test:re:plain must be a file_php regex in the pack' );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, $path, false, null, [ 'chunk' => 0, 'regex' => $resume_regex ] );

		$sig_ids = self::sig_ids( $outcome->matches );

		$this->assertNotContains( 'test:re:anchored-a', $sig_ids, 'a regex before the resume position must not be evaluated, even though it would match' );
		$this->assertContains( 'test:re:plain', $sig_ids, 'the regex at the resume position must still be evaluated' );
		$this->assertFalse( $outcome->partial );
	}

	public function test_targets_for_maps_kind_to_the_spec_target_set(): void {
		$this->assertSame( [ 'file_php', 'file_code', 'file_any', 'file_js', 'file_html' ], FileScanner::targets_for( 'php', 'index.php' ) );
		$this->assertSame( [ 'file_js', 'file_code', 'file_any' ], FileScanner::targets_for( 'js', 'script.js' ) );
		$this->assertSame( [ 'file_html', 'file_any' ], FileScanner::targets_for( 'html', 'page.html' ) );
		$this->assertSame( [ 'file_any' ], FileScanner::targets_for( 'other', 'image.png' ) );
		$this->assertSame( [ 'htaccess', 'file_any' ], FileScanner::targets_for( 'other', '.htaccess' ), 'a .htaccess basename must override the content kind' );
	}

	public function test_overlap_dedup_drops_a_duplicate_match_found_in_the_shared_chunk_bytes(): void {
		$signatures = self::dedup_signatures();

		// The marker sits inside the 64 KB window shared by chunk 0 (its
		// last OVERLAP bytes) and chunk 1 (its first OVERLAP bytes): a
		// two-chunk file whose marker's absolute offset is within
		// [CHUNK-OVERLAP, CHUNK) is read once by each chunk.
		$marker_at = 1000000;
		$content   = str_repeat( 'a', $marker_at ) . 'ZZDEDUPMARKERZZ' . str_repeat( 'b', 100000 );
		$path      = $this->write_temp_file( $content );

		$scanner  = new FileScanner( $signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$hits = array_filter(
			$outcome->matches,
			static function ( $match ): bool {
				return self::DEDUP_SIG === $match->sig_id;
			}
		);

		$this->assertCount( 1, $hits, 'the same underlying byte offset must not be reported twice across overlapping chunks' );
	}

	public function test_resume_does_not_duplicate_a_hash_match_found_before_the_deadline(): void {
		$path = $this->write_temp_file( "hello world\n" );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => 'aabbccddeeff00112233445566778899',
			'sha256' => '',
			'size'   => 12,
			'kind'   => 'php',
		];

		$deadline = static function (): bool {
			return true;
		};

		$first = $scanner->scan( $file_row, $path, false, $deadline );

		$this->assertTrue( $first->partial );
		$this->assertSame( [ 'test:md5:1' ], self::sig_ids( $first->matches ), 'the hash match (steps 1-4) must already be in the first, partial outcome' );

		$second = $scanner->scan( $file_row, $path, false, null, $first->resume );

		$this->assertSame( [ 'test:md5:1' ], self::sig_ids( $second->matches ), 'resume must not re-run steps 1-4 and re-append the hash match' );
		$this->assertTrue( $second->infected );
	}

	public function test_resume_keeps_skip_suspicious_active_across_the_deadline_boundary(): void {
		// Chunk 0: an infected match near the start. A ~1.1 MB pad pushes
		// the suspicious marker below into chunk 1's byte range, so it is
		// never seen while chunk 0 is processed — only skip_suspicious
		// carried across the resume can suppress it.
		$prefix  = "eval(base64_decode('X'));\n";
		$padding = str_repeat( 'a', 1100000 );
		$content = $prefix . $padding . "malicious_marker_9\n";
		$path    = $this->write_temp_file( $content );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		$calls    = 0;
		$deadline = static function () use ( &$calls ): bool {
			++$calls;

			return $calls > 1;
		};

		$first = $scanner->scan( $file_row, $path, false, $deadline );

		$this->assertTrue( $first->partial );
		$this->assertTrue( $first->infected, 'chunk 0 must have found the infected match before the deadline stopped chunk 1' );
		$this->assertContains( 'test:re:anchored-caret', self::sig_ids( $first->matches ) );

		$second = $scanner->scan( $file_row, $path, false, null, $first->resume );

		$this->assertNotContains( 'test:re:plain', self::sig_ids( $second->matches ), "skip_suspicious must still be active on the resumed chunk, seeded from the token's infected flag" );
	}

	public function test_resume_does_not_reintroduce_an_overlap_duplicate_after_a_deadline_between_chunks(): void {
		$signatures = self::dedup_signatures();

		// Same shared-overlap-window setup as the single-call dedup test
		// above, but this time a deadline fires between chunk 0 (which
		// finds and fully absorbs the marker) and chunk 1 (which would
		// otherwise find the same bytes again).
		$marker_at = 1000000;
		$content   = str_repeat( 'a', $marker_at ) . 'ZZDEDUPMARKERZZ' . str_repeat( 'b', 100000 );
		$path      = $this->write_temp_file( $content );

		$scanner  = new FileScanner( $signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		$calls    = 0;
		$deadline = static function () use ( &$calls ): bool {
			++$calls;

			return $calls > 1;
		};

		$first = $scanner->scan( $file_row, $path, false, $deadline );

		$this->assertTrue( $first->partial );

		$second = $scanner->scan( $file_row, $path, false, null, $first->resume );

		$hits = array_filter(
			$second->matches,
			static function ( $match ): bool {
				return self::DEDUP_SIG === $match->sig_id;
			}
		);

		$this->assertCount( 1, $hits, 'the overlap match must be reported once even when a deadline interrupts the file between the two chunks that share it' );
	}

	public function test_resume_does_not_reintroduce_an_overlap_duplicate_found_on_the_interrupted_chunk(): void {
		// The other direction of the same problem: here the deadline fires
		// *inside* the chunk that found the marker, so the literal layer that
		// found it never runs again — it is skipped on a resumed chunk — and
		// nothing would fold its hit into the dedup state the next chunk
		// filters against.
		$signatures = self::dedup_signatures();

		// Chunks are 1 MB with 64 KB of overlap, so they start at 0, 983040
		// and 1966080. A marker at 2,000,000 sits in chunk 1's tail and in
		// chunk 2's leading overlap window — the shared bytes.
		$marker_at = 2000000;
		$content   = str_repeat( 'a', $marker_at ) . 'ZZDEDUPMARKERZZ' . str_repeat( 'b', 200000 );
		$path      = $this->write_temp_file( $content );

		$scanner  = new FileScanner( $signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		// Second ask wins: chunk 0 runs to the end, chunk 1's literal sweep
		// finds the marker and its regex sweep is cut off straight away.
		$calls    = 0;
		$deadline = static function () use ( &$calls ): bool {
			++$calls;

			return $calls > 1;
		};

		$first = $scanner->scan( $file_row, $path, false, $deadline );

		$this->assertTrue( $first->partial );
		$this->assertSame( 1, (int) $first->resume['chunk'], 'fixture precondition: the deadline cut chunk 1 short' );
		$this->assertContains( self::DEDUP_SIG, self::sig_ids( $first->matches ), 'fixture precondition: chunk 1 found the marker before the deadline' );

		$second = $scanner->scan( $file_row, $path, false, null, $first->resume );

		$hits = array_filter(
			$second->matches,
			static function ( $match ): bool {
				return self::DEDUP_SIG === $match->sig_id;
			}
		);

		$this->assertCount( 1, $hits, 'a match the interrupted chunk already recorded must not be found again by the chunk that shares those bytes' );
	}

	public function test_resume_token_round_trips_through_json(): void {
		$path = $this->write_temp_file( "hello world\n" );

		$scanner  = new FileScanner( $this->signatures, self::default_config() );
		$file_row = [
			'md5'    => 'aabbccddeeff00112233445566778899',
			'sha256' => '',
			'size'   => 12,
			'kind'   => 'php',
		];

		$deadline = static function (): bool {
			return true;
		};

		$partial = $scanner->scan( $file_row, $path, false, $deadline );

		$this->assertTrue( $partial->partial );

		$json = json_encode( $partial->resume );

		$this->assertIsString( $json, 'the resume token must be JSON-serialisable' );

		$decoded = json_decode( $json, true );

		$this->assertIsArray( $decoded );
		$this->assertSame( $partial->resume, $decoded, 'the resume token must round-trip through json_encode/json_decode unchanged' );

		$resumed = $scanner->scan( $file_row, $path, false, null, $decoded );

		$this->assertSame( [ 'test:md5:1' ], self::sig_ids( $resumed->matches ) );
	}

	public function test_infected_quota_folds_same_chunk_literal_hits_into_the_regex_budget(): void {
		// 6 infected-tier literal signatures, all present in the chunk, plus
		// 6 infected-tier regex signatures that would also all match if the
		// regex budget did not already account for the literal hits.
		$builder = new PackBuilder();

		foreach ( range( 1, 6 ) as $i ) {
			$builder->sig( $i - 1, 'infected', 'literal', 'unknown' )->plain_literal( $i - 1, "inflit{$i}", 'file_php' );
		}

		foreach ( range( 1, 6 ) as $i ) {
			$builder->sig( $i + 5, 'infected', 'regex', 'unknown' )->regex( $i + 5, "/INFRE{$i}/" );
		}

		$signatures = self::built( $builder );

		$literal_part = implode(
			' ',
			array_map(
				static function ( $i ): string {
					return "INFLIT{$i}";
				},
				range( 1, 6 )
			)
		);
		$regex_part   = implode(
			' ',
			array_map(
				static function ( $i ): string {
					return "INFRE{$i}";
				},
				range( 1, 6 )
			)
		);
		$content = $literal_part . "\n" . $regex_part . "\n";
		$path    = $this->write_temp_file( $content );

		$scanner  = new FileScanner( $signatures, self::default_config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => strlen( $content ),
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$infected_matches = array_filter(
			$outcome->matches,
			static function ( $match ): bool {
				return 'infected' === $match->tier;
			}
		);

		$this->assertCount( 10, $infected_matches, "the whole-file infected quota (10) must include this chunk's literal-layer hits before the regex budget is computed" );
	}

	public function test_a_decoded_payload_is_rescanned_against_the_pack(): void {
		// The payload only exists after gzinflate(base64_decode()); the file
		// itself matches no rule, so every signature id below can only come
		// from the heuristic decoder's rescan through the literal and regex
		// layers.
		$payload = '<?php /* MALWARE_TOKEN */ malicious_marker_42();';
		$content = "<?php\neval( gzinflate( base64_decode( '" . base64_encode( (string) gzdeflate( $payload ) ) . "' ) ) );\n";
		$path    = $this->write_temp_file( $content );

		$config               = self::default_config();
		$config['heuristics'] = true;

		$scanner  = new FileScanner( $this->signatures, $config );
		$file_row = [
			'md5'        => '',
			'sha256'     => '',
			'size'       => strlen( $content ),
			'kind'       => 'php',
			'known_good' => 0,
		];

		$outcome = $scanner->scan( $file_row, $path );

		$this->assertSame( [], $outcome->matches, 'fixture precondition: the encoded file itself matches no rule' );

		$decoded = [];

		foreach ( $outcome->heuristic as $finding ) {
			if ( 'decoder' === $finding['analyzer'] ) {
				$decoded = array_merge( $decoded, $finding['sig_ids'] );
			}
		}

		sort( $decoded );

		$this->assertSame( [ 'test:lit:1', 'test:lit:2', 'test:re:plain' ], $decoded );
	}
}
