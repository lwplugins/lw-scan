<?php
/**
 * Tests for Run\RemoteMeter.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run;

use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Run\RemoteMeter;
use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class RemoteMeterTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Client::reset_counters();
	}

	protected function tearDown(): void {
		Client::reset_counters();
		parent::tearDown();
	}

	public function test_record_books_the_delta_since_construction_for_every_counter(): void {
		Client::$calls     = 10;
		Client::$errors    = 4;
		Client::$not_found = 2;

		$meter = new RemoteMeter();

		Client::$calls     = 13;
		Client::$errors    = 5;
		Client::$not_found = 6;

		$stats = new RunStats();
		$meter->record( $stats );

		$remote = $stats->to_array()['remote'];

		$this->assertSame( 3, $remote['calls'] );
		$this->assertSame( 1, $remote['errors'] );
		$this->assertSame( 4, $remote['not_found'] );
	}

	public function test_record_books_zero_when_the_counters_were_reset_under_it(): void {
		Client::$calls     = 10;
		Client::$errors    = 4;
		Client::$not_found = 2;

		$meter = new RemoteMeter();

		Client::reset_counters();

		$stats = new RunStats();
		$meter->record( $stats );

		$remote = $stats->to_array()['remote'];

		$this->assertSame( 0, $remote['calls'] );
		$this->assertSame( 0, $remote['errors'] );
		$this->assertSame( 0, $remote['not_found'] );
	}
}
