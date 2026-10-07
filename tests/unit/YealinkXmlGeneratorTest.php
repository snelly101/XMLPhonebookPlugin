<?php
/**
 * XML generation tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SitePhonebooks\Xml\Yealink_Xml_Generator;
use SitePhonebooks\Xml\Yealink_Xml_Parser;

class YealinkXmlGeneratorTest extends TestCase {

	private function contacts() {
		return array(
			array( 'name' => 'Example Reception', 'telephone' => '1001' ),
			array( 'name' => 'Support Engineer', 'telephone' => '0001' ),
			array( 'name' => 'Support Engineer', 'telephone' => '1000' ),
			array( 'name' => 'Example External Contact', 'telephone' => '+441234567890' ),
		);
	}

	public function test_matches_supplied_structure_exactly() {
		$xml      = Yealink_Xml_Generator::generate( 'Phonelist', 'Prompt', $this->contacts() );
		$expected = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n"
			. "<XXXIPPhoneDirectory clearlight=\"true\">\n"
			. "  <Title>Phonelist</Title>\n"
			. "  <Prompt>Prompt</Prompt>\n"
			. "  <DirectoryEntry>\n    <Name>Example Reception</Name>\n    <Telephone>1001</Telephone>\n  </DirectoryEntry>\n"
			. "  <DirectoryEntry>\n    <Name>Support Engineer</Name>\n    <Telephone>0001</Telephone>\n  </DirectoryEntry>\n"
			. "  <DirectoryEntry>\n    <Name>Support Engineer</Name>\n    <Telephone>1000</Telephone>\n  </DirectoryEntry>\n"
			. "  <DirectoryEntry>\n    <Name>Example External Contact</Name>\n    <Telephone>+441234567890</Telephone>\n  </DirectoryEntry>\n"
			. "</XXXIPPhoneDirectory>\n";
		$this->assertSame( $expected, $xml );
		$this->assertStringStartsNotWith( "\xEF\xBB\xBF", $xml, 'No BOM' );
	}

	public function test_matches_owner_fixture_when_roundtripped() {
		$fixture = file_get_contents( dirname( __DIR__ ) . '/fixtures/yealink-sample.xml' );
		$parsed  = Yealink_Xml_Parser::parse( $fixture );
		$this->assertTrue( $parsed['ok'] );
		$xml = Yealink_Xml_Generator::generate( $parsed['title'], $parsed['prompt'], $parsed['records'] );
		$this->assertSame( str_replace( "\r\n", "\n", $fixture ), $xml );
	}

	public function test_escapes_special_characters_and_preserves_unicode() {
		$contacts = array(
			array( 'name' => 'Smith & Jones <Ltd> "Quoted" \'Apos\'', 'telephone' => '0001' ),
			array( 'name' => 'Zoë Åström Ñandú', 'telephone' => '+4412' ),
			array( 'name' => '東京 支店 — Москва', 'telephone' => '*#123' ),
		);
		$xml = Yealink_Xml_Generator::generate( 'T & T', 'Prompt', $contacts );
		$this->assertStringContainsString( '<Name>Smith &amp; Jones &lt;Ltd&gt; "Quoted" \'Apos\'</Name>', $xml );
		$this->assertStringContainsString( '<Title>T &amp; T</Title>', $xml );
		$this->assertStringContainsString( '<Name>Zoë Åström Ñandú</Name>', $xml );
		$this->assertStringContainsString( '<Name>東京 支店 — Москва</Name>', $xml );

		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ) );
		$this->assertSame( 'XXXIPPhoneDirectory', $dom->documentElement->nodeName );
		$this->assertSame( 'true', $dom->documentElement->getAttribute( 'clearlight' ) );
		$names = $dom->getElementsByTagName( 'Name' );
		$this->assertSame( 'Smith & Jones <Ltd> "Quoted" \'Apos\'', $names->item( 0 )->textContent );
		$this->assertSame( '東京 支店 — Москва', $names->item( 2 )->textContent );
	}

	public function test_preserves_telephone_strings() {
		$xml = Yealink_Xml_Generator::generate( 'T', 'Prompt', array(
			array( 'name' => 'A', 'telephone' => '0001' ),
			array( 'name' => 'B', 'telephone' => '+441234567890' ),
			array( 'name' => 'C', 'telephone' => '00441234 567 890' ),
		) );
		$this->assertStringContainsString( '<Telephone>0001</Telephone>', $xml );
		$this->assertStringContainsString( '<Telephone>+441234567890</Telephone>', $xml );
		$this->assertStringContainsString( '<Telephone>00441234 567 890</Telephone>', $xml );
	}

	public function test_rejects_control_characters() {
		$this->expectException( \InvalidArgumentException::class );
		Yealink_Xml_Generator::generate( 'T', 'Prompt', array( array( 'name' => "Bad\x01Name", 'telephone' => '1' ) ) );
	}

	public function test_rejects_invalid_utf8() {
		$this->expectException( \InvalidArgumentException::class );
		Yealink_Xml_Generator::generate( 'T', 'Prompt', array( array( 'name' => "Caf\xE9", 'telephone' => '1' ) ) );
	}

	public function test_empty_phonebook_is_well_formed() {
		$xml = Yealink_Xml_Generator::generate( 'Empty', 'Prompt', array() );
		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ) );
		$this->assertSame( 0, $dom->getElementsByTagName( 'DirectoryEntry' )->length );
	}
}
