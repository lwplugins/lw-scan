#!/usr/bin/env php
<?php
/**
 * Generates (or verifies) checksums.json — the plugin's own file manifest.
 *
 * The scanner's decoder and heuristic sources necessarily contain the very
 * strings the signatures look for, so without a shipped manifest lw-scan
 * reports itself as infected on every install (Index\SelfManifest reads it).
 *
 * Usage:
 *   php bin/checksums.php            Write checksums.json.
 *   php bin/checksums.php --check    Exit 1 when checksums.json is stale.
 *
 * Plain PHP: no WordPress, no Composer autoloader, so it runs in CI before
 * anything else is installed.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

const MANIFEST   = 'checksums.json';
const DIRECTORIES = [ 'includes', 'assets' ];
const ROOT_FILES = [ 'lw-scan.php', 'uninstall.php' ];
const EXTENSIONS = [ 'php', 'js', 'css' ];

$root  = dirname( __DIR__ );
$check = in_array( '--check', array_slice( $argv, 1 ), true );

$version = lw_scan_manifest_version( $root . '/lw-scan.php' );

if ( '' === $version ) {
	fwrite( STDERR, "Could not read LW_SCAN_VERSION from lw-scan.php\n" );
	exit( 1 );
}

$manifest = [
	'version' => $version,
	'files'   => lw_scan_manifest_files( $root ),
];

$json   = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
$target = $root . '/' . MANIFEST;

if ( $check ) {
	$current = is_readable( $target ) ? (string) file_get_contents( $target ) : '';

	if ( $current === $json ) {
		printf( "%s is up to date (%d files, version %s).\n", MANIFEST, count( $manifest['files'] ), $version );
		exit( 0 );
	}

	fwrite( STDERR, sprintf( "%s is stale — run: composer checksums\n", MANIFEST ) );
	exit( 1 );
}

if ( false === file_put_contents( $target, $json ) ) {
	fwrite( STDERR, sprintf( "Could not write %s\n", $target ) );
	exit( 1 );
}

printf( "Wrote %s (%d files, version %s).\n", MANIFEST, count( $manifest['files'] ), $version );
exit( 0 );

/**
 * Reads the version the plugin bootstrap defines.
 *
 * @param string $plugin_file Absolute path to lw-scan.php.
 * @return string Version, or '' when it could not be read.
 */
function lw_scan_manifest_version( string $plugin_file ): string {
	if ( ! is_readable( $plugin_file ) ) {
		return '';
	}

	$source = (string) file_get_contents( $plugin_file );

	if ( 1 !== preg_match( "/define\(\s*'LW_SCAN_VERSION',\s*'([^']+)'\s*\)/", $source, $matches ) ) {
		return '';
	}

	return $matches[1];
}

/**
 * Every shipped source file, as plugin-relative path => md5, sorted by path.
 *
 * @param string $root Absolute plugin directory.
 * @return array<string,string>
 */
function lw_scan_manifest_files( string $root ): array {
	$files = [];

	foreach ( ROOT_FILES as $name ) {
		$abs = $root . '/' . $name;

		if ( is_file( $abs ) ) {
			$files[ $name ] = (string) md5_file( $abs );
		}
	}

	foreach ( DIRECTORIES as $dir ) {
		$abs_dir = $root . '/' . $dir;

		if ( ! is_dir( $abs_dir ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $abs_dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			if ( ! in_array( strtolower( $file->getExtension() ), EXTENSIONS, true ) ) {
				continue;
			}

			$rel           = $dir . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $abs_dir ) + 1 ) );
			$files[ $rel ] = (string) md5_file( $file->getPathname() );
		}
	}

	ksort( $files );

	return $files;
}
