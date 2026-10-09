<?php
/**
 * Snapshot and restore tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Integration;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Revision_Repository;

class RevisionTest extends TestCase {

	public function test_restore_updates_contacts_and_feed() {
		$pb = $this->create_phonebook( 'Snap' );
		$this->add_contact( $pb->id, 'Original', '0001' );
		$this->add_contact( $pb->id, 'Plus', '+441234567890' );
		$snapshot_id = Revision_Repository::snapshot( $pb->id, 'manual', 1 );

		Contact_Repository::delete_many( $pb->id, array_map( static function ( $c ) { return $c->id; }, Contact_Repository::all( $pb->id ) ) );
		$this->add_contact( $pb->id, 'Replacement', '9' );
		$this->assertSame( array( array( 'Replacement', '9' ) ), $this->names_in_xml( $this->feed( 'snap', $pb->access_token )->body ) );
		$etag_before = $this->feed( 'snap', $pb->access_token )->headers['ETag'];

		$this->assertTrue( Revision_Repository::restore( $snapshot_id, $pb->id, 1 ) );
		$r = $this->feed( 'snap', $pb->access_token );
		$this->assertSame( array( array( 'Original', '0001' ), array( 'Plus', '+441234567890' ) ), $this->names_in_xml( $r->body ) );
		$this->assertNotSame( $etag_before, $r->headers['ETag'] );
		$this->assertSame( 2, Phonebook_Repository::get( $pb->id )->contact_count );

		$list = Revision_Repository::list_for( $pb->id );
		$this->assertCount( 2, $list );
		$this->assertSame( 'restore', $list[0]->reason, 'restoring creates a safety snapshot first' );
		$this->assertSame( 1, (int) $list[0]->contact_count );

		$preview = Revision_Repository::get( $snapshot_id, $pb->id );
		$this->assertSame( 'Original', $preview->contacts[0]['name'] );
		$this->assertNull( Revision_Repository::get( $snapshot_id, $pb->id + 1 ), 'snapshots are scoped' );
		$this->assertWPError( Revision_Repository::restore( $snapshot_id, $pb->id + 1 ) );
	}

	public function test_retention_is_bounded() {
		update_option( 'spb_settings', array( 'revision_retention' => 2 ) );
		$pb = $this->create_phonebook( 'Prune' );
		for ( $i = 0; $i < 4; $i++ ) {
			Revision_Repository::snapshot( $pb->id, 'manual' );
		}
		$this->assertSame( 2, Revision_Repository::prune( $pb->id ) );
		$this->assertCount( 2, Revision_Repository::list_for( $pb->id ) );
		delete_option( 'spb_settings' );
	}
}
