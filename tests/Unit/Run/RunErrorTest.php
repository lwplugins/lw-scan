<?php
/**
 * Tests for Run\RunError.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Run\RunError;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

/**
 * The run table stores machine codes; these are the sentences they turn
 * into for the Scan tab and for WP-CLI.
 */
final class RunErrorTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		Functions\stubTranslationFunctions();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ): string {
				return $bytes . ' B';
			}
		);
	}

	public function test_an_empty_error_stays_empty(): void {
		$this->assertSame( '', RunError::label( '' ) );
	}

	public function test_an_unknown_code_is_passed_through_unchanged(): void {
		$message = 'Allowed memory size of 268435456 bytes exhausted';

		$this->assertSame( $message, RunError::label( $message ) );
	}

	public function test_the_bundle_codes_explain_what_happened_to_the_signatures(): void {
		$changed = RunError::label( 'bundle_changed' );

		$this->assertSame( $changed, RunError::label( 'bundle_changed_mid_run' ) );
		$this->assertStringContainsString( 'signature', $changed );
		$this->assertStringNotContainsString( '_', $changed );
	}

	public function test_a_missing_bundle_says_what_to_do_about_it(): void {
		$label = RunError::label( 'bundle_missing' );

		$this->assertStringContainsString( 'ignature', $label );
		$this->assertStringNotContainsString( '_', $label );
	}

	public function test_a_missing_bundle_names_both_places_to_look(): void {
		// The first scan on a fresh install is what downloads the bundle, so
		// this code now reaches people who have never seen a bundle at all:
		// the remedy has to point at the Health tab AND at the connection
		// that could not deliver one.
		$label = RunError::label( 'bundle_missing' );

		$this->assertStringContainsString( 'Health tab', $label );
		$this->assertStringContainsString( 'backend connection', $label );
	}

	public function test_out_of_memory_reports_the_limit_it_failed_at(): void {
		$label = RunError::label( 'out_of_memory', [ 'memory_limit' => 268435456 ] );

		$this->assertStringContainsString( '268435456 B', $label );
		$this->assertStringContainsString( 'Raise memory_limit', $label );
		$this->assertStringContainsString( 'WP-CLI', $label );
	}

	public function test_out_of_memory_stays_general_without_a_stored_limit(): void {
		// A run that failed before `Runner::work()` recorded the limit —
		// or an old row from before this stat existed — gets the sentence
		// without a number to make up.
		$label = RunError::label( 'out_of_memory' );

		$this->assertStringContainsString( 'ran out of memory', $label );
		$this->assertStringContainsString( 'WP-CLI', $label );
		$this->assertStringNotContainsString( ' B', $label );
	}

	public function test_an_old_stored_memory_code_is_passed_through_unchanged(): void {
		// `memory_too_low` was the pre-flight refusal's code; a run row
		// written before this release still has it, and it now falls
		// through to the generic default rather than being spelled out.
		$this->assertSame( 'memory_too_low', RunError::label( 'memory_too_low' ) );
	}
}
