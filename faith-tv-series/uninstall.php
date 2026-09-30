<?php
/**
 * Removes the plugin's settings and cached Faith TV data when it is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Every option the plugin keeps starts with ftvs_: settings, secrets, cached answers and their outage copies,
// known ids, health, play counts, queues. Transients too.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'ftvs\_%'
	    OR option_name LIKE '\_transient\_ftvs\_%'
	    OR option_name LIKE '\_transient\_timeout\_ftvs\_%'
	    OR option_name LIKE '\_site\_transient\_ftvs\_%'
	    OR option_name LIKE '\_site\_transient\_timeout\_ftvs\_%'"
);
delete_site_transient( 'ftvs_update_release' );

// Series built by hand under Faith Stream > Build a series.
// (Every status, trash and drafts included; the post type is not registered while uninstalling.)
$ftvs_series = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ftvs_series'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $ftvs_series as $ftvs_id ) {
	wp_delete_post( $ftvs_id, true );
}

foreach ( array( 'ftvs_refresh_stale', 'ftvs_purge_pages', 'ftvs_purge_at', 'ftvs_warm', 'ftvs_warm_now', 'ftvs_weekly_email', 'ftvs_followup_retry' ) as $ftvs_hook ) {
	wp_unschedule_hook( $ftvs_hook );
}
