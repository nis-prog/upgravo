<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: removes every option Upgravo created
 * (scan state, cached WordPress core class index per WP version).
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/** Borra las opciones phpready_* del sitio actual. */
function phpready_uninstall_site_options() {
	global $wpdb;
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'phpready_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: names of our own options are dynamic (per WP version)
	foreach ( $names as $name ) {
		delete_option( $name );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $phpready_site_id ) {
		switch_to_blog( $phpready_site_id );
		phpready_uninstall_site_options();
		restore_current_blog();
	}
} else {
	phpready_uninstall_site_options();
}
delete_metadata( 'user', 0, 'phpready_review', '', true );
