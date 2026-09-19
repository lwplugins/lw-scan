<?php
/**
 * Minimal stand-ins for WordPress' global WP_REST_Request and
 * WP_REST_Response classes.
 *
 * Unit tests run without WordPress, and PSR-4 cannot autoload a
 * global-namespace class, so this file is require_once'd directly by the
 * tests that need it (same pattern as WpErrorStub.php). Only what
 * `Status\StatusRoute` touches is implemented.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	class WP_REST_Request { // phpcs:ignore Squiz.Classes.ClassFileName.NoMatch, Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only global-namespace stub, intentionally not autoloaded via PSR-4.

		/** @var array<string, mixed> Named captures from the route regex. */
		private array $url_params;

		/** @var array<string, mixed> The query string. */
		private array $query_params;

		/**
		 * @param array<string, mixed> $url_params   Route captures.
		 * @param array<string, mixed> $query_params Query string.
		 */
		public function __construct( array $url_params = [], array $query_params = [] ) {
			$this->url_params   = $url_params;
			$this->query_params = $query_params;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_url_params() {
			return $this->url_params;
		}

		/**
		 * Like core for a GET request: the query string wins over the URL
		 * captures, which is exactly why the route must not use this for
		 * the key.
		 *
		 * @param string $key Parameter name.
		 * @return mixed
		 */
		public function get_param( $key ) {
			if ( array_key_exists( $key, $this->query_params ) ) {
				return $this->query_params[ $key ];
			}

			return $this->url_params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	class WP_REST_Response { // phpcs:ignore Squiz.Classes.ClassFileName.NoMatch, Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only global-namespace stub, intentionally not autoloaded via PSR-4.

		/** @var mixed */
		public $data;

		public int $status;

		/** @var array<string, string> */
		public array $headers = [];

		/**
		 * @param mixed                 $data    Response data.
		 * @param int                   $status  HTTP status.
		 * @param array<string, string> $headers Headers.
		 */
		public function __construct( $data = null, $status = 200, $headers = [] ) {
			$this->data    = $data;
			$this->status  = (int) $status;
			$this->headers = $headers;
		}

		/**
		 * @param string $key     Header name.
		 * @param string $value   Header value.
		 * @param bool   $replace Replace an existing value.
		 */
		public function header( $key, $value, $replace = true ): void {
			if ( $replace || ! isset( $this->headers[ $key ] ) ) {
				$this->headers[ $key ] = $value;
			}
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		/**
		 * @return array<string, string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}
