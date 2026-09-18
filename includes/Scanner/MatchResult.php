<?php
/**
 * A single signature hit found by one of the scanner layers.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Bundle\Signatures;

defined( 'ABSPATH' ) || exit;

/**
 * Plain value object shared by HashLayer, LiteralLayer and RegexLayer: one
 * hit against one signature. `from_signatures()` is the one place the file
 * layers turn a pack sig index into a match (tier from the pack, id, name
 * and category from its meta), so the layers never read either directly.
 */
final class MatchResult {

	/**
	 * Sig index in the signature pack.
	 *
	 * @var int
	 */
	public int $sig_index;

	/**
	 * Signature id, e.g. `ct:4711`.
	 *
	 * @var string
	 */
	public string $sig_id;

	/**
	 * `infected` or `suspicious`.
	 *
	 * @var string
	 */
	public string $tier;

	/**
	 * Signature category, e.g. `obfuscation`.
	 *
	 * @var string
	 */
	public string $category;

	/**
	 * Human-readable signature name.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * 1-based line number, or 0 when not applicable (hash matches).
	 *
	 * @var int
	 */
	public int $line;

	/**
	 * ASCII-safe excerpt around the match, or '' when not applicable.
	 *
	 * @var string
	 */
	public string $excerpt;

	/**
	 * Byte offset of the match within the chunk that was searched.
	 *
	 * @var int
	 */
	public int $offset;

	public function __construct(
		int $sig_index,
		string $sig_id,
		string $tier,
		string $category,
		string $name,
		int $line,
		string $excerpt,
		int $offset
	) {
		$this->sig_index = $sig_index;
		$this->sig_id    = $sig_id;
		$this->tier      = $tier;
		$this->category  = $category;
		$this->name      = $name;
		$this->line      = $line;
		$this->excerpt   = $excerpt;
		$this->offset    = $offset;
	}

	/**
	 * @param Signatures $s       Loaded signature set; its meta is read here.
	 * @param int        $sig     Sig index in the pack.
	 * @param int        $line    1-based line number, or 0 when not applicable (hash matches).
	 * @param string     $excerpt ASCII-safe excerpt around the match, or '' when not applicable.
	 * @param int        $offset  Byte offset of the match within the chunk that was searched.
	 * @throws \RuntimeException `bundle_missing` when the pack's meta cannot be loaded.
	 */
	public static function from_signatures( Signatures $s, int $sig, int $line, string $excerpt, int $offset ): MatchResult {
		$meta = $s->meta();

		return new self( $sig, $meta->id( $sig ), $s->pack()->sig_tier( $sig ), $meta->category( $sig ), $meta->name( $sig ), $line, $excerpt, $offset );
	}

	/**
	 * Rebuilds a MatchResult from `to_array()`'s shape (or a resume-token
	 * match row, which is the same shape plus an extra `abs_offset` key that
	 * this simply ignores) — the counterpart `ScanOutcome::from_resume()`
	 * uses to rehydrate matches carried across a scan tick.
	 *
	 * @param array{sig_index?:int,sig_id?:string,tier?:string,category?:string,name?:string,line?:int,excerpt?:string,offset?:int} $row `to_array()`'s output (or a resume-token match row).
	 */
	public static function from_array( array $row ): MatchResult {
		return new self(
			isset( $row['sig_index'] ) ? (int) $row['sig_index'] : -1,
			isset( $row['sig_id'] ) ? (string) $row['sig_id'] : '',
			isset( $row['tier'] ) ? (string) $row['tier'] : '',
			isset( $row['category'] ) ? (string) $row['category'] : '',
			isset( $row['name'] ) ? (string) $row['name'] : '',
			isset( $row['line'] ) ? (int) $row['line'] : 0,
			isset( $row['excerpt'] ) ? (string) $row['excerpt'] : '',
			isset( $row['offset'] ) ? (int) $row['offset'] : 0
		);
	}

	/**
	 * @return array{sig_index:int,sig_id:string,tier:string,category:string,name:string,line:int,excerpt:string,offset:int}
	 */
	public function to_array(): array {
		return [
			'sig_index' => $this->sig_index,
			'sig_id'    => $this->sig_id,
			'tier'      => $this->tier,
			'category'  => $this->category,
			'name'      => $this->name,
			'line'      => $this->line,
			'excerpt'   => $this->excerpt,
			'offset'    => $this->offset,
		];
	}
}
