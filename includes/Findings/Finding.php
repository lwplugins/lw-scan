<?php
/**
 * Value object for one row of the findings table.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors the `{prefix}lw_scan_findings` columns (spec §4.2). Built by
 * scanners/matchers with a tier and an already-computed severity, then
 * merged into the table by FindingsRepository::upsert().
 */
final class Finding {

	/** @var string Finding type: file|integrity|db|vulnerability. */
	public string $type;

	/** @var string Type-specific locator string. */
	public string $locator;

	/** @var int Row id in the files table, or 0 for non-file findings. */
	public int $file_id = 0;

	/** @var string[] Signature/heuristic ids that matched (e.g. "heur:<analyzer>"). */
	public array $signature_ids = [];

	/** @var string Tier: integrity|infected|suspicious|info. */
	public string $tier;

	/** @var string Free-form classification, e.g. "webshell". */
	public string $category = '';

	/** @var string Severity::ALERT or Severity::REVIEW. */
	public string $severity;

	/** @var string Raw matched excerpt, max 512 bytes, escaped on display. */
	public string $excerpt = '';

	/** @var int 1-based line number of the match, or 0 when not applicable. */
	public int $line = 0;

	/** @var string Human-readable reason (heuristic name, path-signal reasons, …). */
	public string $reason = '';

	/** @var array<string, mixed> Type-specific metadata (spec §4.2). */
	public array $meta = [];

	/** @var string State: new|acknowledged|ignored. */
	public string $state = 'new';

	/**
	 * @param string $type     Finding type (file|integrity|db|vulnerability).
	 * @param string $locator  Type-specific locator string.
	 * @param string $tier     integrity|infected|suspicious|info.
	 * @param string $severity Severity::ALERT or Severity::REVIEW.
	 */
	public function __construct( string $type, string $locator, string $tier, string $severity ) {
		$this->type     = $type;
		$this->locator  = $locator;
		$this->tier     = $tier;
		$this->severity = $severity;
	}

	/**
	 * The row to INSERT for a brand-new finding: JSON-encoded array fields,
	 * a freshly computed `locator_hash`, and `first_seen`/`last_seen` both
	 * pinned to `$now`.
	 *
	 * @param int $now Current unix timestamp.
	 * @return array<string, mixed>
	 */
	public function to_row( int $now ): array {
		return [
			'type'             => $this->type,
			'locator'          => $this->locator,
			'locator_hash'     => Fingerprint::of( $this->type, $this->locator ),
			'file_id'          => $this->file_id,
			'signature_ids'    => wp_json_encode( array_values( $this->signature_ids ) ),
			'tier'             => $this->tier,
			'category'         => $this->category,
			'severity'         => $this->severity,
			'excerpt'          => $this->excerpt,
			'line'             => $this->line,
			'reason'           => $this->reason,
			'meta'             => wp_json_encode( $this->meta ),
			'first_seen'       => $now,
			'last_seen'        => $now,
			'state'            => $this->state,
			'state_changed_at' => 0,
		];
	}

	/**
	 * Rebuilds a Finding from a raw DB row (as returned by `get_row(...,
	 * ARRAY_A)`), decoding its JSON columns.
	 *
	 * @param array<string, mixed> $row Raw findings-table row.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$finding = new self(
			(string) ( $row['type'] ?? '' ),
			(string) ( $row['locator'] ?? '' ),
			(string) ( $row['tier'] ?? '' ),
			(string) ( $row['severity'] ?? '' )
		);

		$finding->file_id       = (int) ( $row['file_id'] ?? 0 );
		$finding->signature_ids = self::decode_list( (string) ( $row['signature_ids'] ?? '[]' ) );
		$finding->category      = (string) ( $row['category'] ?? '' );
		$finding->excerpt       = (string) ( $row['excerpt'] ?? '' );
		$finding->line          = (int) ( $row['line'] ?? 0 );
		$finding->reason        = (string) ( $row['reason'] ?? '' );
		$finding->meta          = self::decode_map( (string) ( $row['meta'] ?? '{}' ) );
		$finding->state         = (string) ( $row['state'] ?? 'new' );

		return $finding;
	}

	/**
	 * Decodes a JSON array column, tolerating malformed/empty storage.
	 *
	 * @param string $json JSON-encoded list.
	 * @return string[]
	 */
	public static function decode_list( string $json ): array {
		$data = json_decode( $json, true );

		return is_array( $data ) ? array_values( $data ) : [];
	}

	/**
	 * Decodes a JSON object column, tolerating malformed/empty storage.
	 *
	 * @param string $json JSON-encoded object.
	 * @return array<string, mixed>
	 */
	public static function decode_map( string $json ): array {
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : [];
	}
}
