<?php
/**
 * Tests for Index\KnownGood.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Index;

use LightweightPlugins\Scan\Index\KnownGood;
use LightweightPlugins\Scan\Index\SelfManifest;
use LightweightPlugins\Scan\Remote\ChecksumProvider;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Remote\UnavailableException;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use Mockery;

final class KnownGoodTest extends MonkeyTestCase {

	private string $dir;

	/** @var array<int,string> Throwaway plugin directories created by self_manifest(). */
	private array $self_dirs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->dir       = sys_get_temp_dir() . '/lw-scan-test-' . uniqid();
		$this->self_dirs = [];
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->dir );

		foreach ( $this->self_dirs as $dir ) {
			$this->remove_dir( $dir );
		}

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
	 * A ChecksumProvider is `final`, so it can't be Mockery-mocked directly
	 * (matches the style in tests/Unit/Remote/ChecksumProviderTest.php):
	 * a real ChecksumProvider is built on top of a mocked Client + a
	 * throwaway FileCache, giving full control over its responses.
	 *
	 * @return array{0:ChecksumProvider, 1:Client}
	 */
	private function checksum_provider(): array {
		$client = Mockery::mock( Client::class );

		return [ new ChecksumProvider( $client, new FileCache( $this->dir ) ), $client ];
	}

	/**
	 * @return array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	private function software(): array {
		return [
			[
				'kind'    => 'core',
				'slug'    => 'wordpress',
				'version' => '6.6.2',
				'name'    => 'WordPress',
				'active'  => true,
			],
			[
				'kind'    => 'plugin',
				'slug'    => 'akismet',
				'version' => '5.3',
				'name'    => 'Akismet',
				'active'  => true,
			],
			[
				'kind'    => 'theme',
				'slug'    => 'twentytwentyfive',
				'version' => '1.2',
				'name'    => 'Twenty Twenty-Five',
				'active'  => true,
			],
		];
	}

	/**
	 * @return array{status:string, expected:string, package:string, version:string}
	 */
	private function unknown_result(): array {
		return [
			'status'   => 'unknown',
			'expected' => '',
			'package'  => '',
			'version'  => '',
		];
	}

	/**
	 * Writes a self-manifest into a throwaway directory under ABSPATH and
	 * returns [ SelfManifest, ABSPATH-relative plugin dir ].
	 *
	 * @param array<string,string> $files Plugin-relative path => md5.
	 * @return array{0:SelfManifest, 1:string}
	 */
	private function self_manifest( array $files ): array {
		$rel = 'lwscan-knowngood-' . uniqid() . '/';
		$dir = ABSPATH . $rel;

		mkdir( $dir, 0755, true );

		file_put_contents(
			$dir . 'checksums.json',
			(string) json_encode(
				[
					'version' => '9.9.9',
					'files'   => $files,
				]
			)
		);

		$this->self_dirs[] = $dir;

		return [ new SelfManifest( $dir ), $rel ];
	}

	public function test_a_file_listed_in_the_plugins_own_manifest_is_known_good(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldNotReceive( 'get' );

		[ $manifest, $rel ] = $this->self_manifest( [ 'includes/Scanner/Heuristic/Decoders.php' => 'abc123' ] );

		$known_good = new KnownGood( $checksums, $this->software(), $manifest );

		// The decoder implementations necessarily contain the strings the
		// signatures look for, so without this the scanner alerts on itself.
		$this->assertSame(
			[
				'status'   => 'match',
				'expected' => 'abc123',
				'package'  => 'self',
				'version'  => '9.9.9',
			],
			$known_good->check( $rel . 'includes/Scanner/Heuristic/Decoders.php', 'plugin:lw-scan', 'abc123' )
		);
	}

	public function test_a_tampered_plugin_file_is_not_known_good(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldNotReceive( 'get' );

		[ $manifest, $rel ] = $this->self_manifest( [ 'includes/Scanner/Heuristic/Decoders.php' => 'abc123' ] );

		$known_good = new KnownGood( $checksums, $this->software(), $manifest );

		$this->assertSame(
			$this->unknown_result(),
			$known_good->check( $rel . 'includes/Scanner/Heuristic/Decoders.php', 'plugin:lw-scan', 'tampered' )
		);
	}

	public function test_a_plugin_file_missing_from_the_manifest_falls_through(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldNotReceive( 'get' );

		[ $manifest, $rel ] = $this->self_manifest( [ 'lw-scan.php' => 'abc123' ] );

		$known_good = new KnownGood( $checksums, $this->software(), $manifest );

		$this->assertSame(
			$this->unknown_result(),
			$known_good->check( $rel . 'vendor/composer/autoload_real.php', 'plugin:lw-scan', 'abc123' )
		);
	}

	public function test_another_plugins_file_never_consults_the_self_manifest(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/plugin/akismet/5.3' )
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"akismet.php":{"md5":"deadbeef"}}}', 'headers' => [] ] );

		[ $manifest ] = $this->self_manifest( [ 'akismet.php' => 'abc123' ] );

		$known_good = new KnownGood( $checksums, $this->software(), $manifest );

		$this->assertSame(
			'match',
			$known_good->check( 'wp-content/plugins/akismet/akismet.php', 'plugin:akismet', 'deadbeef' )['status']
		);
	}

	public function test_core_match_when_md5_equals_checksum(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/core/6.6.2' )
			->andReturn( [ 'code' => 200, 'body' => '{"checksums":{"wp-login.php":"abc123"}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			[
				'status'   => 'match',
				'expected' => 'abc123',
				'package'  => 'core',
				'version'  => '6.6.2',
			],
			$known_good->check( 'wp-login.php', 'core', 'abc123' )
		);
	}

	public function test_core_mismatch_when_md5_differs(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"checksums":{"wp-login.php":"abc123"}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			[
				'status'   => 'mismatch',
				'expected' => 'abc123',
				'package'  => 'core',
				'version'  => '6.6.2',
			],
			$known_good->check( 'wp-login.php', 'core', 'deadbeef' )
		);
	}

	public function test_core_unknown_when_path_not_in_list(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"checksums":{"wp-login.php":"abc123"}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame( $this->unknown_result(), $known_good->check( 'wp-includes/random.php', 'core', 'abc123' ) );
	}

	public function test_core_unknown_when_list_unavailable(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )->once()->andThrow( new UnavailableException( 'down', 503 ) );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame( $this->unknown_result(), $known_good->check( 'wp-login.php', 'core', 'abc123' ) );
		$this->assertNull( $known_good->core_paths() );
	}

	/**
	 * @dataProvider provide_never_checksummed_paths
	 */
	public function test_never_checksummed_paths_are_always_unknown( string $rel, string $origin ): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldNotReceive( 'get' );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame( $this->unknown_result(), $known_good->check( $rel, $origin, 'anything' ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function provide_never_checksummed_paths(): array {
		return [
			'wp-config.php'          => [ 'wp-config.php', 'core' ],
			'root .htaccess'         => [ '.htaccess', 'other' ],
			'direct wp-content file' => [ 'wp-content/robots.txt', 'other' ],
		];
	}

	public function test_plugin_match(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/plugin/akismet/5.3' )
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"akismet.php":{"md5":"deadbeef"}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			[
				'status'   => 'match',
				'expected' => 'deadbeef',
				'package'  => 'plugin:akismet',
				'version'  => '5.3',
			],
			$known_good->check( 'wp-content/plugins/akismet/akismet.php', 'plugin:akismet', 'deadbeef' )
		);
	}

	public function test_core_match_on_any_listed_checksum(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"checksums":{"readme.html":["abc123","def456"]}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			[
				'status'   => 'match',
				'expected' => 'abc123',
				'package'  => 'core',
				'version'  => '6.6.2',
			],
			$known_good->check( 'readme.html', 'core', 'def456' )
		);
	}

	public function test_plugin_match_on_the_second_listed_checksum(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"readme.txt":{"md5":["aaa111","bbb222"]}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			'match',
			$known_good->check( 'wp-content/plugins/akismet/readme.txt', 'plugin:akismet', 'bbb222' )['status']
		);
	}

	public function test_plugin_mismatch_when_no_listed_checksum_matches(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"readme.txt":{"md5":["aaa111","bbb222"]}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			[
				'status'   => 'mismatch',
				'expected' => 'aaa111',
				'package'  => 'plugin:akismet',
				'version'  => '5.3',
			],
			$known_good->check( 'wp-content/plugins/akismet/readme.txt', 'plugin:akismet', 'tampered' )
		);
	}

	public function test_unverifiable_checksum_entry_is_unknown_not_mismatch(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"akismet.php":{"md5":12345}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			$this->unknown_result(),
			$known_good->check( 'wp-content/plugins/akismet/akismet.php', 'plugin:akismet', 'deadbeef' )
		);
	}

	public function test_plugin_mismatch(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"akismet.php":{"md5":"deadbeef"}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame( 'mismatch', $known_good->check( 'wp-content/plugins/akismet/akismet.php', 'plugin:akismet', 'other' )['status'] );
	}

	public function test_plugin_file_not_in_checksum_list_is_unknown_not_mismatch(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"akismet.php":{"md5":"deadbeef"}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			$this->unknown_result(),
			$known_good->check( 'wp-content/plugins/akismet/build/bundle.js', 'plugin:akismet', 'whatever' )
		);
	}

	public function test_plugin_unknown_when_slug_not_installed(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldNotReceive( 'get' );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			$this->unknown_result(),
			$known_good->check( 'wp-content/plugins/ghost/ghost.php', 'plugin:ghost', 'whatever' )
		);
	}

	public function test_theme_match(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/theme/twentytwentyfive/1.2' )
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"style.css":{"md5":"cafef00d"}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame(
			[
				'status'   => 'match',
				'expected' => 'cafef00d',
				'package'  => 'theme:twentytwentyfive',
				'version'  => '1.2',
			],
			$known_good->check( 'wp-content/themes/twentytwentyfive/style.css', 'theme:twentytwentyfive', 'cafef00d' )
		);
	}

	public function test_unrelated_origin_is_unknown_without_querying(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldNotReceive( 'get' );

		$known_good = new KnownGood( $checksums, $this->software() );

		$this->assertSame( $this->unknown_result(), $known_good->check( 'wp-content/mu-plugins/loader.php', 'mu', 'whatever' ) );
		$this->assertSame( $this->unknown_result(), $known_good->check( 'wp-content/uploads/2026/a.jpg', 'uploads', 'whatever' ) );
	}

	public function test_core_paths_is_memoized_across_calls(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->andReturn( [ 'code' => 200, 'body' => '{"checksums":{"wp-login.php":"abc123"}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$known_good->check( 'wp-login.php', 'core', 'abc123' );
		$known_good->check( 'wp-login.php', 'core', 'abc123' );

		$this->assertSame( [ 'wp-login.php' => [ 'abc123' ] ], $known_good->core_paths() );
	}

	public function test_package_list_is_memoized_per_slug_and_version(): void {
		[ $checksums, $client ] = $this->checksum_provider();
		$client->shouldReceive( 'get' )
			->once()
			->with( '/v1/checksums/plugin/akismet/5.3' )
			->andReturn( [ 'code' => 200, 'body' => '{"files":{"akismet.php":{"md5":"deadbeef"}}}', 'headers' => [] ] );

		$known_good = new KnownGood( $checksums, $this->software() );

		$known_good->check( 'wp-content/plugins/akismet/akismet.php', 'plugin:akismet', 'deadbeef' );
		$result = $known_good->check( 'wp-content/plugins/akismet/akismet.php', 'plugin:akismet', 'deadbeef' );

		$this->assertSame( 'match', $result['status'] );
	}
}
