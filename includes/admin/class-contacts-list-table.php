<?php
/**
 * Contacts list table for a phonebook.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Contact_Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated, searchable contact list with bulk selection.
 */
final class Contacts_List_Table extends \WP_List_Table {

	/**
	 * Phonebook.
	 *
	 * @var object
	 */
	private $phonebook;

	/**
	 * Search term.
	 *
	 * @var string
	 */
	public $search = '';

	/**
	 * Constructor.
	 *
	 * @param object $phonebook Phonebook row.
	 */
	public function __construct( $phonebook ) {
		$this->phonebook = $phonebook;
		parent::__construct(
			array(
				'singular' => 'contact',
				'plural'   => 'contacts',
				'ajax'     => false,
				'screen'   => 'phonebooks_page_' . Admin::PAGE_EDIT,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'name'      => __( 'Name', 'site-phonebooks' ),
			'telephone' => __( 'Telephone', 'site-phonebooks' ),
			'notes'     => __( 'Notes (internal)', 'site-phonebooks' ),
			'updated'   => __( 'Updated', 'site-phonebooks' ),
		);
	}

	/**
	 * Load items.
	 */
	public function prepare_items() {
		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$result       = Contact_Repository::query(
			$this->phonebook->id,
			array(
				'search'   => $this->search,
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);
		$this->items = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
	}

	/**
	 * Table navigation without WP_List_Table's own bulk nonce field, which
	 * would override the surrounding form's nonce (both are named _wpnonce).
	 *
	 * @param string $which top|bottom.
	 */
	protected function display_tablenav( $which ) {
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php if ( $this->has_items() ) : ?>
			<div class="alignleft actions bulkactions">
				<?php $this->bulk_actions( $which ); ?>
			</div>
			<?php endif; ?>
			<?php $this->pagination( $which ); ?>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * Bulk actions rendered with our own field name so it does not collide
	 * with admin-post.php's "action" parameter.
	 *
	 * @param string $which top|bottom.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<label for="spb-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'site-phonebooks' ); ?></label>
		<select name="bulk_action" id="spb-bulk-action">
			<option value=""><?php esc_html_e( 'Bulk actions', 'site-phonebooks' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete selected', 'site-phonebooks' ); ?></option>
		</select>
		<?php submit_button( __( 'Apply', 'site-phonebooks' ), 'action', '', false, array( 'id' => 'spb-bulk-apply' ) ); ?>
		<?php
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		if ( '' !== $this->search ) {
			esc_html_e( 'No contacts match your search.', 'site-phonebooks' );
			return;
		}
		printf(
			/* translators: %s: Import link */
			esc_html__( 'This phonebook has no contacts yet. Add one above or %s.', 'site-phonebooks' ),
			'<a href="' . esc_url( Admin::phonebook_url( $this->phonebook->id, 'import' ) ) . '">' . esc_html__( 'import a CSV or XML file', 'site-phonebooks' ) . '</a>'
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Contact.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="spb-cb-%1$d">%2$s</label><input type="checkbox" name="contact_ids[]" id="spb-cb-%1$d" value="%1$d">',
			(int) $item->id,
			/* translators: %s: contact name */
			esc_html( sprintf( __( 'Select %s', 'site-phonebooks' ), $item->name ) )
		);
	}

	/**
	 * Name column with row actions.
	 *
	 * @param object $item Contact.
	 * @return string
	 */
	public function column_name( $item ) {
		$edit_url = Admin::phonebook_url( $this->phonebook->id, 'contacts', array( 'contact' => $item->id ) );
		$actions  = array(
			'edit'   => '<a href="' . esc_url( $edit_url ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: contact name */ __( 'Edit %s', 'site-phonebooks' ), $item->name ) ) . '">' . esc_html__( 'Edit', 'site-phonebooks' ) . '</a>',
			'delete' => '<button type="submit" class="button-link spb-link-danger spb-confirm" form="spb-delete-contact" name="contact_id" value="' . (int) $item->id . '" data-confirm="' . esc_attr( sprintf( /* translators: %s: contact name */ __( 'Delete "%s" from this phonebook?', 'site-phonebooks' ), $item->name ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: contact name */ __( 'Delete %s', 'site-phonebooks' ), $item->name ) ) . '">' . esc_html__( 'Delete', 'site-phonebooks' ) . '</button>',
		);
		return '<strong><a href="' . esc_url( $edit_url ) . '" class="row-title">' . esc_html( $item->name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Telephone column.
	 *
	 * @param object $item Contact.
	 * @return string
	 */
	public function column_telephone( $item ) {
		return '<code class="spb-tel">' . esc_html( $item->telephone ) . '</code>';
	}

	/**
	 * Notes column.
	 *
	 * @param object $item Contact.
	 * @return string
	 */
	public function column_notes( $item ) {
		return esc_html( wp_trim_words( $item->notes, 12 ) );
	}

	/**
	 * Updated column.
	 *
	 * @param object $item Contact.
	 * @return string
	 */
	public function column_updated( $item ) {
		return '<span title="' . esc_attr( Admin::format_date( $item->updated_at ) ) . '">' . esc_html( Admin::relative_date( $item->updated_at ) ) . '</span>';
	}
}
