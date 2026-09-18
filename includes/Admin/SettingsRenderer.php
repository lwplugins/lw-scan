<?php
/**
 * Page chrome around the admin tabs.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin;

use LightweightPlugins\Scan\Admin\Settings\TabInterface;
use LightweightPlugins\Scan\Db\FindingsRepository;
use LightweightPlugins\Scan\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the title row and the vertical tab nav shared by every LW plugin,
 * then hands over to the active tab. Tabs are real `?tab=` links rather
 * than client-side panels, so each one loads only its own data. Only a tab that declares `has_save()` is wrapped in the
 * `options.php` settings form, so the read-only tabs never post the
 * options array by accident.
 */
final class SettingsRenderer {

	/**
	 * @var array<int, TabInterface>
	 */
	private array $tabs;

	/**
	 * @param array<int, TabInterface> $tabs Tabs in nav order.
	 */
	public function __construct( array $tabs ) {
		$this->tabs = $tabs;
	}

	/**
	 * @param string $settings_group Registered settings group.
	 * @param string $current        Active tab slug.
	 */
	public function render_page( string $settings_group, string $current ): void {
		$tab = $this->tab( $current );
		?>
		<div class="wrap lw-scan-wrap">
			<h1>
				<img src="<?php echo esc_url( LW_SCAN_URL . 'assets/img/title-icon.svg' ); ?>" alt="" class="lw-title-icon" />
				<?php esc_html_e( 'LW Scan', 'lw-scan' ); ?>
				<span class="lw-scan-version">(<?php echo esc_html( LW_SCAN_VERSION ); ?>)</span>
			</h1>

			<?php settings_errors( $settings_group ); ?>

			<div class="lw-scan-settings">
				<?php $this->render_nav( $current ); ?>

				<div class="lw-scan-tab-content">
					<?php if ( $tab->has_save() ) : ?>
						<form method="post" action="options.php" class="lw-scan-form">
							<?php
							settings_fields( $settings_group );
							$tab->render();
							?>
							<div class="lw-scan-row lw-scan-save">
								<?php submit_button( __( 'Save changes', 'lw-scan' ), 'primary', 'submit', false ); ?>
								<span class="lw-scan-muted lw-scan-small"><?php esc_html_e( 'Settings affect scanning and notifications only. Nothing here modifies files or database rows.', 'lw-scan' ); ?></span>
							</div>
						</form>
					<?php else : ?>
						<?php $tab->render(); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string $current Active tab slug.
	 */
	private function render_nav( string $current ): void {
		$alerts = $this->new_alerts();
		?>
		<ul class="lw-scan-tabs">
			<?php foreach ( $this->tabs as $tab ) : ?>
				<?php $active = $tab->slug() === $current; ?>
				<li>
					<a href="<?php echo esc_url( SettingsPage::tab_url( $tab->slug() ) ); ?>"<?php echo $active ? ' class="active" aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $tab->icon() ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $tab->label() ); ?>
						<?php if ( 'findings' === $tab->slug() && $alerts > 0 ) : ?>
							<span class="lw-scan-count"><?php echo esc_html( (string) $alerts ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The nav badge: findings still in the `new` state at alert severity.
	 */
	private function new_alerts(): int {
		if ( ! Schema::exists() ) {
			return 0;
		}

		$counts = FindingsRepository::counts();

		return (int) ( $counts['state']['new']['alert'] ?? 0 );
	}

	/**
	 * @param string $slug Active tab slug.
	 */
	private function tab( string $slug ): TabInterface {
		foreach ( $this->tabs as $tab ) {
			if ( $tab->slug() === $slug ) {
				return $tab;
			}
		}

		return $this->tabs[0];
	}
}
