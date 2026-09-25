<?php
/**
 * Tests for Rest\Presenter\FindingPresenter.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Rest\Presenter;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Rest\Presenter\FindingPresenter;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class FindingPresenterTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
	}

	public function test_what_to_do_for_a_file_finding(): void {
		$this->assertSame(
			'Remove or restore the file. LW Scan never deletes files itself.',
			FindingPresenter::what_to_do( [ 'type' => 'file' ] )
		);
	}

	public function test_what_to_do_for_a_core_integrity_finding(): void {
		$this->assertSame(
			'Re-install WordPress 6.8.2 from wordpress.org to restore the original file.',
			FindingPresenter::what_to_do(
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
			FindingPresenter::what_to_do(
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
			FindingPresenter::what_to_do( [ 'type' => 'db' ] )
		);
	}

	public function test_what_to_do_for_a_patched_vulnerability(): void {
		$this->assertSame(
			'Update to 5.9.4.',
			FindingPresenter::what_to_do( self::vuln_finding( true ) )
		);
	}

	public function test_what_to_do_for_an_unpatched_vulnerability(): void {
		$this->assertSame(
			'No fix is available yet; consider disabling the plugin.',
			FindingPresenter::what_to_do( self::vuln_finding( false ) )
		);
	}

	public function test_what_to_do_for_an_unknown_type_is_empty(): void {
		$this->assertSame( '', FindingPresenter::what_to_do( [ 'type' => 'something-else' ] ) );
	}

	public function test_a_file_finding_is_titled_by_its_path_with_its_signals_below(): void {
		$item = self::present(
			[
				'id'            => '5',
				'type'          => 'file',
				'severity'      => 'alert',
				'state'         => 'new',
				'tier'          => 'infected',
				'category'      => 'backdoor',
				'locator'       => 'wp-content/uploads/x.php',
				'signature_ids' => [ 'lw-1', 'lw-2' ],
				'first_seen'    => '1790000000',
				'last_seen'     => '1790000100',
				'meta'          => [ 'signal_reasons' => [ 'php_in_uploads', 'hidden_name' ] ],
			]
		);

		$this->assertSame( 5, $item['id'] );
		$this->assertSame( 'wp-content/uploads/x.php', $item['title'] );
		$this->assertSame( 'php_in_uploads · hidden_name', $item['subtitle'] );
		$this->assertSame( 'backdoor', $item['detected_by'] );
		$this->assertSame( 'alert', $item['tier_variant'] );
		$this->assertSame( [ 'lw-1', 'lw-2' ], $item['signature_ids'] );
		$this->assertSame( [ 'php_in_uploads', 'hidden_name' ], $item['signal_reasons'] );
		$this->assertSame( 1790000000, $item['first_seen'] );
		$this->assertSame( 1790000100, $item['last_seen'] );
		$this->assertTrue( $item['can_copy_path'] );
		$this->assertNull( $item['update_url'] );
		$this->assertNull( $item['vuln'] );
	}

	public function test_an_integrity_finding_names_the_package_in_its_subtitle(): void {
		$item = self::present(
			[
				'type'    => 'integrity',
				'tier'    => 'integrity',
				'locator' => 'wp-includes/version.php',
				'meta'    => [
					'package' => 'core',
					'version' => '6.8.2',
				],
			]
		);

		$this->assertSame( 'WordPress 6.8.2 · checksum mismatch', $item['subtitle'] );
		$this->assertSame( 'alert', $item['tier_variant'] );
		$this->assertTrue( $item['can_copy_path'] );
	}

	public function test_a_db_finding_uses_its_label_and_cannot_copy_a_path(): void {
		$item = self::present(
			[
				'type'     => 'db',
				'tier'     => 'suspicious',
				'category' => 'spam',
				'locator'  => 'wp_options:siteurl',
				'meta'     => [ 'label' => 'option siteurl' ],
			]
		);

		$this->assertSame( 'option siteurl', $item['subtitle'] );
		$this->assertSame( 'spam', $item['detected_by'] );
		$this->assertSame( 'review', $item['tier_variant'] );
		$this->assertFalse( $item['can_copy_path'] );
	}

	public function test_a_patched_vulnerability_links_to_the_update_screen(): void {
		$item = self::present( self::vuln_finding( true ) );

		$this->assertSame( 'Contact Form 7 5.9.3', $item['title'] );
		$this->assertSame( 'plugin · patched in 5.9.4', $item['subtitle'] );
		$this->assertSame( 'Unauthenticated Stored XSS', $item['detected_by'] );
		$this->assertSame( 'https://example.com/wp-admin/update-core.php', $item['update_url'] );
		$this->assertSame( 'Update to 5.9.4.', $item['advice'] );
		$this->assertFalse( $item['can_copy_path'] );
		$this->assertSame(
			[
				'title'       => 'Unauthenticated Stored XSS',
				'reference'   => 'https://example.org/cve-1',
				'notice'      => '',
				'license'     => '',
				'license_url' => '',
			],
			$item['vuln']
		);
	}

	public function test_an_unpatched_vulnerability_has_no_update_url(): void {
		$item = self::present( self::vuln_finding( false ) );

		$this->assertSame( 'plugin · no fix available', $item['subtitle'] );
		$this->assertNull( $item['update_url'] );
	}

	public function test_a_long_vulnerability_title_is_shortened_for_detected_by(): void {
		$finding = self::vuln_finding( true );

		$finding['meta']['records'][0]['title'] = str_repeat( 'word ', 30 );

		$this->assertSame( 80, mb_strlen( self::present( $finding )['detected_by'] ) );
	}

	public function test_the_excerpt_comes_from_the_first_match(): void {
		$item = self::present(
			[
				'type'    => 'file',
				'excerpt' => 'column excerpt',
				'line'    => '3',
				'meta'    => [
					'matches' => [
						[
							'excerpt' => 'eval(base64_decode(',
							'line'    => 12,
						],
					],
				],
			]
		);

		$this->assertSame( 'eval(base64_decode(', $item['excerpt'] );
		$this->assertSame( 12, $item['excerpt_line'] );
	}

	public function test_the_excerpt_falls_back_to_the_row_columns(): void {
		$item = self::present(
			[
				'type'    => 'db',
				'excerpt' => '<script src="x"></script>',
				'line'    => '0',
				'meta'    => [ 'matches' => [ [ 'name' => 'no excerpt here' ] ] ],
			]
		);

		$this->assertSame( '<script src="x"></script>', $item['excerpt'] );
		$this->assertSame( 0, $item['excerpt_line'] );
	}

	public function test_no_excerpt_at_all_is_null(): void {
		$this->assertNull( self::present( [ 'type' => 'vulnerability' ] )['excerpt'] );
	}

	/**
	 * @param array<string, mixed> $finding Findings-table row.
	 * @return array<string, mixed>
	 */
	private static function present( array $finding ): array {
		return ( new FindingPresenter( 'https://example.com/wp-admin/update-core.php' ) )->present( $finding );
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
						'id'         => 'cve-1',
						'title'      => 'Unauthenticated Stored XSS',
						'references' => [ 'https://example.org/cve-1' ],
						'software'   => [
							[
								'type'             => 'plugin',
								'slug'             => 'contact-form-7',
								'name'             => 'Contact Form 7',
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
