<?php
/**
 * Tests for CLI\Attribution.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\CLI;

use LightweightPlugins\Scan\CLI\Attribution;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class AttributionTest extends MonkeyTestCase {

	private const NOTICE  = 'Copyright 2012-2026 Defiant Inc.';
	private const LICENSE = 'Defiant hereby grants you a perpetual copyright license.';
	private const TERMS   = 'https://example.test/terms/';
	private const RECORD  = 'https://example.test/v/1';

	/**
	 * @param array<string, mixed> $defiant Record's `copyrights.defiant` block.
	 * @return array<string, mixed>
	 */
	private static function vuln_finding( array $defiant = [] ): array {
		return [
			'id'      => 7,
			'type'    => 'vulnerability',
			'locator' => 'plugin:elementor',
			'meta'    => [
				'records' => [
					[
						'id'         => 'cve-1',
						'title'      => 'Elementor CSRF',
						'references' => [ self::RECORD ],
						'copyrights' => [
							'defiant' => array_merge(
								[
									'notice'      => self::NOTICE,
									'license'     => self::LICENSE,
									'license_url' => self::TERMS,
								],
								$defiant
							),
						],
					],
				],
			],
		];
	}

	public function test_fields_read_the_attribution_verbatim_from_the_record(): void {
		$this->assertSame(
			[
				'reference'   => self::RECORD,
				'copyright'   => self::NOTICE,
				'license'     => self::LICENSE,
				'license_url' => self::TERMS,
			],
			Attribution::fields( self::vuln_finding() )
		);
	}

	public function test_fields_decode_a_raw_meta_column(): void {
		$finding         = self::vuln_finding();
		$finding['meta'] = (string) json_encode( $finding['meta'] );

		$this->assertSame( self::LICENSE, Attribution::fields( $finding )['license'] );
	}

	public function test_fields_are_empty_for_a_non_vulnerability_row(): void {
		$finding         = self::vuln_finding();
		$finding['type'] = 'file';

		$this->assertSame( [ '', '', '', '' ], array_values( Attribution::fields( $finding ) ) );
	}

	public function test_with_fields_adds_the_attribution_to_every_json_row(): void {
		$file = [
			'id'   => 8,
			'type' => 'file',
		];

		$rows = Attribution::with_fields( [ self::vuln_finding(), $file ] );

		$this->assertSame( self::LICENSE, $rows[0]['license'] );
		$this->assertSame( 'cve-1', $rows[0]['meta']['records'][0]['id'] );
		$this->assertSame( '', $rows[1]['license'] );
		$this->assertSame( '', $rows[1]['reference'] );
	}

	public function test_footer_prints_one_block_per_distinct_wording(): void {
		$this->assertSame(
			[ self::NOTICE . ' ' . self::LICENSE . "\nLicense terms: " . self::TERMS ],
			Attribution::footer( [ self::vuln_finding(), self::vuln_finding() ] )
		);
	}

	public function test_footer_leaves_out_a_non_https_terms_link(): void {
		$this->assertSame(
			[ self::NOTICE . ' ' . self::LICENSE ],
			Attribution::footer( [ self::vuln_finding( [ 'license_url' => 'http://example.test/terms/' ] ) ] )
		);
	}

	public function test_footer_is_empty_without_a_vulnerability_row(): void {
		$file = [
			'id'   => 8,
			'type' => 'file',
		];

		$this->assertSame( [], Attribution::footer( [ $file ] ) );
	}
}
