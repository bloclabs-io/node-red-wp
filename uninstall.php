<?php
/**
 * Remove all plugin data when the plugin is deleted.
 *
 * @package node-red-wp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'nrwp_settings' );
delete_option( 'nrwp_data' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup.
$nrwp_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'nrwp_d_' ) . '%'
	)
);

foreach ( $nrwp_options as $nrwp_option ) {
	delete_option( $nrwp_option );
}
