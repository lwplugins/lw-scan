<?php
/**
 * Tests for Run\Phase\BundlePhase.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Run\Phase;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Run\BundleGuard;
use LightweightPlugins\Scan\Run\Context;
use LightweightPlugins\Scan\Run\Cursor;
use LightweightPlugins\Scan\Run\Phase\BundlePhase;
use LightweightPlugins\Scan\Run\RunStats;
use LightweightPlugins\Scan\State;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FixturePack;
use Mockery;
use RuntimeException;

/**
 * The phase end to end over the real `PackFetcher` and `PackLoader`: only
 * the WordPress HTTP API answers from a table of canned responses, and the
 * storage directory is a temp dir behind the `lw_scan_storage_dir` filter,
 * so the store the fetcher writes and the store the loader reads are the
 * same one, as on a site.
 */
final class BundlePhaseTest extends MonkeyTestCase {

	private const VERSION = 20260901001;

	/** @var array<string, mixed> In-memory stand-in for the options table. */
	private array $options = [];

	/** @var string Temp directory standing in for wp-content/lw-scan/. */
	private string $dir;

	/** @var array<string, array{code:int, body:string}> URL suffix => canned response. */
	private array $responses = [];

	/** @var array<string, mixed>|null What the phase wrote onto the run row. */
	private ?array $run_update = null;

	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', '/nonexistent-wp-content' );
		}

		$this->dir = sys_get_temp_dir() . '/lw-scan-bundlephase-' . uniqid();
		$dir       = $this->dir;
		$options   = &$this->options;

		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) use ( $dir ) {
				return 'lw_scan_storage_dir' === $tag ? $dir : $value;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default_value = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default_value;
			}
		);
		foreach ( [ 'add_option', 'update_option' ] as $writer ) {
			Functions\when( $writer )->alias(
				static function ( $name, $value ) use ( &$options ) {
					$options[ $name ] = $value;

					return true;
				}
			);
		}
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg( 1 );

		$this->stub_http();

		PackLoader::use_store( null );
	}

	protected function tearDown(): void {
		PackLoader::use_store( null );

		foreach ( (array) glob( $this->dir . '/{*,.htaccess}', GLOB_BRACE ) as $file ) {
			unlink( (string) $file );
		}

		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}

		parent::tearDown();
	}

	/**
	 * `wp_remote_get()` answers with the canned response whose key the URL
	 * ends with; anything else is a 404.
	 */
	private function stub_http(): void {
		$responses = &$this->responses;

		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url ) use ( &$responses ) {
				foreach ( $responses as $suffix => $response ) {
					if ( substr( (string) $url, -strlen( $suffix ) ) === $suffix ) {
						return $response;
					}
				}

				return [
					'code' => 404,
					'body' => '',
				];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );
	}

	/**
	 * Serves the lw fixture as pack `$version`: the pointer, the pack and its meta.
	 *
	 * @param int $version Version the backend offers.
	 */
	private function serve_pack( int $version ): void {
		$pack_data            = FixturePack::pack_data( 'lw' );
		$meta_data            = FixturePack::meta_data( 'lw' );
		$pack_data['version'] = $version;
		$meta_data['version'] = $version;
		$pack_body            = (string) json_encode( $pack_data );
		$meta_body            = (string) json_encode( $meta_data );

		$this->responses = [
			'/v1/pack/latest'                 => [
				'code' => 200,
				'body' => (string) json_encode(
					[
						'version'         => $version,
						'format'          => 1,
						'signature_count' => (int) $pack_data['count'],
						'pack'            => [
							'url'    => '/v1/pack/' . $version,
							'sha256' => hash( 'sha256', $pack_body ),
						],
						'meta'            => [
							'url'    => '/v1/pack/' . $version . '/meta',
							'sha256' => hash( 'sha256', $meta_body ),
						],
					]
				),
			],
			'/v1/pack/' . $version . '/meta' => [
				'code' => 200,
				'body' => $meta_body,
			],
			'/v1/pack/' . $version            => [
				'code' => 200,
				'body' => $pack_body,
			],
		];
	}

	/**
	 * @param array<string, mixed> $options Plugin options for the tick.
	 */
	private function context( array $options ): Context {
		$runs = Mockery::mock( RunsRepositoryInterface::class );
		$runs->shouldReceive( 'update' )->andReturnUsing(
			function ( $run_id, array $data ): void {
				unset( $run_id );
				$this->run_update = $data;
			}
		);

		return new Context(
			Cursor::fresh( 42, 'changed', '', [ 'bundle', 'files' ] ),
			new RunStats(),
			$options,
			Mockery::mock( FilesRepositoryInterface::class ),
			Mockery::mock( FindingsRepositoryInterface::class ),
			$runs,
			[]
		);
	}

	private static function never_due(): callable {
		return static function (): bool {
			return false;
		};
	}

	public function test_a_fresh_install_downloads_the_pack_and_scans_with_it(): void {
		$this->serve_pack( self::VERSION );

		$ctx = $this->context( [ 'bundle_auto_update' => false ] );

		$this->assertTrue( ( new BundlePhase() )->run( $ctx, self::never_due() ) );

		$this->assertNotNull( $ctx->signatures() );
		$this->assertSame( self::VERSION, $ctx->signatures()->version() );
		$this->assertSame( 'lw:0001', $ctx->signatures()->meta()->id( 0 ) );
		$this->assertSame( self::VERSION, $ctx->cursor->get( BundleGuard::FIELD ) );
		$this->assertSame( [ 'bundle_version' => self::VERSION ], $this->run_update );
		$this->assertSame( 'updated', $ctx->stats->to_array()['bundle']['status'] );
	}

	public function test_a_fresh_install_whose_download_fails_has_no_bundle(): void {
		$this->responses = [
			'/v1/pack/latest' => [
				'code' => 503,
				'body' => '',
			],
		];

		$ctx = $this->context( [ 'bundle_auto_update' => false ] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'bundle_missing' );

		( new BundlePhase() )->run( $ctx, self::never_due() );
	}

	public function test_auto_update_switches_the_run_to_a_newer_pack(): void {
		FixturePack::install( new Store( $this->dir ), 'lw', self::VERSION );
		$this->options[ State::OPTION_NAME ] = [ 'bundle_version' => self::VERSION ];

		$newer = self::VERSION + 1;
		$this->serve_pack( $newer );

		$ctx = $this->context( [ 'bundle_auto_update' => true ] );

		$this->assertTrue( ( new BundlePhase() )->run( $ctx, self::never_due() ) );

		$this->assertNotNull( $ctx->signatures() );
		$this->assertSame( $newer, $ctx->signatures()->version() );
		$this->assertSame( $newer, $ctx->bundle_version() );
		$this->assertSame( $newer, $ctx->cursor->get( BundleGuard::FIELD ) );
		$this->assertSame( [ 'bundle_version' => $newer ], $this->run_update );
		$this->assertSame( 'updated', $ctx->stats->to_array()['bundle']['status'] );
	}

	public function test_auto_update_with_backend_unreachable_continues_on_the_stored_pack(): void {
		// Spec §6/§14: a backend that can't be reached is not fatal as long
		// as a stored pack exists — only a run with no pack at all fails
		// (see test_a_fresh_install_whose_download_fails_has_no_bundle()).
		FixturePack::install( new Store( $this->dir ), 'lw', self::VERSION );
		$this->options[ State::OPTION_NAME ] = [ 'bundle_version' => self::VERSION ];

		$this->responses = [
			'/v1/pack/latest' => [
				'code' => 503,
				'body' => '',
			],
		];

		$ctx = $this->context( [ 'bundle_auto_update' => true ] );

		$this->assertTrue( ( new BundlePhase() )->run( $ctx, self::never_due() ) );

		$this->assertNotNull( $ctx->signatures() );
		$this->assertSame( self::VERSION, $ctx->signatures()->version() );
		$this->assertSame( self::VERSION, $ctx->cursor->get( BundleGuard::FIELD ) );
		$this->assertSame( [ 'bundle_version' => self::VERSION ], $this->run_update );
		$this->assertSame( 'failed', $ctx->stats->to_array()['bundle']['status'] );
	}
}
