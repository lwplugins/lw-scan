<?php
/**
 * Thrown when the backend rate-limits or blocks the request.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for HTTP 429 (Too Many Requests) and 403 (Forbidden) responses.
 */
final class RateLimitedException extends RemoteException {}
