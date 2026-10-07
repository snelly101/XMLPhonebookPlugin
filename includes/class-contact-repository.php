<?php
/**
 * Contact storage.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for contacts. Every contact belongs to exactly one phonebook and all
 * queries are scoped by phonebook_id so one phonebook can never read or alter
 * another's rows.
 */
final class Contact_Repository {

	const BATCH_SIZE = 200;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'contacts' );
	}

	/**
	 * Cast a row.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row->id = (int) $row->id;
		if ( isset( $row->phonebook_id ) ) {
			$row->phonebook_id = (int) $row->phonebook_id;
		}
		return $row;
	}

	/**
	 * Get one contact scoped to a phonebook.
	 *
	 * @param int $id           Contact ID.
	 * @param int $phonebook_id Phonebook ID.
	 * @return object|null
	 */
	public static function get( $id, $phonebook_id ) {
		global $wpdb;
		$table = self::table();
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND phonebook_id = %d", (int) $id, (int) $phonebook_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count contacts in a phonebook.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @return int
	 */
	public static function count( $phonebook_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE phonebook_id = %d", (int) $phonebook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Paginated, searchable list in the default export order (name, telephone, id).
	 *
	 * @param int   $phonebook_id Phonebook ID.
	 * @param array $args         {search, page, per_page}.
	 * @return array{items:object[],total:int}
	 */
	public static function query( $phonebook_id, array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$args   = array_merge(
			array(
				'search'   => '',
				'page'     => 1,
				'per_page' => 50,
			),
			$args
		);
		$where  = 'phonebook_id = %d';
		$params = array( (int) $phonebook_id );
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (name LIKE %s OR telephone LIKE %s OR notes LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY name ASC, telephone ASC, id ASC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable
		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), $items ? $items : array() ),
			'total' => $total,
		);
	}

	/**
	 * All contacts of a phonebook in feed order. Used for XML/CSV export and snapshots.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @return object[]
	 */
	public static function all( $phonebook_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, telephone, notes FROM {$table} WHERE phonebook_id = %d ORDER BY name ASC, telephone ASC, id ASC", (int) $phonebook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Map of dedupe hash => contact id for a phonebook.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @return array<string,int>
	 */
	public static function hashes( $phonebook_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, dedupe_hash FROM {$table} WHERE phonebook_id = %d", (int) $phonebook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$map   = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$map[ $row->dedupe_hash ] = (int) $row->id;
		}
		return $map;
	}

	/**
	 * Create a contact from user input (validates, bumps revision).
	 *
	 * @param int   $phonebook_id Phonebook ID.
	 * @param array $data         {name, telephone, notes}.
	 * @return int|\WP_Error
	 */
	public static function create( $phonebook_id, array $data ) {
		global $wpdb;
		$v = Contact_Validator::validate(
			isset( $data['name'] ) ? $data['name'] : '',
			isset( $data['telephone'] ) ? $data['telephone'] : '',
			isset( $data['notes'] ) ? $data['notes'] : ''
		);
		if ( ! $v['valid'] ) {
			return new \WP_Error( 'spb_contact_invalid', implode( ' ', $v['errors'] ) );
		}
		$existing = self::hashes( $phonebook_id );
		if ( isset( $existing[ $v['hash'] ] ) ) {
			return new \WP_Error( 'spb_contact_duplicate', __( 'A contact with exactly this name and telephone already exists in this phonebook.', 'site-phonebooks' ) );
		}
		$now = Db::now();
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'phonebook_id' => (int) $phonebook_id,
				'name'         => $v['name'],
				'telephone'    => $v['telephone'],
				'notes'        => $v['notes'],
				'dedupe_hash'  => $v['hash'],
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new \WP_Error( 'spb_db_error', __( 'The contact could not be saved because of a database error.', 'site-phonebooks' ) );
		}
		$id = (int) $wpdb->insert_id;
		Phonebook_Repository::bump_revision( $phonebook_id );
		return $id;
	}

	/**
	 * Update a contact (validates, bumps revision).
	 *
	 * @param int   $id           Contact ID.
	 * @param int   $phonebook_id Phonebook ID.
	 * @param array $data         {name, telephone, notes}.
	 * @return true|\WP_Error
	 */
	public static function update( $id, $phonebook_id, array $data ) {
		global $wpdb;
		$current = self::get( $id, $phonebook_id );
		if ( ! $current ) {
			return new \WP_Error( 'spb_not_found', __( 'Contact not found.', 'site-phonebooks' ) );
		}
		$v = Contact_Validator::validate(
			isset( $data['name'] ) ? $data['name'] : $current->name,
			isset( $data['telephone'] ) ? $data['telephone'] : $current->telephone,
			isset( $data['notes'] ) ? $data['notes'] : $current->notes
		);
		if ( ! $v['valid'] ) {
			return new \WP_Error( 'spb_contact_invalid', implode( ' ', $v['errors'] ) );
		}
		$existing = self::hashes( $phonebook_id );
		if ( isset( $existing[ $v['hash'] ] ) && $existing[ $v['hash'] ] !== (int) $id ) {
			return new \WP_Error( 'spb_contact_duplicate', __( 'Another contact with exactly this name and telephone already exists in this phonebook.', 'site-phonebooks' ) );
		}
		$ok = $wpdb->update(
			self::table(),
			array(
				'name'        => $v['name'],
				'telephone'   => $v['telephone'],
				'notes'       => $v['notes'],
				'dedupe_hash' => $v['hash'],
				'updated_at'  => Db::now(),
			),
			array(
				'id'           => (int) $id,
				'phonebook_id' => (int) $phonebook_id,
			),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%d' )
		);
		if ( false === $ok ) {
			return new \WP_Error( 'spb_db_error', __( 'The contact could not be saved because of a database error.', 'site-phonebooks' ) );
		}
		if ( $v['name'] !== $current->name || $v['telephone'] !== $current->telephone ) {
			Phonebook_Repository::bump_revision( $phonebook_id );
		}
		return true;
	}

	/**
	 * Delete specific contacts (scoped) and bump revision.
	 *
	 * @param int   $phonebook_id Phonebook ID.
	 * @param int[] $ids          Contact IDs.
	 * @return int Number deleted.
	 */
	public static function delete_many( $phonebook_id, array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE phonebook_id = %d AND id IN ({$placeholders})", array_merge( array( (int) $phonebook_id ), $ids ) ) );
		if ( $deleted > 0 ) {
			Phonebook_Repository::bump_revision( $phonebook_id );
		}
		return $deleted;
	}

	/**
	 * Delete every contact in a phonebook. Does not bump the revision (callers
	 * do that inside their transaction).
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @return int|false Rows deleted.
	 */
	public static function delete_all_raw( $phonebook_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE phonebook_id = %d", (int) $phonebook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete contacts by dedupe hash (used by Replace imports). No revision bump.
	 *
	 * @param int      $phonebook_id Phonebook ID.
	 * @param string[] $hashes       Hashes.
	 * @return int Rows deleted.
	 */
	public static function delete_by_hashes_raw( $phonebook_id, array $hashes ) {
		global $wpdb;
		$table   = self::table();
		$deleted = 0;
		foreach ( array_chunk( array_values( $hashes ), self::BATCH_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE phonebook_id = %d AND dedupe_hash IN ({$placeholders})", array_merge( array( (int) $phonebook_id ), $chunk ) ) );
			Db::check( 'delete contacts' );
			$deleted += (int) $result;
		}
		return $deleted;
	}

	/**
	 * Insert already-validated rows in batches. No revision bump; callers run
	 * this inside a transaction and bump afterwards.
	 *
	 * @param int   $phonebook_id Phonebook ID.
	 * @param array $rows         Rows with name, telephone, notes (hash optional).
	 * @return int Rows inserted.
	 * @throws \RuntimeException On database error.
	 */
	public static function insert_batch_raw( $phonebook_id, array $rows ) {
		global $wpdb;
		$table    = self::table();
		$now      = Db::now();
		$inserted = 0;
		foreach ( array_chunk( $rows, self::BATCH_SIZE ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $row ) {
				$row       = (array) $row;
				$name      = (string) $row['name'];
				$telephone = (string) $row['telephone'];
				$values[]  = '(%d,%s,%s,%s,%s,%s,%s)';
				$params[]  = (int) $phonebook_id;
				$params[]  = $name;
				$params[]  = $telephone;
				$params[]  = isset( $row['notes'] ) ? (string) $row['notes'] : '';
				$params[]  = isset( $row['hash'] ) && 40 === strlen( (string) $row['hash'] ) ? $row['hash'] : Contact_Validator::dedupe_hash( $name, $telephone );
				$params[]  = $now;
				$params[]  = $now;
			}
			$sql = "INSERT INTO {$table} (phonebook_id,name,telephone,notes,dedupe_hash,created_at,updated_at) VALUES " . implode( ',', $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $wpdb->prepare( $sql, $params ) );
			Db::check( 'insert contacts' );
			if ( false === $result ) {
				throw new \RuntimeException( 'insert contacts failed' );
			}
			$inserted += (int) $result;
		}
		return $inserted;
	}
}
