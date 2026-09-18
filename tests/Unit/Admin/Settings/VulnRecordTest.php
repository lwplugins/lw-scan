<?php
/**
 * Tests for Admin\Settings\VulnRecord.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\Settings\VulnRecord;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class VulnRecordTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
	}

	public function test_short_title_keeps_a_title_within_the_limit(): void {
		$this->assertSame(
			'Unauthenticated Stored XSS',
			VulnRecord::short_title( self::finding(), 80 )
		);
	}

	public function test_short_title_truncates_a_long_title(): void {
		$long = str_repeat( 'a', 120 );
		$out  = VulnRecord::short_title( self::finding( [ 'title' => $long ] ), 80 );

		$this->assertSame( 80, mb_strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
	}

	public function test_short_title_is_empty_without_a_record(): void {
		$this->assertSame( '', VulnRecord::short_title( [ 'type' => 'vulnerability' ], 80 ) );
	}

	public function test_reference_accepts_only_https(): void {
		$this->assertSame(
			'https://example.test/v/1',
			VulnRecord::reference( self::finding( [ 'references' => [ 'https://example.test/v/1' ] ] ) )
		);
	}

	/**
	 * @dataProvider provide_rejected_references
	 */
	public function test_reference_rejects_anything_else( string $url ): void {
		$this->assertSame( '', VulnRecord::reference( self::finding( [ 'references' => [ $url ] ] ) ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_rejected_references(): array {
		return [
			'plain http'   => [ 'http://example.test/v/1' ],
			'javascript'   => [ 'javascript:alert(1)' ],
			'data uri'     => [ 'data:text/html,<script>alert(1)</script>' ],
			'protocol-rel' => [ '//example.test/v/1' ],
		];
	}

	public function test_copyright_reads_the_defiant_notice(): void {
		$this->assertSame(
			'Disclosure provided under license by Defiant Inc.',
			VulnRecord::copyright( self::finding() )
		);
	}

	public function test_copyright_is_empty_when_the_record_has_none(): void {
		$this->assertSame( '', VulnRecord::copyright( self::finding( [ 'copyrights' => [] ] ) ) );
	}

	public function test_patched_version_reads_the_matching_software_entry(): void {
		$this->assertSame( '5.9.4', VulnRecord::patched_version( self::finding() ) );
	}

	public function test_patched_version_ignores_another_packages_entry(): void {
		$finding                        = self::finding();
		$finding['meta']['records'][0]['software'][0]['slug'] = 'some-other-plugin';

		$this->assertSame( '', VulnRecord::patched_version( $finding ) );
	}

	/**
	 * @param array<string, mixed> $overrides Record fields to replace.
	 * @return array<string, mixed>
	 */
	private static function finding( array $overrides = [] ): array {
		$record = array_merge(
			[
				'id'         => 'cve-1',
				'title'      => 'Unauthenticated Stored XSS',
				'references' => [ 'https://example.test/v/1' ],
				'copyrights' => [
					'defiant' => [ 'notice' => 'Disclosure provided under license by Defiant Inc.' ],
				],
				'software'   => [
					[
						'type'             => 'plugin',
						'slug'             => 'contact-form-7',
						'name'             => 'Contact Form 7',
						'patched'          => true,
						'patched_versions' => [ '5.9.4' ],
					],
				],
			],
			$overrides
		);

		return [
			'type'    => 'vulnerability',
			'locator' => 'plugin:contact-form-7',
			'meta'    => [
				'installed_version' => '5.9.3',
				'records'           => [ $record ],
			],
		];
	}
}
