<?php
/**
 * Activation, upgrade and schema management.
 *
 * @package SitePhonebooks
 */

namespace SitePhonebooks;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades database tables, capabilities and rewrite rules.
 *
 * Storage decision: custom tables (InnoDB) rather than posts/postmeta. The
 * plugin needs independent phonebooks with thousands of contacts each,
 * paginated listing, database-level uniqueness, and atomic replace imports
 * inside transactions. Custom tables give all of that with simple indexed
 * queries; postmeta would require serialised blobs or thousands of posts
 * with meta joins and no uniqueness guarantees. Nothing large is stored in
 * autoloaded options.
 */
final class Installer {

	const CAPABILITY = 'manage_site_phonebooks';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether activated network-wide (unsupported, handled per-site).
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			// Network activation is not supported; install for the current site only.
			// Each site that wants phonebooks should activate the plugin individually.
			self::install_site();
			return;
		}
		self::install_site();
	}

	/**
	 * Install or upgrade for the current site and flush rewrite rules.
	 */
	public static function install_site() {
		self::create_tables();
		self::add_capabilities();
		update_option( 'spb_db_version', SPB_DB_VERSION );

		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
			$router = new Feed\Feed_Router();
			$router->add_rewrite_rules();
			flush_rewrite_rules();
			update_option( 'spb_rewrite_version', SPB_REWRITE_VERSION );
		} else {
			// Rewrite API not ready (very early activation): maybe_upgrade() flushes on the next admin load.
			delete_option( 'spb_rewrite_version' );
		}

		if ( ! wp_next_scheduled( 'spb_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'spb_cleanup' );
		}
	}

	/**
	 * Deactivation keeps all data; only the housekeeping event is removed.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'spb_cleanup' );
	}

	/**
	 * Run upgrades when the stored schema version is behind the code version.
	 * Rewrite rules are flushed only when the route version changes.
	 */
	public static function maybe_upgrade() {
		$db_version = get_option( 'spb_db_version' );
		if ( SPB_DB_VERSION !== $db_version ) {
			self::create_tables();
			self::add_capabilities();
			update_option( 'spb_db_version', SPB_DB_VERSION );
		}
		if ( is_admin() && SPB_REWRITE_VERSION !== get_option( 'spb_rewrite_version' ) ) {
			add_action(
				'wp_loaded',
				static function () {
					flush_rewrite_rules();
					update_option( 'spb_rewrite_version', SPB_REWRITE_VERSION );
				}
			);
		}
	}

	/**
	 * Grant the management capability to administrators.
	 */
	public static function add_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Table name helper.
	 *
	 * @param string $name Short table name (phonebooks, contacts, revisions, imports).
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'spb_' . $name;
	}

	/**
	 * Create or update tables with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$phonebooks      = self::table( 'phonebooks' );
		$contacts        = self::table( 'contacts' );
		$revisions       = self::table( 'revisions' );
		$imports         = self::table( 'imports' );

		$sql = "CREATE TABLE {$phonebooks} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  description text NOT NULL,
  prompt varchar(190) NOT NULL DEFAULT 'Prompt',
  access_mode varchar(20) NOT NULL DEFAULT 'token',
  access_token varchar(64) NOT NULL DEFAULT '',
  enabled tinyint(1) NOT NULL DEFAULT 1,
  revision bigint(20) unsigned NOT NULL DEFAULT 1,
  contact_count int(10) unsigned NOT NULL DEFAULT 0,
  content_updated_at datetime NULL DEFAULT NULL,
  last_request_at datetime NULL DEFAULT NULL,
  last_request_status smallint(5) unsigned NULL DEFAULT NULL,
  last_request_agent varchar(190) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  UNIQUE KEY name (name)
) ENGINE=InnoDB {$charset_collate};
CREATE TABLE {$contacts} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  phonebook_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL,
  telephone varchar(64) NOT NULL,
  notes text NOT NULL,
  dedupe_hash char(40) NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY phonebook_dedupe (phonebook_id,dedupe_hash),
  KEY phonebook_name (phonebook_id,name(100))
) ENGINE=InnoDB {$charset_collate};
CREATE TABLE {$revisions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  phonebook_id bigint(20) unsigned NOT NULL,
  revision bigint(20) unsigned NOT NULL DEFAULT 0,
  reason varchar(40) NOT NULL DEFAULT '',
  contact_count int(10) unsigned NOT NULL DEFAULT 0,
  payload longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY phonebook_created (phonebook_id,created_at)
) ENGINE=InnoDB {$charset_collate};
CREATE TABLE {$imports} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  phonebook_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'uploaded',
  source_type varchar(10) NOT NULL DEFAULT 'csv',
  filename varchar(190) NOT NULL DEFAULT '',
  raw_content longblob NULL,
  options longtext NOT NULL,
  base_revision bigint(20) unsigned NOT NULL DEFAULT 0,
  plan longtext NULL,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY phonebook_user (phonebook_id,user_id),
  KEY expires_at (expires_at)
) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Return the storage engine of each plugin table (for diagnostics).
	 *
	 * @return array<string,string>
	 */
	public static function table_engines() {
		global $wpdb;
		$result = array();
		foreach ( array( 'phonebooks', 'contacts', 'revisions', 'imports' ) as $name ) {
			$table = self::table( $name );
			$row   = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			$result[ $table ] = $row && isset( $row['Engine'] ) ? (string) $row['Engine'] : '';
		}
		return $result;
	}
}
