<?php
/**
 * Matches installed software against the vulnerability feed and records findings.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Vuln;

use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Remote\VulnerabilityProvider;
use LightweightPlugins\Scan\Remote\VulnerabilityProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Resumable (spec §8.3/§10.2): `run()` walks `$software` from
 * `$start_index`, checking `$deadline` between items. A software item the
 * provider can't resolve this round (transient error, not a missing
 * endpoint) is left untouched — neither upserted nor deleted. `delete_vuln_not_in()`
 * only runs once the whole list has been walked in this call (`done === true`
 * from a full pass, not from the `endpoint_unavailable` short-circuit), and
 * its keep-set is always every locator in the full `$software` array — not
 * just the ones visited on this call — so an earlier tick's findings (items
 * before `$start_index`) and a transient-failure item's existing finding
 * both survive the cleanup instead of being wrongly wiped.
 *
 * On the `endpoint_unavailable` short-circuit, `index` points at the item
 * whose lookup surfaced the missing endpoint (not advanced past it) — the
 * intended convention for a caller resuming this same item once the
 * endpoint exists.
 */
final class Matcher {

	/** @var VulnerabilityProviderInterface */
	private VulnerabilityProviderInterface $provider;

	/** @var FindingsRepositoryInterface */
	private FindingsRepositoryInterface $findings;

	public function __construct( VulnerabilityProviderInterface $provider, FindingsRepositoryInterface $findings ) {
		$this->provider = $provider;
		$this->findings = $findings;
	}

	/**
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $software    Result of InstalledSoftware::list().
	 * @param int                                                                                   $start_index Index to resume from.
	 * @param callable|null                                                                         $deadline    Returns true once the current tick's time budget is spent.
	 * @return array{index:int, done:bool, lookups:int, findings:int, skipped:?string, skipped_items:int}
	 */
	public function run( array $software, int $start_index = 0, ?callable $deadline = null ): array {
		$total         = count( $software );
		$lookups       = 0;
		$findings      = 0;
		$skipped_items = 0;

		for ( $index = $start_index; $index < $total; $index++ ) {
			if ( $index > $start_index && null !== $deadline && $deadline() ) {
				return $this->result( $index, false, $lookups, $findings, null, $skipped_items );
			}

			$item    = $software[ $index ];
			$locator = $item['kind'] . ':' . $item['slug'];

			$records = $this->provider->for_software( $item['kind'], $item['slug'] );
			++$lookups;

			if ( null === $records ) {
				if ( VulnerabilityProvider::endpoint_missing() ) {
					return $this->result( $index, true, $lookups, $findings, 'endpoint_unavailable', $skipped_items );
				}

				++$skipped_items;
				continue;
			}

			$matches = self::matching_records( $records, $item['kind'], $item['slug'], $item['version'] );

			if ( [] === $matches ) {
				$this->findings->delete_by_locator( 'vulnerability', $locator );
				continue;
			}

			$this->findings->upsert( self::build_finding( $locator, $item['version'], $matches ) );
			++$findings;
		}

		$this->findings->delete_vuln_not_in( self::all_locators( $software ) );

		return $this->result( $total, true, $lookups, $findings, null, $skipped_items );
	}

	/**
	 * Every "<kind>:<slug>" locator in the full software list — the
	 * delete_vuln_not_in() keep-set. Deliberately built from the whole
	 * `$software` array rather than only the items visited on this call, so
	 * a resumed run's earlier tick (indices before `$start_index`) and any
	 * item skipped this tick due to a transient provider failure both keep
	 * their existing finding.
	 *
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $software Result of InstalledSoftware::list().
	 * @return string[]
	 */
	private static function all_locators( array $software ): array {
		return array_map(
			static function ( array $item ): string {
				return $item['kind'] . ':' . $item['slug'];
			},
			$software
		);
	}

	/**
	 * @param int         $index         Next index to resume from.
	 * @param bool        $done          Whether the whole list (or an early stop condition) was reached.
	 * @param int         $lookups       Number of provider calls made this call.
	 * @param int         $findings      Number of vulnerability findings upserted this call.
	 * @param string|null $skipped       'endpoint_unavailable', or null.
	 * @param int         $skipped_items Number of items skipped due to a transient provider failure.
	 * @return array{index:int, done:bool, lookups:int, findings:int, skipped:?string, skipped_items:int}
	 */
	private function result( int $index, bool $done, int $lookups, int $findings, ?string $skipped, int $skipped_items ): array {
		return [
			'index'         => $index,
			'done'          => $done,
			'lookups'       => $lookups,
			'findings'      => $findings,
			'skipped'       => $skipped,
			'skipped_items' => $skipped_items,
		];
	}

	/**
	 * Filters a slug's raw records down to the ones that actually apply
	 * (spec §8.3): not informational, with a `software[]` entry matching
	 * `$kind`/`$slug` whose `affected_versions` covers `$version`.
	 *
	 * @param array<int,mixed> $records Raw records from the provider (each expected to be an array).
	 * @param string           $kind    'plugin'|'theme'|'core'.
	 * @param string           $slug    Package slug.
	 * @param string           $version Installed version.
	 * @return array<int,array{id:string,title:string,patched:bool,patched_versions:array<int,string>,raw:array<string,mixed>}>
	 */
	private static function matching_records( array $records, string $kind, string $slug, string $version ): array {
		$matches = [];

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || true === ( $record['informational'] ?? false ) ) {
				continue;
			}

			$entry = self::software_entry( $record, $kind, $slug );

			if ( null === $entry ) {
				continue;
			}

			$affected = is_array( $entry['affected_versions'] ?? null ) ? $entry['affected_versions'] : [];

			if ( ! VersionRange::affects( $version, $affected ) ) {
				continue;
			}

			$patched_versions = is_array( $entry['patched_versions'] ?? null )
				? array_values( array_map( 'strval', $entry['patched_versions'] ) )
				: [];

			$matches[] = [
				'id'               => (string) ( $record['id'] ?? '' ),
				'title'            => (string) ( $record['title'] ?? '' ),
				'patched'          => true === ( $entry['patched'] ?? false ),
				'patched_versions' => $patched_versions,
				'raw'              => $record,
			];
		}

		return $matches;
	}

	/**
	 * The `software[]` entry of a record matching a given kind+slug, if any.
	 *
	 * @param array<string,mixed> $record Raw vulnerability record.
	 * @param string              $kind   'plugin'|'theme'|'core'.
	 * @param string              $slug   Package slug.
	 * @return array<string,mixed>|null
	 */
	private static function software_entry( array $record, string $kind, string $slug ): ?array {
		$list = is_array( $record['software'] ?? null ) ? $record['software'] : [];

		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && ( $entry['type'] ?? '' ) === $kind && ( $entry['slug'] ?? '' ) === $slug ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * @param string                                                                                                           $locator Locator "<kind>:<slug>".
	 * @param string                                                                                                           $version Installed version.
	 * @param array<int,array{id:string,title:string,patched:bool,patched_versions:array<int,string>,raw:array<string,mixed>}> $matches Matching records (spec §8.3).
	 * @return Finding
	 */
	private static function build_finding( string $locator, string $version, array $matches ): Finding {
		$patched_match = null;

		foreach ( $matches as $match ) {
			if ( $match['patched'] && [] !== $match['patched_versions'] ) {
				$patched_match = $match;
				break;
			}
		}

		$tier     = null !== $patched_match ? 'infected' : 'suspicious';
		$severity = null !== $patched_match ? Severity::ALERT : Severity::REVIEW;

		$titles  = array_slice( array_column( $matches, 'title' ), 0, 3 );
		$reason  = implode( '; ', $titles );
		$reason .= null !== $patched_match
			? sprintf( ' — installed %s, patched in %s', $version, $patched_match['patched_versions'][0] )
			: sprintf( ' — installed %s, no fix available', $version );

		$finding                = new Finding( 'vulnerability', $locator, $tier, $severity );
		$finding->signature_ids = array_column( $matches, 'id' );
		$finding->category      = 'vulnerability';
		$finding->reason        = $reason;
		$finding->meta          = [
			'installed_version' => $version,
			'records'           => array_column( $matches, 'raw' ),
		];

		return $finding;
	}
}
