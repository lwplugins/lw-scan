<?php
/**
 * Tests for Run\Phase\FilesPhase.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run\Phase;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Phase\FilesPhase;
use LightweightPlugins\Scan\Run\Phases;
use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use LightweightPlugins\Scan\Tests\Unit\Support\PackBuilder;
use Mockery;

/**
 * Uses a real `Scanner\FileScanner` over the mini signature pack and real
 * files on disk — `FileScanner` is final, and the phase's whole job is
 * turning real scan outcomes into repository calls, so a scripted double
 * would test nothing. Only the repositories are mocked.
 */
final class FilesPhaseTest extends MonkeyTestCase {

	private const RUN_ID = 42;

	/** @var string ABSPATH-relative directory holding this test's fixture files. */
	private string $dir;

	private Signatures $signatures;

	/** @var FilesRepositoryInterface&\Mockery\MockInterface */
	private $files;

	/** @var FindingsRepositoryInterface&\Mockery\MockInterface */
	private $findings;

	/** @var RunsRepositoryInterface&\Mockery\MockInterface */
	private $runs;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->dir = 'wp-content/uploads/lwscan-filesphase-' . uniqid();
		mkdir( ABSPATH . $this->dir, 0755, true );

		$this->signatures = FixturePack::signatures( 'mini' );

		$this->files    = Mockery::mock( FilesRepositoryInterface::class );
		$this->findings = Mockery::mock( FindingsRepositoryInterface::class );
		$this->runs     = Mockery::mock( RunsRepositoryInterface::class );
	}

	protected function tearDown(): void {
		$this->remove_dir( ABSPATH . $this->dir );
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $entry ) {
			is_dir( $entry ) ? $this->remove_dir( $entry ) : unlink( $entry );
		}

		rmdir( $dir );
	}

	private function bundle_version(): int {
		return $this->signatures->version();
	}

	private function write( string $name, string $content ): string {
		$rel = $this->dir . '/' . $name;

		file_put_contents( ABSPATH . $rel, $content );

		return $rel;
	}

	/**
	 * @param int    $id   Row id.
	 * @param string $rel  ABSPATH-relative path.
	 * @param int    $size Reported size; defaults to the file's real size.
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $rel, int $size = -1 ): array {
		$abs = ABSPATH . $rel;

		return [
			'id'             => $id,
			'path'           => $rel,
			'size'           => -1 === $size ? (int) filesize( $abs ) : $size,
			'md5'            => is_file( $abs ) ? (string) md5_file( $abs ) : str_repeat( 'a', 32 ),
			'sha256'         => '',
			'kind'           => 'php',
			'origin'         => 'uploads',
			'known_good'     => 0,
			'path_signal'    => 60,
			'scanned_bundle' => 0,
		];
	}

	/**
	 * @param array<string, mixed> $options Option overrides for the scan config.
	 * @param string               $scope   Scan scope.
	 * @param Cursor|null          $cursor  Cursor carried over from an earlier tick; a fresh one when null.
	 */
	private function context( array $options = [], string $scope = 'changed', ?Cursor $cursor = null ): Context {
		if ( null === $cursor ) {
			$cursor = Cursor::fresh( self::RUN_ID, $scope, '', Phases::for_scope( $scope ) );
			$cursor->set_phase( 'files' );
		}

		$ctx = new Context(
			$cursor,
			new RunStats(),
			array_merge(
				[
					'max_file_size' => 2097152,
					'heuristics'    => false,
				],
				$options
			),
			$this->files,
			$this->findings,
			$this->runs,
			[
				[
					'kind'    => 'plugin',
					'slug'    => 'sample',
					'version' => '1.0.0',
					'name'    => 'Sample',
					'active'  => true,
				],
			]
		);

		$ctx->use_signatures( $this->signatures );

		return $ctx;
	}

	private static function never_due(): callable {
		return static function (): bool {
			return false;
		};
	}

	private static function always_due(): callable {
		return static function (): bool {
			return true;
		};
	}

	public function test_infected_file_is_upserted_and_a_clean_file_has_its_finding_removed(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$infected = $this->write( 'infected.php', "<?php\n// MALWARE_TOKEN follows\n" );
		$clean    = $this->write( 'clean.php', "<?php\necho 'hello world';\n" );

		$version = $this->bundle_version();

		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 0, 50, '' )->andReturn(
			[ $this->row( 11, $infected ), $this->row( 12, $clean ) ]
		);
		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 12, 50, '' )->andReturn( [] );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 11, $version );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 12, $version );

		$captured = null;
		$this->findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( $finding ) use ( &$captured ): bool {
						$captured = $finding;

						return $finding instanceof Finding;
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
		$this->findings->shouldReceive( 'delete_by_locator' )->once()->with( 'file', $clean )->andReturn( 1 );

		$ctx      = $this->context();
		$complete = ( new FilesPhase() )->run( $ctx, self::never_due() );

		$this->assertTrue( $complete );
		$this->assertSame( 2, $ctx->cursor->get( 'files_done' ) );
		$this->assertSame( 12, $ctx->cursor->get( 'file_id' ) );

		$this->assertInstanceOf( Finding::class, $captured );
		$this->assertSame( 'file', $captured->type );
		$this->assertSame( $infected, $captured->locator );
		$this->assertSame( 11, $captured->file_id );
		$this->assertSame( 'suspicious', $captured->tier );
		$this->assertSame( Severity::ALERT, $captured->severity );
		$this->assertSame( 'injector', $captured->category );
		$this->assertContains( 'test:lit:1', $captured->signature_ids );
		$this->assertSame( 60, $captured->meta['signal'] );
		$this->assertSame( [ 'php_in_uploads' ], $captured->meta['signal_reasons'] );
		$this->assertNotEmpty( $captured->meta['matches'] );
		$this->assertContains( 'test:lit:1', array_column( $captured->meta['matches'], 'sig_id' ) );
		$this->assertStringContainsString( 'path: php_in_uploads', $captured->reason );
	}

	public function test_partial_scan_stores_the_resume_token_without_marking_the_file_scanned(): void {
		$infected = $this->write( 'infected.php', "<?php\n// MALWARE_TOKEN follows\n" );

		$this->files->shouldReceive( 'queue_for_scan' )->once()->andReturn( [ $this->row( 11, $infected ) ] );
		$this->files->shouldReceive( 'mark_scanned' )->never();
		$this->findings->shouldReceive( 'upsert' )->never();
		$this->findings->shouldReceive( 'delete_by_locator' )->never();

		$ctx      = $this->context();
		$complete = ( new FilesPhase() )->run( $ctx, self::always_due() );

		$this->assertFalse( $complete );
		$this->assertNotEmpty( $ctx->cursor->get( 'resume' ) );
		$this->assertNull( $ctx->cursor->get( 'file_id' ) );
		$this->assertSame( 0, $ctx->cursor->get( 'files_done' ) );
	}

	public function test_stored_resume_token_is_ignored_for_a_different_file(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$infected = $this->write( 'infected.php', "<?php\n// MALWARE_TOKEN follows\n" );
		$version  = $this->bundle_version();

		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 0, 50, '' )->andReturn(
			[ $this->row( 11, $infected ) ]
		);
		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 11, 50, '' )->andReturn( [] );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 11, $version );

		$captured = null;
		$this->findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( $finding ) use ( &$captured ): bool {
						$captured = $finding;

						return $finding instanceof Finding;
					}
				)
			)
			->andReturn(
				[
					'id'      => 3,
					'created' => true,
					'changed' => true,
				]
			);

		$ctx = $this->context();

		// A token left behind by a row that is no longer in the queue.
		$ctx->cursor->set( 'resume_file_id', 99 );
		$ctx->cursor->set(
			'resume',
			[
				'chunk'     => 0,
				'regex'     => 0,
				'infected'  => true,
				'errors'    => 0,
				'matches'   => [
					[
						'sig_index' => 0,
						'sig_id'    => 'test:md5:1',
						'tier'      => 'infected',
						'category'  => 'unknown',
						'name'      => 'stale match from another file',
						'line'      => 1,
						'excerpt'   => '',
						'offset'    => 0,
					],
				],
				'heuristic' => [],
				'prev'      => [
					'offset' => 0,
					'sigs'   => [],
				],
			]
		);

		( new FilesPhase() )->run( $ctx, self::never_due() );

		$this->assertInstanceOf( Finding::class, $captured );
		$this->assertNotContains( 'test:md5:1', $captured->signature_ids );
		$this->assertSame( 'suspicious', $captured->tier );
	}

	public function test_oversized_php_file_keeps_an_infected_hash_match(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$big     = $this->write( 'big-known-bad.php', "<?php\n// too big to read\n" );
		$version = $this->bundle_version();

		$row        = $this->row( 31, $big, 5000000 );
		$row['md5'] = 'aabbccddeeff00112233445566778899';

		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 0, 50, '' )->andReturn( [ $row ] );
		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 31, 50, '' )->andReturn( [] );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 31, $version );

		$captured = null;
		$this->findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( $finding ) use ( &$captured ): bool {
						$captured = $finding;

						return $finding instanceof Finding;
					}
				)
			)
			->andReturn(
				[
					'id'      => 4,
					'created' => true,
					'changed' => true,
				]
			);

		$ctx = $this->context( [ 'max_file_size' => 1024 ] );

		$this->assertTrue( ( new FilesPhase() )->run( $ctx, self::never_due() ) );

		$this->assertInstanceOf( Finding::class, $captured );
		$this->assertSame( 'infected', $captured->tier );
		$this->assertContains( 'test:md5:1', $captured->signature_ids );
		$this->assertContains( 'skip:large_php', $captured->signature_ids );
	}

	public function test_oversized_php_file_produces_the_large_php_review_finding(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$big     = $this->write( 'big.php', "<?php\n// nothing interesting\n" );
		$version = $this->bundle_version();

		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 0, 50, '' )->andReturn(
			[ $this->row( 21, $big, 5000000 ) ]
		);
		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 21, 50, '' )->andReturn( [] );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 21, $version );

		$captured = null;
		$this->findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( $finding ) use ( &$captured ): bool {
						$captured = $finding;

						return $finding instanceof Finding;
					}
				)
			)
			->andReturn(
				[
					'id'      => 2,
					'created' => true,
					'changed' => true,
				]
			);

		$ctx = $this->context( [ 'max_file_size' => 1024 ] );

		$this->assertTrue( ( new FilesPhase() )->run( $ctx, self::never_due() ) );

		$this->assertInstanceOf( Finding::class, $captured );
		$this->assertSame( [ 'skip:large_php' ], $captured->signature_ids );
		$this->assertSame( 'suspicious', $captured->tier );
		$this->assertSame( 'unknown', $captured->category );
		$this->assertSame( 'PHP file larger than the scan limit; not inspected', $captured->reason );
		// The file was never inspected, so however loud its path signals are
		// (60 here) it can only ever be a review item, never an alert.
		$this->assertSame( Severity::REVIEW, $captured->severity );
	}

	public function test_an_oversized_archive_in_uploads_produces_no_finding(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$zip     = $this->write( 'backup.zip', "PK\x03\x04" . str_repeat( 'x', 100 ) . '<?php echo 1;' );
		$version = $this->bundle_version();

		$row         = $this->row( 31, $zip, 5000000 );
		$row['kind'] = 'archive';

		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 0, 50, '' )->andReturn( [ $row ] );
		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 31, 50, '' )->andReturn( [] );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 31, $version );

		$this->findings->shouldNotReceive( 'upsert' );
		$this->findings->shouldReceive( 'delete_by_locator' )->once()->with( 'file', $zip );

		$ctx = $this->context( [ 'max_file_size' => 1024 ] );

		$this->assertTrue( ( new FilesPhase() )->run( $ctx, self::never_due() ) );
	}

	public function test_a_file_scanned_at_an_earlier_version_is_checked_only_against_the_new_signatures(): void {
		$captured = $this->rescan_previously_scanned( "<?php\n// MALWARE_TOKEN follows\n", self::new_set( [], [ 1 ] ) );

		$this->assertSame( [ 'test:lit:2' ], $captured->signature_ids, 'only the rule new in this pack may run on a file an earlier pack already cleared' );
	}

	public function test_a_full_scan_checks_every_signature_even_on_a_previously_scanned_file(): void {
		$captured = $this->rescan_previously_scanned( "<?php\n// MALWARE_TOKEN follows\n", self::new_set( [], [ 1 ] ), 1, 'full' );

		$this->assertContains( 'test:lit:1', $captured->signature_ids );
		$this->assertContains( 'test:lit:2', $captured->signature_ids );
	}

	/**
	 * @dataProvider provide_new_sets_the_file_does_not_match
	 *
	 * @param array<string, mixed> $new New-signature set, as new-<v>.json holds it.
	 */
	public function test_a_file_with_a_finding_keeps_it_when_no_new_rule_matches( array $new ): void {
		// The file's finding came from an old rule. A scan restricted to the
		// new rules would find nothing and delete it; the file must be
		// rescanned against every rule instead.
		$captured = $this->rescan_previously_scanned( "<?php\nmalicious_marker_7\n", NewSignatures::from_array( $new ), 1, 'changed', true );

		$this->assertSame( [ 'test:re:plain' ], $captured->signature_ids );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function provide_new_sets_the_file_does_not_match(): array {
		return [
			'hash rules only'    => [ self::new_data( [], [], true ) ],
			'literal rules only' => [ self::new_data( [], [ 1 ] ) ],
		];
	}

	public function test_a_file_with_a_finding_keeps_its_old_matches_next_to_a_new_one(): void {
		$captured = $this->rescan_previously_scanned( "<?php\nmalicious_marker_7\n// MALWARE_TOKEN\n", self::new_set( [], [ 1 ] ), 1, 'changed', true );

		$this->assertContains( 'test:re:plain', $captured->signature_ids, 'the old rule that produced the finding must still be reported' );
		$this->assertContains( 'test:lit:2', $captured->signature_ids, 'the new rule must be reported too' );
	}

	public function test_a_file_last_scanned_before_the_new_rules_base_version_gets_every_rule(): void {
		// Left at v1 by an interrupted run; the pack is now v3 and its new
		// set is relative to v2, so v2's own rules never ran on this file.
		$captured = $this->rescan_previously_scanned( "<?php\n// MALWARE_TOKEN follows\n", self::new_set( [], [ 1 ], false, 2 ), 1 );

		$this->assertContains( 'test:lit:1', $captured->signature_ids );
		$this->assertContains( 'test:lit:2', $captured->signature_ids );
	}

	public function test_a_downgraded_pack_scans_every_rule_instead_of_only_the_new_set(): void {
		// The backend withdrew a bad pack and re-served an older one:
		// PackFetcher wrote new-<v>.json with since >= version (the pack it
		// downgraded from), which NewSignatures::from_array rejects outright
		// — the row, already stamped above the now-current version by the
		// withdrawn pack, must get every rule, not a restricted "new" set.
		$since_equals_version = self::new_set( [], [ 1 ], false, 20260101001 );

		$this->assertTrue( $since_equals_version->is_empty(), 'fixture precondition: an inverted since must read as none()' );

		$captured = $this->rescan_previously_scanned( "<?php\n// MALWARE_TOKEN follows\n", $since_equals_version, 20260101002 );

		$this->assertContains( 'test:lit:1', $captured->signature_ids );
		$this->assertContains( 'test:lit:2', $captured->signature_ids );
	}

	public function test_a_resumed_file_keeps_the_rule_set_its_interrupted_scan_started_with(): void {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// 60 regex rules, so a deadline can cut the walk at position 50; the
		// file matches rule 10 (old) and rule 55 (new).
		$builder = new PackBuilder();

		for ( $i = 0; $i < 60; $i++ ) {
			$builder->sig( $i, 'suspicious', 'regex', 'unknown' )->regex( $i, '/zzrule' . $i . 'zz/' );
		}

		$meta             = $builder->meta();
		$this->signatures = new Signatures(
			$builder->pack(),
			static function () use ( $meta ): PackMeta {
				return $meta;
			},
			self::new_set( [ 55 ] )
		);

		$rel                   = $this->write( 'resumed.php', "<?php\n// zzrule10zz zzrule55zz\n" );
		$row                   = $this->row( 51, $rel );
		$row['scanned_bundle'] = 1;
		$version               = $this->bundle_version();

		$this->files->shouldReceive( 'queue_for_scan' )->with( $version, 0, 50, '' )->andReturn( [ $row ] );
		$this->files->shouldReceive( 'queue_for_scan' )->with( $version, 51, 50, '' )->andReturn( [] );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 51, $version );

		// Tick 1: the file has a finding, so it is walked against every rule
		// and cut at position 50. Tick 2: the finding is gone (cleared in
		// between); the resume position still points into the full walk.
		$this->findings->shouldReceive( 'existing_locators' )->andReturn( [ $rel ], [] );

		$captured = $this->capture_upsert();
		$cursor   = Cursor::fresh( self::RUN_ID, 'changed', '', Phases::for_scope( 'changed' ) );
		$calls    = 0;

		$cursor->set_phase( 'files' );
		$deadline = static function () use ( &$calls ): bool {
			return ++$calls >= 2;
		};

		$this->assertFalse( ( new FilesPhase() )->run( $this->context( [], 'changed', $cursor ), $deadline ) );
		$this->assertSame( 51, $cursor->get( 'resume_file_id' ), 'fixture precondition: tick 1 left the file mid-walk' );

		$this->assertTrue( ( new FilesPhase() )->run( $this->context( [], 'changed', $cursor ), self::never_due() ) );

		$this->assertSame( [ 'test:10', 'test:55' ], $captured()->signature_ids );
	}

	/**
	 * @param int[] $regex   New regex indexes.
	 * @param int[] $literal New plain-literal positions.
	 * @param bool  $hash    Whether a hash rule is new.
	 * @param int   $since   Version the rules are new relative to.
	 * @return array<string, mixed>
	 */
	private static function new_data( array $regex = [], array $literal = [], bool $hash = false, int $since = 1 ): array {
		return [
			'version' => 20260101001,
			'since'   => $since,
			'regex'   => $regex,
			'literal' => $literal,
			'hash'    => $hash,
		];
	}

	/**
	 * @param int[] $regex   New regex indexes.
	 * @param int[] $literal New plain-literal positions (mini: 1 is test:lit:2).
	 * @param bool  $hash    Whether a hash rule is new.
	 * @param int   $since   Version the rules are new relative to.
	 */
	private static function new_set( array $regex = [], array $literal = [], bool $hash = false, int $since = 1 ): NewSignatures {
		return NewSignatures::from_array( self::new_data( $regex, $literal, $hash, $since ) );
	}

	/**
	 * Expects exactly one upsert and hands back a getter for the finding.
	 *
	 * @return callable(): Finding
	 */
	private function capture_upsert(): callable {
		$captured = null;

		$this->findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					static function ( $finding ) use ( &$captured ): bool {
						$captured = $finding;

						return $finding instanceof Finding;
					}
				)
			)
			->andReturn(
				[
					'id'      => 5,
					'created' => true,
					'changed' => true,
				]
			);
		$this->findings->shouldReceive( 'delete_by_locator' )->never();

		return function () use ( &$captured ): Finding {
			$this->assertInstanceOf( Finding::class, $captured );

			return $captured;
		};
	}

	/**
	 * Runs the phase over one file last scanned at `$scanned_bundle`, against
	 * the mini pack with `$new` as its new-signature set, and returns the
	 * finding it upserts.
	 *
	 * @param string        $content        File content.
	 * @param NewSignatures $new            The pack's new-signature set.
	 * @param int           $scanned_bundle Version the file was last scanned at.
	 * @param string        $scope          Scan scope.
	 * @param bool          $has_finding    Whether the findings table already holds a finding for the file.
	 */
	private function rescan_previously_scanned( string $content, NewSignatures $new, int $scanned_bundle = 1, string $scope = 'changed', bool $has_finding = false ): Finding {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$plain = $this->signatures->pack()->plain_literals();

		$this->assertSame( 'test:lit:2', $this->signatures->meta()->id( $plain[1][1] ), 'fixture precondition: plain literal 1 is test:lit:2' );

		$this->signatures = FixturePack::signatures( 'mini', $new );

		$rel                   = $this->write( 'previously-scanned.php', $content );
		$version               = $this->bundle_version();
		$row                   = $this->row( 41, $rel );
		$row['scanned_bundle'] = $scanned_bundle;

		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 0, 50, '' )->andReturn( [ $row ] );
		$this->files->shouldReceive( 'queue_for_scan' )->once()->with( $version, 41, 50, '' )->andReturn( [] );
		$this->files->shouldReceive( 'mark_scanned' )->once()->with( 41, $version );
		$this->findings->shouldReceive( 'existing_locators' )->andReturnUsing(
			static function ( string $type, array $locators ) use ( $has_finding ): array {
				return 'file' === $type && $has_finding ? $locators : [];
			}
		);

		$captured = $this->capture_upsert();

		$this->assertTrue( ( new FilesPhase() )->run( $this->context( [], $scope ), self::never_due() ) );

		return $captured();
	}
}
