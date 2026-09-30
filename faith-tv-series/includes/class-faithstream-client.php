<?php
/**
 * Reads a church's public catalog from Faith Stream.
 *
 *   GET {base}/api/public/home                  home rows (with each row's series), newest video, live
 *   GET {base}/api/public/categories/{slug}     a category's series and videos
 *   GET {base}/api/public/videos/{slug}         one video, with its HLS address and related videos
 *   GET {base}/api/public/live                  live channels
 *   GET {base}/api/public/search?q=             search titles, speakers, scripture and tags
 *   GET {base}/api/tenant                       church name, logo and colors
 * The church is chosen with the X-Tenant header.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_FaithStream_Client {

	// Short: a church using signed Mux playback gets tokens that expire after an hour.
	const VIDEO_TTL  = 5 * MINUTE_IN_SECONDS;
	const LIVE_TTL   = 20;
	const SEARCH_TTL = 5 * MINUTE_IN_SECONDS;
	// Card pictures; Faith Stream sizes them (and signs the size when playback is signed).
	const THUMB = 640;

	public static function base() {
		return untrailingslashit( (string) FTVS_Settings::get( 'fs_url' ) );
	}

	public static function tenant() {
		return (string) FTVS_Settings::get( 'fs_tenant' );
	}

	public static function is_id( $value ) {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,127}$/', $value );
	}

	private static function ttl() {
		return 60 * (int) FTVS_Settings::get( 'cache_minutes' );
	}

	/* ---------- Cached reads ---------- */

	/** Home rows with their series, the newest video and what's live. */
	public static function get_home() {
		return FTVS_Cache::remember( 'home', self::ttl(), array( __CLASS__, 'fetch_home', array() ) );
	}

	public static function get_children( $slug = '' ) {
		if ( '' === $slug ) {
			$home = self::get_home();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			return array(
				'categories' => wp_list_pluck( $home['rows'], 'category' ),
				'videos'     => array(),
			);
		}
		return FTVS_Cache::remember( 'c_' . $slug, self::ttl(), array( __CLASS__, 'fetch_children', array( $slug ) ) );
	}

	/** Home rows, each with 'children', from the one home request. */
	public static function fetch_tree() {
		$home = self::get_home();
		if ( is_wp_error( $home ) ) {
			return $home;
		}
		$tree = array();
		foreach ( $home['rows'] as $row ) {
			$cat                  = $row['category'];
			$cat['children']      = $row['children'];
			$cat['subcategories'] = count( $row['children'] );
			$cat['style']         = $row['style'];
			$tree[]               = $cat;
		}
		return $tree;
	}

	/** @return array|WP_Error Video details plus 'hls' (and 'related'). */
	public static function get_video( $slug ) {
		return FTVS_Cache::remember( 'v_' . $slug, self::VIDEO_TTL, array( __CLASS__, 'fetch_video', array( $slug ) ) );
	}

	public static function get_video_url( $slug ) {
		$video = self::get_video( $slug );
		if ( is_wp_error( $video ) ) {
			return $video;
		}
		return '' !== $video['hls'] ? $video['hls'] : new WP_Error( 'ftvs_no_stream', __( 'This video is not ready to play yet.', 'faith-tv-series' ) );
	}

	/** @return array|WP_Error Live channels. */
	public static function get_live() {
		return FTVS_Cache::remember( 'live', self::LIVE_TTL, array( __CLASS__, 'fetch_live', array() ) );
	}

	/** @return array|WP_Error { videos, categories } */
	public static function search( $q ) {
		return FTVS_Cache::remember( 's_' . md5( strtolower( $q ) ), self::SEARCH_TTL, array( __CLASS__, 'fetch_search', array( $q ) ) );
	}

	/* ---------- Fetchers (no caching; FTVS_Cache and WP-Cron call these) ---------- */

	/** @internal */
	public static function fetch_home() {
		$home = self::get( '/api/public/home?thumb_width=' . self::THUMB );
		if ( is_wp_error( $home ) ) {
			return $home;
		}
		$out = array(
			'rows'   => array(),
			'newest' => empty( $home['newest']['slug'] ) ? null : self::video( $home['newest'], '' ),
			'live'   => empty( $home['live']['slug'] ) ? null : self::channel( $home['live'] ),
		);
		foreach ( isset( $home['rows'] ) ? (array) $home['rows'] : array() as $row ) {
			if ( empty( $row['category']['slug'] ) ) {
				continue;
			}
			$slug     = (string) $row['category']['slug'];
			$videos   = array();
			foreach ( isset( $row['videos'] ) ? (array) $row['videos'] : array() as $video ) {
				if ( ! empty( $video['slug'] ) ) {
					$videos[] = self::video( $video, $slug );
				}
			}
			$children = array();
			foreach ( isset( $row['children'] ) ? (array) $row['children'] : array() as $child ) {
				if ( ! empty( $child['slug'] ) ) {
					$children[] = self::category( $child );
				}
			}
			$out['rows'][] = array(
				'category' => self::category( $row['category'], $row ),
				'style'    => isset( $row['style'] ) ? (string) $row['style'] : '',
				'videos'   => $videos,
				'children' => $children,
			);
		}
		return $out;
	}

	/** @internal */
	public static function fetch_children( $slug ) {
		$out      = array(
			'categories' => array(),
			'videos'     => array(),
		);
		$fallback = array();
		$own      = true;
		// Up to 1,000 videos, 200 at a time (a big archive would otherwise be cut off).
		for ( $page_no = 1; $page_no <= 5; $page_no++ ) {
			$page = self::get( '/api/public/categories/' . rawurlencode( $slug ) . '?per_page=200&page=' . $page_no . '&thumb_width=' . self::THUMB, null, null, true );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			if ( 1 === $page_no ) {
				$out['self'] = empty( $page['category'] ) ? null : self::category( $page['category'] );
				// A series without its own picture borrows its first episode's (from "sections").
				foreach ( isset( $page['sections'] ) ? (array) $page['sections'] : array() as $section ) {
					if ( ! empty( $section['category']['slug'] ) && ! empty( $section['videos'][0]['thumbnail_url'] ) ) {
						$fallback[ $section['category']['slug'] ] = $section['videos'][0]['thumbnail_url'];
					}
				}
				foreach ( isset( $page['children'] ) ? (array) $page['children'] : array() as $child ) {
					if ( empty( $child['thumbnail_url'] ) && isset( $child['slug'], $fallback[ $child['slug'] ] ) ) {
						$child['thumbnail_url'] = $fallback[ $child['slug'] ];
					}
					$out['categories'][] = self::category( $child );
				}
				// A folder with no videos of its own lists every episode below it; those belong to the series.
				$own = ! ( isset( $page['has_own_videos'] ) && ! $page['has_own_videos'] && $out['categories'] );
				if ( ! $own ) {
					break;
				}
			}
			$videos = isset( $page['videos'] ) ? (array) $page['videos'] : array();
			foreach ( $videos as $video ) {
				$out['videos'][] = self::video( $video, $slug );
			}
			// A page can hold fewer than 200 (a video filed twice is listed once), so go by the total.
			$total = isset( $page['total'] ) ? (int) $page['total'] : 0;
			if ( ! $videos || $page_no * 200 >= $total ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @internal Every video under a category (for the library). A folder lists all its
	 * descendants itself; a category with videos of its own lists only those, so its series
	 * are walked too (one level, which is how churches file series).
	 */
	public static function fetch_all_under( $slug, $depth = 0 ) {
		$all  = array();
		$kids = array();
		for ( $page_no = 1; $page_no <= 10; $page_no++ ) {
			$page = self::get( '/api/public/categories/' . rawurlencode( $slug ) . '?per_page=200&page=' . $page_no . '&thumb_width=' . self::THUMB, null, null, true );
			if ( is_wp_error( $page ) ) {
				return 1 === $page_no ? $page : $all;
			}
			if ( 1 === $page_no && ! empty( $page['has_own_videos'] ) ) {
				foreach ( isset( $page['children'] ) ? (array) $page['children'] : array() as $child ) {
					if ( ! empty( $child['slug'] ) ) {
						$kids[] = (string) $child['slug'];
					}
				}
			}
			$videos = isset( $page['videos'] ) ? (array) $page['videos'] : array();
			foreach ( $videos as $video ) {
				$all[] = self::video( $video, $slug );
			}
			if ( ! $videos || $page_no * 200 >= ( isset( $page['total'] ) ? (int) $page['total'] : 0 ) ) {
				break;
			}
		}
		if ( $depth < 1 ) {
			foreach ( array_slice( $kids, 0, 40 ) as $kid ) {
				$more = self::fetch_all_under( $kid, $depth + 1 );
				if ( ! is_wp_error( $more ) ) {
					$all = array_merge( $all, $more );
				}
			}
		}
		return $all;
	}

	/** @internal */
	public static function fetch_video( $slug ) {
		$data = self::get( '/api/public/videos/' . rawurlencode( $slug ) . '?thumb_width=' . self::THUMB, null, null, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$v = isset( $data['video'] ) ? (array) $data['video'] : array();
		if ( empty( $v['slug'] ) ) {
			return new WP_Error( 'ftvs_parse', __( 'Faith Stream sent something we could not read.', 'faith-tv-series' ) );
		}
		$series = array();
		foreach ( isset( $data['categories'] ) ? (array) $data['categories'] : array() as $cat ) {
			if ( ! empty( $cat['slug'] ) ) {
				$series[] = array(
					'id'    => (string) $cat['slug'],
					'title' => trim( (string) ( isset( $cat['name'] ) ? $cat['name'] : '' ) ),
				);
			}
		}
		$related = array();
		foreach ( isset( $data['related'] ) ? (array) $data['related'] : array() as $r ) {
			if ( ! empty( $r['slug'] ) ) {
				$related[] = self::video( $r, '' );
			}
		}
		$video             = self::video( $v, $series ? $series[0]['id'] : '' );
		$hls               = isset( $v['hls_url'] ) ? self::absolute( (string) $v['hls_url'] ) : '';
		$video['hls']      = 0 === strpos( $hls, 'https://' ) ? $hls : '';
		$audio             = isset( $v['audio_url'] ) ? self::absolute( (string) $v['audio_url'] ) : '';
		$video['audio']    = 0 === strpos( $audio, 'https://' ) ? $audio : '';
		$video['captions'] = ! empty( $v['captions'] );
		$video['series']   = $series;
		$video['related']  = $related;
		$video['chat']     = ! empty( $data['chat_enabled'] );
		return $video;
	}

	/** @internal */
	public static function fetch_live() {
		$list = self::get( '/api/public/live' );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$out = array();
		foreach ( (array) $list as $ch ) {
			if ( is_array( $ch ) && ! empty( $ch['slug'] ) ) {
				$out[] = self::channel( $ch );
			}
		}
		return $out;
	}

	/** @internal */
	public static function fetch_search( $q ) {
		$data = self::get( '/api/public/search?q=' . rawurlencode( $q ) . '&thumb_width=' . self::THUMB );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array(
			'videos'     => array(),
			'categories' => array(),
		);
		foreach ( isset( $data['videos'] ) ? (array) $data['videos'] : array() as $v ) {
			if ( ! empty( $v['slug'] ) ) {
				$out['videos'][] = self::video( $v, '' );
			}
		}
		foreach ( isset( $data['categories'] ) ? (array) $data['categories'] : array() as $c ) {
			if ( ! empty( $c['slug'] ) ) {
				$out['categories'][] = self::category( $c );
			}
		}
		return $out;
	}

	/* ---------- Links ---------- */

	public static function category_link( $slug ) {
		return add_query_arg( 'tenant', self::tenant(), self::base() . '/browse/' . rawurlencode( $slug ) );
	}

	public static function video_link( $slug, $category_id ) {
		return add_query_arg( 'tenant', self::tenant(), self::base() . '/watch/' . rawurlencode( $slug ) );
	}

	public static function live_link( $slug ) {
		return add_query_arg( 'tenant', self::tenant(), self::base() . '/live/' . rawurlencode( $slug ) );
	}

	/* ---------- Connecting ---------- */

	/**
	 * Finds a church on a Faith Stream server (used while connecting; not cached).
	 * One address is enough: "stream.yourchurch.com", or the platform address with ?tenant=yourchurch.
	 *
	 * @return array|WP_Error Settings to save plus 'rows' and 'colors' for the preview.
	 */
	public static function lookup( $address, $tenant = '' ) {
		$address = trim( (string) $address );
		if ( '' !== $address && ! preg_match( '#^https?://#i', $address ) ) {
			$address = 'https://' . $address;
		}
		$parts  = wp_parse_url( $address );
		$tenant = strtolower( trim( (string) $tenant ) );
		if ( '' === $tenant && ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$tenant = isset( $query['tenant'] ) ? strtolower( trim( (string) $query['tenant'] ) ) : '';
		}
		if ( empty( $parts['host'] ) ) {
			return new WP_Error( 'ftvs_lookup', __( 'Type your Faith Stream address, for example stream.yourchurch.com.', 'faith-tv-series' ) );
		}
		$base = untrailingslashit( esc_url_raw( ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ) );
		if ( '' !== $tenant && ! self::is_id( $tenant ) ) {
			return new WP_Error( 'ftvs_lookup', __( 'That church ID does not look right.', 'faith-tv-series' ) );
		}
		// Without an ID, Faith Stream works out the church from its own address (custom domain or subdomain).
		$church = self::get( '/api/tenant' . ( '' === $tenant ? '' : '?tenant=' . rawurlencode( $tenant ) ), $base, $tenant );
		if ( is_wp_error( $church ) || empty( $church['name'] ) || empty( $church['slug'] ) ) {
			return new WP_Error(
				'ftvs_lookup',
				'' === $tenant
					? __( 'We could not find a church at that address. If it is the main Faith Stream address, add your church ID under "I have a church ID".', 'faith-tv-series' )
					: __( 'We could not find that church on that Faith Stream address. Check both and try again.', 'faith-tv-series' )
			);
		}
		$tenant = strtolower( (string) $church['slug'] );
		$home   = self::get( '/api/public/home?thumb_width=' . self::THUMB, $base, $tenant );
		$rows   = array();
		foreach ( is_wp_error( $home ) || empty( $home['rows'] ) ? array() : $home['rows'] as $row ) {
			if ( ! empty( $row['category'] ) ) {
				$rows[] = self::category( $row['category'], $row, $base );
			}
		}
		$theme = isset( $church['theme'] ) && is_array( $church['theme'] ) ? $church['theme'] : array();
		$pub   = isset( $church['settings_public'] ) && is_array( $church['settings_public'] ) ? $church['settings_public'] : array();
		return array(
			'source'      => 'faithstream',
			'fs_url'      => $base,
			'fs_tenant'   => $tenant,
			'church_name' => (string) $church['name'],
			'church_logo' => empty( $church['logo_url'] ) ? '' : self::absolute( $church['logo_url'], $base ),
			'rows'        => $rows,
			'colors'      => array(
				'primary' => isset( $theme['primary_color'] ) ? (string) sanitize_hex_color( $theme['primary_color'] ) : '',
				'accent'  => isset( $theme['accent_color'] ) ? (string) sanitize_hex_color( $theme['accent_color'] ) : '',
				'scheme'  => isset( $pub['default_scheme'] ) && 'light' === $pub['default_scheme'] ? 'light' : 'dark',
			),
			'features'    => isset( $pub['features'] ) && is_array( $pub['features'] ) ? array_values( array_filter( $pub['features'], 'is_string' ) ) : null,
		);
	}

	/* ---------- Plumbing ---------- */

	/**
	 * @internal
	 * @param bool $gone_on_404 A 404 means "removed from the channel" (categories and videos only).
	 */
	public static function get( $path, $base = null, $tenant = null, $gone_on_404 = false ) {
		$base     = null === $base ? self::base() : $base;
		$tenant   = null === $tenant ? self::tenant() : $tenant;
		$headers  = array( 'Accept' => 'application/json' );
		if ( '' !== $tenant ) {
			$headers['X-Tenant'] = $tenant;
		}
		$response = wp_safe_remote_get(
			$base . $path,
			array(
				// Visitors never wait long; WP-Cron can.
				'timeout'    => wp_doing_cron() ? 12 : 6,
				'user-agent' => 'FaithTVSeries/' . FTVS_VERSION . '; ' . home_url( '/' ),
				'headers'    => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code && $gone_on_404 ) {
			return new WP_Error( 'ftvs_gone', __( 'That is no longer on your channel.', 'faith-tv-series' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'ftvs_http', sprintf( __( 'Faith Stream answered with status %d.', 'faith-tv-series' ), $code ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'ftvs_parse', __( 'Faith Stream sent something we could not read.', 'faith-tv-series' ) );
	}

	/** @internal */
	public static function category( $c, $row = null, $base = null ) {
		$image = empty( $c['thumbnail_url'] ) ? '' : self::absolute( $c['thumbnail_url'], $base );
		if ( '' === $image && $row && ! empty( $row['videos'][0]['thumbnail_url'] ) ) {
			$image = self::absolute( $row['videos'][0]['thumbnail_url'], $base );
		}
		return array(
			'id'            => (string) $c['slug'],
			'title'         => trim( (string) ( isset( $c['name'] ) ? $c['name'] : '' ) ),
			'description'   => trim( str_replace( array( "\r\n", "\r" ), "\n", (string) ( isset( $c['description'] ) ? $c['description'] : '' ) ) ),
			'image'         => $image,
			'videos'        => isset( $c['video_count'] ) ? (int) $c['video_count'] : 0,
			'subcategories' => isset( $c['children_count'] ) ? (int) $c['children_count'] : ( $row && ! empty( $row['children'] ) ? count( $row['children'] ) : 0 ),
		);
	}

	/** @internal */
	public static function video( $v, $parent ) {
		$image = empty( $v['thumbnail_url'] ) ? '' : self::absolute( $v['thumbnail_url'] );
		$tags  = isset( $v['tags'] ) && is_array( $v['tags'] ) ? array_values( array_filter( array_map( 'strval', $v['tags'] ) ) ) : array();
		return array(
			'id'          => (string) $v['slug'],
			'parent'      => (string) $parent,
			'title'       => trim( (string) ( isset( $v['title'] ) ? $v['title'] : '' ) ),
			'description' => trim( (string) ( isset( $v['description'] ) ? $v['description'] : '' ) ),
			'image'       => $image,
			'poster'      => self::sized( $image, 1280 ),
			'length'      => isset( $v['duration_s'] ) ? (int) round( (float) $v['duration_s'] ) : 0,
			'added'       => isset( $v['published_at'] ) ? (string) $v['published_at'] : '',
			'live'        => false,
			'speaker'     => isset( $v['speaker'] ) ? trim( (string) $v['speaker'] ) : '',
			'scripture'   => isset( $v['scripture'] ) ? trim( (string) $v['scripture'] ) : '',
			'tags'        => $tags,
		);
	}

	/** @internal A live channel: { id, title, status, image, hls, viewers, scheduled, started, chat, replay } */
	public static function channel( $ch ) {
		$hls    = isset( $ch['hls_url'] ) ? self::absolute( (string) $ch['hls_url'] ) : '';
		$replay = null;
		if ( ! empty( $ch['latest_recording']['slug'] ) ) {
			$replay = self::video( $ch['latest_recording'], '' );
		}
		return array(
			'id'        => (string) $ch['slug'],
			'title'     => trim( (string) ( ! empty( $ch['title'] ) ? $ch['title'] : ( isset( $ch['name'] ) ? $ch['name'] : '' ) ) ),
			'name'      => trim( (string) ( isset( $ch['name'] ) ? $ch['name'] : '' ) ),
			'status'    => isset( $ch['status'] ) ? (string) $ch['status'] : 'idle',
			'image'     => empty( $ch['thumbnail_url'] ) ? '' : self::absolute( $ch['thumbnail_url'] ),
			'hls'       => 0 === strpos( $hls, 'https://' ) ? $hls : '',
			'viewers'   => isset( $ch['viewers'] ) ? (int) $ch['viewers'] : 0,
			'scheduled' => isset( $ch['scheduled_at'] ) ? (string) $ch['scheduled_at'] : '',
			'started'   => isset( $ch['started_at'] ) ? (string) $ch['started_at'] : '',
			'chat'      => ! empty( $ch['chat_enabled'] ),
			'replay'    => $replay,
			'link'      => self::live_link( (string) $ch['slug'] ),
		);
	}

	/** A bigger copy of a Mux picture, when its size isn't signed. */
	public static function sized( $url, $width ) {
		if ( '' === $url || false === strpos( $url, '://image.mux.com/' ) || false !== strpos( $url, 'token=' ) ) {
			return $url;
		}
		return add_query_arg( 'width', (int) $width, remove_query_arg( 'width', $url ) );
	}

	/** @internal */
	public static function absolute( $url, $base = null ) {
		$url = (string) $url;
		if ( 0 === strpos( $url, '/' ) ) {
			$url = ( null === $base ? self::base() : $base ) . $url;
		}
		return 0 === strpos( $url, 'https://' ) || 0 === strpos( $url, 'http://' ) ? esc_url_raw( $url ) : '';
	}
}
