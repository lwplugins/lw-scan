<?php
/**
 * Read-only view over a format-1 signature pack's meta file (spec §4.3).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * The human-facing half of the pack: rule id, name, category and kind per
 * sig, in the same sig order as `Pack`. It is only needed when a finding is
 * recorded, so it lives in its own file and is loaded lazily
 * (`Signatures::meta()`). `from_array()` accepts it only when all four lists
 * are lists of exactly `count` strings, so a sig below `count()` always has
 * an entry.
 */
final class PackMeta {

	private const LISTS = [ 'ids', 'names', 'categories', 'kinds' ];

	/**
	 * @var int
	 */
	private int $version;

	/**
	 * @var array<string, string[]> List name => one string per sig.
	 */
	private array $lists;

	/**
	 * @param int                     $version Pack version the meta belongs to.
	 * @param array<string, string[]> $lists   Validated lists keyed by LISTS.
	 */
	private function __construct( int $version, array $lists ) {
		$this->version = $version;
		$this->lists   = $lists;
	}

	/**
	 * @param array<string, mixed> $data `json_decode( meta.json, true )`.
	 * @return self|null Null on a missing or non-int header, another format or a list that does not match `count`.
	 */
	public static function from_array( array $data ): ?self {
		foreach ( [ 'format', 'version', 'count' ] as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_int( $data[ $key ] ) ) {
				return null;
			}
		}

		if ( Pack::FORMAT !== $data['format'] ) {
			return null;
		}

		$count = $data['count'];
		$lists = [];

		foreach ( self::LISTS as $key ) {
			if ( ! isset( $data[ $key ] ) || ! self::is_string_list( $data[ $key ], $count ) ) {
				return null;
			}

			$lists[ $key ] = $data[ $key ];
		}

		return new self( $data['version'], $lists );
	}

	/**
	 * @param mixed $value Candidate list.
	 * @param int   $count Required length.
	 */
	private static function is_string_list( $value, int $count ): bool {
		if ( ! is_array( $value ) || count( $value ) !== $count ) {
			return false;
		}

		$expected = 0;

		foreach ( $value as $key => $item ) {
			if ( $expected !== $key || ! is_string( $item ) ) {
				return false;
			}

			++$expected;
		}

		return true;
	}

	public function version(): int {
		return $this->version;
	}

	public function count(): int {
		return count( $this->lists['ids'] );
	}

	public function id( int $sig ): string {
		return $this->lists['ids'][ $sig ] ?? '';
	}

	public function name( int $sig ): string {
		return $this->lists['names'][ $sig ] ?? '';
	}

	public function category( int $sig ): string {
		return $this->lists['categories'][ $sig ] ?? '';
	}

	public function kind( int $sig ): string {
		return $this->lists['kinds'][ $sig ] ?? '';
	}

	/**
	 * @return string[] Rule ids in sig order.
	 */
	public function ids(): array {
		return $this->lists['ids'];
	}
}
