<?php
/**
 * The regex walk for each target set, selected once per signature set.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Bundle\Signatures;

defined( 'ABSPATH' ) || exit;

/**
 * `RegexLayer::indexes_for()` unpacks, unions and sorts every regex index of
 * a target set — about a millisecond on a full pack — yet a scanner only
 * ever asks for a handful of distinct target sets (one per content kind,
 * plus the decoder rescan), each with or without the new-rules restriction.
 * This keeps each answer for the lifetime of one `FileScanner`, which is
 * bound to one `Signatures`, so a changed signature set always starts with
 * an empty memo.
 */
final class RegexWalks {

	/**
	 * @var Signatures
	 */
	private Signatures $signatures;

	/**
	 * Target set + restriction => regex indexes in walk order.
	 *
	 * @var array<string, int[]>
	 */
	private array $walks = [];

	/**
	 * @param Signatures $signatures Signature set the walks index into.
	 */
	public function __construct( Signatures $signatures ) {
		$this->signatures = $signatures;
	}

	/**
	 * @param string[] $targets  Target names, e.g. `FileScanner::targets_for()`'s result.
	 * @param bool     $only_new Restrict to regexes flagged as new.
	 * @return int[] `RegexLayer::indexes_for()`'s result for the same arguments.
	 */
	public function indexes( array $targets, bool $only_new ): array {
		$key = implode( ',', $targets ) . ( $only_new ? '|new' : '|all' );

		if ( ! isset( $this->walks[ $key ] ) ) {
			$this->walks[ $key ] = RegexLayer::indexes_for( $this->signatures, $targets, $only_new );
		}

		return $this->walks[ $key ];
	}
}
