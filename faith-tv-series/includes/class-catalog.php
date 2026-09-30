<?php
/**
 * The connected church's video catalog, whichever platform it lives on.
 *
 * Every source returns the same shapes:
 *   category { id, title, description, image, videos, subcategories }
 *   video    { id, parent, title, description, image, poster, length, added, live, speaker, scripture, tags }
 * Ids are Gideo's 32-character ids, Faith Stream slugs or YouTube ids. Series built by hand in
 * WordPress (FTVS_Manual) sit alongside any source, and two automatic picks ride on top:
 *   @newest    the newest messages from the whole channel
 *   @featured  what the church features on its channel's home page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Catalog {

	const TREE_TTL    = 6 * HOUR_IN_SECONDS;
	const LIBRARY_TTL = HOUR_IN_SECONDS;
	const NEWEST      = '@newest';
	const FEATURED    = '@featured';

	public static function init() {
		add_action( 'ftvs_gone', array( __CLASS__, 'on_gone' ) );
		add_action( 'ftvs_cache_changed', array( __CLASS__, 'on_changed' ), 10, 3 );
	}

	/** Every platform the plugin can read, as source id => client class. */
	public static function sources() {
		$sources = array(
			'faithstream' => 'FTVS_FaithStream_Client',
			'gideo'       => 'FTVS_Gideo_Client',
			'youtube'     => 'FTVS_YouTube_Client',
			'demo'        => 'FTVS_Demo_Client',
		);
		if ( ! FTVS_Settings::extra_sources() ) {
			unset( $sources['youtube'] ); // not offered for now (FTVS_Settings::extra_sources())
		}
		return apply_filters( 'ftvs_sources', $sources );
	}

	/** 'gideo', 'faithstream', 'youtube', 'demo', or '' when no church is connected yet. */
	public static function source() {
		$source = (string) FTVS_Settings::get( 'source' );
		$all    = self::sources();
		return isset( $all[ $source ] ) && class_exists( $all[ $source ] ) ? $source : '';
	}

	public static function connected() {
		switch ( self::source() ) {
			case 'gideo':
				return '' !== (string) FTVS_Settings::get( 'account_id' );
			case 'faithstream':
				return '' !== (string) FTVS_Settings::get( 'fs_url' ) && '' !== (string) FTVS_Settings::get( 'fs_tenant' );
			case 'youtube':
				return (bool) FTVS_Settings::get( 'yt_playlists' ) || '' !== (string) FTVS_Settings::get( 'yt_channel' );
			case 'demo':
				return true;
		}
		return false;
	}

	/** Sample videos only ever show to editors. */
	public static function is_demo() {
		return 'demo' === self::source();
	}

	/**
	 * Cache namespace: changes whenever a different church is connected.
	 * For Gideo it is the bare account id, the same key 1.1 used, so caches and
	 * outage backups carry over through the update.
	 */
	public static function identity() {
		switch ( self::source() ) {
			case 'faithstream':
				return 'f:' . FTVS_Settings::get( 'fs_url' ) . '|' . FTVS_Settings::get( 'fs_tenant' );
			case 'youtube':
				return 'y:' . md5( wp_json_encode( array( FTVS_Settings::get( 'yt_channel' ), FTVS_Settings::get( 'yt_playlists' ) ) ) );
			case 'demo':
				return 'demo';
		}
		return (string) FTVS_Settings::get( 'account_id' );
	}

	/** Client class of the connected source. */
	public static function client() {
		$all    = self::sources();
		$source = self::source();
		return '' !== $source ? $all[ $source ] : 'FTVS_Gideo_Client';
	}

	private static function has( $method ) {
		return method_exists( self::client(), $method );
	}

	private static function not_connected() {
		return new WP_Error( 'ftvs_not_connected', __( 'No church is connected yet. Go to Faith Stream > Church in the WordPress admin.', 'faith-tv-series' ) );
	}

	/** Can this source tell us what's live? */
	public static function has_live() {
		return self::connected() && self::has( 'get_live' );
	}

	/**
	 * Sub-categories and videos directly inside a category ('' = the home rows).
	 *
	 * @return array|WP_Error { categories: array, videos: array }
	 */
	public static function get_children( $id = '' ) {
		if ( self::NEWEST === $id ) {
			return self::newest();
		}
		if ( self::FEATURED === $id ) {
			return self::featured();
		}
		if ( FTVS_Manual::owns( $id ) ) {
			return FTVS_Manual::get_children( $id );
		}
		if ( ! self::connected() ) {
			return self::not_connected();
		}
		if ( '' !== $id && ! self::is_id( $id ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a category on your channel.', 'faith-tv-series' ) );
		}
		$data = call_user_func( array( self::client(), 'get_children' ), $id );
		if ( ! is_wp_error( $data ) ) {
			self::learn( $id, $data );
		}
		return $data;
	}

	/** The newest messages across the whole channel. */
	public static function newest( $limit = 24 ) {
		$videos = array();
		if ( 'faithstream' === self::source() ) {
			$home = FTVS_FaithStream_Client::get_home();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			if ( $home['newest'] ) {
				$videos[] = $home['newest'];
			}
			foreach ( $home['rows'] as $row ) {
				$videos = array_merge( $videos, $row['videos'] );
			}
		} else {
			$videos = self::library();
			if ( is_wp_error( $videos ) ) {
				return $videos;
			}
		}
		$videos = self::sort_newest( self::unique( $videos ) );
		$data   = array(
			'categories' => array(),
			'videos'     => array_slice( $videos, 0, $limit ),
		);
		self::learn( '', $data );
		return $data;
	}

	/** What the church features at the top of its channel (Faith Stream), or its first home row. */
	public static function featured() {
		if ( ! self::connected() ) {
			$manual = FTVS_Manual::tree_row();
			return $manual ? FTVS_Manual::get_children( $manual['id'] ) : self::not_connected();
		}
		if ( 'faithstream' === self::source() ) {
			$home = FTVS_FaithStream_Client::get_home();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			$out = array(
				'categories' => array(),
				'videos'     => array(),
			);
			foreach ( $home['rows'] as $row ) {
				if ( 'hero' === $row['style'] ) {
					$out['videos'] = array_merge( $out['videos'], $row['videos'] );
				} elseif ( 'slider' === $row['style'] ) {
					// The row's own listing gives series without artwork their first episode's picture.
					$inside            = FTVS_FaithStream_Client::get_children( $row['category']['id'] );
					$out['categories'] = array_merge( $out['categories'], is_wp_error( $inside ) ? $row['children'] : $inside['categories'] );
				}
			}
			if ( $out['categories'] || $out['videos'] ) {
				self::learn( '', $out );
				return $out;
			}
		}
		$home = self::get_children( '' );
		if ( is_wp_error( $home ) || ! $home['categories'] ) {
			return is_wp_error( $home ) ? $home : new WP_Error( 'ftvs_empty', __( 'This category has nothing published yet.', 'faith-tv-series' ) );
		}
		return self::get_children( $home['categories'][0]['id'] );
	}

	/**
	 * Every video on the channel, newest first (for search, "newest" on sources without a
	 * newest list, the sermon library, watch pages and the podcast feed).
	 *
	 * @return array|WP_Error List of videos, each with 'series' (its series' title).
	 */
	public static function library() {
		if ( ! self::connected() ) {
			// Only series built by hand: they are the whole library (search, message pages, "newest").
			$manual = FTVS_Manual::library();
			if ( ! $manual ) {
				return self::not_connected();
			}
			$manual = self::sort_newest( $manual );
			self::learn( '', array( 'categories' => array(), 'videos' => $manual ) );
			return $manual;
		}
		$list = FTVS_Cache::remember( 'library', self::LIBRARY_TTL, array( __CLASS__, 'fetch_library', array() ) );
		if ( ! is_wp_error( $list ) ) {
			$list = array_merge( $list, FTVS_Manual::library() );
			self::learn( '', array( 'categories' => array(), 'videos' => $list ) );
		}
		return $list;
	}

	/** @internal */
	public static function fetch_library() {
		$client = self::client();
		$titles = array();
		$all    = array();
		$tree   = self::get_tree();
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		// Series built by hand join the library separately (FTVS_Manual::library()).
		$tree = array_values(
			array_filter(
				$tree,
				function ( $row ) {
					return ! FTVS_Manual::owns( $row['id'] );
				}
			)
		);
		foreach ( $tree as $row ) {
			$titles[ $row['id'] ] = $row['title'];
			foreach ( $row['children'] as $child ) {
				$titles[ $child['id'] ] = $child['title'];
			}
		}
		if ( method_exists( $client, 'fetch_all_under' ) ) {
			// Faith Stream: one listing per home row covers every episode below it. Featured rows
			// go last, so a message is listed under its own row rather than "Featured".
			usort(
				$tree,
				function ( $a, $b ) {
					return (int) in_array( $a['style'], array( 'hero', 'slider' ), true ) - (int) in_array( $b['style'], array( 'hero', 'slider' ), true );
				}
			);
			foreach ( $tree as $row ) {
				$videos = call_user_func( array( $client, 'fetch_all_under' ), $row['id'] );
				if ( is_wp_error( $videos ) ) {
					return $videos;
				}
				$all = array_merge( $all, $videos );
			}
		} else {
			$budget = 80; // requests; each one is cached on its own too
			foreach ( $tree as $row ) {
				$ids = $row['children'] ? wp_list_pluck( $row['children'], 'id' ) : array( $row['id'] );
				foreach ( $ids as $id ) {
					if ( --$budget < 0 ) {
						break 2;
					}
					$data = call_user_func( array( $client, 'get_children' ), $id );
					if ( is_wp_error( $data ) && 'ftvs_gone' !== $data->get_error_code() ) {
						return $data; // the saved library is kept instead of a partial one
					}
					if ( ! is_wp_error( $data ) ) {
						foreach ( $data['videos'] as $video ) {
							$video['parent'] = '' !== $video['parent'] ? $video['parent'] : $id;
							$all[]           = $video;
						}
					}
				}
			}
		}
		$all = self::sort_newest( self::unique( $all ) );
		foreach ( $all as $i => $video ) {
			$all[ $i ]['series'] = isset( $titles[ $video['parent'] ] ) ? $titles[ $video['parent'] ] : '';
		}
		return $all;
	}

	/**
	 * Search titles, speakers, scripture, descriptions and series.
	 *
	 * @return array|WP_Error List of videos.
	 */
	public static function search( $q ) {
		$q = trim( (string) $q );
		if ( strlen( $q ) < 2 ) {
			return array();
		}
		$library = self::library();
		if ( is_wp_error( $library ) ) {
			return $library;
		}
		$found = array();
		$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower'; // accented capitals too
		$words = preg_split( '/\s+/', $lower( $q ) );
		foreach ( $library as $video ) {
			$hay = $lower( $video['title'] . ' ' . $video['description'] . ' ' . $video['speaker'] . ' ' . $video['scripture'] . ' ' . implode( ' ', $video['tags'] ) . ' ' . ( isset( $video['series'] ) ? $video['series'] : '' ) );
			$hit = true;
			foreach ( $words as $word ) {
				if ( '' !== $word && false === strpos( $hay, $word ) ) {
					$hit = false;
					break;
				}
			}
			if ( $hit ) {
				$found[ $video['id'] ] = $video;
			}
		}
		// Faith Stream also searches words the lists don't carry (scripture, tags) server-side.
		if ( self::has( 'search' ) ) {
			$more = call_user_func( array( self::client(), 'search' ), $q );
			if ( ! is_wp_error( $more ) ) {
				$by_id = array();
				foreach ( $library as $video ) {
					$by_id[ $video['id'] ] = $video;
				}
				foreach ( $more['videos'] as $video ) {
					if ( ! isset( $found[ $video['id'] ] ) ) {
						$found[ $video['id'] ] = isset( $by_id[ $video['id'] ] ) ? $by_id[ $video['id'] ] : $video;
					}
				}
			}
		}
		return self::sort_newest( array_values( $found ) );
	}

	/** One video's details and stream (Faith Stream also sends related videos). @return array|WP_Error */
	public static function get_video( $id ) {
		if ( FTVS_Manual::owns( $id ) ) {
			return FTVS_Manual::get_video( $id );
		}
		if ( ! self::connected() ) {
			return self::not_connected();
		}
		if ( ! self::is_id( $id ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a video on your channel.', 'faith-tv-series' ) );
		}
		return call_user_func( array( self::client(), 'get_video' ), $id );
	}

	/** A video's details from the library (for pages that need its title without playing it). */
	/** A video's title from what is already cached here (never asks the platform), or ''. */
	public static function cached_title( $id ) {
		$library = '' === (string) $id ? null : FTVS_Cache::peek( 'library' );
		if ( is_array( $library ) ) {
			foreach ( $library as $video ) {
				if ( isset( $video['id'], $video['title'] ) && $video['id'] === $id ) {
					return (string) $video['title'];
				}
			}
		}
		return '';
	}

	public static function find_video( $id ) {
		$library = self::library();
		if ( is_wp_error( $library ) ) {
			return null;
		}
		foreach ( $library as $video ) {
			if ( $video['id'] === $id ) {
				return $video;
			}
		}
		return null;
	}

	/**
	 * Whether an id has been seen in the church's catalog. Public endpoints (REST and embeds)
	 * only fetch known ids, so a visitor can't make the site call the platform for made-up ones.
	 */
	public static function is_known( $id ) {
		if ( FTVS_Manual::owns( $id ) ) {
			return FTVS_Manual::exists( $id );
		}
		if ( ! self::is_id( $id ) ) {
			return false;
		}
		$known = self::known();
		if ( isset( $known[ $id ] ) ) {
			return true;
		}
		// Not seen yet on this site: filling the category list teaches it every row and series.
		self::get_tree();
		$known = self::known();
		return isset( $known[ $id ] );
	}

	private static function known_key() {
		return 'ftvs_known_' . md5( self::identity() );
	}

	private static function known() {
		$known = get_option( self::known_key(), array() );
		return is_array( $known ) ? $known : array();
	}

	/** Remember the category and everything listed in it (writes only when something is new). */
	private static function learn( $id, $data ) {
		$known = self::known();
		$ids   = array_merge( '' === $id || '@' === $id[0] ? array() : array( $id ), wp_list_pluck( $data['categories'], 'id' ), wp_list_pluck( $data['videos'], 'id' ) );
		$new   = false;
		foreach ( $ids as $one ) {
			if ( is_string( $one ) && '' !== $one && ! isset( $known[ $one ] ) ) {
				$known[ $one ] = 1;
				$new           = true;
			}
		}
		if ( $new ) {
			update_option( self::known_key(), $known, false );
		}
	}

	/** Something was removed from the channel: forget it, so links to it stop working here too. */
	public static function on_gone( $key ) {
		if ( preg_match( '/^[cv]_(.+)$/', (string) $key, $m ) ) {
			$known = self::known();
			if ( isset( $known[ $m[1] ] ) ) {
				unset( $known[ $m[1] ] );
				update_option( self::known_key(), $known, false );
				FTVS_Purge::soon();
			}
		}
	}

	/**
	 * The catalog changed (new series, new sermon, a rename, a removal). Page caches may hold the
	 * old list, so ask them to clear, and tell anything listening about brand-new videos.
	 */
	public static function on_changed( $key, $data, $old ) {
		if ( null === $old || ! ( in_array( $key, array( 'home', 'tree', 'library' ), true ) || 0 === strpos( $key, 'c_' ) ) ) {
			return; // first fetch ever, a single video's stream address, or the live status
		}
		FTVS_Purge::soon();
		$before = self::video_ids( $old );
		$seen   = self::known();
		$added  = array();
		foreach ( self::videos_in( $data ) as $video ) {
			if ( ! isset( $before[ $video['id'] ] ) && ! isset( $seen[ $video['id'] ] ) ) {
				$added[ $video['id'] ] = $video;
			}
		}
		if ( $added ) {
			/**
			 * New videos appeared on the channel (not on the first check after connecting).
			 *
			 * @param array $videos Video shapes.
			 */
			do_action( 'ftvs_new_videos', array_values( $added ) );
		}
	}

	private static function videos_in( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}
		if ( isset( $data['videos'] ) && is_array( $data['videos'] ) ) {
			return $data['videos'];
		}
		if ( isset( $data['rows'] ) ) {
			$all = array();
			foreach ( $data['rows'] as $row ) {
				$all = array_merge( $all, $row['videos'] );
			}
			return $all;
		}
		if ( isset( $data[0]['id'], $data[0]['length'] ) ) {
			return $data; // the library
		}
		return array();
	}

	private static function video_ids( $data ) {
		$ids = array();
		foreach ( self::videos_in( $data ) as $video ) {
			$ids[ $video['id'] ] = 1;
		}
		return $ids;
	}

	/** @return string|WP_Error HLS address. */
	public static function get_video_url( $id ) {
		$video = self::get_video( $id );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		return ! empty( $video['hls'] ) ? $video['hls'] : new WP_Error( 'ftvs_no_stream', __( 'This video is not ready to play yet.', 'faith-tv-series' ) );
	}

	public static function is_id( $value ) {
		return is_string( $value ) && ( FTVS_Manual::owns( $value ) || call_user_func( array( self::client(), 'is_id' ), $value ) );
	}

	public static function category_link( $id ) {
		if ( FTVS_Manual::owns( $id ) || '' === $id || '@' === $id[0] ) {
			return '';
		}
		return call_user_func( array( self::client(), 'category_link' ), $id );
	}

	public static function video_link( $video_id, $category_id ) {
		if ( FTVS_Manual::owns( $video_id ) ) {
			return '';
		}
		return call_user_func( array( self::client(), 'video_link' ), $video_id, $category_id );
	}

	/** Web address of the church's channel, for "Watch on ..." links. */
	public static function channel_url() {
		switch ( self::source() ) {
			case 'faithstream':
				return add_query_arg( 'tenant', FTVS_Settings::get( 'fs_tenant' ), trailingslashit( (string) FTVS_Settings::get( 'fs_url' ) ) );
			case 'youtube':
				return FTVS_YouTube_Client::channel_url();
			case 'gideo':
				return (string) FTVS_Settings::get( 'tv_url' );
		}
		return '';
	}

	public static function channel_host() {
		$host = wp_parse_url( self::channel_url(), PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./', '', $host ) : '';
	}

	/**
	 * Home rows plus one level of children. For the admin pages, the Elementor picker,
	 * and looking categories up by name.
	 *
	 * @return array|WP_Error List of categories, each with 'children'.
	 */
	public static function get_tree() {
		if ( ! self::connected() ) {
			$manual = FTVS_Manual::tree_row();
			return $manual ? array( $manual ) : self::not_connected();
		}
		$tree = FTVS_Cache::remember( 'tree', self::TREE_TTL, array( self::client(), 'fetch_tree', array() ) );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		foreach ( $tree as $row ) {
			self::learn( $row['id'], array( 'categories' => $row['children'], 'videos' => array() ) );
		}
		$manual = FTVS_Manual::tree_row();
		if ( $manual ) {
			$tree[] = $manual;
		}
		return $tree;
	}

	/**
	 * Accepts a category id, a category name ("Faith TV Mini Series"), or @newest / @featured.
	 *
	 * @return array|WP_Error { id, title }
	 */
	public static function find_category( $needle ) {
		$needle = trim( (string) $needle );
		if ( '' === $needle ) {
			return new WP_Error( 'ftvs_no_category', __( 'Pick a category.', 'faith-tv-series' ) );
		}
		$auto = array(
			self::NEWEST   => __( 'Latest messages', 'faith-tv-series' ),
			self::FEATURED => __( 'Featured', 'faith-tv-series' ),
		);
		if ( isset( $auto[ strtolower( $needle ) ] ) ) {
			return array(
				'id'    => strtolower( $needle ),
				'title' => $auto[ strtolower( $needle ) ],
			);
		}
		if ( FTVS_Manual::owns( $needle ) ) {
			return FTVS_Manual::exists( $needle ) ? array( 'id' => $needle, 'title' => FTVS_Manual::title( $needle ) ) : new WP_Error( 'ftvs_not_found', __( 'That series was deleted.', 'faith-tv-series' ) );
		}
		if ( ! self::connected() ) {
			return self::not_connected();
		}
		if ( self::is_id( $needle ) ) {
			return array(
				'id'    => 'gideo' === self::source() ? strtolower( $needle ) : $needle,
				'title' => '',
			);
		}
		$tree = self::get_tree();
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$want = self::name_key( $needle );
		// Home rows win over a same-named series further down.
		foreach ( $tree as $row ) {
			if ( self::name_key( $row['title'] ) === $want ) {
				return array( 'id' => $row['id'], 'title' => $row['title'] );
			}
		}
		foreach ( $tree as $row ) {
			foreach ( $row['children'] as $child ) {
				if ( self::name_key( $child['title'] ) === $want ) {
					return array( 'id' => $child['id'], 'title' => $child['title'] );
				}
			}
		}
		/* translators: %s: category name typed by the site editor */
		return new WP_Error( 'ftvs_not_found', sprintf( __( 'No category on your channel is named "%s".', 'faith-tv-series' ), $needle ) );
	}

	/** Title of a category found in the tree (for labels), or ''. */
	public static function title_of( $id ) {
		$tree = self::get_tree();
		if ( is_wp_error( $tree ) ) {
			return '';
		}
		foreach ( $tree as $row ) {
			if ( $row['id'] === $id ) {
				return $row['title'];
			}
			foreach ( $row['children'] as $child ) {
				if ( $child['id'] === $id ) {
					return $child['title'];
				}
			}
		}
		return '';
	}

	/** Adds each item's web link, for the page script. */
	public static function with_links( $data, $category_id ) {
		foreach ( $data['categories'] as $i => $cat ) {
			$data['categories'][ $i ]['link'] = self::category_link( $cat['id'] );
		}
		foreach ( $data['videos'] as $i => $video ) {
			$data['videos'][ $i ]['link']  = self::video_link( $video['id'], $video['parent'] ? $video['parent'] : $category_id );
			$data['videos'][ $i ]['watch'] = FTVS_Watch::url( $video['id'] );
		}
		return $data;
	}

	private static function unique( $videos ) {
		$seen = array();
		$out  = array();
		foreach ( $videos as $video ) {
			if ( ! isset( $seen[ $video['id'] ] ) ) {
				$seen[ $video['id'] ] = 1;
				$out[]                = $video;
			}
		}
		return $out;
	}

	private static function sort_newest( $videos ) {
		usort(
			$videos,
			function ( $a, $b ) {
				return strcmp( self::sortable_date( $b['added'] ), self::sortable_date( $a['added'] ) );
			}
		);
		return $videos;
	}

	/** Gideo sends "2023-05-14 10:00:00", Faith Stream ISO 8601; both sort as UTC timestamps. */
	private static function sortable_date( $raw ) {
		$t = $raw ? strtotime( (string) $raw ) : false;
		return false === $t ? '0000000000' : str_pad( (string) $t, 10, '0', STR_PAD_LEFT );
	}

	private static function name_key( $title ) {
		return strtolower( preg_replace( '/\s+/', ' ', trim( $title ) ) );
	}
}
