<?php
/**
 * Walks the filesystem into the file-index table, then hashes queued rows.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Index;

use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Findings\Finding;
use LightweightPlugins\Scan\Findings\Severity;
use LightweightPlugins\Scan\Vuln\InstalledSoftware;

defined( 'ABSPATH' ) || exit;

/**
 * Two passes (spec §6.5). `stat_pass()` drains a Walker generator into
 * batched upserts with a preliminary path signal; it cannot resume mid-walk
 * (the walker has no savable cursor) so it always runs to completion.
 * `hash_pass()` is itself the resumable half: it pulls a bounded page of
 * `md5 = ''` rows, hashes each one, and finalizes its origin/known-good/
 * signal — checking a time budget every 20 files so a caller (Run\Runner)
 * can stop it mid-page.
 */
final class FileIndexer {

	private const STAT_BATCH_SIZE = 200;

	private const DEADLINE_CHECK_INTERVAL = 20;

	private const HASH_CHUNK_SIZE = 1048576; // 1 MB reads keep memory flat on multi-GB files.

	/** @var FilesRepositoryInterface */
	private FilesRepositoryInterface $files;

	/** @var KnownGoodInterface */
	private KnownGoodInterface $known_good;

	/** @var FindingsRepositoryInterface */
	private FindingsRepositoryInterface $findings;

	/** @var array<int,string> */
	private array $plugin_slugs;

	/** @var array<int,string> */
	private array $theme_slugs;

	/**
	 * @param FilesRepositoryInterface                                                              $files      File-index repository.
	 * @param KnownGoodInterface                                                                    $known_good Checksum verifier.
	 * @param FindingsRepositoryInterface                                                           $findings   Findings repository.
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}> $software   Result of InstalledSoftware::list().
	 */
	public function __construct( FilesRepositoryInterface $files, KnownGoodInterface $known_good, FindingsRepositoryInterface $findings, array $software ) {
		$this->files        = $files;
		$this->known_good   = $known_good;
		$this->findings     = $findings;
		$this->plugin_slugs = InstalledSoftware::plugin_slugs( $software );
		$this->theme_slugs  = InstalledSoftware::theme_slugs( $software );
	}

	/**
	 * Drains the walker into the file-index table, 200 rows/upsert.
	 *
	 * @param Walker $walker Filesystem walker.
	 * @param int    $run_id Current run id, stored as `seen_run`.
	 * @return array{indexed:int, unreadable:int}
	 */
	public function stat_pass( Walker $walker, int $run_id ): array {
		$indexed    = 0;
		$unreadable = 0;
		$batch      = [];

		foreach ( $walker->walk() as $entry ) {
			$batch[] = $this->stat_row( $entry );

			++$indexed;
			if ( $entry['unreadable'] ) {
				++$unreadable;
			}

			if ( count( $batch ) >= self::STAT_BATCH_SIZE ) {
				$this->files->upsert_stat_batch( $batch, $run_id );
				$batch = [];
			}
		}

		if ( [] !== $batch ) {
			$this->files->upsert_stat_batch( $batch, $run_id );
		}

		return [
			'indexed'    => $indexed,
			'unreadable' => $unreadable,
		];
	}

	/**
	 * @param array{path:string, size:int, mtime:int, unreadable:bool} $entry One Walker::walk() entry.
	 * @return array{path:string, size:int, mtime:int, path_signal:int, origin:string}
	 */
	private function stat_row( array $entry ): array {
		$origin = Origin::of( $entry['path'], $this->plugin_slugs, $this->theme_slugs );
		$signal = PathSignals::score( $entry['path'], $origin, '', null )['score'];

		return [
			'path'        => $entry['path'],
			'size'        => $entry['size'],
			'mtime'       => $entry['mtime'],
			'path_signal' => $signal,
			'origin'      => $origin,
		];
	}

	/**
	 * Hashes up to `$limit` queued rows, checking `$deadline()` every 20 files.
	 *
	 * @param int      $after_id Cursor: only rows with a greater id.
	 * @param int      $limit    Max rows to pull from the queue this call.
	 * @param callable $deadline Returns true once the time budget is spent.
	 * @return array{last_id:int, hashed:int, known_good:int, integrity:int, unreadable:int, done:bool}
	 */
	public function hash_pass( int $after_id, int $limit, callable $deadline ): array {
		$rows = $this->files->next_unhashed( $after_id, $limit );

		$last_id       = $after_id;
		$known_good    = 0;
		$integrity     = 0;
		$unreadable    = 0;
		$processed     = 0;
		$stopped_early = false;

		foreach ( $rows as $row ) {
			$last_id = (int) $row['id'];
			$outcome = $this->hash_row( $row );

			$unreadable += $outcome['unreadable'] ? 1 : 0;
			$known_good += $outcome['known_good'] ? 1 : 0;
			$integrity  += $outcome['integrity'] ? 1 : 0;

			++$processed;

			if ( 0 === $processed % self::DEADLINE_CHECK_INTERVAL && $deadline() ) {
				$stopped_early = true;
				break;
			}
		}

		return [
			'last_id'    => $last_id,
			'hashed'     => $processed,
			'known_good' => $known_good,
			'integrity'  => $integrity,
			'unreadable' => $unreadable,
			'done'       => ! $stopped_early && count( $rows ) < $limit,
		];
	}

	/**
	 * @param array<string, mixed> $row One next_unhashed() row.
	 * @return array{unreadable:bool, known_good:bool, integrity:bool}
	 */
	private function hash_row( array $row ): array {
		$id  = (int) $row['id'];
		$rel = (string) $row['path'];

		// No short-circuit for a row the stat pass could not size: the index
		// stores size unsigned, so there is no sentinel to read back, and the
		// `fopen()` below fails for exactly the same files one syscall later.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- WP_Filesystem is unavailable here (WP-Cron/CLI, and unit tests without WordPress loaded); a vanished/unreadable file is an expected outcome recorded as kind=unreadable, not a PHP error to surface.
		$handle = @fopen( self::absolute_path( $rel ), 'rb' );

		if ( false === $handle ) {
			$this->mark_unreadable( $id );

			return [
				'unreadable' => true,
				'known_good' => false,
				'integrity'  => false,
			];
		}

		[ $kind, $md5, $sha256 ] = self::read_and_hash( $handle, ContentType::ext( $rel ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the handle opened with fopen() above.
		fclose( $handle );

		return $this->finalize_hashed_row( $id, $rel, (string) $row['origin'], $kind, $md5, $sha256 );
	}

	/**
	 * Reads the 4 KB head for content-type sniffing, then streams the whole
	 * file once, updating both hash contexts per chunk so md5 and sha256
	 * are computed from a single read pass even on multi-GB files.
	 *
	 * @param resource $handle Open file handle, positioned at 0.
	 * @param string   $ext    Lowercased extension without the dot (ContentType::ext()).
	 * @return array{0:string, 1:string, 2:string} kind, md5, sha256.
	 */
	private static function read_and_hash( $handle, string $ext ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no streaming/chunked read API; this file may be up to 2 GB, so it must never be loaded whole.
		$head = (string) fread( $handle, 4096 );

		rewind( $handle );

		$md5_ctx    = hash_init( 'md5' );
		$sha256_ctx = hash_init( 'sha256' );

		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no streaming/chunked read API; this file may be up to 2 GB, so it must never be loaded whole.
			$chunk = fread( $handle, self::HASH_CHUNK_SIZE );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			hash_update( $md5_ctx, $chunk );
			hash_update( $sha256_ctx, $chunk );
		}

		return [
			ContentType::detect( $head, $ext ),
			hash_final( $md5_ctx ),
			hash_final( $sha256_ctx ),
		];
	}

	/**
	 * Finalizes origin/known-good/signal for a successfully hashed row and
	 * reconciles its integrity finding.
	 *
	 * @param int    $id     Row id.
	 * @param string $rel    ABSPATH-relative path.
	 * @param string $origin Origin recorded at stat time.
	 * @param string $kind   Result of ContentType::detect().
	 * @param string $md5    Computed md5.
	 * @param string $sha256 Computed sha256.
	 * @return array{unreadable:bool, known_good:bool, integrity:bool}
	 */
	private function finalize_hashed_row( int $id, string $rel, string $origin, string $kind, string $md5, string $sha256 ): array {
		$check         = $this->known_good->check( $rel, $origin, $md5 );
		$is_known_good = 'match' === $check['status'];
		$signal        = PathSignals::score( $rel, $origin, $kind, $this->known_good->core_paths(), $is_known_good )['score'];

		$this->files->update_hashed(
			$id,
			[
				'md5'         => $md5,
				'sha256'      => $sha256,
				'kind'        => $kind,
				'origin'      => $origin,
				'known_good'  => $is_known_good ? 1 : 0,
				'path_signal' => $signal,
				'indexed_at'  => time(),
			]
		);

		$is_mismatch = 'mismatch' === $check['status'];

		if ( $is_mismatch ) {
			$this->upsert_integrity_finding( $id, $rel, $md5, $check );
		} elseif ( $is_known_good ) {
			$this->findings->delete_by_locator( 'integrity', $rel );
			$this->findings->delete_by_locator( 'file', $rel );
		}

		return [
			'unreadable' => false,
			'known_good' => $is_known_good,
			'integrity'  => $is_mismatch,
		];
	}

	/**
	 * @param int                                                                   $id    files.id of the mismatched row.
	 * @param string                                                                $rel   ABSPATH-relative path.
	 * @param string                                                                $md5   Actual md5.
	 * @param array{status:string, expected:string, package:string, version:string} $check KnownGood::check() result.
	 * @return void
	 */
	private function upsert_integrity_finding( int $id, string $rel, string $md5, array $check ): void {
		$finding = new Finding( 'integrity', $rel, 'integrity', Severity::ALERT );

		$finding->file_id       = $id;
		$finding->signature_ids = [ 'integrity:checksum' ];
		$finding->category      = 'integrity';
		$finding->reason        = sprintf( 'Modified %s %s file (checksum mismatch)', self::package_label( $check['package'] ), $check['version'] );
		$finding->meta          = [
			'expected_md5' => $check['expected'],
			'actual_md5'   => $md5,
			'package'      => $check['package'],
			'version'      => $check['version'],
		];

		$this->findings->upsert( $finding );
	}

	/**
	 * @param string $package `core`, `plugin:<slug>` or `theme:<slug>`.
	 * @return string `WordPress` for core, the bare slug otherwise.
	 */
	private static function package_label( string $package ): string {
		if ( 'core' === $package ) {
			return 'WordPress';
		}

		$colon = strpos( $package, ':' );

		return false === $colon ? $package : substr( $package, $colon + 1 );
	}

	/**
	 * @param int $id Row id.
	 * @return void
	 */
	private function mark_unreadable( int $id ): void {
		$this->files->update_hashed(
			$id,
			[
				'kind'   => 'unreadable',
				'md5'    => '-',
				'sha256' => '-',
			]
		);
	}

	/**
	 * @param string $rel ABSPATH-relative path.
	 * @return string
	 */
	private static function absolute_path( string $rel ): string {
		return rtrim( ABSPATH, '/' ) . '/' . ltrim( $rel, '/' );
	}
}
