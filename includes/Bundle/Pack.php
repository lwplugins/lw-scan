<?php
/**
 * Read-only view over a format-1 signature pack (spec §4.2, §5.3).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * Numeric sections stay packed as binary strings (little-endian uint32 or
 * bytes) and are unpacked per lookup: the whole 13k-rule set costs ~3 MB
 * instead of the ~25 MB the same data takes as PHP arrays. The one exception
 * is the regex literal table, see `regex_lits()`. `from_array()`
 * runs PackIntegrity once, so the accessors read without bounds checks: any
 * index below the pack's counts, or handed out by the pack itself, is safe.
 */
final class Pack {

	public const FORMAT = 1;

	private const BINARY = [ 'sig_tier', 'regex_offsets', 'regex_sigs', 'regex_flags', 'regex_lits', 'regex_lit_offsets', 'literal_offsets', 'literal_word_counts', 'literal_pure', 'wordless_literals' ];

	private const TIERS = [
		1 => 'infected',
		2 => 'suspicious',
		3 => 'info',
	];

	/**
	 * The decoded pack, binary sections already base64-decoded.
	 *
	 * @var array<string, mixed>
	 */
	private array $d;

	/**
	 * `regex_lit_offsets` and `regex_lits` as PHP int lists, decoded on the
	 * first `regex_lits()` call (see there); null until then.
	 *
	 * @var array{0:int[],1:int[]}|null
	 */
	private ?array $lit_table = null;

	/**
	 * @param array<string, mixed> $data Decoded, validated pack.
	 */
	private function __construct( array $data ) {
		$this->d = $data;
	}

	/**
	 * @param array<string, mixed> $data `json_decode( pack.json, true )`.
	 * @return self|null Null on a missing key, a mistyped header, another format, bad base64, or any
	 *                   length, offset, index, flag or row that fails PackIntegrity.
	 */
	public static function from_array( array $data ): ?self {
		if ( ! PackIntegrity::shape( $data ) ) {
			return null;
		}

		foreach ( self::BINARY as $key ) {
			$raw = is_string( $data[ $key ] ) ? base64_decode( $data[ $key ], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the pack format stores binary sections as base64 (spec §4.2).

			if ( false === $raw ) {
				return null;
			}

			$data[ $key ] = $raw;
		}

		foreach ( [ 'targets', 'words' ] as $map ) {
			foreach ( $data[ $map ] as $name => $b64 ) {
				$raw = is_string( $b64 ) ? base64_decode( $b64, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the pack format stores index lists as base64 (spec §4.2).

				if ( false === $raw || 0 !== strlen( $raw ) % 4 ) {
					return null;
				}

				$data[ $map ][ $name ] = $raw;
			}
		}

		return PackIntegrity::consistent( $data ) ? new self( $data ) : null;
	}

	private static function u32( string $bin, int $i ): int {
		$value = unpack( 'V', $bin, 4 * $i );

		return false === $value ? 0 : (int) $value[1];
	}

	/**
	 * @param string   $bin  Little-endian uint32 sequence.
	 * @param int      $from First element (inclusive).
	 * @param int|null $to   Last element (exclusive); null reads to the end.
	 * @return int[]
	 */
	private static function u32_list( string $bin, int $from = 0, ?int $to = null ): array {
		$to = null === $to ? intdiv( strlen( $bin ), 4 ) : $to;

		if ( $to <= $from ) {
			return [];
		}

		$values = unpack( 'V' . ( $to - $from ), $bin, 4 * $from );

		return false === $values ? [] : array_values( $values );
	}

	public function version(): int {
		return (int) $this->d['version'];
	}

	public function count(): int {
		return (int) $this->d['count'];
	}

	public function sig_tier( int $sig ): string {
		return self::TIERS[ ord( $this->d['sig_tier'][ $sig ] ) ] ?? 'suspicious';
	}

	public function regex_count(): int {
		return (int) $this->d['regex_count'];
	}

	public function pattern( int $i ): string {
		$from = self::u32( $this->d['regex_offsets'], $i );

		return substr( $this->d['regex_patterns'], $from, self::u32( $this->d['regex_offsets'], $i + 1 ) - $from );
	}

	public function regex_sig( int $i ): int {
		return self::u32( $this->d['regex_sigs'], $i );
	}

	/**
	 * Flags byte: bit0 = anchored, bit1 = first (evaluate before the prefilter).
	 *
	 * @param int $i Regex index.
	 */
	public function regex_first( int $i ): bool {
		return 2 === ( ord( $this->d['regex_flags'][ $i ] ) & 2 );
	}

	/**
	 * Asked for every regex of every chunk's walk, so unpacking it per call
	 * dominated the scan: the table is decoded into two PHP int lists on the
	 * first call instead (about 0.8 MB for the full rule set) and sliced
	 * after that.
	 *
	 * @param int $i Regex index.
	 * @return int[] Literal indexes the regex needs present.
	 */
	public function regex_lits( int $i ): array {
		if ( null === $this->lit_table ) {
			$this->lit_table = [ self::u32_list( $this->d['regex_lit_offsets'] ), self::u32_list( $this->d['regex_lits'] ) ];
		}

		$from = $this->lit_table[0][ $i ];
		$to   = $this->lit_table[0][ $i + 1 ];

		return $to > $from ? array_slice( $this->lit_table[1], $from, $to - $from ) : [];
	}

	/**
	 * @param string $target File target, e.g. `file_php`.
	 * @return int[] Ascending regex indexes; empty for an unknown target.
	 */
	public function target_regexes( string $target ): array {
		return isset( $this->d['targets'][ $target ] ) ? self::u32_list( $this->d['targets'][ $target ] ) : [];
	}

	public function literal_count(): int {
		return (int) $this->d['literal_count'];
	}

	public function literal( int $i ): string {
		$from = self::u32( $this->d['literal_offsets'], $i );

		return substr( $this->d['literal_strings'], $from, self::u32( $this->d['literal_offsets'], $i + 1 ) - $from );
	}

	public function literal_pure( int $i ): bool {
		return 1 === ord( $this->d['literal_pure'][ $i ] );
	}

	public function literal_word_count( int $i ): int {
		return ord( $this->d['literal_word_counts'][ $i ] );
	}

	/**
	 * A numeric-looking word ("3600") became an int key in json_decode; the
	 * string lookup below still finds it, because PHP normalises the offset.
	 *
	 * @param string $word Lowercase word from the scanned content.
	 * @return int[] Indexes of the literals containing the word.
	 */
	public function word_literals( string $word ): array {
		return isset( $this->d['words'][ $word ] ) ? self::u32_list( $this->d['words'][ $word ] ) : [];
	}

	/**
	 * @return int[]
	 */
	public function wordless_literals(): array {
		return self::u32_list( $this->d['wordless_literals'] );
	}

	/**
	 * @return array<int, array{0:int,1:int,2:string}> [literal index, sig, target] per literal rule.
	 */
	public function plain_literals(): array {
		return array_map(
			static function ( array $row ): array {
				return [ (int) $row[0], (int) $row[1], (string) $row[2] ];
			},
			$this->d['plain_literals']
		);
	}

	/**
	 * @param string $kind `md5` or `sha256`.
	 * @param string $hex  Lowercase hex digest.
	 */
	public function hash_sig( string $kind, string $hex ): ?int {
		return isset( $this->d['hash'][ $kind ][ $hex ] ) ? (int) $this->d['hash'][ $kind ][ $hex ] : null;
	}

	/**
	 * @param string $md5  Lowercase hex md5 of the file.
	 * @param int    $size File size in bytes.
	 */
	public function allowlisted( string $md5, int $size ): bool {
		return isset( $this->d['allowlist'][ $md5 ] ) || isset( $this->d['allowlist'][ $md5 . 'O' . $size ] );
	}

	/**
	 * @param string $target `db_option`, `db_post` or `db_trigger`.
	 * @return array<int, array{sig:int, re:string, like:?string}>
	 */
	public function db_rules( string $target ): array {
		$rules = [];

		foreach ( (array) ( $this->d['db'][ $target ] ?? [] ) as $row ) {
			$rules[] = [
				'sig'  => (int) $row[0],
				're'   => (string) $row[1],
				'like' => null === $row[2] ? null : (string) $row[2],
			];
		}

		return $rules;
	}
}
