<?php
/**
 * Feed delivery, access control and caching tests.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Tests\Integration;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Feed\Feed_Cache;
use SitePhonebooks\Feed\Feed_Router;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Token;

class FeedTest extends TestCase {

	public function test_protected_feed_requires_valid_token() {
		$a = $this->create_phonebook( 'Secure' );
		$this->add_contact( $a->id, 'Secret Person', '0001' );

		$no_key = $this->feed( 'secure' );
		$this->assertSame( 403, $no_key->status );
		$this->assertStringNotContainsString( 'Secret', $no_key->body );
		$this->assertStringNotContainsString( '0001', $no_key->body );
		$this->assertStringStartsWith( '<?xml', $no_key->body );

		$wrong = $this->feed( 'secure', Token::generate() );
		$this->assertSame( 403, $wrong->status );
		$this->assertSame( 403, $this->feed( 'secure.xml', 'not-hex' )->status );
		$this->assertSame( 403, $this->feed( 'secure', substr( $a->access_token, 0, 39 ) )->status );

		$ok = $this->feed( 'secure.xml', $a->access_token );
		$this->assertSame( 200, $ok->status );
		$this->assertSame( 'application/xml; charset=utf-8', $ok->headers['Content-Type'] );
		$this->assertSame( 'inline; filename="secure.xml"', $ok->headers['Content-Disposition'] );
		$this->assertStringContainsString( 'private', $ok->headers['Cache-Control'] );
		$this->assertSame( 'noindex, nofollow', $ok->headers['X-Robots-Tag'] );
		$this->assertSame( (string) strlen( $ok->body ), $ok->headers['Content-Length'] );
		$this->assertStringContainsString( '<Name>Secret Person</Name>', $ok->body );
	}

	public function test_tokens_are_scoped_to_one_phonebook() {
		$a = $this->create_phonebook( 'A' );
		$b = $this->create_phonebook( 'B' );
		$this->assertSame( 403, $this->feed( 'a', $b->access_token )->status );
		$this->assertSame( 403, $this->feed( 'b', $a->access_token )->status );
		$this->assertSame( 200, $this->feed( 'a', $a->access_token )->status );
	}

	public function test_public_mode_and_missing_or_disabled_feeds() {
		$a = $this->create_phonebook( 'Open', array( 'access_mode' => 'public' ) );
		$this->add_contact( $a->id, 'Anyone', '1' );
		$r = $this->feed( 'open' );
		$this->assertSame( 200, $r->status );
		$this->assertStringContainsString( 'public', $r->headers['Cache-Control'] );

		$this->assertSame( 404, $this->feed( 'does-not-exist' )->status );
		$this->assertSame( 404, $this->feed( '../../etc/passwd' )->status );
		$this->assertSame( 404, $this->feed( '' )->status );

		Phonebook_Repository::set_enabled( $a->id, false );
		$disabled = $this->feed( 'open' );
		$this->assertSame( 404, $disabled->status );
		$this->assertStringNotContainsString( 'Anyone', $disabled->body );

		Phonebook_Repository::set_enabled( $a->id, true );
		$this->assertSame( 200, $this->feed( 'open' )->status );
		Phonebook_Repository::set_access_mode( $a->id, 'token' );
		$this->assertSame( 403, $this->feed( 'open' )->status, 'switching to token mode protects a previously public feed' );
	}

	public function test_conditional_requests_never_bypass_authentication() {
		$a    = $this->create_phonebook( 'Cond' );
		$this->add_contact( $a->id, 'X', '1' );
		$ok   = $this->feed( 'cond', $a->access_token );
		$etag = $ok->headers['ETag'];
		$this->assertNotEmpty( $etag );
		$this->assertNotEmpty( $ok->headers['Last-Modified'] );

		$not_modified = $this->feed( 'cond', $a->access_token, array( 'if_none_match' => $etag ) );
		$this->assertSame( 304, $not_modified->status );
		$this->assertSame( '', $not_modified->body );
		$this->assertSame( $etag, $not_modified->headers['ETag'] );

		$weak = $this->feed( 'cond', $a->access_token, array( 'if_none_match' => 'W/' . $etag ) );
		$this->assertSame( 304, $weak->status );

		$ims = $this->feed( 'cond', $a->access_token, array( 'if_modified_since' => $ok->headers['Last-Modified'] ) );
		$this->assertSame( 304, $ims->status );

		$this->assertSame( 403, $this->feed( 'cond', null, array( 'if_none_match' => $etag ) )->status, 'no 304 without a token' );
		$this->assertSame( 403, $this->feed( 'cond', Token::generate(), array( 'if_none_match' => $etag ) )->status );
		$this->assertSame( 404, $this->feed( 'nope', $a->access_token, array( 'if_none_match' => $etag ) )->status );
	}

	public function test_content_changes_invalidate_cached_output_and_validators() {
		$a   = $this->create_phonebook( 'Cache' );
		$id  = $this->add_contact( $a->id, 'Before', '1' );
		$one = $this->feed( 'cache', $a->access_token );
		$this->assertNotNull( Feed_Cache::get( $a->id, Phonebook_Repository::get( $a->id )->revision ), 'rendered XML is cached' );

		Contact_Repository::update( $id, $a->id, array( 'name' => 'After' ) );
		$two = $this->feed( 'cache', $a->access_token );
		$this->assertNotSame( $one->headers['ETag'], $two->headers['ETag'] );
		$this->assertStringContainsString( '<Name>After</Name>', $two->body );
		$this->assertStringNotContainsString( 'Before', $two->body );
		$this->assertSame( 200, $this->feed( 'cache', $a->access_token, array( 'if_none_match' => $one->headers['ETag'] ) )->status, 'stale validator gets fresh content' );

		Contact_Repository::delete_many( $a->id, array( $id ) );
		$three = $this->feed( 'cache', $a->access_token );
		$this->assertSame( array(), $this->names_in_xml( $three->body ) );
		$this->assertNotSame( $two->headers['ETag'], $three->headers['ETag'] );
	}

	public function test_rotation_and_disablement_take_effect_despite_cache() {
		$a = $this->create_phonebook( 'Rotate' );
		$this->add_contact( $a->id, 'X', '1' );
		$old = $a->access_token;
		$this->assertSame( 200, $this->feed( 'rotate', $old )->status );
		$this->assertNotNull( Feed_Cache::get( $a->id, Phonebook_Repository::get( $a->id )->revision ) );

		$new = Phonebook_Repository::rotate_token( $a->id );
		$this->assertMatchesRegularExpression( Token::PATTERN, $new );
		$this->assertNotSame( $old, $new );
		$this->assertSame( 403, $this->feed( 'rotate', $old )->status );
		$this->assertSame( 403, $this->feed( 'rotate', $old, array( 'if_none_match' => '*' ) )->status );
		$this->assertSame( 200, $this->feed( 'rotate', $new )->status );

		Phonebook_Repository::set_enabled( $a->id, false );
		$this->assertSame( 404, $this->feed( 'rotate', $new, array( 'if_none_match' => '*' ) )->status );
	}

	public function test_head_and_other_methods() {
		$a    = $this->create_phonebook( 'Head' );
		$head = $this->feed( 'head', $a->access_token, array( 'method' => 'HEAD' ) );
		$this->assertSame( 200, $head->status );
		$this->assertSame( '', $head->body );
		$this->assertGreaterThan( 0, (int) $head->headers['Content-Length'] );
		$post = $this->feed( 'head', $a->access_token, array( 'method' => 'POST' ) );
		$this->assertSame( 405, $post->status );
	}

	public function test_request_logging_records_status_and_agent_only() {
		$a = $this->create_phonebook( 'Log' );
		Phonebook_Repository::record_request( $a->id, 403, "Yealink SIP-T46S 66.85.0.5 \x00evil" );
		$pb = Phonebook_Repository::get( $a->id );
		$this->assertSame( 403, $pb->last_request_status );
		$this->assertSame( 'Yealink SIP-T46S 66.85.0.5 evil', $pb->last_request_agent );
		$this->assertNotEmpty( $pb->last_request_at );
	}

	public function test_urls_for_pretty_plain_and_subdirectory_installs() {
		$a = $this->create_phonebook( 'Frickley Mews' );
		update_option( 'permalink_structure', '' );
		$urls = Feed_Router::urls( $a );
		$this->assertSame( 'http://example.org/?spb_feed=frickley-mews.xml&key=' . $a->access_token, $urls['fallback'] );
		$this->assertSame( $urls['fallback'], $urls['primary'], 'plain permalinks use the fallback endpoint' );

		update_option( 'permalink_structure', '/%postname%/' );
		update_option( 'home', 'https://example.org/blog' );
		$urls = Feed_Router::urls( Phonebook_Repository::get( $a->id ) );
		$this->assertSame( 'https://example.org/blog/phonebooks/frickley-mews.xml?key=' . $a->access_token, $urls['pretty'] );
		$this->assertSame( 'https://example.org/blog/phonebooks/' . $a->access_token . '/frickley-mews.xml', $urls['pretty_path_token'] );
		$this->assertSame( $urls['pretty'], $urls['primary'] );
		$this->assertSame( 'https://example.org/blog/phonebooks/frickley-mews.xml', $urls['public_pretty'] );

		Phonebook_Repository::set_access_mode( $a->id, 'public' );
		$urls = Feed_Router::urls( Phonebook_Repository::get( $a->id ) );
		$this->assertSame( 'https://example.org/blog/phonebooks/frickley-mews.xml', $urls['primary'] );
		$this->assertStringNotContainsString( $a->access_token, implode( ' ', $urls ) );
		update_option( 'home', 'http://example.org' );
		update_option( 'permalink_structure', '' );
	}

	public function test_rewrite_routing_dispatches_to_controller() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		( new Feed_Router() )->add_rewrite_rules();
		$wp_rewrite->flush_rules();

		$a = $this->create_phonebook( 'Routed' );
		$this->add_contact( $a->id, 'Routed Person', '1' );
		$captured = null;
		$observer = static function ( $response ) use ( &$captured ) {
			$captured = $response;
			throw new \RuntimeException( 'intercepted' );
		};
		add_action( 'spb_feed_response', $observer );

		foreach ( array(
			'http://example.org/phonebooks/routed.xml?key=' . $a->access_token   => 200,
			'http://example.org/phonebooks/' . $a->access_token . '/routed.xml'    => 200,
			'http://example.org/phonebooks/routed.xml'                             => 403,
			'http://example.org/phonebooks/routed.xml?key=' . Token::generate()    => 403,
			'http://example.org/phonebooks/missing.xml'                            => 404,
			'http://example.org/?spb_feed=routed.xml&key=' . $a->access_token      => 200,
			'http://example.org/index.php?spb_feed=routed&key=' . $a->access_token => 200,
		) as $path => $expected ) {
			$captured = null;
			try {
				$this->go_to( $path );
				$this->fail( 'feed request was not intercepted for ' . $path );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'intercepted', $e->getMessage() );
			}
			$this->assertSame( $expected, $captured->status, $path );
			if ( 200 === $expected ) {
				$this->assertStringContainsString( 'Routed Person', $captured->body );
			}
		}
		remove_action( 'spb_feed_response', $observer );
		$this->assertSame( 200, Phonebook_Repository::get( $a->id )->last_request_status );

		$captured = null;
		$this->go_to( 'http://example.org/' );
		$this->assertNull( $captured, 'ordinary requests are untouched' );
		$this->go_to( 'http://example.org/?spb_key=' . $a->access_token );
		$this->assertNull( $captured, 'a key without a feed does nothing' );
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();
		unset( $_GET['key'] );
	}
}
