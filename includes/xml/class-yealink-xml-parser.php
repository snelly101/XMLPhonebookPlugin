<?php
/**
 * Secure parser for the Yealink phonebook XML import format.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Xml;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the working XML structure into contact rows. Rejects anything that is
 * not the known structure, any DOCTYPE/entity declarations, processing
 * instructions and external references. Never resolves network resources.
 */
final class Yealink_Xml_Parser {

	/**
	 * Parse XML bytes.
	 *
	 * @param string $raw         XML bytes.
	 * @param int    $max_entries Maximum DirectoryEntry elements.
	 * @return array{ok:bool,error:string,title:string,prompt:string,records:array<int,array{row:int,name:string,telephone:string}>,warnings:string[]}
	 */
	public static function parse( $raw, $max_entries = 5000 ) {
		$raw      = (string) $raw;
		$warnings = array();
		$fail     = static function ( $message ) {
			return array(
				'ok'       => false,
				'error'    => $message,
				'title'    => '',
				'prompt'   => '',
				'records'  => array(),
				'warnings' => array(),
			);
		};

		if ( "\xEF\xBB\xBF" === substr( $raw, 0, 3 ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( '' === trim( $raw ) ) {
			return $fail( __( 'The XML file is empty.', 'site-phonebooks' ) );
		}
		if ( ! preg_match( '//u', $raw ) ) {
			return $fail( __( 'The XML file is not valid UTF-8. Save it as UTF-8 and try again.', 'site-phonebooks' ) );
		}
		if ( preg_match( '/<!DOCTYPE/i', $raw ) || preg_match( '/<!ENTITY/i', $raw ) ) {
			return $fail( __( 'The XML file contains a document type or entity declaration, which is not allowed for security reasons.', 'site-phonebooks' ) );
		}

		// Cheap structural guard before expensive parsing.
		$entry_count = preg_match_all( '/<DirectoryEntry\b/', $raw );
		if ( $entry_count > $max_entries ) {
			/* translators: %d: maximum number of entries */
			return $fail( sprintf( __( 'The XML file has more than %d entries. Split it into smaller files or raise the limit in Phonebooks > Settings.', 'site-phonebooks' ), $max_entries ) );
		}

		$previous_errors = libxml_use_internal_errors( true );
		if ( \PHP_VERSION_ID < 80000 && function_exists( 'libxml_disable_entity_loader' ) ) {
			$previous_loader = libxml_disable_entity_loader( true ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated
		}

		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->resolveExternals  = false;
		$dom->substituteEntities = false;
		$dom->validateOnParse   = false;
		$options = LIBXML_NONET | LIBXML_NOCDATA;
		if ( defined( 'LIBXML_NOXMLDECL' ) ) {
			$options |= 0; // keep explicit for clarity.
		}
		$loaded = $dom->loadXML( $raw, $options );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		if ( isset( $previous_loader ) ) {
			libxml_disable_entity_loader( $previous_loader ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated
		}

		if ( ! $loaded ) {
			$detail = '';
			if ( ! empty( $errors ) ) {
				$first  = $errors[0];
				$detail = sprintf( ' (line %d: %s)', (int) $first->line, trim( (string) $first->message ) );
			}
			return $fail( __( 'The XML file is malformed and could not be parsed.', 'site-phonebooks' ) . $detail );
		}

		foreach ( $dom->childNodes as $node ) {
			if ( XML_DOCUMENT_TYPE_NODE === $node->nodeType ) {
				return $fail( __( 'The XML file contains a document type declaration, which is not allowed.', 'site-phonebooks' ) );
			}
		}

		$root = $dom->documentElement;
		if ( ! $root || Yealink_Xml_Generator::ROOT_ELEMENT !== $root->nodeName ) {
			return $fail(
				sprintf(
					/* translators: 1: expected root element, 2: found root element */
					__( 'Unsupported XML structure: expected a <%1$s> root element but found <%2$s>.', 'site-phonebooks' ),
					Yealink_Xml_Generator::ROOT_ELEMENT,
					$root ? $root->nodeName : '?'
				)
			);
		}

		$title   = '';
		$prompt  = '';
		$records = array();
		$row     = 0;
		$allowed = array( 'Title', 'Prompt', 'DirectoryEntry' );

		foreach ( $root->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( ! in_array( $child->nodeName, $allowed, true ) ) {
				/* translators: %s: element name */
				$warnings[] = sprintf( __( 'Ignored unexpected element <%s>.', 'site-phonebooks' ), $child->nodeName );
				continue;
			}
			if ( 'Title' === $child->nodeName ) {
				$title = trim( $child->textContent );
				continue;
			}
			if ( 'Prompt' === $child->nodeName ) {
				$prompt = trim( $child->textContent );
				continue;
			}
			++$row;
			$name      = null;
			$telephone = null;
			foreach ( $child->childNodes as $field ) {
				if ( XML_ELEMENT_NODE !== $field->nodeType ) {
					continue;
				}
				if ( 'Name' === $field->nodeName ) {
					if ( null === $name ) {
						$name = $field->textContent;
					} else {
						/* translators: %d: entry number */
						$warnings[] = sprintf( __( 'Entry %d has more than one <Name>; only the first was used.', 'site-phonebooks' ), $row );
					}
				} elseif ( 'Telephone' === $field->nodeName ) {
					if ( null === $telephone ) {
						$telephone = $field->textContent;
					} else {
						/* translators: %d: entry number */
						$warnings[] = sprintf( __( 'Entry %d has more than one <Telephone>; only the first was used (additional numbers are not part of the current compatibility profile).', 'site-phonebooks' ), $row );
					}
				} else {
					/* translators: 1: entry number, 2: element name */
					$warnings[] = sprintf( __( 'Entry %1$d: ignored unexpected element <%2$s>.', 'site-phonebooks' ), $row, $field->nodeName );
				}
			}
			$records[] = array(
				'row'       => $row,
				'name'      => (string) $name,
				'telephone' => (string) $telephone,
			);
		}

		return array(
			'ok'       => true,
			'error'    => '',
			'title'    => $title,
			'prompt'   => $prompt,
			'records'  => $records,
			'warnings' => $warnings,
		);
	}
}
