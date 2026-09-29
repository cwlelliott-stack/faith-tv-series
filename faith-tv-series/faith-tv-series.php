<?php
/**
 * Plugin Name:       Faith TV Series
 * Description:       By FaithStream. Puts your church's video series (from Faith Stream or a Gideo TV channel) live on your website. New series appear by themselves, and visitors watch right on the page.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Faith Tabernacle
 * License:           GPL-2.0-or-later
 * Text Domain:       faith-tv-series
 * Update URI:        https://github.com/cwlelliott-stack/faith-tv-series
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FTVS_VERSION', '1.1.0' );
define( 'FTVS_FILE', __FILE__ );
define( 'FTVS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FTVS_URL', plugin_dir_url( __FILE__ ) );

require_once FTVS_DIR . 'includes/class-settings.php';
require_once FTVS_DIR . 'includes/class-cache.php';
require_once FTVS_DIR . 'includes/class-catalog.php';
require_once FTVS_DIR . 'includes/class-gideo-client.php';
require_once FTVS_DIR . 'includes/class-faithstream-client.php';
require_once FTVS_DIR . 'includes/class-renderer.php';
require_once FTVS_DIR . 'includes/class-rest.php';
require_once FTVS_DIR . 'includes/class-admin.php';
require_once FTVS_DIR . 'includes/class-updater.php';
require_once FTVS_DIR . 'includes/class-embed.php';

FTVS_Settings::maybe_migrate();
FTVS_Embed::init();

add_action( 'init', array( 'FTVS_Renderer', 'register' ) );
add_action( 'rest_api_init', array( 'FTVS_Rest', 'register_routes' ) );

// Update checks also run from WP-Cron and WP-CLI, so this is not admin-only.
FTVS_Updater::init();

if ( is_admin() ) {
	FTVS_Admin::init();
}

// Elementor is optional: the widget only loads when Elementor is active.
add_action(
	'elementor/widgets/register',
	function ( $widgets_manager ) {
		require_once FTVS_DIR . 'includes/class-elementor-widget.php';
		$widgets_manager->register( new FTVS_Elementor_Widget() );
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
