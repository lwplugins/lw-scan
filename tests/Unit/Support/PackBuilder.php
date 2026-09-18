<?php
/**
 * Test-only encoder: builds a format-1 pack array from readable parts, so
 * scanner tests can state rules directly instead of generating fixtures.
 * It encodes; it does not compile (patterns are given PHP-ready).
 * Unlike the Go builder it does not sort regexes by rank/length and numbers
 * literals in call order, so tests that depend on regex order must add rules
 * in the intended order.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Support;

use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Bundle\PackMeta;

final class PackBuilder {

	private const TIERS = [
		'infected'   => 1,
		'suspicious' => 2,
		'info'       => 3,
	];

	private const FILE_TARGETS = [ 'file_php', 'file_any', 'file_code', 'file_js', 'file_html', 'htaccess' ];

	/** @var array<int, array{tier:string,id:string,name:string,category:string,kind:string}> */
	private array $sigs = [];

	/** @var array<int, array{sig:int,re:string,target:string,lits:int[],first:bool}> */
	private array $regex = [];

	/** @var string[] */
	private array $literals = [];

	/**
	 * Reverse index of $literals, so a large synthetic pack builds in linear time.
	 *
	 * @var array<array-key, int>
	 */
	private array $literal_ids = [];

	/** @var array<int, array{0:int,1:int,2:string}> */
	private array $plain = [];

	/** @var array<string, array<string,int>> */
	private array $hash = [
		'md5'    => [],
		'sha256' => [],
	];

	/** @var array<string, array<int, array{0:int,1:string,2:?string}>> */
	private array $db = [
		'db_option'  => [],
		'db_post'    => [],
		'db_trigger' => [],
	];

	/** @var array<string,int> */
	private array $allow = [];

	public function sig( int $sig, string $tier = 'infected', string $kind = 'regex', string $category = 'backdoor' ): self {
		$this->sigs[ $sig ] = [
			'tier'     => $tier,
			'id'       => 'test:' . $sig,
			'name'     => 'rule ' . $sig,
			'category' => $category,
			'kind'     => $kind,
		];
		return $this;
	}

	public function literal( string $lc ): int {
		if ( isset( $this->literal_ids[ $lc ] ) ) {
			return $this->literal_ids[ $lc ];
		}
		$this->literals[]          = $lc;
		$this->literal_ids[ $lc ] = count( $this->literals ) - 1;
		return $this->literal_ids[ $lc ];
	}

	/**
	 * @param string[] $common Lowercase common strings the regex needs.
	 */
	public function regex( int $sig, string $re, string $target = 'file_php', array $common = [], bool $first = false ): self {
		$lits = [];
		foreach ( $common as $lc ) {
			$lits[] = $this->literal( $lc );
		}
		$this->regex[] = [
			'sig'    => $sig,
			're'     => $re,
			'target' => $target,
			'lits'   => $lits,
			'first'  => $first,
		];
		return $this;
	}

	public function plain_literal( int $sig, string $lc, string $target = 'file_any' ): self {
		$this->plain[] = [ $this->literal( $lc ), $sig, $target ];
		return $this;
	}

	public function hash( string $kind, string $hex, int $sig ): self {
		$this->hash[ $kind ][ $hex ] = $sig;
		return $this;
	}

	public function db( string $target, int $sig, string $re, ?string $like = null ): self {
		$this->db[ $target ][] = [ $sig, $re, $like ];
		return $this;
	}

	public function allow( string $key ): self {
		$this->allow[ $key ] = 1;
		return $this;
	}

	/** @return array<string,mixed> */
	public function pack_array(): array {
		$count = $this->sig_count();
		$tiers = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$tiers .= chr( self::TIERS[ $this->sigs[ $i ]['tier'] ?? 'infected' ] );
		}

		return array_merge(
			[
				'format'       => 1,
				'version'      => 1,
				'generated_at' => '2026-01-01T00:00:00Z',
				'count'        => $count,
				'sig_tier'     => base64_encode( $tiers ),
			],
			$this->regex_sections(),
			$this->literal_sections(),
			[
				'plain_literals' => $this->plain,
				'hash'           => $this->hash,
				'db'             => $this->db,
				'allowlist'      => $this->allow,
			]
		);
	}

	public function pack(): Pack {
		$pack = Pack::from_array( $this->pack_array() );
		if ( null === $pack ) {
			throw new \LogicException( 'PackBuilder produced an invalid pack.' );
		}
		return $pack;
	}

	public function meta(): PackMeta {
		$count = $this->sig_count();
		$data  = [
			'format'     => 1,
			'version'    => 1,
			'count'      => $count,
			'ids'        => [],
			'names'      => [],
			'categories' => [],
			'kinds'      => [],
		];
		for ( $i = 0; $i < $count; $i++ ) {
			$sig                  = $this->sigs[ $i ] ?? [
				'id'       => 'test:' . $i,
				'name'     => '',
				'category' => 'unknown',
				'kind'     => 'regex',
			];
			$data['ids'][]        = $sig['id'];
			$data['names'][]      = $sig['name'];
			$data['categories'][] = $sig['category'];
			$data['kinds'][]      = $sig['kind'];
		}
		$meta = PackMeta::from_array( $data );
		if ( null === $meta ) {
			throw new \LogicException( 'PackBuilder produced invalid meta.' );
		}
		return $meta;
	}

	private function sig_count(): int {
		return [] === $this->sigs ? 0 : max( array_keys( $this->sigs ) ) + 1;
	}

	/** @return array<string,mixed> */
	private function regex_sections(): array {
		$patterns = '';
		$offsets  = [ 0 ];
		$sigs     = [];
		$flags    = '';
		$lits     = [];
		$lit_offs = [ 0 ];
		$targets  = array_fill_keys( self::FILE_TARGETS, [] );
		foreach ( $this->regex as $index => $entry ) {
			$patterns .= $entry['re'];
			$offsets[] = strlen( $patterns );
			$sigs[]    = $entry['sig'];
			$flags    .= chr( $entry['first'] ? 3 : 0 );
			foreach ( $entry['lits'] as $lit ) {
				$lits[] = $lit;
			}
			$lit_offs[]                    = count( $lits );
			$targets[ $entry['target'] ][] = $index;
		}

		return [
			'regex_count'       => count( $this->regex ),
			'regex_patterns'    => $patterns,
			'regex_offsets'     => self::u32( $offsets ),
			'regex_sigs'        => self::u32( $sigs ),
			'regex_flags'       => base64_encode( $flags ),
			'regex_lits'        => self::u32( $lits ),
			'regex_lit_offsets' => self::u32( $lit_offs ),
			'targets'           => array_map( [ self::class, 'u32' ], $targets ),
		];
	}

	/** @return array<string,mixed> */
	private function literal_sections(): array {
		$strings  = '';
		$offsets  = [ 0 ];
		$counts   = '';
		$pure     = '';
		$words    = [];
		$wordless = [];
		foreach ( $this->literals as $index => $lc ) {
			$strings  .= $lc;
			$offsets[] = strlen( $strings );
			$split     = preg_split( '/[^a-z0-9_]+/', $lc, -1, PREG_SPLIT_NO_EMPTY );
			$ws        = array_values(
				array_unique(
					array_filter(
						(array) $split,
						static function ( string $w ): bool {
							return strlen( $w ) >= 3;
						}
					)
				)
			);
			$counts   .= chr( count( $ws ) );
			$pure     .= chr( 1 === preg_match( '/^[a-z0-9_]+$/', $lc ) ? 1 : 0 );
			if ( [] === $ws ) {
				$wordless[] = $index;
			}
			foreach ( $ws as $w ) {
				$words[ $w ][] = $index;
			}
		}

		return [
			'literal_count'       => count( $this->literals ),
			'literal_strings'     => $strings,
			'literal_offsets'     => self::u32( $offsets ),
			'literal_word_counts' => base64_encode( $counts ),
			'literal_pure'        => base64_encode( $pure ),
			'words'               => array_map( [ self::class, 'u32' ], $words ),
			'wordless_literals'   => self::u32( $wordless ),
		];
	}

	/**
	 * @param int[] $values
	 */
	private static function u32( array $values ): string {
		return base64_encode( [] === $values ? '' : pack( 'V*', ...$values ) );
	}
}
