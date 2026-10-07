<?php
/**
 * CSV parsing with encoding and delimiter handling.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Csv;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps PHP's native CSV parser (fgetcsv) with BOM handling, encoding checks,
 * line-ending normalisation, delimiter detection and row limits.
 *
 * The parser never guesses an 8-bit encoding: a file that is not valid UTF-8
 * (and has no UTF-16 byte order mark) is reported so the user can either
 * re-save it as UTF-8 or explicitly choose a legacy encoding.
 */
final class Csv_Parser {

	const ENCODING_UTF8   = 'utf-8';
	const ENCODING_CP1252 = 'windows-1252';
	const ENCODING_LATIN1 = 'iso-8859-1';

	/**
	 * Supported delimiters keyed by identifier.
	 *
	 * @return array<string,string>
	 */
	public static function delimiters() {
		return array(
			'comma'     => ',',
			'semicolon' => ';',
			'tab'       => "\t",
		);
	}

	/**
	 * Supported explicit encodings.
	 *
	 * @return string[]
	 */
	public static function encodings() {
		return array( self::ENCODING_UTF8, self::ENCODING_CP1252, self::ENCODING_LATIN1 );
	}

	/**
	 * Convert raw bytes to clean UTF-8 text with "\n" line endings.
	 *
	 * @param string $raw      File bytes.
	 * @param string $encoding One of encodings(); 'utf-8' also auto-detects UTF-16 BOMs.
	 * @return array{ok:bool,text:string,error:string,detected:string}
	 */
	public static function to_utf8( $raw, $encoding = self::ENCODING_UTF8 ) {
		$raw      = (string) $raw;
		$detected = $encoding;

		if ( self::ENCODING_UTF8 === $encoding ) {
			if ( "\xFF\xFE" === substr( $raw, 0, 2 ) ) {
				$raw      = (string) mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16LE' );
				$detected = 'utf-16le';
			} elseif ( "\xFE\xFF" === substr( $raw, 0, 2 ) ) {
				$raw      = (string) mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
				$detected = 'utf-16be';
			} elseif ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
				$raw      = substr( $raw, 3 );
				$detected = 'utf-8-bom';
			}
			if ( ! preg_match( '//u', $raw ) ) {
				return array(
					'ok'       => false,
					'text'     => '',
					'detected' => 'unknown',
					'error'    => __( 'The file is not valid UTF-8 text. In your spreadsheet choose "Save As" and pick "CSV UTF-8 (Comma delimited)", or select the legacy encoding the file was saved in below. Importing it as-is would corrupt accented names.', 'site-phonebooks' ),
				);
			}
		} else {
			$from = self::ENCODING_CP1252 === $encoding ? 'Windows-1252' : 'ISO-8859-1';
			if ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
				$raw = substr( $raw, 3 );
			}
			$converted = @mb_convert_encoding( $raw, 'UTF-8', $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $converted || ! preg_match( '//u', $converted ) ) {
				return array(
					'ok'       => false,
					'text'     => '',
					'detected' => 'unknown',
					'error'    => __( 'The file could not be converted from the selected encoding.', 'site-phonebooks' ),
				);
			}
			$raw = $converted;
		}

		if ( false !== strpos( $raw, "\0" ) ) {
			return array(
				'ok'       => false,
				'text'     => '',
				'detected' => 'binary',
				'error'    => __( 'The file contains binary data and does not look like a CSV text file.', 'site-phonebooks' ),
			);
		}

		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		return array(
			'ok'       => true,
			'text'     => $raw,
			'detected' => $detected,
			'error'    => '',
		);
	}

	/**
	 * Guess the delimiter by counting candidates outside quoted sections in
	 * the first few lines. Ties and no-signal fall back to comma.
	 *
	 * @param string $text UTF-8 text.
	 * @return string Delimiter identifier (comma|semicolon|tab).
	 */
	public static function detect_delimiter( $text ) {
		$lines  = array_slice( explode( "\n", (string) $text ), 0, 20 );
		$counts = array(
			'comma'     => 0,
			'semicolon' => 0,
			'tab'       => 0,
		);
		foreach ( $lines as $line ) {
			$in_quotes = false;
			$len       = strlen( $line );
			for ( $i = 0; $i < $len; $i++ ) {
				$ch = $line[ $i ];
				if ( '"' === $ch ) {
					$in_quotes = ! $in_quotes;
				} elseif ( ! $in_quotes ) {
					if ( ',' === $ch ) {
						++$counts['comma'];
					} elseif ( ';' === $ch ) {
						++$counts['semicolon'];
					} elseif ( "\t" === $ch ) {
						++$counts['tab'];
					}
				}
			}
		}
		arsort( $counts );
		$best = key( $counts );
		return $counts[ $best ] > 0 ? $best : 'comma';
	}

	/**
	 * Parse UTF-8 CSV text into records.
	 *
	 * @param string $text          UTF-8 text with "\n" endings.
	 * @param string $delimiter_id  comma|semicolon|tab.
	 * @param int    $max_records   Maximum records (excluding blank lines) before aborting.
	 * @return array{ok:bool,records:array<int,array{row:int,fields:string[]}>,error:string,truncated:bool}
	 */
	public static function parse( $text, $delimiter_id = 'comma', $max_records = 5000 ) {
		$delims    = self::delimiters();
		$delimiter = isset( $delims[ $delimiter_id ] ) ? $delims[ $delimiter_id ] : ',';
		$records   = array();
		$handle    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array(
				'ok'        => false,
				'records'   => array(),
				'error'     => __( 'Could not open a temporary stream for parsing.', 'site-phonebooks' ),
				'truncated' => false,
			);
		}
		fwrite( $handle, (string) $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );

		$row = 0;
		// Escape character disabled ('') so backslashes are literal, per RFC 4180.
		while ( false !== ( $fields = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
			++$row;
			if ( null === $fields || ( 1 === count( $fields ) && ( null === $fields[0] || '' === trim( (string) $fields[0] ) ) ) ) {
				continue; // Blank line.
			}
			$all_blank = true;
			foreach ( $fields as $k => $field ) {
				$fields[ $k ] = null === $field ? '' : (string) $field;
				if ( '' !== trim( $fields[ $k ] ) ) {
					$all_blank = false;
				}
			}
			if ( $all_blank ) {
				continue;
			}
			if ( count( $records ) >= $max_records ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return array(
					'ok'        => false,
					'records'   => $records,
					/* translators: %d: maximum number of rows */
					'error'     => sprintf( __( 'The file has more than %d rows. Split it into smaller files or raise the limit in Phonebooks > Settings.', 'site-phonebooks' ), $max_records ),
					'truncated' => true,
				);
			}
			$records[] = array(
				'row'    => $row,
				'fields' => $fields,
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'ok'        => true,
			'records'   => $records,
			'error'     => '',
			'truncated' => false,
		);
	}

	/**
	 * Guess which columns hold the name and telephone from a header row.
	 *
	 * @param string[] $header Header cells.
	 * @return array{name:int|null,telephone:int|null,notes:int|null}
	 */
	public static function guess_mapping( array $header ) {
		$guess = array(
			'name'      => null,
			'telephone' => null,
			'notes'     => null,
		);
		$patterns = array(
			'name'      => '/^(display\s*)?name$|^(full|contact|display)[\s_-]*name$|^label$|^caller$|^user$/i',
			'telephone' => '/^(tele?phone|phone|number|tel|extension|ext|mobile|cell|phone[\s_-]*number|telephone[\s_-]*number|dial|office[\s_-]*number)$/i',
			'notes'     => '/^(notes?|comments?|description|remarks?)$/i',
		);
		foreach ( $header as $index => $cell ) {
			$cell = trim( (string) $cell );
			foreach ( $patterns as $field => $pattern ) {
				if ( null === $guess[ $field ] && preg_match( $pattern, $cell ) ) {
					$guess[ $field ] = (int) $index;
					break;
				}
			}
		}
		if ( null === $guess['name'] && null === $guess['telephone'] && count( $header ) >= 2 ) {
			$guess['name']      = 0;
			$guess['telephone'] = 1;
		}
		return $guess;
	}

	/**
	 * Heuristic: does the first record look like a header rather than data?
	 *
	 * @param string[] $first_record First record cells.
	 * @return bool
	 */
	public static function looks_like_header( array $first_record ) {
		$guess = self::guess_mapping( $first_record );
		if ( null !== $guess['telephone'] && preg_match( '/^[0-9+*#()\-. ]+$/', trim( (string) $first_record[ $guess['telephone'] ] ) ) ) {
			return false;
		}
		foreach ( $first_record as $cell ) {
			if ( preg_match( '/^(name|telephone|phone|number|tel|extension|notes)$/i', trim( (string) $cell ) ) ) {
				return true;
			}
		}
		foreach ( $first_record as $cell ) {
			if ( preg_match( '/^\+?[0-9][0-9 ()\-.#*]{2,}$/', trim( (string) $cell ) ) ) {
				return false;
			}
		}
		return true;
	}
}
