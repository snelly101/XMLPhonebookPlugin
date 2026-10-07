<?php
/**
 * XML import parser tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SitePhonebooks\Xml\Yealink_Xml_Parser;

class YealinkXmlParserTest extends TestCase {

	public function test_parses_working_format() {
		$xml    = file_get_contents( dirname( __DIR__ ) . '/fixtures/yealink-sample.xml' );
		$result = Yealink_Xml_Parser::parse( $xml );
		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertSame( 'Phonelist', $result['title'] );
		$this->assertSame( 'Prompt', $result['prompt'] );
		$this->assertCount( 4, $result['records'] );
		$this->assertSame( '0001', $result['records'][1]['telephone'] );
		$this->assertSame( '+441234567890', $result['records'][3]['telephone'] );
		$this->assertSame( 3, $result['records'][2]['row'] );
	}

	public function test_accepts_bom_and_crlf() {
		$xml    = "\xEF\xBB\xBF" . str_replace( "\n", "\r\n", file_get_contents( dirname( __DIR__ ) . '/fixtures/yealink-sample.xml' ) );
		$result = Yealink_Xml_Parser::parse( $xml );
		$this->assertTrue( $result['ok'], $result['error'] );
		$this->assertCount( 4, $result['records'] );
	}

	public function test_rejects_doctype_and_entities() {
		$xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><XXXIPPhoneDirectory><DirectoryEntry><Name>&xxe;</Name><Telephone>1</Telephone></DirectoryEntry></XXXIPPhoneDirectory>';
		$result = Yealink_Xml_Parser::parse( $xml );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'not allowed', $result['error'] );
	}

	public function test_rejects_external_doctype_case_insensitively() {
		$xml    = '<?xml version="1.0"?><!doctype XXXIPPhoneDirectory SYSTEM "http://example.com/evil.dtd"><XXXIPPhoneDirectory></XXXIPPhoneDirectory>';
		$result = Yealink_Xml_Parser::parse( $xml );
		$this->assertFalse( $result['ok'] );
	}

	public function test_rejects_malformed() {
		$result = Yealink_Xml_Parser::parse( '<XXXIPPhoneDirectory><DirectoryEntry><Name>Unclosed' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'malformed', $result['error'] );
	}

	public function test_rejects_wrong_root() {
		$result = Yealink_Xml_Parser::parse( '<?xml version="1.0"?><AddressBook><Contact/></AddressBook>' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'AddressBook', $result['error'] );
	}

	public function test_rejects_empty_and_binary() {
		$this->assertFalse( Yealink_Xml_Parser::parse( '' )['ok'] );
		$this->assertFalse( Yealink_Xml_Parser::parse( "\x00\x01\x02" )['ok'] );
		$this->assertFalse( Yealink_Xml_Parser::parse( "<XXXIPPhoneDirectory><Title>\xE9</Title></XXXIPPhoneDirectory>" )['ok'] );
	}

	public function test_rejects_oversized_before_parsing() {
		$entries = str_repeat( '<DirectoryEntry><Name>A</Name><Telephone>1</Telephone></DirectoryEntry>', 11 );
		$result  = Yealink_Xml_Parser::parse( '<XXXIPPhoneDirectory>' . $entries . '</XXXIPPhoneDirectory>', 10 );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'more than 10', $result['error'] );
	}

	public function test_warns_on_unexpected_elements_and_extra_fields() {
		$xml    = '<XXXIPPhoneDirectory clearlight="true"><Title>T</Title><Menu/><DirectoryEntry><Name>A</Name><Telephone>1</Telephone><Telephone>2</Telephone><Mobile>3</Mobile></DirectoryEntry></XXXIPPhoneDirectory>';
		$result = Yealink_Xml_Parser::parse( $xml );
		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $result['records'] );
		$this->assertSame( '1', $result['records'][0]['telephone'] );
		$this->assertCount( 3, $result['warnings'] );
	}

	public function test_decodes_entities_and_cdata_as_text() {
		$xml    = '<XXXIPPhoneDirectory><DirectoryEntry><Name>A &amp; B &lt;C&gt;</Name><Telephone><![CDATA[+44 (0) 1]]></Telephone></DirectoryEntry></XXXIPPhoneDirectory>';
		$result = Yealink_Xml_Parser::parse( $xml );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'A & B <C>', $result['records'][0]['name'] );
		$this->assertSame( '+44 (0) 1', $result['records'][0]['telephone'] );
	}
}
