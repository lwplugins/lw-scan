<?php
/**
 * The loaded signature set a scan works with: pack, lazy meta, new rules.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Bundle;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Bundles what a scan tick needs from storage. The pack is always loaded;
 * the meta (ids, names, categories — about half the download) is only read
 * when a finding is recorded, through the loader callable, at most once per
 * instance. The pack and its meta are written together, so a meta that
 * cannot be loaded, or that describes another pack (version or rule count
 * differs), means a damaged store: `meta()` throws `bundle_missing`, the
 * same failure a missing pack gives, and the next tick re-downloads.
 */
final class Signatures {

	/**
	 * @var Pack
	 */
	private Pack $pack;

	/**
	 * @var callable(): (PackMeta|null)
	 */
	private $meta_loader;

	/**
	 * @var NewSignatures
	 */
	private NewSignatures $new;

	/**
	 * @var PackMeta|null
	 */
	private ?PackMeta $meta = null;

	/**
	 * @var bool
	 */
	private bool $meta_loaded = false;

	/**
	 * @param Pack          $pack        The loaded pack.
	 * @param callable      $meta_loader Returns the pack's PackMeta, or null when it cannot be read.
	 * @param NewSignatures $new         Rules added since the previous pack.
	 */
	public function __construct( Pack $pack, callable $meta_loader, NewSignatures $new ) {
		$this->pack        = $pack;
		$this->meta_loader = $meta_loader;
		$this->new         = $new;
	}

	public function pack(): Pack {
		return $this->pack;
	}

	public function new_signatures(): NewSignatures {
		return $this->new;
	}

	public function version(): int {
		return $this->pack->version();
	}

	/**
	 * @throws RuntimeException `bundle_missing` when the meta cannot be loaded or does not match the pack.
	 */
	public function meta(): PackMeta {
		if ( ! $this->meta_loaded ) {
			$this->meta_loaded = true;

			$meta = ( $this->meta_loader )();

			if ( $meta instanceof PackMeta && $meta->version() === $this->pack->version() && $meta->count() === $this->pack->count() ) {
				$this->meta = $meta;
			}
		}

		if ( null === $this->meta ) {
			throw new RuntimeException( 'bundle_missing' );
		}

		return $this->meta;
	}
}
