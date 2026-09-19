<?php
/**
 * `wp lw-scan endpoint <url|enable|disable|rotate|ttl|status>` — the status
 * endpoint from the terminal.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The Status tab (`Admin\Settings\TabStatus`) as a command: same
 * `Status\EndpointSettings` object underneath (through `EndpointCli`), so
 * a CLI call and an admin-screen click leave identical state. `endpoint`
 * is its own top-level command — not a `status` sub-command — because
 * `wp lw-scan status` already names the scan-status command.
 *
 * All the deciding logic lives in `EndpointCli`, which has no `WP_CLI` in
 * it and is what the tests exercise (`WP_CLI` does not exist outside a
 * real WP-CLI process, so this class cannot be unit tested the way the
 * rest of the CLI surface's pure logic is — the same reason none of
 * `StatusCommand`, `BundleCommand`, `IndexCommand`, `StopCommand` or
 * `StateCommand` are). This class only turns `EndpointCli`'s plain result
 * arrays into `WP_CLI::success()`/`::error()`/`::line()` calls.
 */
final class EndpointCommand {

	/**
	 * Show or change the status endpoint: its switch, its secret URL and
	 * how long a computed result is reused.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : What to do.
	 * ---
	 * options:
	 *   - url
	 *   - enable
	 *   - disable
	 *   - rotate
	 *   - ttl
	 *   - status
	 * ---
	 *
	 * [<minutes>]
	 * : New reuse period for `ttl`, in minutes — 1, 5, 15, 30 or 60. Omit to print the current one.
	 *
	 * [--porcelain]
	 * : For `url` and `rotate`: print only the URL, nothing else. Exits non-zero with no output when there is none.
	 *
	 * [--format=<format>]
	 * : Output format for `status`. Omit it for a one-line summary.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lw-scan endpoint url
	 *     wp lw-scan endpoint url --porcelain
	 *     wp lw-scan endpoint enable
	 *     wp lw-scan endpoint disable
	 *     wp lw-scan endpoint rotate
	 *     wp lw-scan endpoint ttl 15
	 *     wp lw-scan endpoint ttl
	 *     wp lw-scan endpoint status
	 *     wp lw-scan endpoint status --format=json
	 *
	 * @param array<int, string>   $args  Positional arguments: the operation, and the ttl minutes.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$operation = (string) ( $args[0] ?? '' );
		$cli       = new EndpointCli();

		switch ( $operation ) {
			case 'url':
				$this->url( $cli, $assoc );
				return;
			case 'enable':
				WP_CLI::success( $cli->enable()['message'] );
				return;
			case 'disable':
				WP_CLI::success( $cli->disable()['message'] );
				return;
			case 'rotate':
				$this->rotate( $cli, $assoc );
				return;
			case 'ttl':
				$this->ttl( $cli, $args );
				return;
			case 'status':
				$this->status( $cli, $assoc );
				return;
			default:
				WP_CLI::error( sprintf( 'Unknown operation "%s". Use url, enable, disable, rotate, ttl or status.', $operation ) );
		}
	}

	/**
	 * @param EndpointCli          $cli   Endpoint logic.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	private function url( EndpointCli $cli, array $assoc ): void {
		$result    = $cli->url();
		$porcelain = (bool) Utils\get_flag_value( $assoc, 'porcelain', false );

		if ( ! $result['ok'] ) {
			if ( $porcelain ) {
				WP_CLI::halt( 1 );
			}

			WP_CLI::error( $result['message'] );
			return;
		}

		if ( $porcelain ) {
			WP_CLI::line( $result['url'] );
			return;
		}

		if ( '' !== $result['message'] ) {
			WP_CLI::log( $result['message'] );
		}

		WP_CLI::success( $result['url'] );
	}

	/**
	 * @param EndpointCli          $cli   Endpoint logic.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	private function rotate( EndpointCli $cli, array $assoc ): void {
		$result    = $cli->rotate();
		$porcelain = (bool) Utils\get_flag_value( $assoc, 'porcelain', false );

		if ( $porcelain ) {
			WP_CLI::line( $result['url'] );
			return;
		}

		WP_CLI::success( $result['url'] );
		WP_CLI::warning( 'The previous URL no longer works.' );
	}

	/**
	 * @param EndpointCli        $cli  Endpoint logic.
	 * @param array<int, string> $args Positional arguments: the operation, and the ttl minutes.
	 * @return void
	 */
	private function ttl( EndpointCli $cli, array $args ): void {
		$raw     = $args[1] ?? null;
		$minutes = null === $raw ? null : absint( $raw );

		$result = $cli->ttl( $minutes );

		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( sprintf( 'Reuse period: %d minute%s.', $result['minutes'], 1 === $result['minutes'] ? '' : 's' ) );
	}

	/**
	 * @param EndpointCli          $cli   Endpoint logic.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	private function status( EndpointCli $cli, array $assoc ): void {
		$format = (string) Utils\get_flag_value( $assoc, 'format', '' );
		$status = $cli->status();

		if ( '' === $format ) {
			WP_CLI::line( Formatter::endpoint_summary( $status ) );
			return;
		}

		Utils\format_items( $format, Formatter::endpoint_status_rows( $status ), Formatter::SUMMARY_COLUMNS );
	}
}
