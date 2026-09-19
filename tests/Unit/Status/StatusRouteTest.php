<?php
/**
 * Tests for Status\StatusRoute.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Status;

use Brain\Monkey\Functions;
use LightweightPlugins\Scan\Status\EndpointSettings;
use LightweightPlugins\Scan\Status\StatusReport;
use LightweightPlugins\Scan\Status\StatusRoute;
use LightweightPlugins\Scan\Tests\Unit\MonkeyTestCase;
use LightweightPlugins\Scan\Tests\Unit\Support\FakeStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once dirname( __DIR__ ) . '/WpErrorStub.php';
require_once dirname( __DIR__ ) . '/RestStubs.php';

final class StatusRouteTest extends MonkeyTestCase {

	use FakeStore;

	private const KEY = '0123456789abcdef0123456789abcdef';

	/** What WP core answers for a route that does not exist. */
	private const NO_ROUTE = 'No route was found matching the URL and request method.';

	/** @var string Level the injected measurement answers. */
	private string $level = 'crit';

	/** @var int How many times the injected measurement ran. */
	private int $measured = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->stub_store();
		$this->level    = 'crit';
		$this->measured = 0;

		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
	}

	private function store_key(): void {
		$this->options[ EndpointSettings::OPTION ] = [
			'enabled'    => true,
			'key'        => self::KEY,
			'key_set_at' => 1750000000,
			'cache_ttl'  => 300,
		];
	}

	private function route(): StatusRoute {
		$settings = new EndpointSettings();

		return new StatusRoute(
			$settings,
			new StatusReport(
				$settings,
				function (): array {
					++$this->measured;

					return [
						'level'   => $this->level,
						'summary' => 'measured',
						'details' => [ 'alerts_new' => 'crit' === $this->level ? 1 : 0 ],
					];
				}
			)
		);
	}

	/**
	 * @param array<string, mixed> $query Query string.
	 * @param string               $key   Key in the path.
	 */
	private function request( array $query = [], string $key = self::KEY ): WP_REST_Request {
		return new WP_REST_Request( [ 'key' => $key ], $query );
	}

	public function test_the_route_is_not_registered_when_the_endpoint_is_off(): void {
		$this->store_key();
		$this->options[ EndpointSettings::OPTION ]['enabled'] = false;

		Functions\expect( 'register_rest_route' )->never();

		$this->route()->register_routes();
	}

	public function test_the_route_is_not_registered_without_a_key(): void {
		Functions\expect( 'register_rest_route' )->never();

		$this->route()->register_routes();
	}

	public function test_the_keyed_route_is_registered_when_on_and_keyed(): void {
		$this->store_key();

		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				'lw-scan/v1',
				'/status/(?P<key>[a-f0-9]{32})',
				\Mockery::on(
					static fn ( array $args ): bool => 'GET' === $args['methods']
						&& '__return_true' === $args['permission_callback']
						&& false === ( $args['show_in_index'] ?? true )
						&& is_callable( $args['callback'] )
				)
			);

		$this->route()->register_routes();
	}

	public function test_a_wrong_key_answers_with_the_same_body_as_a_missing_route(): void {
		$this->store_key();

		// One argument only: core's own string in core's default text domain.
		Functions\expect( '__' )->once()->with( self::NO_ROUTE )->andReturnFirstArg();

		$result = $this->route()->status( $this->request( [], 'ffffffffffffffffffffffffffffffff' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [ 'rest_no_route' ], array_keys( $result->errors ) );
		$this->assertSame( [ self::NO_ROUTE ], $result->errors['rest_no_route'] );
		$this->assertSame( [ 'status' => 404 ], $result->error_data['rest_no_route'] );
		$this->assertSame( 0, $this->measured );
	}

	public function test_the_public_path_never_mints_a_key_on_a_keyless_site(): void {
		Functions\stubTranslationFunctions();
		Functions\expect( 'register_rest_route' )->never();

		$route = $this->route();
		$route->register_routes();
		$result = $route->status( $this->request( [], self::KEY ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [], $this->option_writes );
		$this->assertArrayNotHasKey( EndpointSettings::OPTION, $this->options );
	}

	public function test_the_public_path_never_writes_the_option(): void {
		$this->store_key();
		Functions\stubTranslationFunctions();
		Functions\when( 'register_rest_route' )->justReturn( true );

		$route = $this->route();
		$route->register_routes();
		$route->status( $this->request() );
		$route->status( $this->request( [ 'fresh' => '1' ] ) );
		$route->status( $this->request( [], 'ffffffffffffffffffffffffffffffff' ) );

		$this->assertSame( [], $this->option_writes );
	}

	public function test_a_key_in_the_query_string_cannot_stand_in_for_the_path(): void {
		$this->store_key();
		Functions\stubTranslationFunctions();

		$result = $this->route()->status( $this->request( [ 'key' => self::KEY ], 'ffffffffffffffffffffffffffffffff' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
	}

	public function test_the_right_key_answers_200_with_the_report(): void {
		$this->store_key();
		$this->level = 'ok';

		$response = $this->route()->status( $this->request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'overall', 'checked_at', 'cached', 'site', 'checks' ], array_keys( $response->get_data() ) );
		$this->assertSame( 'ok', $response->get_data()['checks']['lw_scan']['status'] );
	}

	public function test_the_report_is_never_cached_or_indexed_downstream(): void {
		$this->store_key();

		$headers = $this->route()->status( $this->request() )->get_headers();

		$this->assertSame( 'no-store, private', $headers['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $headers['X-Robots-Tag'] );
	}

	public function test_crit_stays_200_without_http_status(): void {
		$this->store_key();

		$response = $this->route()->status( $this->request() );

		$this->assertSame( 'crit', $response->get_data()['overall'] );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_http_status_turns_crit_into_503(): void {
		$this->store_key();

		$response = $this->route()->status( $this->request( [ 'http_status' => '1' ] ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'crit', $response->get_data()['overall'] );
	}

	public function test_http_status_leaves_a_non_crit_report_at_200(): void {
		$this->store_key();
		$this->level = 'warn';

		$response = $this->route()->status( $this->request( [ 'http_status' => '1' ] ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_a_second_request_is_served_from_the_cache(): void {
		$this->store_key();

		$this->route()->status( $this->request() );
		$second = $this->route()->status( $this->request() );

		$this->assertSame( 1, $this->measured );
		$this->assertTrue( $second->get_data()['cached'] );
	}

	public function test_fresh_recomputes_the_report(): void {
		$this->store_key();

		$this->route()->status( $this->request() );
		$fresh = $this->route()->status( $this->request( [ 'fresh' => '1' ] ) );

		$this->assertSame( 2, $this->measured );
		$this->assertFalse( $fresh->get_data()['cached'] );
	}
}
