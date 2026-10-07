<?php
/**
 * Shared helpers for integration tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Integration;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Feed\Feed_Controller;
use SitePhonebooks\Import\Import_Planner;
use SitePhonebooks\Import\Import_Staging;
use SitePhonebooks\Phonebook_Repository;

abstract class TestCase extends \WP_UnitTestCase {

	protected function create_phonebook( $name, array $extra = array() ) {
		$id = Phonebook_Repository::create( array_merge( array( 'name' => $name ), $extra ) );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : 'create failed' );
		return Phonebook_Repository::get( $id );
	}

	protected function add_contact( $phonebook_id, $name, $telephone, $notes = '' ) {
		$id = Contact_Repository::create( $phonebook_id, compact( 'name', 'telephone', 'notes' ) );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : 'contact create failed' );
		return $id;
	}

	protected function feed( $slug, $key = null, array $request = array() ) {
		return Feed_Controller::handle( $slug, $key, $request );
	}

	protected function names_in_xml( $xml ) {
		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ), 'feed is well-formed XML' );
		$out = array();
		foreach ( $dom->getElementsByTagName( 'DirectoryEntry' ) as $entry ) {
			$out[] = array( $entry->getElementsByTagName( 'Name' )->item( 0 )->textContent, $entry->getElementsByTagName( 'Telephone' )->item( 0 )->textContent );
		}
		return $out;
	}

	protected function title_in_xml( $xml ) {
		$dom = new \DOMDocument();
		$dom->loadXML( $xml );
		return $dom->getElementsByTagName( 'Title' )->item( 0 )->textContent;
	}

	/**
	 * Stage a CSV upload, map it and build a preview plan.
	 */
	protected function stage_csv( $phonebook_id, $user_id, $csv, array $options = array() ) {
		$options    = array_merge(
			array(
				'encoding'   => 'utf-8',
				'delimiter'  => 'comma',
				'has_header' => true,
				'map'        => array( 'name' => 0, 'telephone' => 1, 'notes' => null ),
				'mode'       => 'merge',
				'valid_only' => false,
			),
			$options
		);
		$staging_id = Import_Staging::create( $phonebook_id, $user_id, 'csv', 'upload.csv', $csv, $options );
		$this->assertIsInt( $staging_id );
		$staging = Import_Staging::get( $staging_id, $phonebook_id, $user_id, true );
		$rows    = Import_Planner::rows_from_csv( $staging->raw_content, $options );
		$this->assertTrue( $rows['ok'], $rows['error'] );
		$plan      = Import_Planner::plan( $phonebook_id, $rows['rows'], $options );
		$phonebook = Phonebook_Repository::get( $phonebook_id );
		Import_Staging::save_plan( $staging_id, $options, $plan, $phonebook->revision );
		return array( $staging_id, $plan );
	}
}
