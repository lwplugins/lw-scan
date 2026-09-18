<?php
/**
 * Tests for Scanner\Heuristic\SourceSinkAnalyzer.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Scanner\Heuristic;

use LightweightPlugins\Scan\Scanner\Heuristic\SourceSinkAnalyzer;
use LightweightPlugins\Scan\Scanner\Heuristic\TokenStream;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class SourceSinkAnalyzerTest extends MonkeyTestCase {

	/**
	 * @return array<int,string>
	 */
	private function reasons( string $code ): array {
		return array_column( SourceSinkAnalyzer::analyze( new TokenStream( $code ) ), 'reason' );
	}

	private function fixture( string $name ): string {
		$path = dirname( __DIR__, 3 ) . '/Fixtures/heuristic/' . $name;
		$code = file_get_contents( $path );

		$this->assertIsString( $code, $name . ' must be readable' );

		return (string) $code;
	}

	public function test_the_file_manager_webshell_fixture_is_caught(): void {
		$reasons = $this->reasons( $this->fixture( 'source-sink.php' ) );

		$this->assertContains( "\$_GET['path'] → unlink() (line 13)", $reasons );
		$this->assertContains( "\$_GET['path'] → file_put_contents() (line 17)", $reasons );
	}

	public function test_input_reaching_a_sink_through_a_parameter_is_attributed(): void {
		// `go( $p, $a )` hands top-level input to the function that does the
		// deleting — the shape every file-manager shell has. One hop of
		// parameter taint is what connects the two.
		$reasons = $this->reasons( $this->fixture( 'source-sink.php' ) );

		$this->assertContains( "\$_GET['path'] → unlink() (line 13)", $reasons );
	}

	public function test_an_ordinary_plugin_file_produces_no_findings(): void {
		$this->assertSame( [], SourceSinkAnalyzer::analyze( new TokenStream( $this->fixture( 'clean-plugin.php' ) ) ) );
	}

	public function test_findings_carry_the_line_of_the_sink(): void {
		$findings = SourceSinkAnalyzer::analyze( new TokenStream( $this->fixture( 'source-sink.php' ) ) );

		foreach ( $findings as $finding ) {
			$this->assertArrayHasKey( 'reason', $finding );
			$this->assertArrayHasKey( 'line', $finding );
			$this->assertStringContainsString( '(line ' . $finding['line'] . ')', $finding['reason'] );
		}
	}

	public function test_a_source_used_directly_in_a_sink_argument_is_caught(): void {
		$reasons = $this->reasons( "<?php\nsystem( \$_GET['cmd'] );\n" );

		$this->assertSame( [ "\$_GET['cmd'] → system() (line 2)" ], $reasons );
	}

	public function test_an_untainted_variable_in_a_sink_is_not_flagged(): void {
		$this->assertSame( [], $this->reasons( "<?php
\$p = '/tmp/x';
unlink( \$p );
" ) );
	}

	public function test_update_option_and_wp_mail_are_not_sinks(): void {
		$code = "<?php\n\$t = \$_POST['t'];\nupdate_option( 'x', \$t );\nwp_mail( 'a@b.c', \$t, \$t );\necho esc_html( \$t );\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_include_of_a_tainted_variable_is_caught(): void {
		$reasons = $this->reasons( "<?php\n\$f = \$_GET['f'];\ninclude \$f;\n" );

		$this->assertSame( [ "\$_GET['f'] → include() (line 3)" ], $reasons );
	}

	public function test_a_constant_include_is_not_flagged(): void {
		$this->assertSame( [], $this->reasons( "<?php\ninclude __DIR__ . '/x.php';\nrequire_once dirname( __FILE__ ) . '/y.php';\n" ) );
	}

	public function test_preg_replace_with_the_e_modifier_is_flagged_without_a_source(): void {
		$reasons = $this->reasons( "<?php\n\$out = preg_replace( '/(.*)/e', 'strtoupper(\"\\\\1\")', \$in );\n" );

		$this->assertSame( [ 'preg_replace() /e modifier (line 2)' ], $reasons );
	}

	public function test_preg_replace_without_the_e_modifier_is_not_flagged(): void {
		$this->assertSame( [], $this->reasons( "<?php\n\$out = preg_replace( '/(.*)/i', 'x', \$in );\n" ) );
	}

	public function test_a_dynamic_call_through_a_tainted_variable_is_caught(): void {
		$reasons = $this->reasons( "<?php\n\$fn = \$_REQUEST['fn'];\n\$fn( 'id' );\n" );

		$this->assertSame( [ "\$_REQUEST['fn'] → \$fn() (line 3)" ], $reasons );
	}

	public function test_a_variable_variable_built_from_a_source_is_caught(): void {
		$reasons = $this->reasons( "<?php\n\$v = \$_COOKIE['v'];\n\${\$v} = 1;\n" );

		$this->assertSame( [ "\$_COOKIE['v'] → \${...} (line 3)" ], $reasons );
	}

	public function test_php_input_and_getenv_count_as_sources(): void {
		$stream = $this->reasons( "<?php\n\$d = file_get_contents( 'php://input' );\neval( \$d );\n" );
		$env    = $this->reasons( "<?php\n\$e = getenv( 'HTTP_X' );\npassthru( \$e );\n" );

		$this->assertSame( [ "php://input → eval() (line 3)" ], $stream );
		$this->assertSame( [ 'getenv → passthru() (line 3)' ], $env );
	}

	public function test_a_method_call_named_like_a_sink_is_not_flagged(): void {
		$this->assertSame( [], $this->reasons( "<?php\n\$fs = new FS();\n\$fs->unlink( \$_GET['p'] );\nFS::copy( \$_GET['p'] );\n" ) );
	}

	public function test_only_one_finding_per_sink_call_site(): void {
		$findings = SourceSinkAnalyzer::analyze( new TokenStream( $this->fixture( 'source-sink.php' ) ) );
		$sites    = [];

		foreach ( $findings as $finding ) {
			$sites[] = $finding['line'];
		}

		$this->assertSame( array_values( array_unique( $sites ) ), $sites );
	}

	public function test_unparsable_code_yields_no_findings_instead_of_an_error(): void {
		$this->assertSame( [], SourceSinkAnalyzer::analyze( new TokenStream( '' ) ) );
	}

	public function test_plain_preg_replace_on_request_input_is_not_a_sink(): void {
		$code = "<?php\n\$clean = preg_replace( '/\\?.*/', '', \$_SERVER['REQUEST_URI'] );\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_call_user_func_with_a_clean_callback_is_not_a_sink(): void {
		$code = "<?php\n\$cb = 'my_handler';\ncall_user_func( \$cb, \$_POST );\ncall_user_func_array( \$cb, array( \$_GET ) );\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_call_user_func_with_a_source_as_the_callback_is_a_sink(): void {
		$reasons = $this->reasons( "<?php\ncall_user_func( \$_POST['f'] );\n" );

		$this->assertSame( [ "\$_POST['f'] → call_user_func() (line 2)" ], $reasons );
	}

	public function test_call_user_func_with_a_tainted_callback_is_a_sink(): void {
		$reasons = $this->reasons( "<?php\n\$t = \$_GET['f'];\ncall_user_func( \$t, 'x' );\n" );

		$this->assertSame( [ "\$_GET['f'] → call_user_func() (line 3)" ], $reasons );
	}

	public function test_taint_propagates_through_concatenating_assignment(): void {
		$reasons = $this->reasons( "<?php\n\$cmd = 'ls ';\n\$cmd .= \$_GET['dir'];\nsystem( \$cmd );\n" );

		$this->assertSame( [ "\$_GET['dir'] → system() (line 4)" ], $reasons );
	}

	public function test_chained_assignments_in_one_statement_all_become_tainted(): void {
		$reasons = $this->reasons( "<?php\n\$a = \$b = \$_GET['x'];\nunlink( \$a );\nsystem( \$b );\n" );

		$this->assertContains( "\$_GET['x'] → unlink() (line 3)", $reasons );
		$this->assertContains( "\$_GET['x'] → system() (line 4)", $reasons );
	}

	public function test_eight_thousand_chained_assignments_stay_linear(): void {
		$code = "<?php\n\$a0 = \$_GET['x'];\n";

		for ( $i = 1; $i <= 8000; $i++ ) {
			$code .= '$a' . $i . ' = $a' . ( $i - 1 ) . ";\n";
		}

		$code .= "unlink( \$a8000 );\n";

		$started  = microtime( true );
		$findings = SourceSinkAnalyzer::analyze( new TokenStream( $code ) );
		$elapsed  = microtime( true ) - $started;

		$this->assertCount( 1, $findings );
		$this->assertLessThan( 1.0, $elapsed, sprintf( '8000 chained assignments took %.2fs', $elapsed ) );
	}

	public function test_two_thousand_nested_sink_calls_stay_linear(): void {
		$code = "<?php\n\$p = \$_GET['p'];\n" . str_repeat( "copy(\n", 2000 ) . '$p' . str_repeat( "\n)", 2000 ) . ";\n";

		$started  = microtime( true );
		$findings = SourceSinkAnalyzer::analyze( new TokenStream( $code ) );
		$elapsed  = microtime( true ) - $started;

		$this->assertCount( 2000, $findings, 'one finding per nested call site' );
		$this->assertLessThan( 1.0, $elapsed, sprintf( '2000 nested copy() calls took %.2fs', $elapsed ) );
	}

	public function test_a_guarded_one_line_assignment_is_still_tainted(): void {
		$code = "<?php\nif ( isset( \$_GET['c'] ) ) \$cmd = \$_GET['c'];\nsystem( \$cmd );\n";

		$this->assertSame( [ "\$_GET['c'] → system() (line 3)" ], $this->reasons( $code ) );
	}

	public function test_a_guarded_braced_assignment_is_still_tainted(): void {
		$code = "<?php\nif ( isset( \$_GET['c'] ) ) {\n\t\$cmd = \$_GET['c'];\n}\nsystem( \$cmd );\n";

		$this->assertSame( [ "\$_GET['c'] → system() (line 5)" ], $this->reasons( $code ) );
	}

	public function test_a_ternary_guarded_assignment_is_still_tainted(): void {
		$code = "<?php\n\$cmd = isset( \$_GET['c'] ) ? \$_GET['c'] : 'ls';\nsystem( \$cmd );\n";

		$this->assertSame( [ "\$_GET['c'] → system() (line 3)" ], $this->reasons( $code ) );
	}

	public function test_an_assignment_before_the_only_input_is_not_tainted(): void {
		$code = "<?php\n\$safe = 'ls';\necho \$_GET['c'];\nsystem( \$safe );\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_taint_does_not_leak_between_two_function_bodies(): void {
		$code = "<?php\nfunction a() {\n\t\$x = \$_GET['x'];\n}\nfunction b() {\n\tinclude \$x;\n}\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_top_level_taint_does_not_reach_into_a_function_body(): void {
		$code = "<?php\n\$x = \$_GET['x'];\nfunction b() {\n\tinclude \$x;\n}\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_top_level_taint_still_reaches_a_top_level_sink(): void {
		$code = "<?php\n\$x = \$_GET['x'];\ninclude \$x;\n";

		$this->assertSame( [ "\$_GET['x'] → include() (line 3)" ], $this->reasons( $code ) );
	}

	public function test_top_level_code_on_both_sides_of_a_function_is_one_scope(): void {
		$code = "<?php\n\$x = \$_GET['x'];\nfunction b() {\n\t\$x = 'safe';\n}\nunlink( \$x );\n";

		$this->assertSame( [ "\$_GET['x'] → unlink() (line 6)" ], $this->reasons( $code ) );
	}

	public function test_a_call_carries_input_into_the_parameter_that_receives_it(): void {
		$code = "<?php\n\$p = \$_GET['path'];\ngo( \$p, \$_GET['action'] );\nfunction go( \$p, \$a ) {\n\tif ( \$a === 'del' ) {\n\t\tunlink( \$p );\n\t}\n}\n";

		$this->assertContains( "\$_GET['path'] → unlink() (line 6)", $this->reasons( $code ) );
	}

	public function test_a_source_passed_straight_into_a_call_taints_the_parameter(): void {
		$code = "<?php\ngo( \$_GET['x'] );\nfunction go( \$p ) {\n\teval( \$p );\n}\n";

		$this->assertSame( [ "\$_GET['x'] → eval() (line 4)" ], $this->reasons( $code ) );
	}

	public function test_a_static_argument_does_not_taint_the_parameter(): void {
		$code = "<?php\ngo( 'static' );\nfunction go( \$p ) {\n\teval( \$p );\n}\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_input_is_carried_into_the_matching_position_only(): void {
		$code = "<?php\ngo( 'safe', \$_GET['x'] );\nfunction go( \$first, \$second ) {\n\tunlink( \$first );\n\teval( \$second );\n}\n";

		$this->assertSame( [ "\$_GET['x'] → eval() (line 5)" ], $this->reasons( $code ) );
	}

	public function test_parameter_taint_travels_one_hop_only(): void {
		$code = "<?php\nouter( \$_GET['x'] );\nfunction outer( \$a ) {\n\tinner( \$a );\n}\nfunction inner( \$b ) {\n\teval( \$b );\n}\n";

		$reasons = $this->reasons( $code );

		$this->assertNotContains( "\$_GET['x'] → eval() (line 7)", $reasons, 'a seeded parameter must not seed another call' );
	}

	public function test_a_named_argument_is_not_mapped_positionally(): void {
		$code = "<?php\ngo( second: \$_GET['x'] );\nfunction go( \$first, \$second ) {\n\tunlink( \$first );\n}\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}

	public function test_two_thousand_calls_into_one_function_stay_linear(): void {
		$code = "<?php\n\$p = \$_GET['p'];\nfunction go( \$q ) {\n\tunlink( \$q );\n}\n" . str_repeat( "go( \$p );\n", 2000 );

		$started  = microtime( true );
		$findings = SourceSinkAnalyzer::analyze( new TokenStream( $code ) );
		$elapsed  = microtime( true ) - $started;

		$this->assertCount( 1, $findings );
		$this->assertSame( "\$_GET['p'] → unlink() (line 4)", $findings[0]['reason'] );
		$this->assertLessThan( 1.0, $elapsed, sprintf( '2000 calls took %.2fs', $elapsed ) );
	}

	public function test_an_arrow_function_body_shares_the_enclosing_scope(): void {
		// Arrow functions capture by value, so the enclosing taint applies.
		$code = "<?php\n\$p = \$_GET['p'];\n\$f = fn() => unlink( \$p );\n";

		$this->assertSame( [ "\$_GET['p'] → unlink() (line 3)" ], $this->reasons( $code ) );
	}

	public function test_a_closure_body_does_not_inherit_the_enclosing_scope(): void {
		// A closure has to import explicitly, so its body is its own scope.
		$code = "<?php\n\$p = \$_GET['p'];\n\$f = function () {\n\tunlink( \$p );\n};\n";

		$this->assertSame( [], $this->reasons( $code ) );
	}
}
