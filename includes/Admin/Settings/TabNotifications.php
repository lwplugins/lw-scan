<?php
/**
 * The Notifications tab.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Admin\Settings;

use LightweightPlugins\Scan\Admin\Post\NotifyTestHandler;
use LightweightPlugins\Scan\Admin\SettingsSanitizer;
use LightweightPlugins\Scan\Notify\Baseline;
use LightweightPlugins\Scan\Notify\Preferences;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about who hears from the scanner, in one place: the switch,
 * the severities, the recipients, how long one e-mail may get, and the
 * admin notice. Before 1.3.0 these were a card on the Settings tab with no
 * way to turn e-mail off at all — which is how a first scan on an existing
 * site mailed the admin its entire backlog in one message.
 *
 * "Send e-mail" is three states over two options (`notify_enabled` and
 * `notify_level`), posted as the single `notify_send` field
 * `Admin\SettingsSanitizer` decomposes. Two options rather than one
 * tri-state so that switching off remembers the level to come back to.
 *
 * The test e-mail is a write of its own, so it posts to `admin-post.php`
 * through the small form `after_form()` renders beside the settings form —
 * the button inside the card reaches it by `form` attribute, since forms
 * cannot nest.
 */
final class TabNotifications implements TabInterface, AfterFormInterface {

	use FieldRendererTrait;

	private const TEST_FORM_ID = 'lw-scan-notify-test';

	public function slug(): string {
		return 'notifications';
	}

	public function label(): string {
		return __( 'Notifications', 'lw-scan' );
	}

	public function icon(): string {
		return 'dashicons-email-alt';
	}

	public function has_save(): bool {
		return true;
	}

	public function render(): void {
		self::notice();
		self::baseline_card();
		$this->email_card();
		$this->notice_card();
	}

	public function after_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="<?php echo esc_attr( self::TEST_FORM_ID ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( NotifyTestHandler::ACTION ); ?>" />
			<?php wp_nonce_field( NotifyTestHandler::ACTION, NotifyTestHandler::NONCE, false ); ?>
		</form>
		<?php
	}

	/**
	 * The switch, the severities, the recipients and the body cap.
	 */
	private function email_card(): void {
		?>
		<div class="lw-scan-card">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'E-mail', 'lw-scan' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Send e-mail', 'lw-scan' ); ?></th>
					<td>
						<?php
						$this->send_control();
						printf(
							'<p class="description">%s</p>',
							esc_html__( 'Off sends no scan e-mail at all — the warning about repeatedly failing scheduled scans included. There are no "all clear" e-mails either way.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="notify_emails"><?php esc_html_e( 'Recipients', 'lw-scan' ); ?></label></th>
					<td>
						<?php
						$this->render_text(
							'notify_emails',
							implode( ', ', ( new Preferences() )->configured() ),
							self::recipients_help()
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="notify_limit"><?php esc_html_e( 'Maximum items per e-mail', 'lw-scan' ); ?></label></th>
					<td>
						<?php
						$this->render_select(
							'notify_limit',
							self::limit_choices(),
							__( 'A scan that finds more than this lists the first few and links to the Findings tab for the rest. The subject line always carries the true total.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test', 'lw-scan' ); ?></th>
					<td><?php self::test_button(); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * The admin-screen notice, which is not e-mail and stays on whatever
	 * the e-mail switch says.
	 */
	private function notice_card(): void {
		?>
		<div class="lw-scan-card">
			<h2 class="lw-scan-section-title"><?php esc_html_e( 'In the admin', 'lw-scan' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin notice', 'lw-scan' ); ?></th>
					<td>
						<?php
						$this->render_toggle(
							'admin_notice',
							__( 'Show a persistent notice while new alerts exist', 'lw-scan' ),
							__( 'Shown to administrators on every admin screen until the findings are acknowledged or ignored. Independent of the e-mail switch above.', 'lw-scan' )
						);
						?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * The three-state send control: a radio group styled as one strip, like
	 * `FieldRendererTrait::render_segment()`, but posting the virtual
	 * `notify_send` field rather than an option of its own.
	 */
	private function send_control(): void {
		$current = self::send_mode();

		echo '<div class="lw-scan-seg">';

		foreach ( self::send_choices() as $value => $label ) {
			printf(
				'<label class="%4$s"><input type="radio" name="%1$s" value="%2$s" %5$s /> %3$s</label>',
				esc_attr( $this->field_name( SettingsSanitizer::SEND_FIELD ) ),
				esc_attr( $value ),
				esc_html( $label ),
				esc_attr( $current === $value ? 'is-on' : '' ),
				checked( $current, $value, false )
			);
		}

		echo '</div>';
	}

	/**
	 * The button that submits the separate test-e-mail form.
	 */
	private static function test_button(): void {
		printf(
			'<button type="submit" form="%1$s" class="button">%2$s</button>',
			esc_attr( self::TEST_FORM_ID ),
			esc_html__( 'Send a test e-mail', 'lw-scan' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Sends a short message to the saved recipients and reports whether WordPress accepted it. Save your changes first if you just edited the list.', 'lw-scan' )
		);
	}

	/**
	 * Says that the first scan's findings are a baseline nobody was mailed
	 * about — shown until a later run has finished.
	 */
	private static function baseline_card(): void {
		if ( ! ( new Baseline() )->pending_review() ) {
			return;
		}

		?>
		<div class="lw-scan-card">
			<h2 class="lw-scan-section-title">
				<?php Icons::render( 'ok' ); ?>
				<?php esc_html_e( 'First scan: a baseline', 'lw-scan' ); ?>
			</h2>
			<p class="lw-scan-muted lw-scan-nomargin">
				<?php esc_html_e( 'The first scan reports everything already on the site, so no e-mail was sent for it. Go through those findings on the Findings tab; from the next scan on, only what is new is mailed.', 'lw-scan' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Which of the three states the stored options add up to.
	 */
	private static function send_mode(): string {
		$prefs = new Preferences();

		return $prefs->enabled() ? $prefs->level() : 'off';
	}

	/**
	 * @return array<string, string>
	 */
	private static function send_choices(): array {
		return [
			'off'                     => __( 'Off', 'lw-scan' ),
			Preferences::LEVEL_ALERT  => __( 'New alerts only', 'lw-scan' ),
			Preferences::LEVEL_REVIEW => __( 'New alerts + review items', 'lw-scan' ),
		];
	}

	/**
	 * @return array<array-key, string>
	 */
	private static function limit_choices(): array {
		$choices = [];

		foreach ( Preferences::LIMIT_CHOICES as $limit ) {
			$choices[ (string) $limit ] = 0 === $limit
				? __( 'All', 'lw-scan' )
				: (string) number_format_i18n( $limit );
		}

		return $choices;
	}

	/**
	 * Names the address mail actually goes to when the field is empty —
	 * the fallback that made an unconfigured site mail its admin 78 alerts.
	 */
	private static function recipients_help(): string {
		$prefs = new Preferences();

		if ( ! $prefs->uses_admin_email() ) {
			return __( 'Comma-separated.', 'lw-scan' );
		}

		$admin = $prefs->admin_email();

		if ( '' === $admin ) {
			return __( 'Comma-separated. Empty means the site\'s admin e-mail address, which this site has not set.', 'lw-scan' );
		}

		return sprintf(
			/* translators: %s: the site's admin e-mail address. */
			__( 'Comma-separated. Empty, as now, means the site\'s admin e-mail address: %s', 'lw-scan' ),
			$admin
		);
	}

	/**
	 * What the last test e-mail did, read from the redirect.
	 */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from NotifyTestHandler's redirect; it picks a message and changes nothing.
		$notice = isset( $_GET[ NotifyTestHandler::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ NotifyTestHandler::NOTICE_ARG ] ) ) : '';

		$messages = [
			'sent'         => [ 'success', __( 'Test e-mail sent. If it does not arrive, the site cannot deliver mail — check the server or an SMTP plugin.', 'lw-scan' ) ],
			'failed'       => [ 'error', __( 'WordPress could not send the test e-mail. The site has no working mail transport.', 'lw-scan' ) ],
			'norecipients' => [ 'error', __( 'There is nobody to send to: the recipients field is empty and this site has no admin e-mail address.', 'lw-scan' ) ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s inline lw-scan-nomargin"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}
}
