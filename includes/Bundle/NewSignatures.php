<?php
/**
 * The rules a pack added since the previous one (spec §5.1 `new-<v>.json`).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

defined( 'ABSPATH' ) || exit;

/**
 * A "changed files + new rules" scan only evaluates the rules that were not
 * in the previously stored pack. This holds them as the pack addresses them:
 * regex indexes, positions in `Pack::plain_literals()`, and one flag for
 * "a hash rule is new" (hash lookups are a single map read, so they are not
 * worth tracking one by one). Membership sets are stored flipped, ascending.
 * `since()` is the pack version the rules are new relative to: only a file
 * last scanned at that version or later has already seen every other rule.
 * Anything malformed — including a `since` that is not older than
 * `version` — reads as `none()`, the same as a missing file.
 */
final class NewSignatures {

	/**
	 * @var array<int, true>
	 */
	private array $regex;

	/**
	 * @var array<int, true>
	 */
	private array $literal;

	/**
	 * @var bool
	 */
	private bool $hash;

	/**
	 * @var int
	 */
	private int $since;

	/**
	 * @param array<int, true> $regex   New regex indexes.
	 * @param array<int, true> $literal New plain-literal positions.
	 * @param bool             $hash    Whether any hash rule is new.
	 * @param int              $since   Pack version the rules are new relative to (0 for none).
	 */
	private function __construct( array $regex, array $literal, bool $hash, int $since ) {
		$this->regex   = $regex;
		$this->literal = $literal;
		$this->hash    = $hash;
		$this->since   = $since;
	}

	public static function none(): self {
		return new self( [], [], false, 0 );
	}

	/**
	 * @param array<string, mixed> $data `{version, since, regex: int[], literal: int[], hash: bool}`.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['version'], $data['since'], $data['regex'], $data['literal'], $data['hash'] )
			|| ! is_int( $data['version'] ) || ! is_int( $data['since'] ) || ! is_bool( $data['hash'] )
			|| $data['since'] < 0 || $data['since'] >= $data['version'] ) {
			return self::none();
		}

		$regex   = self::index_set( $data['regex'] );
		$literal = self::index_set( $data['literal'] );

		if ( null === $regex || null === $literal ) {
			return self::none();
		}

		return new self( $regex, $literal, $data['hash'], $data['since'] );
	}

	/**
	 * @param mixed $list Candidate list of non-negative ints.
	 * @return array<int, true>|null Ascending set, or null when malformed.
	 */
	private static function index_set( $list ): ?array {
		if ( ! is_array( $list ) ) {
			return null;
		}

		$set = [];

		foreach ( $list as $index ) {
			if ( ! is_int( $index ) || $index < 0 ) {
				return null;
			}

			$set[ $index ] = true;
		}

		ksort( $set );

		return $set;
	}

	public function is_empty(): bool {
		return [] === $this->regex && [] === $this->literal && ! $this->hash;
	}

	public function has_regex( int $i ): bool {
		return isset( $this->regex[ $i ] );
	}

	/**
	 * @param int $position Position in `Pack::plain_literals()`.
	 */
	public function has_literal( int $position ): bool {
		return isset( $this->literal[ $position ] );
	}

	public function hash(): bool {
		return $this->hash;
	}

	public function since(): int {
		return $this->since;
	}

	/**
	 * @return int[] Ascending.
	 */
	public function regex_indexes(): array {
		return array_keys( $this->regex );
	}

	/**
	 * @param int $version Pack version these rules are new in.
	 * @param int $since   Pack version they are new relative to.
	 * @return array{version:int, since:int, regex:int[], literal:int[], hash:bool}
	 */
	public function to_array( int $version, int $since ): array {
		return [
			'version' => $version,
			'since'   => $since,
			'regex'   => array_keys( $this->regex ),
			'literal' => array_keys( $this->literal ),
			'hash'    => $this->hash,
		];
	}
}
