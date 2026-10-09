<?php
/**
 * All Phonebooks screen.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the phonebook list.
 */
final class List_Screen {

	/**
	 * Screen load: help tab.
	 */
	public static function load() {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
		$screen = get_current_screen();
		if ( $screen ) {
			$screen->add_help_tab(
				array(
					'id'      => 'spb-overview',
					'title'   => __( 'Overview', 'site-phonebooks' ),
					'content' => '<p>' . esc_html__( 'Each phonebook serves one site (customer, building or office) and has its own XML feed URL. Open a phonebook to manage its contacts, import files and copy the feed address into your handsets.', 'site-phonebooks' ) . '</p>',
				)
			);
		}
	}

	/**
	 * Render.
	 */
	public static function render() {
		if ( ! Admin::can_manage() ) {
			Admin::deny();
		}
		$table = new Phonebooks_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap spb-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Phonebooks', 'site-phonebooks' ); ?></h1>
			<a href="<?php echo esc_url( Admin::url( Admin::PAGE_EDIT ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Phonebook', 'site-phonebooks' ); ?></a>
			<hr class="wp-header-end">
			<?php Notices::render(); ?>
			<form method="get" id="spb-phonebooks-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_LIST ); ?>">
				<?php $table->search_box( __( 'Search phonebooks', 'site-phonebooks' ), 'spb-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
