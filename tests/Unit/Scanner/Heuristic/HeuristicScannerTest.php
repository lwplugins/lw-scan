<?php
/**
 * Tests for Scanner\Heuristic\HeuristicScanner.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner\Heuristic;

use LightweightPlugins\Scan\Scanner\Heuristic\HeuristicScanner;
use LightweightPlugins\Scan\Scanner\MatchResult;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class HeuristicScannerTest extends MonkeyTestCase {

	private function scanner(): HeuristicScanner {
		return new HeuristicScanner(
			static function ( string $decoded ): array {
				if ( false === strpos( $decoded, 'eval($_POST' ) ) {
					return [];
				}

				return [ new MatchResult( 0, 'test:sig:1', 'infected', 'backdoor', 'Eval on POST', 1, 'excerpt', 0 ) ];
			}
		);
	}

	private function fixture( string $name ): string {
		$path = dirname( __DIR__, 3 ) . '/Fixtures/heuristic/' . $name;
		$code = file_get_contents( $path );

		$this->assertIsString( $code, $name . ' must be readable' );

		return (string) $code;
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function row( array $overrides = [] ): array {
		return array_merge(
			[
				'kind'       => 'php',
				'known_good' => 0,
				'origin'     => 'plugin:x',
				'size'       => 1024,
			],
			$overrides
		);
	}

	public function test_every_finding_has_the_documented_shape(): void {
		$findings = $this->scanner()->analyze( $this->fixture( 'source-sink.php' ), $this->row() );

		$this->assertNotSame( [], $findings );

		foreach ( $findings as $finding ) {
			$this->assertSame(
				[ 'analyzer', 'category', 'reason', 'line', 'excerpt', 'sig_ids' ],
				array_keys( $finding )
			);
			$this->assertIsString( $finding['analyzer'] );
			$this->assertIsString( $finding['category'] );
			$this->assertIsString( $finding['reason'] );
			$this->assertIsInt( $finding['line'] );
			$this->assertIsString( $finding['excerpt'] );
			$this->assertIsArray( $finding['sig_ids'] );
		}
	}

	public function test_source_sink_findings_are_categorised_as_backdoor(): void {
		$findings = $this->scanner()->analyze( $this->fixture( 'source-sink.php' ), $this->row() );
		$first    = $findings[0];

		$this->assertSame( 'source_sink', $first['analyzer'] );
		$this->assertSame( 'backdoor', $first['category'] );
		$this->assertSame( [ 'heur:source_sink' ], $first['sig_ids'] );
		$this->assertSame( "\$_GET['path'] → unlink() (line 13)", $first['reason'] );
	}

	public function test_the_excerpt_is_the_trimmed_source_line_of_the_finding(): void {
		$findings = $this->scanner()->analyze( $this->fixture( 'source-sink.php' ), $this->row() );

		$this->assertSame( 'unlink( $p );', $findings[0]['excerpt'] );
	}

	public function test_the_excerpt_is_capped_at_160_characters(): void {
		$code     = "<?php\n\$p = \$_GET['p'];\nunlink( \$p ); // " . str_repeat( 'x', 400 ) . "\n";
		$findings = $this->scanner()->analyze( $code, $this->row() );

		$this->assertCount( 1, $findings );
		$this->assertSame( 160, strlen( $findings[0]['excerpt'] ) );
	}

	public function test_entropy_findings_are_categorised_as_obfuscation(): void {
		$findings = $this->scanner()->analyze( $this->fixture( 'entropy.php' ), $this->row() );
		$entropy  = array_values(
			array_filter(
				$findings,
				static function ( array $finding ): bool {
					return 'entropy' === $finding['analyzer'];
				}
			)
		);

		$this->assertCount( 1, $entropy );
		$this->assertSame( 'obfuscation', $entropy[0]['category'] );
		$this->assertSame( [ 'heur:entropy' ], $entropy[0]['sig_ids'] );
	}

	public function test_disguise_findings_are_categorised_as_dropper(): void {
		$findings = $this->scanner()->analyze( $this->fixture( 'disguise-uploads.php' ), $this->row( [ 'origin' => 'uploads' ] ) );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'disguise', $findings[0]['analyzer'] );
		$this->assertSame( 'dropper', $findings[0]['category'] );
		$this->assertSame( [ 'heur:disguise' ], $findings[0]['sig_ids'] );
	}

	public function test_decoder_findings_keep_the_signature_category_and_ids(): void {
		$blob     = base64_encode( (string) gzdeflate( '<?php eval($_POST[1]);' ) );
		$code     = "<?php\neval( gzinflate( base64_decode( '" . $blob . "' ) ) );\n";
		$findings = $this->scanner()->analyze( $code, $this->row() );

		$decoder = array_values(
			array_filter(
				$findings,
				static function ( array $finding ): bool {
					return 'decoder' === $finding['analyzer'];
				}
			)
		);

		$this->assertCount( 1, $decoder );
		$this->assertSame( 'backdoor', $decoder[0]['category'] );
		$this->assertSame( [ 'test:sig:1' ], $decoder[0]['sig_ids'] );
	}

	public function test_an_ordinary_plugin_file_produces_no_findings_at_all(): void {
		$this->assertSame(
			[],
			$this->scanner()->analyze( $this->fixture( 'clean-plugin.php' ), $this->row( [ 'origin' => 'plugin:example' ] ) )
		);
	}

	public function test_a_known_good_mu_plugin_row_suppresses_the_mu_disguise_signal(): void {
		$code = "<?php\n/* Plugin Name: Loader */\n\$x = 1;\n";

		$this->assertSame( [], $this->scanner()->analyze( $code, $this->row( [ 'origin' => 'mu', 'known_good' => 1 ] ) ) );
	}

	public function test_unparsable_content_yields_no_findings(): void {
		$this->assertSame( [], $this->scanner()->analyze( '', $this->row() ) );
	}
}
