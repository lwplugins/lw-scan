<?php
/**
 * The Findings tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §11.1: chips, search, and a 50-rows-per-page table with an
 * expandable detail panel per finding.
 *
 * The filter parameters arrive in the query string — this is a read-only
 * list view, so there is no nonce to verify; every value is nevertheless
 * whitelisted or `sanitize_text_field`'d before it reaches the repository,
 * which prepares them as placeholders.
 */
final class TabFindings implements TabInterface {

	/** Values `?severity=` may take. */
	private const SEVERITIES = [ 'alert', 'review' ];

	/** Values `?type=` may take. */
	private const TYPES = [ 'file', 'integrity', 'db', 'vulnerability' ];

	/** Values `?state=` may take. */
	private const STATES = [ 'new', 'acknowledged', 'ignored' ];

	public function slug(): string {
		return 'findings';
	}

	public function label(): string {
		return __( 'Findings', 'lw-scan' );
	}

	public function icon(): string {
		return 'dashicons-warning';
	}

	public function has_save(): bool {
		return false;
	}

	public function render(): void {
		if ( ! Schema::exists() ) {
			ScanHero::render_missing_tables();

			return;
		}

		$filters = self::filters();
		$page    = self::page();
		$counts  = FindingsRepository::counts();
		$list    = FindingsRepository::list( array_filter( $filters ), $page, FindingsTable::PER_PAGE );

		echo '<div class="lw-scan-stack" data-lw-scan-tab="findings">';
		FindingsFilters::render( $filters, $counts );
		FindingsTable::render( $list['items'], (int) $list['total'], $page );
		echo '</div>';
	}

	/**
	 * The query-string filters, whitelisted.
	 *
	 * @return array<string, string>
	 */
	private static function filters(): array {
		return [
			'severity' => self::one_of( 'severity', self::SEVERITIES ),
			'type'     => self::one_of( 'type', self::TYPES ),
			'state'    => self::one_of( 'state', self::STATES ),
			'search'   => self::search(),
		];
	}

	/**
	 * @param string   $key     Query-string key.
	 * @param string[] $allowed Accepted values.
	 */
	private static function one_of( string $key, array $allowed ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter; the value is whitelisted on the next line.
		$value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	private static function search(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter, sanitized here and passed to the repository as a prepared placeholder.
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}

	private static function page(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination parameter.
		return max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
	}
}
