<?php
/**
 * Minimal stand-in for WordPress' global WP_Error class.
 *
 * Unit tests run without WordPress, so `WP_Error` is never defined; PSR-4
 * can't autoload a global-namespace class, so this file is require_once'd
 * directly by the tests that need it (same pattern as
 * tests/Unit/Db/FakeWpdb.php). Only the accessors the plugin actually uses
 * are implemented.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error { // phpcs:ignore Squiz.Classes.ClassFileName.NoMatch, Generic.Files.OneObjectStructurePerFile.MultipleFound -- test-only global-namespace stub, intentionally not autoloaded via PSR-4.

		/** @var array<string, string[]> */
		public array $errors = [];

		/** @var array<string, mixed> */
		public array $error_data = [];

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->errors[ $code ][] = (string) $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );

			return (string) ( $codes[0] ?? '' );
		}

		/**
		 * @param string $code Error code, or '' for the first one.
		 */
		public function get_error_message( $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : (string) $code;

			return (string) ( $this->errors[ $code ][0] ?? '' );
		}
	}
}
