<?php
/**
 * Minimal stand-in for \wpdb, declared in the global namespace so it
 * satisfies FilesRepository's `?\wpdb $wpdb` constructor type-hint.
 *
 * Unit tests run without WordPress, so the real \wpdb class is never
 * loaded; PSR-4 can't autoload a global-namespace class, so this file is
 * require_once'd directly by the tests that need it. It records what it's
 * asked to do instead of touching any database — callers assert against
 * the recorded SQL/calls.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb { // phpcs:ignore Squiz.Classes.ClassFileName.NoMatch, Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only global-namespace stub, intentionally not autoloaded via PSR-4.

		public string $prefix = 'wp_';

		public string $options = 'wp_options';

		public string $posts = 'wp_posts';

		public string $postmeta = 'wp_postmeta';

		public string $usermeta = 'wp_usermeta';

		public string $users = 'wp_users';

		public string $last_error = '';

		public int $insert_id = 1;

		/** @var string[] Raw query templates passed to prepare(), in call order. */
		public array $prepared_queries = [];

		/** @var array<int, array<int, mixed>> Args passed to prepare(), in call order. */
		public array $prepared_args = [];

		/** @var string[] Queries passed to query(), in call order. */
		public array $queries = [];

		/** @var array<int, array<int, array<string, mixed>>> Queued return values for get_results(), consumed one per call. */
		public array $results_queue = [];

		/** @var array<int, ?array<string, mixed>> Queued return values for get_row(), consumed one per call. */
		public array $row_queue = [];

		/** @var array<int, mixed> Queued return values for get_var(), consumed one per call. */
		public array $var_queue = [];

		/**
		 * @param string $query Raw query template.
		 * @param mixed  ...$args Replacement values (a single array, or a variadic list).
		 * @return string The unmodified template, so callers can inspect it in query()/get_*().
		 */
		public function prepare( $query, ...$args ) {
			$this->prepared_queries[] = $query;
			$this->prepared_args[]    = $args;

			return $query;
		}

		/**
		 * @param string $query SQL, typically the (unmodified) return value of prepare().
		 * @return int Always 1; this stub never touches a database.
		 */
		public function query( $query ) {
			$this->queries[] = $query;

			return 1;
		}

		/**
		 * @return string Fixed suffix; these tests never touch a real server.
		 */
		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}

		/**
		 * @param string $text Value to escape.
		 * @return string Unmodified; no LIKE-wildcard escaping needed by these tests.
		 */
		public function esc_like( $text ) {
			return $text;
		}

		/**
		 * @param string               $table Table name.
		 * @param array<string, mixed> $data  Column => value.
		 * @return int Always 1; this stub never touches a database.
		 */
		public function update( $table, $data, $where = null, $format = null, $where_format = null ) {
			return 1;
		}

		/**
		 * @param string $query  SQL, typically the (unmodified) return value of prepare().
		 * @param mixed  $output Row format; ignored by this stub, whose canned rows already have their final shape.
		 * @return array<int, array<string, mixed>> Next queued batch, or [] once the queue is exhausted.
		 */
		public function get_results( $query, $output = OBJECT ) {
			$this->queries[] = $query;

			return [] === $this->results_queue ? [] : array_shift( $this->results_queue );
		}

		/**
		 * @param string $query  SQL, typically the (unmodified) return value of prepare().
		 * @param mixed  $output Row format; ignored by this stub, whose canned row already has its final shape.
		 * @param int    $y      Row offset; ignored by this stub.
		 * @return array<string, mixed>|null Next queued row, or null once the queue is exhausted.
		 */
		public function get_row( $query, $output = OBJECT, $y = 0 ) {
			$this->queries[] = $query;

			return [] === $this->row_queue ? null : array_shift( $this->row_queue );
		}

		/**
		 * @param string|null $query SQL, typically the (unmodified) return value of prepare().
		 * @param int         $x     Column offset; ignored by this stub.
		 * @param int         $y     Row offset; ignored by this stub.
		 * @return mixed Next queued value, or null once the queue is exhausted.
		 */
		public function get_var( $query = null, $x = 0, $y = 0 ) {
			$this->queries[] = (string) $query;

			return [] === $this->var_queue ? null : array_shift( $this->var_queue );
		}
	}
}
