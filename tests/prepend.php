<?php
/**
 * Auto-prepended before PHPUnit's bin proxy loads Composer's autoloader.
 *
 * vendor/bin/phpunit's generated proxy requires vendor/autoload.php to
 * autoload PHPUnit itself before phpunit.xml.dist's own "bootstrap" file
 * ever runs. That first require executes Composer's files-autoloaded
 * includes/functions.php with ABSPATH still undefined, so it hits its
 * direct-access guard and returns before declaring its functions — and
 * Composer's files-autoloader never includes the same file twice, so
 * tests/bootstrap.php defining ABSPATH afterwards is too late. Defining
 * ABSPATH here, before any of that happens, closes the gap.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
