<?php
/**
 * Registers the lw-scan abilities with the WordPress Abilities API.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\SiteManager;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.3: four abilities in an `lw-scan` category — start a scan, read
 * its status, page through the findings and change their state — so an AI
 * agent (LW Site Manager's MCP server, `@wordpress/abilities`, the
 * `wp-abilities/v1` REST namespace) can drive the scanner.
 *
 * The Abilities API landed in WordPress 6.9 and the plugin supports 6.0, so
 * every registration is guarded twice: by the API's own init hooks, which
 * only fire where the API exists, and by a `function_exists()` check for
 * the site that has the hooks but not the functions. Categories must be
 * registered on `wp_abilities_api_categories_init` and abilities on
 * `wp_abilities_api_init` — registering outside them triggers
 * `_doing_it_wrong()` and fails.
 *
 * Only the schemas and the hook wiring live here; the work each ability
 * does is in `AbilityCallbacks`.
 */
final class Abilities {

	/** Ability category slug; also the ability-name prefix. */
	private const CATEGORY = 'lw-scan';

	/** @var bool Whether the category has been registered this request. */
	private static bool $category_registered = false;

	/** @var bool Whether the abilities have been registered this request. */
	private static bool $abilities_registered = false;

	/**
	 * Hooks both Abilities API init actions. Safe to call unconditionally:
	 * on a site without the API neither hook ever fires.
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
	}

	/**
	 * Registers the `lw-scan` category the four abilities belong to.
	 */
	public static function register_category(): void {
		if ( self::$category_registered || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		self::$category_registered = true;

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Malware scan', 'lw-scan' ),
				'description' => __( 'Run malware scans and read their findings.', 'lw-scan' ),
			]
		);
	}

	/**
	 * Registers all four abilities.
	 */
	public static function register_abilities(): void {
		if ( self::$abilities_registered || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::$abilities_registered = true;

		self::register_run();
		self::register_status();
		self::register_findings();
		self::register_acknowledge();
	}

	/**
	 * Test-only: clears the once-per-request guards so a test can register
	 * again in a fresh scenario.
	 */
	public static function reset(): void {
		self::$category_registered  = false;
		self::$abilities_registered = false;
	}

	/**
	 * `lw-scan/run` — opens a scan and returns immediately; the WP-Cron
	 * tick relay does the work.
	 */
	private static function register_run(): void {
		wp_register_ability(
			'lw-scan/run',
			[
				'label'               => __( 'Start Malware Scan', 'lw-scan' ),
				'description'         => __( 'Start a malware scan and return its run id. The scan runs in the background; poll lw-scan/status for progress.', 'lw-scan' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => [ AbilityCallbacks::class, 'run' ],
				'permission_callback' => self::permission(),
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'scope' ],
					'properties' => [
						'scope'  => [
							'type'        => 'string',
							'description' => __( 'What to scan: the whole site, only changed files, the database, or one folder.', 'lw-scan' ),
							'enum'        => [ 'full', 'changed', 'db', 'path' ],
						],
						'path'   => [
							'type'        => 'string',
							'description' => __( 'Folder to scan, relative to the WordPress root. Used with scope=path.', 'lw-scan' ),
							'default'     => '',
						],
						'resume' => [
							'type'        => 'boolean',
							'description' => __( 'Continue a stopped scan instead of starting a new one.', 'lw-scan' ),
							'default'     => false,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'run_id'  => [ 'type' => 'integer' ],
						'started' => [ 'type' => 'boolean' ],
					],
				],
				'meta'                => self::meta( false, false, false ),
			]
		);
	}

	/**
	 * `lw-scan/status` — progress of the running scan, or the last one.
	 */
	private static function register_status(): void {
		wp_register_ability(
			'lw-scan/status',
			[
				'label'               => __( 'Get Scan Status', 'lw-scan' ),
				'description'         => __( 'Get the progress of the running scan (or the last one), the last run summary and the current finding counts.', 'lw-scan' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => [ AbilityCallbacks::class, 'status' ],
				'permission_callback' => self::permission(),
				'input_schema'        => [
					'type'    => 'object',
					'default' => [],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'progress' => [ 'type' => 'object' ],
						'last_run' => [ 'type' => [ 'object', 'null' ] ],
						'counts'   => [ 'type' => 'object' ],
					],
				],
				'meta'                => self::meta( true, false, true ),
			]
		);
	}

	/**
	 * `lw-scan/findings` — one filtered page of findings.
	 */
	private static function register_findings(): void {
		wp_register_ability(
			'lw-scan/findings',
			[
				'label'               => __( 'List Scan Findings', 'lw-scan' ),
				'description'         => __( 'List malware-scan findings, filtered by severity, type or state, one page at a time.', 'lw-scan' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => [ AbilityCallbacks::class, 'findings' ],
				'permission_callback' => self::permission(),
				'input_schema'        => self::findings_input_schema(),
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'items' => [
							'type'  => 'array',
							'items' => [ 'type' => 'object' ],
						],
						'total' => [ 'type' => 'integer' ],
					],
				],
				'meta'                => self::meta( true, false, true ),
			]
		);
	}

	/**
	 * The filters and paging `lw-scan/findings` accepts. Its own method
	 * because the literal alone is longer than a method may be; the values
	 * mirror what `AbilityCallbacks::findings_query()` enforces for callers
	 * that reach the callback without the schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function findings_input_schema(): array {
		return [
			'type'       => 'object',
			'default'    => [],
			'properties' => [
				'severity' => [
					'type'        => 'string',
					'description' => __( 'Only findings of this severity.', 'lw-scan' ),
					'enum'        => [ 'alert', 'review' ],
				],
				'type'     => [
					'type'        => 'string',
					'description' => __( 'Only findings of this type.', 'lw-scan' ),
					'enum'        => [ 'file', 'integrity', 'db', 'vulnerability' ],
				],
				'state'    => [
					'type'        => 'string',
					'description' => __( 'Only findings in this state.', 'lw-scan' ),
					'enum'        => [ 'new', 'acknowledged', 'ignored' ],
				],
				'page'     => [
					'type'        => 'integer',
					'description' => __( '1-based page number.', 'lw-scan' ),
					'default'     => 1,
					'minimum'     => 1,
				],
				'per_page' => [
					'type'        => 'integer',
					'description' => __( 'Findings per page. Defaults to 50, max 200.', 'lw-scan' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				],
			],
		];
	}

	/**
	 * `lw-scan/acknowledge` — bulk state change on a set of findings.
	 */
	private static function register_acknowledge(): void {
		wp_register_ability(
			'lw-scan/acknowledge',
			[
				'label'               => __( 'Set Finding State', 'lw-scan' ),
				'description'         => __( 'Mark findings as acknowledged or ignored, or move them back to new. Never changes a file or the database.', 'lw-scan' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => [ AbilityCallbacks::class, 'acknowledge' ],
				'permission_callback' => self::permission(),
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'ids', 'state' ],
					'properties' => [
						'ids'   => [
							'type'        => 'array',
							'description' => __( 'Finding ids to update.', 'lw-scan' ),
							'items'       => [ 'type' => 'integer' ],
						],
						'state' => [
							'type'        => 'string',
							'description' => __( 'The state to set.', 'lw-scan' ),
							'enum'        => [ 'new', 'acknowledged', 'ignored' ],
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'updated' => [ 'type' => 'integer' ],
					],
				],
				'meta'                => self::meta( false, true, true ),
			]
		);
	}

	/**
	 * Every ability is an administrator tool (spec §13).
	 *
	 * @return callable(): bool
	 */
	private static function permission(): callable {
		return static function () {
			return current_user_can( 'manage_options' );
		};
	}

	/**
	 * Ability metadata. The three annotations are independent (see
	 * `wp_register_ability()`), so each ability states its own:
	 *
	 * - `lw-scan/run` — writes (a run row, a cursor, a scheduled tick), adds
	 *   rather than overwrites, and is *not* idempotent: calling it again
	 *   opens another scan or is refused as busy.
	 * - `lw-scan/status` / `lw-scan/findings` — read-only, so idempotent.
	 * - `lw-scan/acknowledge` — overwrites the `state` column of existing
	 *   findings and can bulk-move alerts to `ignored`, which is a
	 *   destructive update in the API's sense (not additive-only); setting
	 *   the same state twice changes nothing more, so it is idempotent.
	 *
	 * None of them ever touches a file, a post or a site setting: the
	 * scanner reports, it never repairs.
	 *
	 * @param bool $readonly    Whether the ability leaves its environment untouched.
	 * @param bool $destructive Whether it may overwrite or remove existing data (false = additive only).
	 * @param bool $idempotent  Whether repeating the same call has no further effect.
	 * @return array<string, mixed>
	 */
	private static function meta( bool $readonly, bool $destructive, bool $idempotent ): array {
		return [
			'show_in_rest' => true,
			'annotations'  => [
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			],
		];
	}
}
