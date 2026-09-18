<?php
/**
 * The pack-driven scanner finds exactly what the array-bundle scanner found.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Scanner\FileScanner;
use LightweightPlugins\Scan\Scanner\ScanOutcome;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;

/**
 * `tests/Fixtures/golden-matches.json` was captured from the compiled-array
 * scanner (`Bundle\Compiler` + the array-based layers) before the switch to
 * the signature pack, over the same file walk, file rows and config used
 * here, with `bundle-{lw,mini}.json` in place of the `{lw,mini}-pack.json`
 * the backend built from them. Signature matches are `[sig_id, line, tier]`;
 * heuristic findings (which carry no tier) are
 * `[comma-joined sig_ids, line, 'heuristic:<analyzer>']`. A difference means
 * the port changed behaviour: fix the scanner, never the golden file.
 */
final class GoldenMatchesTest extends MonkeyTestCase {

	/**
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		return [
			'max_file_size' => 10485760,
			'heuristics'    => true,
			'memory_limit'  => -1,
		];
	}

	public function test_every_fixture_matches_the_golden_capture(): void {
		$fixtures = dirname( __DIR__, 2 ) . '/Fixtures';
		$golden   = json_decode( (string) file_get_contents( $fixtures . '/golden-matches.json' ), true );

		$this->assertIsArray( $golden, 'golden-matches.json must decode' );

		self::write_decoder_fixture( $fixtures );

		$actual = [];

		foreach ( [ 'lw', 'mini' ] as $name ) {
			$scanner = new FileScanner( FixturePack::signatures( $name ), self::config() );

			foreach ( self::files( $fixtures ) as $relative ) {
				$path                               = $fixtures . '/' . $relative;
				$actual[ $name . '/' . $relative ] = self::rows( $scanner->scan( self::file_row( $path ), $path ) );
			}
		}

		$this->assertSame( array_keys( $golden ), array_keys( $actual ), 'the file walk must cover exactly the captured files' );

		foreach ( $golden as $key => $rows ) {
			$this->assertSame( $rows, $actual[ $key ], $key );
		}
	}

	/**
	 * The file is generated, not committed (gzdeflate output is zlib-version
	 * dependent); these are the bytes StaticDecoderTest writes.
	 */
	private static function write_decoder_fixture( string $fixtures ): void {
		$blob = base64_encode( (string) gzdeflate( '<?php eval($_POST[1]);' ) );
		$code = "<?php die('inert lw-scan fixture'); ?>\n<?php\neval( gzinflate( base64_decode( '" . $blob . "' ) ) );\n";

		file_put_contents( $fixtures . '/heuristic/decoder.php', $code );
	}

	/**
	 * @return string[] Paths relative to tests/Fixtures, sorted.
	 */
	private static function files( string $fixtures ): array {
		$files = [];

		foreach ( [ 'corpus/positives', 'corpus/clean', 'heuristic' ] as $dir ) {
			foreach ( (array) glob( $fixtures . '/' . $dir . '/*' ) as $path ) {
				$files[] = $dir . '/' . basename( (string) $path );
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function file_row( string $path ): array {
		$htaccess = '.htaccess' === substr( $path, -9 );

		return [
			'md5'    => '',
			'sha256' => '',
			'size'   => filesize( $path ),
			'kind'   => $htaccess ? 'other' : 'php',
			'path'   => $htaccess ? '.htaccess' : basename( $path ),
		];
	}

	/**
	 * @return array<int,array{0:string,1:int,2:string}>
	 */
	private static function rows( ScanOutcome $outcome ): array {
		$rows = [];

		foreach ( $outcome->matches as $match ) {
			$rows[] = [ $match->sig_id, $match->line, $match->tier ];
		}

		foreach ( $outcome->heuristic as $finding ) {
			$rows[] = [ implode( ',', $finding['sig_ids'] ), $finding['line'], 'heuristic:' . $finding['analyzer'] ];
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $a <=> $b;
			}
		);

		return $rows;
	}
}
