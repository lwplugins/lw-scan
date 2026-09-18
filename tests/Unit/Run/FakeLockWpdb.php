<?php
/**
 * Minimal stand-in for \wpdb used by LockTest. Lock never type-hints
 * \wpdb — it reads `global $wpdb` and only calls prepare()/get_var() — so
 * this plain, namespaced stub is enough; no global-namespace class is
 * needed (see tests/Unit/Db/FakeWpdb.php for that pattern, used where a
 * type-hint forces it).
 *
 * `Run\Starter` also reaches `Db\Schema`'s table probes through
 * `Health\Environment::blocking_issue()`, so this double answers those too:
 * `SHOW TABLES LIKE` is served from `$tables_installed` rather than from the
 * queue, which stays what it was — the lock's `GET_LOCK`/`RELEASE_LOCK`
 * answers, in order.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

final class FakeLockWpdb {

	/** Table prefix, as `Db\Schema` reads it off `$wpdb`. */
	public string $prefix = 'wp_';

	/** Whether the three `SHOW TABLES` probes find the plugin's tables. */
	public bool $tables_installed = true;

	/** @var string[] Raw query templates passed to prepare(), in call order. */
	public array $prepared_queries = [];

	/** @var array<int, array<int, mixed>> Args passed to prepare(), in call order. */
	public array $prepared_args = [];

	/** @var array<int, string|null> Values get_var() returns, one per call, in order. */
	private array $queue;

	/**
	 * @param array<int, string|null> $queue Values get_var() returns, one per call, in order.
	 */
	public function __construct( array $queue = [] ) {
		$this->queue = $queue;
	}

	/**
	 * @param string $query Raw query template.
	 * @param mixed  ...$args Replacement values.
	 * @return string The unmodified template, so get_var() can be asserted against it.
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared_queries[] = $query;
		$this->prepared_args[]    = $args;

		return $query;
	}

	/**
	 * @param string $text Value to escape for a LIKE comparison.
	 * @return string Unmodified; no wildcard escaping is needed by these tests.
	 */
	public function esc_like( $text ) {
		return $text;
	}

	/**
	 * @param string $query  SQL, typically the (unmodified) return value of prepare().
	 * @param mixed  $output Row format; ignored.
	 * @param int    $y      Row offset; ignored.
	 * @return array<string, mixed>|null Always null: `Health\Checks\TablesCheck`
	 *                                   reads this for engine/row figures it
	 *                                   reports but never blocks on.
	 */
	public function get_row( $query, $output = null, $y = 0 ) {
		unset( $query, $output, $y );

		return null;
	}

	/**
	 * @param string $query SQL, typically the (unmodified) return value of prepare().
	 * @return string|null The probed table name for a `SHOW TABLES` existence
	 *                     probe, else the next queued value (null once the
	 *                     queue is exhausted).
	 */
	public function get_var( $query ) {
		if ( 0 === strpos( (string) $query, 'SHOW TABLES' ) ) {
			$args = end( $this->prepared_args );

			return $this->tables_installed ? (string) ( is_array( $args ) ? ( $args[0] ?? '' ) : '' ) : null;
		}

		return array_shift( $this->queue );
	}
}
