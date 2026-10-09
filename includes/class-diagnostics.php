<?php
/**
 * Environment and configuration checks.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

use SitePhonebooks\Feed\Feed_Controller;
use SitePhonebooks\Feed\Feed_Router;

defined( 'ABSPATH' ) || exit;

/**
 * Produces a list of checks for the Help & Diagnostics screen. Never includes
 * tokens, SQL or file paths in output.
 */
final class Diagnostics {

	/**
	 * Run all checks.
	 *
	 * @return array<int,array{label:string,status:string,detail:string}>
	 */
	public static function run() {
		global $wpdb, $wp_rewrite;
		$checks = array();

		$checks[] = self::check( __( 'PHP version', 'site-phonebooks' ), version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'error', PHP_VERSION );
		$checks[] = self::check( __( 'WordPress version', 'site-phonebooks' ), version_compare( get_bloginfo( 'version' ), '6.4', '>=' ) ? 'ok' : 'warning', get_bloginfo( 'version' ) );

		foreach ( array( 'dom', 'xmlwriter', 'mbstring', 'json', 'libxml' ) as $ext ) {
			/* translators: %s: PHP extension name */
			$checks[] = self::check( sprintf( __( 'PHP extension: %s', 'site-phonebooks' ), $ext ), extension_loaded( $ext ) ? 'ok' : 'error', extension_loaded( $ext ) ? __( 'loaded', 'site-phonebooks' ) : __( 'missing – required', 'site-phonebooks' ) );
		}
		$checks[] = self::check( __( 'PHP extension: intl (optional)', 'site-phonebooks' ), class_exists( '\Transliterator' ) ? 'ok' : 'info', class_exists( '\Transliterator' ) ? __( 'loaded – non-Latin site names get transliterated file names', 'site-phonebooks' ) : __( 'not loaded – non-Latin site names fall back to "phonebook-N" file names', 'site-phonebooks' ) );

		$checks[] = self::check( __( 'Database', 'site-phonebooks' ), 'info', $wpdb->db_server_info() );
		foreach ( Installer::table_engines() as $table => $engine ) {
			$short = str_replace( $wpdb->prefix, '', $table );
			if ( '' === $engine ) {
				$checks[] = self::check( sprintf( /* translators: %s: table name */ __( 'Table %s', 'site-phonebooks' ), $short ), 'error', __( 'missing – deactivate and reactivate the plugin', 'site-phonebooks' ) );
			} else {
				$checks[] = self::check( sprintf( /* translators: %s: table name */ __( 'Table %s', 'site-phonebooks' ), $short ), 'InnoDB' === $engine ? 'ok' : 'warning', 'InnoDB' === $engine ? 'InnoDB' : sprintf( /* translators: %s: engine */ __( '%s – imports are not atomic without InnoDB', 'site-phonebooks' ), $engine ) );
			}
		}
		$checks[] = self::check( __( 'Schema version', 'site-phonebooks' ), SPB_DB_VERSION === get_option( 'spb_db_version' ) ? 'ok' : 'warning', (string) get_option( 'spb_db_version' ) );

		$pretty = Feed_Router::pretty_permalinks();
		$checks[] = self::check( __( 'Permalinks', 'site-phonebooks' ), $pretty ? 'ok' : 'warning', $pretty ? sprintf( /* translators: %s: path */ __( 'pretty permalinks active – feeds use /%s/{site}.xml', 'site-phonebooks' ), Feed_Router::base() ) : __( 'plain permalinks – feeds use the ?spb_feed= fallback address (shown on each phonebook)', 'site-phonebooks' ) );
		if ( $pretty && $wp_rewrite instanceof \WP_Rewrite ) {
			$rules = $wp_rewrite->wp_rewrite_rules();
			$found = false;
			foreach ( (array) $rules as $pattern => $target ) {
				if ( false !== strpos( $target, Feed_Router::QUERY_FEED . '=' ) ) {
					$found = true;
					break;
				}
			}
			$checks[] = self::check( __( 'Feed rewrite rules', 'site-phonebooks' ), $found ? 'ok' : 'error', $found ? __( 'registered', 'site-phonebooks' ) : __( 'missing – visit Settings > Permalinks and click Save to rebuild them', 'site-phonebooks' ) );
			$conflict = get_page_by_path( Feed_Router::base() );
			$checks[] = self::check( __( 'Feed path conflict', 'site-phonebooks' ), $conflict ? 'warning' : 'ok', $conflict ? sprintf( /* translators: %s: path */ __( 'a page with the slug "%s" exists; feed rules take priority but check the page still works', 'site-phonebooks' ), Feed_Router::base() ) : __( 'none', 'site-phonebooks' ) );
		}
		$checks[] = self::check( __( 'Site address', 'site-phonebooks' ), 0 === strpos( home_url(), 'https://' ) ? 'ok' : 'info', home_url() . ( 0 === strpos( home_url(), 'https://' ) ? '' : ' – ' . __( 'HTTPS is recommended; verify your handsets trust the certificate', 'site-phonebooks' ) ) );
		$checks[] = self::check( __( 'Object cache', 'site-phonebooks' ), 'info', wp_using_ext_object_cache() ? __( 'persistent object cache in use – rendered feeds are cached and validated by revision', 'site-phonebooks' ) : __( 'no persistent object cache – feeds are rendered per request (fine for typical sizes)', 'site-phonebooks' ) );
		$checks[] = self::check( __( 'Housekeeping event', 'site-phonebooks' ), wp_next_scheduled( 'spb_cleanup' ) ? 'ok' : 'warning', wp_next_scheduled( 'spb_cleanup' ) ? __( 'scheduled (removes expired staged imports)', 'site-phonebooks' ) : __( 'not scheduled – reactivate the plugin', 'site-phonebooks' ) );
		$checks[] = self::check( __( 'Upload limit', 'site-phonebooks' ), 'info', size_format( wp_max_upload_size() ) );

		$phonebooks = Phonebook_Repository::all_summaries();
		$checks[]   = self::check( __( 'Phonebooks', 'site-phonebooks' ), 'info', sprintf( /* translators: 1: count, 2: contacts */ __( '%1$s phonebooks, %2$s contacts in total', 'site-phonebooks' ), number_format_i18n( count( $phonebooks ) ), number_format_i18n( array_sum( wp_list_pluck( $phonebooks, 'contact_count' ) ) ) ) );

		foreach ( $phonebooks as $summary ) {
			$phonebook = Phonebook_Repository::get( $summary->id );
			try {
				$xml = Feed_Controller::render( $phonebook );
				$dom = new \DOMDocument();
				$ok  = $dom->loadXML( $xml ) && Xml\Yealink_Xml_Generator::ROOT_ELEMENT === $dom->documentElement->nodeName;
				$checks[] = self::check( sprintf( /* translators: %s: site name */ __( 'XML for "%s"', 'site-phonebooks' ), $phonebook->name ), $ok ? 'ok' : 'error', $ok ? sprintf( /* translators: 1: entries, 2: bytes */ __( 'valid, %1$s entries, %2$s', 'site-phonebooks' ), number_format_i18n( $dom->getElementsByTagName( 'DirectoryEntry' )->length ), size_format( strlen( $xml ) ) ) : __( 'does not parse', 'site-phonebooks' ) );
			} catch ( \InvalidArgumentException $e ) {
				$checks[] = self::check( sprintf( /* translators: %s: site name */ __( 'XML for "%s"', 'site-phonebooks' ), $phonebook->name ), 'error', $e->getMessage() );
			}
		}

		return $checks;
	}

	/**
	 * Build a check row.
	 *
	 * @param string $label  Label.
	 * @param string $status ok|warning|error|info.
	 * @param string $detail Detail.
	 * @return array
	 */
	private static function check( $label, $status, $detail ) {
		return array(
			'label'  => $label,
			'status' => $status,
			'detail' => (string) $detail,
		);
	}

	/**
	 * Server-side loopback request against a phonebook's own feed. Proves the
	 * route works on this server only, not reachability from a phone's network.
	 *
	 * @param object $phonebook Phonebook.
	 * @return array{ok:bool,message:string}
	 */
	public static function self_test( $phonebook ) {
		$urls     = Feed_Router::urls( $phonebook );
		$response = wp_remote_head(
			$urls['primary'],
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => 'SitePhonebooks self-test',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: error message */
				'message' => sprintf( __( 'The server could not request its own feed: %s. Loopback requests may be blocked on this host; this does not necessarily mean phones cannot reach it.', 'site-phonebooks' ), $response->get_error_message() ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( 200 === $code && false !== stripos( $type, 'xml' ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'The server fetched its own feed successfully (HTTP 200, XML). This confirms routing and access settings on the server; it does not prove a handset on another network can reach it.', 'site-phonebooks' ),
			);
		}
		return array(
			'ok'      => false,
			/* translators: 1: HTTP status, 2: content type */
			'message' => sprintf( __( 'Unexpected response: HTTP %1$d, content type "%2$s". If the status is 404 with pretty permalinks, re-save Settings > Permalinks. If a page cache or security plugin answered, exclude the feed path.', 'site-phonebooks' ), $code, $type ),
		);
	}
}
