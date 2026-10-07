<?php
/**
 * Slug and token helper tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SitePhonebooks\Slug;
use SitePhonebooks\Token;

class SlugAndTokenTest extends TestCase {

	public function test_slug_generation() {
		$this->assertSame( 'frickley-mews', Slug::from_name( 'Frickley Mews' ) );
		$this->assertSame( 'potteries-court', Slug::from_name( '  Potteries   Court! ' ) );
		$this->assertSame( 'cafe-du-nord', Slug::from_name( 'Café du Nord' ) );
		if ( class_exists( '\\Transliterator' ) ) {
			$this->assertSame( 'dong-jing', Slug::from_name( '東京' ), 'intl transliterates non-Latin scripts' );
		} else {
			$this->assertSame( 'phonebook', Slug::from_name( '東京' ), 'non-transliterable names fall back' );
		}
		$this->assertSame( 'phonebook', Slug::from_name( '!!! ???' ) );
		$this->assertSame( 'phonebook', Slug::from_name( '' ) );
		$this->assertTrue( Slug::is_valid( 'a-b-1' ) );
		$this->assertFalse( Slug::is_valid( '-a' ) );
		$this->assertFalse( Slug::is_valid( 'A' ) );
		$this->assertFalse( Slug::is_valid( 'a--b' ) );
	}

	public function test_slug_collisions_get_numeric_suffix() {
		$taken  = array( 'frickley-mews' => true, 'frickley-mews-2' => true );
		$exists = static function ( $slug ) use ( $taken ) {
			return isset( $taken[ $slug ] );
		};
		$this->assertSame( 'frickley-mews-3', Slug::unique( 'Frickley Mews', $exists ) );
		$this->assertSame( 'frickley-mews-3', Slug::unique( 'frickley mews!!', $exists ), 'names that sanitise identically still get unique slugs' );
		$this->assertSame( 'other', Slug::unique( 'Other', $exists ) );
	}

	public function test_tokens() {
		$a = Token::generate();
		$b = Token::generate();
		$this->assertMatchesRegularExpression( Token::PATTERN, $a );
		$this->assertNotSame( $a, $b );
		$this->assertTrue( Token::matches( $a, $a ) );
		$this->assertFalse( Token::matches( $a, $b ) );
		$this->assertFalse( Token::matches( $a, '' ) );
		$this->assertFalse( Token::matches( '', '' ) );
		$this->assertSame( $a, Token::clean( ' ' . strtoupper( $a ) . ' ' ) );
		$this->assertSame( '', Token::clean( 'not-hex!' ) );
		$this->assertSame( '', Token::clean( array( $a ) ) );
		$this->assertSame( substr( $a, 0, 4 ) . '••••••••••••' . substr( $a, -4 ), Token::mask( $a ) );
		$this->assertStringNotContainsString( substr( $a, 4, 32 ), Token::mask( $a ) );
	}
}
