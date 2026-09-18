<?php
/**
 * HTTP client for the LW Scan backend (scan-data.lwplugins.com).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_get(). Never sends the site's URL or
 * hostname — only a generic User-Agent identifying the plugin and WP
 * version — and maps every non-2xx/304 outcome to a typed exception so
 * callers decide how to degrade instead of juggling status codes.
 *
 * Deliberately not `final`: PackFetcher/ChecksumProvider/VulnerabilityProvider
 * tests take it as a Mockery mock (`Mockery::mock(Client::class)`), which
 * requires subclassing — Mockery cannot generate a type-satisfying mock for
 * a final class.
 */
class Client {

	public const DEFAULT_BASE = 'https://scan-data.lwplugins.com';

	/**
	 * Number of get() calls made since the last reset_counters().
	 *
	 * @var int
	 */
	public static int $calls = 0;

	/**
	 * Number of get() calls that failed since the last reset_counters(): a
	 * transport error, a 5xx, or a 429/403. A 404 is not one of them — see
	 * $not_found.
	 *
	 * @var int
	 */
	public static int $errors = 0;

	/**
	 * Number of get() calls answered with a 404 since the last
	 * reset_counters(). Per spec 5.4 that is a normal answer ("the backend
	 * has no checksum list for this package"), not a failure, so it is
	 * counted apart from $errors rather than folded into it — a site with
	 * a handful of packages the backend does not know would otherwise read
	 * as a site whose backend is broken.
	 *
	 * @var int
	 */
	public static int $not_found = 0;

	/**
	 * Backend base URL, no trailing slash.
	 *
	 * @var string
	 */
	private string $base;

	public function __construct( ?string $base = null ) {
		/**
		 * Filters the backend base URL.
		 *
		 * @param string $base Default base URL.
		 */
		$this->base = $base ?? (string) apply_filters( 'lw_scan_api_base', self::DEFAULT_BASE );
	}

	/**
	 * @param string                                                                $path Request path, e.g. '/v1/bundle/latest'.
	 * @param array{timeout?:int, headers?:array<string,string>, stream_to?:string} $args Request options.
	 * @return array{code:int, body:string, headers:array<string,string>}
	 * @throws NotFoundException When the backend returns 404 — counted as a call and a not_found, never as an error.
	 * @throws RateLimitedException When the backend returns 429 or 403.
	 * @throws UnavailableException On a WP_Error, a 5xx response, or any other non-2xx/304 status.
	 */
	public function get( string $path, array $args = [] ): array {
		++self::$calls;

		$request_args = [
			'timeout'     => $args['timeout'] ?? 15,
			'redirection' => 3,
			'decompress'  => true,
			'user-agent'  => 'lw-scan/' . LW_SCAN_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'     => $args['headers'] ?? [],
		];

		$streaming = isset( $args['stream_to'] ) && '' !== $args['stream_to'];

		if ( $streaming ) {
			$request_args['stream']   = true;
			$request_args['filename'] = $args['stream_to'];
		}

		$response = wp_remote_get( $this->base . $path, $request_args );

		if ( is_wp_error( $response ) ) {
			++self::$errors;

			throw new UnavailableException( esc_html( $response->get_error_message() ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$body    = $streaming ? '' : (string) wp_remote_retrieve_body( $response );
		$headers = self::normalize_headers( wp_remote_retrieve_headers( $response ) );

		if ( ( $code >= 200 && $code < 300 ) || 304 === $code ) {
			return [
				'code'    => $code,
				'body'    => $body,
				'headers' => $headers,
			];
		}

		if ( 404 === $code ) {
			++self::$not_found;

			throw new NotFoundException( 'Backend resource not found', $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $code is an internal HTTP status int, never output as HTML.
		}

		++self::$errors;

		if ( 429 === $code || 403 === $code ) {
			throw new RateLimitedException( 'Backend rate-limited the request', $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $code is an internal HTTP status int, never output as HTML.
		}

		throw new UnavailableException( 'Backend request failed', $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $code is an internal HTTP status int, never output as HTML.
	}

	/**
	 * @return array{calls:int, errors:int, not_found:int}
	 */
	public static function counters(): array {
		return [
			'calls'     => self::$calls,
			'errors'    => self::$errors,
			'not_found' => self::$not_found,
		];
	}

	public static function reset_counters(): void {
		self::$calls     = 0;
		self::$errors    = 0;
		self::$not_found = 0;
	}

	/**
	 * Normalizes wp_remote_retrieve_headers() output to a lowercase-keyed
	 * string array. Depending on the HTTP transport this comes back as a
	 * plain array or a Requests_Utility_CaseInsensitiveDictionary /
	 * WpOrg\Requests\Utility\CaseInsensitiveDictionary object — both are
	 * iterable, so a plain foreach handles either shape.
	 *
	 * @param mixed $headers Value from wp_remote_retrieve_headers().
	 * @return array<string,string>
	 */
	private static function normalize_headers( mixed $headers ): array {
		$normalized = [];

		if ( ! is_iterable( $headers ) ) {
			return $normalized;
		}

		foreach ( $headers as $key => $value ) {
			$normalized[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return $normalized;
	}
}
