<?php
/**
 * Contact value normalisation and validation.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Pure (WordPress-independent apart from translation) validation helpers.
 *
 * Normalisation policy (documented in the user guide):
 * - Names: surrounding whitespace trimmed, internal whitespace runs collapsed to one space.
 * - Telephones: surrounding whitespace trimmed only. No prefix rewriting, no
 *   stripping of leading zeros, no numbering-plan rules.
 * - Duplicate detection: exact, case-sensitive match of normalised name AND
 *   normalised telephone.
 */
final class Contact_Validator {

	const NAME_MAX_LENGTH      = 190;
	const TELEPHONE_MAX_LENGTH = 64;
	const NOTES_MAX_LENGTH     = 2000;

	/**
	 * Characters that are not allowed anywhere in XML 1.0 text.
	 * Allowed: #x9 | #xA | #xD | [#x20-#xD7FF] | [#xE000-#xFFFD] | [#x10000-#x10FFFF].
	 */
	const XML_INVALID_PATTERN = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

	/**
	 * Normalise a display name.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function normalize_name( $name ) {
		$name = (string) $name;
		$name = str_replace( "\xEF\xBB\xBF", '', $name ); // Stray BOM.
		$name = preg_replace( '/[\s\x{00A0}]+/u', ' ', $name );
		if ( null === $name ) {
			return '';
		}
		return trim( $name );
	}

	/**
	 * Normalise a telephone string: trim only.
	 *
	 * @param string $telephone Raw telephone.
	 * @return string
	 */
	public static function normalize_telephone( $telephone ) {
		$telephone = (string) $telephone;
		$telephone = str_replace( "\xEF\xBB\xBF", '', $telephone );
		return trim( $telephone, " \t\n\r\0\x0B\xC2\xA0" );
	}

	/**
	 * Normalise notes (trim, bounded length).
	 *
	 * @param string $notes Raw notes.
	 * @return string
	 */
	public static function normalize_notes( $notes ) {
		$notes = trim( (string) $notes );
		$notes = str_replace( array( "\r\n", "\r" ), "\n", $notes );
		return $notes;
	}

	/**
	 * Deterministic duplicate-detection key.
	 *
	 * @param string $name      Normalised name.
	 * @param string $telephone Normalised telephone.
	 * @return string 40-char sha1 hex.
	 */
	public static function dedupe_hash( $name, $telephone ) {
		return sha1( $name . "\n" . $telephone );
	}

	/**
	 * Validate a contact. Returns normalised values plus error/warning lists.
	 *
	 * @param string $name      Raw name.
	 * @param string $telephone Raw telephone.
	 * @param string $notes     Raw notes.
	 * @return array{name:string,telephone:string,notes:string,errors:string[],warnings:string[],valid:bool,hash:string}
	 */
	public static function validate( $name, $telephone, $notes = '' ) {
		$errors   = array();
		$warnings = array();

		$name      = self::normalize_name( $name );
		$telephone = self::normalize_telephone( $telephone );
		$notes     = self::normalize_notes( $notes );

		if ( ! self::is_utf8( $name ) || ! self::is_utf8( $telephone ) || ! self::is_utf8( $notes ) ) {
			$errors[] = __( 'Contains text that is not valid UTF-8. Save the file as UTF-8 and try again.', 'site-phonebooks' );
			return self::result( $name, $telephone, $notes, $errors, $warnings );
		}

		if ( '' === $name ) {
			$errors[] = __( 'Name is required.', 'site-phonebooks' );
		} elseif ( mb_strlen( $name, 'UTF-8' ) > self::NAME_MAX_LENGTH ) {
			/* translators: %d: maximum length */
			$errors[] = sprintf( __( 'Name is longer than %d characters.', 'site-phonebooks' ), self::NAME_MAX_LENGTH );
		} elseif ( preg_match( self::XML_INVALID_PATTERN, $name ) ) {
			$errors[] = __( 'Name contains control characters that cannot be represented in XML. Remove them and try again.', 'site-phonebooks' );
		}

		if ( '' === $telephone ) {
			$errors[] = __( 'Telephone is required.', 'site-phonebooks' );
		} elseif ( mb_strlen( $telephone, 'UTF-8' ) > self::TELEPHONE_MAX_LENGTH ) {
			/* translators: %d: maximum length */
			$errors[] = sprintf( __( 'Telephone is longer than %d characters.', 'site-phonebooks' ), self::TELEPHONE_MAX_LENGTH );
		} elseif ( preg_match( '/[\x00-\x1F\x7F]/', $telephone ) || preg_match( self::XML_INVALID_PATTERN, $telephone ) ) {
			$errors[] = __( 'Telephone contains control characters or line breaks that cannot be represented in XML.', 'site-phonebooks' );
		} elseif ( ! preg_match( '/^[0-9+*#()\-. ]+$/', $telephone ) ) {
			$warnings[] = __( 'Telephone contains characters other than digits, +, *, #, brackets, dashes, dots and spaces. The handset may not be able to dial it.', 'site-phonebooks' );
		}

		if ( mb_strlen( $notes, 'UTF-8' ) > self::NOTES_MAX_LENGTH ) {
			/* translators: %d: maximum length */
			$errors[] = sprintf( __( 'Notes are longer than %d characters.', 'site-phonebooks' ), self::NOTES_MAX_LENGTH );
		} elseif ( preg_match( self::XML_INVALID_PATTERN, $notes ) ) {
			$errors[] = __( 'Notes contain control characters.', 'site-phonebooks' );
		}

		return self::result( $name, $telephone, $notes, $errors, $warnings );
	}

	/**
	 * Build the result array.
	 *
	 * @param string   $name      Name.
	 * @param string   $telephone Telephone.
	 * @param string   $notes     Notes.
	 * @param string[] $errors    Errors.
	 * @param string[] $warnings  Warnings.
	 * @return array
	 */
	private static function result( $name, $telephone, $notes, $errors, $warnings ) {
		return array(
			'name'      => $name,
			'telephone' => $telephone,
			'notes'     => $notes,
			'errors'    => $errors,
			'warnings'  => $warnings,
			'valid'     => empty( $errors ),
			'hash'      => self::dedupe_hash( $name, $telephone ),
		);
	}

	/**
	 * UTF-8 check.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_utf8( $value ) {
		return '' === $value || 1 === preg_match( '//u', $value );
	}
}
