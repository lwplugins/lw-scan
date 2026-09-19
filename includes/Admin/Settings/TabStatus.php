<?php
/**
 * The Status tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Admin\Post\StatusEndpointHandler;
use LightweightPlugins\Scan\Status\EndpointSettings;

defined( 'ABSPATH' ) || exit;

/**
 * The read-only status endpoint (`Status\StatusRoute`): its switch, the
 * secret URL to hand to a monitoring service, and how long a computed
 * result is reused.
 *
 * Opening this tab is the admin-side half of key provisioning: a site
 * that was already active before the endpoint existed never ran the
 * activation hook that gives it a key, so the first visit here does. A
 * public request never creates one.
 *
 * Both writes are plain form POSTs to `admin-post.php`
 * (`Admin\Post\StatusEndpointHandler`). "Generate new URL" belongs to its
 * own form outside the main one — forms cannot nest — and reaches it
 * through the button's `form` attribute.
 */
final class TabStatus implements TabInterface {

	private const ROTATE_FORM_ID = 'lw-scan-status-rotate';

	public function slug(): string {
		return 'status';
	}

	public function label(): string {
		return __( 'Status', 'lw-scan' );
	}

	public function icon(): string {
		return 'dashicons-chart-line';
	}

	public function has_save(): bool {
		return false;
	}

	public function render(): void {
		$settings = new EndpointSettings();
		$settings->ensure_key();
		$enabled = $settings->is_enabled();

		echo '<div class="lw-scan-stack" data-lw-scan-tab="status">';
		self::notice();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lw-scan-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( StatusEndpointHandler::SAVE_ACTION ); ?>" />
			<?php
			wp_nonce_field( StatusEndpointHandler::SAVE_ACTION, StatusEndpointHandler::SAVE_NONCE, false );
			self::header_card( $enabled );
			self::access_card( $settings, $enabled );
			?>
			<div class="lw-scan-row lw-scan-save">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'lw-scan' ); ?></button>
			</div>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="<?php echo esc_attr( self::ROTATE_FORM_ID ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( StatusEndpointHandler::ROTATE_ACTION ); ?>" />
			<?php wp_nonce_field( StatusEndpointHandler::ROTATE_ACTION, StatusEndpointHandler::ROTATE_NONCE, false ); ?>
		</form>
		<?php
		echo '</div>';
	}

	/**
	 * @param bool $enabled Whether the endpoint is on.
	 */
	private static function header_card( bool $enabled ): void {
		?>
		<div class="lw-scan-card lw-scan-row lw-scan-row--between lw-scan-status-head">
			<div class="lw-scan-col">
				<h2 class="lw-scan-section-title">
					<?php Icons::render( 'pulse' ); ?>
					<?php esc_html_e( 'Status endpoint', 'lw-scan' ); ?>
				</h2>
				<p id="lw-scan-status-desc" class="lw-scan-muted lw-scan-nomargin">
					<?php esc_html_e( "A read-only report on this site's malware-scan status for an external monitoring service. It only reads; it changes nothing. Enabled by default.", 'lw-scan' ); ?>
				</p>
			</div>
			<label class="lw-scan-toggle">
				<input type="checkbox" class="lw-scan-switch" name="enabled" value="1" aria-describedby="lw-scan-status-desc" <?php checked( $enabled ); ?> />
				<span><?php esc_html_e( 'Enabled', 'lw-scan' ); ?></span>
			</label>
		</div>
		<?php
	}

	/**
	 * @param EndpointSettings $settings Endpoint settings.
	 * @param bool             $enabled  Whether the endpoint is on.
	 */
	private static function access_card( EndpointSettings $settings, bool $enabled ): void {
		?>
		<div class="lw-scan-card<?php echo $enabled ? '' : ' is-off'; ?>">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'Access', 'lw-scan' ); ?></h2>
			<p class="lw-scan-muted lw-scan-nomargin"><?php esc_html_e( 'Give this address to your monitoring service.', 'lw-scan' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="lw-scan-status-url"><?php esc_html_e( 'Status URL', 'lw-scan' ); ?></label>
						<p class="lw-scan-help"><?php esc_html_e( 'The key inside it is the only protection — anyone who knows the address can read the report.', 'lw-scan' ); ?></p>
					</th>
					<td><?php self::url_field( $settings, $enabled ); ?></td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lw-scan-cache-ttl"><?php esc_html_e( 'Reuse the result for', 'lw-scan' ); ?></label>
						<p class="lw-scan-help"><?php esc_html_e( 'A stored result answers requests for this long; after that the next request runs the check again.', 'lw-scan' ); ?></p>
					</th>
					<td><?php self::ttl_select( $settings->cache_ttl() ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * The URL, its copy button, when it was made and the rotate button.
	 * Switched off, the URL stays visible but muted, under a line saying
	 * it answers "not found" until the endpoint is back on.
	 *
	 * @param EndpointSettings $settings Endpoint settings.
	 * @param bool             $enabled  Whether the endpoint is on.
	 */
	private static function url_field( EndpointSettings $settings, bool $enabled ): void {
		$url = $settings->status_url();

		if ( ! $enabled ) {
			printf(
				'<p class="lw-scan-status-off"><span class="lw-scan-pill lw-scan-pill--muted">%1$s</span> %2$s</p>',
				esc_html__( 'Off', 'lw-scan' ),
				esc_html__( 'The endpoint is switched off: this address answers "not found" until you switch it on and save.', 'lw-scan' )
			);
		}
		?>
		<div class="lw-scan-url-field">
			<input type="text" id="lw-scan-status-url" class="lw-scan-mono" value="<?php echo esc_attr( $url ); ?>" readonly="readonly" />
			<button type="button" class="button lw-scan-copy-url" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'lw-scan' ); ?></button>
		</div>
		<p class="lw-scan-url-meta lw-scan-muted lw-scan-small">
			<?php
			printf(
				/* translators: %s: date and time the current URL was created, in the site's timezone. */
				esc_html__( 'Created: %s', 'lw-scan' ),
				esc_html( self::created_at( $settings->key_set_at() ) )
			);
			?>
			<span aria-hidden="true">·</span>
			<button type="submit" form="<?php echo esc_attr( self::ROTATE_FORM_ID ); ?>" class="button-link"><?php esc_html_e( 'Generate new URL', 'lw-scan' ); ?></button>
		</p>
		<?php
	}

	/**
	 * @param int $current Current TTL in seconds.
	 */
	private static function ttl_select( int $current ): void {
		echo '<select id="lw-scan-cache-ttl" name="cache_ttl">';

		foreach ( EndpointSettings::TTL_CHOICES as $seconds ) {
			$minutes = intdiv( $seconds, 60 );

			printf(
				'<option value="%1$d" %3$s>%2$s</option>',
				(int) $seconds,
				/* translators: %d: number of minutes. */
				esc_html( sprintf( _n( '%d minute', '%d minutes', $minutes, 'lw-scan' ), $minutes ) ),
				selected( $current, $seconds, false )
			);
		}

		echo '</select>';
	}

	/**
	 * The creation time in the site's own date and time format.
	 *
	 * @param int $ts Unix timestamp; 0 renders an em dash.
	 */
	private static function created_at( int $ts ): string {
		if ( $ts <= 0 ) {
			return '—';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * What the last save or rotation did, read from the redirect.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from StatusEndpointHandler's redirect; it picks a message and changes nothing.
		$notice = isset( $_GET[ StatusEndpointHandler::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ StatusEndpointHandler::NOTICE_ARG ] ) ) : '';

		$messages = [
			'saved'   => __( 'Status endpoint settings saved.', 'lw-scan' ),
			'rotated' => __( 'New URL generated. The previous URL no longer works.', 'lw-scan' ),
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success inline lw-scan-nomargin"><p>%s</p></div>', esc_html( $messages[ $notice ] ) );
	}
}
