<?php
/**
 * Tests for Admin\Settings\FindingRow.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Admin\Settings;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Admin\Settings\FindingRow;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class FindingRowTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	public function test_what_to_do_for_a_file_finding(): void {
		$this->assertSame(
			'Remove or restore the file. LW Scan never deletes files itself.',
			FindingRow::what_to_do( [ 'type' => 'file' ] )
		);
	}

	public function test_what_to_do_for_a_core_integrity_finding(): void {
		$this->assertSame(
			'Re-install WordPress 6.8.2 from wordpress.org to restore the original file.',
			FindingRow::what_to_do(
				[
					'type' => 'integrity',
					'meta' => [
						'package' => 'core',
						'version' => '6.8.2',
					],
				]
			)
		);
	}

	public function test_what_to_do_for_a_plugin_integrity_finding(): void {
		$this->assertSame(
			'Re-install akismet 5.3 from wordpress.org to restore the original file.',
			FindingRow::what_to_do(
				[
					'type' => 'integrity',
					'meta' => [
						'package' => 'plugin:akismet',
						'version' => '5.3',
					],
				]
			)
		);
	}

	public function test_what_to_do_for_a_db_finding(): void {
		$this->assertSame(
			'Inspect and clean the row in the database (e.g. via WP-CLI or phpMyAdmin).',
			FindingRow::what_to_do( [ 'type' => 'db' ] )
		);
	}

	public function test_what_to_do_for_a_patched_vulnerability(): void {
		$this->assertSame(
			'Update to 5.9.4.',
			FindingRow::what_to_do( self::vuln_finding( true ) )
		);
	}

	public function test_what_to_do_for_an_unpatched_vulnerability(): void {
		$this->assertSame(
			'No fix is available yet; consider disabling the plugin.',
			FindingRow::what_to_do( self::vuln_finding( false ) )
		);
	}

	public function test_what_to_do_for_an_unknown_type_is_empty(): void {
		$this->assertSame( '', FindingRow::what_to_do( [ 'type' => 'something-else' ] ) );
	}

	public function test_highlighted_excerpt_escapes_html(): void {
		$this->assertSame(
			'&lt;script&gt;alert(1)&lt;/script&gt;',
			FindingRow::highlighted_excerpt( '<script>alert(1)</script>', [] )
		);
	}

	public function test_highlighted_excerpt_wraps_the_match_in_a_mark(): void {
		$out = FindingRow::highlighted_excerpt(
			"<?php eval(base64_decode(\$_POST['c']));",
			[ [ 'excerpt' => 'eval(base64_decode(' ] ]
		);

		$this->assertSame(
			'&lt;?php <mark>eval(base64_decode(</mark>$_POST[&#039;c&#039;]));',
			$out
		);
	}

	public function test_highlighted_excerpt_escapes_a_hostile_match_too(): void {
		$out = FindingRow::highlighted_excerpt(
			'before <img src=x onerror=alert(1)> after',
			[ [ 'excerpt' => '<img src=x onerror=alert(1)>' ] ]
		);

		$this->assertSame(
			'before <mark>&lt;img src=x onerror=alert(1)&gt;</mark> after',
			$out
		);
		$this->assertStringNotContainsString( '<img', $out );
	}

	public function test_highlighted_excerpt_without_a_substring_match_is_only_escaped(): void {
		$this->assertSame(
			'a &amp; b',
			FindingRow::highlighted_excerpt( 'a & b', [ [ 'excerpt' => 'nowhere' ] ] )
		);
	}

	public function test_highlighted_excerpt_ignores_a_match_without_an_excerpt(): void {
		$this->assertSame(
			'plain text',
			FindingRow::highlighted_excerpt( 'plain text', [ [ 'name' => 'sig' ] ] )
		);
	}

	/**
	 * @param bool $patched Whether the record carries a patched version.
	 * @return array<string, mixed>
	 */
	private static function vuln_finding( bool $patched ): array {
		return [
			'type'    => 'vulnerability',
			'locator' => 'plugin:contact-form-7',
			'meta'    => [
				'installed_version' => '5.9.3',
				'records'           => [
					[
						'id'       => 'cve-1',
						'title'    => 'Unauthenticated Stored XSS',
						'software' => [
							[
								'type'             => 'plugin',
								'slug'             => 'contact-form-7',
								'patched'          => $patched,
								'patched_versions' => $patched ? [ '5.9.4' ] : [],
							],
						],
					],
				],
			],
		];
	}
}
