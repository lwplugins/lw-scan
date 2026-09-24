<?php
/**
 * Tests for Autoloader.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit;

use LightweightPlugins\Scan\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {

	private function includes_dir(): string {
		return dirname( __DIR__, 2 ) . '/includes';
	}

	public function test_resolves_class_in_own_namespace_to_includes_path(): void {
		$path = Autoloader::path_for( 'LightweightPlugins\\Scan\\Foo\\Bar' );

		$this->assertSame( $this->includes_dir() . '/Foo/Bar.php', $path );
	}

	public function test_resolves_top_level_class_to_includes_path(): void {
		$path = Autoloader::path_for( 'LightweightPlugins\\Scan\\Options' );

		$this->assertSame( $this->includes_dir() . '/Options.php', $path );
	}

	public function test_ignores_foreign_namespace(): void {
		$this->assertNull( Autoloader::path_for( 'Foo\\Bar' ) );
		$this->assertNull( Autoloader::path_for( 'LightweightPlugins\\SEO\\Options' ) );
	}

	public function test_autoload_does_not_require_a_missing_file(): void {
		// A class in the plugin's namespace that does not exist on disk.
		// If this ever attempted an unconditional require(), it would fatal
		// instead of merely leaving the class undefined.
		Autoloader::autoload( 'LightweightPlugins\\Scan\\NoSuchClassAbc123' );

		$this->assertFalse( class_exists( 'LightweightPlugins\\Scan\\NoSuchClassAbc123', false ) );
	}

	public function test_autoload_is_a_silent_no_op_for_foreign_classes(): void {
		// Must not throw, warn, or otherwise misbehave for a class this
		// autoloader is not responsible for.
		Autoloader::autoload( 'Some\\Unrelated\\ClassName' );

		$this->assertTrue( true );
	}

	/**
	 * @dataProvider provide_real_classes
	 */
	public function test_real_plugin_classes_resolve_to_existing_files( string $class ): void {
		$path = Autoloader::path_for( $class );

		$this->assertNotNull( $path );
		$this->assertFileExists( $path );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function provide_real_classes(): array {
		return [
			'Plugin'                => [ 'LightweightPlugins\\Scan\\Plugin' ],
			'Options'                => [ 'LightweightPlugins\\Scan\\Options' ],
			'Activator'              => [ 'LightweightPlugins\\Scan\\Activator' ],
			'Db\\Schema'             => [ 'LightweightPlugins\\Scan\\Db\\Schema' ],
			'Run\\Runner'            => [ 'LightweightPlugins\\Scan\\Run\\Runner' ],
			'Rest\\Routes'           => [ 'LightweightPlugins\\Scan\\Rest\\Routes' ],
			'CLI\\RunCommand'        => [ 'LightweightPlugins\\Scan\\CLI\\RunCommand' ],
		];
	}
}
