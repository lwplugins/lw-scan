<?php
/**
 * Streams a file in fixed-size, overlapping chunks for the scanner layers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Files can be up to a few hundred MB (the pipeline already refuses to
 * regex-scan past `max_file_size`, but hashing/heuristics still walk the
 * whole thing), so this never loads more than one chunk (plus its overlap,
 * which is already included in the chunk size) into memory at a time.
 * Consecutive chunks overlap by OVERLAP bytes so a signature straddling a
 * chunk boundary is still fully visible in at least one chunk.
 */
final class ChunkReader {

	public const CHUNK   = 1048576;
	public const OVERLAP = 65536;

	/**
	 * Absolute path to the file being read.
	 *
	 * @var string
	 */
	private string $abs_path;

	/**
	 * Bytes per chunk, including the overlap.
	 *
	 * @var int
	 */
	private int $chunk;

	/**
	 * Bytes of overlap between consecutive chunks.
	 *
	 * @var int
	 */
	private int $overlap;

	public function __construct( string $abs_path, int $chunk = self::CHUNK, int $overlap = self::OVERLAP ) {
		$this->abs_path = $abs_path;
		$this->chunk    = $chunk;
		$this->overlap  = $overlap;
	}

	/**
	 * Yields one chunk at a time, each covering bytes
	 * `[i*(chunk-overlap), i*(chunk-overlap)+chunk)` of the file. A 0-byte
	 * file yields a single empty chunk with both `first` and `last` true.
	 *
	 * @return \Generator<int, array{index:int, data:string, offset:int, first:bool, last:bool, line_base:int}>
	 */
	public function chunks(): \Generator {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem has no streaming/chunked read API; this class runs from WP-Cron/CLI and in unit tests without WordPress loaded.
		$handle = @fopen( $this->abs_path, 'rb' );

		if ( false === $handle ) {
			return;
		}

		$size = filesize( $this->abs_path );
		$size = false === $size ? 0 : $size;

		if ( 0 === $size ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
			fclose( $handle );

			yield [
				'index'     => 0,
				'data'      => '',
				'offset'    => 0,
				'first'     => true,
				'last'      => true,
				'line_base' => 0,
			];

			return;
		}

		$stride    = $this->chunk - $this->overlap;
		$index     = 0;
		$offset    = 0;
		$line_base = 0;

		while ( $offset < $size ) {
			$data = self::read_full_chunk( $handle, $this->chunk );
			$last = ( $offset + strlen( $data ) ) >= $size;

			yield [
				'index'     => $index,
				'data'      => $data,
				'offset'    => $offset,
				'first'     => 0 === $index,
				'last'      => $last,
				'line_base' => $line_base,
			];

			if ( $last ) {
				break;
			}

			$line_base += substr_count( substr( $data, 0, $stride ), "\n" );
			$offset    += $stride;
			++$index;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- see fopen note above; positions the stream for the next overlapping chunk read.
			if ( 0 !== fseek( $handle, $offset ) ) {
				break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
		fclose( $handle );
	}

	/**
	 * A single `fread()` call is not guaranteed to fill the requested
	 * length even for a plain file (pipes, some stream-wrapper overrides,
	 * and short reads near EOF all return less) — this loops until either
	 * `$want` bytes have been read or the stream is exhausted.
	 *
	 * @param resource $handle Open file handle.
	 * @param int      $want   Maximum number of bytes to read.
	 */
	private static function read_full_chunk( $handle, int $want ): string {
		$data = '';
		$have = 0;

		while ( $have < $want && ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see fopen note above; streaming reads are required so a multi-hundred-MB file is never loaded whole.
			$piece = fread( $handle, $want - $have );

			if ( false === $piece || '' === $piece ) {
				break;
			}

			$data .= $piece;
			$have += strlen( $piece );
		}

		return $data;
	}

	/**
	 * Reads the first `$bytes` bytes of a file (used for content-type sniffing).
	 *
	 * @param string $abs   Absolute file path.
	 * @param int    $bytes Number of bytes to read from the start of the file.
	 */
	public static function head( string $abs, int $bytes = 4096 ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem has no simple "read first N bytes" API; a vanished/unreadable file just yields an empty head.
		$handle = @fopen( $abs, 'rb' );

		if ( false === $handle ) {
			return '';
		}

		$data = self::read_full_chunk( $handle, $bytes );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened above.
		fclose( $handle );

		return $data;
	}

	/**
	 * Builds an ASCII-safe excerpt around a match offset: every byte outside
	 * printable ASCII (except tab and newline) becomes `?`, capped at 512
	 * bytes so a stored finding never balloons on binary-ish content.
	 *
	 * @param string $data    Chunk (or file) data the offset is relative to.
	 * @param int    $offset  Byte offset of the match within `$data`.
	 * @param int    $len     Bytes to include after the context window.
	 * @param int    $context Bytes of context to include before `$offset`.
	 */
	public static function excerpt( string $data, int $offset, int $len = 160, int $context = 80 ): string {
		$start = max( 0, $offset - $context );
		$raw   = substr( $data, $start, $len + $context );
		$safe  = preg_replace( '/[^\x09\x0A\x20-\x7E]/', '?', $raw );
		$safe  = null === $safe ? '' : $safe;

		return substr( $safe, 0, 512 );
	}
}
