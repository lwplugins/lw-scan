<?php
/**
 * Runs FileScanner over a small corpus of malware/clean sample fixtures.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Scanner\FileScanner;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;

/**
 * Exercises the whole per-file pipeline end to end against real (inert)
 * sample files and the backend-built `lw` signature pack instead of
 * hand-built rules: every `positives/*` fixture must produce a finding,
 * every `clean/*` fixture must not, and the one `.htaccess`-shaped fixture
 * must match through the `htaccess` target specifically (not merely
 * `file_any`).
 */
final class CorpusTest extends MonkeyTestCase {

	private Signatures $signatures;

	protected function setUp(): void {
		parent::setUp();

		$this->signatures = FixturePack::signatures( 'lw' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		return [
			'max_file_size' => 10485760,
			'heuristics'    => false,
			'memory_limit'  => -1,
		];
	}

	/**
	 * @return string[]
	 */
	private static function corpus_files( string $subdir ): array {
		$dir   = dirname( __DIR__, 2 ) . '/Fixtures/corpus/' . $subdir;
		$files = glob( $dir . '/*' );

		return false === $files ? [] : $files;
	}

	/**
	 * @dataProvider provide_positive_fixtures
	 */
	public function test_every_positive_fixture_has_findings( string $path ): void {
		$scanner  = new FileScanner( $this->signatures, self::config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => filesize( $path ),
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$this->assertTrue( $outcome->has_findings(), $path . ' must produce a finding' );
	}

	/**
	 * @dataProvider provide_clean_fixtures
	 */
	public function test_every_clean_fixture_has_no_findings( string $path ): void {
		$scanner  = new FileScanner( $this->signatures, self::config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => filesize( $path ),
			'kind'   => 'php',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$this->assertFalse( $outcome->has_findings(), $path . ' must not produce a finding' );
	}

	public function test_htaccess_fixture_matches_through_the_htaccess_target(): void {
		$path = dirname( __DIR__, 2 ) . '/Fixtures/corpus/positives/lw0014-htaccess-prepend.htaccess';

		$this->assertFileExists( $path );

		$scanner  = new FileScanner( $this->signatures, self::config() );
		$file_row = [
			'md5'    => '',
			'sha256' => '',
			'size'   => filesize( $path ),
			'kind'   => 'other',
			'path'   => '.htaccess',
		];

		$outcome = $scanner->scan( $file_row, $path );

		$sig_ids = array_map(
			static function ( $match ): string {
				return $match->sig_id;
			},
			$outcome->matches
		);

		$this->assertContains( 'lw:0014', $sig_ids, 'the .htaccess fixture must match lw:0014 via the htaccess target' );
		$this->assertTrue( $outcome->infected );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_positive_fixtures(): array {
		return self::provide_from( 'positives', [ 'lw0014-htaccess-prepend.htaccess' ] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_clean_fixtures(): array {
		return self::provide_from( 'clean', [] );
	}

	/**
	 * @param string[] $exclude Basenames to leave out (covered by a dedicated test instead).
	 * @return array<string,array{0:string}>
	 */
	private static function provide_from( string $subdir, array $exclude ): array {
		$cases = [];

		foreach ( self::corpus_files( $subdir ) as $path ) {
			$basename = basename( $path );

			if ( in_array( $basename, $exclude, true ) ) {
				continue;
			}

			$cases[ $basename ] = [ $path ];
		}

		return $cases;
	}
}
