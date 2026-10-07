<?php
/**
 * Plugin Name:       Site Phonebooks
 * Plugin URI:        https://github.com/snelly101/XMLPhonebookPlugin
 * Description:       Centrally manage multiple site-specific phonebooks and serve each one as a Yealink-compatible XML remote phonebook feed.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Snelson Server
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-phonebooks
 * Domain Path:       /languages
 *
 * @package SitePhonebooks
 */

defined( 'ABSPATH' ) || exit;

define( 'SPB_VERSION', '1.0.0' );
define( 'SPB_DB_VERSION', '1' );
define( 'SPB_REWRITE_VERSION', '1' );
define( 'SPB_PLUGIN_FILE', __FILE__ );
define( 'SPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once SPB_PLUGIN_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'SitePhonebooks\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SitePhonebooks\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SitePhonebooks\\Plugin', 'boot' ), 5 );
