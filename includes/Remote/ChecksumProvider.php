<?php
/**
 * Known-good file checksums for WordPress core, plugins and themes.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps Remote\Client + Remote\FileCache to fetch and cache the backend's
 * checksum lists (design spec §5.4): `core()` returns a path => md5 list map
 * from `/v1/checksums/core/{version}`; `plugin()`/`theme()` return a path =>
 * md5 list map extracted from the `files` field of
 * `/v1/checksums/{kind}/{slug}/{version}`. A checksum is always a *list*:
 * files that shipped with more than one valid content (the ZIP vs. the SVN
 * trunk, differing line endings) carry several acceptable md5s, so the
 * caller treats a file as known-good when its md5 is any of them. A hit is
 * cached 7 days; a 404 ("no such package/version known to the backend") is
 * cached as a short-lived miss marker so it doesn't get treated as a
 * permanent 7-day cache entry. `Unavailable`/`RateLimited` responses are never cached — the
 * caller (Index\KnownGood) falls back to a full scan for that package.
 */
final class ChecksumProvider {

	/**
	 * Prefix every checksum FileCache key starts with; `Health\Environment`
	 * uses it (not an ad-hoc string) to count cached checksum responses.
	 */
	public const CACHE_PREFIX = 'checksum-';

	private const SLUG_RE    = '/^[a-z0-9][a-z0-9._-]{0,99}$/';
	private const VERSION_RE = '/^[0-9A-Za-z._-]{1,40}$/';

	/**
	 * Number of get() calls issued to the Client since the last reset_requests().
	 *
	 * @var int
	 */
	public static int $requests = 0;

	/**
	 * @var Client
	 */
	private Client $client;

	/**
	 * @var FileCache
	 */
	private FileCache $cache;

	public function __construct( Client $client, FileCache $cache ) {
		$this->client = $client;
		$this->cache  = $cache;
	}

	public static function reset_requests(): void {
		self::$requests = 0;
	}

	/**
	 * @param string $version WordPress core version, e.g. "6.6.2".
	 * @return array<string,array<int,string>>|null Relative path => acceptable md5s, or null when unknown/unavailable/invalid.
	 */
	public function core( string $version ): ?array {
		if ( 1 !== preg_match( self::VERSION_RE, $version ) ) {
			return null;
		}

		return $this->fetch(
			self::CACHE_PREFIX . 'core-' . $version,
			'/v1/checksums/core/' . $version,
			static function ( array $decoded ): ?array {
				return is_array( $decoded['checksums'] ?? null ) ? $decoded['checksums'] : null;
			}
		);
	}

	/**
	 * @param string $slug    Plugin directory slug.
	 * @param string $version Plugin version.
	 * @return array<string,array<int,string>>|null Package-relative path => acceptable md5s, or null when unknown/unavailable/invalid.
	 */
	public function plugin( string $slug, string $version ): ?array {
		return $this->software( 'plugin', $slug, $version );
	}

	/**
	 * @param string $slug    Theme directory slug.
	 * @param string $version Theme version.
	 * @return array<string,array<int,string>>|null Package-relative path => acceptable md5s, or null when unknown/unavailable/invalid.
	 */
	public function theme( string $slug, string $version ): ?array {
		return $this->software( 'theme', $slug, $version );
	}

	/**
	 * @param string $kind    'plugin' or 'theme'.
	 * @param string $slug    Package directory slug.
	 * @param string $version Package version.
	 * @return array<string,array<int,string>>|null
	 */
	private function software( string $kind, string $slug, string $version ): ?array {
		if ( 1 !== preg_match( self::SLUG_RE, $slug ) || 1 !== preg_match( self::VERSION_RE, $version ) ) {
			return null;
		}

		return $this->fetch(
			self::CACHE_PREFIX . $kind . '-' . $slug . '-' . $version,
			'/v1/checksums/' . $kind . '/' . $slug . '/' . $version,
			static function ( array $decoded ): ?array {
				if ( ! is_array( $decoded['files'] ?? null ) ) {
					return null;
				}

				$map = [];

				foreach ( $decoded['files'] as $path => $entry ) {
					if ( is_array( $entry ) && isset( $entry['md5'] ) ) {
						$map[ (string) $path ] = $entry['md5'];
					}
				}

				return $map;
			}
		);
	}

	/**
	 * @param string   $cache_key Cache key.
	 * @param string   $path      Backend request path.
	 * @param callable $extract   array<string,mixed> $decoded => array<string,mixed>|null (raw checksum values, normalised below).
	 * @return array<string,array<int,string>>|null
	 */
	private function fetch( string $cache_key, string $path, callable $extract ): ?array {
		$cached = $this->cache->get( $cache_key, 7 * DAY_IN_SECONDS );

		if ( is_array( $cached ) ) {
			if ( array_key_exists( 'missing', $cached ) ) {
				if ( (int) ( $cached['until'] ?? 0 ) > time() ) {
					return null;
				}
				// Stale miss marker (older than its 24h intent, but still
				// within the 7-day file TTL) — fall through and re-fetch.
			} else {
				// Maps cached by an older release store a bare md5 string per
				// path; normalize_map() turns those into single-entry lists.
				return is_array( $cached['map'] ?? null ) ? self::normalize_map( $cached['map'] ) : null;
			}
		}

		++self::$requests;

		try {
			$response = $this->client->get( $path );
		} catch ( NotFoundException $e ) {
			unset( $e );

			$this->cache->put(
				$cache_key,
				[
					'missing' => true,
					'until'   => time() + DAY_IN_SECONDS,
				]
			);

			return null;
		} catch ( RemoteException $e ) {
			unset( $e );

			return null;
		}

		$decoded = json_decode( $response['body'], true );
		$map     = is_array( $decoded ) ? $extract( $decoded ) : null;

		if ( null === $map ) {
			return null;
		}

		$map = self::normalize_map( $map );

		$this->cache->put( $cache_key, [ 'map' => $map ] );

		return $map;
	}

	/**
	 * @param array<string,mixed> $map Raw path => md5 (string, or array of strings) map.
	 * @return array<string,array<int,string>> Paths whose checksum could not be read at all are dropped — which also lets PathSignals' `unknown_core_path` fire for them, intentionally: an unverifiable core file is worth a look.
	 */
	private static function normalize_map( array $map ): array {
		$normalized = [];

		foreach ( $map as $path => $value ) {
			$hashes = self::normalize_hashes( $value );

			if ( [] !== $hashes ) {
				$normalized[ (string) $path ] = $hashes;
			}
		}

		return $normalized;
	}

	/**
	 * @param mixed $value One checksum value: a string, or an array of strings.
	 * @return array<int,string> Empty when nothing usable is there — the caller then treats the file as unknown, never as tampered with.
	 */
	private static function normalize_hashes( $value ): array {
		if ( is_string( $value ) ) {
			return '' === $value ? [] : [ $value ];
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$hashes = [];

		foreach ( $value as $hash ) {
			if ( is_string( $hash ) && '' !== $hash ) {
				$hashes[] = $hash;
			}
		}

		return $hashes;
	}
}
