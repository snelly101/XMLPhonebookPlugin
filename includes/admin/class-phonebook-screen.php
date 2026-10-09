<?php
/**
 * Add / manage phonebook screen.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Contact_Repository;
use SitePhonebooks\Feed\Feed_Router;
use SitePhonebooks\Phonebook_Repository;
use SitePhonebooks\Revision_Repository;
use SitePhonebooks\Settings;
use SitePhonebooks\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Add Phonebook form and the tabbed phonebook detail screen.
 */
final class Phonebook_Screen {

	/**
	 * Tabs.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'contacts' => __( 'Contacts', 'site-phonebooks' ),
			'import'   => __( 'Import', 'site-phonebooks' ),
			'export'   => __( 'Export & snapshots', 'site-phonebooks' ),
			'feed'     => __( 'Feed & access', 'site-phonebooks' ),
			'settings' => __( 'Settings', 'site-phonebooks' ),
		);
	}

	/**
	 * Screen load hook.
	 */
	public static function load() {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
	}

	/**
	 * Current phonebook from the request, or null for "Add".
	 *
	 * @return object|null
	 */
	private static function current_phonebook() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		return $id ? Phonebook_Repository::get( $id ) : null;
	}

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
		$phonebook = self::current_phonebook();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $phonebook && ! empty( $_GET['id'] ) ) {
			echo '<div class="wrap spb-wrap"><h1>' . esc_html__( 'Phonebook not found', 'site-phonebooks' ) . '</h1><p><a href="' . esc_url( Admin::url() ) . '">' . esc_html__( 'Back to all phonebooks', 'site-phonebooks' ) . '</a></p></div>';
			return;
		}
		if ( ! $phonebook ) {
			self::render_add();
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'contacts';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'contacts';
		}
		?>
		<div class="wrap spb-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $phonebook->name ); ?></h1>
			<a href="<?php echo esc_url( Admin::url() ); ?>" class="page-title-action"><?php esc_html_e( 'All phonebooks', 'site-phonebooks' ); ?></a>
			<hr class="wp-header-end">
			<?php Notices::render(); ?>
			<?php self::render_summary( $phonebook ); ?>
			<nav class="nav-tab-wrapper spb-tabs" aria-label="<?php esc_attr_e( 'Phonebook sections', 'site-phonebooks' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( Admin::phonebook_url( $phonebook->id, $key ) ); ?>" class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" <?php echo $key === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="spb-tab-panel">
			<?php
			switch ( $tab ) {
				case 'import':
					Import_Screen::render( $phonebook );
					break;
				case 'export':
					self::render_export( $phonebook );
					break;
				case 'feed':
					self::render_feed( $phonebook );
					break;
				case 'settings':
					self::render_settings( $phonebook );
					break;
				default:
					self::render_contacts( $phonebook );
			}
			?>
			</div>
		</div>
		<?php
	}

	/**
	 * Add Phonebook form.
	 */
	private static function render_add() {
		$default_mode = Settings::get( 'default_access_mode' );
		?>
		<div class="wrap spb-wrap">
			<h1><?php esc_html_e( 'Add Phonebook', 'site-phonebooks' ); ?></h1>
			<?php Notices::render(); ?>
			<p><?php esc_html_e( 'A phonebook serves one site. Its name becomes the title shown on handsets and is used to generate a permanent feed address.', 'site-phonebooks' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form">
				<?php wp_nonce_field( 'spb_create_phonebook' ); ?>
				<input type="hidden" name="action" value="spb_create_phonebook">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spb-name"><?php esc_html_e( 'Site name', 'site-phonebooks' ); ?> <span class="required" aria-hidden="true">*</span></label></th>
						<td>
							<input name="name" id="spb-name" type="text" class="regular-text" required maxlength="190" autocomplete="off" aria-describedby="spb-name-desc">
							<p class="description" id="spb-name-desc"><?php esc_html_e( 'Must be unique. Example: "Frickley Mews". The feed file will be named from it, e.g. frickley-mews.xml.', 'site-phonebooks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spb-description"><?php esc_html_e( 'Internal description', 'site-phonebooks' ); ?></label></th>
						<td>
							<textarea name="description" id="spb-description" rows="3" class="large-text" aria-describedby="spb-description-desc"></textarea>
							<p class="description" id="spb-description-desc"><?php esc_html_e( 'Optional. Only shown in WordPress, never on handsets.', 'site-phonebooks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Feed access', 'site-phonebooks' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Feed access', 'site-phonebooks' ); ?></legend>
								<label><input type="radio" name="access_mode" value="token" <?php checked( 'token', $default_mode ); ?>> <?php esc_html_e( 'Secret URL (recommended): the feed address contains a private key.', 'site-phonebooks' ); ?></label><br>
								<label><input type="radio" name="access_mode" value="public" <?php checked( 'public', $default_mode ); ?>> <?php esc_html_e( 'Public: anyone who knows the address can read the contacts.', 'site-phonebooks' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Create phonebook', 'site-phonebooks' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Summary strip under the heading.
	 *
	 * @param object $phonebook Phonebook.
	 */
	private static function render_summary( $phonebook ) {
		$urls = Feed_Router::urls( $phonebook );
		?>
		<div class="spb-summary" role="region" aria-label="<?php esc_attr_e( 'Phonebook summary', 'site-phonebooks' ); ?>">
			<div class="spb-summary-item">
				<span class="spb-summary-label"><?php esc_html_e( 'Status', 'site-phonebooks' ); ?></span>
				<?php if ( $phonebook->enabled ) : ?>
					<span class="spb-badge spb-badge-on"><?php esc_html_e( 'Feed enabled', 'site-phonebooks' ); ?></span>
				<?php else : ?>
					<span class="spb-badge spb-badge-off"><?php esc_html_e( 'Feed disabled', 'site-phonebooks' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="spb-summary-item">
				<span class="spb-summary-label"><?php esc_html_e( 'Contacts', 'site-phonebooks' ); ?></span>
				<span class="spb-summary-value"><?php echo esc_html( number_format_i18n( $phonebook->contact_count ) ); ?></span>
			</div>
			<div class="spb-summary-item">
				<span class="spb-summary-label"><?php esc_html_e( 'Last content update', 'site-phonebooks' ); ?></span>
				<span class="spb-summary-value" title="<?php echo esc_attr( Admin::format_date( $phonebook->content_updated_at ) ); ?>"><?php echo esc_html( Admin::relative_date( $phonebook->content_updated_at ) ); ?></span>
			</div>
			<div class="spb-summary-item">
				<span class="spb-summary-label"><?php esc_html_e( 'Last feed request seen', 'site-phonebooks' ); ?></span>
				<span class="spb-summary-value"><?php echo $phonebook->last_request_at ? esc_html( Admin::relative_date( $phonebook->last_request_at ) . ' (HTTP ' . $phonebook->last_request_status . ')' ) : esc_html__( 'none yet', 'site-phonebooks' ); ?></span>
			</div>
			<div class="spb-summary-item spb-summary-url">
				<label class="spb-summary-label" for="spb-summary-feed-url"><?php esc_html_e( 'Feed URL', 'site-phonebooks' ); ?></label>
				<div class="spb-copy-field">
					<input type="text" id="spb-summary-feed-url" readonly value="<?php echo esc_attr( $urls['primary'] ); ?>" class="code" onfocus="this.select()">
					<button type="button" class="button spb-copy" data-copy-target="spb-summary-feed-url"><?php esc_html_e( 'Copy', 'site-phonebooks' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Contacts tab.
	 *
	 * @param object $phonebook Phonebook.
	 */
	private static function render_contacts( $phonebook ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$editing    = isset( $_GET['contact'] ) ? Contact_Repository::get( absint( $_GET['contact'] ), $phonebook->id ) : null;
		$pending    = isset( $_GET['pending'] ) ? sanitize_key( wp_unslash( $_GET['pending'] ) ) : '';
		$form_state = get_transient( 'spb_form_' . get_current_user_id() );
		// phpcs:enable
		if ( is_array( $form_state ) ) {
			delete_transient( 'spb_form_' . get_current_user_id() );
		} else {
			$form_state = array();
		}
		$values = array(
			'name'      => $editing ? $editing->name : '',
			'telephone' => $editing ? $editing->telephone : '',
			'notes'     => $editing ? $editing->notes : '',
		);
		if ( ! empty( $form_state ) && ( ! $editing || (int) $form_state['contact_id'] === $editing->id ) ) {
			$values = array_merge( $values, array_intersect_key( $form_state, $values ) );
		}

		if ( 'delete' === $pending ) {
			self::render_pending_delete( $phonebook );
		}
		?>
		<div class="spb-columns">
			<div class="spb-main">
				<form method="get" class="spb-search-form" role="search">
					<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_EDIT ); ?>">
					<input type="hidden" name="id" value="<?php echo (int) $phonebook->id; ?>">
					<input type="hidden" name="tab" value="contacts">
					<label class="screen-reader-text" for="spb-contact-search"><?php esc_html_e( 'Search contacts', 'site-phonebooks' ); ?></label>
					<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<input type="search" id="spb-contact-search" name="s" value="<?php echo isset( $_GET['s'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; ?>" placeholder="<?php esc_attr_e( 'Search name, number or notes', 'site-phonebooks' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'site-phonebooks' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="spb-contacts-form">
					<?php wp_nonce_field( 'spb_bulk_contacts_' . $phonebook->id ); ?>
					<input type="hidden" name="action" value="spb_bulk_contacts">
					<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
					<?php
					$table = new Contacts_List_Table( $phonebook );
					$table->prepare_items();
					$table->display();
					?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="spb-delete-contact">
					<?php wp_nonce_field( 'spb_delete_contact_' . $phonebook->id ); ?>
					<input type="hidden" name="action" value="spb_delete_contact">
					<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				</form>
				<p class="description"><?php esc_html_e( 'Contacts are published in the XML feed sorted by name, then telephone. Notes are internal and never appear in the feed.', 'site-phonebooks' ); ?></p>
			</div>
			<aside class="spb-side" aria-labelledby="spb-contact-form-heading">
				<div class="spb-card">
					<h2 id="spb-contact-form-heading"><?php echo $editing ? esc_html__( 'Edit contact', 'site-phonebooks' ) : esc_html__( 'Add contact', 'site-phonebooks' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form spb-single-submit">
						<?php wp_nonce_field( 'spb_save_contact_' . $phonebook->id ); ?>
						<input type="hidden" name="action" value="spb_save_contact">
						<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
						<input type="hidden" name="contact_id" value="<?php echo $editing ? (int) $editing->id : 0; ?>">
						<p>
							<label for="spb-contact-name"><?php esc_html_e( 'Display name', 'site-phonebooks' ); ?> <span class="required" aria-hidden="true">*</span></label>
							<input type="text" id="spb-contact-name" name="name" class="widefat" required maxlength="190" value="<?php echo esc_attr( $values['name'] ); ?>">
						</p>
						<p>
							<label for="spb-contact-telephone"><?php esc_html_e( 'Telephone', 'site-phonebooks' ); ?> <span class="required" aria-hidden="true">*</span></label>
							<input type="text" id="spb-contact-telephone" name="telephone" class="widefat code" required maxlength="64" inputmode="tel" autocomplete="off" value="<?php echo esc_attr( $values['telephone'] ); ?>" aria-describedby="spb-contact-telephone-desc">
							<span class="description" id="spb-contact-telephone-desc"><?php esc_html_e( 'Stored exactly as typed (leading zeros and + are kept).', 'site-phonebooks' ); ?></span>
						</p>
						<p>
							<label for="spb-contact-notes"><?php esc_html_e( 'Internal notes', 'site-phonebooks' ); ?></label>
							<textarea id="spb-contact-notes" name="notes" class="widefat" rows="3" maxlength="2000"><?php echo esc_textarea( $values['notes'] ); ?></textarea>
						</p>
						<p class="spb-actions">
							<button type="submit" class="button button-primary"><?php echo $editing ? esc_html__( 'Save changes', 'site-phonebooks' ) : esc_html__( 'Add contact', 'site-phonebooks' ); ?></button>
							<?php if ( $editing ) : ?>
								<a class="button" href="<?php echo esc_url( Admin::phonebook_url( $phonebook->id ) ); ?>"><?php esc_html_e( 'Cancel', 'site-phonebooks' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				</div>
				<?php if ( $phonebook->contact_count > 0 ) : ?>
				<div class="spb-card spb-card-danger">
					<h2><?php esc_html_e( 'Clear phonebook', 'site-phonebooks' ); ?></h2>
					<p><?php esc_html_e( 'Removes every contact. A snapshot is taken first so the list can be restored from Export & snapshots.', 'site-phonebooks' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-single-submit">
						<?php wp_nonce_field( 'spb_clear_contacts_' . $phonebook->id ); ?>
						<input type="hidden" name="action" value="spb_clear_contacts">
						<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
						<p><label><input type="checkbox" name="confirm" value="1" required> <?php echo esc_html( sprintf( /* translators: %s: number of contacts */ _n( 'I understand %s contact will be removed from the feed.', 'I understand %s contacts will be removed from the feed.', $phonebook->contact_count, 'site-phonebooks' ), number_format_i18n( $phonebook->contact_count ) ) ); ?></label></p>
						<button type="submit" class="button spb-button-danger spb-confirm" data-confirm="<?php esc_attr_e( 'Remove all contacts from this phonebook?', 'site-phonebooks' ); ?>"><?php esc_html_e( 'Clear all contacts', 'site-phonebooks' ); ?></button>
					</form>
				</div>
				<?php endif; ?>
			</aside>
		</div>
		<?php
	}

	/**
	 * Bulk delete confirmation panel.
	 *
	 * @param object $phonebook Phonebook.
	 */
	private static function render_pending_delete( $phonebook ) {
		$pending = get_transient( 'spb_pending_delete_' . get_current_user_id() );
		if ( ! is_array( $pending ) || (int) $pending['phonebook_id'] !== $phonebook->id || empty( $pending['ids'] ) ) {
			return;
		}
		$count = count( $pending['ids'] );
		?>
		<div class="spb-card spb-card-danger spb-confirm-panel" role="alertdialog" aria-labelledby="spb-pending-heading" aria-describedby="spb-pending-desc">
			<h2 id="spb-pending-heading"><?php esc_html_e( 'Confirm bulk delete', 'site-phonebooks' ); ?></h2>
			<p id="spb-pending-desc"><?php echo esc_html( sprintf( /* translators: %s: number of contacts */ _n( 'Delete %s selected contact? A snapshot is taken first.', 'Delete %s selected contacts? A snapshot is taken first.', $count, 'site-phonebooks' ), number_format_i18n( $count ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-inline-form spb-single-submit">
				<?php wp_nonce_field( 'spb_bulk_contacts_confirm_' . $phonebook->id ); ?>
				<input type="hidden" name="action" value="spb_bulk_contacts_confirm">
				<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				<button type="submit" class="button spb-button-danger"><?php esc_html_e( 'Yes, delete them', 'site-phonebooks' ); ?></button>
				<a class="button" href="<?php echo esc_url( Admin::phonebook_url( $phonebook->id ) ); ?>"><?php esc_html_e( 'Cancel', 'site-phonebooks' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Export & snapshots tab.
	 *
	 * @param object $phonebook Phonebook.
	 */
	private static function render_export( $phonebook ) {
		$snapshots = Revision_Repository::list_for( $phonebook->id );
		$labels    = Revision_Repository::reason_labels();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$preview = isset( $_GET['snapshot'] ) ? Revision_Repository::get( absint( $_GET['snapshot'] ), $phonebook->id ) : null;
		$xml_url = wp_nonce_url( add_query_arg( array( 'action' => 'spb_export', 'format' => 'xml', 'phonebook_id' => $phonebook->id ), admin_url( 'admin-post.php' ) ), 'spb_export_' . $phonebook->id );
		$csv_url = wp_nonce_url( add_query_arg( array( 'action' => 'spb_export', 'format' => 'csv', 'phonebook_id' => $phonebook->id ), admin_url( 'admin-post.php' ) ), 'spb_export_' . $phonebook->id );
		?>
		<div class="spb-card">
			<h2><?php esc_html_e( 'Download', 'site-phonebooks' ); ?></h2>
			<p>
				<a class="button" href="<?php echo esc_url( $xml_url ); ?>"><?php esc_html_e( 'Download XML', 'site-phonebooks' ); ?></a>
				<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download CSV', 'site-phonebooks' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'The XML download is identical to the feed. The CSV contains Name, Telephone and Notes; values that spreadsheets could run as formulas are prefixed with an apostrophe, which the importer removes again. Spreadsheet software may strip leading zeros when it opens a CSV; the file itself keeps them.', 'site-phonebooks' ); ?></p>
		</div>

		<div class="spb-card">
			<h2><?php esc_html_e( 'Snapshots', 'site-phonebooks' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %d: retention count */ __( 'A snapshot of the contact list is taken automatically before replace imports, bulk deletes, clears and restores. The newest %d snapshots are kept per phonebook (Phonebooks > Settings).', 'site-phonebooks' ), (int) Settings::get( 'revision_retention' ) ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-inline-form">
				<?php wp_nonce_field( 'spb_snapshot_create_' . $phonebook->id ); ?>
				<input type="hidden" name="action" value="spb_snapshot_create">
				<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Take a snapshot now', 'site-phonebooks' ); ?></button>
			</form>
			<?php if ( empty( $snapshots ) ) : ?>
				<p class="spb-empty"><?php esc_html_e( 'No snapshots yet.', 'site-phonebooks' ); ?></p>
			<?php else : ?>
				<table class="widefat striped spb-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Taken', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Reason', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Contacts', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'site-phonebooks' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $snapshots as $snapshot ) : ?>
						<tr>
							<td><?php echo esc_html( Admin::format_date( $snapshot->created_at ) ); ?></td>
							<td><?php echo esc_html( isset( $labels[ $snapshot->reason ] ) ? $labels[ $snapshot->reason ] : $snapshot->reason ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $snapshot->contact_count ) ); ?></td>
							<td class="spb-row-actions">
								<a class="button button-small" href="<?php echo esc_url( Admin::phonebook_url( $phonebook->id, 'export', array( 'snapshot' => $snapshot->id ) ) ); ?>#spb-snapshot-preview"><?php esc_html_e( 'Preview', 'site-phonebooks' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-inline-form spb-single-submit">
									<?php wp_nonce_field( 'spb_snapshot_restore_' . $phonebook->id ); ?>
									<input type="hidden" name="action" value="spb_snapshot_restore">
									<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
									<input type="hidden" name="snapshot_id" value="<?php echo (int) $snapshot->id; ?>">
									<button type="submit" class="button button-small spb-confirm" data-confirm="<?php echo esc_attr( sprintf( /* translators: 1: contact count, 2: current count */ __( 'Replace the current %2$s contacts with the %1$s contacts in this snapshot? The current list is snapshotted first.', 'site-phonebooks' ), number_format_i18n( $snapshot->contact_count ), number_format_i18n( $phonebook->contact_count ) ) ); ?>"><?php esc_html_e( 'Restore', 'site-phonebooks' ); ?></button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-inline-form">
									<?php wp_nonce_field( 'spb_snapshot_delete_' . $phonebook->id ); ?>
									<input type="hidden" name="action" value="spb_snapshot_delete">
									<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
									<input type="hidden" name="snapshot_id" value="<?php echo (int) $snapshot->id; ?>">
									<button type="submit" class="button-link spb-link-danger spb-confirm" data-confirm="<?php esc_attr_e( 'Delete this snapshot?', 'site-phonebooks' ); ?>"><?php esc_html_e( 'Delete', 'site-phonebooks' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( $preview ) : ?>
		<div class="spb-card" id="spb-snapshot-preview">
			<h2><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'Snapshot from %s', 'site-phonebooks' ), Admin::format_date( $preview->created_at ) ) ); ?></h2>
			<?php if ( empty( $preview->contacts ) ) : ?>
				<p class="spb-empty"><?php esc_html_e( 'This snapshot is empty.', 'site-phonebooks' ); ?></p>
			<?php else : ?>
			<table class="widefat striped spb-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Name', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Telephone', 'site-phonebooks' ); ?></th><th scope="col"><?php esc_html_e( 'Notes', 'site-phonebooks' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $preview->contacts, 0, 1000 ) as $contact ) : ?>
					<tr><td><?php echo esc_html( isset( $contact['name'] ) ? $contact['name'] : '' ); ?></td><td><code><?php echo esc_html( isset( $contact['telephone'] ) ? $contact['telephone'] : '' ); ?></code></td><td><?php echo esc_html( isset( $contact['notes'] ) ? wp_trim_words( $contact['notes'], 10 ) : '' ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $preview->contacts ) > 1000 ) : ?>
				<p class="description"><?php esc_html_e( 'Only the first 1,000 contacts are shown.', 'site-phonebooks' ); ?></p>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php endif;
	}

	/**
	 * Feed & access tab.
	 *
	 * @param object $phonebook Phonebook.
	 */
	private static function render_feed( $phonebook ) {
		$urls      = Feed_Router::urls( $phonebook );
		$pretty    = Feed_Router::pretty_permalinks();
		$protected = 'public' !== $phonebook->access_mode;
		?>
		<div class="spb-columns">
			<div class="spb-main">
				<div class="spb-card">
					<h2><?php esc_html_e( 'Feed address', 'site-phonebooks' ); ?></h2>
					<p><?php esc_html_e( 'Enter this address in the handset\'s remote phonebook settings (on Yealink phones usually Directory > Remote Phone Book, or the web interface under Directory > Remote Phone Book). Menu names, the number of remote phonebooks and refresh options vary by model and firmware.', 'site-phonebooks' ); ?></p>
					<label for="spb-feed-url-primary" class="spb-label"><?php echo $pretty ? esc_html__( 'Feed URL', 'site-phonebooks' ) : esc_html__( 'Feed URL (plain permalinks are active, so the fallback form is used)', 'site-phonebooks' ); ?></label>
					<div class="spb-copy-field">
						<input type="text" id="spb-feed-url-primary" readonly value="<?php echo esc_attr( $urls['primary'] ); ?>" class="code" onfocus="this.select()">
						<button type="button" class="button spb-copy" data-copy-target="spb-feed-url-primary"><?php esc_html_e( 'Copy', 'site-phonebooks' ); ?></button>
					</div>
					<?php if ( $protected ) : ?>
					<details class="spb-details">
						<summary><?php esc_html_e( 'Alternative address forms', 'site-phonebooks' ); ?></summary>
						<p><?php esc_html_e( 'If a handset or firmware does not pass the ?key= query string, use the path form, which keeps the site-specific .xml filename:', 'site-phonebooks' ); ?></p>
						<label for="spb-feed-url-path" class="screen-reader-text"><?php esc_html_e( 'Path-token feed URL', 'site-phonebooks' ); ?></label>
						<div class="spb-copy-field">
							<input type="text" id="spb-feed-url-path" readonly value="<?php echo esc_attr( $urls['pretty_path_token'] ); ?>" class="code" onfocus="this.select()">
							<button type="button" class="button spb-copy" data-copy-target="spb-feed-url-path"><?php esc_html_e( 'Copy', 'site-phonebooks' ); ?></button>
						</div>
						<p><?php esc_html_e( 'Fallback that works without pretty permalinks:', 'site-phonebooks' ); ?></p>
						<label for="spb-feed-url-fallback" class="screen-reader-text"><?php esc_html_e( 'Fallback feed URL', 'site-phonebooks' ); ?></label>
						<div class="spb-copy-field">
							<input type="text" id="spb-feed-url-fallback" readonly value="<?php echo esc_attr( $urls['fallback'] ); ?>" class="code" onfocus="this.select()">
							<button type="button" class="button spb-copy" data-copy-target="spb-feed-url-fallback"><?php esc_html_e( 'Copy', 'site-phonebooks' ); ?></button>
						</div>
					</details>
					<?php else : ?>
					<details class="spb-details">
						<summary><?php esc_html_e( 'Alternative address forms', 'site-phonebooks' ); ?></summary>
						<p><?php esc_html_e( 'Fallback that works without pretty permalinks:', 'site-phonebooks' ); ?></p>
						<label for="spb-feed-url-fallback" class="screen-reader-text"><?php esc_html_e( 'Fallback feed URL', 'site-phonebooks' ); ?></label>
						<div class="spb-copy-field">
							<input type="text" id="spb-feed-url-fallback" readonly value="<?php echo esc_attr( $urls['public_fallback'] ); ?>" class="code" onfocus="this.select()">
							<button type="button" class="button spb-copy" data-copy-target="spb-feed-url-fallback"><?php esc_html_e( 'Copy', 'site-phonebooks' ); ?></button>
						</div>
					</details>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'The feed is served by WordPress on request; the handset decides when to fetch it. A request logged here shows the phone reached WordPress, not that it imported the contacts. Responses served by a page cache or CDN may not be logged. HTTPS is preferred; whether a given model accepts your certificate must be verified on the handset.', 'site-phonebooks' ); ?></p>
				</div>

				<div class="spb-card">
					<h2><?php esc_html_e( 'Access', 'site-phonebooks' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form">
						<?php wp_nonce_field( 'spb_feed_settings_' . $phonebook->id ); ?>
						<input type="hidden" name="action" value="spb_feed_settings">
						<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
						<fieldset>
							<legend class="spb-label"><?php esc_html_e( 'Who can read this feed', 'site-phonebooks' ); ?></legend>
							<p><label><input type="radio" name="access_mode" value="token" <?php checked( $protected ); ?>> <?php esc_html_e( 'Secret URL: requests must include this phonebook\'s private key. Treat the address as a password.', 'site-phonebooks' ); ?></label></p>
							<p><label><input type="radio" name="access_mode" value="public" <?php checked( ! $protected ); ?>> <?php esc_html_e( 'Public: anyone who can reach the site can read the contacts. Only use when the handsets cannot send a key.', 'site-phonebooks' ); ?></label></p>
						</fieldset>
						<p>
							<label for="spb-prompt" class="spb-label"><?php esc_html_e( 'XML prompt text', 'site-phonebooks' ); ?></label>
							<input type="text" id="spb-prompt" name="prompt" class="regular-text" maxlength="190" value="<?php echo esc_attr( $phonebook->prompt ); ?>" aria-describedby="spb-prompt-desc">
							<span class="description" id="spb-prompt-desc"><?php esc_html_e( 'The <Prompt> element. Leave as "Prompt" unless you know your handsets use it.', 'site-phonebooks' ); ?></span>
						</p>
						<p><label><input type="checkbox" name="enabled" value="1" <?php checked( $phonebook->enabled ); ?>> <?php esc_html_e( 'Feed enabled (when unticked the address returns 404 and exposes nothing)', 'site-phonebooks' ); ?></label></p>
						<?php submit_button( __( 'Save access settings', 'site-phonebooks' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<div class="spb-card">
					<h2><?php esc_html_e( 'Private key', 'site-phonebooks' ); ?></h2>
					<p><?php esc_html_e( 'Key (masked):', 'site-phonebooks' ); ?> <code><?php echo esc_html( Token::mask( $phonebook->access_token ) ); ?></code></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-inline-form spb-single-submit">
						<?php wp_nonce_field( 'spb_rotate_token_' . $phonebook->id ); ?>
						<input type="hidden" name="action" value="spb_rotate_token">
						<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
						<button type="submit" class="button spb-confirm" data-confirm="<?php esc_attr_e( 'Generate a new key? Every handset configured with the current address will stop receiving this phonebook until it is updated with the new address.', 'site-phonebooks' ); ?>"><?php esc_html_e( 'Generate a new key', 'site-phonebooks' ); ?></button>
					</form>
					<p class="description"><?php esc_html_e( 'Rotating the key immediately invalidates the previous address, including any cached copies. Keys are generated with a cryptographic random source and compared in constant time.', 'site-phonebooks' ); ?></p>
				</div>
			</div>
			<aside class="spb-side">
				<div class="spb-card">
					<h2><?php esc_html_e( 'Last request seen', 'site-phonebooks' ); ?></h2>
					<?php if ( $phonebook->last_request_at ) : ?>
						<dl class="spb-dl">
							<dt><?php esc_html_e( 'When', 'site-phonebooks' ); ?></dt><dd><?php echo esc_html( Admin::format_date( $phonebook->last_request_at ) ); ?></dd>
							<dt><?php esc_html_e( 'Result', 'site-phonebooks' ); ?></dt><dd><?php echo esc_html( self::status_label( $phonebook->last_request_status ) ); ?></dd>
							<dt><?php esc_html_e( 'Client', 'site-phonebooks' ); ?></dt><dd><?php echo '' !== $phonebook->last_request_agent ? esc_html( $phonebook->last_request_agent ) : esc_html__( '(no user agent)', 'site-phonebooks' ); ?></dd>
						</dl>
					<?php else : ?>
						<p class="spb-empty"><?php esc_html_e( 'WordPress has not seen a request for this feed yet.', 'site-phonebooks' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="spb-card">
					<h2><?php esc_html_e( 'Caching and security plugins', 'site-phonebooks' ); ?></h2>
					<p><?php esc_html_e( 'Exclude the feed path from page caches, CDNs and firewall rules so that key checks and rotation always reach WordPress:', 'site-phonebooks' ); ?></p>
					<code>/<?php echo esc_html( Feed_Router::base() ); ?>/*</code>
					<p><?php esc_html_e( 'Protected feeds are sent with Cache-Control: private; public feeds may be cached by shared caches until they revalidate.', 'site-phonebooks' ); ?></p>
				</div>
			</aside>
		</div>
		<?php
	}

	/**
	 * Human label for a feed status code.
	 *
	 * @param int|null $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( (int) $status ) {
			case 200:
				return __( '200 – phonebook delivered', 'site-phonebooks' );
			case 304:
				return __( '304 – not modified since the client\'s last copy', 'site-phonebooks' );
			case 403:
				return __( '403 – rejected: missing or wrong key', 'site-phonebooks' );
			case 404:
				return __( '404 – feed disabled', 'site-phonebooks' );
			case 405:
				return __( '405 – unsupported request method', 'site-phonebooks' );
			case 500:
				return __( '500 – feed could not be generated', 'site-phonebooks' );
			default:
				return (string) $status;
		}
	}

	/**
	 * Settings tab.
	 *
	 * @param object $phonebook Phonebook.
	 */
	private static function render_settings( $phonebook ) {
		?>
		<div class="spb-card">
			<h2><?php esc_html_e( 'Details', 'site-phonebooks' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form">
				<?php wp_nonce_field( 'spb_update_phonebook_' . $phonebook->id ); ?>
				<input type="hidden" name="action" value="spb_update_phonebook">
				<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spb-name"><?php esc_html_e( 'Site name', 'site-phonebooks' ); ?></label></th>
						<td>
							<input name="name" id="spb-name" type="text" class="regular-text" required maxlength="190" value="<?php echo esc_attr( $phonebook->name ); ?>" aria-describedby="spb-name-desc">
							<p class="description" id="spb-name-desc"><?php esc_html_e( 'Shown as the XML <Title>. Changing it does not change the feed address.', 'site-phonebooks' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spb-description"><?php esc_html_e( 'Internal description', 'site-phonebooks' ); ?></label></th>
						<td><textarea name="description" id="spb-description" rows="3" class="large-text"><?php echo esc_textarea( $phonebook->description ); ?></textarea></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save details', 'site-phonebooks' ) ); ?>
			</form>
		</div>

		<div class="spb-card spb-card-warning">
			<h2><?php esc_html_e( 'Feed file name', 'site-phonebooks' ); ?></h2>
			<p><?php esc_html_e( 'The file name is part of every handset\'s configured address. Change it only when necessary: phones configured with the old address will stop receiving this phonebook until they are updated.', 'site-phonebooks' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form spb-single-submit">
				<?php wp_nonce_field( 'spb_change_slug_' . $phonebook->id ); ?>
				<input type="hidden" name="action" value="spb_change_slug">
				<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				<p>
					<label for="spb-slug" class="spb-label"><?php esc_html_e( 'File name (without .xml)', 'site-phonebooks' ); ?></label>
					<input type="text" id="spb-slug" name="slug" class="regular-text code" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="100" value="<?php echo esc_attr( $phonebook->slug ); ?>" aria-describedby="spb-slug-desc">
					<span class="description" id="spb-slug-desc"><?php esc_html_e( 'Lowercase letters, numbers and single dashes only.', 'site-phonebooks' ); ?></span>
				</p>
				<p><label><input type="checkbox" name="confirm" value="1" required> <?php esc_html_e( 'I understand configured phones must be updated with the new address.', 'site-phonebooks' ); ?></label></p>
				<button type="submit" class="button spb-confirm" data-confirm="<?php esc_attr_e( 'Change the feed file name? Phones using the old address will stop receiving this phonebook.', 'site-phonebooks' ); ?>"><?php esc_html_e( 'Change file name', 'site-phonebooks' ); ?></button>
			</form>
		</div>

		<div class="spb-card spb-card-danger">
			<h2><?php esc_html_e( 'Delete phonebook', 'site-phonebooks' ); ?></h2>
			<p><?php esc_html_e( 'Permanently deletes this phonebook, all of its contacts, snapshots and staged imports. The feed address stops working immediately. This cannot be undone.', 'site-phonebooks' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="spb-form spb-single-submit">
				<?php wp_nonce_field( 'spb_delete_phonebook_' . $phonebook->id ); ?>
				<input type="hidden" name="action" value="spb_delete_phonebook">
				<input type="hidden" name="phonebook_id" value="<?php echo (int) $phonebook->id; ?>">
				<p>
					<label for="spb-delete-confirm-name" class="spb-label"><?php esc_html_e( 'Type the site name to confirm', 'site-phonebooks' ); ?></label>
					<input type="text" id="spb-delete-confirm-name" name="confirm_name" class="regular-text" autocomplete="off" required>
				</p>
				<button type="submit" class="button spb-button-danger spb-confirm" data-confirm="<?php esc_attr_e( 'Delete this phonebook and all of its data?', 'site-phonebooks' ); ?>"><?php esc_html_e( 'Delete phonebook', 'site-phonebooks' ); ?></button>
			</form>
		</div>
		<?php
	}
}
