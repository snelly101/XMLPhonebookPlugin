<?php
/**
 * admin-post.php handlers for every state-changing admin action.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Csv\Csv_Exporter;
use SitePhonebooks\Csv\Csv_Parser;
use SitePhonebooks\Db;
use SitePhonebooks\Diagnostics;
use SitePhonebooks\Feed\Feed_Cache;
use SitePhonebooks\Feed\Feed_Controller;
use SitePhonebooks\Import\Import_Committer;
use SitePhonebooks\Import\Import_Planner;
use SitePhonebooks\Import\Import_Staging;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Revision_Repository;
use SitePhonebooks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every handler: capability check, nonce check, phonebook ownership check,
 * action, flash notice, redirect. State never changes on GET (exports and the
 * template download only read).
 */
final class Actions {

	/**
	 * Register handlers.
	 */
	public function register() {
		$actions = array(
			'spb_create_phonebook',
			'spb_update_phonebook',
			'spb_change_slug',
			'spb_delete_phonebook',
			'spb_feed_settings',
			'spb_rotate_token',
			'spb_save_contact',
			'spb_delete_contact',
			'spb_bulk_contacts',
			'spb_bulk_contacts_confirm',
			'spb_clear_contacts',
			'spb_import_upload',
			'spb_import_map',
			'spb_import_commit',
			'spb_import_cancel',
			'spb_export',
			'spb_template',
			'spb_snapshot_create',
			'spb_snapshot_restore',
			'spb_snapshot_delete',
			'spb_self_test',
		);
		foreach ( $actions as $action ) {
			add_action( 'admin_post_' . $action, array( $this, substr( $action, 4 ) ) );
		}
	}

	/**
	 * Common guard: capability + nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function guard( $nonce_action ) {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Load the phonebook named in the request or die.
	 *
	 * @param string $nonce_prefix Nonce prefix (phonebook id is appended).
	 * @param string $method       post|get.
	 * @return object
	 */
	private function phonebook( $nonce_prefix, $method = 'post' ) {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$id = 'get' === $method ? ( isset( $_GET['phonebook_id'] ) ? absint( $_GET['phonebook_id'] ) : 0 ) : ( isset( $_POST['phonebook_id'] ) ? absint( $_POST['phonebook_id'] ) : 0 );
		// phpcs:enable
		check_admin_referer( $nonce_prefix . $id );
		$phonebook = $id ? Phonebook_Repository::get( $id ) : null;
		if ( ! $phonebook ) {
			wp_die( esc_html__( 'Phonebook not found.', 'site-phonebooks' ), 404 );
		}
		return $phonebook;
	}

	/**
	 * Read a POST text field.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function post_text( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url URL.
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Create phonebook.
	 */
	public function create_phonebook() {
		$this->guard( 'spb_create_phonebook' );
		$id = Phonebook_Repository::create(
			array(
				'name'        => $this->post_text( 'name' ),
				'description' => $this->post_text( 'description' ),
				'access_mode' => $this->post_text( 'access_mode' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			Notices::add_error( $id );
			$this->redirect( Admin::url( Admin::PAGE_EDIT ) );
		}
		$phonebook = Phonebook_Repository::get( $id );
		/* translators: %s: feed file name */
		Notices::add( 'success', sprintf( __( 'Phonebook created. Its feed file is %s. Add contacts or import a file, then copy the feed URL into your handsets.', 'site-phonebooks' ), $phonebook->slug . '.xml' ) );
		$this->redirect( Admin::phonebook_url( $id ) );
	}

	/**
	 * Update name/description.
	 */
	public function update_phonebook() {
		$phonebook = $this->phonebook( 'spb_update_phonebook_' );
		$result    = Phonebook_Repository::update_details(
			$phonebook->id,
			array(
				'name'        => $this->post_text( 'name' ),
				'description' => $this->post_text( 'description' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			Notices::add_error( $result );
		} else {
			Notices::add( 'success', __( 'Details saved. The feed address is unchanged.', 'site-phonebooks' ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'settings' ) );
	}

	/**
	 * Change slug.
	 */
	public function change_slug() {
		$phonebook = $this->phonebook( 'spb_change_slug_' );
		if ( '1' !== $this->post_text( 'confirm' ) ) {
			Notices::add( 'error', __( 'Tick the confirmation box to change the feed file name.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id, 'settings' ) );
		}
		$slug = $this->post_text( 'slug' );
		if ( $slug === $phonebook->slug ) {
			Notices::add( 'info', __( 'The file name is unchanged.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id, 'settings' ) );
		}
		$result = Phonebook_Repository::change_slug( $phonebook->id, $slug );
		if ( is_wp_error( $result ) ) {
			Notices::add_error( $result );
		} else {
			/* translators: 1: old file, 2: new file */
			Notices::add( 'warning', sprintf( __( 'Feed file renamed from %1$s to %2$s. Update every handset that uses the old address.', 'site-phonebooks' ), $phonebook->slug . '.xml', $result . '.xml' ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'feed' ) );
	}

	/**
	 * Delete phonebook.
	 */
	public function delete_phonebook() {
		$phonebook = $this->phonebook( 'spb_delete_phonebook_' );
		if ( trim( $this->post_text( 'confirm_name' ) ) !== $phonebook->name ) {
			Notices::add( 'error', __( 'The site name you typed does not match. The phonebook was not deleted.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id, 'settings' ) );
		}
		if ( Phonebook_Repository::delete( $phonebook->id ) ) {
			/* translators: %s: site name */
			Notices::add( 'success', sprintf( __( 'Phonebook "%s" deleted.', 'site-phonebooks' ), $phonebook->name ) );
			$this->redirect( Admin::url() );
		}
		Notices::add( 'error', __( 'The phonebook could not be deleted.', 'site-phonebooks' ) );
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'settings' ) );
	}

	/**
	 * Access mode, prompt, enabled.
	 */
	public function feed_settings() {
		$phonebook = $this->phonebook( 'spb_feed_settings_' );
		$mode      = 'public' === $this->post_text( 'access_mode' ) ? 'public' : 'token';
		$enabled   = '1' === $this->post_text( 'enabled' );
		$prompt    = Phonebook_Repository::update_details( $phonebook->id, array( 'prompt' => $this->post_text( 'prompt' ) ) );
		if ( is_wp_error( $prompt ) ) {
			Notices::add_error( $prompt );
		}
		if ( $mode !== $phonebook->access_mode ) {
			Phonebook_Repository::set_access_mode( $phonebook->id, $mode );
			Notices::add( 'warning', 'public' === $mode ? __( 'The feed is now public: anyone who knows the address can read it. The key is no longer required.', 'site-phonebooks' ) : __( 'The feed now requires its key. Update handsets that were using the public address.', 'site-phonebooks' ) );
		}
		if ( $enabled !== $phonebook->enabled ) {
			Phonebook_Repository::set_enabled( $phonebook->id, $enabled );
			Notices::add( $enabled ? 'success' : 'warning', $enabled ? __( 'Feed enabled.', 'site-phonebooks' ) : __( 'Feed disabled. Requests now receive 404 and no contact data.', 'site-phonebooks' ) );
		}
		if ( ! is_wp_error( $prompt ) ) {
			Notices::add( 'success', __( 'Access settings saved.', 'site-phonebooks' ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'feed' ) );
	}

	/**
	 * Rotate token.
	 */
	public function rotate_token() {
		$phonebook = $this->phonebook( 'spb_rotate_token_' );
		if ( Phonebook_Repository::rotate_token( $phonebook->id ) ) {
			Notices::add( 'warning', __( 'A new key was generated. The previous feed address no longer works; copy the new address into every handset.', 'site-phonebooks' ) );
		} else {
			Notices::add( 'error', __( 'The key could not be rotated.', 'site-phonebooks' ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'feed' ) );
	}

	/**
	 * Add or edit a contact.
	 */
	public function save_contact() {
		$phonebook  = $this->phonebook( 'spb_save_contact_' );
		$contact_id = absint( $this->post_text( 'contact_id' ) );
		$data       = array(
			'name'      => $this->post_text( 'name' ),
			'telephone' => $this->post_text( 'telephone' ),
			'notes'     => $this->post_text( 'notes' ),
		);
		if ( $contact_id ) {
			$result = Contact_Repository::update( $contact_id, $phonebook->id, $data );
			$ok     = __( 'Contact saved. The feed now includes the change.', 'site-phonebooks' );
		} else {
			$result = Contact_Repository::create( $phonebook->id, $data );
			/* translators: %s: contact name */
			$ok = sprintf( __( 'Contact "%s" added.', 'site-phonebooks' ), \SitePhonebooks\Contact_Validator::normalize_name( $data['name'] ) );
		}
		if ( is_wp_error( $result ) ) {
			Notices::add_error( $result );
			set_transient( 'spb_form_' . get_current_user_id(), array_merge( $data, array( 'contact_id' => $contact_id ) ), MINUTE_IN_SECONDS );
			$this->redirect( Admin::phonebook_url( $phonebook->id, 'contacts', $contact_id ? array( 'contact' => $contact_id ) : array() ) );
		}
		Notices::add( 'success', $ok );
		$this->redirect( Admin::phonebook_url( $phonebook->id ) );
	}

	/**
	 * Delete a single contact.
	 */
	public function delete_contact() {
		$phonebook = $this->phonebook( 'spb_delete_contact_' );
		$deleted   = Contact_Repository::delete_many( $phonebook->id, array( absint( $this->post_text( 'contact_id' ) ) ) );
		Notices::add( $deleted ? 'success' : 'error', $deleted ? __( 'Contact deleted.', 'site-phonebooks' ) : __( 'Contact not found.', 'site-phonebooks' ) );
		$this->redirect( Admin::phonebook_url( $phonebook->id ) );
	}

	/**
	 * Bulk action: stash selection and ask for confirmation.
	 */
	public function bulk_contacts() {
		$phonebook = $this->phonebook( 'spb_bulk_contacts_' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids = isset( $_POST['contact_ids'] ) && is_array( $_POST['contact_ids'] ) ? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['contact_ids'] ) ) ) ) : array();
		if ( 'delete' !== $this->post_text( 'bulk_action' ) || empty( $ids ) ) {
			Notices::add( 'info', __( 'Select contacts and the "Delete selected" action first.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id ) );
		}
		set_transient(
			'spb_pending_delete_' . get_current_user_id(),
			array(
				'phonebook_id' => $phonebook->id,
				'ids'          => array_slice( $ids, 0, 500 ),
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'contacts', array( 'pending' => 'delete' ) ) );
	}

	/**
	 * Confirmed bulk delete (snapshot first).
	 */
	public function bulk_contacts_confirm() {
		$phonebook = $this->phonebook( 'spb_bulk_contacts_confirm_' );
		$key       = 'spb_pending_delete_' . get_current_user_id();
		$pending   = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $pending ) || (int) $pending['phonebook_id'] !== $phonebook->id || empty( $pending['ids'] ) ) {
			Notices::add( 'error', __( 'Nothing to delete: the selection expired. Select the contacts again.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id ) );
		}
		try {
			$deleted = Db::transaction(
				static function () use ( $phonebook, $pending ) {
					Revision_Repository::lock_phonebook( $phonebook->id );
					Revision_Repository::snapshot( $phonebook->id, 'bulk_delete', get_current_user_id() );
					$count = Contact_Repository::delete_many( $phonebook->id, $pending['ids'] );
					Db::check( 'bulk delete' );
					return $count;
				}
			);
		} catch ( \Throwable $e ) {
			Notices::add( 'error', __( 'The contacts could not be deleted; nothing was changed.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id ) );
		}
		Revision_Repository::prune( $phonebook->id );
		/* translators: %s: number deleted */
		Notices::add( 'success', sprintf( _n( '%s contact deleted. A snapshot was saved first.', '%s contacts deleted. A snapshot was saved first.', $deleted, 'site-phonebooks' ), number_format_i18n( $deleted ) ) );
		$this->redirect( Admin::phonebook_url( $phonebook->id ) );
	}

	/**
	 * Clear all contacts (snapshot first).
	 */
	public function clear_contacts() {
		$phonebook = $this->phonebook( 'spb_clear_contacts_' );
		if ( '1' !== $this->post_text( 'confirm' ) ) {
			Notices::add( 'error', __( 'Tick the confirmation box to clear the phonebook.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id ) );
		}
		try {
			Db::transaction(
				static function () use ( $phonebook ) {
					Revision_Repository::lock_phonebook( $phonebook->id );
					Revision_Repository::snapshot( $phonebook->id, 'clear', get_current_user_id() );
					Contact_Repository::delete_all_raw( $phonebook->id );
					Db::check( 'clear' );
					Phonebook_Repository::bump_revision( $phonebook->id );
					Db::check( 'bump' );
				}
			);
		} catch ( \Throwable $e ) {
			Notices::add( 'error', __( 'The phonebook could not be cleared; nothing was changed.', 'site-phonebooks' ) );
			$this->redirect( Admin::phonebook_url( $phonebook->id ) );
		}
		Feed_Cache::delete( $phonebook->id );
		Revision_Repository::prune( $phonebook->id );
		Notices::add( 'success', __( 'All contacts removed. The feed is now empty. Restore the previous list from Export & snapshots if needed.', 'site-phonebooks' ) );
		$this->redirect( Admin::phonebook_url( $phonebook->id ) );
	}

	/**
	 * Step 1: receive an upload.
	 */
	public function import_upload() {
		$phonebook = $this->phonebook( 'spb_import_upload_' );
		$back      = Admin::phonebook_url( $phonebook->id, 'import' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$file = isset( $_FILES['import_file'] ) ? $_FILES['import_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			Notices::add( 'error', __( 'Choose a file to upload.', 'site-phonebooks' ) );
			$this->redirect( $back );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			Notices::add( 'error', in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? __( 'The file is larger than the upload limit.', 'site-phonebooks' ) : __( 'The upload failed. Try again.', 'site-phonebooks' ) );
			$this->redirect( $back );
		}
		$max = Import_Staging::max_bytes();
		if ( (int) $file['size'] > $max || ! is_uploaded_file( $file['tmp_name'] ) ) {
			/* translators: %s: size */
			Notices::add( 'error', sprintf( __( 'The file is larger than the %s limit set in Phonebooks > Settings.', 'site-phonebooks' ), size_format( $max ) ) );
			$this->redirect( $back );
		}
		$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $file['tmp_name'] );
		if ( false === $raw || '' === trim( $raw ) ) {
			Notices::add( 'error', __( 'The uploaded file is empty.', 'site-phonebooks' ) );
			$this->redirect( $back );
		}
		$filename = sanitize_file_name( (string) $file['name'] );
		$probe    = ltrim( substr( $raw, 0, 512 ), "\xEF\xBB\xBF \t\r\n" );
		$is_xml   = 'xml' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) || '<' === substr( $probe, 0, 1 );

		$options = array();
		if ( $is_xml ) {
			$parsed = \SitePhonebooks\Xml\Yealink_Xml_Parser::parse( $raw, (int) Settings::get( 'import_max_rows' ) );
			if ( ! $parsed['ok'] ) {
				Notices::add( 'error', $parsed['error'] );
				$this->redirect( $back );
			}
			$options = array(
				'title'  => $parsed['title'],
				'prompt' => $parsed['prompt'],
			);
		} else {
			$decoded = Csv_Parser::to_utf8( $raw );
			if ( $decoded['ok'] ) {
				$options['delimiter_detected'] = Csv_Parser::detect_delimiter( $decoded['text'] );
			} else {
				Notices::add( 'warning', $decoded['error'] );
			}
		}
		Import_Staging::cleanup_expired();
		$id = Import_Staging::create( $phonebook->id, get_current_user_id(), $is_xml ? 'xml' : 'csv', $filename, $raw, $options );
		if ( is_wp_error( $id ) ) {
			Notices::add_error( $id );
			$this->redirect( $back );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'import', array( 'import' => $id ) ) );
	}

	/**
	 * Step 2: save options and build the plan.
	 */
	public function import_map() {
		$phonebook = $this->phonebook( 'spb_import_map_' );
		$import_id = absint( $this->post_text( 'import_id' ) );
		$staging   = Import_Staging::get( $import_id, $phonebook->id, get_current_user_id(), true );
		$back      = Admin::phonebook_url( $phonebook->id, 'import' );
		if ( ! $staging ) {
			Notices::add( 'error', __( 'That staged import is no longer available. Upload the file again.', 'site-phonebooks' ) );
			$this->redirect( $back );
		}
		$options = array(
			'mode'       => 'replace' === $this->post_text( 'mode' ) ? 'replace' : 'merge',
			'valid_only' => '1' === $this->post_text( 'valid_only' ),
		);
		if ( 'xml' === $staging->source_type ) {
			$options['title']             = isset( $staging->options['title'] ) ? $staging->options['title'] : '';
			$options['rename_from_title'] = '1' === $this->post_text( 'rename_from_title' );
			$rows                         = Import_Planner::rows_from_xml( $staging->raw_content );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$map                   = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$options['encoding']   = in_array( $this->post_text( 'encoding' ), Csv_Parser::encodings(), true ) ? $this->post_text( 'encoding' ) : 'utf-8';
			$options['delimiter']  = isset( Csv_Parser::delimiters()[ $this->post_text( 'delimiter' ) ] ) ? $this->post_text( 'delimiter' ) : 'comma';
			$options['has_header'] = '1' === $this->post_text( 'has_header' );
			$options['map']        = array(
				'name'      => isset( $map['name'] ) && '' !== $map['name'] ? absint( $map['name'] ) : null,
				'telephone' => isset( $map['telephone'] ) && '' !== $map['telephone'] ? absint( $map['telephone'] ) : null,
				'notes'     => isset( $map['notes'] ) && '' !== $map['notes'] ? absint( $map['notes'] ) : null,
			);
			$rows                  = Import_Planner::rows_from_csv( $staging->raw_content, $options );
		}
		$step_url = Admin::phonebook_url( $phonebook->id, 'import', array( 'import' => $staging->id, 'step' => 'map' ) );
		if ( ! $rows['ok'] ) {
			Import_Staging::save_options( $staging->id, array_merge( $staging->options, $options ) );
			Notices::add( 'error', $rows['error'] );
			$this->redirect( $step_url );
		}
		$plan = Import_Planner::plan( $phonebook->id, $rows['rows'], $options );
		if ( ! empty( $rows['warnings'] ) ) {
			foreach ( $rows['warnings'] as $warning ) {
				Notices::add( 'warning', $warning );
			}
		}
		$current = Phonebook_Repository::get( $phonebook->id );
		Import_Staging::save_plan( $staging->id, array_merge( $staging->options, $options ), $plan, $current->revision );
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'import', array( 'import' => $staging->id ) ) );
	}

	/**
	 * Step 3: commit.
	 */
	public function import_commit() {
		$phonebook = $this->phonebook( 'spb_import_commit_' );
		$import_id = absint( $this->post_text( 'import_id' ) );
		$result    = Import_Committer::commit( $import_id, $phonebook->id, get_current_user_id(), '1' === $this->post_text( 'confirm_replace' ) );
		if ( is_wp_error( $result ) ) {
			Notices::add_error( $result );
			$staging = Import_Staging::get( $import_id, $phonebook->id, get_current_user_id() );
			$this->redirect( $staging ? Admin::phonebook_url( $phonebook->id, 'import', array( 'import' => $import_id, 'step' => 'map' ) ) : Admin::phonebook_url( $phonebook->id, 'import' ) );
		}
		if ( 'replace' === $result['mode'] ) {
			/* translators: 1: added, 2: removed */
			Notices::add( 'success', sprintf( __( 'Import applied: %1$s contacts added, %2$s removed. A snapshot of the previous list is available under Export & snapshots. The feed now serves the new list.', 'site-phonebooks' ), number_format_i18n( $result['added'] ), number_format_i18n( $result['removed'] ) ) );
		} else {
			/* translators: %s: added */
			Notices::add( 'success', sprintf( __( 'Import applied: %s contacts added. The feed now serves the updated list.', 'site-phonebooks' ), number_format_i18n( $result['added'] ) ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id ) );
	}

	/**
	 * Discard a staged import.
	 */
	public function import_cancel() {
		$phonebook = $this->phonebook( 'spb_import_cancel_' );
		$staging   = Import_Staging::get( absint( $this->post_text( 'import_id' ) ), $phonebook->id, get_current_user_id() );
		if ( $staging ) {
			Import_Staging::delete( $staging->id );
			Notices::add( 'info', __( 'Upload discarded. Nothing was changed.', 'site-phonebooks' ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'import' ) );
	}

	/**
	 * Download XML or CSV (read-only GET with nonce).
	 */
	public function export() {
		$phonebook = $this->phonebook( 'spb_export_', 'get' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$format = isset( $_GET['format'] ) && 'csv' === $_GET['format'] ? 'csv' : 'xml';
		nocache_headers();
		if ( 'csv' === $format ) {
			$body = Csv_Exporter::build( Contact_Repository::all( $phonebook->id ), true );
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $phonebook->slug . '.csv"' );
		} else {
			try {
				$body = Feed_Controller::render( $phonebook );
			} catch ( \InvalidArgumentException $e ) {
				wp_die( esc_html( $e->getMessage() ) );
			}
			header( 'Content-Type: application/xml; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $phonebook->slug . '.xml"' );
		}
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * CSV template download.
	 */
	public function template() {
		$this->guard( 'spb_template' );
		nocache_headers();
		$body = Csv_Exporter::template();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="phonebook-template.csv"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Manual snapshot.
	 */
	public function snapshot_create() {
		$phonebook = $this->phonebook( 'spb_snapshot_create_' );
		try {
			Revision_Repository::snapshot( $phonebook->id, 'manual', get_current_user_id() );
			Revision_Repository::prune( $phonebook->id );
			Notices::add( 'success', __( 'Snapshot saved.', 'site-phonebooks' ) );
		} catch ( \Throwable $e ) {
			Notices::add( 'error', __( 'The snapshot could not be saved.', 'site-phonebooks' ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'export' ) );
	}

	/**
	 * Restore a snapshot.
	 */
	public function snapshot_restore() {
		$phonebook = $this->phonebook( 'spb_snapshot_restore_' );
		$result    = Revision_Repository::restore( absint( $this->post_text( 'snapshot_id' ) ), $phonebook->id, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			Notices::add_error( $result );
		} else {
			Notices::add( 'success', __( 'Snapshot restored. The feed now serves the restored list; the list it replaced was snapshotted first.', 'site-phonebooks' ) );
		}
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'export' ) );
	}

	/**
	 * Delete a snapshot.
	 */
	public function snapshot_delete() {
		$phonebook = $this->phonebook( 'spb_snapshot_delete_' );
		$deleted   = Revision_Repository::delete( absint( $this->post_text( 'snapshot_id' ) ), $phonebook->id );
		Notices::add( $deleted ? 'success' : 'error', $deleted ? __( 'Snapshot deleted.', 'site-phonebooks' ) : __( 'Snapshot not found.', 'site-phonebooks' ) );
		$this->redirect( Admin::phonebook_url( $phonebook->id, 'export' ) );
	}

	/**
	 * Loopback self-test.
	 */
	public function self_test() {
		$this->guard( 'spb_self_test' );
		$phonebook = Phonebook_Repository::get( absint( $this->post_text( 'phonebook_id' ) ) );
		if ( ! $phonebook ) {
			Notices::add( 'error', __( 'Phonebook not found.', 'site-phonebooks' ) );
			$this->redirect( Admin::url( Admin::PAGE_HELP ) );
		}
		$result = Diagnostics::self_test( $phonebook );
		/* translators: 1: site name, 2: result */
		Notices::add( $result['ok'] ? 'success' : 'warning', sprintf( __( 'Self-test for "%1$s": %2$s', 'site-phonebooks' ), $phonebook->name, $result['message'] ) );
		$this->redirect( Admin::url( Admin::PAGE_HELP ) );
	}
}
