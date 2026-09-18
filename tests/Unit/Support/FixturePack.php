<?php
/**
 * Test-only access to the committed backend-built pack fixtures
 * (`tests/Fixtures/{lw,mini}-{pack,meta}.json`): as decoded arrays, as a
 * ready `Signatures`, or installed into a `Store` under any version.
 *
 * Provenance: `{lw,mini}-{pack,meta}.json` were generated from
 * `tests/Fixtures/{bundle-lw,bundle-mini}.json` — old-format bundle
 * documents, kept only as the source those pairs were built from — by the
 * backend's pack builder CLI (`scan-data-lwplugins-com` repo):
 *
 *   scan-data pack -in tests/Fixtures/bundle-lw.json   -out tests/Fixtures -prefix lw-
 *   scan-data pack -in tests/Fixtures/bundle-mini.json -out tests/Fixtures -prefix mini-
 *
 * Nothing in this plugin reads `bundle-{lw,mini}.json` any more; regenerate
 * the pack/meta pair from them (or from a newer bundle document) with the
 * command above whenever the fixtures need to change.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Tests\Unit\Support;

use LightweightPlugins\Scan\Bundle\NewSignatures;
use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Bundle\Store;

final class FixturePack {

	/**
	 * @param string $name `lw` or `mini`.
	 * @return array<string, mixed>
	 */
	public static function pack_data( string $name ): array {
		return self::decode( $name . '-pack.json' );
	}

	/**
	 * @param string $name `lw` or `mini`.
	 * @return array<string, mixed>
	 */
	public static function meta_data( string $name ): array {
		return self::decode( $name . '-meta.json' );
	}

	/**
	 * @param string             $name `lw` or `mini`.
	 * @param NewSignatures|null $new  Rules to treat as new; none by default.
	 */
	public static function signatures( string $name, ?NewSignatures $new = null ): Signatures {
		return self::signatures_from( self::pack_data( $name ), self::meta_data( $name ), $new );
	}

	/**
	 * A fixture's signatures under another version (pack and meta headers
	 * both rewritten), for tests about which version a run scans with.
	 *
	 * @param string $name    `lw` or `mini`.
	 * @param int    $version Version to report.
	 */
	public static function signatures_as( string $name, int $version ): Signatures {
		$pack_data            = self::pack_data( $name );
		$meta_data            = self::meta_data( $name );
		$pack_data['version'] = $version;
		$meta_data['version'] = $version;

		return self::signatures_from( $pack_data, $meta_data );
	}

	/**
	 * @param array<string, mixed> $pack_data Decoded pack.
	 * @param array<string, mixed> $meta_data Decoded meta.
	 * @param NewSignatures|null   $new       Rules to treat as new; none by default.
	 */
	public static function signatures_from( array $pack_data, array $meta_data, ?NewSignatures $new = null ): Signatures {
		$pack = Pack::from_array( $pack_data );
		$meta = PackMeta::from_array( $meta_data );

		if ( null === $pack || null === $meta ) {
			throw new \LogicException( 'Fixture pack or meta does not load.' );
		}

		return new Signatures(
			$pack,
			static function () use ( $meta ): PackMeta {
				return $meta;
			},
			$new ?? NewSignatures::none()
		);
	}

	/**
	 * Writes a fixture's pack and meta into `$store` as version `$version`
	 * (the header rewritten to match), the way PackFetcher leaves them.
	 *
	 * @param Store  $store   Target storage.
	 * @param string $name    `lw` or `mini`.
	 * @param int    $version Version to install the files as.
	 */
	public static function install( Store $store, string $name, int $version ): void {
		self::install_data( $store, self::pack_data( $name ), self::meta_data( $name ), $version );
	}

	/**
	 * @param Store                $store     Target storage.
	 * @param array<string, mixed> $pack_data Decoded pack.
	 * @param array<string, mixed> $meta_data Decoded meta.
	 * @param int                  $version   Version to install the files as.
	 */
	public static function install_data( Store $store, array $pack_data, array $meta_data, int $version ): void {
		$pack_data['version'] = $version;
		$meta_data['version'] = $version;

		if ( ! is_dir( $store->dir() ) ) {
			mkdir( $store->dir(), 0755, true );
		}

		file_put_contents( $store->pack_path( $version ), (string) json_encode( $pack_data ) );
		file_put_contents( $store->meta_path( $version ), (string) json_encode( $meta_data ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function decode( string $file ): array {
		$decoded = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/' . $file ), true );

		if ( ! is_array( $decoded ) ) {
			throw new \LogicException( $file . ' does not decode.' );
		}

		return $decoded;
	}
}
