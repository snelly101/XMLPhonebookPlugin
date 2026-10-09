<?php
/**
 * Contact validation tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SitePhonebooks\Contact_Validator;

class ContactValidatorTest extends TestCase {

	public function test_normalisation_policy() {
		$this->assertSame( 'Support Engineer', Contact_Validator::normalize_name( "  Support \t\n Engineer  " ) );
		$this->assertSame( '+44 1234  567', Contact_Validator::normalize_telephone( "  +44 1234  567 \t" ), 'telephones keep internal spacing' );
		$this->assertSame( '0001', Contact_Validator::normalize_telephone( '0001' ) );
	}

	public function test_valid_contact() {
		$v = Contact_Validator::validate( ' Zoë ', '+441234567890', ' note ' );
		$this->assertTrue( $v['valid'] );
		$this->assertSame( 'Zoë', $v['name'] );
		$this->assertSame( '+441234567890', $v['telephone'] );
		$this->assertSame( 'note', $v['notes'] );
		$this->assertSame( sha1( "Zoë\n+441234567890" ), $v['hash'] );
	}

	public function test_required_fields() {
		$v = Contact_Validator::validate( '', '' );
		$this->assertFalse( $v['valid'] );
		$this->assertCount( 2, $v['errors'] );
	}

	public function test_rejects_control_characters_and_invalid_utf8() {
		$this->assertFalse( Contact_Validator::validate( "A\x01B", '1' )['valid'] );
		$this->assertFalse( Contact_Validator::validate( 'A', "1\n2" )['valid'] );
		$this->assertFalse( Contact_Validator::validate( "Caf\xE9", '1' )['valid'] );
	}

	public function test_lengths() {
		$this->assertFalse( Contact_Validator::validate( str_repeat( 'a', 191 ), '1' )['valid'] );
		$this->assertTrue( Contact_Validator::validate( str_repeat( 'é', 190 ), '1' )['valid'], 'multibyte counted by characters' );
		$this->assertFalse( Contact_Validator::validate( 'a', str_repeat( '1', 65 ) )['valid'] );
	}

	public function test_unusual_telephone_characters_warn_only() {
		$v = Contact_Validator::validate( 'SIP', 'sip:user@example.com' );
		$this->assertTrue( $v['valid'] );
		$this->assertCount( 1, $v['warnings'] );
	}

	public function test_duplicates_are_exact_and_case_sensitive() {
		$a = Contact_Validator::validate( 'Support Engineer', '1000' );
		$b = Contact_Validator::validate( 'Support  Engineer ', ' 1000' );
		$c = Contact_Validator::validate( 'support engineer', '1000' );
		$d = Contact_Validator::validate( 'Support Engineer', '0001' );
		$this->assertSame( $a['hash'], $b['hash'] );
		$this->assertNotSame( $a['hash'], $c['hash'] );
		$this->assertNotSame( $a['hash'], $d['hash'] );
	}
}
