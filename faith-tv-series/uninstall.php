<?php
/**
 * Removes the plugin's settings and cached Faith TV data when it is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'ftvs_settings' );
delete_option( 'ftvs_cache_gen' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'ftvs\_bk\_%'
	    OR option_name LIKE '\_transient\_ftvs\_%'
	    OR option_name LIKE '\_transient\_timeout\_ftvs\_%'"
);
