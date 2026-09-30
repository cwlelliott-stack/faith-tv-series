<?php
/**
 * Clears saved pages in the site's page cache, so a new series or sermon really shows up
 * (sections are built on the server, and a cache plugin can keep serving the old page).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Purge {

	const CRON = 'ftvs_purge_pages';
	// At a scheduled section's switch time (the argument is that time).
	const AT = 'ftvs_purge_at';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'now' ) );
		add_action( self::AT, array( __CLASS__, 'now' ) );
	}

	/** Purge within a minute; many changes in a row cause one purge. */
	public static function soon() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/** @return string[] Names of the caches that were asked to clear. */
	public static function now() {
		$done = array();
		// WordPress core's own caches first.
		if ( function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'ftvs' );
		}
		$calls = array(
			'WP Rocket'      => function () {
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
					return true;
				}
			},
			'LiteSpeed'      => function () {
				if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) ) {
					do_action( 'litespeed_purge_all' );
					return true;
				}
			},
			'W3 Total Cache' => function () {
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
					return true;
				}
			},
			'WP Super Cache' => function () {
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache();
					return true;
				}
			},
			'WP Fastest Cache' => function () {
				if ( function_exists( 'wpfc_clear_all_cache' ) ) {
					wpfc_clear_all_cache( true );
					return true;
				}
			},
			'SiteGround'     => function () {
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					sg_cachepress_purge_cache();
					return true;
				}
			},
			'Kinsta'         => function () {
				global $kinsta_cache;
				if ( is_object( $kinsta_cache ) && isset( $kinsta_cache->kinsta_cache_purge ) && method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ) ) {
					$kinsta_cache->kinsta_cache_purge->purge_complete_caches();
					return true;
				}
			},
			'WP Engine'      => function () {
				if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					WpeCommon::purge_memcached();
					WpeCommon::purge_varnish_cache();
					return true;
				}
			},
			'Cloudflare'     => function () {
				if ( has_action( 'cloudflare_purge_everything' ) ) {
					do_action( 'cloudflare_purge_everything' );
					return true;
				}
			},
			'Breeze'         => function () {
				if ( has_action( 'breeze_clear_all_cache' ) ) {
					do_action( 'breeze_clear_all_cache' );
					return true;
				}
			},
			'Elementor'      => function () {
				if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
					return true;
				}
			},
		);
		foreach ( $calls as $name => $call ) {
			try {
				if ( true === $call() ) {
					$done[] = $name;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// A cache plugin failing must never break the site.
			}
		}
		update_option(
			'ftvs_last_purge',
			array(
				't'      => time(),
				'caches' => $done,
			),
			false
		);
		/**
		 * The plugin asked page caches to clear. Hosts and custom caches can hook in here.
		 * (Not named like the CRON hook, which runs this method: that would call it again, forever.)
		 *
		 * @param string[] $done Caches that were cleared.
		 */
		do_action( 'ftvs_pages_purged', $done );
		return $done;
	}
}
