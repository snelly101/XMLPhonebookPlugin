<?php
/**
 * Phonebook storage, naming and isolation tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Integration;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Phonebook_Repository;

class PhonebookTest extends TestCase {

	public function test_two_sites_produce_distinct_isolated_feeds() {
		$a = $this->create_phonebook( 'Frickley Mews' );
		$b = $this->create_phonebook( 'Potteries Court' );
		$this->assertSame( 'frickley-mews', $a->slug );
		$this->assertSame( 'potteries-court', $b->slug );

		$this->add_contact( $a->id, 'Reception A', '1001' );
		$this->add_contact( $b->id, 'Reception B', '2001' );
		$this->add_contact( $b->id, 'Support', '0001' );

		$a  = Phonebook_Repository::get( $a->id );
		$b  = Phonebook_Repository::get( $b->id );
		$ra = $this->feed( 'frickley-mews', $a->access_token );
		$rb = $this->feed( 'potteries-court', $b->access_token );
		$this->assertSame( 200, $ra->status );
		$this->assertSame( 200, $rb->status );
		$this->assertSame( 'Frickley Mews', $this->title_in_xml( $ra->body ) );
		$this->assertSame( 'Potteries Court', $this->title_in_xml( $rb->body ) );
		$this->assertSame( array( array( 'Reception A', '1001' ) ), $this->names_in_xml( $ra->body ) );
		$this->assertSame( array( array( 'Reception B', '2001' ), array( 'Support', '0001' ) ), $this->names_in_xml( $rb->body ) );
		$this->assertSame( 1, Phonebook_Repository::get( $a->id )->contact_count );
		$this->assertSame( 2, Phonebook_Repository::get( $b->id )->contact_count );
	}

	public function test_duplicate_names_are_blocked_and_slug_collisions_resolved() {
		$a = $this->create_phonebook( 'Frickley Mews' );
		$this->assertSame( 'frickley-mews', $a->slug );

		$dup = Phonebook_Repository::create( array( 'name' => 'frickley mews' ) );
		$this->assertWPError( $dup );
		$this->assertSame( 'spb_name_duplicate', $dup->get_error_code() );

		$b = $this->create_phonebook( 'Frickley Mews!' );
		$this->assertSame( 'frickley-mews-2', $b->slug, 'a name that sanitises to the same slug gets a suffix' );
		$c = $this->create_phonebook( 'Frickley-Mews ' );
		$this->assertSame( 'frickley-mews-3', $c->slug );

		$this->assertSame( 'Frickley Mews', $this->title_in_xml( $this->feed( 'frickley-mews', $a->access_token )->body ) );
		$this->assertSame( 'Frickley Mews!', $this->title_in_xml( $this->feed( 'frickley-mews-2', $b->access_token )->body ) );
		$this->assertSame( 3, Phonebook_Repository::count() );
	}

	public function test_non_latin_name_gets_fallback_slug_and_unique_suffix() {
		$a = $this->create_phonebook( 'Москва' );
		$b = $this->create_phonebook( '東京支店' );
		if ( class_exists( '\Transliterator' ) ) {
			$this->assertSame( 'moskva', $a->slug );
			$this->assertSame( 'dong-jing-zhi-dian', $b->slug );
		} else {
			$this->assertSame( 'phonebook', $a->slug );
			$this->assertSame( 'phonebook-2', $b->slug );
		}
		$c = $this->create_phonebook( '!!!' );
		$this->assertSame( 'phonebook', $c->slug );
		$d = $this->create_phonebook( '???' );
		$this->assertSame( 'phonebook-2', $d->slug );
		$this->assertSame( '!!!', $this->title_in_xml( $this->feed( 'phonebook', $c->access_token )->body ) );
	}

	public function test_renaming_updates_title_but_keeps_url() {
		$a = $this->create_phonebook( 'Frickley Mews' );
		$this->add_contact( $a->id, 'Reception', '1001' );
		$before = Phonebook_Repository::get( $a->id );
		$this->assertSame( 200, $this->feed( 'frickley-mews', $a->access_token )->status );

		$this->assertTrue( Phonebook_Repository::update_details( $a->id, array( 'name' => 'Frickley Mews Residents' ) ) );
		$after = Phonebook_Repository::get( $a->id );
		$this->assertSame( 'frickley-mews', $after->slug );
		$this->assertGreaterThan( $before->revision, $after->revision, 'title change publishes a new revision' );
		$r = $this->feed( 'frickley-mews', $a->access_token );
		$this->assertSame( 'Frickley Mews Residents', $this->title_in_xml( $r->body ) );
		$this->assertSame( array( array( 'Reception', '1001' ) ), $this->names_in_xml( $r->body ) );

		$err = Phonebook_Repository::update_details( $a->id, array( 'name' => '' ) );
		$this->assertWPError( $err );
	}

	public function test_explicit_slug_change_is_validated_and_unique() {
		$a = $this->create_phonebook( 'Alpha' );
		$b = $this->create_phonebook( 'Beta' );
		$this->assertWPError( Phonebook_Repository::change_slug( $b->id, 'alpha' ) );
		$this->assertWPError( Phonebook_Repository::change_slug( $b->id, 'Bad Slug' ) );
		$this->assertSame( 'beta-site', Phonebook_Repository::change_slug( $b->id, 'beta-site' ) );
		$this->assertSame( 404, $this->feed( 'beta', Phonebook_Repository::get( $b->id )->access_token )->status );
		$this->assertSame( 200, $this->feed( 'beta-site', Phonebook_Repository::get( $b->id )->access_token )->status );
		$this->assertSame( 200, $this->feed( 'alpha', $a->access_token )->status, 'other feed untouched' );
	}

	public function test_contacts_are_scoped_and_duplicates_follow_policy() {
		$a = $this->create_phonebook( 'A' );
		$b = $this->create_phonebook( 'B' );
		$id = $this->add_contact( $a->id, 'Support Engineer', '1000' );
		$this->add_contact( $a->id, 'Support Engineer', '0001' );
		$this->assertNull( Contact_Repository::get( $id, $b->id ), 'contact lookups are scoped by phonebook' );
		$this->assertNotNull( Contact_Repository::get( $id, $a->id ) );

		$dup = Contact_Repository::create( $a->id, array( 'name' => ' Support  Engineer', 'telephone' => '1000 ' ) );
		$this->assertWPError( $dup );
		$this->assertSame( 'spb_contact_duplicate', $dup->get_error_code() );
		$this->assertIsInt( Contact_Repository::create( $b->id, array( 'name' => 'Support Engineer', 'telephone' => '1000' ) ), 'same contact allowed in another phonebook' );

		$this->assertSame( 0, Contact_Repository::delete_many( $b->id, array( $id ) ), 'cannot delete across phonebooks' );
		$this->assertSame( 2, Contact_Repository::count( $a->id ) );
		$this->assertSame( 1, Contact_Repository::delete_many( $a->id, array( $id ) ) );
	}

	public function test_telephone_strings_survive_storage_and_export() {
		$a = $this->create_phonebook( 'Tel' );
		$this->add_contact( $a->id, 'Zero', '0001' );
		$this->add_contact( $a->id, 'Plus', '+441234567890' );
		$this->add_contact( $a->id, 'Spaced', '00 44 1234' );
		$xml = $this->feed( 'tel', $a->access_token )->body;
		$this->assertSame( array( array( 'Plus', '+441234567890' ), array( 'Spaced', '00 44 1234' ), array( 'Zero', '0001' ) ), $this->names_in_xml( $xml ) );
		$this->assertStringContainsString( '<Telephone>0001</Telephone>', $xml );
	}

	public function test_edit_updates_feed_and_validation_errors_are_reported() {
		$a  = $this->create_phonebook( 'Edit' );
		$id = $this->add_contact( $a->id, 'Old', '1' );
		$this->assertTrue( Contact_Repository::update( $id, $a->id, array( 'name' => 'New & Improved', 'telephone' => '2' ) ) );
		$this->assertSame( array( array( 'New & Improved', '2' ) ), $this->names_in_xml( $this->feed( 'edit', $a->access_token )->body ) );
		$this->assertWPError( Contact_Repository::update( $id, $a->id, array( 'name' => "Bad\x01" ) ) );
		$this->assertWPError( Contact_Repository::update( $id, 999999, array( 'name' => 'X' ) ) );
	}

	public function test_delete_phonebook_removes_everything_and_feed() {
		$a = $this->create_phonebook( 'Gone' );
		$this->add_contact( $a->id, 'X', '1' );
		$this->assertTrue( Phonebook_Repository::delete( $a->id ) );
		$this->assertNull( Phonebook_Repository::get( $a->id ) );
		$this->assertSame( 0, Contact_Repository::count( $a->id ) );
		$this->assertSame( 404, $this->feed( 'gone', $a->access_token )->status );
	}

	public function test_list_query_searches_and_paginates() {
		foreach ( array( 'Alpha House', 'Beta House', 'Gamma Court' ) as $name ) {
			$this->create_phonebook( $name );
		}
		$r = Phonebook_Repository::query( array( 'search' => 'house', 'per_page' => 1, 'page' => 2 ) );
		$this->assertSame( 2, $r['total'] );
		$this->assertCount( 1, $r['items'] );
		$this->assertSame( 'Beta House', $r['items'][0]->name );
	}
}
