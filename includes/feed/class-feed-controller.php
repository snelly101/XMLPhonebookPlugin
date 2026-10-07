<?php
/**
 * Feed delivery logic.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Feed;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Settings;
use SitePhonebooks\Token;
use SitePhonebooks\Xml\Yealink_Xml_Generator;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a feed request to a response: lookup, authorisation, conditional
 * GET, cached or fresh rendering. Order matters: nothing is read from the
 * cache and no 304 is issued before the token has been verified.
 */
final class Feed_Controller {

	/**
	 * Handle a request.
	 *
	 * @param string      $slug    Requested slug (with or without .xml).
	 * @param string|null $key     Provided token, if any.
	 * @param array       $request {method, if_none_match, if_modified_since, user_agent}.
	 * @return Feed_Response
	 */
	public static function handle( $slug, $key, array $request = array() ) {
		$request = array_merge(
			array(
				'method'            => 'GET',
				'if_none_match'     => '',
				'if_modified_since' => '',
				'user_agent'        => '',
			),
			$request
		);
		$method = strtoupper( (string) $request['method'] );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$response                     = Feed_Response::error( 405, 'Method not allowed' );
			$response->headers['Allow']   = 'GET, HEAD';
			return $response;
		}

		$slug = self::normalize_slug( $slug );
		if ( '' === $slug ) {
			return Feed_Response::error( 404, 'Not found' );
		}
		$phonebook = Phonebook_Repository::get_by_slug( $slug );
		if ( ! $phonebook || ! $phonebook->enabled ) {
			$response = Feed_Response::error( 404, 'Not found' );
			if ( $phonebook ) {
				$response->phonebook_id = $phonebook->id;
			}
			return $response;
		}

		if ( 'public' !== $phonebook->access_mode ) {
			$provided = Token::clean( $key );
			if ( '' === $provided || ! Token::matches( $phonebook->access_token, $provided ) ) {
				$response               = Feed_Response::error( 403, 'Forbidden' );
				$response->phonebook_id = $phonebook->id;
				return $response;
			}
		}

		$etag          = self::etag( $phonebook );
		$last_modified = self::last_modified_timestamp( $phonebook );
		$headers       = self::base_headers( $phonebook, $etag, $last_modified );

		if ( self::is_not_modified( $request, $etag, $last_modified ) ) {
			$response               = new Feed_Response( 304, $headers, '' );
			$response->phonebook_id = $phonebook->id;
			return $response;
		}

		$xml = Feed_Cache::get( $phonebook->id, $phonebook->revision );
		if ( null === $xml ) {
			try {
				$xml = self::render( $phonebook );
			} catch ( \InvalidArgumentException $e ) {
				$response               = Feed_Response::error( 500, 'Feed could not be generated' );
				$response->phonebook_id = $phonebook->id;
				return $response;
			}
			Feed_Cache::set( $phonebook->id, $phonebook->revision, $xml );
		}

		$headers['Content-Length'] = (string) strlen( $xml );
		$response                  = new Feed_Response( 200, $headers, 'HEAD' === $method ? '' : $xml );
		$response->phonebook_id    = $phonebook->id;
		return $response;
	}

	/**
	 * Render the XML for a phonebook (uncached).
	 *
	 * @param object $phonebook Phonebook row.
	 * @return string
	 */
	public static function render( $phonebook ) {
		$contacts = Contact_Repository::all( $phonebook->id );
		return Yealink_Xml_Generator::generate( $phonebook->name, $phonebook->prompt, $contacts );
	}

	/**
	 * Strip a trailing .xml and validate characters.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function normalize_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( '.xml' === substr( $slug, -4 ) ) {
			$slug = substr( $slug, 0, -4 );
		}
		return \SitePhonebooks\Slug::is_valid( $slug ) ? $slug : '';
	}

	/**
	 * Strong validator derived from the phonebook identity and revision.
	 *
	 * @param object $phonebook Phonebook row.
	 * @return string
	 */
	public static function etag( $phonebook ) {
		return '"' . substr( sha1( 'spb|' . $phonebook->id . '|' . $phonebook->revision . '|' . SPB_VERSION . '|' . Yealink_Xml_Generator::PROFILE_ID ), 0, 32 ) . '"';
	}

	/**
	 * Last-Modified timestamp.
	 *
	 * @param object $phonebook Phonebook row.
	 * @return int Unix timestamp.
	 */
	public static function last_modified_timestamp( $phonebook ) {
		$ts = ! empty( $phonebook->content_updated_at ) ? strtotime( $phonebook->content_updated_at . ' UTC' ) : false;
		return $ts ? (int) $ts : time();
	}

	/**
	 * Headers common to 200 and 304.
	 *
	 * @param object $phonebook     Phonebook row.
	 * @param string $etag          ETag.
	 * @param int    $last_modified Timestamp.
	 * @return array<string,string>
	 */
	private static function base_headers( $phonebook, $etag, $last_modified ) {
		$max_age = (int) Settings::get( 'feed_max_age' );
		if ( 'public' === $phonebook->access_mode ) {
			$cache_control = 'public, max-age=' . $max_age . ', must-revalidate';
		} else {
			// Private: shared caches (CDNs, page caches) must not store a token-protected feed.
			$cache_control = 'private, no-cache, max-age=' . $max_age . ', must-revalidate';
		}
		return array(
			'Content-Type'           => 'application/xml; charset=utf-8',
			'Content-Disposition'    => 'inline; filename="' . $phonebook->slug . '.xml"',
			'Cache-Control'          => $cache_control,
			'ETag'                   => $etag,
			'Last-Modified'          => gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT',
			'Vary'                   => 'Accept-Encoding',
			'X-Robots-Tag'           => 'noindex, nofollow',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Evaluate conditional request headers.
	 *
	 * @param array  $request       Request data.
	 * @param string $etag          Current ETag.
	 * @param int    $last_modified Current Last-Modified timestamp.
	 * @return bool
	 */
	private static function is_not_modified( array $request, $etag, $last_modified ) {
		$inm = trim( (string) $request['if_none_match'] );
		if ( '' !== $inm ) {
			foreach ( explode( ',', $inm ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( 0 === strpos( $candidate, 'W/' ) ) {
					$candidate = substr( $candidate, 2 );
				}
				if ( '*' === $candidate || $candidate === $etag ) {
					return true;
				}
			}
			return false;
		}
		$ims = trim( (string) $request['if_modified_since'] );
		if ( '' !== $ims ) {
			$since = strtotime( $ims );
			if ( false !== $since && $since >= $last_modified ) {
				return true;
			}
		}
		return false;
	}
}
