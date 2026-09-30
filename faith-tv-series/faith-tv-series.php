<?php
/**
 * Plugin Name:       Faith TV Series
 * Description:       By FaithStream. Puts your church's videos live on your website: series, Sunday live, a searchable sermon library and a page for every message, from Faith Stream, a Gideo TV channel or YouTube.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            FaithStream
 * Author URI:        https://faithstream.video
 * License:           GPL-2.0-or-later
 * Text Domain:       faith-tv-series
 * Domain Path:       /languages
 * Update URI:        https://github.com/cwlelliott-stack/faith-tv-series
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FTVS_VERSION', '1.2.1' );
define( 'FTVS_FILE', __FILE__ );
define( 'FTVS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FTVS_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'settings', 'cache', 'purge', 'catalog', 'gideo-client', 'faithstream-client', 'youtube-client', 'demo-client', 'manual', 'watch', 'live', 'stats', 'followup', 'health', 'renderer', 'rest', 'admin', 'embed', 'podcast', 'blocks' ) as $ftvs_file ) {
	require_once FTVS_DIR . 'includes/class-' . $ftvs_file . '.php';
}
// The wordpress.org edition is updated by WordPress itself and leaves this file out.
if ( file_exists( FTVS_DIR . 'includes/class-updater.php' ) ) {
	require_once FTVS_DIR . 'includes/class-updater.php';
}
unset( $ftvs_file );

FTVS_Settings::maybe_migrate();
FTVS_Settings::maybe_upgrade();
FTVS_Cache::init();
FTVS_Purge::init();
FTVS_Catalog::init();
FTVS_Manual::init();
FTVS_Watch::init();
FTVS_Live::init();
FTVS_Followup::init();
FTVS_Health::init();
FTVS_Podcast::init();
FTVS_Embed::init();
FTVS_Blocks::init();

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'faith-tv-series', false, dirname( plugin_basename( FTVS_FILE ) ) . '/languages' );
		FTVS_Renderer::register();
		FTVS_Stats::init();
	},
	5
);
add_action( 'rest_api_init', array( 'FTVS_Rest', 'register_routes' ) );

// Update checks also run from WP-Cron and WP-CLI, so this is not admin-only.
if ( class_exists( 'FTVS_Updater' ) ) {
	FTVS_Updater::init();
}

if ( is_admin() ) {
	FTVS_Admin::init();
}

// Elementor is optional: the widgets only load when Elementor is active.
add_action(
	'elementor/widgets/register',
	function ( $widgets_manager ) {
		require_once FTVS_DIR . 'includes/class-elementor-widget.php';
		$widgets_manager->register( new FTVS_Elementor_Widget() );
		$widgets_manager->register( new FTVS_Elementor_Live_Widget() );
		$widgets_manager->register( new FTVS_Elementor_Library_Widget() );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=' . FTVS_Admin::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'faith-tv-series' ) . '</a>' );
		return $links;
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		foreach ( array( FTVS_Cache::CRON, FTVS_Purge::CRON, FTVS_Purge::AT, FTVS_Health::WARM, FTVS_Health::WARM_NOW, FTVS_Stats::WEEKLY, FTVS_Followup::CRON ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		// Our /watch/ and podcast rules are still registered in this request, so a flush would keep them: let
		// WordPress rebuild the rules on the next page load instead, and add ours again after a reactivation.
		delete_option( 'rewrite_rules' );
		delete_option( 'ftvs_rewrite_ver' );
		delete_option( 'ftvs_feed_ver' );
	}
);
