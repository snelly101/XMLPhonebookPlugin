<?php
/**
 * Import validation and change planning.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Import;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Contact_Validator;
use SitePhonebooks\Csv\Csv_Exporter;
use SitePhonebooks\Csv\Csv_Parser;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Settings;
use SitePhonebooks\Xml\Yealink_Xml_Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an uploaded file plus mapping options into an exact, reviewable plan.
 * Nothing here writes to the live phonebook.
 */
final class Import_Planner {

	const MODE_MERGE   = 'merge';
	const MODE_REPLACE = 'replace';

	const STATUS_ADD          = 'add';
	const STATUS_DUP_FILE     = 'duplicate_in_file';
	const STATUS_DUP_EXISTING = 'duplicate_existing';
	const STATUS_INVALID      = 'invalid';

	/**
	 * Extract raw rows (row number, name, telephone, notes) from a CSV upload.
	 *
	 * @param string $raw     File bytes.
	 * @param array  $options {encoding, delimiter, has_header, map}.
	 * @return array{ok:bool,error:string,rows:array,header:string[],records_total:int}
	 */
	public static function rows_from_csv( $raw, array $options ) {
		$encoding = isset( $options['encoding'] ) && in_array( $options['encoding'], Csv_Parser::encodings(), true ) ? $options['encoding'] : Csv_Parser::ENCODING_UTF8;
		$decoded  = Csv_Parser::to_utf8( $raw, $encoding );
		if ( ! $decoded['ok'] ) {
			return array(
				'ok'            => false,
				'error'         => $decoded['error'],
				'rows'          => array(),
				'header'        => array(),
				'records_total' => 0,
			);
		}
		$delimiter = isset( $options['delimiter'] ) && isset( Csv_Parser::delimiters()[ $options['delimiter'] ] ) ? $options['delimiter'] : Csv_Parser::detect_delimiter( $decoded['text'] );
		$max_rows  = (int) Settings::get( 'import_max_rows' );
		$parsed    = Csv_Parser::parse( $decoded['text'], $delimiter, $max_rows + 1 );
		if ( ! $parsed['ok'] ) {
			return array(
				'ok'            => false,
				'error'         => $parsed['error'],
				'rows'          => array(),
				'header'        => array(),
				'records_total' => count( $parsed['records'] ),
			);
		}
		$records    = $parsed['records'];
		$has_header = ! empty( $options['has_header'] );
		$header     = array();
		if ( $has_header && ! empty( $records ) ) {
			$header = array_shift( $records )['fields'];
		}
		$map       = isset( $options['map'] ) && is_array( $options['map'] ) ? $options['map'] : array();
		$name_col  = isset( $map['name'] ) && null !== $map['name'] && '' !== $map['name'] ? (int) $map['name'] : null;
		$tel_col   = isset( $map['telephone'] ) && null !== $map['telephone'] && '' !== $map['telephone'] ? (int) $map['telephone'] : null;
		$notes_col = isset( $map['notes'] ) && null !== $map['notes'] && '' !== $map['notes'] ? (int) $map['notes'] : null;

		if ( null === $name_col || null === $tel_col ) {
			return array(
				'ok'            => false,
				'error'         => __( 'Choose which column holds the Name and which holds the Telephone.', 'site-phonebooks' ),
				'rows'          => array(),
				'header'        => $header,
				'records_total' => count( $records ),
			);
		}
		if ( $name_col === $tel_col ) {
			return array(
				'ok'            => false,
				'error'         => __( 'Name and Telephone cannot come from the same column.', 'site-phonebooks' ),
				'rows'          => array(),
				'header'        => $header,
				'records_total' => count( $records ),
			);
		}

		$rows = array();
		foreach ( $records as $record ) {
			$fields = $record['fields'];
			$rows[] = array(
				'row'       => (int) $record['row'],
				'name'      => Csv_Exporter::unprotect_cell( isset( $fields[ $name_col ] ) ? $fields[ $name_col ] : '' ),
				'telephone' => Csv_Exporter::unprotect_cell( isset( $fields[ $tel_col ] ) ? $fields[ $tel_col ] : '' ),
				'notes'     => null !== $notes_col ? Csv_Exporter::unprotect_cell( isset( $fields[ $notes_col ] ) ? $fields[ $notes_col ] : '' ) : '',
				'missing'   => ! isset( $fields[ $name_col ] ) || ! isset( $fields[ $tel_col ] ),
			);
		}
		return array(
			'ok'            => true,
			'error'         => '',
			'rows'          => $rows,
			'header'        => $header,
			'records_total' => count( $records ),
		);
	}

	/**
	 * Extract rows from an XML upload.
	 *
	 * @param string $raw File bytes.
	 * @return array{ok:bool,error:string,rows:array,title:string,prompt:string,warnings:string[]}
	 */
	public static function rows_from_xml( $raw ) {
		$parsed = Yealink_Xml_Parser::parse( $raw, (int) Settings::get( 'import_max_rows' ) );
		if ( ! $parsed['ok'] ) {
			return array(
				'ok'       => false,
				'error'    => $parsed['error'],
				'rows'     => array(),
				'title'    => '',
				'prompt'   => '',
				'warnings' => array(),
			);
		}
		$rows = array();
		foreach ( $parsed['records'] as $record ) {
			$rows[] = array(
				'row'       => (int) $record['row'],
				'name'      => $record['name'],
				'telephone' => $record['telephone'],
				'notes'     => '',
				'missing'   => false,
			);
		}
		return array(
			'ok'       => true,
			'error'    => '',
			'rows'     => $rows,
			'title'    => $parsed['title'],
			'prompt'   => $parsed['prompt'],
			'warnings' => $parsed['warnings'],
		);
	}

	/**
	 * Validate rows and compute the exact change set against the live phonebook.
	 *
	 * @param int   $phonebook_id Phonebook ID.
	 * @param array $rows         Rows from rows_from_csv/rows_from_xml.
	 * @param array $options      {mode, valid_only, rename_from_title, title}.
	 * @return array Plan.
	 */
	public static function plan( $phonebook_id, array $rows, array $options ) {
		$mode       = ( isset( $options['mode'] ) && self::MODE_REPLACE === $options['mode'] ) ? self::MODE_REPLACE : self::MODE_MERGE;
		$valid_only = ! empty( $options['valid_only'] );
		$existing   = Contact_Repository::hashes( $phonebook_id );
		$seen       = array();
		$planned    = array();
		$summary    = array(
			'mode'               => $mode,
			'total'              => count( $rows ),
			'valid'              => 0,
			'invalid'            => 0,
			'warnings'           => 0,
			'add'                => 0,
			'duplicate_in_file'  => 0,
			'duplicate_existing' => 0,
			'existing_count'     => count( $existing ),
			'unchanged'          => 0,
			'removed'            => 0,
			'retained'           => 0,
			'final_count'        => 0,
		);
		$import_hashes = array();

		foreach ( $rows as $row ) {
			$v     = Contact_Validator::validate( $row['name'], $row['telephone'], isset( $row['notes'] ) ? $row['notes'] : '' );
			$entry = array(
				'row'       => (int) $row['row'],
				'name'      => $v['name'],
				'telephone' => $v['telephone'],
				'notes'     => $v['notes'],
				'hash'      => $v['hash'],
				'status'    => self::STATUS_ADD,
				'errors'    => $v['errors'],
				'warnings'  => $v['warnings'],
			);
			if ( ! empty( $row['missing'] ) ) {
				$entry['errors'][] = __( 'The row has fewer columns than the mapping requires.', 'site-phonebooks' );
			}
			if ( ! empty( $entry['warnings'] ) ) {
				++$summary['warnings'];
			}
			if ( ! empty( $entry['errors'] ) ) {
				$entry['status'] = self::STATUS_INVALID;
				++$summary['invalid'];
				$planned[] = $entry;
				continue;
			}
			++$summary['valid'];
			if ( isset( $seen[ $v['hash'] ] ) ) {
				$entry['status'] = self::STATUS_DUP_FILE;
				++$summary['duplicate_in_file'];
				/* translators: %d: row number */
				$entry['warnings'][] = sprintf( __( 'Exact duplicate of row %d; skipped.', 'site-phonebooks' ), $seen[ $v['hash'] ] );
			} elseif ( isset( $existing[ $v['hash'] ] ) ) {
				$seen[ $v['hash'] ]          = $entry['row'];
				$import_hashes[ $v['hash'] ] = true;
				$entry['status']             = self::STATUS_DUP_EXISTING;
				++$summary['duplicate_existing'];
			} else {
				$seen[ $v['hash'] ]          = $entry['row'];
				$import_hashes[ $v['hash'] ] = true;
				++$summary['add'];
			}
			$planned[] = $entry;
		}

		$remove_hashes = array();
		if ( self::MODE_REPLACE === $mode ) {
			foreach ( $existing as $hash => $id ) {
				if ( isset( $import_hashes[ $hash ] ) ) {
					++$summary['unchanged'];
				} else {
					$remove_hashes[] = $hash;
				}
			}
			$summary['removed']     = count( $remove_hashes );
			$summary['retained']    = $summary['unchanged'];
			$summary['final_count'] = $summary['unchanged'] + $summary['add'];
		} else {
			$summary['retained']    = count( $existing );
			$summary['unchanged']   = $summary['duplicate_existing'];
			$summary['final_count'] = count( $existing ) + $summary['add'];
		}

		$blocking = array();
		if ( 0 === $summary['total'] ) {
			$blocking[] = __( 'The file contains no contact rows.', 'site-phonebooks' );
		}
		if ( $summary['invalid'] > 0 && ! $valid_only ) {
			$blocking[] = sprintf(
				/* translators: %d: number of invalid rows */
				_n( '%d row is invalid. Fix the file, or tick "Import valid rows only" to skip it.', '%d rows are invalid. Fix the file, or tick "Import valid rows only" to skip them.', $summary['invalid'], 'site-phonebooks' ),
				$summary['invalid']
			);
		}
		if ( self::MODE_REPLACE === $mode && 0 === $summary['final_count'] ) {
			$blocking[] = __( 'A replace import must leave at least one contact. To empty a phonebook, use "Clear phonebook" on the Contacts tab instead.', 'site-phonebooks' );
		}
		if ( self::MODE_MERGE === $mode && 0 === $summary['add'] && $summary['total'] > 0 && empty( $blocking ) ) {
			$blocking[] = __( 'Nothing to import: every valid row already exists in this phonebook.', 'site-phonebooks' );
		}

		$rename = null;
		if ( ! empty( $options['rename_from_title'] ) && isset( $options['title'] ) && '' !== trim( (string) $options['title'] ) ) {
			$name = Phonebook_Repository::validate_name( $options['title'], $phonebook_id );
			if ( is_wp_error( $name ) ) {
				$blocking[] = __( 'The XML title cannot be used as the phonebook name:', 'site-phonebooks' ) . ' ' . $name->get_error_message();
			} else {
				$rename = $name;
			}
		}

		return array(
			'rows'          => $planned,
			'summary'       => $summary,
			'remove_hashes' => $remove_hashes,
			'blocking'      => $blocking,
			'rename'        => $rename,
			'valid_only'    => $valid_only,
			'mode'          => $mode,
		);
	}
}
