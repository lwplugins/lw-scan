<?php
/**
 * The status endpoint's report, in HelloPack Client's wire format.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Status;

use LightweightPlugins\Scan\HelloPack\StatusCheck;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes exactly what the `lw_scan` HelloPack status check measures —
 * `HelloPack\StatusCheck::measure()`, one check, nothing added — so a site
 * without HelloPack Client can be monitored by the same service and read
 * the same way:
 *
 *     { overall, checked_at, cached, site: {…}, checks: { lw_scan: {…} } }
 *
 * The measurement is reused for the configured TTL from a transient;
 * `wire( true )` bypasses and refreshes it. What is cached is the
 * measurement and its time, not the envelope: the `site` block is built on
 * every answer, as HelloPack Client does.
 *
 * A measurement that throws is published as `unknown` naming only the
 * exception class — its message may carry a filesystem path, and this
 * report sits behind nothing but a URL key. Even the class name is cut:
 * for an anonymous class `get_class()` appends a NUL byte and the absolute
 * path of the file that declared it.
 */
final class StatusReport {

	public const TRANSIENT = 'lw_scan_status_report';

	/** The levels the wire format knows; anything else is published as `unknown`. */
	private const LEVELS = [ 'ok', 'warn', 'crit', 'unknown' ];

	/** @var EndpointSettings Supplies the TTL. */
	private EndpointSettings $settings;

	/** @var callable(): array<string, mixed> */
	private $measure;

	/**
	 * @param EndpointSettings $settings Endpoint settings (for the TTL).
	 * @param callable|null    $measure  Returns `{level, summary, details}`; defaults to `StatusCheck::measure()`.
	 */
	public function __construct( EndpointSettings $settings, ?callable $measure = null ) {
		$this->settings = $settings;
		$this->measure  = $measure ?? [ StatusCheck::class, 'measure' ];
	}

	/**
	 * The report as the endpoint publishes it.
	 *
	 * @param bool $fresh Ignore (and replace) the cached measurement.
	 * @return array<string, mixed>
	 */
	public function wire( bool $fresh = false ): array {
		$snapshot = $fresh ? null : $this->cached();

		if ( null !== $snapshot ) {
			return self::envelope( $snapshot, true );
		}

		$snapshot = $this->run();
		set_transient( self::TRANSIENT, $snapshot, $this->settings->cache_ttl() );

		return self::envelope( $snapshot, false );
	}

	/**
	 * Drops the cached measurement.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The stored measurement while it is younger than the current TTL — a
	 * TTL lowered after it was stored still applies — else null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function cached(): ?array {
		$stored = get_transient( self::TRANSIENT );

		if ( ! is_array( $stored ) || ! isset( $stored['checked_at'], $stored['result'] ) || ! is_array( $stored['result'] ) ) {
			return null;
		}

		$age = time() - (int) $stored['checked_at'];

		return $age >= 0 && $age < $this->settings->cache_ttl() ? $stored : null;
	}

	/**
	 * Takes one measurement.
	 *
	 * @return array{checked_at: int, result: array<string, mixed>}
	 */
	private function run(): array {
		$now   = time();
		$start = microtime( true );

		try {
			$verdict = (array) call_user_func( $this->measure );
		} catch ( Throwable $e ) {
			$verdict = self::failed( $e );
		}

		$level = (string) ( $verdict['level'] ?? '' );

		return [
			'checked_at' => $now,
			'result'     => [
				'status'      => in_array( $level, self::LEVELS, true ) ? $level : 'unknown',
				'summary'     => (string) ( $verdict['summary'] ?? '' ),
				'details'     => (array) ( $verdict['details'] ?? [] ),
				'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			],
		];
	}

	/**
	 * @param Throwable $e What the measurement threw.
	 * @return array{level: string, summary: string, details: array<string, mixed>}
	 */
	private static function failed( Throwable $e ): array {
		$class = self::class_name( $e );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-mode only; the message stays in the server log and never reaches the published report.
			error_log( sprintf( 'lw-scan status endpoint: %s: %s', $class, $e->getMessage() ) );
		}

		return [
			'level'   => 'unknown',
			/* translators: %s: exception class name. */
			'summary' => sprintf( __( 'Check failed (%s).', 'lw-scan' ), $class ),
			'details' => [],
		];
	}

	/**
	 * The exception's class name, cut at the NUL byte an anonymous class
	 * carries before its file path ("RuntimeException@anonymous").
	 *
	 * @param Throwable $e Exception.
	 */
	private static function class_name( Throwable $e ): string {
		return explode( "\0", get_class( $e ), 2 )[0];
	}

	/**
	 * @param array<string, mixed> $snapshot Stored or fresh measurement.
	 * @param bool                 $cached   Served from the transient.
	 * @return array<string, mixed>
	 */
	private static function envelope( array $snapshot, bool $cached ): array {
		$result     = (array) $snapshot['result'];
		$checked_at = gmdate( 'c', (int) $snapshot['checked_at'] );
		$status     = (string) ( $result['status'] ?? 'unknown' );

		return [
			'overall'    => $status,
			'checked_at' => $checked_at,
			'cached'     => $cached,
			'site'       => [
				'url'     => home_url(),
				'wp'      => get_bloginfo( 'version' ),
				'php'     => PHP_VERSION,
				'lw_scan' => LW_SCAN_VERSION,
			],
			'checks'     => [
				StatusCheck::CHECK_ID => [
					'status'      => $status,
					'summary'     => (string) ( $result['summary'] ?? '' ),
					'details'     => (array) ( $result['details'] ?? [] ),
					'checked_at'  => $checked_at,
					'duration_ms' => (int) ( $result['duration_ms'] ?? 0 ),
				],
			],
		];
	}
}
