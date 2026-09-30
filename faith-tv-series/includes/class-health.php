<?php
/**
 * Keeping it working without anyone watching:
 *   - every hour WP-Cron checks the channel, so sections stay fresh (and page caches get
 *     cleared) even on quiet days, and nobody waits on the platform;
 *   - "Where it's used" finds every page with a video section and checks each one;
 *   - WordPress's Site Health page shows the connection, and a Copy diagnostics button gives
 *     support everything in one paste;
 *   - if the channel keeps failing, or a section on a live page breaks, the admin gets an email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Health {

	const WARM     = 'ftvs_warm';
	const WARM_NOW = 'ftvs_warm_now';
	const USED     = 'ftvs_where_used';

	public static function init() {
		add_action( self::WARM, array( __CLASS__, 'warm' ) );
		add_action( self::WARM_NOW, array( __CLASS__, 'warm' ) );
		add_action( 'save_post', array( __CLASS__, 'forget_used' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'health_route' ) );
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( self::WARM ) ) {
					wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::WARM );
				}
			}
		);
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'faith-tv', 'FTVS_CLI' );
		}
	}

	public static function warm_soon() {
		if ( ! wp_next_scheduled( self::WARM_NOW ) ) {
			wp_schedule_single_event( time(), self::WARM_NOW );
		}
	}

	/** Fetch everything the site shows, in the background; then check the pages that use it. */
	public static function warm() {
		if ( ! FTVS_Catalog::connected() ) {
			return;
		}
		FTVS_Catalog::get_tree();
		FTVS_Catalog::get_children( '' );
		foreach ( self::used_categories() as $id ) {
			FTVS_Catalog::get_children( $id );
		}
		FTVS_Catalog::library();
		// Anything that went stale above was queued; fetch it now instead of on a visitor's page.
		FTVS_Cache::run_queue();
		FTVS_Podcast::rebuild();
		self::check_used( true );
		self::maybe_alert();
		self::schedule_switches();
	}

	/**
	 * A dated section (from/until) switches at a set time, but a page cache would keep showing the
	 * old one. Clear page caches right after each switch in the coming hour.
	 */
	private static function schedule_switches() {
		$now = time();
		foreach ( self::where_used() as $row ) {
			foreach ( array( $row['from'], $row['until'] ) as $when ) {
				$t = '' !== $when ? FTVS_Renderer::local_time( $when ) : 0;
				if ( $t > $now && $t <= $now + 70 * MINUTE_IN_SECONDS && ! wp_next_scheduled( FTVS_Purge::AT, array( $t ) ) ) {
					wp_schedule_single_event( $t + 5, FTVS_Purge::AT, array( $t ) );
				}
			}
		}
	}

	/* ---------- Where it's used ---------- */

	public static function forget_used() {
		delete_transient( self::USED );
	}

	/**
	 * Every post and page with a video section: shortcodes, blocks and Elementor widgets.
	 *
	 * @return array List of { post, title, url, edit, kind, category, layout }.
	 */
	public static function where_used( $fresh = false ) {
		$cached = get_transient( self::USED );
		if ( false !== $cached && ! $fresh ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','future','private','draft') AND post_type NOT IN ('revision','nav_menu_item')
			 AND ( post_content LIKE '%[faith\\_tv\\_%' OR post_content LIKE '%[faithstream%' OR post_content LIKE '%wp:faith-tv/%' ) LIMIT 300"
		);
		$el  = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE '%faith\\_tv\\_%' LIMIT 300" );
		// phpcs:enable
		$found = array();
		foreach ( array_unique( array_map( 'intval', array_merge( $ids, $el ) ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'revision' === $post->post_type ) {
				continue;
			}
			foreach ( self::sections_in( $post ) as $section ) {
				$found[] = array_merge(
					array(
						'post'   => $id,
						'title'  => get_the_title( $post ),
						'status' => $post->post_status,
						'url'    => get_permalink( $post ),
						'edit'   => get_edit_post_link( $id, 'raw' ),
					),
					$section
				);
			}
		}
		set_transient( self::USED, $found, DAY_IN_SECONDS );
		return $found;
	}

	/** @return array List of { kind, category, layout, from, until }. */
	private static function sections_in( $post ) {
		$out = array();
		$pattern = get_shortcode_regex( array( 'faith_tv_series', 'faithstream', 'faith_tv_live', 'faith_tv_library' ) );
		if ( preg_match_all( '/' . $pattern . '/', $post->post_content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $sc ) {
				$atts  = shortcode_parse_atts( $sc[3] );
				$atts  = is_array( $atts ) ? $atts : array();
				$out[] = self::section( 'shortcode', $sc[2], $atts );
			}
		}
		if ( function_exists( 'parse_blocks' ) && false !== strpos( $post->post_content, 'wp:faith-tv/' ) ) {
			foreach ( self::flatten( parse_blocks( $post->post_content ) ) as $block ) {
				if ( 0 === strpos( (string) $block['blockName'], 'faith-tv/' ) ) {
					$out[] = self::section( 'block', str_replace( 'faith-tv/', 'faith_tv_', $block['blockName'] ), $block['attrs'] );
				}
			}
		}
		$data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $data ) && false !== strpos( $data, 'faith_tv_' ) ) {
			$tree = json_decode( $data, true );
			foreach ( self::elementor_widgets( is_array( $tree ) ? $tree : array() ) as $w ) {
				$s        = isset( $w['settings'] ) ? $w['settings'] : array();
				$category = isset( $s['category'] ) ? $s['category'] : '';
				if ( 'custom' === $category ) {
					$category = isset( $s['category_id'] ) ? $s['category_id'] : '';
				}
				$tag   = in_array( $w['widgetType'], array( 'faith_tv_live', 'faith_tv_library' ), true ) ? $w['widgetType'] : 'faith_tv_series';
				$out[] = self::section( 'elementor', $tag, array_merge( $s, array( 'category' => $category ) ) );
			}
		}
		return $out;
	}

	private static function section( $kind, $tag, $atts ) {
		$category = isset( $atts['category'] ) ? (string) $atts['category'] : '';
		if ( 'faithstream' === $tag ) {
			// Faith Stream's own shortcode: [faithstream live="..."] and [faithstream library="..."] too.
			if ( ! empty( $atts['live'] ) ) {
				$tag = 'faith_tv_live';
			} elseif ( ! empty( $atts['library'] ) ) {
				$tag      = 'faith_tv_library';
				$category = 'all' === $atts['library'] ? '' : (string) $atts['library'];
			} elseif ( ! empty( $atts['video'] ) && '' === $category ) {
				$category = 'video:' . $atts['video'];
			}
		}
		return array(
			'kind'     => $kind,
			'type'     => 'faith_tv_live' === $tag ? 'live' : ( 'faith_tv_library' === $tag ? 'library' : 'series' ),
			'category' => 'faith_tv_live' === $tag ? 'live' : $category,
			'video'    => isset( $atts['video'] ) ? (string) $atts['video'] : '',
			'layout'   => isset( $atts['layout'] ) && 'default' !== $atts['layout'] ? (string) $atts['layout'] : '',
			'from'     => isset( $atts['from'] ) ? (string) $atts['from'] : '',
			'until'    => isset( $atts['until'] ) ? (string) $atts['until'] : '',
			'otherwise' => isset( $atts['otherwise'] ) ? (string) $atts['otherwise'] : '',
		);
	}

	private static function flatten( $blocks ) {
		$out = array();
		foreach ( $blocks as $b ) {
			$out[] = $b;
			if ( ! empty( $b['innerBlocks'] ) ) {
				$out = array_merge( $out, self::flatten( $b['innerBlocks'] ) );
			}
		}
		return $out;
	}

	private static function elementor_widgets( $nodes ) {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( isset( $node['widgetType'] ) && 0 === strpos( $node['widgetType'], 'faith_tv_' ) ) {
				$out[] = $node;
			}
			if ( ! empty( $node['elements'] ) ) {
				$out = array_merge( $out, self::elementor_widgets( $node['elements'] ) );
			}
		}
		return $out;
	}

	/** Categories pages show (so the hourly check keeps them fresh). */
	public static function used_categories() {
		$ids = array();
		foreach ( self::where_used() as $s ) {
			foreach ( array( $s['category'], $s['otherwise'] ) as $id ) {
				if ( '' !== $id && 'live' !== $id && 0 !== strpos( $id, 'video:' ) ) {
					$ids[ $id ] = 1;
				}
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Each section's status: ok, or what is wrong.
	 *
	 * @return array where_used() rows plus 'ok' (bool) and 'problem' (string).
	 */
	public static function check_used( $store = false ) {
		$rows = self::where_used();
		foreach ( $rows as $i => $row ) {
			$problem = '';
			if ( 'series' === $row['type'] || 'library' === $row['type'] ) {
				$cat = '' !== $row['video'] ? null : ( 'library' === $row['type'] && '' === $row['category'] ? null : FTVS_Catalog::find_category( $row['category'] ) );
				if ( is_wp_error( $cat ) ) {
					$problem = $cat->get_error_message();
				} elseif ( $cat ) {
					$data = FTVS_Catalog::get_children( $cat['id'] );
					if ( is_wp_error( $data ) ) {
						$problem = 'ftvs_gone' === $data->get_error_code() ? __( 'This category was removed or renamed on your channel. Pick it again.', 'faith-tv-series' ) : $data->get_error_message();
					} elseif ( ! $data['categories'] && ! $data['videos'] ) {
						$problem = __( 'This category has nothing published yet.', 'faith-tv-series' );
					}
				}
			}
			$rows[ $i ]['ok']      = '' === $problem;
			$rows[ $i ]['problem'] = $problem;
		}
		if ( $store ) {
			update_option( 'ftvs_used_status', array( 't' => time(), 'rows' => $rows ), false );
		}
		return $rows;
	}

	/* ---------- Alerts ---------- */

	/** Email the admin (at most once a day) when the channel keeps failing or a live page's section breaks. */
	private static function maybe_alert() {
		if ( ! FTVS_Settings::get( 'alert_email' ) || FTVS_Catalog::is_demo() || get_transient( 'ftvs_alerted' ) ) {
			return;
		}
		$h      = FTVS_Cache::health();
		$lines  = array();
		if ( $h['fails'] >= 3 && $h['failing_at'] && time() - $h['failing_at'] > HOUR_IN_SECONDS ) {
			/* translators: 1: how long, 2: error message */
			$lines[] = sprintf( __( 'Your video platform has not answered for %1$s (%2$s). Visitors are seeing the last list the site saved.', 'faith-tv-series' ), human_time_diff( $h['failing_at'] ), $h['error'] );
		}
		$status = get_option( 'ftvs_used_status', array() );
		foreach ( isset( $status['rows'] ) ? $status['rows'] : array() as $row ) {
			if ( ! $row['ok'] && 'publish' === $row['status'] ) {
				/* translators: 1: page title, 2: problem */
				$lines[] = sprintf( __( 'The video section on "%1$s" is not showing: %2$s', 'faith-tv-series' ), $row['title'], $row['problem'] );
			}
		}
		if ( ! $lines ) {
			return;
		}
		set_transient( 'ftvs_alerted', 1, DAY_IN_SECONDS );
		wp_mail(
			get_option( 'admin_email' ),
			/* translators: %s: site name */
			sprintf( __( '%s: a video section needs attention', 'faith-tv-series' ), get_bloginfo( 'name' ) ),
			implode( "\n\n", $lines ) . "\n\n" . FTVS_Admin::url( 'faith-stream-health' ) . "\n\n" . __( 'You can turn these emails off under Faith Stream > Health.', 'faith-tv-series' )
		);
	}

	/* ---------- Site Health ---------- */

	public static function tests( $tests ) {
		$tests['direct']['ftvs_connection'] = array(
			'label' => __( 'Faith Stream videos', 'faith-tv-series' ),
			'test'  => array( __CLASS__, 'test_connection' ),
		);
		$tests['async']['ftvs_rest'] = array(
			'label'             => __( 'Visitors can open Faith Stream videos', 'faith-tv-series' ),
			'test'              => rest_url( 'faith-tv/v1/admin/health-rest' ),
			'has_rest'          => true,
			'async_direct_test' => array( __CLASS__, 'test_rest' ),
		);
		return $tests;
	}

	private static function result( $status, $label, $text, $test = 'ftvs_connection' ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Faith Stream', 'faith-tv-series' ),
				'color' => 'good' === $status ? 'blue' : 'red',
			),
			'description' => '<p>' . esc_html( $text ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( FTVS_Admin::url( 'faith-stream-health' ) ) . '">' . esc_html__( 'Open Faith Stream > Health', 'faith-tv-series' ) . '</a></p>',
			'test'        => $test, // Site Health uses it in the panel's id: one per check
		);
	}

	public static function test_connection() {
		if ( ! FTVS_Catalog::connected() ) {
			return self::result( 'recommended', __( 'No church is connected to Faith Stream yet', 'faith-tv-series' ), __( 'Video sections show nothing until a church is connected.', 'faith-tv-series' ) );
		}
		$h = FTVS_Cache::health();
		if ( $h['fails'] && $h['failing_at'] && time() - $h['failing_at'] > 30 * MINUTE_IN_SECONDS ) {
			/* translators: 1: how long, 2: error */
			return self::result( 'critical', __( 'Your video platform is not answering', 'faith-tv-series' ), sprintf( __( 'For %1$s: %2$s. Visitors see the last list the site saved.', 'faith-tv-series' ), human_time_diff( $h['failing_at'] ), $h['error'] ) );
		}
		/* translators: %s: how long ago */
		return self::result( 'good', __( 'Faith Stream videos are up to date', 'faith-tv-series' ), $h['last_ok'] ? sprintf( __( 'Last checked %s ago.', 'faith-tv-series' ), human_time_diff( $h['last_ok'] ) ) : __( 'Connected.', 'faith-tv-series' ) );
	}

	public static function health_route() {
		register_rest_route(
			FTVS_Rest::NS,
			'/admin/health-rest',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return rest_ensure_response( FTVS_Health::test_rest() );
				},
				'permission_callback' => function () {
					return current_user_can( 'view_site_health_checks' );
				},
			)
		);
	}

	/** Can a logged-out visitor reach the endpoints the player needs? (Security plugins sometimes block them.) */
	public static function test_rest() {
		$response = wp_remote_get(
			rest_url( 'faith-tv/v1/ping' ),
			array(
				'timeout'   => 10,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
				'cookies'   => array(),
			)
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return self::result( 'good', __( 'Visitors can open and play videos', 'faith-tv-series' ), __( 'The address the player uses answers normally.', 'faith-tv-series' ), 'ftvs_rest' );
		}
		/* translators: %s: address */
		$text = sprintf( __( 'The player loads each series and video from %s, and it did not answer for a logged-out visitor. A security plugin or firewall rule may be blocking it; allow that address.', 'faith-tv-series' ), rest_url( 'faith-tv/v1/' ) );
		// A loopback request can fail on some hosts even when visitors are fine.
		return self::result( 'recommended', __( 'The site could not check the video player\'s address', 'faith-tv-series' ), $text, 'ftvs_rest' );
	}

	public static function debug_information( $info ) {
		$info['faith-tv-series'] = array(
			'label'  => __( 'Faith Stream videos', 'faith-tv-series' ),
			'fields' => array(),
		);
		foreach ( self::facts() as $key => $fact ) {
			$info['faith-tv-series']['fields'][ $key ] = array(
				'label' => $fact[0],
				'value' => $fact[1],
			);
		}
		return $info;
	}

	/** @return array key => [ label, value ] */
	public static function facts() {
		$h     = FTVS_Cache::health();
		$purge = get_option( 'ftvs_last_purge', array() );
		$ping  = get_option( 'ftvs_last_ping', array() );
		$cron  = wp_next_scheduled( self::WARM );
		return array(
			'version'  => array( __( 'Plugin version', 'faith-tv-series' ), FTVS_VERSION ),
			'source'   => array( __( 'Videos come from', 'faith-tv-series' ), FTVS_Catalog::source() ? FTVS_Catalog::source() : '-' ),
			'channel'  => array( __( 'Channel', 'faith-tv-series' ), FTVS_Catalog::channel_url() ),
			'last_ok'  => array( __( 'Last good check', 'faith-tv-series' ), $h['last_ok'] ? gmdate( 'Y-m-d H:i', $h['last_ok'] ) . ' UTC' : '-' ),
			'error'    => array( __( 'Last error', 'faith-tv-series' ), $h['last_error'] ? gmdate( 'Y-m-d H:i', $h['last_error'] ) . ' UTC: ' . $h['error'] . ' (' . $h['error_key'] . ')' : '-' ),
			'fails'    => array( __( 'Failures in a row', 'faith-tv-series' ), (string) (int) $h['fails'] ),
			'cache'    => array( __( 'Checks for new videos every', 'faith-tv-series' ), (int) FTVS_Settings::get( 'cache_minutes' ) . ' min' ),
			'cron'     => array( __( 'Background check (WP-Cron)', 'faith-tv-series' ), $cron ? gmdate( 'Y-m-d H:i', $cron ) . ' UTC' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' (DISABLE_WP_CRON)' : '' ) : '-' ),
			'purge'    => array( __( 'Last page-cache clear', 'faith-tv-series' ), ! empty( $purge['t'] ) ? gmdate( 'Y-m-d H:i', $purge['t'] ) . ' UTC ' . implode( ', ', (array) $purge['caches'] ) : '-' ),
			'ping'     => array( __( 'Last update from Faith Stream', 'faith-tv-series' ), ! empty( $ping['t'] ) ? gmdate( 'Y-m-d H:i', $ping['t'] ) . ' UTC ' . $ping['event'] : '-' ),
			'watch'    => array( __( 'Watch page', 'faith-tv-series' ), FTVS_Watch::page_id() ? get_permalink( FTVS_Watch::page_id() ) : '-' ),
			'rest'     => array( __( 'Player address', 'faith-tv-series' ), rest_url( 'faith-tv/v1/' ) ),
			'wp'       => array( 'WordPress / PHP', get_bloginfo( 'version' ) . ' / ' . PHP_VERSION ),
			'builders' => array( __( 'Page builders and caches', 'faith-tv-series' ), implode( ', ', self::active_extras() ) ),
		);
	}

	private static function active_extras() {
		$found = array();
		$map   = array(
			'ELEMENTOR_VERSION' => 'Elementor',
			'WP_ROCKET_VERSION' => 'WP Rocket',
			'LSCWP_V'           => 'LiteSpeed Cache',
			'W3TC'              => 'W3 Total Cache',
			'WPCACHEHOME'       => 'WP Super Cache',
			'WPFC_WP_PLUGIN_DIR' => 'WP Fastest Cache',
			'WPSEO_VERSION'     => 'Yoast SEO',
			'WORDFENCE_VERSION' => 'Wordfence',
		);
		foreach ( $map as $const => $name ) {
			if ( defined( $const ) ) {
				$found[] = $name;
			}
		}
		return $found ? $found : array( '-' );
	}

	/** Everything support needs, as plain text to paste into an email. */
	public static function diagnostics() {
		$lines = array( 'Faith TV Series diagnostics, ' . gmdate( 'Y-m-d H:i' ) . ' UTC', 'Site: ' . home_url( '/' ) );
		foreach ( self::facts() as $fact ) {
			$lines[] = $fact[0] . ': ' . $fact[1];
		}
		$lines[] = '';
		$lines[] = 'Video sections:';
		foreach ( self::check_used() as $row ) {
			$lines[] = '- ' . $row['title'] . ' (' . $row['kind'] . ', ' . $row['type'] . ', ' . ( '' !== $row['category'] ? $row['category'] : '-' ) . '): ' . ( $row['ok'] ? 'OK' : $row['problem'] );
		}
		return implode( "\n", $lines );
	}
}

/**
 * wp faith-tv status | refresh | where-used
 */
class FTVS_CLI {

	/** Connection, cache and background-check status. */
	public function status() {
		foreach ( FTVS_Health::facts() as $fact ) {
			WP_CLI::line( str_pad( $fact[0], 34 ) . $fact[1] );
		}
	}

	/** Fetch everything fresh from the channel now, and clear page caches. */
	public function refresh() {
		FTVS_Cache::clear();
		FTVS_Health::warm();
		$done = FTVS_Purge::now();
		WP_CLI::success( 'Refreshed.' . ( $done ? ' Cleared: ' . implode( ', ', $done ) : '' ) );
	}

	/**
	 * Every page with a video section, and whether each one works.
	 *
	 * @subcommand where-used
	 */
	public function where_used() {
		FTVS_Health::where_used( true );
		$rows = array();
		foreach ( FTVS_Health::check_used() as $row ) {
			$rows[] = array(
				'page'     => $row['title'],
				'kind'     => $row['kind'],
				'category' => $row['category'],
				'status'   => $row['ok'] ? 'OK' : $row['problem'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'page', 'kind', 'category', 'status' ) );
	}
}
