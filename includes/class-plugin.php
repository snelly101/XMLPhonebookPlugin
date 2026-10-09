<?php
/**
 * Plugin bootstrap.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components into WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boot the plugin once.
	 *
	 * @return Plugin
	 */
	public static function boot() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}
		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );

		$router = new Feed\Feed_Router();
		$router->register();

		add_action( 'spb_cleanup', array( Import\Import_Staging::class, 'cleanup_expired' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );

		if ( is_admin() ) {
			$admin = new Admin\Admin();
			$admin->register();
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'site-phonebooks', false, dirname( SPB_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Ensure the housekeeping event is scheduled. Housekeeping only deletes
	 * expired staged imports; feed publication never depends on cron.
	 */
	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'spb_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'spb_cleanup' );
		}
	}
}
