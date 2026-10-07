<?php
/**
 * Staged import storage.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Import;

use SitePhonebooks\Db;
use SitePhonebooks\Installer;
use SitePhonebooks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Uploaded files and preview plans are staged in a database table (never in a
 * web-accessible directory), scoped to the uploading user and phonebook, and
 * deleted after commit, cancellation or expiry.
 */
final class Import_Staging {

	const STATUS_UPLOADED  = 'uploaded';
	const STATUS_PREVIEW   = 'preview';
	const STATUS_COMMITTED = 'committed';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'imports' );
	}

	/**
	 * Create a staging record for an uploaded file.
	 *
	 * @param int    $phonebook_id Phonebook ID.
	 * @param int    $user_id      Uploading user.
	 * @param string $source_type  csv|xml.
	 * @param string $filename     Original filename (display only).
	 * @param string $raw          File bytes.
	 * @param array  $options      Initial options.
	 * @return int|\WP_Error Staging ID.
	 */
	public static function create( $phonebook_id, $user_id, $source_type, $filename, $raw, array $options = array() ) {
		global $wpdb;
		$ttl = (int) Settings::get( 'staging_ttl_minutes' );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'phonebook_id'  => (int) $phonebook_id,
				'user_id'       => (int) $user_id,
				'status'        => self::STATUS_UPLOADED,
				'source_type'   => 'xml' === $source_type ? 'xml' : 'csv',
				'filename'      => substr( sanitize_file_name( (string) $filename ), 0, 190 ),
				'raw_content'   => $raw,
				'options'       => wp_json_encode( $options ),
				'base_revision' => 0,
				'plan'          => null,
				'created_at'    => Db::now(),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + $ttl * MINUTE_IN_SECONDS ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new \WP_Error( 'spb_db_error', __( 'The upload could not be staged because of a database error.', 'site-phonebooks' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Load a staging record scoped to phonebook and user. Expired records are
	 * treated as missing.
	 *
	 * @param int  $id           Staging ID.
	 * @param int  $phonebook_id Phonebook ID.
	 * @param int  $user_id      User ID.
	 * @param bool $with_raw     Include raw content.
	 * @return object|null
	 */
	public static function get( $id, $phonebook_id, $user_id, $with_raw = false ) {
		global $wpdb;
		$table   = self::table();
		$columns = $with_raw ? '*' : 'id, phonebook_id, user_id, status, source_type, filename, options, base_revision, plan, created_at, expires_at';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE id = %d AND phonebook_id = %d AND user_id = %d AND expires_at > %s", (int) $id, (int) $phonebook_id, (int) $user_id, Db::now() ) );
		return self::hydrate( $row );
	}

	/**
	 * Open (not committed, not expired) staged imports for a user on a phonebook.
	 *
	 * @param int $phonebook_id Phonebook ID.
	 * @param int $user_id      User ID.
	 * @return object[]
	 */
	public static function open_for( $phonebook_id, $user_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, phonebook_id, user_id, status, source_type, filename, options, base_revision, created_at, expires_at FROM {$table} WHERE phonebook_id = %d AND user_id = %d AND status <> %s AND expires_at > %s ORDER BY id DESC", (int) $phonebook_id, (int) $user_id, self::STATUS_COMMITTED, Db::now() ) );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param object|null $row Row.
	 * @return object|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row->id            = (int) $row->id;
		$row->phonebook_id  = (int) $row->phonebook_id;
		$row->user_id       = (int) $row->user_id;
		$row->base_revision = (int) $row->base_revision;
		$row->options       = json_decode( (string) $row->options, true );
		if ( ! is_array( $row->options ) ) {
			$row->options = array();
		}
		if ( property_exists( $row, 'plan' ) ) {
			$row->plan = null === $row->plan ? null : json_decode( (string) $row->plan, true );
			if ( ! is_array( $row->plan ) ) {
				$row->plan = null;
			}
		}
		return $row;
	}

	/**
	 * Save options (mapping choices).
	 *
	 * @param int   $id      Staging ID.
	 * @param array $options Options.
	 */
	public static function save_options( $id, array $options ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'options' => wp_json_encode( $options ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Save the computed plan and move to preview state.
	 *
	 * @param int   $id            Staging ID.
	 * @param array $options       Options.
	 * @param array $plan          Plan.
	 * @param int   $base_revision Phonebook revision the plan was computed against.
	 * @return bool
	 */
	public static function save_plan( $id, array $options, array $plan, $base_revision ) {
		global $wpdb;
		$ok = $wpdb->update(
			self::table(),
			array(
				'options'       => wp_json_encode( $options ),
				'plan'          => wp_json_encode( $plan, JSON_UNESCAPED_UNICODE ),
				'base_revision' => (int) $base_revision,
				'status'        => self::STATUS_PREVIEW,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
		return false !== $ok;
	}

	/**
	 * Delete a staging record.
	 *
	 * @param int $id Staging ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete expired or committed records (cron and opportunistic).
	 *
	 * @return int Rows removed.
	 */
	public static function cleanup_expired() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %s OR status = %s", Db::now(), self::STATUS_COMMITTED ) );
	}

	/**
	 * Maximum upload size in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		return (int) Settings::get( 'import_max_file_kb' ) * 1024;
	}
}
