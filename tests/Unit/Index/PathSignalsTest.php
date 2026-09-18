<?php
/**
 * Tests for Index\PathSignals.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Index;

use LightweightPlugins\Scan\Index\PathSignals;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class PathSignalsTest extends MonkeyTestCase {

	public function test_php_in_uploads_is_flagged(): void {
		$result = PathSignals::score( 'wp-content/uploads/2026/09/cafccefhgc.php', 'uploads', 'php', null );

		$this->assertContains( 'php_in_uploads', $result['reasons'] );
		$this->assertGreaterThanOrEqual( 60, $result['score'] );
	}

	public function test_uploads_file_that_is_not_php_is_not_flagged(): void {
		$result = PathSignals::score( 'wp-content/uploads/2026/09/photo.jpg', 'uploads', 'other', null );

		$this->assertNotContains( 'php_in_uploads', $result['reasons'] );
	}

	public function test_core_path_missing_from_checksum_list_is_flagged(): void {
		$core_paths = [ 'wp-includes/js/wp-embed.min.js' => 'deadbeef' ];

		$result = PathSignals::score( 'wp-includes/blocks/DYO/block/debug-compat.php', 'core', 'php', $core_paths );

		$this->assertContains( 'unknown_core_path', $result['reasons'] );
		$this->assertGreaterThanOrEqual( 60, $result['score'] );
	}

	public function test_core_path_present_in_checksum_list_is_not_flagged(): void {
		$rel        = 'wp-includes/js/wp-embed.min.js';
		$core_paths = [ $rel => 'deadbeef' ];

		$result = PathSignals::score( $rel, 'core', 'js', $core_paths );

		$this->assertNotContains( 'unknown_core_path', $result['reasons'] );
	}

	public function test_three_nested_same_name_dirs_are_flagged(): void {
		$result = PathSignals::score( 'EsFAlcvaSRYZ/EsFAlcvaSRYZ/EsFAlcvaSRYZ/x.php', 'other', 'php', null );

		$this->assertContains( 'nested_same_dir', $result['reasons'] );
	}

	public function test_repeated_dir_name_that_is_not_consecutive_is_not_flagged(): void {
		$result = PathSignals::score( 'EsFAlcvaSRYZ/other/EsFAlcvaSRYZ/x.php', 'other', 'php', null );

		$this->assertNotContains( 'nested_same_dir', $result['reasons'] );
	}

	public function test_random_looking_root_dir_is_flagged(): void {
		$result = PathSignals::score( 'ZXCVBNMQ/shell.php', 'other', 'php', null );

		$this->assertContains( 'random_root_dir', $result['reasons'] );
	}

	public function test_ordinary_root_dir_is_not_flagged(): void {
		$result = PathSignals::score( 'includesfolder/page.php', 'other', 'php', null );

		$this->assertNotContains( 'random_root_dir', $result['reasons'] );
	}

	public function test_short_mixed_case_root_dir_is_flagged(): void {
		$result = PathSignals::score( 'TpAEMU/index.php', 'other', 'php', null );

		$this->assertContains( 'random_root_dir', $result['reasons'] );
	}

	public function test_normal_camel_case_root_dir_is_not_flagged(): void {
		$result = PathSignals::score( 'MyPlugin/x.php', 'other', 'php', null );

		$this->assertNotContains( 'random_root_dir', $result['reasons'] );
	}

	public function test_wp_content_root_is_never_flagged_as_random(): void {
		$result = PathSignals::score( 'wp-content/uploads/2026/09/photo.jpg', 'uploads', 'other', null );

		$this->assertNotContains( 'random_root_dir', $result['reasons'] );
	}

	public function test_mu_plugin_is_flagged(): void {
		$result = PathSignals::score( 'wp-content/mu-plugins/site-compat-layer.php', 'mu', 'php', null );

		$this->assertContains( 'mu_plugin_unknown', $result['reasons'] );
	}

	public function test_non_mu_origin_is_not_flagged_as_mu_plugin(): void {
		$result = PathSignals::score( 'wp-content/plugins/akismet/akismet.php', 'plugin:akismet', 'php', null );

		$this->assertNotContains( 'mu_plugin_unknown', $result['reasons'] );
	}

	public function test_known_good_mu_plugin_is_not_flagged(): void {
		$result = PathSignals::score( 'wp-content/mu-plugins/site-compat-layer.php', 'mu', 'php', null, true );

		$this->assertNotContains( 'mu_plugin_unknown', $result['reasons'] );
	}

	public function test_php_content_with_non_php_extension_is_flagged(): void {
		$result = PathSignals::score( 'wp-content/plugins/some-plugin/readme.txt', 'plugin:some-plugin', 'php', null );

		$this->assertContains( 'php_disguised', $result['reasons'] );
	}

	public function test_php_content_with_php_extension_is_not_flagged_as_disguised(): void {
		$result = PathSignals::score( 'wp-content/plugins/some-plugin/main.php', 'plugin:some-plugin', 'php', null );

		$this->assertNotContains( 'php_disguised', $result['reasons'] );
	}

	public function test_binary_file_under_wp_content_is_flagged(): void {
		$result = PathSignals::score( 'wp-content/uploads/malware.elf', 'uploads', 'binary', null );

		$this->assertContains( 'binary_in_content', $result['reasons'] );
	}

	public function test_binary_file_outside_wp_content_is_not_flagged(): void {
		$result = PathSignals::score( 'wp-includes/js/somefile.bin', 'core', 'binary', null );

		$this->assertNotContains( 'binary_in_content', $result['reasons'] );
	}

	public function test_core_looking_image_missing_from_checksum_list_is_flagged(): void {
		$core_paths = [ 'wp-includes/images/real.gif' => 'deadbeef' ];

		$result = PathSignals::score( 'wp-includes/images/xit-3x.gif', 'core', 'other', $core_paths );

		$this->assertContains( 'fake_core_asset', $result['reasons'] );
	}

	public function test_core_image_present_in_checksum_list_is_not_flagged(): void {
		$rel        = 'wp-includes/images/real.gif';
		$core_paths = [ $rel => 'deadbeef' ];

		$result = PathSignals::score( $rel, 'core', 'other', $core_paths );

		$this->assertNotContains( 'fake_core_asset', $result['reasons'] );
	}

	public function test_htaccess_outside_allowed_locations_is_flagged(): void {
		$result = PathSignals::score( 'wp-content/plugins/some-plugin/.htaccess', 'plugin:some-plugin', 'other', null );

		$this->assertContains( 'stray_htaccess', $result['reasons'] );
	}

	public function test_htaccess_in_uploads_is_not_flagged(): void {
		$result = PathSignals::score( 'wp-content/uploads/.htaccess', 'uploads', 'other', null );

		$this->assertNotContains( 'stray_htaccess', $result['reasons'] );
	}

	public function test_score_is_capped_at_100(): void {
		$rel = 'wp-content/uploads/EsFAlcvaSRYZ/EsFAlcvaSRYZ/EsFAlcvaSRYZ/evil.txt';

		$result = PathSignals::score( $rel, 'uploads', 'php', null );

		$this->assertSame( 100, $result['score'] );
		$this->assertContains( 'php_in_uploads', $result['reasons'] );
		$this->assertContains( 'php_disguised', $result['reasons'] );
		$this->assertContains( 'nested_same_dir', $result['reasons'] );
	}

	public function test_null_core_paths_skips_core_dependent_rules(): void {
		$result = PathSignals::score( 'wp-includes/images/xit-3x.gif', 'core', 'other', null );

		$this->assertNotContains( 'unknown_core_path', $result['reasons'] );
		$this->assertNotContains( 'fake_core_asset', $result['reasons'] );
	}

	public function test_an_archive_in_uploads_is_not_treated_as_disguised_php(): void {
		$result = PathSignals::score( 'wp-content/uploads/wp-rollback/plugin-10.1.zip', 'uploads', 'archive', null );

		$this->assertNotContains( 'php_disguised', $result['reasons'] );
		$this->assertNotContains( 'php_in_uploads', $result['reasons'] );
	}

	public function test_an_archive_under_wp_content_still_scores_as_binary_content(): void {
		$result = PathSignals::score( 'wp-content/uploads/backup.zip', 'uploads', 'archive', null );

		$this->assertContains( 'binary_in_content', $result['reasons'] );
	}
}
