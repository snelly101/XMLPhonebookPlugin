<?php
/**
 * Minimal PSR-4 style autoloader for the SitePhonebooks namespace.
 *
 * Class SitePhonebooks\Foo_Bar        => includes/class-foo-bar.php
 * Class SitePhonebooks\Feed\Foo_Bar   => includes/feed/class-foo-bar.php
 *
 * No Composer runtime dependency is required.
 *
 * @package SitePhonebooks
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'SitePhonebooks\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dir      = SPB_PLUGIN_DIR . 'includes/';
		if ( $parts ) {
			$dir .= strtolower( implode( '/', $parts ) ) . '/';
		}
		$file = $dir . 'class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
