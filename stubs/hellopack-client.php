<?php
/**
 * Static-analysis stubs for the optional HelloPack Client status API.
 *
 * HelloPack Client is not a dependency: `HelloPack\StatusCheck` only ever
 * touches these symbols on a site that runs it, behind an
 * `interface_exists()` guard. PHPStan still has to know their shape, and
 * `interface.notFound` is non-ignorable, so the contract from HelloPack
 * Client's docs/status-checks.md is declared here instead.
 *
 * Referenced from phpstan.neon.dist's `scanFiles` only — never autoloaded,
 * never shipped (.gitattributes export-ignore + the release workflow's
 * excludes).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace HelloPack\Client\Status;

defined( 'ABSPATH' ) || exit;

/**
 * One measurement of one aspect of the site.
 */
interface StatusCheck {

	/**
	 * Stable identifier: `[a-z0-9_]{2,40}`, unique across all checks.
	 */
	public function id(): string;

	/**
	 * Short, translatable name shown in the admin.
	 */
	public function label(): string;

	/**
	 * One translatable sentence on what is measured.
	 */
	public function description(): string;

	/**
	 * Whether the check makes sense on this site.
	 */
	public function applies(): bool;

	/**
	 * Take the measurement.
	 */
	public function run(): CheckResult;
}

/**
 * Immutable outcome of a check; only the four factories can create one.
 */
final class CheckResult {

	/**
	 * Everything is fine.
	 *
	 * @param string              $summary One human-readable sentence.
	 * @param array<string, mixed> $details Machine-readable data.
	 */
	public static function ok( string $summary, array $details = [] ): self {
	}

	/**
	 * Worth attention, not broken.
	 *
	 * @param string              $summary One human-readable sentence.
	 * @param array<string, mixed> $details Machine-readable data.
	 */
	public static function warn( string $summary, array $details = [] ): self {
	}

	/**
	 * Broken or dangerous.
	 *
	 * @param string              $summary One human-readable sentence.
	 * @param array<string, mixed> $details Machine-readable data.
	 */
	public static function crit( string $summary, array $details = [] ): self {
	}

	/**
	 * Could not be measured — not a failure.
	 *
	 * @param string              $summary One human-readable sentence.
	 * @param array<string, mixed> $details Machine-readable data.
	 */
	public static function unknown( string $summary, array $details = [] ): self {
	}
}
