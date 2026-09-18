<?php
/**
 * Tests for Health\Checks\PcreCheck.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Health\Checks;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Health\Checks\PcreCheck;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class PcreCheckTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_id_and_label(): void {
		$check = new PcreCheck();

		$this->assertSame( 'pcre', $check->id() );
		$this->assertSame( 'PCRE engine', $check->label() );
	}

	public function test_status_ok_when_jit_enabled(): void {
		$check = new PcreCheck(
			static function ( string $key ) {
				return 'pcre.jit' === $key ? '1' : ini_get( $key );
			}
		);

		$result = $check->run();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_status_warning_when_jit_disabled(): void {
		$check = new PcreCheck(
			static function ( string $key ) {
				return 'pcre.jit' === $key ? '0' : ini_get( $key );
			}
		);

		$result = $check->run();

		$this->assertSame( 'warning', $result['status'] );
		$this->assertFalse( $result['blocking'] );
	}

	public function test_status_warning_when_jit_value_is_empty_string(): void {
		$check = new PcreCheck(
			static function ( string $key ) {
				return 'pcre.jit' === $key ? '' : ini_get( $key );
			}
		);

		$this->assertSame( 'warning', $check->run()['status'] );
	}
}
