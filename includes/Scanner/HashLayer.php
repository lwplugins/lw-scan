<?php
/**
 * Whole-file md5/sha256 signature matching and allowlist checks.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner;

use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Bundle\Signatures;

defined( 'ABSPATH' ) || exit;

/**
 * Runs before any content is read: hash matches apply regardless of file
 * kind or size (FileScanner §6.6 step 2), and the allowlist check (step 1)
 * short-circuits the whole file to `clean` before hashing is even needed.
 */
final class HashLayer {

	/**
	 * @param string     $md5    Lowercase hex md5 of the file.
	 * @param string     $sha256 Lowercase hex sha256 of the file.
	 * @param Signatures $s      Loaded signature set; uses the pack's `hash` map.
	 * @return array<int,MatchResult>
	 */
	public static function match( string $md5, string $sha256, Signatures $s ): array {
		$matches = [];
		$hashes  = [
			'md5'    => $md5,
			'sha256' => $sha256,
		];

		foreach ( $hashes as $kind => $hex ) {
			$sig = $s->pack()->hash_sig( $kind, $hex );

			if ( null !== $sig ) {
				$matches[] = MatchResult::from_signatures( $s, $sig, 0, '', 0 );
			}
		}

		return $matches;
	}

	/**
	 * @param string $md5  Lowercase hex md5 of the file.
	 * @param int    $size File size in bytes.
	 * @param Pack   $pack Signature pack; uses its allowlist (md5 or `md5 . 'O' . size`).
	 */
	public static function allowlisted( string $md5, int $size, Pack $pack ): bool {
		return $pack->allowlisted( $md5, $size );
	}
}
