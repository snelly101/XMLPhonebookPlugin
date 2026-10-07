<?php
/**
 * Import preview and atomic commit tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Integration;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Import\Import_Committer;
use SitePhonebooks\Import\Import_Planner;
use SitePhonebooks\Import\Import_Staging;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Revision_Repository;

class ImportTest extends TestCase {

	private $user;

	public function set_up() {
		parent::set_up();
		$this->user = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_preview_leaves_live_data_unchanged_and_commit_applies_once() {
		$pb = $this->create_phonebook( 'Imp' );
		$this->add_contact( $pb->id, 'Existing', '0001' );
		$before = Phonebook_Repository::get( $pb->id );

		list( $staging_id, $plan ) = $this->stage_csv( $pb->id, $this->user, "Name,Telephone\nReception,1001\nExisting,0001\n\"Smith, John\",+441234567890\nReception,1001\n" );
		$this->assertSame( 4, $plan['summary']['total'] );
		$this->assertSame( 2, $plan['summary']['add'] );
		$this->assertSame( 1, $plan['summary']['duplicate_existing'] );
		$this->assertSame( 1, $plan['summary']['duplicate_in_file'] );
		$this->assertSame( 1, $plan['summary']['retained'] );
		$this->assertSame( 3, $plan['summary']['final_count'] );
		$this->assertSame( array(), $plan['blocking'] );
		$this->assertSame( 5, $plan['rows'][3]['row'], 'source row numbers are reported' );

		$this->assertSame( 1, Contact_Repository::count( $pb->id ), 'preview changed nothing' );
		$this->assertSame( $before->revision, Phonebook_Repository::get( $pb->id )->revision );

		$result = Import_Committer::commit( $staging_id, $pb->id, $this->user );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 2, $result['added'] );
		$this->assertSame( 3, Contact_Repository::count( $pb->id ) );
		$this->assertGreaterThan( $before->revision, Phonebook_Repository::get( $pb->id )->revision );
		$this->assertSame( array( array( 'Existing', '0001' ), array( 'Reception', '1001' ), array( 'Smith, John', '+441234567890' ) ), $this->names_in_xml( $this->feed( 'imp', $pb->access_token )->body ) );

		$again = Import_Committer::commit( $staging_id, $pb->id, $this->user );
		$this->assertWPError( $again, 'a committed import cannot be applied twice' );
		$this->assertSame( 3, Contact_Repository::count( $pb->id ) );
	}

	public function test_replace_removes_only_missing_contacts_and_snapshots_first() {
		$pb = $this->create_phonebook( 'Rep' );
		$keep_id = $this->add_contact( $pb->id, 'Keep', '1' );
		$this->add_contact( $pb->id, 'Drop', '2' );

		list( $staging_id, $plan ) = $this->stage_csv( $pb->id, $this->user, "Name,Telephone\nKeep,1\nNew,3\n", array( 'mode' => 'replace' ) );
		$this->assertSame( 1, $plan['summary']['removed'] );
		$this->assertSame( 1, $plan['summary']['unchanged'] );
		$this->assertSame( 1, $plan['summary']['add'] );
		$this->assertSame( 2, $plan['summary']['final_count'] );

		$this->assertWPError( Import_Committer::commit( $staging_id, $pb->id, $this->user, false ), 'replace requires confirmation' );
		$this->assertSame( 2, Contact_Repository::count( $pb->id ) );

		$result = Import_Committer::commit( $staging_id, $pb->id, $this->user, true );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['removed'] );
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( array( array( 'Keep', '1' ), array( 'New', '3' ) ), $this->names_in_xml( $this->feed( 'rep', $pb->access_token )->body ) );
		$this->assertNotNull( Contact_Repository::get( $keep_id, $pb->id ), 'unchanged contacts keep their IDs' );

		$snapshots = Revision_Repository::list_for( $pb->id );
		$this->assertCount( 1, $snapshots );
		$this->assertSame( 'replace_import', $snapshots[0]->reason );
		$this->assertSame( 2, (int) $snapshots[0]->contact_count );
	}

	public function test_invalid_rows_block_unless_valid_only_chosen() {
		$pb  = $this->create_phonebook( 'Inv' );
		$csv = "Name,Telephone\nGood,1\n,2\nNoPhone,\nBad\x01Name,4\n";
		list( $staging_id, $plan ) = $this->stage_csv( $pb->id, $this->user, $csv );
		$this->assertSame( 3, $plan['summary']['invalid'] );
		$this->assertSame( 1, $plan['summary']['add'] );
		$this->assertNotEmpty( $plan['blocking'] );
		$this->assertSame( Import_Planner::STATUS_INVALID, $plan['rows'][1]['status'] );
		$this->assertSame( 3, $plan['rows'][1]['row'] );
		$this->assertWPError( Import_Committer::commit( $staging_id, $pb->id, $this->user ) );
		$this->assertSame( 0, Contact_Repository::count( $pb->id ) );

		list( $staging_id, $plan ) = $this->stage_csv( $pb->id, $this->user, $csv, array( 'valid_only' => true ) );
		$this->assertSame( array(), $plan['blocking'] );
		$result = Import_Committer::commit( $staging_id, $pb->id, $this->user );
		$this->assertIsArray( $result );
		$this->assertSame( 1, Contact_Repository::count( $pb->id ) );
	}

	public function test_empty_replace_is_rejected() {
		$pb = $this->create_phonebook( 'Empty' );
		$this->add_contact( $pb->id, 'Keep', '1' );
		list( $staging_id, $plan ) = $this->stage_csv( $pb->id, $this->user, "Name,Telephone\n,\n", array( 'mode' => 'replace', 'valid_only' => true ) );
		$this->assertNotEmpty( $plan['blocking'] );
		$this->assertWPError( Import_Committer::commit( $staging_id, $pb->id, $this->user, true ) );
		$this->assertSame( 1, Contact_Repository::count( $pb->id ) );
	}

	public function test_edit_after_preview_is_detected() {
		$pb = $this->create_phonebook( 'Stale' );
		list( $staging_id ) = $this->stage_csv( $pb->id, $this->user, "Name,Telephone\nA,1\n" );
		$this->add_contact( $pb->id, 'Someone Else', '9' );
		$result = Import_Committer::commit( $staging_id, $pb->id, $this->user );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'changed after this preview', $result->get_error_message() );
		$this->assertSame( 1, Contact_Repository::count( $pb->id ) );
		$this->assertNotNull( Import_Staging::get( $staging_id, $pb->id, $this->user ), 'staged import is kept for re-preview' );
	}

	public function test_storage_failure_rolls_back_everything() {
		$pb = $this->create_phonebook( 'Roll' );
		$this->add_contact( $pb->id, 'Keep', '1' );
		$this->add_contact( $pb->id, 'Drop', '2' );
		$before = Phonebook_Repository::get( $pb->id );
		list( $staging_id ) = $this->stage_csv( $pb->id, $this->user, "Name,Telephone\nKeep,1\nNew,3\n", array( 'mode' => 'replace' ) );

		$table    = Contact_Repository::table();
		$sabotage = static function ( $query ) use ( $table ) {
			if ( 0 === strpos( $query, "INSERT INTO {$table}" ) ) {
				return 'INSERT INTO spb_table_that_does_not_exist (x) VALUES (1)';
			}
			return $query;
		};
		global $wpdb;
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $sabotage );
		$result = Import_Committer::commit( $staging_id, $pb->id, $this->user, true );
		remove_filter( 'query', $sabotage );
		$wpdb->suppress_errors( $suppress );

		$this->assertWPError( $result );
		$this->assertSame( 'spb_import_failed', $result->get_error_code() );
		$this->assertSame( 2, Contact_Repository::count( $pb->id ), 'deleted rows were restored' );
		$this->assertSame( $before->revision, Phonebook_Repository::get( $pb->id )->revision );
		$this->assertCount( 0, Revision_Repository::list_for( $pb->id ), 'snapshot was rolled back too' );
		$this->assertSame( array( array( 'Drop', '2' ), array( 'Keep', '1' ) ), $this->names_in_xml( $this->feed( 'roll', $pb->access_token )->body ) );
		$staging = Import_Staging::get( $staging_id, $pb->id, $this->user );
		$this->assertSame( Import_Staging::STATUS_PREVIEW, $staging->status, 'import can be retried' );
	}

	public function test_csv_mapping_delimiters_bom_and_encoding() {
		$pb = $this->create_phonebook( 'Map' );
		$csv = "\xEF\xBB\xBFExtension;Display Name;Notes\r\n1001;\"Smith; John\";\"VIP\"\r\n0001;Zoë;\r\n";
		$staging_id = Import_Staging::create( $pb->id, $this->user, 'csv', 'x.csv', $csv );
		$staging    = Import_Staging::get( $staging_id, $pb->id, $this->user, true );
		$rows       = Import_Planner::rows_from_csv( $staging->raw_content, array( 'has_header' => true, 'map' => array( 'name' => 1, 'telephone' => 0, 'notes' => 2 ) ) );
		$this->assertTrue( $rows['ok'], $rows['error'] );
		$this->assertSame( array( 'Extension', 'Display Name', 'Notes' ), $rows['header'] );
		$this->assertSame( 'Smith; John', $rows['rows'][0]['name'] );
		$this->assertSame( '1001', $rows['rows'][0]['telephone'] );
		$this->assertSame( 'VIP', $rows['rows'][0]['notes'] );
		$this->assertSame( 'Zoë', $rows['rows'][1]['name'] );
		$this->assertSame( '0001', $rows['rows'][1]['telephone'] );

		$bad = Import_Planner::rows_from_csv( "Name,Telephone\nCaf\xE9,1\n", array( 'has_header' => true, 'map' => array( 'name' => 0, 'telephone' => 1 ) ) );
		$this->assertFalse( $bad['ok'] );
		$this->assertStringContainsString( 'UTF-8', $bad['error'] );
		$fixed = Import_Planner::rows_from_csv( "Name,Telephone\nCaf\xE9,1\n", array( 'encoding' => 'windows-1252', 'has_header' => true, 'map' => array( 'name' => 0, 'telephone' => 1 ) ) );
		$this->assertTrue( $fixed['ok'] );
		$this->assertSame( 'Café', $fixed['rows'][0]['name'] );

		$missing = Import_Planner::rows_from_csv( "A,B\n", array( 'map' => array( 'name' => 0 ) ) );
		$this->assertFalse( $missing['ok'] );
		$same = Import_Planner::rows_from_csv( "A,B\n", array( 'map' => array( 'name' => 0, 'telephone' => 0 ) ) );
		$this->assertFalse( $same['ok'] );
	}

	public function test_row_limit_is_enforced_before_commit() {
		update_option( 'spb_settings', array( 'import_max_rows' => 3 ) );
		$pb   = $this->create_phonebook( 'Limit' );
		$rows = Import_Planner::rows_from_csv( "Name,Telephone\nA,1\nB,2\nC,3\nD,4\n", array( 'has_header' => true, 'map' => array( 'name' => 0, 'telephone' => 1 ) ) );
		$this->assertFalse( $rows['ok'] );
		$this->assertStringContainsString( 'more than', $rows['error'] );
		delete_option( 'spb_settings' );
	}

	public function test_xml_import_goes_through_same_pipeline_with_optional_rename() {
		$pb  = $this->create_phonebook( 'Xml Site' );
		$xml = file_get_contents( dirname( __DIR__ ) . '/fixtures/yealink-sample.xml' );
		$parsed = Import_Planner::rows_from_xml( $xml );
		$this->assertTrue( $parsed['ok'] );
		$this->assertSame( 'Phonelist', $parsed['title'] );
		$this->assertCount( 4, $parsed['rows'] );

		$options = array( 'mode' => 'merge', 'rename_from_title' => false, 'title' => $parsed['title'] );
		$plan    = Import_Planner::plan( $pb->id, $parsed['rows'], $options );
		$this->assertSame( 4, $plan['summary']['add'] );
		$this->assertNull( $plan['rename'] );
		$staging_id = Import_Staging::create( $pb->id, $this->user, 'xml', 'book.xml', $xml, $options );
		Import_Staging::save_plan( $staging_id, $options, $plan, Phonebook_Repository::get( $pb->id )->revision );
		$this->assertIsArray( Import_Committer::commit( $staging_id, $pb->id, $this->user ) );
		$this->assertSame( 'Xml Site', Phonebook_Repository::get( $pb->id )->name, 'title does not overwrite the site name by default' );
		$this->assertSame( 4, Contact_Repository::count( $pb->id ) );
		$this->assertSame( '0001', $this->names_in_xml( $this->feed( 'xml-site', $pb->access_token )->body )[2][1] );

		$this->create_phonebook( 'Phonelist' );
		$options['rename_from_title'] = true;
		$plan = Import_Planner::plan( $pb->id, $parsed['rows'], $options );
		$this->assertNotEmpty( $plan['blocking'], 'rename to an existing site name is blocked' );

		$pb2  = $this->create_phonebook( 'Temp Name' );
		$plan = Import_Planner::plan( $pb2->id, $parsed['rows'], array( 'mode' => 'merge', 'rename_from_title' => true, 'title' => 'Fresh Title' ) );
		$this->assertSame( 'Fresh Title', $plan['rename'] );
		$staging_id = Import_Staging::create( $pb2->id, $this->user, 'xml', 'book.xml', $xml );
		Import_Staging::save_plan( $staging_id, array( 'mode' => 'merge' ), $plan, Phonebook_Repository::get( $pb2->id )->revision );
		$this->assertIsArray( Import_Committer::commit( $staging_id, $pb2->id, $this->user ) );
		$this->assertSame( 'Fresh Title', Phonebook_Repository::get( $pb2->id )->name );
		$this->assertSame( 'temp-name', Phonebook_Repository::get( $pb2->id )->slug, 'URL is preserved on rename' );
	}

	public function test_unsafe_or_malformed_xml_is_rejected_without_changes() {
		$pb = $this->create_phonebook( 'Safe' );
		$this->add_contact( $pb->id, 'Keep', '1' );
		foreach ( array(
			'<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><XXXIPPhoneDirectory><DirectoryEntry><Name>&e;</Name><Telephone>1</Telephone></DirectoryEntry></XXXIPPhoneDirectory>',
			'<XXXIPPhoneDirectory><DirectoryEntry>',
			'<Other/>',
			'',
		) as $bad ) {
			$r = Import_Planner::rows_from_xml( $bad );
			$this->assertFalse( $r['ok'] );
		}
		$this->assertSame( 1, Contact_Repository::count( $pb->id ) );
	}

	public function test_staging_is_scoped_to_user_and_phonebook_and_expires() {
		$pb    = $this->create_phonebook( 'Scope' );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id    = Import_Staging::create( $pb->id, $this->user, 'csv', 'a.csv', "Name,Telephone\nA,1\n" );
		$this->assertNotNull( Import_Staging::get( $id, $pb->id, $this->user ) );
		$this->assertNull( Import_Staging::get( $id, $pb->id, $other ) );
		$this->assertNull( Import_Staging::get( $id, $pb->id + 1, $this->user ) );
		$this->assertWPError( Import_Committer::commit( $id, $pb->id, $other ) );

		global $wpdb;
		$wpdb->update( Import_Staging::table(), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $id ) );
		$this->assertNull( Import_Staging::get( $id, $pb->id, $this->user ) );
		$this->assertSame( 1, Import_Staging::cleanup_expired() );
	}
}
