<?php
/**
 * Phonebook storage.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for phonebooks (one row per site).
 */
final class Phonebook_Repository {

	const NAME_MAX_LENGTH = 190;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'phonebooks' );
	}

	/**
	 * Cast a database row.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row->id = (int) $row->id;
		if ( isset( $row->enabled ) ) {
			$row->enabled = (bool) (int) $row->enabled;
		}
		if ( isset( $row->revision ) ) {
			$row->revision = (int) $row->revision;
		}
		if ( isset( $row->contact_count ) ) {
			$row->contact_count = (int) $row->contact_count;
		}
		if ( property_exists( $row, 'last_request_status' ) ) {
			$row->last_request_status = null === $row->last_request_status ? null : (int) $row->last_request_status;
		}
		return $row;
	}

	/**
	 * Get by ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get by slug.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		$table = self::table();
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a slug is taken.
	 *
	 * @param string $slug       Slug.
	 * @param int    $exclude_id Phonebook to ignore.
	 * @return bool
	 */
	public static function slug_exists( $slug, $exclude_id = 0 ) {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", (string) $slug, (int) $exclude_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a name is taken (case-insensitive via collation).
	 *
	 * @param string $name       Name.
	 * @param int    $exclude_id Phonebook to ignore.
	 * @return bool
	 */
	public static function name_exists( $name, $exclude_id = 0 ) {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s AND id <> %d", (string) $name, (int) $exclude_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Paginated list.
	 *
	 * @param array $args {search, page, per_page, orderby, order}.
	 * @return array{items:object[],total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table    = self::table();
		$args     = array_merge(
			array(
				'search'   => '',
				'page'     => 1,
				'per_page' => 20,
				'orderby'  => 'name',
				'order'    => 'ASC',
			),
			$args
		);
		$where    = '1=1';
		$params   = array();
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (name LIKE %s OR slug LIKE %s OR description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$allowed_orderby = array( 'name', 'slug', 'contact_count', 'updated_at', 'content_updated_at', 'enabled' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
		$order           = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
		$per_page        = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset          = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id ASC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		$items = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $items ? $items : array() ),
			'total' => $total,
		);
	}

	/**
	 * All phonebooks (id, name, slug) for dropdowns.
	 *
	 * @return object[]
	 */
	public static function all_summaries() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT id, name, slug, enabled, contact_count FROM {$table} ORDER BY name ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Total count.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Validate and normalise a display name.
	 *
	 * @param string $name       Name.
	 * @param int    $exclude_id Phonebook to ignore for uniqueness.
	 * @return string|\WP_Error
	 */
	public static function validate_name( $name, $exclude_id = 0 ) {
		$name = Contact_Validator::normalize_name( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'spb_name_required', __( 'Site name is required.', 'site-phonebooks' ) );
		}
		if ( ! Contact_Validator::is_utf8( $name ) || preg_match( Contact_Validator::XML_INVALID_PATTERN, $name ) ) {
			return new \WP_Error( 'spb_name_invalid', __( 'Site name contains characters that cannot be represented in XML.', 'site-phonebooks' ) );
		}
		if ( mb_strlen( $name, 'UTF-8' ) > self::NAME_MAX_LENGTH ) {
			/* translators: %d: maximum length */
			return new \WP_Error( 'spb_name_too_long', sprintf( __( 'Site name is longer than %d characters.', 'site-phonebooks' ), self::NAME_MAX_LENGTH ) );
		}
		if ( self::name_exists( $name, $exclude_id ) ) {
			/* translators: %s: site name */
			return new \WP_Error( 'spb_name_duplicate', sprintf( __( 'A phonebook named "%s" already exists. Site names must be unique.', 'site-phonebooks' ), $name ) );
		}
		return $name;
	}

	/**
	 * Validate a prompt.
	 *
	 * @param string $prompt Prompt.
	 * @return string|\WP_Error
	 */
	public static function validate_prompt( $prompt ) {
		$prompt = Contact_Validator::normalize_name( $prompt );
		if ( '' === $prompt ) {
			$prompt = 'Prompt';
		}
		if ( ! Contact_Validator::is_utf8( $prompt ) || preg_match( Contact_Validator::XML_INVALID_PATTERN, $prompt ) || mb_strlen( $prompt, 'UTF-8' ) > 190 ) {
			return new \WP_Error( 'spb_prompt_invalid', __( 'Prompt contains invalid characters or is too long.', 'site-phonebooks' ) );
		}
		return $prompt;
	}

	/**
	 * Create a phonebook.
	 *
	 * @param array $data {name, description, prompt, access_mode, slug (optional)}.
	 * @return int|\WP_Error New ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$name = self::validate_name( isset( $data['name'] ) ? $data['name'] : '' );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$prompt = self::validate_prompt( isset( $data['prompt'] ) ? $data['prompt'] : 'Prompt' );
		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}
		$requested_slug = isset( $data['slug'] ) ? trim( (string) $data['slug'] ) : '';
		$slug_base      = '' !== $requested_slug ? $requested_slug : $name;
		$slug           = Slug::unique( $slug_base, array( __CLASS__, 'slug_exists' ) );
		$access_mode    = ( isset( $data['access_mode'] ) && 'public' === $data['access_mode'] ) ? 'public' : 'token';
		$now            = Db::now();

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'name'               => $name,
				'slug'               => $slug,
				'description'        => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : '',
				'prompt'             => $prompt,
				'access_mode'        => $access_mode,
				'access_token'       => Token::generate(),
				'enabled'            => isset( $data['enabled'] ) ? (int) (bool) $data['enabled'] : 1,
				'revision'           => 1,
				'contact_count'      => 0,
				'content_updated_at' => $now,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'spb_db_error', __( 'The phonebook could not be saved because of a database error.', 'site-phonebooks' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update name/description/prompt. Name or prompt changes bump the revision
	 * (the XML changes) without touching the slug.
	 *
	 * @param int   $id   Phonebook ID.
	 * @param array $data {name, description, prompt}.
	 * @return true|\WP_Error
	 */
	public static function update_details( $id, array $data ) {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'spb_not_found', __( 'Phonebook not found.', 'site-phonebooks' ) );
		}
		$fields  = array();
		$formats = array();

		if ( array_key_exists( 'name', $data ) ) {
			$name = self::validate_name( $data['name'], $id );
			if ( is_wp_error( $name ) ) {
				return $name;
			}
			$fields['name'] = $name;
			$formats[]      = '%s';
		}
		if ( array_key_exists( 'prompt', $data ) ) {
			$prompt = self::validate_prompt( $data['prompt'] );
			if ( is_wp_error( $prompt ) ) {
				return $prompt;
			}
			$fields['prompt'] = $prompt;
			$formats[]        = '%s';
		}
		if ( array_key_exists( 'description', $data ) ) {
			$fields['description'] = sanitize_textarea_field( (string) $data['description'] );
			$formats[]             = '%s';
		}
		if ( empty( $fields ) ) {
			return true;
		}
		$fields['updated_at'] = Db::now();
		$formats[]            = '%s';

		$xml_changed = ( isset( $fields['name'] ) && $fields['name'] !== $current->name ) || ( isset( $fields['prompt'] ) && $fields['prompt'] !== $current->prompt );

		$result = $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			return new \WP_Error( 'spb_db_error', __( 'The phonebook could not be saved because of a database error.', 'site-phonebooks' ) );
		}
		if ( $xml_changed ) {
			self::bump_revision( $id );
		}
		return true;
	}

	/**
	 * Deliberately change the slug (feed URL).
	 *
	 * @param int    $id   Phonebook ID.
	 * @param string $slug Requested slug.
	 * @return string|\WP_Error The stored slug.
	 */
	public static function change_slug( $id, $slug ) {
		global $wpdb;
		$slug = strtolower( trim( (string) $slug ) );
		if ( ! Slug::is_valid( $slug ) ) {
			return new \WP_Error( 'spb_slug_invalid', __( 'The URL name may only contain lowercase letters, numbers and single dashes, and may not start or end with a dash.', 'site-phonebooks' ) );
		}
		if ( self::slug_exists( $slug, $id ) ) {
			/* translators: %s: slug */
			return new \WP_Error( 'spb_slug_duplicate', sprintf( __( 'The URL name "%s" is already used by another phonebook.', 'site-phonebooks' ), $slug ) );
		}
		$result = $wpdb->update(
			self::table(),
			array(
				'slug'       => $slug,
				'updated_at' => Db::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $result ) {
			return new \WP_Error( 'spb_db_error', __( 'The URL name could not be changed because of a database error.', 'site-phonebooks' ) );
		}
		Feed\Feed_Cache::delete( $id );
		return $slug;
	}

	/**
	 * Enable or disable the feed.
	 *
	 * @param int  $id      Phonebook ID.
	 * @param bool $enabled Enabled.
	 * @return bool
	 */
	public static function set_enabled( $id, $enabled ) {
		global $wpdb;
		$ok = $wpdb->update(
			self::table(),
			array(
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => Db::now(),
			),
			array( 'id' => (int) $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		Feed\Feed_Cache::delete( $id );
		return false !== $ok;
	}

	/**
	 * Set access mode.
	 *
	 * @param int    $id   Phonebook ID.
	 * @param string $mode token|public.
	 * @return bool
	 */
	public static function set_access_mode( $id, $mode ) {
		global $wpdb;
		$mode = 'public' === $mode ? 'public' : 'token';
		$ok   = $wpdb->update(
			self::table(),
			array(
				'access_mode' => $mode,
				'updated_at'  => Db::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		Feed\Feed_Cache::delete( $id );
		return false !== $ok;
	}

	/**
	 * Rotate the access token. Previously configured URLs stop working at once.
	 *
	 * @param int $id Phonebook ID.
	 * @return string|false New token.
	 */
	public static function rotate_token( $id ) {
		global $wpdb;
		$token = Token::generate();
		$ok    = $wpdb->update(
			self::table(),
			array(
				'access_token' => $token,
				'updated_at'   => Db::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		Feed\Feed_Cache::delete( $id );
		return false !== $ok ? $token : false;
	}

	/**
	 * Mark content as changed: new revision, fresh count, feed cache dropped.
	 *
	 * @param int $id Phonebook ID.
	 * @return int New revision (0 on failure).
	 */
	public static function bump_revision( $id ) {
		global $wpdb;
		$table    = self::table();
		$contacts = Contact_Repository::table();
		$now      = Db::now();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revision = revision + 1, contact_count = (SELECT COUNT(*) FROM {$contacts} WHERE phonebook_id = %d), content_updated_at = %s, updated_at = %s WHERE id = %d", (int) $id, $now, $now, (int) $id ) );
		Feed\Feed_Cache::delete( $id );
		$row = self::get( $id );
		return $row ? $row->revision : 0;
	}

	/**
	 * Record the most recent feed request (no contact data, no IP, no token).
	 *
	 * @param int    $id     Phonebook ID.
	 * @param int    $status HTTP status returned.
	 * @param string $agent  User agent (truncated).
	 */
	public static function record_request( $id, $status, $agent ) {
		global $wpdb;
		$agent = preg_replace( '/[^\x20-\x7E]/', '', (string) $agent );
		$agent = substr( (string) $agent, 0, 190 );
		$wpdb->update(
			self::table(),
			array(
				'last_request_at'     => Db::now(),
				'last_request_status' => (int) $status,
				'last_request_agent'  => $agent,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a phonebook and everything attached to it.
	 *
	 * @param int $id Phonebook ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		try {
			Db::transaction(
				static function () use ( $wpdb, $id ) {
					$wpdb->delete( Contact_Repository::table(), array( 'phonebook_id' => $id ), array( '%d' ) );
					Db::check( 'delete contacts' );
					$wpdb->delete( Revision_Repository::table(), array( 'phonebook_id' => $id ), array( '%d' ) );
					Db::check( 'delete revisions' );
					$wpdb->delete( Import\Import_Staging::table(), array( 'phonebook_id' => $id ), array( '%d' ) );
					Db::check( 'delete imports' );
					$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
					Db::check( 'delete phonebook' );
				}
			);
		} catch ( \Throwable $e ) {
			return false;
		}
		Feed\Feed_Cache::delete( $id );
		return true;
	}
}
