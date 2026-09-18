<?php
/**
 * Registers the `wp lw-scan …` command surface.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Scan for malware, modified core files and vulnerable plugins.
 */
final class Commands {

	/** Synopsis of the three state commands: one or more finding ids. */
	private const ID_SYNOPSIS = [
		[
			'type'        => 'positional',
			'name'        => 'id',
			'description' => 'Finding ids, as listed by `wp lw-scan findings`.',
			'optional'    => false,
			'repeating'   => true,
		],
	];

	/**
	 * Called from `Plugin::init_components()` behind `defined( 'WP_CLI' )`.
	 *
	 * The bare `lw-scan` registration has to come first, and it points at
	 * this very class: WP-CLI turns a registered class with no
	 * `__invoke()` into a container command (skipping static methods, so
	 * `register()` never becomes a subcommand), and every `lw-scan <sub>`
	 * addition needs that container to already exist — otherwise WP-CLI
	 * defers the subcommand until a parent shows up, which here would
	 * never happen. The class docblock above is what `wp help lw-scan`
	 * prints, so it stays user-facing.
	 *
	 * `ack`/`ignore`/`reopen` are one `StateCommand` with three target
	 * states, registered as closures because WP-CLI instantiates a
	 * registered class without constructor arguments (spec §11.2).
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'lw-scan', self::class );

		WP_CLI::add_command( 'lw-scan run', RunCommand::class );
		WP_CLI::add_command( 'lw-scan status', StatusCommand::class );
		WP_CLI::add_command( 'lw-scan findings', FindingsCommand::class );
		WP_CLI::add_command( 'lw-scan bundle', BundleCommand::class );
		WP_CLI::add_command( 'lw-scan index', IndexCommand::class );
		WP_CLI::add_command( 'lw-scan stop', StopCommand::class );

		self::state( 'ack', 'acknowledged', 'Acknowledge findings: seen, decided, no longer new.' );
		self::state( 'ignore', 'ignored', 'Ignore findings: do not report them again.' );
		self::state( 'reopen', 'new', 'Reopen acknowledged or ignored findings.' );
	}

	/**
	 * @param string $name      Subcommand name (`ack`, `ignore`, `reopen`).
	 * @param string $state     State the subcommand writes.
	 * @param string $shortdesc One-line description for `wp help`.
	 * @return void
	 */
	private static function state( string $name, string $state, string $shortdesc ): void {
		WP_CLI::add_command(
			'lw-scan ' . $name,
			static function ( array $args, array $assoc ) use ( $state ): void {
				( new StateCommand( $state ) )( $args, $assoc );
			},
			[
				'shortdesc' => $shortdesc,
				'synopsis'  => self::ID_SYNOPSIS,
			]
		);
	}
}
