<?php
/**
 * Uninstall handler for Site Phonebooks.
 *
 * Data is preserved by default. Tables and options are only removed when an
 * administrator has explicitly enabled "Delete all data on uninstall" in
 * Phonebooks > Settings.
 *
 * @package SitePhonebooks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$spb_settings = get_option( 'spb_settings', array() );
if ( empty( $spb_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$spb_tables = array(
	$wpdb->prefix . 'spb_contacts',
	$wpdb->prefix . 'spb_revisions',
	$wpdb->prefix . 'spb_imports',
	$wpdb->prefix . 'spb_phonebooks',
);
foreach ( $spb_tables as $spb_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$spb_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'spb_settings' );
delete_option( 'spb_db_version' );
delete_option( 'spb_rewrite_version' );

$spb_role = get_role( 'administrator' );
if ( $spb_role ) {
	$spb_role->remove_cap( 'manage_site_phonebooks' );
}

wp_clear_scheduled_hook( 'spb_cleanup' );
