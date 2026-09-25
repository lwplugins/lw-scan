<?php
/**
 * Wordfence Intelligence attribution for vulnerability findings on the CLI.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Admin\Settings\VulnRecord;
use LightweightPlugins\Scan\Findings\Finding;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * A vulnerability finding printed on the terminal copies Wordfence
 * Intelligence content, and the WFI licence asks every copy to link to the
 * record, reproduce Defiant's copyright designation and reproduce the
 * licence itself — the same three things the Findings modal shows.
 *
 * JSON rows carry all of it per row (`with_fields()`). Table and CSV rows
 * carry the link (`Formatter::findings_rows()`'s `reference` column), and
 * the notice and licence text are printed once after the output
 * (`footer()`): on STDOUT for a table, on STDERR for CSV so the document
 * still parses. Everything comes verbatim from the record, never from here.
 */
final class Attribution {

	/**
	 * The attribution fields of one finding; empty strings for anything that
	 * is not a vulnerability finding or whose record lacks them.
	 *
	 * @param array<string, mixed> $finding Findings row, `meta` raw or decoded.
	 * @return array{reference:string, copyright:string, license:string, license_url:string}
	 */
	public static function fields( array $finding ): array {
		if ( 'vulnerability' !== ( $finding['type'] ?? '' ) ) {
			return [
				'reference'   => '',
				'copyright'   => '',
				'license'     => '',
				'license_url' => '',
			];
		}

		$finding = self::decoded( $finding );

		return [
			'reference'   => VulnRecord::reference( $finding ),
			'copyright'   => VulnRecord::copyright( $finding ),
			'license'     => VulnRecord::license( $finding ),
			'license_url' => VulnRecord::license_url( $finding ),
		];
	}

	/**
	 * Full JSON rows with the attribution fields added to each.
	 *
	 * @param array<int, array<string, mixed>> $rows Findings rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function with_fields( array $rows ): array {
		return array_map(
			static function ( array $row ): array {
				return array_merge( $row, self::fields( $row ) );
			},
			$rows
		);
	}

	/**
	 * The notice and licence lines to print after a table or CSV, one block
	 * per distinct wording; empty when no vulnerability row carries one.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings printed.
	 * @return array<int, string>
	 */
	public static function footer( array $findings ): array {
		$blocks = [];

		foreach ( $findings as $finding ) {
			$fields = self::fields( $finding );
			$text   = trim( $fields['copyright'] . ' ' . $fields['license'] );

			if ( '' === $text ) {
				continue;
			}

			$blocks[ $text . "\n" . $fields['license_url'] ] = '' === $fields['license_url']
				? $text
				: $text . "\nLicense terms: " . $fields['license_url'];
		}

		return array_values( $blocks );
	}

	/**
	 * Prints `footer()` after a table (STDOUT) or a CSV document (STDERR).
	 *
	 * @param array<int, array<string, mixed>> $findings Findings printed.
	 * @param bool                             $stderr   Whether stdout holds a machine-readable document.
	 * @return void
	 */
	public static function print_footer( array $findings, bool $stderr ): void {
		foreach ( self::footer( $findings ) as $block ) {
			if ( $stderr ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- attribution goes to STDERR so the CSV on STDOUT still parses.
				fwrite( STDERR, "\n" . $block . "\n" );
				continue;
			}

			WP_CLI::log( '' );
			WP_CLI::log( $block );
		}
	}

	/**
	 * @param array<string, mixed> $finding Findings row.
	 * @return array<string, mixed> The row with `meta` decoded.
	 */
	private static function decoded( array $finding ): array {
		if ( ! is_array( $finding['meta'] ?? null ) ) {
			$finding['meta'] = Finding::decode_map( (string) ( $finding['meta'] ?? '{}' ) );
		}

		return $finding;
	}
}
