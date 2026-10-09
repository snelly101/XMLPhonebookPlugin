<?php
/**
 * Plugin settings access.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitises the single small autoloaded settings option.
 */
final class Settings {

	const OPTION = 'spb_settings';

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'default_access_mode' => 'token',
			'revision_retention'  => 10,
			'import_max_rows'     => 5000,
			'import_max_file_kb'  => 2048,
			'feed_max_age'        => 0,
			'staging_ttl_minutes' => 60,
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitise a settings array (also used as the Settings API callback).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		$out['default_access_mode'] = ( isset( $input['default_access_mode'] ) && 'public' === $input['default_access_mode'] ) ? 'public' : 'token';
		$out['revision_retention']  = self::bounded_int( $input, 'revision_retention', 1, 100, $defaults['revision_retention'] );
		$out['import_max_rows']     = self::bounded_int( $input, 'import_max_rows', 1, 50000, $defaults['import_max_rows'] );
		$out['import_max_file_kb']  = self::bounded_int( $input, 'import_max_file_kb', 16, 16384, $defaults['import_max_file_kb'] );
		$out['feed_max_age']        = self::bounded_int( $input, 'feed_max_age', 0, 86400, $defaults['feed_max_age'] );
		$out['staging_ttl_minutes'] = self::bounded_int( $input, 'staging_ttl_minutes', 5, 1440, $defaults['staging_ttl_minutes'] );
		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		return $out;
	}

	/**
	 * Clamp an integer setting.
	 *
	 * @param array  $input   Input array.
	 * @param string $key     Key.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @param int    $default Default.
	 * @return int
	 */
	private static function bounded_int( $input, $key, $min, $max, $default ) {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return (int) $default;
		}
		return (int) max( $min, min( $max, (int) $input[ $key ] ) );
	}
}
