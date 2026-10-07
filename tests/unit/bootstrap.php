<?php
/**
 * Bootstrap for WordPress-free unit tests: define the handful of WordPress
 * functions the pure classes rely on.
 *
 * @package SitePhonebooks
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SPB_PLUGIN_DIR' ) ) {
	define( 'SPB_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'SPB_VERSION' ) ) {
	define( 'SPB_VERSION', 'test' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) { // phpcs:ignore
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore
		return json_encode( $data, $options, $depth ); // phpcs:ignore
	}
}

require_once SPB_PLUGIN_DIR . 'includes/autoload.php';
