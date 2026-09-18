<?php
/**
 * Loads the stored signature pack once per process.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * `State bundle_version` names the files; a missing, unreadable, invalid or
 * version-mismatched file is `null` (the run's BundlePhase downloads again).
 * There is no recompile path: the backend builds the pack.
 */
final class PackLoader {

	/**
	 * @var Store|null
	 */
	private static ?Store $store = null;

	/**
	 * @var Pack|null
	 */
	private static ?Pack $pack = null;

	/**
	 * @var bool
	 */
	private static bool $pack_loaded = false;

	/**
	 * @var PackMeta|null
	 */
	private static ?PackMeta $meta = null;

	/**
	 * @var bool
	 */
	private static bool $meta_loaded = false;

	/**
	 * @var NewSignatures|null
	 */
	private static ?NewSignatures $new = null;

	public static function get(): ?Pack {
		if ( ! self::$pack_loaded ) {
			self::$pack_loaded = true;
			$version           = self::version();
			$data              = $version > 0 ? self::read( self::store()->pack_path( $version ) ) : null;
			$pack              = null === $data ? null : Pack::from_array( $data );
			self::$pack        = null !== $pack && $pack->version() === $version ? $pack : null;
		}

		return self::$pack;
	}

	public static function meta(): ?PackMeta {
		if ( ! self::$meta_loaded ) {
			self::$meta_loaded = true;
			$version           = self::version();
			$data              = $version > 0 ? self::read( self::store()->meta_path( $version ) ) : null;
			$meta              = null === $data ? null : PackMeta::from_array( $data );
			self::$meta        = null !== $meta && $meta->version() === $version ? $meta : null;
		}

		return self::$meta;
	}

	public static function new_signatures(): NewSignatures {
		if ( null === self::$new ) {
			$version   = self::version();
			$data      = $version > 0 ? self::read( self::store()->new_path( $version ) ) : null;
			self::$new = null === $data ? NewSignatures::none() : NewSignatures::from_array( $data );
		}

		return self::$new;
	}

	public static function signatures(): ?Signatures {
		$pack = self::get();

		return null === $pack ? null : new Signatures( $pack, [ self::class, 'meta' ], self::new_signatures() );
	}

	public static function use_store( ?Store $store ): void {
		self::$store = $store;
		self::reset();
	}

	public static function reset(): void {
		self::$pack        = null;
		self::$pack_loaded = false;
		self::$meta        = null;
		self::$meta_loaded = false;
		self::$new         = null;
	}

	private static function version(): int {
		return (int) State::get( 'bundle_version', 0 );
	}

	private static function store(): Store {
		return self::$store ?? new Store();
	}

	/**
	 * @param string $path Absolute file path.
	 * @return array<string, mixed>|null
	 */
	private static function read( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local pack/meta/new file under the plugin's own storage dir, not a remote URL.
		$json = file_get_contents( $path );
		$data = false === $json ? null : json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}
}
