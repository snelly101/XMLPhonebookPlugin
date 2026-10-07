<?php
/**
 * CSV parser tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SitePhonebooks\Csv\Csv_Parser;

class CsvParserTest extends TestCase {

	public function test_strips_utf8_bom_and_normalises_line_endings() {
		$r = Csv_Parser::to_utf8( "\xEF\xBB\xBFName,Telephone\r\nA,1\rB,2\n" );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( "Name,Telephone\nA,1\nB,2\n", $r['text'] );
		$this->assertSame( 'utf-8-bom', $r['detected'] );
	}

	public function test_rejects_non_utf8_with_guidance() {
		$r = Csv_Parser::to_utf8( "Name,Telephone\nCaf\xE9,1\n" );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'UTF-8', $r['error'] );
	}

	public function test_explicit_legacy_encoding_conversion() {
		$r = Csv_Parser::to_utf8( "Name,Telephone\nCaf\xE9,1\n", Csv_Parser::ENCODING_CP1252 );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'Café', $r['text'] );
	}

	public function test_utf16_bom_is_converted() {
		$text = mb_convert_encoding( "Name,Telephone\nZoë,1\n", 'UTF-16LE', 'UTF-8' );
		$r    = Csv_Parser::to_utf8( "\xFF\xFE" . $text );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( "Name,Telephone\nZoë,1\n", $r['text'] );
	}

	public function test_detects_delimiters() {
		$this->assertSame( 'comma', Csv_Parser::detect_delimiter( "Name,Telephone\nA,1\n" ) );
		$this->assertSame( 'semicolon', Csv_Parser::detect_delimiter( "Name;Telephone\nA;1\nB;2\n" ) );
		$this->assertSame( 'tab', Csv_Parser::detect_delimiter( "Name\tTelephone\nA\t1\n" ) );
		$this->assertSame( 'semicolon', Csv_Parser::detect_delimiter( "\"Smith, John\";1\n\"Doe, Jane\";2\n" ), 'commas inside quotes are ignored' );
		$this->assertSame( 'comma', Csv_Parser::detect_delimiter( "Name\nA\n" ), 'default to comma' );
	}

	public function test_parses_quoted_commas_and_blank_rows() {
		$r = Csv_Parser::parse( "Name,Telephone\n\"Smith, John\",\"0001\"\n\n  ,  \n\"Say \"\"hi\"\"\",+44\n", 'comma' );
		$this->assertTrue( $r['ok'] );
		$this->assertCount( 3, $r['records'] );
		$this->assertSame( array( 'Smith, John', '0001' ), $r['records'][1]['fields'] );
		$this->assertSame( 2, $r['records'][1]['row'] );
		$this->assertSame( array( 'Say "hi"', '+44' ), $r['records'][2]['fields'] );
		$this->assertSame( 5, $r['records'][2]['row'], 'row numbers count physical records including blanks' );
	}

	public function test_backslash_is_literal() {
		$r = Csv_Parser::parse( "A\\B,1\n", 'comma' );
		$this->assertSame( 'A\\B', $r['records'][0]['fields'][0] );
	}

	public function test_enforces_row_limit() {
		$r = Csv_Parser::parse( "a,1\nb,2\nc,3\n", 'comma', 2 );
		$this->assertFalse( $r['ok'] );
		$this->assertTrue( $r['truncated'] );
		$this->assertStringContainsString( 'more than 2', $r['error'] );
	}

	public function test_guesses_mapping_from_headers() {
		$g = Csv_Parser::guess_mapping( array( 'Extension', 'Display Name', 'Comments' ) );
		$this->assertSame( 1, $g['name'] );
		$this->assertSame( 0, $g['telephone'] );
		$this->assertSame( 2, $g['notes'] );

		$g = Csv_Parser::guess_mapping( array( 'Foo', 'Bar' ) );
		$this->assertSame( 0, $g['name'] );
		$this->assertSame( 1, $g['telephone'] );
	}

	public function test_detects_header_row() {
		$this->assertTrue( Csv_Parser::looks_like_header( array( 'Name', 'Telephone' ) ) );
		$this->assertFalse( Csv_Parser::looks_like_header( array( 'Reception', '1001' ) ) );
		$this->assertFalse( Csv_Parser::looks_like_header( array( 'Reception', '+44 1234' ) ) );
	}
}
