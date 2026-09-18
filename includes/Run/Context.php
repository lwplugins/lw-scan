<?php
/**
 * Run-scoped services and state shared by every phase of one tick.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Run;

use LightweightPlugins\Scan\Bundle\PackLoader;
use LightweightPlugins\Scan\Bundle\Signatures;
use LightweightPlugins\Scan\Bundle\Store;
use LightweightPlugins\Scan\Db\FilesRepositoryInterface;
use LightweightPlugins\Scan\Db\FindingsRepositoryInterface;
use LightweightPlugins\Scan\Db\RunsRepositoryInterface;
use LightweightPlugins\Scan\Index\KnownGood;
use LightweightPlugins\Scan\Remote\ChecksumProvider;
use LightweightPlugins\Scan\Remote\Client;
use LightweightPlugins\Scan\Remote\FileCache;
use LightweightPlugins\Scan\Remote\VulnerabilityProvider;
use LightweightPlugins\Scan\Scanner\FileScanner;
use LightweightPlugins\Scan\Scanner\Heuristic\Gate;
use LightweightPlugins\Scan\Vuln\InstalledSoftware;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Plain holder the Runner builds once per tick and hands to each phase. The
 * cursor, stats, options and repositories are injected; everything else
 * (HTTP client, bundle store, checksum/vulnerability providers, the
 * known-good verifier, the file scanner and the installed-software list) is
 * built on first use and memoized for the rest of the tick, so a phase that
 * never touches the network never constructs a client. No logic beyond that
 * wiring lives here.
 *
 * The signature set is part of that wiring: `BundlePhase` hands it over on
 * the tick that runs it (`use_signatures()`), and `signatures()` loads the
 * stored pack on every later tick — each of which is its own PHP process
 * with its own, empty Context. Every phase, including the database phase,
 * scans against that same signature set.
 */
final class Context {

	/** @var Cursor Persisted position of this run. */
	public Cursor $cursor;

	/** @var RunStats Counters and phase timings accumulated across the run. */
	public RunStats $stats;

	/** @var array<string, mixed> Options::all() as of this tick. */
	public array $options;

	/** @var FilesRepositoryInterface File-index repository. */
	public FilesRepositoryInterface $files;

	/** @var FindingsRepositoryInterface Findings repository. */
	public FindingsRepositoryInterface $findings;

	/** @var RunsRepositoryInterface Run-history repository. */
	public RunsRepositoryInterface $runs;

	/** @var int The run this tick belongs to. */
	public int $run_id;

	/**
	 * Items the current phase processed this tick. The Runner zeroes it
	 * before each phase step and feeds it to `RunStats::phase_end()`, so a
	 * phase reports its throughput without owning the stats bookkeeping.
	 *
	 * @var int
	 */
	public int $items = 0;

	/** @var array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>|null */
	private ?array $software;

	/** @var Store|null Bundle storage directory accessor. */
	private ?Store $store = null;

	/** @var Client|null Backend HTTP client. */
	private ?Client $client = null;

	/** @var FileCache|null On-disk cache for backend responses. */
	private ?FileCache $cache = null;

	/** @var ChecksumProvider|null wp.org checksum lists. */
	private ?ChecksumProvider $checksums = null;

	/** @var VulnerabilityProvider|null Vulnerability feed client. */
	private ?VulnerabilityProvider $vulns = null;

	/** @var KnownGood|null Checksum-backed known-good verifier. */
	private ?KnownGood $known_good = null;

	/** @var FileScanner|null Per-file signature/heuristic scanner. */
	private ?FileScanner $file_scanner = null;

	/** @var Signatures|null Signature set the file scan runs against. */
	private ?Signatures $signatures = null;

	/** @var bool Whether `signatures()` already went to Bundle\PackLoader — a null result is memoized too. */
	private bool $signatures_loaded = false;

	/**
	 * @param Cursor                                                                                     $cursor   Persisted run cursor.
	 * @param RunStats                                                                                   $stats    Accumulated run stats.
	 * @param array<string, mixed>                                                                       $options  Options::all() as of this tick.
	 * @param FilesRepositoryInterface                                                                   $files    File-index repository.
	 * @param FindingsRepositoryInterface                                                                $findings Findings repository.
	 * @param RunsRepositoryInterface                                                                    $runs     Run-history repository.
	 * @param array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>|null $software Installed software; built from InstalledSoftware::list() on first use when null.
	 */
	public function __construct(
		Cursor $cursor,
		RunStats $stats,
		array $options,
		FilesRepositoryInterface $files,
		FindingsRepositoryInterface $findings,
		RunsRepositoryInterface $runs,
		?array $software = null
	) {
		$this->cursor   = $cursor;
		$this->stats    = $stats;
		$this->options  = $options;
		$this->files    = $files;
		$this->findings = $findings;
		$this->runs     = $runs;
		$this->software = $software;
		$this->run_id   = $cursor->run_id();
	}

	/**
	 * The installed core/plugin/theme list, built once per tick — Vuln\Matcher
	 * must see the same complete list on every tick of a run.
	 *
	 * @return array<int, array{kind:string, slug:string, version:string, name:string, active:bool}>
	 */
	public function software(): array {
		if ( null === $this->software ) {
			$this->software = InstalledSoftware::list();
		}

		return $this->software;
	}

	public function store(): Store {
		if ( null === $this->store ) {
			$this->store = new Store();
		}

		return $this->store;
	}

	public function client(): Client {
		if ( null === $this->client ) {
			$this->client = new Client();
		}

		return $this->client;
	}

	public function cache(): FileCache {
		if ( null === $this->cache ) {
			$this->cache = new FileCache( $this->store()->cache_dir() );
		}

		return $this->cache;
	}

	public function checksums(): ChecksumProvider {
		if ( null === $this->checksums ) {
			$this->checksums = new ChecksumProvider( $this->client(), $this->cache() );
		}

		return $this->checksums;
	}

	public function vulns(): VulnerabilityProvider {
		if ( null === $this->vulns ) {
			$this->vulns = new VulnerabilityProvider( $this->client(), $this->cache() );
		}

		return $this->vulns;
	}

	public function known_good(): KnownGood {
		if ( null === $this->known_good ) {
			$this->known_good = new KnownGood( $this->checksums(), $this->software() );
		}

		return $this->known_good;
	}

	/**
	 * @throws RuntimeException `bundle_missing` when there is no signature set to scan with.
	 */
	public function file_scanner(): FileScanner {
		if ( null === $this->file_scanner ) {
			$signatures = $this->signatures();

			if ( null === $signatures ) {
				throw new RuntimeException( 'bundle_missing' );
			}

			$this->file_scanner = new FileScanner(
				$signatures,
				[
					'max_file_size' => (int) ( $this->options['max_file_size'] ?? 2097152 ),
					'heuristics'    => ! empty( $this->options['heuristics'] ),
					'memory_limit'  => Gate::memory_limit_bytes(),
				]
			);
		}

		return $this->file_scanner;
	}

	/**
	 * The signature set for this run. Only the tick that runs `BundlePhase`
	 * is handed one; every later tick is a fresh process, so it loads the
	 * stored pack itself rather than scanning against nothing (which reads
	 * as "version 0" — a queue that matches no row and a rule set with no
	 * rules, i.e. a run that silently finishes early).
	 *
	 * @return Signatures|null Null when no pack is available at all.
	 */
	public function signatures(): ?Signatures {
		if ( ! $this->signatures_loaded ) {
			$this->signatures_loaded = true;
			$this->signatures        = PackLoader::signatures();
		}

		return $this->signatures;
	}

	/**
	 * Hands over the signature set `BundlePhase` settled on, and drops a file
	 * scanner built against a previous one.
	 *
	 * @param Signatures|null $signatures Signature set to scan with.
	 */
	public function use_signatures( ?Signatures $signatures ): void {
		$this->signatures        = $signatures;
		$this->signatures_loaded = true;
		$this->file_scanner      = null;
	}

	public function bundle_version(): int {
		$signatures = $this->signatures();

		return null === $signatures ? 0 : $signatures->version();
	}

	public function wpdb(): \wpdb {
		global $wpdb;

		return $wpdb;
	}
}
