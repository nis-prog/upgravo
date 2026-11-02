<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes every option Upgravo created
 * (scan state, cached WordPress core class index per WP version).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$phpready_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'phpready_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: names of our own options are dynamic (per WP version)
foreach ( $phpready_options as $phpready_option ) {
	delete_option( $phpready_option );
}
delete_metadata( 'user', 0, 'phpready_review', '', true );
