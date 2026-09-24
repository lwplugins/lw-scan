<?php
/**
 * `/settings` and `/notify/test` — the Settings and Notifications tabs.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Rest;

use LightweightPlugins\Scan\Notify\Mailer;
use LightweightPlugins\Scan\Notify\Preferences;
use LightweightPlugins\Scan\Options;
use LightweightPlugins\Scan\Rest\Presenter\SettingsView;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Both tabs write the same `lw_scan_options` row; `SettingsInput` makes a
 * save partial, so each tab sends only its own keys. The save goes through
 * `update_option()`, whose `update_option_lw_scan_options` hook is what
 * `Run\Scheduler::reschedule()` listens on — which is why the response is
 * read back afterwards: `next_due` may just have moved.
 *
 * The test e-mail goes to the *stored* recipients: unsaved edits in the
 * form are not part of the question it answers.
 */
final class SettingsController implements ControllerInterface {

	public function routes(): array {
		return [
			'/settings'    => [
				[
					'methods'  => 'GET',
					'callback' => [ $this, 'show' ],
				],
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'save' ],
				],
			],
			'/notify/test' => [
				[
					'methods'  => 'POST',
					'callback' => [ $this, 'notify_test' ],
				],
			],
		];
	}

	/**
	 * GET /settings.
	 *
	 * @return array<string, mixed>
	 */
	public function show(): array {
		return SettingsView::current();
	}

	/**
	 * POST /settings — any subset of the editable keys.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public function save( WP_REST_Request $request ): array {
		update_option( Options::OPTION_NAME, SettingsInput::sanitize( $request->get_params() ), true );

		return SettingsView::current();
	}

	/**
	 * POST /notify/test.
	 *
	 * @return array{result:string}
	 */
	public function notify_test(): array {
		$options = Options::all();

		if ( [] === ( new Preferences( $options ) )->recipients() ) {
			return [ 'result' => 'norecipients' ];
		}

		return [ 'result' => Mailer::send_test( $options ) ? 'sent' : 'failed' ];
	}
}
