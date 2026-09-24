<?php
/**
 * Typed, sanitized reads of REST request parameters.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `WP_REST_Request::get_param()` merges the JSON body, the form body and
 * the query string, and hands back whatever type the client sent. These
 * readers turn that into the one type each route expects — a non-scalar
 * where a scalar belongs reads as absent — and sanitize it on the way.
 */
final class Params {

	/**
	 * A `sanitize_key()`'d slug (scope, state, op, filters).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 */
	public static function key( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * A `sanitize_text_field()`'d string (search, path).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 */
	public static function text( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * A boolean; JSON `true`, "1", "true", "yes" and friends read as true.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 */
	public static function bool( WP_REST_Request $request, string $name ): bool {
		$value = $request->get_param( $name );

		return is_scalar( $value ) && rest_sanitize_boolean( $value );
	}

	/**
	 * Whether the parameter was sent at all (with a non-null value).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 */
	public static function has( WP_REST_Request $request, string $name ): bool {
		return null !== $request->get_param( $name );
	}

	/**
	 * A non-negative integer clamped into `$min..$max`; `$fallback` when
	 * absent or not a number.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param string          $name     Parameter.
	 * @param int             $fallback Value when absent.
	 * @param int             $min      Lower bound.
	 * @param int             $max      Upper bound.
	 */
	public static function int( WP_REST_Request $request, string $name, int $fallback, int $min, int $max ): int {
		$value = $request->get_param( $name );

		if ( ! is_numeric( $value ) ) {
			return $fallback;
		}

		return max( $min, min( $max, absint( $value ) ) );
	}

	/**
	 * A list of positive ids, from a JSON array or a comma list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Parameter.
	 * @return array<int|string, mixed> Scalars only; `StateChange::normalize_ids()` finishes the job.
	 */
	public static function scalars( WP_REST_Request $request, string $name ): array {
		$value = $request->get_param( $name );

		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		return is_array( $value ) ? array_filter( $value, 'is_scalar' ) : [];
	}
}
