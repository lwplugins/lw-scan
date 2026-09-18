<?php
/**
 * Tests for Findings\StateChange.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Findings;

use LightweightPlugins\Scan\Findings\StateChange;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class StateChangeTest extends MonkeyTestCase {

	public function test_normalize_ids_returns_an_empty_list_for_no_input(): void {
		$this->assertSame( [], StateChange::normalize_ids( [] ) );
	}

	public function test_normalize_ids_casts_numeric_strings_to_integers(): void {
		$this->assertSame( [ 7, 12 ], StateChange::normalize_ids( [ '7', '12' ] ) );
	}

	public function test_normalize_ids_drops_zero_and_unusable_values(): void {
		$this->assertSame( [ 5 ], StateChange::normalize_ids( [ 0, 'abc', '', 5, null ] ) );
	}

	public function test_normalize_ids_removes_duplicates_and_reindexes(): void {
		$this->assertSame( [ 3, 9 ], StateChange::normalize_ids( [ 3, 9, 3 ] ) );
	}

	public function test_normalize_ids_folds_a_negative_id_to_its_absolute_value(): void {
		$this->assertSame( [ 4 ], StateChange::normalize_ids( [ -4 ] ) );
	}
}
