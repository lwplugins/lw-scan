<?php
/**
 * Memory limit and per-tick time budget check for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

use LightweightPlugins\Scan\Run\Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12: `memory_limit`, `max_execution_time`, and the tick budget
 * `Run\Budget` computes from it.
 *
 * Task 12 dropped the memory floor the plugin used to ask WordPress to
 * raise `memory_limit` to — the signature pack it loads every tick costs a
 * few megabytes rather than the tens the old compiled bundle did, so there
 * is nothing left worth asking a host for. This check is informational
 * only now: it never calls `wp_raise_memory_limit()` and its rows are never
 * `blocking`. It still says what a tick actually has to work with, because
 * a limit on its own says very little — what is left of it after
 * WordPress and its plugins have loaded is the number that matters.
 *
 * Both readings are injectable so tests can describe a machine without
 * having to become one: lowering the real `memory_limit`, even temporarily,
 * risks the test process itself hitting the new limit, and simulating a
 * heavy bootstrap would mean allocating it.
 */
final class MemoryCheck implements CheckInterface {

	/** Nothing finishes a scan below this, whatever is free. */
	private const CRITICAL_BYTES = 128 * 1024 * 1024;

	/** Free memory a scan tick needs at minimum; below this is a warning. */
	private const TICK_BYTES = 48 * 1024 * 1024;

	/**
	 * @var callable(string): (string|false)
	 */
	private $ini_get;

	/**
	 * @var callable(): int
	 */
	private $memory_get_usage;

	/**
	 * @param callable(string): (string|false) $ini_get          Reads one ini setting; defaults to the real `ini_get()`.
	 * @param callable(): int                  $memory_get_usage Bytes this request has spent; defaults to the real allocation.
	 */
	public function __construct( ?callable $ini_get = null, ?callable $memory_get_usage = null ) {
		$this->ini_get          = $ini_get ?? 'ini_get';
		$this->memory_get_usage = $memory_get_usage ?? static function (): int {
			return memory_get_usage( true );
		};
	}

	public function id(): string {
		return 'memory';
	}

	public function label(): string {
		return __( 'Memory & time budget', 'lw-scan' );
	}

	public function run(): array {
		$raw = $this->ini_get_memory_limit();

		if ( '' === $raw || '-1' === $raw ) {
			return self::row(
				'ok',
				/* translators: %s: per-tick time budget in seconds. */
				sprintf( __( 'memory_limit is unlimited; per-tick time budget %ss.', 'lw-scan' ), (string) $this->budget_seconds() )
			);
		}

		$bytes = (int) wp_convert_hr_to_bytes( $raw );

		if ( $bytes < self::CRITICAL_BYTES ) {
			return self::row(
				'critical',
				/* translators: %s: memory_limit ini value, e.g. "64M". */
				sprintf( __( 'memory_limit is %s — scans need at least 128 MB.', 'lw-scan' ), $raw )
			);
		}

		return $this->headroom_row( $bytes );
	}

	/**
	 * The per-tick time budget the Runner itself uses (spec §10.2).
	 */
	public function budget_seconds(): float {
		return Budget::seconds();
	}

	/**
	 * What is left of `memory_limit` once WordPress and its plugins have
	 * loaded, against the ~20 MB a scan tick needs on top of that.
	 *
	 * @param int $bytes The `memory_limit` in bytes.
	 * @return array{status:string, message:string, blocking:bool}
	 */
	private function headroom_row( int $bytes ): array {
		$used = ( $this->memory_get_usage )();
		$free = $bytes - $used;

		if ( $free < self::TICK_BYTES ) {
			return self::row(
				'warning',
				sprintf(
					/* translators: 1: memory in use. 2: memory_limit. 3: memory left for a tick. */
					__( 'WordPress and your plugins already use %1$s of the %2$s memory_limit, leaving %3$s; a scan tick needs about 20 MB. Raise memory_limit or run scans with WP-CLI.', 'lw-scan' ),
					(string) size_format( $used ),
					(string) size_format( $bytes ),
					(string) size_format( max( 0, $free ) )
				)
			);
		}

		return self::row(
			'ok',
			sprintf(
				/* translators: 1: memory_limit. 2: memory free after WordPress loads. 3: per-tick time budget in seconds. */
				__( 'memory_limit %1$s, %2$s free after WordPress loads; per-tick time budget %3$ss.', 'lw-scan' ),
				(string) size_format( $bytes ),
				(string) size_format( $free ),
				(string) $this->budget_seconds()
			)
		);
	}

	/**
	 * @param string $status  ok|warning|critical.
	 * @param string $message The row's sentence.
	 * @return array{status:string, message:string, blocking:bool}
	 */
	private static function row( string $status, string $message ): array {
		return [
			'status'   => $status,
			'message'  => $message,
			'blocking' => false,
		];
	}

	private function ini_get_memory_limit(): string {
		$value = ( $this->ini_get )( 'memory_limit' );

		return false === $value ? '' : trim( $value );
	}
}
