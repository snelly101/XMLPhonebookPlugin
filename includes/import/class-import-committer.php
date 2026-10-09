<?php
/**
 * Atomic import commitment.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Import;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Db;
use SitePhonebooks\Feed\Feed_Cache;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Revision_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a previewed plan exactly once inside a database transaction. Any
 * failure rolls back and leaves the previous phonebook and feed intact.
 */
final class Import_Committer {

	/**
	 * Commit a staged import.
	 *
	 * @param int  $staging_id   Staging ID.
	 * @param int  $phonebook_id Phonebook ID.
	 * @param int  $user_id      Acting user.
	 * @param bool $confirmed    Whether the user confirmed a replace (if applicable).
	 * @return array|\WP_Error Result summary.
	 */
	public static function commit( $staging_id, $phonebook_id, $user_id, $confirmed = false ) {
		global $wpdb;

		$staging = Import_Staging::get( $staging_id, $phonebook_id, $user_id );
		if ( ! $staging ) {
			return new \WP_Error( 'spb_import_missing', __( 'This import is no longer available. It may have expired or already been applied. Upload the file again.', 'site-phonebooks' ) );
		}
		if ( Import_Staging::STATUS_PREVIEW !== $staging->status || ! is_array( $staging->plan ) ) {
			return new \WP_Error( 'spb_import_not_previewed', __( 'Preview the import before applying it.', 'site-phonebooks' ) );
		}
		$plan = $staging->plan;
		if ( ! empty( $plan['blocking'] ) ) {
			return new \WP_Error( 'spb_import_blocked', implode( ' ', $plan['blocking'] ) );
		}
		if ( Import_Planner::MODE_REPLACE === $plan['mode'] && ! $confirmed ) {
			return new \WP_Error( 'spb_import_unconfirmed', __( 'Tick the confirmation box to acknowledge that existing contacts will be removed.', 'site-phonebooks' ) );
		}

		$rows = array();
		foreach ( $plan['rows'] as $row ) {
			if ( Import_Planner::STATUS_ADD === $row['status'] ) {
				$rows[] = $row;
			}
		}

		$table = Import_Staging::table();
		try {
			$result = Db::transaction(
				static function () use ( $wpdb, $table, $staging, $plan, $rows, $phonebook_id, $user_id ) {
					// Serialise commits for this phonebook and claim this import exactly once.
					$locked_pb = Revision_Repository::lock_phonebook( $phonebook_id );
					if ( ! $locked_pb ) {
						throw new Import_Exception( __( 'Phonebook not found.', 'site-phonebooks' ) );
					}
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$locked = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, base_revision FROM {$table} WHERE id = %d FOR UPDATE", $staging->id ) );
					Db::check( 'lock import' );
					if ( ! $locked || Import_Staging::STATUS_PREVIEW !== $locked->status ) {
						throw new Import_Exception( __( 'This import has already been applied.', 'site-phonebooks' ) );
					}
					if ( (int) $locked_pb->revision !== (int) $locked->base_revision ) {
						throw new Import_Exception( __( 'The phonebook was changed after this preview was generated. Review a fresh preview before applying the import.', 'site-phonebooks' ) );
					}

					$snapshot_id = 0;
					$removed     = 0;
					if ( Import_Planner::MODE_REPLACE === $plan['mode'] ) {
						$snapshot_id = Revision_Repository::snapshot( $phonebook_id, 'replace_import', $user_id );
						if ( ! empty( $plan['remove_hashes'] ) ) {
							$removed = Contact_Repository::delete_by_hashes_raw( $phonebook_id, $plan['remove_hashes'] );
						}
					}
					$added = Contact_Repository::insert_batch_raw( $phonebook_id, $rows );

					if ( ! empty( $plan['rename'] ) ) {
						$renamed = Phonebook_Repository::update_details( $phonebook_id, array( 'name' => $plan['rename'] ) );
						if ( is_wp_error( $renamed ) ) {
							throw new Import_Exception( $renamed->get_error_message() );
						}
					}

					$revision = Phonebook_Repository::bump_revision( $phonebook_id );
					Db::check( 'bump revision' );

					$wpdb->update( $table, array( 'status' => Import_Staging::STATUS_COMMITTED ), array( 'id' => $staging->id ), array( '%s' ), array( '%d' ) );
					Db::check( 'mark import committed' );

					return array(
						'added'       => (int) $added,
						'removed'     => (int) $removed,
						'snapshot_id' => (int) $snapshot_id,
						'revision'    => (int) $revision,
						'mode'        => $plan['mode'],
					);
				}
			);
		} catch ( Import_Exception $e ) {
			return new \WP_Error( 'spb_import_failed', $e->getMessage() );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'spb_import_failed', __( 'The import failed because of a database error and was rolled back. The phonebook was left unchanged.', 'site-phonebooks' ) );
		}

		Feed_Cache::delete( $phonebook_id );
		Import_Staging::delete( $staging->id );
		Revision_Repository::prune( $phonebook_id );
		return $result;
	}
}
