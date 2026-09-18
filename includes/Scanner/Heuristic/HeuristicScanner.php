<?php
/**
 * Runs the heuristic analyzers over one file and normalises their findings.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Scanner\Heuristic;

defined( 'ABSPATH' ) || exit;

/**
 * The entry point FileScanner calls once Gate has allowed a file (§6.6 step
 * 8). It tokenises the file once, hands the same token view to every
 * analyzer, and gives each finding the shape the findings repository
 * stores: an analyzer name, a category, a human-readable reason, a line,
 * the source line it happened on, and the signature ids to attribute it to.
 * Nothing here ever reports `infected` — that verdict belongs to
 * signatures, not to guesses.
 */
final class HeuristicScanner {

	/**
	 * Finding category per analyzer, unless the finding names its own.
	 *
	 * @var array<string,string>
	 */
	private const CATEGORIES = [
		'source_sink' => 'backdoor',
		'entropy'     => 'obfuscation',
		'decoder'     => 'obfuscation',
		'disguise'    => 'dropper',
	];

	/**
	 * Characters of the source line kept as the excerpt.
	 */
	private const EXCERPT_LENGTH = 160;

	/**
	 * Rescans decoded text with the signature layers.
	 *
	 * @var callable(string):array<int,\LightweightPlugins\Scan\Scanner\MatchResult>
	 */
	private $rescan;

	/**
	 * Source lines of the file under analysis.
	 *
	 * @var array<int,string>
	 */
	private array $lines = [];

	/**
	 * @param callable $rescan Runs the signature layers over decoded text.
	 * @phpstan-param callable(string):array<int,\LightweightPlugins\Scan\Scanner\MatchResult> $rescan
	 */
	public function __construct( callable $rescan ) {
		$this->rescan = $rescan;
	}

	/**
	 * @param string              $code     Raw file contents.
	 * @param array<string,mixed> $file_row Row from the files table (`origin`, `known_good`).
	 * @return array<int,array{analyzer:string,category:string,reason:string,line:int,excerpt:string,sig_ids:array<int,string>}>
	 */
	public function analyze( string $code, array $file_row ): array {
		$stream      = new TokenStream( $code );
		$this->lines = explode( "\n", $code );
		$origin      = isset( $file_row['origin'] ) ? (string) $file_row['origin'] : '';
		$known_good  = ! empty( $file_row['known_good'] );
		$decoder     = new StaticDecoder( $this->rescan );

		$findings = array_merge(
			$this->shape( 'source_sink', SourceSinkAnalyzer::analyze( $stream ) ),
			$this->shape( 'entropy', IdentifierEntropy::analyze( $stream ) ),
			$this->shape( 'decoder', $decoder->analyze( $stream, $code ) ),
			$this->shape( 'disguise', DisguiseSignals::analyze( $code, $origin, $known_good ) )
		);

		$this->lines = [];

		return $findings;
	}

	/**
	 * @param string                         $analyzer Analyzer name.
	 * @param array<int,array<string,mixed>> $raw      Findings as the analyzer reported them.
	 * @return array<int,array{analyzer:string,category:string,reason:string,line:int,excerpt:string,sig_ids:array<int,string>}>
	 */
	private function shape( string $analyzer, array $raw ): array {
		$findings = [];

		foreach ( $raw as $finding ) {
			$line = isset( $finding['line'] ) ? (int) $finding['line'] : 0;

			$findings[] = [
				'analyzer' => $analyzer,
				'category' => isset( $finding['category'] ) ? (string) $finding['category'] : self::CATEGORIES[ $analyzer ],
				'reason'   => isset( $finding['reason'] ) ? (string) $finding['reason'] : '',
				'line'     => $line,
				'excerpt'  => $this->excerpt( $line ),
				'sig_ids'  => isset( $finding['sig_ids'] ) ? (array) $finding['sig_ids'] : [ 'heur:' . $analyzer ],
			];
		}

		return $findings;
	}

	/**
	 * The source line a finding points at, trimmed — it is shown in the
	 * admin list, so it must stay one short line.
	 *
	 * @param int $line 1-based line number.
	 */
	private function excerpt( int $line ): string {
		if ( $line < 1 || ! isset( $this->lines[ $line - 1 ] ) ) {
			return '';
		}

		return substr( trim( $this->lines[ $line - 1 ] ), 0, self::EXCERPT_LENGTH );
	}
}
