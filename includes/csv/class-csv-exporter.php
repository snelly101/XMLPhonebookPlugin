<?php
/**
 * CSV export with spreadsheet formula protection.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * Writes RFC 4180 CSV (UTF-8, CRLF, quoted when needed).
 *
 * Formula-injection policy (documented and round-trip tested):
 * - A cell that starts with "=", "@", a tab or a carriage return, or that
 *   starts with "+" or "-" but is NOT a plain telephone-like string, is
 *   prefixed with a single apostrophe. Spreadsheets treat an apostrophe as
 *   "this is text".
 * - Telephone-like values such as "+441234567890" or "-" never contain the
 *   characters a formula needs (letters, "|", "!", "(" with a function name),
 *   so they are written unchanged and leading zeros/plus signs survive.
 * - On import the plugin removes one leading apostrophe only when the next
 *   character is one of "=", "+", "-" or "@", which exactly reverses this
 *   convention.
 */
final class Csv_Exporter {

	const TELEPHONE_SAFE_PATTERN = '/^[+\-]?[0-9 ()\-.#*]*$/';

	/**
	 * Make a cell safe for spreadsheet software.
	 *
	 * @param string $value Cell text.
	 * @return string
	 */
	public static function protect_cell( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return $value;
		}
		$first = $value[0];
		if ( '=' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
			return "'" . $value;
		}
		if ( ( '+' === $first || '-' === $first ) && ! preg_match( self::TELEPHONE_SAFE_PATTERN, $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Reverse protect_cell() for values coming back through import.
	 *
	 * @param string $value Cell text.
	 * @return string
	 */
	public static function unprotect_cell( $value ) {
		$value = (string) $value;
		if ( strlen( $value ) >= 2 && "'" === $value[0] && false !== strpos( '=+-@', $value[1] ) ) {
			return substr( $value, 1 );
		}
		return $value;
	}

	/**
	 * Quote a cell for CSV output.
	 *
	 * @param string $value Cell text.
	 * @return string
	 */
	private static function quote( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/[",\r\n;\t]/', $value ) || ' ' === $value[0] || ' ' === substr( $value, -1 ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/**
	 * Build CSV text from contacts.
	 *
	 * @param iterable $contacts      Items with name, telephone, notes (object or array).
	 * @param bool     $include_notes Whether to include the Notes column.
	 * @return string
	 */
	public static function build( $contacts, $include_notes = true ) {
		$header = $include_notes ? array( 'Name', 'Telephone', 'Notes' ) : array( 'Name', 'Telephone' );
		$lines  = array( implode( ',', $header ) );
		foreach ( $contacts as $contact ) {
			$contact = (array) $contact;
			$cells   = array(
				self::quote( self::protect_cell( isset( $contact['name'] ) ? $contact['name'] : '' ) ),
				self::quote( self::protect_cell( isset( $contact['telephone'] ) ? $contact['telephone'] : '' ) ),
			);
			if ( $include_notes ) {
				$cells[] = self::quote( self::protect_cell( isset( $contact['notes'] ) ? $contact['notes'] : '' ) );
			}
			$lines[] = implode( ',', $cells );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * The downloadable template.
	 *
	 * @return string
	 */
	public static function template() {
		return "Name,Telephone\r\nReception,1001\r\nSupport Engineer,0001\r\nExternal Contact,+441234567890\r\n";
	}
}
