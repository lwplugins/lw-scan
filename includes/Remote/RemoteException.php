<?php
/**
 * Base exception for Remote\Client failures.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status code that triggered the failure (0 when there was
 * no HTTP response at all, e.g. a WP_Error from wp_remote_get()).
 */
class RemoteException extends \RuntimeException {

	/**
	 * HTTP status code that triggered this exception, or 0 when there was
	 * no HTTP response at all (e.g. a WP_Error from wp_remote_get()).
	 *
	 * @var int
	 */
	public int $http_code = 0;

	public function __construct( string $message, int $http_code = 0 ) {
		parent::__construct( $message );

		$this->http_code = $http_code;
	}
}
