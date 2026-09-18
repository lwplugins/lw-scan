<?php
/**
 * Tests for the Finding value object.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Findings;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Fingerprint;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class FindingTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
	}

	public function test_to_row_encodes_json_fields_and_sets_locator_hash(): void {
		$finding                 = new Finding( 'file', 'wp-content/x.php', 'infected', Severity::ALERT );
		$finding->signature_ids  = [ 'sig:1', 'sig:2' ];
		$finding->meta           = [ 'foo' => 'bar' ];

		$row = $finding->to_row( 1000 );

		$this->assertSame( 'file', $row['type'] );
		$this->assertSame( 'wp-content/x.php', $row['locator'] );
		$this->assertSame( Fingerprint::of( 'file', 'wp-content/x.php' ), $row['locator_hash'] );
		$this->assertSame( '["sig:1","sig:2"]', $row['signature_ids'] );
		$this->assertSame( '{"foo":"bar"}', $row['meta'] );
	}

	public function test_to_row_sets_first_seen_and_last_seen_to_now(): void {
		$finding = new Finding( 'file', 'a.php', 'infected', Severity::ALERT );

		$row = $finding->to_row( 5000 );

		$this->assertSame( 5000, $row['first_seen'] );
		$this->assertSame( 5000, $row['last_seen'] );
	}

	public function test_from_row_decodes_json_and_scalar_fields(): void {
		$row = [
			'type'          => 'file',
			'locator'       => 'a.php',
			'tier'          => 'infected',
			'severity'      => 'alert',
			'file_id'       => '5',
			'signature_ids' => '["sig:1"]',
			'category'      => 'malware',
			'excerpt'       => 'eval(',
			'line'          => '10',
			'reason'        => 'matched sig:1',
			'meta'          => '{"foo":"bar"}',
			'state'         => 'acknowledged',
		];

		$finding = Finding::from_row( $row );

		$this->assertSame( 'file', $finding->type );
		$this->assertSame( 5, $finding->file_id );
		$this->assertSame( [ 'sig:1' ], $finding->signature_ids );
		$this->assertSame( [ 'foo' => 'bar' ], $finding->meta );
		$this->assertSame( 10, $finding->line );
		$this->assertSame( 'acknowledged', $finding->state );
	}
}
