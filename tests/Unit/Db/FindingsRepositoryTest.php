<?php
/**
 * Tests for FindingsRepository::merge_rows() — the pure merge logic behind
 * upsert(), exercised without any database — and for the SQL of the batch
 * locator lookup, against the recording \wpdb stand-in.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Db;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Fingerprint;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

require_once __DIR__ . '/FakeWpdb.php';

final class FindingsRepositoryTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data ) => json_encode( $data ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_existing_locators_returns_the_locators_that_already_have_a_finding(): void {
		$wpdb                  = new \wpdb();
		$wpdb->results_queue[] = [ [ 'locator_hash' => Fingerprint::of( 'file', 'b.php' ) ] ];
		$GLOBALS['wpdb']       = $wpdb;

		$found = FindingsRepository::existing_locators( 'file', [ 'a.php', 'b.php', 'c.php' ] );

		$this->assertSame( [ 'b.php' ], $found );
		$this->assertCount( 1, $wpdb->queries, 'one query for the whole batch' );
		$this->assertStringContainsString( 'locator_hash IN (%s,%s,%s)', $wpdb->prepared_queries[0] );
		$this->assertSame(
			[ [ Fingerprint::of( 'file', 'a.php' ), Fingerprint::of( 'file', 'b.php' ), Fingerprint::of( 'file', 'c.php' ) ] ],
			$wpdb->prepared_args[0]
		);
	}

	public function test_existing_locators_asks_nothing_for_an_empty_batch(): void {
		$wpdb            = new \wpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$this->assertSame( [], FindingsRepository::existing_locators( 'file', [] ) );
		$this->assertSame( [], $wpdb->queries );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function existing_row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'               => 1,
				'type'             => 'file',
				'locator'          => 'a.php',
				'tier'             => 'suspicious',
				'severity'         => Severity::REVIEW,
				'category'         => 'malware',
				'signature_ids'    => '["sig:1"]',
				'excerpt'          => 'old excerpt',
				'line'             => 1,
				'reason'           => 'old reason',
				'meta'             => '{}',
				'first_seen'       => 1000,
				'last_seen'        => 1000,
				'state'            => 'new',
				'state_changed_at' => 0,
			],
			$overrides
		);
	}

	public function test_merge_unions_signature_ids(): void {
		$existing = $this->existing_row();
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::REVIEW );
		$incoming->signature_ids = [ 'sig:1', 'sig:2' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( [ 'sig:1', 'sig:2' ], json_decode( $result['row']['signature_ids'], true ) );
		$this->assertTrue( $result['changed'] );
	}

	public function test_merge_raises_tier_to_the_higher_of_the_two(): void {
		$existing = $this->existing_row( [ 'tier' => 'suspicious', 'severity' => Severity::REVIEW ] );
		$incoming = new Finding( 'file', 'a.php', 'infected', Severity::ALERT );
		$incoming->signature_ids = [ 'sig:1' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( 'infected', $result['row']['tier'] );
		$this->assertSame( Severity::ALERT, $result['row']['severity'] );
		$this->assertTrue( $result['changed'] );
	}

	public function test_merge_keeps_the_existing_tier_when_incoming_is_lower(): void {
		$existing = $this->existing_row( [ 'tier' => 'infected', 'severity' => Severity::ALERT ] );
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::REVIEW );
		$incoming->signature_ids = [ 'sig:1' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( 'infected', $result['row']['tier'] );
		$this->assertSame( Severity::ALERT, $result['row']['severity'] );
		$this->assertFalse( $result['changed'] );
	}

	public function test_merge_takes_incoming_severity_when_tier_is_unchanged(): void {
		$existing = $this->existing_row( [ 'tier' => 'suspicious', 'severity' => Severity::REVIEW ] );
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::ALERT );
		$incoming->signature_ids = [ 'sig:1' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( 'suspicious', $result['row']['tier'] );
		$this->assertSame( Severity::ALERT, $result['row']['severity'] );
	}

	public function test_merge_keeps_existing_severity_and_tier_when_incoming_tier_is_lower(): void {
		$existing = $this->existing_row( [ 'tier' => 'infected', 'severity' => Severity::ALERT ] );
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::REVIEW );
		$incoming->signature_ids = [ 'sig:1' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( 'infected', $result['row']['tier'] );
		$this->assertSame( Severity::ALERT, $result['row']['severity'] );
	}

	public function test_ignored_finding_reopens_when_signature_set_grows(): void {
		$existing = $this->existing_row(
			[
				'state'         => 'ignored',
				'signature_ids' => '["sig:1"]',
			]
		);
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::REVIEW );
		$incoming->signature_ids = [ 'sig:1', 'sig:2' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( 'new', $result['row']['state'] );
		$this->assertSame( 2000, $result['row']['state_changed_at'] );
		$this->assertTrue( $result['reopened'] );
	}

	public function test_ignored_finding_stays_ignored_when_signature_set_is_unchanged(): void {
		$existing = $this->existing_row(
			[
				'state'         => 'ignored',
				'signature_ids' => '["sig:1"]',
			]
		);
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::REVIEW );
		$incoming->signature_ids = [ 'sig:1' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( 'ignored', $result['row']['state'] );
		$this->assertFalse( $result['reopened'] );
	}

	public function test_acknowledged_finding_stays_acknowledged(): void {
		$existing = $this->existing_row( [ 'state' => 'acknowledged' ] );
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::REVIEW );
		$incoming->signature_ids = [ 'sig:1', 'sig:2' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertSame( 'acknowledged', $result['row']['state'] );
		$this->assertFalse( $result['reopened'] );
	}

	public function test_first_seen_is_not_part_of_the_merged_row(): void {
		$existing = $this->existing_row( [ 'first_seen' => 1234 ] );
		$incoming = new Finding( 'file', 'a.php', 'suspicious', Severity::REVIEW );
		$incoming->signature_ids = [ 'sig:1' ];

		$result = FindingsRepository::merge_rows( $existing, $incoming, 2000 );

		$this->assertArrayNotHasKey( 'first_seen', $result['row'] );
	}
}
