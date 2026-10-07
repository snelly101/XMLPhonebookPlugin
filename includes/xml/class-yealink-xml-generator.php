<?php
/**
 * Yealink remote phonebook XML generation.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Xml;

defined( 'ABSPATH' ) || exit;

/**
 * Emits the compatibility profile supplied by the owner, byte-for-byte in
 * structure:
 *
 * <?xml version="1.0" encoding="utf-8"?>
 * <XXXIPPhoneDirectory clearlight="true">
 *   <Title>Site name</Title>
 *   <Prompt>Prompt</Prompt>
 *   <DirectoryEntry>
 *     <Name>...</Name>
 *     <Telephone>...</Telephone>
 *   </DirectoryEntry>
 * </XXXIPPhoneDirectory>
 *
 * The unusual root element name and its attribute are preserved verbatim;
 * no behaviour is inferred from them. Additional manufacturer formats would be
 * separate profiles, not changes to this one.
 */
final class Yealink_Xml_Generator {

	const PROFILE_ID    = 'yealink-ipphonedirectory-v1';
	const ROOT_ELEMENT  = 'XXXIPPhoneDirectory';
	const ROOT_ATTR     = 'clearlight';
	const ROOT_ATTR_VAL = 'true';

	/**
	 * Generate the XML document.
	 *
	 * @param string   $title    Phonebook title (site name).
	 * @param string   $prompt   Prompt text.
	 * @param iterable $contacts Items with name and telephone (object or array), already ordered.
	 * @return string UTF-8 XML without BOM.
	 * @throws \InvalidArgumentException When a value cannot be represented in XML 1.0.
	 */
	public static function generate( $title, $prompt, $contacts ) {
		// DOMDocument is used (rather than XMLWriter) because it reproduces the
		// owner's working file exactly: lowercase "utf-8" in the declaration,
		// two-space indentation and minimal escaping (&amp; &lt; &gt;) in text.
		$dom                     = new \DOMDocument( '1.0', 'utf-8' );
		$dom->formatOutput       = true;
		$dom->preserveWhiteSpace = false;

		$root = $dom->createElement( self::ROOT_ELEMENT );
		$root->setAttribute( self::ROOT_ATTR, self::ROOT_ATTR_VAL );
		$dom->appendChild( $root );

		$root->appendChild( self::text_element( $dom, 'Title', self::assert_xml_text( $title, 'Title' ) ) );
		$root->appendChild( self::text_element( $dom, 'Prompt', self::assert_xml_text( $prompt, 'Prompt' ) ) );

		foreach ( $contacts as $contact ) {
			$contact = (array) $contact;
			$entry   = $dom->createElement( 'DirectoryEntry' );
			$entry->appendChild( self::text_element( $dom, 'Name', self::assert_xml_text( isset( $contact['name'] ) ? $contact['name'] : '', 'Name' ) ) );
			$entry->appendChild( self::text_element( $dom, 'Telephone', self::assert_xml_text( isset( $contact['telephone'] ) ? $contact['telephone'] : '', 'Telephone' ) ) );
			$root->appendChild( $entry );
		}

		$xml = $dom->saveXML( null, 0 );
		if ( false === $xml ) {
			throw new \InvalidArgumentException( 'The XML document could not be serialised.' );
		}
		return $xml;
	}

	/**
	 * Create an element whose text content is escaped by the DOM library.
	 *
	 * @param \DOMDocument $dom   Document.
	 * @param string       $name  Element name.
	 * @param string       $value Text value.
	 * @return \DOMElement
	 */
	private static function text_element( \DOMDocument $dom, $name, $value ) {
		$element = $dom->createElement( $name );
		$element->appendChild( $dom->createTextNode( $value ) );
		return $element;
	}

	/**
	 * Ensure a value is valid UTF-8 and contains only XML 1.0 characters.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field label for the error.
	 * @return string
	 * @throws \InvalidArgumentException On invalid text.
	 */
	public static function assert_xml_text( $value, $field ) {
		$value = (string) $value;
		if ( '' !== $value && ! preg_match( '//u', $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%s contains invalid UTF-8 and cannot be written to the XML feed.', $field ) );
		}
		if ( preg_match( \SitePhonebooks\Contact_Validator::XML_INVALID_PATTERN, $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%s contains control characters that cannot be represented in XML 1.0. Remove them from the contact and try again.', $field ) );
		}
		return $value;
	}
}
