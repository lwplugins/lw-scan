<?php
/**
 * Tests for Vuln\Matcher.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Vuln;

use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Remote\NotFoundException;
use LightweightPlugins\Scan\Remote\VulnerabilityProvider;
use LightweightPlugins\Scan\Remote\VulnerabilityProviderInterface;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Vuln\Matcher;
use Mockery;

final class MatcherTest extends MonkeyTestCase {

	private string $cache_dir;

	protected function setUp(): void {
		parent::setUp();
		VulnerabilityProvider::reset();
		$this->cache_dir = sys_get_temp_dir() . '/lw-scan-matcher-test-' . uniqid();
	}

	protected function tearDown(): void {
		VulnerabilityProvider::reset();
		$this->remove_dir( $this->cache_dir );
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			is_dir( $file ) ? $this->remove_dir( $file ) : unlink( $file );
		}

		rmdir( $dir );
	}

	/**
	 * @return array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	private function software( string $version ): array {
		return [
			[
				'kind'    => 'plugin',
				'slug'    => 'opening-hours',
				'version' => $version,
				'name'    => "We're Open!",
				'active'  => true,
			],
		];
	}

	/**
	 * The sample record from the spec (§8/§18): one plugin software entry
	 * for `opening-hours`, patched in 1.38, affecting everything up to and
	 * including 1.37.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_record(): array {
		return [
			'id'      => '0004db27-1234-4c8e-9a11-abc123def456',
			'title'   => "We're Open! <= 1.37 - Authenticated (Administrator+) Stored Cross-Site Scripting",
			'software' => [
				[
					'name'              => "We're Open!",
					'slug'              => 'opening-hours',
					'type'              => 'plugin',
					'patched'           => true,
					'patched_versions'  => [ '1.38' ],
					'affected_versions' => [
						'*-1.37' => [
							'to_version'     => '1.37',
							'from_version'   => '*',
							'to_inclusive'   => true,
							'from_inclusive' => true,
						],
					],
				],
			],
			'published'   => '2022-09-09 00:00:00',
			'copyrights'  => [
				'defiant' => [
					'notice' => 'Disclosure provided under license by Defiant Inc.',
				],
			],
			'references'    => [ 'https://www.wordfence.com/threat-intel/vulnerabilities/id/0004db27' ],
			'informational' => false,
		];
	}

	/**
	 * @return array{0:VulnerabilityProviderInterface, 1:FindingsRepositoryInterface}
	 */
	private function mocks(): array {
		return [
			Mockery::mock( VulnerabilityProviderInterface::class ),
			Mockery::mock( FindingsRepositoryInterface::class ),
		];
	}

	public function test_matching_version_upserts_an_alert_finding_with_records_preserved(): void {
		[ $provider, $findings ] = $this->mocks();

		$provider->shouldReceive( 'for_software' )
			->once()
			->with( 'plugin', 'opening-hours' )
			->andReturn( [ $this->sample_record() ] );

		$captured = null;
		$findings->shouldReceive( 'upsert' )
			->once()
			->with(
				Mockery::on(
					function ( Finding $finding ) use ( &$captured ): bool {
						$captured = $finding;
						return true;
					}
				)
			)
			->andReturn( [ 'id' => 1, 'created' => true, 'changed' => true ] );

		$findings->shouldReceive( 'delete_vuln_not_in' )->once()->with( [ 'plugin:opening-hours' ] )->andReturn( 0 );

		$matcher = new Matcher( $provider, $findings );
		$result  = $matcher->run( $this->software( '1.37' ) );

		$this->assertSame(
			[
				'index'         => 1,
				'done'          => true,
				'lookups'       => 1,
				'findings'      => 1,
				'skipped'       => null,
				'skipped_items' => 0,
			],
			$result
		);

		$this->assertSame( 'vulnerability', $captured->type );
		$this->assertSame( 'plugin:opening-hours', $captured->locator );
		$this->assertSame( 'infected', $captured->tier );
		$this->assertSame( Severity::ALERT, $captured->severity );
		$this->assertSame( 'vulnerability', $captured->category );
		$this->assertSame( [ '0004db27-1234-4c8e-9a11-abc123def456' ], $captured->signature_ids );
		$this->assertSame(
			"We're Open! <= 1.37 - Authenticated (Administrator+) Stored Cross-Site Scripting — installed 1.37, patched in 1.38",
			$captured->reason
		);
		$this->assertSame( '1.37', $captured->meta['installed_version'] );
		$this->assertSame(
			'Disclosure provided under license by Defiant Inc.',
			$captured->meta['records'][0]['copyrights']['defiant']['notice']
		);
	}

	public function test_non_matching_version_deletes_the_existing_finding(): void {
		[ $provider, $findings ] = $this->mocks();

		$provider->shouldReceive( 'for_software' )
			->once()
			->with( 'plugin', 'opening-hours' )
			->andReturn( [ $this->sample_record() ] );

		$findings->shouldNotReceive( 'upsert' );
		$findings->shouldReceive( 'delete_by_locator' )->once()->with( 'vulnerability', 'plugin:opening-hours' )->andReturn( 1 );
		$findings->shouldReceive( 'delete_vuln_not_in' )->once()->with( [ 'plugin:opening-hours' ] )->andReturn( 0 );

		$matcher = new Matcher( $provider, $findings );
		$result  = $matcher->run( $this->software( '1.38' ) );

		$this->assertSame( 0, $result['findings'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_endpoint_missing_marks_the_run_skipped_and_stops(): void {
		// A real VulnerabilityProvider on a mocked Client, matching the
		// "mock Client, real provider" pattern in KnownGoodTest — this is
		// the only way to make the static endpoint_missing() flag flip
		// true, since it's set inside for_software()'s NotFoundException
		// handling and there's no public setter.
		$client = Mockery::mock( Client::class );
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/vulnerabilities/plugin/opening-hours' )
			->andThrow( new NotFoundException( 'not found', 404 ) );
		$provider = new VulnerabilityProvider( $client, new FileCache( $this->cache_dir ) );

		[ , $findings ] = $this->mocks();
		$findings->shouldNotReceive( 'upsert' );
		$findings->shouldNotReceive( 'delete_by_locator' );
		$findings->shouldNotReceive( 'delete_vuln_not_in' );

		$matcher = new Matcher( $provider, $findings );
		$result  = $matcher->run( $this->software( '1.37' ) );

		$this->assertSame(
			[
				'index'         => 0,
				'done'          => true,
				'lookups'       => 1,
				'findings'      => 0,
				'skipped'       => 'endpoint_unavailable',
				'skipped_items' => 0,
			],
			$result
		);
	}

	public function test_transient_provider_failure_is_counted_and_skipped_without_touching_findings(): void {
		[ $provider, $findings ] = $this->mocks();

		$provider->shouldReceive( 'for_software' )
			->once()
			->with( 'plugin', 'opening-hours' )
			->andReturn( null );

		$findings->shouldNotReceive( 'upsert' );
		$findings->shouldNotReceive( 'delete_by_locator' );
		$findings->shouldReceive( 'delete_vuln_not_in' )->once()->with( [ 'plugin:opening-hours' ] )->andReturn( 0 );

		$matcher = new Matcher( $provider, $findings );
		$result  = $matcher->run( $this->software( '1.37' ) );

		$this->assertSame( 1, $result['skipped_items'] );
		$this->assertNull( $result['skipped'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_deadline_between_items_returns_a_partial_result(): void {
		[ $provider, $findings ] = $this->mocks();

		$software = [
			$this->software( '1.37' )[0],
			[
				'kind'    => 'plugin',
				'slug'    => 'second-plugin',
				'version' => '1.0',
				'name'    => 'Second Plugin',
				'active'  => true,
			],
		];

		$provider->shouldReceive( 'for_software' )
			->once()
			->with( 'plugin', 'opening-hours' )
			->andReturn( [] );

		$findings->shouldReceive( 'delete_by_locator' )->once()->with( 'vulnerability', 'plugin:opening-hours' )->andReturn( 0 );
		$findings->shouldNotReceive( 'delete_vuln_not_in' );

		$calls = 0;
		$deadline = static function () use ( &$calls ): bool {
			++$calls;
			return true;
		};

		$matcher = new Matcher( $provider, $findings );
		$result  = $matcher->run( $software, 0, $deadline );

		$this->assertSame(
			[
				'index'         => 1,
				'done'          => false,
				'lookups'       => 1,
				'findings'      => 0,
				'skipped'       => null,
				'skipped_items' => 0,
			],
			$result
		);
	}

	public function test_resumes_from_start_index(): void {
		[ $provider, $findings ] = $this->mocks();

		$software = [
			[
				'kind'    => 'plugin',
				'slug'    => 'first-plugin',
				'version' => '1.0',
				'name'    => 'First Plugin',
				'active'  => true,
			],
			$this->software( '1.38' )[0],
		];

		$provider->shouldNotReceive( 'for_software' )->with( 'plugin', 'first-plugin' );
		$provider->shouldReceive( 'for_software' )
			->once()
			->with( 'plugin', 'opening-hours' )
			->andReturn( [ $this->sample_record() ] );

		$findings->shouldReceive( 'delete_by_locator' )->once()->with( 'vulnerability', 'plugin:opening-hours' )->andReturn( 0 );
		// The keep-set passed to delete_vuln_not_in() must cover the WHOLE
		// $software array, including index 0 ('first-plugin') which wasn't
		// looked up on this call (it belongs to an earlier tick) — not just
		// the locators visited from $start_index onward. Otherwise a
		// resumed run would wipe out findings recorded before the resume.
		$findings->shouldReceive( 'delete_vuln_not_in' )->once()->with( [ 'plugin:first-plugin', 'plugin:opening-hours' ] )->andReturn( 0 );

		$matcher = new Matcher( $provider, $findings );
		$result  = $matcher->run( $software, 1 );

		$this->assertSame( 2, $result['index'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, $result['lookups'] );
	}

	public function test_two_tick_run_prunes_using_all_software_locators_not_just_this_ticks(): void {
		[ $provider, $findings ] = $this->mocks();

		$software = [
			[
				'kind'    => 'plugin',
				'slug'    => 'first-plugin',
				'version' => '1.0',
				'name'    => 'First Plugin',
				'active'  => true,
			],
			$this->software( '1.38' )[0],
		];

		// Tick 1: item 0 is looked up and upserted, then the deadline stops
		// the run before item 1 is even attempted.
		$provider->shouldReceive( 'for_software' )
			->once()
			->with( 'plugin', 'first-plugin' )
			->andReturn( [] );

		$findings->shouldReceive( 'delete_by_locator' )->once()->with( 'vulnerability', 'plugin:first-plugin' )->andReturn( 0 );
		$findings->shouldNotReceive( 'delete_vuln_not_in' );

		$deadline_stops = static fn(): bool => true;

		$matcher = new Matcher( $provider, $findings );
		$tick1   = $matcher->run( $software, 0, $deadline_stops );

		$this->assertFalse( $tick1['done'] );
		$this->assertSame( 1, $tick1['index'] );

		// Tick 2: resumes from the cursor left by tick 1, finishes the
		// list, and only NOW calls delete_vuln_not_in — with locators for
		// BOTH items, even though only item 1 was looked up this tick.
		$provider->shouldReceive( 'for_software' )
			->once()
			->with( 'plugin', 'opening-hours' )
			->andReturn( [ $this->sample_record() ] );

		$findings->shouldReceive( 'delete_by_locator' )->once()->with( 'vulnerability', 'plugin:opening-hours' )->andReturn( 0 );
		$findings->shouldReceive( 'delete_vuln_not_in' )->once()->with( [ 'plugin:first-plugin', 'plugin:opening-hours' ] )->andReturn( 0 );

		$tick2 = $matcher->run( $software, $tick1['index'] );

		$this->assertTrue( $tick2['done'] );
		$this->assertSame( 2, $tick2['index'] );
	}
}
