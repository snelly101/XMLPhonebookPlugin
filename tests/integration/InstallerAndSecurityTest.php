<?php
/**
 * Installation, capability and settings tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Integration;

use SitePhonebooks\Installer;
use SitePhonebooks\Settings;

class InstallerAndSecurityTest extends TestCase {

	public function test_tables_and_options_exist_after_activation() {
		global $wpdb;
		foreach ( array( 'phonebooks', 'contacts', 'revisions', 'imports' ) as $name ) {
			$table = Installer::table( $name );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
		$this->assertSame( SPB_DB_VERSION, get_option( 'spb_db_version' ) );
		$this->assertSame( SPB_REWRITE_VERSION, get_option( 'spb_rewrite_version' ) );
		$this->assertNotFalse( wp_next_scheduled( 'spb_cleanup' ) );
	}

	public function test_capability_is_granted_to_administrators_only() {
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( user_can( $admin, Installer::CAPABILITY ) );
		$this->assertFalse( user_can( $editor, Installer::CAPABILITY ) );
		$this->assertFalse( user_can( $subscriber, Installer::CAPABILITY ) );
		$this->assertFalse( user_can( 0, Installer::CAPABILITY ) );
	}

	public function test_reactivation_preserves_data_and_is_idempotent() {
		$pb = $this->create_phonebook( 'Persist' );
		$this->add_contact( $pb->id, 'X', '1' );
		Installer::deactivate();
		$this->assertFalse( wp_next_scheduled( 'spb_cleanup' ) );
		Installer::activate();
		$this->assertSame( 'Persist', \SitePhonebooks\Phonebook_Repository::get( $pb->id )->name );
		$this->assertSame( 1, \SitePhonebooks\Contact_Repository::count( $pb->id ) );
		$this->assertSame( 200, $this->feed( 'persist', $pb->access_token )->status, 'URL and token survive reactivation' );
	}

	public function test_upgrade_path_runs_when_version_differs() {
		update_option( 'spb_db_version', '0' );
		Installer::maybe_upgrade();
		$this->assertSame( SPB_DB_VERSION, get_option( 'spb_db_version' ) );
	}

	public function test_settings_are_sanitised_and_bounded() {
		$s = Settings::sanitize( array( 'default_access_mode' => 'evil', 'revision_retention' => 9999, 'import_max_rows' => -5, 'feed_max_age' => 'x', 'delete_on_uninstall' => '1' ) );
		$this->assertSame( 'token', $s['default_access_mode'] );
		$this->assertSame( 100, $s['revision_retention'] );
		$this->assertSame( 1, $s['import_max_rows'] );
		$this->assertSame( 0, $s['feed_max_age'] );
		$this->assertTrue( $s['delete_on_uninstall'] );
		$this->assertSame( 'token', Settings::get( 'default_access_mode' ) );
	}
}
