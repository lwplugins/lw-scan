<?php
/**
 * Backend-driven lifecycle of the signature pack (spec §5.2).
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Remote;

use LightweightPlugins\Scan\Bundle\NewSignaturesBuilder;
use LightweightPlugins\Scan\Bundle\Pack;
use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\PackMeta;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Health\Environment;
use LightweightPlugins\Scan\State;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the pack lifecycle from design spec §5.2: check the backend
 * pointer (`/v1/pack/latest`), download `pack-<v>.json` and `meta-<v>.json`,
 * verify them, persist them, and record which signatures are new relative
 * to whatever was stored before. The only writer of the `bundle_version`,
 * `bundle_count`, `bundle_sha256`, `bundle_etag` and `bundle_checked_at`
 * State keys.
 */
final class PackFetcher {

	/** @var Client */
	private Client $client;

	/** @var Store */
	private Store $store;

	public function __construct( Client $client, Store $store ) {
		$this->client = $client;
		$this->store  = $store;
	}

	/**
	 * @param bool $force Bypass the "checked within the last hour" skip.
	 * @return array{status:string,version:int,message:string,new_ids:string[]}
	 */
	public function check( bool $force = false ): array {
		$stored = (int) State::get( 'bundle_version', 0 );

		if ( ! $force
			&& (int) State::get( 'bundle_checked_at', 0 ) > time() - HOUR_IN_SECONDS
			&& $this->store->has_pack( $stored ) ) {
			return $this->result( 'skipped', $stored, 'Signatures checked within the last hour; skipping.', [] );
		}

		return $this->run( $stored, $force );
	}

	/**
	 * Ignores the "checked within the last hour" skip, the ETag and the
	 * "already at this version" short-circuit: always re-downloads and
	 * re-verifies the current pack, even when it is the one already stored.
	 *
	 * @return array{status:string,version:int,message:string,new_ids:string[]}
	 */
	public function force_full(): array {
		return $this->run( (int) State::get( 'bundle_version', 0 ), true );
	}

	/**
	 * @param int  $stored Currently stored pack version (0 = none).
	 * @param bool $force  Skip the ETag header and the same-version short-circuit.
	 * @return array{status:string,version:int,message:string,new_ids:string[]}
	 */
	private function run( int $stored, bool $force ): array {
		try {
			$latest = $this->client->get( '/v1/pack/latest', [ 'headers' => $force ? [] : $this->conditional_headers() ] );
		} catch ( RemoteException $e ) {
			return $this->result( 'failed', $stored, $e->getMessage(), [] );
		}

		if ( 304 === $latest['code'] ) {
			if ( $this->store->has_pack( $stored ) ) {
				State::merge( [ 'bundle_checked_at' => time() ] );
				$this->store->prune_except( $stored );

				return $this->result( 'unchanged', $stored, 'Not modified.', [] );
			}

			// The stored files are gone locally (deleted/corrupted out of
			// band): a conditional 304 tells us nothing usable, so repeat
			// the request without the header exactly as if forced.
			try {
				$latest = $this->client->get( '/v1/pack/latest' );
			} catch ( RemoteException $e ) {
				return $this->result( 'failed', $stored, $e->getMessage(), [] );
			}
		}

		$pointer = json_decode( $latest['body'], true );
		$error   = $this->pointer_error( $pointer );

		if ( null !== $error ) {
			return $this->result( 'failed', $stored, $error, [] );
		}

		$version = (int) $pointer['version'];

		if ( ! $force && $version === $stored && $this->store->has_pack( $stored ) ) {
			State::merge(
				[
					'bundle_checked_at' => time(),
					'bundle_etag'       => $this->etag( $latest['headers'], (string) $pointer['pack']['sha256'] ),
				]
			);
			$this->store->prune_except( $stored );

			return $this->result( 'unchanged', $stored, 'Already at the latest signature version.', [] );
		}

		return $this->update( $stored, $version, $pointer, $latest['headers'] );
	}

	/**
	 * Validates a decoded `/v1/pack/latest` body against spec §5.2 step 4.
	 *
	 * @param mixed $pointer Decoded response body.
	 * @return string|null Failure message, or null when the pointer is well-formed.
	 */
	private function pointer_error( $pointer ): ?string {
		if ( ! is_array( $pointer )
			|| ! isset( $pointer['version'], $pointer['format'], $pointer['signature_count'] )
			|| ! is_int( $pointer['version'] ) || $pointer['version'] <= 0
			|| ! is_int( $pointer['format'] )
			|| ! is_int( $pointer['signature_count'] ) ) {
			return 'Malformed signature pointer response.';
		}

		if ( Pack::FORMAT !== $pointer['format'] ) {
			return sprintf( 'The signature pack uses format %d, which this version of LW Scan does not understand. Update the plugin.', $pointer['format'] );
		}

		foreach ( [ 'pack', 'meta' ] as $part ) {
			if ( ! isset( $pointer[ $part ] ) || ! is_array( $pointer[ $part ] )
				|| ! self::is_backend_path( $pointer[ $part ]['url'] ?? null )
				|| ! self::is_sha256( $pointer[ $part ]['sha256'] ?? null ) ) {
				return 'Malformed signature pointer response.';
			}
		}

		return null;
	}

	/**
	 * @param int                  $stored         Currently stored pack version.
	 * @param int                  $version        New pack version from the pointer.
	 * @param array<string,mixed>  $pointer        Decoded, validated `/v1/pack/latest` body.
	 * @param array<string,string> $latest_headers Response headers from the `/v1/pack/latest` call.
	 * @return array{status:string,version:int,message:string,new_ids:string[]}
	 */
	private function update( int $stored, int $version, array $pointer, array $latest_headers ): array {
		try {
			$pack_body = $this->download( (string) $pointer['pack']['url'], (string) $pointer['pack']['sha256'] );

			if ( null === $pack_body ) {
				return $this->result( 'failed', $stored, 'The signature pack failed verification.', [] );
			}

			$meta_body = $this->download( (string) $pointer['meta']['url'], (string) $pointer['meta']['sha256'] );

			if ( null === $meta_body ) {
				return $this->result( 'failed', $stored, 'The signature pack failed verification.', [] );
			}
		} catch ( RemoteException $e ) {
			return $this->result( 'failed', $stored, $e->getMessage(), [] );
		}

		$loaded = $this->decode( $pack_body, $meta_body, $version );

		if ( null === $loaded ) {
			return $this->result( 'failed', $stored, 'The signature pack failed verification.', [] );
		}

		[ $pack, $new_meta ] = $loaded;

		if ( ! $this->write( $version, $stored, $pack_body, $meta_body ) ) {
			return $this->result( 'failed', $stored, 'The signature pack could not be written — check that wp-content/lw-scan is writable.', [] );
		}

		$new_ids = $this->record_new( $stored, $version, $pack, $new_meta );

		$this->merge_state( $version, $pointer, $latest_headers );
		$this->store->prune_except( $version );
		PackLoader::reset();
		Environment::invalidate();

		return $this->result( 'updated', $version, sprintf( 'Updated to signature version %d.', $version ), $new_ids );
	}

	/**
	 * Downloads and verifies a pack/meta blob.
	 *
	 * @param string $url    Backend path to GET.
	 * @param string $sha256 Expected sha256 of the (decompressed) body.
	 * @return string|null The verified body, or null when it does not match `$sha256`.
	 */
	private function download( string $url, string $sha256 ): ?string {
		$response = $this->client->get( $url, [ 'timeout' => 60 ] );
		$body     = $response['body'];

		if ( "\x1f\x8b" === substr( $body, 0, 2 ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-gzip or corrupt body is a normal "leave it as-is, let the sha256 check below fail" outcome here, not a bug to surface.
			$decompressed = @gzdecode( $body );

			if ( false !== $decompressed ) {
				$body = $decompressed;
			}
		}

		return hash( 'sha256', $body ) === $sha256 ? $body : null;
	}

	/**
	 * Whether a sha256-verified pack/meta pair actually loads: the hash only
	 * proves transport integrity, not that `PackIntegrity` accepts the
	 * shape, or that the two files describe the same pack. Either failure,
	 * written and promoted to `bundle_version` anyway, would leave
	 * `PackLoader` returning null for good — silently breaking every scan
	 * from then on.
	 *
	 * @param string $pack_body Sha256-verified pack JSON.
	 * @param string $meta_body Sha256-verified meta JSON.
	 * @param int    $version   The pointer's pack version.
	 * @return array{0:Pack,1:PackMeta}|null
	 */
	private function decode( string $pack_body, string $meta_body, int $version ): ?array {
		$pack_data = json_decode( $pack_body, true );
		$pack      = is_array( $pack_data ) ? Pack::from_array( $pack_data ) : null;
		unset( $pack_data );

		$meta_data = json_decode( $meta_body, true );
		$meta      = is_array( $meta_data ) ? PackMeta::from_array( $meta_data ) : null;
		unset( $meta_data );

		if ( null === $pack || null === $meta
			|| $pack->version() !== $version || $meta->version() !== $version
			|| $pack->count() !== $meta->count() ) {
			return null;
		}

		return [ $pack, $meta ];
	}

	/**
	 * @param int    $version   New pack version.
	 * @param int    $stored    Currently stored pack version.
	 * @param string $pack_body Verified pack JSON.
	 * @param string $meta_body Verified meta JSON.
	 * @return bool Whether both files were written.
	 */
	private function write( int $version, int $stored, string $pack_body, string $meta_body ): bool {
		if ( $this->store->ensure_dir()
			&& $this->store->write_atomic( $this->store->pack_path( $version ), $pack_body )
			&& $this->store->write_atomic( $this->store->meta_path( $version ), $meta_body ) ) {
			return true;
		}

		if ( $version !== $stored ) {
			$this->store->delete_version( $version );
		}

		return false;
	}

	/**
	 * Builds and persists `new-<version>.json` when the previously stored
	 * version's meta is still on disk and readable. A missing previous
	 * version, or an old meta that is missing/corrupt, leaves no
	 * new-signatures file: the next scan simply checks every changed file
	 * against the whole pack, same as today.
	 *
	 * @param int      $stored   Currently stored pack version (0 = none).
	 * @param int      $version  New pack version.
	 * @param Pack     $pack     The new, already-loaded pack.
	 * @param PackMeta $new_meta The new, already-loaded meta.
	 * @return string[] New signature ids, in sig order.
	 */
	private function record_new( int $stored, int $version, Pack $pack, PackMeta $new_meta ): array {
		if ( 0 === $stored || $stored === $version ) {
			return [];
		}

		$old_meta = $this->read_meta( $this->store->meta_path( $stored ) );

		if ( null === $old_meta ) {
			return [];
		}

		$diff = NewSignaturesBuilder::build( $pack, $new_meta, $old_meta );

		if ( null !== $diff ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() is unavailable here; Bundle\Store already writes pack/meta files with plain json_encode()/file_put_contents() via write_atomic().
			$this->store->write_atomic( $this->store->new_path( $version ), (string) json_encode( $diff ) );
		}

		return NewSignaturesBuilder::new_ids( $new_meta, $old_meta );
	}

	/**
	 * @param string $path Absolute meta file path.
	 */
	private function read_meta( string $path ): ?PackMeta {
		if ( ! is_file( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local meta file under the plugin's own storage dir, not a remote URL.
		$json = file_get_contents( $path );
		$data = false === $json ? null : json_decode( $json, true );

		return is_array( $data ) ? PackMeta::from_array( $data ) : null;
	}

	/**
	 * @param int                  $version        New pack version.
	 * @param array<string,mixed>  $pointer        Decoded, validated `/v1/pack/latest` body.
	 * @param array<string,string> $latest_headers Response headers from the `/v1/pack/latest` call.
	 */
	private function merge_state( int $version, array $pointer, array $latest_headers ): void {
		State::merge(
			[
				'bundle_version'    => $version,
				'bundle_count'      => (int) $pointer['signature_count'],
				'bundle_sha256'     => (string) $pointer['pack']['sha256'],
				'bundle_etag'       => $this->etag( $latest_headers, (string) $pointer['pack']['sha256'] ),
				'bundle_checked_at' => time(),
			]
		);
	}

	/**
	 * @param array<string,string> $headers Response headers.
	 * @param string               $sha256  The pack's sha256, used as a fallback identity.
	 */
	private function etag( array $headers, string $sha256 ): string {
		return $headers['etag'] ?? ( 'pack-' . $sha256 );
	}

	/**
	 * Whether a pointer's `url` is a path on the backend rather than
	 * somewhere else entirely.
	 *
	 * `Remote\Client::get()` prefixes what it is handed with the backend base
	 * URL, so a pointer carrying an absolute URL either produces a nonsense
	 * request or, on a transport that tolerates it, sends the request to a
	 * host the site owner never agreed to talk to. `//host/path` is a
	 * protocol-relative URL and is refused for the same reason, despite
	 * starting with a slash.
	 *
	 * @param mixed $url Candidate `url` value.
	 */
	private static function is_backend_path( $url ): bool {
		return is_string( $url ) && 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' );
	}

	/**
	 * @param mixed $value Candidate `sha256` value.
	 */
	private static function is_sha256( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $value );
	}

	/**
	 * @return array<string,string>
	 */
	private function conditional_headers(): array {
		$etag = (string) State::get( 'bundle_etag', '' );

		return '' !== $etag ? [ 'If-None-Match' => $etag ] : [];
	}

	/**
	 * @param string   $status  One of 'unchanged'|'updated'|'skipped'|'failed'.
	 * @param int      $version Current stored pack version after the call.
	 * @param string   $message Human-readable outcome description.
	 * @param string[] $new_ids Signature ids added since the previously stored version.
	 * @return array{status:string,version:int,message:string,new_ids:string[]}
	 */
	private function result( string $status, int $version, string $message, array $new_ids ): array {
		return [
			'status'  => $status,
			'version' => $version,
			'message' => $message,
			'new_ids' => $new_ids,
		];
	}
}
