<?php
/**
 * Computes the deterministic hash that identifies a finding.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * A finding's identity is its type plus its locator — the same locator
 * under two different types (e.g. a file path scanned for content vs.
 * hashed against a DB row locator string) must never collide.
 */
final class Fingerprint {

	/**
	 * @param string $type    Finding type (file|integrity|db|vulnerability).
	 * @param string $locator Type-specific locator string.
	 * @return string sha256 hex digest, stored as `locator_hash`.
	 */
	public static function of( string $type, string $locator ): string {
		return hash( 'sha256', $type . '|' . $locator );
	}
}
