<?php
/**
 * Feed routing (rewrite rules, query vars, early dispatch).
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Feed;

use SitePhonebooks\Phonebook_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Maps /phonebooks/{slug}.xml (and variants) onto the feed controller.
 */
final class Feed_Router {

	const BASE       = 'phonebooks';
	const QUERY_FEED = 'spb_feed';
	const QUERY_KEY  = 'spb_key';
	const QUERY_PARAM_KEY = 'key';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical_redirect' ), 10, 2 );
	}

	/**
	 * Feed base path segment (filterable for sites that already use /phonebooks/).
	 *
	 * @return string
	 */
	public static function base() {
		$base = apply_filters( 'spb_feed_base', self::BASE );
		$base = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $base ) );
		return '' === $base ? self::BASE : $base;
	}

	/**
	 * Rewrite rules:
	 *   phonebooks/{slug}.xml              -> ?spb_feed={slug}             (token via ?key=)
	 *   phonebooks/{token}/{slug}.xml      -> ?spb_feed={slug}&spb_key=... (path-token variant)
	 */
	public function add_rewrite_rules() {
		$base = self::base();
		add_rewrite_rule( '^' . $base . '/([a-f0-9]{20,128})/([a-z0-9-]+)\.xml$', 'index.php?' . self::QUERY_FEED . '=$matches[2]&' . self::QUERY_KEY . '=$matches[1]', 'top' );
		add_rewrite_rule( '^' . $base . '/([a-z0-9-]+)\.xml$', 'index.php?' . self::QUERY_FEED . '=$matches[1]', 'top' );
	}

	/**
	 * Register public query vars.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::QUERY_FEED;
		$vars[] = self::QUERY_KEY;
		return $vars;
	}

	/**
	 * Never canonical-redirect feed requests.
	 *
	 * @param string $redirect_url  Redirect URL.
	 * @param string $requested_url Requested URL.
	 * @return string|false
	 */
	public function no_canonical_redirect( $redirect_url, $requested_url ) {
		if ( get_query_var( self::QUERY_FEED ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Serve the feed as early as possible and stop WordPress.
	 *
	 * @param \WP $wp WP environment.
	 */
	public function maybe_serve( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_FEED ] ) ) {
			return;
		}
		$slug = (string) $wp->query_vars[ self::QUERY_FEED ];
		$key  = '';
		if ( ! empty( $wp->query_vars[ self::QUERY_KEY ] ) ) {
			$key = (string) $wp->query_vars[ self::QUERY_KEY ];
		} elseif ( isset( $_GET[ self::QUERY_PARAM_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- feed credential, not a form.
			$key = (string) wp_unslash( $_GET[ self::QUERY_PARAM_KEY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$context  = self::request_context();
		$response = Feed_Controller::handle( $slug, $key, $context );
		if ( $response->phonebook_id ) {
			Phonebook_Repository::record_request( $response->phonebook_id, $response->status, $context['user_agent'] );
		}

		/**
		 * Fires before a feed response is sent. Allows tests and diagnostics to
		 * observe the response. Must not output anything.
		 *
		 * @param Feed_Response $response Response about to be sent.
		 */
		do_action( 'spb_feed_response', $response );

		$response->send();
	}

	/**
	 * Collect request context from the server environment.
	 *
	 * @return array{method:string,if_none_match:string,if_modified_since:string,user_agent:string}
	 */
	public static function request_context() {
		return array(
			'method'            => isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET',
			'if_none_match'     => isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '',
			'if_modified_since' => isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '',
			'user_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);
	}

	/**
	 * Whether pretty permalinks are active.
	 *
	 * @return bool
	 */
	public static function pretty_permalinks() {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Build the feed URLs for a phonebook.
	 *
	 * @param object $phonebook Phonebook row.
	 * @return array{primary:string,pretty:string,pretty_path_token:string,fallback:string,public_pretty:string,public_fallback:string}
	 */
	public static function urls( $phonebook ) {
		$base            = self::base();
		$slug            = $phonebook->slug;
		$token           = $phonebook->access_token;
		$public_pretty   = home_url( '/' . $base . '/' . $slug . '.xml' );
		$public_fallback = add_query_arg( self::QUERY_FEED, $slug . '.xml', home_url( '/' ) );

		$protected = 'public' !== $phonebook->access_mode;
		$pretty    = $protected ? add_query_arg( self::QUERY_PARAM_KEY, $token, $public_pretty ) : $public_pretty;
		$path      = $protected ? home_url( '/' . $base . '/' . $token . '/' . $slug . '.xml' ) : $public_pretty;
		$fallback  = $protected ? add_query_arg( self::QUERY_PARAM_KEY, $token, $public_fallback ) : $public_fallback;

		return array(
			'primary'           => self::pretty_permalinks() ? $pretty : $fallback,
			'pretty'            => $pretty,
			'pretty_path_token' => $path,
			'fallback'          => $fallback,
			'public_pretty'     => $public_pretty,
			'public_fallback'   => $public_fallback,
		);
	}
}
