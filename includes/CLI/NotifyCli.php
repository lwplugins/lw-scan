<?php
/**
 * Deciding logic behind `wp lw-scan notify …`.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\CLI;

use LightweightPlugins\Scan\Notify\Baseline;
use LightweightPlugins\Scan\Notify\Mailer;
use LightweightPlugins\Scan\Notify\Preferences;
use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * What each `notify` sub-command does to the notification options and what
 * it should report — with no `WP_CLI` in it, the same way `EndpointCli` is
 * the deciding half of `wp lw-scan endpoint`. `NotifyCommand` is the only
 * caller; it turns these plain result arrays into `WP_CLI::success()`,
 * `::error()` and `::line()` calls.
 *
 * Every write goes through `Options::update()`, the same path the
 * Notifications tab's save takes, so a CLI call and an admin-screen click
 * leave the site in identical state. Reads write nothing at all: `level`,
 * `recipients` and `limit` without an argument only report.
 *
 * The command's vocabulary is the tab's, not the option row's: `alerts` and
 * `review` for the two levels (the option stores the singular `alert`), and
 * `all` for "no cap" (the option stores 0).
 */
final class NotifyCli {

	/** What `level` accepts, including the option's own singular spelling. */
	private const LEVEL_WORDS = [
		'alerts' => Preferences::LEVEL_ALERT,
		'alert'  => Preferences::LEVEL_ALERT,
		'review' => Preferences::LEVEL_REVIEW,
	];

	/** What `limit` accepts, in the order the tab offers them. */
	private const LIMIT_WORDS = [
		'10'  => 10,
		'20'  => 20,
		'50'  => 50,
		'all' => 0,
	];

	/** @var Baseline First-scan baseline state. */
	private Baseline $baseline;

	/**
	 * @param Baseline|null $baseline First-scan baseline state; the State/runs-table-backed one when null.
	 */
	public function __construct( ?Baseline $baseline = null ) {
		$this->baseline = $baseline ?? new Baseline();
	}

	/**
	 * The switch, the level, who receives mail, the cap and whether the
	 * baseline scan is behind this site.
	 *
	 * @return array{enabled:bool, level:string, recipients:string[], uses_admin_email:bool, limit:int, baseline:bool}
	 */
	public function status(): array {
		$prefs = new Preferences();

		return [
			'enabled'          => $prefs->enabled(),
			'level'            => self::level_word( $prefs->level() ),
			'recipients'       => $prefs->recipients(),
			'uses_admin_email' => $prefs->uses_admin_email(),
			'limit'            => $prefs->limit(),
			'baseline'         => $this->baseline->taken(),
		];
	}

	/**
	 * Switches scan e-mail on, whether or not it already was.
	 *
	 * @return array{changed:bool, message:string}
	 */
	public function enable(): array {
		$prefs   = new Preferences();
		$already = $prefs->enabled();

		Options::update( [ 'notify_enabled' => true ] );

		return [
			'changed' => ! $already,
			'message' => sprintf(
				'%s Sending: %s.',
				$already ? 'Already enabled.' : 'Enabled.',
				self::level_label( $prefs->level() )
			),
		];
	}

	/**
	 * Switches scan e-mail off — new findings and the failure-streak
	 * warning alike. The level is left stored, so `enable` comes back to it.
	 *
	 * @return array{changed:bool, message:string}
	 */
	public function disable(): array {
		$already_off = ! ( new Preferences() )->enabled();

		Options::update( [ 'notify_enabled' => false ] );

		return [
			'changed' => ! $already_off,
			'message' => $already_off
				? 'Already disabled.'
				: 'Disabled. No scan e-mail is sent, the failure-streak warning included.',
		];
	}

	/**
	 * Reads or sets which severities are mailed.
	 *
	 * @param string|null $level `alerts`|`review`, or null to only report the current one.
	 * @return array{ok:bool, level:string, message:string}
	 */
	public function level( ?string $level ): array {
		$prefs = new Preferences();

		if ( null === $level ) {
			return self::level_result( $prefs->level(), $prefs->enabled() );
		}

		$word = strtolower( trim( $level ) );

		if ( ! isset( self::LEVEL_WORDS[ $word ] ) ) {
			return [
				'ok'      => false,
				'level'   => '',
				'message' => 'Allowed values: alerts, review. Use `wp lw-scan notify disable` to stop e-mail altogether.',
			];
		}

		$stored = self::LEVEL_WORDS[ $word ];

		Options::update( [ 'notify_level' => $stored ] );

		return self::level_result( $stored, $prefs->enabled() );
	}

	/**
	 * Reads, replaces or clears the recipient list. An address the site
	 * would reject fails the whole call: a typo must not half-write a list
	 * whose point is that somebody reads it.
	 *
	 * @param string|null $emails Comma-separated addresses, or null to only report the current list.
	 * @param bool        $clear  Empty the list, falling back to the site admin address.
	 * @return array{ok:bool, recipients:string[], uses_admin_email:bool, message:string}
	 */
	public function recipients( ?string $emails, bool $clear ): array {
		if ( $clear && null !== $emails ) {
			return self::recipients_error( 'Pass a list of addresses or --clear, not both.' );
		}

		if ( $clear ) {
			Options::update( [ 'notify_emails' => [] ] );

			return self::recipients_result( 'Cleared.' );
		}

		if ( null === $emails ) {
			return self::recipients_result( '' );
		}

		$parsed = self::parse_emails( $emails );

		if ( [] !== $parsed['rejected'] ) {
			return self::recipients_error( 'Not a valid e-mail address: ' . implode( ', ', $parsed['rejected'] ) );
		}

		Options::update( [ 'notify_emails' => $parsed['valid'] ] );

		return self::recipients_result( '' );
	}

	/**
	 * Reads or sets how many findings one e-mail may list.
	 *
	 * @param string|null $limit `10`|`20`|`50`|`all`, or null to only report the current cap.
	 * @return array{ok:bool, limit:int, message:string}
	 */
	public function limit( ?string $limit ): array {
		if ( null === $limit ) {
			return self::limit_result( ( new Preferences() )->limit() );
		}

		$word = strtolower( trim( $limit ) );

		if ( ! isset( self::LIMIT_WORDS[ $word ] ) ) {
			return [
				'ok'      => false,
				'limit'   => 0,
				'message' => 'Allowed values: 10, 20, 50 or all.',
			];
		}

		Options::update( [ 'notify_limit' => self::LIMIT_WORDS[ $word ] ] );

		return self::limit_result( self::LIMIT_WORDS[ $word ] );
	}

	/**
	 * Mails the configured recipients a short test message.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public function test(): array {
		$options = Options::all();
		$to      = ( new Preferences( $options ) )->recipients();

		if ( [] === $to ) {
			return [
				'ok'      => false,
				'message' => 'There is nobody to mail: the recipient list is empty and this site has no admin e-mail address.',
			];
		}

		$sent = Mailer::send_test( $options );

		return [
			'ok'      => $sent,
			'message' => $sent
				? 'wp_mail() accepted the test e-mail for: ' . implode( ', ', $to )
				: 'wp_mail() refused the test e-mail for: ' . implode( ', ', $to ) . '. This site has no working mail transport.',
		];
	}

	/**
	 * Splits a comma-separated list into what the site accepts and what it
	 * does not.
	 *
	 * @param string $emails Comma-separated addresses.
	 * @return array{valid: string[], rejected: string[]}
	 */
	private static function parse_emails( string $emails ): array {
		$valid    = [];
		$rejected = [];

		foreach ( (array) preg_split( '/[\r\n,]+/', $emails ) as $part ) {
			$email = trim( sanitize_text_field( (string) $part ) );

			if ( '' === $email ) {
				continue;
			}

			if ( is_email( $email ) ) {
				$valid[] = $email;
			} else {
				$rejected[] = $email;
			}
		}

		return [
			'valid'    => array_values( array_unique( $valid ) ),
			'rejected' => array_values( array_unique( $rejected ) ),
		];
	}

	/**
	 * @param string $level   Stored level value.
	 * @param bool   $enabled Whether e-mail is switched on.
	 * @return array{ok:bool, level:string, message:string}
	 */
	private static function level_result( string $level, bool $enabled ): array {
		$message = 'Sending: ' . self::level_label( $level ) . '.';

		if ( ! $enabled ) {
			$message .= ' E-mail is off, so nothing goes out until: wp lw-scan notify enable';
		}

		return [
			'ok'      => true,
			'level'   => self::level_word( $level ),
			'message' => $message,
		];
	}

	/**
	 * Reports the recipient list as it stands after whatever just happened.
	 *
	 * @param string $prefix Sentence to put in front, '' for none.
	 * @return array{ok:bool, recipients:string[], uses_admin_email:bool, message:string}
	 */
	private static function recipients_result( string $prefix ): array {
		$prefs      = new Preferences();
		$configured = $prefs->configured();
		$admin      = $prefs->admin_email();

		if ( [] !== $configured ) {
			$sentence = 'Recipients: ' . implode( ', ', $configured );
		} elseif ( '' !== $admin ) {
			$sentence = 'No recipients configured; mail goes to the site admin address: ' . $admin;
		} else {
			$sentence = 'No recipients configured, and this site has no admin e-mail address: nothing would be sent.';
		}

		return [
			'ok'               => true,
			'recipients'       => $configured,
			'uses_admin_email' => $prefs->uses_admin_email(),
			'message'          => '' === $prefix ? $sentence : $prefix . ' ' . $sentence,
		];
	}

	/**
	 * @param string $message Why the call failed.
	 * @return array{ok:bool, recipients:string[], uses_admin_email:bool, message:string}
	 */
	private static function recipients_error( string $message ): array {
		return [
			'ok'               => false,
			'recipients'       => [],
			'uses_admin_email' => false,
			'message'          => $message,
		];
	}

	/**
	 * @param int $limit Cap in items, 0 for all.
	 * @return array{ok:bool, limit:int, message:string}
	 */
	private static function limit_result( int $limit ): array {
		return [
			'ok'      => true,
			'limit'   => $limit,
			'message' => 0 === $limit
				? 'Maximum items per e-mail: all of them.'
				: sprintf( 'Maximum items per e-mail: %d.', $limit ),
		];
	}

	/**
	 * @param string $level Stored level value.
	 */
	private static function level_word( string $level ): string {
		return Preferences::LEVEL_REVIEW === $level ? 'review' : 'alerts';
	}

	/**
	 * @param string $level Stored level value.
	 */
	private static function level_label( string $level ): string {
		return Formatter::notify_level_label( self::level_word( $level ) );
	}
}
