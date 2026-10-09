<?php
/**
 * Bounded contact snapshots ("revisions") for recovery.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a JSON snapshot of a phonebook's contacts before destructive
 * operations, with per-phonebook retention, preview and restore.
 */
final class Revision_Repository {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'revisions' );
	}

	/**
	 * Human labels for snapshot reasons.
	 *
	 * @return array<string,string>
	 */
	public static function reason_labels() {
		return array(
			'replace_import' => __( 'Before replace import', 'site-phonebooks' ),
			'bulk_delete'    => __( 'Before bulk delete', 'site-phonebooks' ),
			'clear'          => __( 'Before clearing phonebook', 'site-phonebooks' ),
			'restore'        => __( 'Before restoring a snapshot', 'site-phonebooks' ),
			'manual'         => __( 'Manual snapshot', 'site-phonebooks' ),
		);
	}

	/**
	 * Create a snapshot of the current contacts. Safe to call inside a transaction.
	 *
	 * @param int    $phonebook_id Phonebook ID.
	 * @param string $reason       Reason key.
	 * @param int    $user_id      Acting user.
	 * @return int Snapshot ID.
	 * @throws \RuntimeException On database error.
	 */
	public static function snapshot( $phonebook_id, $reason, $user_id = 0 ) {
		global $wpdb;
		$phonebook = Phonebook_Repository::get( $phonebook_id );
		$contacts  = Contact_Repository::all( $phonebook_id );
		$payload   = array();
		foreach ( $contacts as $contact ) {
			$payload[] = array(
				'name'      => $contact->name,
				'telephone' => $contact->telephone,
				'notes'     => $contact->notes,
			);
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'phonebook_id'  => (int) $phonebook_id,
				'revision'      => $phonebook ? $phonebook->revision : 0,
				'reason'        => substr( (string) $reason, 0, 40 ),
				'contact_count' => count( $payload ),
				'payload'       => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'created_by'    => (int) $user_id,
				'created_at'    => Db::now(),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%d', '%s' )
		);
		Db::check( 'create snapshot' );
		if ( false === $ok ) {
			throw new \RuntimeException( 'snapshot insert failed' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * List snapshots (without payload) newest first.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @return object[]
	 */
	public static function list_for( $phonebook_id ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, phonebook_id, revision, reason, contact_count, created_by, created_at FROM {$table} WHERE phonebook_id = %d ORDER BY id DESC", (int) $phonebook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $rows ? $rows : array();
	}

	/**
	 * Get one snapshot including decoded payload, scoped to a phonebook.
	 *
	 * @param int $id           Snapshot ID.
	 * @param int $phonebook_id Phonebook ID.
	 * @return object|null
	 */
	public static function get( $id, $phonebook_id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND phonebook_id = %d", (int) $id, (int) $phonebook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		$row->id       = (int) $row->id;
		$row->contacts = json_decode( (string) $row->payload, true );
		if ( ! is_array( $row->contacts ) ) {
			$row->contacts = array();
		}
		unset( $row->payload );
		return $row;
	}

	/**
	 * Delete a snapshot.
	 *
	 * @param int $id           Snapshot ID.
	 * @param int $phonebook_id Phonebook ID.
	 * @return bool
	 */
	public static function delete( $id, $phonebook_id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table(),
			array(
				'id'           => (int) $id,
				'phonebook_id' => (int) $phonebook_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Keep only the newest N snapshots for a phonebook.
	 *
	 * @param int      $phonebook_id Phonebook ID.
	 * @param int|null $keep         Number to keep (defaults to setting).
	 * @return int Number removed.
	 */
	public static function prune( $phonebook_id, $keep = null ) {
		global $wpdb;
		$keep  = null === $keep ? (int) Settings::get( 'revision_retention' ) : (int) $keep;
		$table = self::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE phonebook_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", (int) $phonebook_id, 1000, max( 0, $keep ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $ids ) ) {
			return 0;
		}
		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE phonebook_id = %d AND id IN ({$placeholders})", array_merge( array( (int) $phonebook_id ), $ids ) ) );
	}

	/**
	 * Restore a snapshot: snapshots the current list first, replaces all
	 * contacts atomically and publishes a new revision.
	 *
	 * @param int $id           Snapshot ID.
	 * @param int $phonebook_id Phonebook ID.
	 * @param int $user_id      Acting user.
	 * @return true|\WP_Error
	 */
	public static function restore( $id, $phonebook_id, $user_id = 0 ) {
		$snapshot = self::get( $id, $phonebook_id );
		if ( ! $snapshot ) {
			return new \WP_Error( 'spb_not_found', __( 'Snapshot not found.', 'site-phonebooks' ) );
		}
		$rows = array();
		$seen = array();
		foreach ( $snapshot->contacts as $contact ) {
			$v = Contact_Validator::validate(
				isset( $contact['name'] ) ? $contact['name'] : '',
				isset( $contact['telephone'] ) ? $contact['telephone'] : '',
				isset( $contact['notes'] ) ? $contact['notes'] : ''
			);
			if ( ! $v['valid'] || isset( $seen[ $v['hash'] ] ) ) {
				continue;
			}
			$seen[ $v['hash'] ] = true;
			$rows[]             = $v;
		}
		try {
			Db::transaction(
				static function () use ( $phonebook_id, $rows, $user_id ) {
					self::lock_phonebook( $phonebook_id );
					self::snapshot( $phonebook_id, 'restore', $user_id );
					Contact_Repository::delete_all_raw( $phonebook_id );
					Db::check( 'clear contacts' );
					Contact_Repository::insert_batch_raw( $phonebook_id, $rows );
					Phonebook_Repository::bump_revision( $phonebook_id );
					Db::check( 'bump revision' );
				}
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'spb_restore_failed', __( 'The snapshot could not be restored; the phonebook was left unchanged.', 'site-phonebooks' ) );
		}
		Feed\Feed_Cache::delete( $phonebook_id );
		self::prune( $phonebook_id );
		return true;
	}

	/**
	 * Lock a phonebook row for the duration of the current transaction.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @return object|null Locked row.
	 */
	public static function lock_phonebook( $phonebook_id ) {
		global $wpdb;
		$table = Phonebook_Repository::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", (int) $phonebook_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Db::check( 'lock phonebook' );
		return $row;
	}
}
