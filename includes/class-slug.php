<?php
/**
 * Slug generation for feed filenames.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Produces lowercase ASCII slugs ([a-z0-9-]) suitable for a stable `.xml`
 * filename that handsets can request without encoding concerns.
 */
final class Slug {

	const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
	const MAX_LENGTH = 100;

	/**
	 * Build a slug from a display name. Non-ASCII letters are transliterated
	 * where possible (accents removed); anything else becomes a dash.
	 * Falls back to "phonebook" when nothing usable remains.
	 *
	 * @param string $name Display name or requested slug.
	 * @return string
	 */
	public static function from_name( $name ) {
		$slug = (string) $name;
		if ( function_exists( 'remove_accents' ) ) {
			$slug = remove_accents( $slug );
		}
		if ( preg_match( '/[^\x00-\x7F]/', $slug ) && class_exists( '\Transliterator' ) ) {
			// Non-Latin scripts (Cyrillic, Greek, ...) when the intl extension is available.
			$transliterator = \Transliterator::create( 'Any-Latin; Latin-ASCII' );
			if ( $transliterator ) {
				$converted = $transliterator->transliterate( $slug );
				if ( false !== $converted ) {
					$slug = $converted;
				}
			}
		}
		if ( preg_match( '/[^\x00-\x7F]/', $slug ) && function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $converted ) {
				$slug = $converted;
			}
		}
		$slug = strtolower( $slug );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );
		if ( strlen( $slug ) > self::MAX_LENGTH ) {
			$slug = rtrim( substr( $slug, 0, self::MAX_LENGTH ), '-' );
		}
		if ( '' === $slug ) {
			$slug = 'phonebook';
		}
		return $slug;
	}

	/**
	 * Whether a string is already a valid slug.
	 *
	 * @param string $slug Candidate.
	 * @return bool
	 */
	public static function is_valid( $slug ) {
		return is_string( $slug ) && strlen( $slug ) <= self::MAX_LENGTH && 1 === preg_match( self::PATTERN, $slug );
	}

	/**
	 * Find a unique slug by appending -2, -3, ... when needed.
	 *
	 * @param string   $base      Base slug.
	 * @param callable $exists    function(string $slug): bool.
	 * @return string
	 */
	public static function unique( $base, callable $exists ) {
		$base = self::from_name( $base );
		if ( ! $exists( $base ) ) {
			return $base;
		}
		for ( $i = 2; $i < 10000; $i++ ) {
			$suffix    = '-' . $i;
			$candidate = substr( $base, 0, self::MAX_LENGTH - strlen( $suffix ) ) . $suffix;
			$candidate = str_replace( '--', '-', $candidate );
			if ( ! $exists( $candidate ) ) {
				return $candidate;
			}
		}
		return $base . '-' . bin2hex( random_bytes( 4 ) );
	}
}
