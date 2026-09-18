<?php
/**
 * Thrown when the requested resource does not exist on the backend.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for HTTP 404 responses.
 */
final class NotFoundException extends RemoteException {}
