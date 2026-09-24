<?php
/**
 * Tests for Rest\Errors.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest;

use LightweightPlugins\Scan\Rest\Errors;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname( __DIR__ ) . '/WpErrorStub.php';

final class ErrorsTest extends TestCase {

	/**
	 * @dataProvider provide_runner_refusals
	 */
	public function test_a_runner_refusal_gets_its_http_status( string $code, int $status ): void {
		$error = Errors::from_runner( new WP_Error( $code, 'Refused.' ) );

		$this->assertSame( $code, $error->get_error_code() );
		$this->assertSame( 'Refused.', $error->get_error_message() );
		$this->assertSame( [ 'status' => $status ], $error->error_data[ $code ] );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function provide_runner_refusals(): array {
		return [
			'busy'         => [ 'lw_scan_busy', 409 ],
			'bad path'     => [ 'lw_scan_bad_path', 400 ],
			'blocked'      => [ 'lw_scan_blocked', 400 ],
			'nothing left' => [ 'lw_scan_nothing_to_resume', 400 ],
		];
	}
}
