<?php
/**
 * Reads the notification settings out of the options array.
 *
 * @package LightweightPlugins\Scan
 */

declare(strict_types=1);

namespace LightweightPlugins\Scan\Notify;

use LightweightPlugins\Scan\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One reader for the four notification options, so the mailer, the
 * Notifications tab and `wp lw-scan notify` all answer the same question the
 * same way — what the switch says, which severities go out, how many lines
 * the body may carry, and who receives it.
 *
 * Every getter tolerates a partial options array: a missing
 * `notify_enabled` reads as off (the default since 1.4.5; `Upgrader` pins
 * older installs that never stored the key to on, so an upgrade changes
 * nothing about who gets mail), and a missing `notify_limit` as the
 * default cap.
 *
 * `recipients()` is the one method that reaches outside the array: an empty
 * recipient list means "the site's admin address", which is what made a
 * first scan mail 78 alerts to an admin who had configured nothing. The
 * fallback stays (silence would be worse), but `uses_admin_email()` lets the
 * UI and the CLI say out loud that it is in force.
 */
final class Preferences {

	/** Only alert-severity findings are mailed. */
	public const LEVEL_ALERT = 'alert';

	/** Every new finding is mailed, alert and review alike. */
	public const LEVEL_REVIEW = 'review';

	/** Values `notify_level` may take. */
	public const LEVELS = [ self::LEVEL_ALERT, self::LEVEL_REVIEW ];

	/** Body caps the Notifications tab offers, in tab order; 0 is "All". */
	public const LIMIT_CHOICES = [ 10, 20, 50, 0 ];

	/** Cap a site gets until it chooses another. */
	public const LIMIT_DEFAULT = 20;

	/** @var array<string, mixed> Plugin options this reader answers from. */
	private array $options;

	/**
	 * @param array<string, mixed>|null $options Plugin options; Options::all() when null.
	 */
	public function __construct( ?array $options = null ) {
		$this->options = $options ?? Options::all();
	}

	/**
	 * Whether any scan e-mail goes out at all — new findings and the
	 * failure-streak warning alike.
	 */
	public function enabled(): bool {
		return ! empty( $this->options['notify_enabled'] ?? false );
	}

	/**
	 * Which severities are mailed: `alert` for alerts only, `review` for
	 * everything new.
	 */
	public function level(): string {
		$level = (string) ( $this->options['notify_level'] ?? self::LEVEL_ALERT );

		return in_array( $level, self::LEVELS, true ) ? $level : self::LEVEL_ALERT;
	}

	/**
	 * How many findings one e-mail may list, 0 for all of them.
	 */
	public function limit(): int {
		if ( ! isset( $this->options['notify_limit'] ) ) {
			return self::LIMIT_DEFAULT;
		}

		$limit = (int) $this->options['notify_limit'];

		return in_array( $limit, self::LIMIT_CHOICES, true ) ? $limit : self::LIMIT_DEFAULT;
	}

	/**
	 * The addresses configured on the Notifications tab, invalid ones
	 * dropped. Empty means the site admin address is in use.
	 *
	 * @return string[]
	 */
	public function configured(): array {
		$emails = $this->options['notify_emails'] ?? [];

		return is_array( $emails ) ? self::valid( $emails ) : [];
	}

	/**
	 * Whether mail goes to the site's admin address because nothing was
	 * configured.
	 */
	public function uses_admin_email(): bool {
		return [] === $this->configured();
	}

	/**
	 * The site's own admin address, '' when there is none to read.
	 */
	public function admin_email(): string {
		$admin = get_option( 'admin_email' );

		return is_string( $admin ) ? $admin : '';
	}

	/**
	 * Who actually receives the mail: the configured list, or the site
	 * admin address when that list is empty.
	 *
	 * @return string[]
	 */
	public function recipients(): array {
		$configured = $this->configured();

		if ( [] !== $configured ) {
			return $configured;
		}

		return self::valid( [ $this->admin_email() ] );
	}

	/**
	 * @param array<int|string, mixed> $emails Candidate addresses.
	 * @return string[] Those `is_email()` accepts, deduplicated, in order.
	 */
	private static function valid( array $emails ): array {
		$out = [];

		foreach ( $emails as $email ) {
			$email = trim( (string) $email );

			if ( '' !== $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}

		return array_values( array_unique( $out ) );
	}
}
