<?php
/**
 * Rendered feed cache.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Feed;

defined( 'ABSPATH' ) || exit;

/**
 * Caches rendered XML per phonebook in the WordPress object cache, validated
 * by phonebook revision so a stale entry can never be served. Authentication
 * always happens before the cache is consulted.
 */
final class Feed_Cache {

	const GROUP = 'spb_feed';

	/**
	 * Fetch cached XML for a phonebook at a specific revision.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @param int $revision     Expected revision.
	 * @return string|null
	 */
	public static function get( $phonebook_id, $revision ) {
		$entry = wp_cache_get( 'feed_' . (int) $phonebook_id, self::GROUP );
		if ( is_array( $entry ) && isset( $entry['revision'], $entry['xml'] ) && (int) $entry['revision'] === (int) $revision ) {
			return (string) $entry['xml'];
		}
		return null;
	}

	/**
	 * Store rendered XML.
	 *
	 * @param int    $phonebook_id Phonebook ID.
	 * @param int    $revision     Revision.
	 * @param string $xml          XML.
	 */
	public static function set( $phonebook_id, $revision, $xml ) {
		wp_cache_set(
			'feed_' . (int) $phonebook_id,
			array(
				'revision' => (int) $revision,
				'xml'      => (string) $xml,
			),
			self::GROUP,
			DAY_IN_SECONDS
		);
	}

	/**
	 * Drop the cached feed for a phonebook.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 */
	public static function delete( $phonebook_id ) {
		wp_cache_delete( 'feed_' . (int) $phonebook_id, self::GROUP );
	}
}
