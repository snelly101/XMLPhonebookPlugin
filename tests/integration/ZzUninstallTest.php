<?php
/**
 * Uninstall policy test. Runs last because dropping/recreating real tables
 * cannot happen inside the suite's per-test transaction.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Integration;

use SitePhonebooks\Installer;

class ZzUninstallTest extends TestCase {

	private function table_exists( $name ) {
		global $wpdb;
		$table = Installer::table( $name );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function test_uninstall_preserves_data_by_default_and_deletes_when_enabled() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'site-phonebooks/site-phonebooks.php' );
		}
		update_option( 'spb_settings', array( 'delete_on_uninstall' => false ) );
		include SPB_PLUGIN_DIR . 'uninstall.php';
		$this->assertTrue( $this->table_exists( 'phonebooks' ), 'tables kept by default' );
		$this->assertSame( SPB_DB_VERSION, get_option( 'spb_db_version' ) );

		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		update_option( 'spb_settings', array( 'delete_on_uninstall' => true ) );
		include SPB_PLUGIN_DIR . 'uninstall.php';
		foreach ( array( 'phonebooks', 'contacts', 'revisions', 'imports' ) as $name ) {
			$this->assertFalse( $this->table_exists( $name ), $name . ' dropped' );
		}
		$this->assertFalse( get_option( 'spb_db_version' ) );
		$this->assertFalse( get_option( 'spb_settings' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );

		// Recreate real tables so a later run starts clean.
		Installer::install_site();
		$this->assertTrue( $this->table_exists( 'phonebooks' ) );
	}
}
