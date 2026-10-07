<?php
/**
 * Feed access tokens.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Cryptographic per-phonebook feed tokens.
 *
 * Tokens are 40 hexadecimal characters (160 bits) so they survive URL
 * transport without encoding, work in both a query string and a path segment,
 * and are compared in constant time.
 */
final class Token {

	const LENGTH  = 40;
	const PATTERN = '/^[a-f0-9]{40}$/';

	/**
	 * Generate a new token.
	 *
	 * @return string
	 */
	public static function generate() {
		return bin2hex( random_bytes( self::LENGTH / 2 ) );
	}

	/**
	 * Normalise a user-supplied token candidate (lowercase hex only).
	 *
	 * @param mixed $candidate Raw value.
	 * @return string Empty string if unusable.
	 */
	public static function clean( $candidate ) {
		if ( ! is_string( $candidate ) ) {
			return '';
		}
		$candidate = strtolower( trim( $candidate ) );
		return preg_match( '/^[a-f0-9]{20,128}$/', $candidate ) ? $candidate : '';
	}

	/**
	 * Constant-time comparison.
	 *
	 * @param string $expected Stored token.
	 * @param string $provided Provided token.
	 * @return bool
	 */
	public static function matches( $expected, $provided ) {
		if ( ! is_string( $expected ) || ! is_string( $provided ) || '' === $expected || '' === $provided ) {
			return false;
		}
		return hash_equals( $expected, $provided );
	}

	/**
	 * Mask a token for lists, logs and diagnostics.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function mask( $token ) {
		$token = (string) $token;
		if ( strlen( $token ) < 8 ) {
			return str_repeat( '•', 8 );
		}
		return substr( $token, 0, 4 ) . str_repeat( '•', 12 ) . substr( $token, -4 );
	}
}
