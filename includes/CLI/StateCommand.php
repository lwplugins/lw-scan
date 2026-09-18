<?php
/**
 * `wp lw-scan ack|ignore|reopen` — findings state changes.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Db\FindingsRepository;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * One class behind three commands: the target state is a constructor
 * argument, so `Commands::register()` registers a closure per subcommand
 * (`acknowledged`, `ignored`, `new`) rather than the class name — WP-CLI
 * instantiates a registered class with no arguments.
 *
 * The same three states the Findings tab's row actions write (spec §9),
 * through the same repository call, so a bulk acknowledge from the
 * terminal and one from the browser are indistinguishable afterwards.
 */
final class StateCommand {

	/** @var string State to write: new|acknowledged|ignored. */
	private string $state;

	/**
	 * @param string $state new|acknowledged|ignored.
	 */
	public function __construct( string $state ) {
		$this->state = $state;
	}

	/**
	 * @param array<int, string>   $args  Finding ids.
	 * @param array<string, mixed> $assoc Named arguments (unused).
	 * @return void
	 */
	public function __invoke( array $args, array $assoc ): void {
		unset( $assoc );

		$ids = array_values( array_filter( array_map( 'absint', $args ) ) );

		if ( [] === $ids ) {
			WP_CLI::error( 'Pass at least one finding id.' );
		}

		$updated = FindingsRepository::set_state( $ids, $this->state );

		if ( 0 === $updated ) {
			WP_CLI::warning( 'No finding matched those ids.' );

			return;
		}

		WP_CLI::success( sprintf( 1 === $updated ? '%d finding is now %s.' : '%d findings are now %s.', $updated, $this->state ) );
	}
}
