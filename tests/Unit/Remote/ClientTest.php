<?php
/**
 * Tests for Remote\Client.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Remote;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\NotFoundException;
use LightweightPlugins\Scan\Remote\RateLimitedException;
use LightweightPlugins\Scan\Remote\UnavailableException;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;

final class ClientTest extends MonkeyTestCase {

	protected function setUp(): void {
		parent::setUp();
		Client::reset_counters();
	}

	/**
	 * @param mixed $response wp_remote_get() return value.
	 */
	private function stub_response( mixed $response, string $url = 'https://scan-data.lwplugins.com/v1/x' ): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->withArgs(
				function ( $given_url, $args ) use ( $url ) {
					return $given_url === $url
						&& isset( $args['user-agent'] )
						&& str_starts_with( $args['user-agent'], 'lw-scan/' )
						&& str_contains( $args['user-agent'], '; WordPress/6.6' )
						&& true === $args['decompress']
						&& 3 === $args['redirection'];
				}
			)
			->andReturn( $response );

		Functions\when( 'is_wp_error' )->justReturn( is_object( $response ) && isset( $response->is_error ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : ''
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static fn ( $r ) => is_array( $r ) ? ( $r['headers'] ?? [] ) : []
		);
	}

	public function test_returns_array_on_200(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '{"ok":1}',
				'headers'  => [ 'etag' => '"a"' ],
			]
		);

		$result = ( new Client() )->get( '/v1/x' );

		$this->assertSame( 200, $result['code'] );
		$this->assertSame( '{"ok":1}', $result['body'] );
		$this->assertSame( [ 'etag' => '"a"' ], $result['headers'] );
		$this->assertSame(
			[
				'calls'     => 1,
				'errors'    => 0,
				'not_found' => 0,
			],
			Client::counters()
		);
	}

	public function test_returns_array_on_304(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 304 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		$result = ( new Client() )->get( '/v1/x' );

		$this->assertSame( 304, $result['code'] );
	}

	public function test_throws_not_found_on_404(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 404 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		$this->expectException( NotFoundException::class );

		( new Client() )->get( '/v1/x' );
	}

	public function test_throws_rate_limited_on_429(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 429 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		$this->expectException( RateLimitedException::class );

		( new Client() )->get( '/v1/x' );
	}

	public function test_throws_rate_limited_on_403(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 403 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		$this->expectException( RateLimitedException::class );

		( new Client() )->get( '/v1/x' );
	}

	public function test_throws_unavailable_on_503(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 503 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		$this->expectException( UnavailableException::class );

		( new Client() )->get( '/v1/x' );
	}

	public function test_throws_unavailable_on_wp_error(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );
		Functions\when( 'esc_html' )->returnArg( 1 );

		$wp_error = new class() {
			public function get_error_message(): string {
				return 'connection timed out';
			}
		};

		Functions\expect( 'wp_remote_get' )->once()->andReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$this->expectException( UnavailableException::class );

		( new Client() )->get( '/v1/x' );
	}

	public function test_increments_error_counter_on_failure(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 500 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		try {
			( new Client() )->get( '/v1/x' );
		} catch ( UnavailableException $e ) {
			unset( $e );
		}

		$this->assertSame(
			[
				'calls'     => 1,
				'errors'    => 1,
				'not_found' => 0,
			],
			Client::counters()
		);
	}

	public function test_stream_to_sets_stream_args_and_returns_empty_body(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->withArgs(
				function ( $url, $args ) {
					unset( $url );
					return true === ( $args['stream'] ?? null )
						&& '/tmp/x.bin' === ( $args['filename'] ?? null );
				}
			)
			->andReturn(
				[
					'response' => [ 'code' => 200 ],
					'body'     => '',
					'headers'  => [],
				]
			);

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );

		$result = ( new Client() )->get( '/v1/x', [ 'stream_to' => '/tmp/x.bin' ] );

		$this->assertSame( '', $result['body'] );
	}

	public function test_base_url_uses_filter(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'lw_scan_api_base', Client::DEFAULT_BASE )
			->andReturn( 'https://custom.example.com' );

		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );

		Functions\expect( 'wp_remote_get' )
			->once()
			->withArgs(
				static function ( $url ) {
					return 'https://custom.example.com/v1/x' === $url;
				}
			)
			->andReturn(
				[
					'response' => [ 'code' => 200 ],
					'body'     => '',
					'headers'  => [],
				]
			);

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( [] );

		( new Client() )->get( '/v1/x' );
	}

	public function test_normalizes_object_headers_to_lowercase_string_array(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );

		$headers = new class() implements \IteratorAggregate {
			public function getIterator(): \Iterator {
				return new \ArrayIterator(
					[
						'Content-Type' => 'application/json',
						'X-Multi'      => [ 'a', 'b' ],
					]
				);
			}
		};

		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			[
				'response' => [ 'code' => 200 ],
				'body'     => '',
				'headers'  => $headers,
			]
		);

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn ( $r ) => $r['headers'] );

		$result = ( new Client() )->get( '/v1/x' );

		$this->assertSame(
			[
				'content-type' => 'application/json',
				'x-multi'      => 'a, b',
			],
			$result['headers']
		);
	}

	/**
	 * Per spec 5.4 a 404 is a normal answer ("the backend has no checksum
	 * list for this package"), not a backend failure — counting it as one
	 * inflated a metric users read as "the backend is broken".
	 */
	public function test_a_404_is_counted_as_a_call_and_a_not_found_never_as_an_error(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 404 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		try {
			( new Client() )->get( '/v1/x' );
		} catch ( NotFoundException $e ) {
			unset( $e );
		}

		$this->assertSame(
			[
				'calls'     => 1,
				'errors'    => 0,
				'not_found' => 1,
			],
			Client::counters()
		);
	}

	public function test_a_503_is_counted_as_an_error(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 503 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		try {
			( new Client() )->get( '/v1/x' );
		} catch ( UnavailableException $e ) {
			unset( $e );
		}

		$this->assertSame(
			[
				'calls'     => 1,
				'errors'    => 1,
				'not_found' => 0,
			],
			Client::counters()
		);
	}

	public function test_a_429_is_counted_as_an_error(): void {
		$this->stub_response(
			[
				'response' => [ 'code' => 429 ],
				'body'     => '',
				'headers'  => [],
			]
		);

		try {
			( new Client() )->get( '/v1/x' );
		} catch ( RateLimitedException $e ) {
			unset( $e );
		}

		$this->assertSame(
			[
				'calls'     => 1,
				'errors'    => 1,
				'not_found' => 0,
			],
			Client::counters()
		);
	}

	public function test_a_transport_failure_is_counted_as_an_error(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '6.6' );
		Functions\when( 'esc_html' )->returnArg( 1 );

		$wp_error = new class() {
			public function get_error_message(): string {
				return 'connection timed out';
			}
		};

		Functions\expect( 'wp_remote_get' )->once()->andReturn( $wp_error );
		Functions\when( 'is_wp_error' )->justReturn( true );

		try {
			( new Client() )->get( '/v1/x' );
		} catch ( UnavailableException $e ) {
			unset( $e );
		}

		$this->assertSame(
			[
				'calls'     => 1,
				'errors'    => 1,
				'not_found' => 0,
			],
			Client::counters()
		);
	}

	public function test_reset_counters_clears_the_not_found_counter_too(): void {
		Client::$calls     = 3;
		Client::$errors    = 2;
		Client::$not_found = 1;

		Client::reset_counters();

		$this->assertSame(
			[
				'calls'     => 0,
				'errors'    => 0,
				'not_found' => 0,
			],
			Client::counters()
		);
	}
}
