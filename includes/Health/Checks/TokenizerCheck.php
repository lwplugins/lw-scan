<?php
/**
 * Tokenizer extension availability check for the Health report.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Health\Checks;

defined( 'ABSPATH' ) || exit;

/**
 * Spec §12: the heuristic layer (source→sink, entropy, decoder chains…)
 * is built on `token_get_all()`. A handful of minimal PHP builds compile
 * without the tokenizer extension; when that's the case the heuristic
 * layer is disabled rather than fatal-erroring, and this row says so.
 */
final class TokenizerCheck implements CheckInterface {

	public function id(): string {
		return 'tokenizer';
	}

	public function label(): string {
		return __( 'Tokenizer extension', 'lw-scan' );
	}

	public function run(): array {
		if ( ! function_exists( 'token_get_all' ) ) {
			return [
				'status'   => 'warning',
				'message'  => __( 'The tokenizer extension is not available — the heuristic layer is disabled.', 'lw-scan' ),
				'blocking' => false,
			];
		}

		return [
			'status'   => 'ok',
			'message'  => __( 'Tokenizer extension available.', 'lw-scan' ),
			'blocking' => false,
		];
	}
}
