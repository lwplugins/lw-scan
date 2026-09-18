<?php
/**
 * Tests for Scanner\ChunkReader.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner;

use LightweightPlugins\Scan\Scanner\ChunkReader;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class ChunkReaderTest extends MonkeyTestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/lw-scan-chunkreader-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) ?: [] as $file ) {
			unlink( $file );
		}

		rmdir( $this->dir );

		parent::tearDown();
	}

	public function test_large_file_yields_three_chunks_with_matching_overlap_and_correct_line_base(): void {
		$line    = str_repeat( 'x', 79 ) . "\n";
		$target  = 2_500_000;
		$content = str_repeat( $line, (int) ceil( $target / strlen( $line ) ) );
		$content = substr( $content, 0, $target );

		$path = $this->dir . '/big.php';
		file_put_contents( $path, $content );

		$reader = new ChunkReader( $path );
		$chunks = iterator_to_array( $reader->chunks() );

		$this->assertCount( 3, $chunks );

		$this->assertTrue( $chunks[0]['first'] );
		$this->assertFalse( $chunks[0]['last'] );
		$this->assertFalse( $chunks[1]['first'] );
		$this->assertFalse( $chunks[1]['last'] );
		$this->assertFalse( $chunks[2]['first'] );
		$this->assertTrue( $chunks[2]['last'] );

		foreach ( $chunks as $i => $chunk ) {
			$this->assertSame( $i, $chunk['index'] );
			$this->assertSame(
				substr_count( $content, "\n", 0, $chunk['offset'] ),
				$chunk['line_base'],
				"line_base mismatch for chunk {$i}"
			);
		}

		$overlap = ChunkReader::OVERLAP;

		$this->assertSame(
			substr( $chunks[0]['data'], -$overlap ),
			substr( $chunks[1]['data'], 0, $overlap )
		);

		$this->assertSame(
			substr( $chunks[1]['data'], -$overlap ),
			substr( $chunks[2]['data'], 0, $overlap )
		);
	}

	public function test_file_smaller_than_chunk_yields_a_single_chunk(): void {
		$path = $this->dir . '/small.php';
		file_put_contents( $path, "<?php\necho 1;\n" );

		$reader = new ChunkReader( $path );
		$chunks = iterator_to_array( $reader->chunks() );

		$this->assertCount( 1, $chunks );
		$this->assertTrue( $chunks[0]['first'] );
		$this->assertTrue( $chunks[0]['last'] );
		$this->assertSame( 0, $chunks[0]['offset'] );
		$this->assertSame( 0, $chunks[0]['line_base'] );
		$this->assertSame( "<?php\necho 1;\n", $chunks[0]['data'] );
	}

	public function test_zero_byte_file_yields_one_empty_chunk_marked_first_and_last(): void {
		$path = $this->dir . '/empty.php';
		file_put_contents( $path, '' );

		$reader = new ChunkReader( $path );
		$chunks = iterator_to_array( $reader->chunks() );

		$this->assertCount( 1, $chunks );
		$this->assertSame( '', $chunks[0]['data'] );
		$this->assertTrue( $chunks[0]['first'] );
		$this->assertTrue( $chunks[0]['last'] );
		$this->assertSame( 0, $chunks[0]['offset'] );
		$this->assertSame( 0, $chunks[0]['line_base'] );
	}

	public function test_head_reads_the_requested_number_of_bytes(): void {
		$path = $this->dir . '/head.php';
		file_put_contents( $path, str_repeat( 'A', 10000 ) );

		$head = ChunkReader::head( $path, 4096 );

		$this->assertSame( str_repeat( 'A', 4096 ), $head );
	}

	public function test_head_reads_whole_file_when_smaller_than_requested_bytes(): void {
		$path = $this->dir . '/small-head.php';
		file_put_contents( $path, 'short' );

		$this->assertSame( 'short', ChunkReader::head( $path, 4096 ) );
	}

	public function test_head_returns_empty_string_for_unreadable_path(): void {
		$this->assertSame( '', ChunkReader::head( $this->dir . '/does-not-exist.php' ) );
	}

	public function test_excerpt_replaces_non_printable_bytes_with_question_marks(): void {
		$data = "before\x00after";

		$excerpt = ChunkReader::excerpt( $data, 6, 4, 2 );

		$this->assertStringNotContainsString( "\x00", $excerpt );
		$this->assertStringContainsString( '?', $excerpt );
	}

	public function test_excerpt_keeps_tabs_and_newlines(): void {
		$data = "line one\n\tindented";

		$excerpt = ChunkReader::excerpt( $data, 9, 10, 9 );

		$this->assertStringContainsString( "\n", $excerpt );
		$this->assertStringContainsString( "\t", $excerpt );
	}

	public function test_excerpt_is_capped_at_512_bytes(): void {
		$data = str_repeat( 'A', 5000 );

		$excerpt = ChunkReader::excerpt( $data, 2500, 1000, 1000 );

		$this->assertLessThanOrEqual( 512, strlen( $excerpt ) );
	}

	public function test_excerpt_clamps_start_offset_to_zero(): void {
		$data = 'hello world';

		$excerpt = ChunkReader::excerpt( $data, 0, 5, 80 );

		$this->assertSame( 'hello world', $excerpt );
	}
}
