<?php
/**
 * Reseller Intent uninstall.
 *
 * Data is only removed when the site owner opted in via
 * Settings → "Delete all tracked data and settings when the plugin is uninstalled".
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rintent_settings = (array) get_option( 'rintent_settings', array() );

if ( empty( $rintent_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$rintent_table = $wpdb->prefix . 'rintent_events';
$wpdb->query( "DROP TABLE IF EXISTS {$rintent_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'rintent_settings' );
delete_option( 'rintent_db_version' );
delete_option( 'rintent_import_done' );
delete_option( 'rintent_tld_strip_sets' );
delete_option( 'rintent_tld_last_good' );

// TLD price transients (both storage forms).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%rintent_tld_prices_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

wp_clear_scheduled_hook( 'rintent_auto_purge' );
wp_clear_scheduled_hook( 'rintent_tld_prefetch' );
wp_clear_scheduled_hook( 'rintent_tld_prefetch', array( 'refresh' ) );
wp_clear_scheduled_hook( 'rintent_weekly_digest' );
