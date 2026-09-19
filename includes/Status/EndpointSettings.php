<?php
/**
 * Status endpoint settings.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Status;

defined( 'ABSPATH' ) || exit;

/**
 * One option holding the switch, the secret key and the cache TTL of the
 * read-only status endpoint (`StatusRoute`).
 *
 * The endpoint is on by default: a missing `enabled` key reads as true, so
 * a site only has to be given a key — on activation, or the first time an
 * admin opens the Status tab — to be monitorable.
 *
 * The option is not autoloaded. It is read on `rest_api_init` and on the
 * Status tab only, never on an ordinary page load, so it has no business
 * in every request's `alloptions`.
 */
final class EndpointSettings {

	public const OPTION = 'lw_scan_status_endpoint';

	public const DEFAULT_TTL = 300;

	public const MIN_TTL = 60;

	public const MAX_TTL = 3600;

	/** The reuse periods the Status tab offers, in seconds. */
	public const TTL_CHOICES = [ 60, 300, 900, 1800, 3600 ];

	public function is_enabled(): bool {
		return (bool) ( $this->read()['enabled'] ?? true );
	}

	/**
	 * Switching the endpoint drops the cached report, so a monitor that
	 * reaches it again never gets a result older than the switch.
	 *
	 * @param bool $enabled New state.
	 */
	public function set_enabled( bool $enabled ): void {
		$changed = $enabled !== $this->is_enabled();

		$this->write( [ 'enabled' => $enabled ] );

		if ( $changed ) {
			StatusReport::clear_cache();
		}
	}

	/**
	 * Seconds a computed report is reused, clamped to 60..3600.
	 */
	public function cache_ttl(): int {
		return self::clamp_ttl( (int) ( $this->read()['cache_ttl'] ?? self::DEFAULT_TTL ) );
	}

	/**
	 * @param int $seconds TTL; clamped to 60..3600.
	 */
	public function set_cache_ttl( int $seconds ): void {
		$this->write( [ 'cache_ttl' => self::clamp_ttl( $seconds ) ] );
	}

	/**
	 * The stored key.
	 *
	 * @return string 32 lowercase hex characters, or '' when there is none
	 *                (or what is stored is not a well-formed key).
	 */
	public function key(): string {
		$key = $this->read()['key'] ?? '';

		return is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $key ) ? $key : '';
	}

	public function has_key(): bool {
		return '' !== $this->key();
	}

	/**
	 * When the current key was created (Unix time), 0 when there is none.
	 */
	public function key_set_at(): int {
		return (int) ( $this->read()['key_set_at'] ?? 0 );
	}

	/**
	 * Creates a new key; the previous URL stops working the moment this
	 * returns. Stored in the clear — the Status tab shows the URL again on
	 * request — and the cached report is dropped with it.
	 *
	 * @return string The new key.
	 */
	public function generate_key(): string {
		$key = bin2hex( random_bytes( 16 ) );

		$this->write(
			[
				'key'        => $key,
				'key_set_at' => time(),
			]
		);

		StatusReport::clear_cache();

		return $key;
	}

	/**
	 * The key, generating one only when there is none yet. Admin-side and
	 * activation callers only: a public request must never mint a key.
	 *
	 * @return string The existing or the new key.
	 */
	public function ensure_key(): string {
		$key = $this->key();

		return '' !== $key ? $key : $this->generate_key();
	}

	/**
	 * Whether the key presented in a request path is the stored one.
	 *
	 * @param string $presented Key from the request path.
	 */
	public function verify_key( string $presented ): bool {
		$key = $this->key();

		return '' !== $key && '' !== $presented && hash_equals( $key, $presented );
	}

	/**
	 * The full secret URL, or '' when there is no key.
	 */
	public function status_url(): string {
		$key = $this->key();

		if ( '' === $key ) {
			return '';
		}

		return rest_url( StatusRoute::NAMESPACE . '/status/' . $key );
	}

	/**
	 * @param int $seconds Requested TTL.
	 */
	private static function clamp_ttl( int $seconds ): int {
		return max( self::MIN_TTL, min( self::MAX_TTL, $seconds ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read(): array {
		$value = get_option( self::OPTION, [] );

		return is_array( $value ) ? $value : [];
	}

	/**
	 * Merges the changes over what is stored — itself merged over the
	 * defaults, so the row always carries the documented shape — and
	 * saves. The first write goes through `add_option()`, because
	 * `update_option()` ignores the autoload flag of a row that exists.
	 *
	 * @param array<string, mixed> $changes Keys to overwrite.
	 */
	private function write( array $changes ): void {
		$stored = get_option( self::OPTION, false );
		$merged = array_merge( self::defaults(), is_array( $stored ) ? $stored : [], $changes );

		if ( false === $stored ) {
			add_option( self::OPTION, $merged, '', false );
			return;
		}

		update_option( self::OPTION, $merged, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function defaults(): array {
		return [
			'enabled'    => true,
			'key'        => '',
			'key_set_at' => 0,
			'cache_ttl'  => self::DEFAULT_TTL,
		];
	}
}
