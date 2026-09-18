<?php
/**
 * Tests for Scanner\Heuristic\DisguiseSignals.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner\Heuristic;

use LightweightPlugins\Scan\Scanner\Heuristic\DisguiseSignals;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class DisguiseSignalsTest extends MonkeyTestCase {

	/**
	 * @return array<int,string>
	 */
	private function reasons( string $code, string $origin, bool $known_good = false ): array {
		return array_column( DisguiseSignals::analyze( $code, $origin, $known_good ), 'reason' );
	}

	private function fixture( string $name ): string {
		$path = dirname( __DIR__, 3 ) . '/Fixtures/heuristic/' . $name;
		$code = file_get_contents( $path );

		$this->assertIsString( $code, $name . ' must be readable' );

		return (string) $code;
	}

	public function test_a_plugin_header_inside_uploads_is_flagged(): void {
		$reasons = $this->reasons( $this->fixture( 'disguise-uploads.php' ), 'uploads' );

		$this->assertContains( 'plugin header in an uploads file', $reasons );
	}

	public function test_the_same_file_in_a_plugin_directory_is_not_flagged(): void {
		$this->assertSame( [], $this->reasons( $this->fixture( 'disguise-uploads.php' ), 'plugin:cache-helper' ) );
	}

	public function test_findings_carry_the_line_of_the_header(): void {
		$findings = DisguiseSignals::analyze( $this->fixture( 'disguise-uploads.php' ), 'uploads', false );

		$this->assertSame( 3, $findings[0]['line'] );
	}

	public function test_a_plugin_header_in_mu_plugins_is_flagged_when_the_file_is_not_known_good(): void {
		$code = "<?php\n/* Plugin Name: Loader */\n\$x = 1;\n";

		$this->assertContains( 'plugin header in a must-use plugin', $this->reasons( $code, 'mu' ) );
	}

	public function test_a_known_good_mu_plugin_is_not_flagged(): void {
		$code = "<?php\n/* Plugin Name: Loader */\n\$x = 1;\n";

		$this->assertSame( [], $this->reasons( $code, 'mu', true ) );
	}

	public function test_an_mu_plugin_loader_that_includes_a_subdirectory_is_not_flagged(): void {
		$code = "<?php\n/* Plugin Name: MU Loader */\nrequire_once __DIR__ . '/my-plugin/my-plugin.php';\n";

		$this->assertSame( [], $this->reasons( $code, 'mu' ) );
	}

	public function test_package_wordpress_outside_core_is_flagged(): void {
		$code = "<?php\n/**\n * @package WordPress\n */\n";

		$this->assertContains( '@package WordPress outside core', $this->reasons( $code, 'uploads' ) );
	}

	public function test_package_wordpress_in_a_theme_or_plugin_is_not_flagged(): void {
		$code = "<?php\n/**\n * @package WordPress\n */\n";

		$this->assertSame( [], $this->reasons( $code, 'theme:twentytwentyfive' ) );
		$this->assertSame( [], $this->reasons( $code, 'plugin:akismet' ) );
	}

	public function test_package_wordpress_in_an_unclassified_file_is_flagged(): void {
		$code = "<?php\n/**\n * @package WordPress\n */\n";

		$this->assertContains( '@package WordPress outside core', $this->reasons( $code, 'other' ) );
	}

	public function test_package_wordpress_in_core_is_not_flagged(): void {
		$code = "<?php\n/**\n * @package WordPress\n */\n";

		$this->assertSame( [], $this->reasons( $code, 'core' ) );
	}

	public function test_the_encoder_header_family_is_flagged(): void {
		$framework = "<?php\n/* Advanced Web Application Framework */\n\$x = 1;\n";
		$sizes     = "<?php\n/* Original size: 4096 */\n/* Encoded size: 9001 */\n";

		$this->assertContains( 'encoder header: Advanced Web Application Framework', $this->reasons( $framework, 'plugin:x' ) );
		$this->assertContains( 'encoder header: Original size:', $this->reasons( $sizes, 'plugin:x' ) );
	}

	public function test_the_encoder_header_is_only_looked_for_near_the_top_of_the_file(): void {
		$code = "<?php\n" . str_repeat( "\$x = 1;\n", 40 ) . "/* Original size: 4096 */\n";

		$this->assertSame( [], $this->reasons( $code, 'plugin:x' ) );
	}

	public function test_silenced_errors_plus_request_input_are_flagged(): void {
		$code = "<?php\nerror_reporting(0);\n@ini_set('display_errors', 0);\n\$c = \$_POST['c'];\necho \$c;\n";

		$this->assertContains( 'errors silenced at the top of a file that reads request input', $this->reasons( $code, 'plugin:x' ) );
	}

	public function test_silenced_errors_without_request_input_are_not_flagged(): void {
		$code = "<?php\nerror_reporting(0);\n@ini_set('display_errors', 0);\necho 'hello';\n";

		$this->assertSame( [], $this->reasons( $code, 'plugin:x' ) );
	}

	public function test_silenced_errors_below_the_fifth_line_are_not_flagged(): void {
		$code = "<?php\n\$a = 1;\n\$b = 2;\n\$c = 3;\n\$d = 4;\n\$e = 5;\nerror_reporting(0);\n@ini_set('display_errors', 0);\n\$f = \$_POST['c'];\n";

		$this->assertSame( [], $this->reasons( $code, 'plugin:x' ) );
	}

	public function test_an_ordinary_plugin_file_produces_no_findings(): void {
		$this->assertSame( [], $this->reasons( $this->fixture( 'clean-plugin.php' ), 'plugin:example' ) );
	}
}
