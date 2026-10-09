<?php
/**
 * CSV export safety tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SitePhonebooks\Csv\Csv_Exporter;
use SitePhonebooks\Csv\Csv_Parser;

class CsvExporterTest extends TestCase {

	public function test_formulas_are_neutralised_but_telephones_preserved() {
		$this->assertSame( "'=cmd|' /C calc'!A0", Csv_Exporter::protect_cell( "=cmd|' /C calc'!A0" ) );
		$this->assertSame( "'@SUM(1+1)", Csv_Exporter::protect_cell( '@SUM(1+1)' ) );
		$this->assertSame( "'+cmd|' /C calc'!A0", Csv_Exporter::protect_cell( "+cmd|' /C calc'!A0" ) );
		$this->assertSame( "'-2+3+cmd|' /C calc'!A0", Csv_Exporter::protect_cell( "-2+3+cmd|' /C calc'!A0" ) );
		$this->assertSame( "'\tx", Csv_Exporter::protect_cell( "\tx" ) );
		$this->assertSame( '+441234567890', Csv_Exporter::protect_cell( '+441234567890' ) );
		$this->assertSame( '0001', Csv_Exporter::protect_cell( '0001' ) );
		$this->assertSame( '+44 (0) 1234-567', Csv_Exporter::protect_cell( '+44 (0) 1234-567' ) );
		$this->assertSame( '-', Csv_Exporter::protect_cell( '-' ) );
		$this->assertSame( 'Support Engineer', Csv_Exporter::protect_cell( 'Support Engineer' ) );
	}

	public function test_round_trip_through_parser_restores_values() {
		$contacts = array(
			array( 'name' => '=HYPERLINK("http://x")', 'telephone' => '+441234567890', 'notes' => '-ok' ),
			array( 'name' => 'Smith, "John"', 'telephone' => '0001', 'notes' => "multi\nline" ),
			array( 'name' => 'Zoë', 'telephone' => ' 00 44 ', 'notes' => '' ),
		);
		$csv = Csv_Exporter::build( $contacts );
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"http://x\"\")\"", $csv );
		$this->assertStringContainsString( ',+441234567890,', $csv );

		$decoded = Csv_Parser::to_utf8( $csv );
		$parsed  = Csv_Parser::parse( $decoded['text'], 'comma' );
		$this->assertTrue( $parsed['ok'] );
		$this->assertSame( array( 'Name', 'Telephone', 'Notes' ), $parsed['records'][0]['fields'] );
		$rows = array_slice( $parsed['records'], 1 );
		foreach ( $contacts as $i => $contact ) {
			$this->assertSame( $contact['name'], Csv_Exporter::unprotect_cell( $rows[ $i ]['fields'][0] ) );
			$this->assertSame( $contact['telephone'], Csv_Exporter::unprotect_cell( $rows[ $i ]['fields'][1] ) );
			$this->assertSame( $contact['notes'], Csv_Exporter::unprotect_cell( $rows[ $i ]['fields'][2] ) );
		}
	}

	public function test_unprotect_only_strips_convention_prefix() {
		$this->assertSame( "'Tis the season", Csv_Exporter::unprotect_cell( "'Tis the season" ) );
		$this->assertSame( '=1', Csv_Exporter::unprotect_cell( "'=1" ) );
		$this->assertSame( "'", Csv_Exporter::unprotect_cell( "'" ) );
	}

	public function test_template_round_trips() {
		$parsed = Csv_Parser::parse( Csv_Parser::to_utf8( Csv_Exporter::template() )['text'], 'comma' );
		$this->assertCount( 4, $parsed['records'] );
		$this->assertSame( array( 'External Contact', '+441234567890' ), $parsed['records'][3]['fields'] );
		$this->assertSame( array( 'Support Engineer', '0001' ), $parsed['records'][2]['fields'] );
	}
}
