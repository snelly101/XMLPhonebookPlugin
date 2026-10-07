<?php
/**
 * Admin bootstrap: menus, assets, screen dispatch.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks\Admin;

use SitePhonebooks\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Phonebooks menu and routes to screen renderers.
 */
final class Admin {

	const PAGE_LIST     = 'site-phonebooks';
	const PAGE_EDIT     = 'site-phonebooks-edit';
	const PAGE_SETTINGS = 'site-phonebooks-settings';
	const PAGE_HELP     = 'site-phonebooks-help';

	/**
	 * Hook suffixes of our screens (for asset loading).
	 *
	 * @var string[]
	 */
	private $hooks = array();

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( Settings_Screen::class, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . SPB_PLUGIN_BASENAME, array( $this, 'plugin_links' ) );
		( new Actions() )->register();
	}

	/**
	 * Capability check helper.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( Installer::CAPABILITY );
	}

	/**
	 * Build an admin URL for one of our pages.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $page = self::PAGE_LIST, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of a phonebook's detail screen.
	 *
	 * @param int    $id   Phonebook ID.
	 * @param string $tab  Tab.
	 * @param array  $args Extra args.
	 * @return string
	 */
	public static function phonebook_url( $id, $tab = 'contacts', array $args = array() ) {
		return self::url( self::PAGE_EDIT, array_merge( array( 'id' => (int) $id, 'tab' => $tab ), $args ) );
	}

	/**
	 * Register menu pages.
	 */
	public function menu() {
		$cap           = Installer::CAPABILITY;
		$this->hooks[] = add_menu_page( __( 'Phonebooks', 'site-phonebooks' ), __( 'Phonebooks', 'site-phonebooks' ), $cap, self::PAGE_LIST, array( List_Screen::class, 'render' ), 'dashicons-phone', 58 );
		$this->hooks[] = add_submenu_page( self::PAGE_LIST, __( 'All Phonebooks', 'site-phonebooks' ), __( 'All Phonebooks', 'site-phonebooks' ), $cap, self::PAGE_LIST, array( List_Screen::class, 'render' ) );
		$this->hooks[] = add_submenu_page( self::PAGE_LIST, __( 'Add Phonebook', 'site-phonebooks' ), __( 'Add Phonebook', 'site-phonebooks' ), $cap, self::PAGE_EDIT, array( Phonebook_Screen::class, 'render' ) );
		$this->hooks[] = add_submenu_page( self::PAGE_LIST, __( 'Phonebook Settings', 'site-phonebooks' ), __( 'Settings', 'site-phonebooks' ), $cap, self::PAGE_SETTINGS, array( Settings_Screen::class, 'render' ) );
		$this->hooks[] = add_submenu_page( self::PAGE_LIST, __( 'Help & Diagnostics', 'site-phonebooks' ), __( 'Help & Diagnostics', 'site-phonebooks' ), $cap, self::PAGE_HELP, array( Help_Screen::class, 'render' ) );
		add_action( 'load-' . $this->hooks[0], array( List_Screen::class, 'load' ) );
		add_action( 'load-' . $this->hooks[2], array( Phonebook_Screen::class, 'load' ) );
	}

	/**
	 * Load assets only on plugin screens.
	 *
	 * @param string $hook Current hook suffix.
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'site-phonebooks-admin', SPB_PLUGIN_URL . 'assets/css/admin.css', array(), SPB_VERSION );
		wp_enqueue_script( 'site-phonebooks-admin', SPB_PLUGIN_URL . 'assets/js/admin.js', array(), SPB_VERSION, true );
		wp_localize_script(
			'site-phonebooks-admin',
			'spbAdmin',
			array(
				'copied'     => __( 'Copied', 'site-phonebooks' ),
				'copyFailed' => __( 'Copy failed. Select the text and copy it manually.', 'site-phonebooks' ),
				'copy'       => __( 'Copy', 'site-phonebooks' ),
				'working'    => __( 'Working…', 'site-phonebooks' ),
			)
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Phonebooks', 'site-phonebooks' ) . '</a>' );
		return $links;
	}

	/**
	 * Die with a permissions error.
	 */
	public static function deny() {
		wp_die( esc_html__( 'You do not have permission to manage phonebooks.', 'site-phonebooks' ), 403 );
	}

	/**
	 * Format a GMT MySQL datetime for display in the site's timezone.
	 *
	 * @param string|null $gmt Datetime.
	 * @return string
	 */
	public static function format_date( $gmt ) {
		if ( empty( $gmt ) || '0000-00-00 00:00:00' === $gmt ) {
			return '—';
		}
		$ts = strtotime( $gmt . ' UTC' );
		if ( ! $ts ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Human relative time for a GMT datetime.
	 *
	 * @param string|null $gmt Datetime.
	 * @return string
	 */
	public static function relative_date( $gmt ) {
		if ( empty( $gmt ) ) {
			return '';
		}
		$ts = strtotime( $gmt . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: human time difference */
		return sprintf( __( '%s ago', 'site-phonebooks' ), human_time_diff( $ts, time() ) );
	}
}
