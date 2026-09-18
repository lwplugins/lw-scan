<?php
/**
 * Thrown when the backend is unreachable or fails with a server/unexpected error.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for WP_Error network failures, 5xx responses, and any other
 * non-2xx/304 status that isn't more specifically mapped.
 */
final class UnavailableException extends RemoteException {}
