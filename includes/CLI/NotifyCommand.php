<?php
/**
 * `wp lw-scan notify <status|enable|disable|level|recipients|limit|test>` —
 * the Notifications tab from the terminal.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The Notifications tab (`Admin\Settings\TabNotifications`) as a command:
 * the same options underneath (through `NotifyCli`), so a CLI call and an
 * admin-screen save leave identical state.
 *
 * All the deciding logic lives in `NotifyCli`, which has no `WP_CLI` in it
 * and is what the tests exercise — `WP_CLI` does not exist outside a real
 * WP-CLI process, the same reason `EndpointCommand` is split this way. This
 * class only turns `NotifyCli`'s plain result arrays into
 * `WP_CLI::success()`/`::error()`/`::line()` calls.
 *
 * `__invoke()`'s docblock is what `wp help lw-scan notify` prints, so it
 * stays user-facing — and its first line is a whole sentence on its own,
 * because WP-CLI lifts exactly that line into the subcommand list of
 * `wp help lw-scan` and cuts a two-line summary in half there.
 */
final class NotifyCommand {

	/**
	 * Show or change the scan e-mail settings.
	 *
	 * The switch, what it reports, who receives it and how long one message
	 * may get: the Notifications tab from the terminal.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : What to do.
	 * ---
	 * options:
	 *   - status
	 *   - enable
	 *   - disable
	 *   - level
	 *   - recipients
	 *   - limit
	 *   - test
	 * ---
	 *
	 * [<value>]
	 * : The new value. For `level`: alerts or review. For `recipients`: a comma-separated list. For `limit`: 10, 20, 50 or all. Omit it to print the current one.
	 *
	 * [--clear]
	 * : For `recipients`: empty the list, so mail goes to the site's admin address.
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
	 *     wp lw-scan notify status
	 *     wp lw-scan notify status --format=json
	 *     wp lw-scan notify disable
	 *     wp lw-scan notify enable
	 *     wp lw-scan notify level review
	 *     wp lw-scan notify level
	 *     wp lw-scan notify recipients ops@example.com,dev@example.com
	 *     wp lw-scan notify recipients --clear
	 *     wp lw-scan notify limit 10
	 *     wp lw-scan notify test
	 *
	 * @param array<int, string>   $args  Positional arguments: the operation, and its value.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		$operation = (string) ( $args[0] ?? '' );
		$value     = isset( $args[1] ) ? (string) $args[1] : null;
		$cli       = new NotifyCli();

		switch ( $operation ) {
			case 'status':
				$this->status( $cli, $assoc );
				return;
			case 'enable':
				WP_CLI::success( $cli->enable()['message'] );
				return;
			case 'disable':
				WP_CLI::success( $cli->disable()['message'] );
				return;
			case 'level':
				$this->report( $cli->level( $value ) );
				return;
			case 'recipients':
				$this->report( $cli->recipients( $value, (bool) Utils\get_flag_value( $assoc, 'clear', false ) ) );
				return;
			case 'limit':
				$this->report( $cli->limit( $value ) );
				return;
			case 'test':
				$this->report( $cli->test() );
				return;
			default:
				WP_CLI::error( sprintf( 'Unknown operation "%s". Use status, enable, disable, level, recipients, limit or test.', $operation ) );
		}
	}

	/**
	 * Prints a result that carries its own `ok` flag and message.
	 *
	 * @param array{ok:bool, message:string} $result What the sub-command decided.
	 * @return void
	 */
	private function report( array $result ): void {
		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['message'] );
			return;
		}

		WP_CLI::success( $result['message'] );
	}

	/**
	 * @param NotifyCli            $cli   Notification logic.
	 * @param array<string, mixed> $assoc Named arguments.
	 * @return void
	 */
	private function status( NotifyCli $cli, array $assoc ): void {
		$format = (string) Utils\get_flag_value( $assoc, 'format', '' );
		$status = $cli->status();

		if ( '' === $format ) {
			WP_CLI::line( Formatter::notify_summary( $status ) );
			return;
		}

		Utils\format_items( $format, Formatter::notify_status_rows( $status ), Formatter::SUMMARY_COLUMNS );
	}
}
