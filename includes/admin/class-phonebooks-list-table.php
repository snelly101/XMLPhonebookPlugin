<?php
/**
 * All Phonebooks list table.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Feed\Feed_Router;
use SitePhonebooks\Phonebook_Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Searchable, paginated phonebook list.
 */
final class Phonebooks_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'phonebook',
				'plural'   => 'phonebooks',
				'ajax'     => false,
				'screen'   => 'toplevel_page_' . Admin::PAGE_LIST,
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
			'name'          => __( 'Site name', 'site-phonebooks' ),
			'slug'          => __( 'Feed file', 'site-phonebooks' ),
			'contact_count' => __( 'Contacts', 'site-phonebooks' ),
			'status'        => __( 'Status', 'site-phonebooks' ),
			'updated'       => __( 'Updated', 'site-phonebooks' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'          => array( 'name', true ),
			'slug'          => array( 'slug', false ),
			'contact_count' => array( 'contact_count', false ),
			'updated'       => array( 'content_updated_at', false ),
		);
	}

	/**
	 * Load items.
	 */
	public function prepare_items() {
		$per_page = 20;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'DESC' : 'ASC';
		// phpcs:enable
		$result = Phonebook_Repository::query(
			array(
				'search'   => $search,
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
				'orderby'  => $orderby,
				'order'    => $order,
			)
		);
		$this->items = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) ) {
			esc_html_e( 'No phonebooks match your search.', 'site-phonebooks' );
			return;
		}
		printf(
			/* translators: %s: Add Phonebook link */
			esc_html__( 'No phonebooks yet. %s to create the first one, then import a CSV or add contacts.', 'site-phonebooks' ),
			'<a href="' . esc_url( Admin::url( Admin::PAGE_EDIT ) ) . '">' . esc_html__( 'Add a phonebook', 'site-phonebooks' ) . '</a>'
		);
	}

	/**
	 * Name column with row actions.
	 *
	 * @param object $item Phonebook.
	 * @return string
	 */
	public function column_name( $item ) {
		$edit    = Admin::phonebook_url( $item->id );
		$actions = array(
			'manage' => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Manage', 'site-phonebooks' ) . '</a>',
			'import' => '<a href="' . esc_url( Admin::phonebook_url( $item->id, 'import' ) ) . '">' . esc_html__( 'Import', 'site-phonebooks' ) . '</a>',
			'feed'   => '<a href="' . esc_url( Admin::phonebook_url( $item->id, 'feed' ) ) . '">' . esc_html__( 'Feed & access', 'site-phonebooks' ) . '</a>',
		);
		$out     = '<strong><a class="row-title" href="' . esc_url( $edit ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: site name */ __( 'Manage %s', 'site-phonebooks' ), $item->name ) ) . '">' . esc_html( $item->name ) . '</a></strong>';
		if ( '' !== $item->description ) {
			$out .= '<div class="spb-muted">' . esc_html( wp_trim_words( $item->description, 14 ) ) . '</div>';
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Slug column.
	 *
	 * @param object $item Phonebook.
	 * @return string
	 */
	public function column_slug( $item ) {
		$label = 'public' === $item->access_mode ? __( 'Public', 'site-phonebooks' ) : __( 'Secret URL', 'site-phonebooks' );
		return '<code>' . esc_html( $item->slug . '.xml' ) . '</code><div class="spb-muted">' . esc_html( $label ) . '</div>';
	}

	/**
	 * Count column.
	 *
	 * @param object $item Phonebook.
	 * @return string
	 */
	public function column_contact_count( $item ) {
		return esc_html( number_format_i18n( $item->contact_count ) );
	}

	/**
	 * Status column.
	 *
	 * @param object $item Phonebook.
	 * @return string
	 */
	public function column_status( $item ) {
		if ( $item->enabled ) {
			return '<span class="spb-badge spb-badge-on">' . esc_html__( 'Enabled', 'site-phonebooks' ) . '</span>';
		}
		return '<span class="spb-badge spb-badge-off">' . esc_html__( 'Disabled', 'site-phonebooks' ) . '</span>';
	}

	/**
	 * Updated column.
	 *
	 * @param object $item Phonebook.
	 * @return string
	 */
	public function column_updated( $item ) {
		return '<span title="' . esc_attr( Admin::format_date( $item->content_updated_at ) ) . '">' . esc_html( Admin::relative_date( $item->content_updated_at ) ) . '</span>';
	}

	/**
	 * Default column.
	 *
	 * @param object $item        Phonebook.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}
}
