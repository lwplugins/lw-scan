<?php
/**
 * Tests for Index\SelfManifest.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Index;

use LightweightPlugins\Scan\Index\SelfManifest;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class SelfManifestTest extends MonkeyTestCase {

	/** @var string ABSPATH-relative plugin directory, with a trailing slash. */
	private string $rel;

	/** @var string Absolute plugin directory, with a trailing slash. */
	private string $dir;

	protected function setUp(): void {
		parent::setUp();

		$this->rel = 'lwscan-selfmanifest-' . uniqid() . '/';
		$this->dir = ABSPATH . $this->rel;

		mkdir( $this->dir, 0755, true );
	}

	protected function tearDown(): void {
		if ( is_file( $this->dir . 'checksums.json' ) ) {
			unlink( $this->dir . 'checksums.json' );
		}

		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}

		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $manifest Manifest payload to write.
	 */
	private function write_manifest( array $manifest ): void {
		file_put_contents( $this->dir . 'checksums.json', (string) json_encode( $manifest ) );
	}

	public function test_md5_for_returns_the_listed_hash(): void {
		$this->write_manifest(
			[
				'version' => '1.0.0',
				'files'   => [ 'includes/Scanner/Heuristic/Decoders.php' => 'abc123' ],
			]
		);

		$this->assertSame( 'abc123', ( new SelfManifest( $this->dir ) )->md5_for( 'includes/Scanner/Heuristic/Decoders.php' ) );
	}

	public function test_md5_for_returns_null_for_a_path_outside_the_manifest(): void {
		$this->write_manifest(
			[
				'version' => '1.0.0',
				'files'   => [ 'lw-scan.php' => 'abc123' ],
			]
		);

		$this->assertNull( ( new SelfManifest( $this->dir ) )->md5_for( 'includes/Plugin.php' ) );
	}

	public function test_md5_for_returns_null_when_the_manifest_is_missing(): void {
		$this->assertNull( ( new SelfManifest( $this->dir ) )->md5_for( 'lw-scan.php' ) );
	}

	public function test_md5_for_returns_null_when_the_manifest_is_not_readable_json(): void {
		file_put_contents( $this->dir . 'checksums.json', 'not json at all' );

		$manifest = new SelfManifest( $this->dir );

		$this->assertNull( $manifest->md5_for( 'lw-scan.php' ) );
		$this->assertSame( '', $manifest->version() );
	}

	public function test_root_is_the_plugin_directory_relative_to_abspath(): void {
		$this->assertSame( $this->rel, ( new SelfManifest( $this->dir ) )->root() );
	}

	public function test_root_is_empty_when_the_plugin_directory_is_unknown(): void {
		$this->assertSame( '', ( new SelfManifest( '' ) )->root() );
	}

	public function test_version_comes_from_the_manifest(): void {
		$this->write_manifest(
			[
				'version' => '1.2.3',
				'files'   => [],
			]
		);

		$this->assertSame( '1.2.3', ( new SelfManifest( $this->dir ) )->version() );
	}

	public function test_non_string_entries_are_ignored(): void {
		$this->write_manifest(
			[
				'version' => '1.0.0',
				'files'   => [
					'lw-scan.php'        => [ 'abc123' ],
					'includes/Plugin.php' => 'def456',
				],
			]
		);

		$manifest = new SelfManifest( $this->dir );

		$this->assertNull( $manifest->md5_for( 'lw-scan.php' ) );
		$this->assertSame( 'def456', $manifest->md5_for( 'includes/Plugin.php' ) );
	}
}
