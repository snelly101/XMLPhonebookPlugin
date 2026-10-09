<?php
/**
 * Flash notices across redirects.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Stores short-lived notices per user so admin-post handlers can redirect
 * back to a screen with feedback. Notices never contain contact data beyond
 * what the acting user just submitted.
 */
final class Notices {

	/**
	 * Add a notice.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Plain text (escaped on output).
	 */
	public static function add( $type, $message ) {
		$key     = self::key();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		$notices[] = array(
			'type'    => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'message' => (string) $message,
		);
		set_transient( $key, array_slice( $notices, -10 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Add a WP_Error's messages.
	 *
	 * @param \WP_Error $error Error.
	 */
	public static function add_error( \WP_Error $error ) {
		foreach ( $error->get_error_messages() as $message ) {
			self::add( 'error', $message );
		}
	}

	/**
	 * Print and clear pending notices.
	 */
	public static function render() {
		$key     = self::key();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible spb-notice" role="%3$s"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] ),
				'error' === $notice['type'] ? 'alert' : 'status'
			);
		}
	}

	/**
	 * Transient key for the current user.
	 *
	 * @return string
	 */
	private static function key() {
		return 'spb_notices_' . get_current_user_id();
	}
}
