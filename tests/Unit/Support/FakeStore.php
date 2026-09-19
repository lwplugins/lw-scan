<?php
/**
 * In-memory options and transients for Brain Monkey tests.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Support;

use Brain\Monkey\Functions;

/**
 * Stubs the option and transient API against two arrays, and records how
 * each option was written — `add_option()` vs `update_option()` and the
 * autoload flag — so a test can assert an option stays out of autoload.
 */
trait FakeStore {

	/** @var array<string, mixed> Stand-in for the options table. */
	protected array $options = [];

	/** @var array<string, array{value: mixed, ttl: int}> Stand-in for the transients. */
	protected array $transients = [];

	/** @var array<int, array{0: string, 1: string, 2: mixed}> Writes: [function, option name, autoload flag]. */
	protected array $option_writes = [];

	/** @var array<int, string> Transient names passed to delete_transient(), in call order. */
	protected array $deleted_transients = [];

	protected function stub_store(): void {
		$this->options            = [];
		$this->transients         = [];
		$this->option_writes      = [];
		$this->deleted_transients = [];

		$this->stub_option_functions();
		$this->stub_transient_functions();
	}

	private function stub_option_functions(): void {
		$options = &$this->options;
		$writes  = &$this->option_writes;

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value, $deprecated = '', $autoload = null ) use ( &$options, &$writes ): bool {
				$options[ $name ] = $value;
				$writes[]         = [ 'add_option', $name, $autoload ];

				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( &$options, &$writes ): bool {
				$options[ $name ] = $value;
				$writes[]         = [ 'update_option', $name, $autoload ];

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$options ): bool {
				unset( $options[ $name ] );

				return true;
			}
		);
	}

	private function stub_transient_functions(): void {
		$transients = &$this->transients;
		$deleted    = &$this->deleted_transients;

		Functions\when( 'get_transient' )->alias(
			static function ( $name ) use ( &$transients ) {
				return array_key_exists( $name, $transients ) ? $transients[ $name ]['value'] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $name, $value, $ttl = 0 ) use ( &$transients ): bool {
				$transients[ $name ] = [
					'value' => $value,
					'ttl'   => (int) $ttl,
				];

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $name ) use ( &$transients, &$deleted ): bool {
				unset( $transients[ $name ] );
				$deleted[] = $name;

				return true;
			}
		);
	}
}
