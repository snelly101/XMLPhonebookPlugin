<?php
/**
 * PHPUnit bootstrap.
 *
 * - The "unit" suite needs no WordPress: a few WordPress functions are stubbed.
 * - The "integration" suite loads the WordPress test library. Set WP_TESTS_DIR
 *   (the wordpress-develop tests/phpunit directory) and optionally
 *   WP_TESTS_CONFIG_FILE_PATH. See docs/developer-notes.md.
 *
 * @package SitePhonebooks
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$spb_suite = 'unit';
foreach ( $_SERVER['argv'] as $spb_i => $spb_arg ) {
	if ( '--testsuite' === $spb_arg && isset( $_SERVER['argv'][ $spb_i + 1 ] ) ) {
		$spb_suite = $_SERVER['argv'][ $spb_i + 1 ];
	} elseif ( 0 === strpos( $spb_arg, '--testsuite=' ) ) {
		$spb_suite = substr( $spb_arg, 12 );
	} elseif ( false !== strpos( $spb_arg, 'tests/integration' ) ) {
		$spb_suite = 'integration';
	}
}

if ( 'integration' !== $spb_suite ) {
	require_once __DIR__ . '/unit/bootstrap.php';
	return;
}

$spb_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $spb_tests_dir ) {
	$spb_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}
if ( ! file_exists( $spb_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test library at {$spb_tests_dir}. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}
$spb_config = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );
if ( $spb_config ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', $spb_config );
}

require_once $spb_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/site-phonebooks.php';
	}
);

// The WordPress test suite wraps every test in a transaction, so the plugin
// must use savepoints instead of START TRANSACTION (which would commit it).
tests_add_filter( 'spb_db_in_outer_transaction', '__return_true' );

tests_add_filter(
	'setup_theme',
	static function () {
		global $wpdb;
		\SitePhonebooks\Installer::install_site();
		foreach ( array( 'contacts', 'revisions', 'imports', 'phonebooks' ) as $spb_table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . \SitePhonebooks\Installer::table( $spb_table ) ); // phpcs:ignore
		}
	},
	1
);

require $spb_tests_dir . '/includes/bootstrap.php';
