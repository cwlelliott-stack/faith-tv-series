<?php
/**
 * Reads a church's videos from YouTube: each playlist becomes a series, and the channel's
 * uploads become "Latest videos". Videos play in YouTube's privacy-enhanced player.
 *
 * Without a key it uses YouTube's public feeds (the newest 15 videos of each playlist or
 * channel). With a YouTube Data API key (optional, Faith Stream > Church > Advanced) it reads
 * whole playlists and can list every playlist on the channel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_YouTube_Client {

	const FEED     = 'https://www.youtube.com/feeds/videos.xml';
	const API      = 'https://www.googleapis.com/youtube/v3/';
	const SERIES   = 'yt-series';
	const UPLOADS  = 'yt-uploads';

	public static function is_id( $value ) {
		return in_array( $value, array( self::SERIES, self::UPLOADS ), true ) || self::is_playlist( $value ) || self::is_video( $value );
	}

	public static function is_playlist( $value ) {
		return 1 === preg_match( '/^(PL|UU|OL|FL|LL|RD)[A-Za-z0-9_-]{10,64}$/D', $value );
	}

	public static function is_video( $value ) {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/D', $value );
	}

	private static function key() {
		return trim( (string) FTVS_Settings::get( 'yt_key' ) );
	}

	private static function channel() {
		return (string) FTVS_Settings::get( 'yt_channel' );
	}

	/** Playlist ids the church picked (or, with a key and none picked, every playlist on the channel). */
	private static function playlists() {
		$ids = array_values( array_filter( (array) FTVS_Settings::get( 'yt_playlists' ), array( __CLASS__, 'is_playlist' ) ) );
		if ( ! $ids && '' !== self::key() && '' !== self::channel() ) {
			$all = FTVS_Cache::remember( 'yt_all_playlists', FTVS_Catalog::TREE_TTL, array( __CLASS__, 'fetch_channel_playlists', array( self::channel() ) ) );
			if ( ! is_wp_error( $all ) ) {
				$ids = wp_list_pluck( $all, 'id' );
			}
		}
		return $ids;
	}

	private static function ttl() {
		return 60 * (int) FTVS_Settings::get( 'cache_minutes' );
	}

	public static function get_children( $id = '' ) {
		if ( '' === $id ) {
			$rows = array();
			foreach ( self::fetch_tree_rows() as $row ) {
				unset( $row['children'] );
				$rows[] = $row;
			}
			return array(
				'categories' => $rows,
				'videos'     => array(),
			);
		}
		if ( self::SERIES === $id ) {
			$cats = array();
			foreach ( self::playlists() as $pl ) {
				$data = self::playlist( $pl );
				if ( ! is_wp_error( $data ) ) {
					$cats[] = $data['self'];
				}
			}
			return array(
				'categories' => $cats,
				'videos'     => array(),
			);
		}
		if ( self::UPLOADS === $id ) {
			return FTVS_Cache::remember( 'c_' . $id, self::ttl(), array( __CLASS__, 'fetch_uploads', array( self::channel() ) ) );
		}
		if ( self::is_playlist( $id ) ) {
			$data = self::playlist( $id );
			return is_wp_error( $data ) ? $data : array(
				'categories' => array(),
				'videos'     => $data['videos'],
			);
		}
		return new WP_Error( 'ftvs_bad_id', __( 'That is not a category on your channel.', 'faith-tv-series' ) );
	}

	private static function playlist( $id ) {
		return FTVS_Cache::remember( 'c_' . $id, self::ttl(), array( __CLASS__, 'fetch_playlist', array( $id ) ) );
	}

	public static function fetch_tree() {
		return self::fetch_tree_rows();
	}

	private static function fetch_tree_rows() {
		$rows = array();
		$pls  = self::playlists();
		if ( $pls ) {
			$children = array();
			foreach ( $pls as $pl ) {
				$data = self::playlist( $pl );
				if ( ! is_wp_error( $data ) ) {
					$children[] = $data['self'];
				}
			}
			$rows[] = array(
				'id'            => self::SERIES,
				'title'         => __( 'Series', 'faith-tv-series' ),
				'description'   => '',
				'image'         => $children ? $children[0]['image'] : '',
				'videos'        => 0,
				'subcategories' => count( $children ),
				'children'      => $children,
				'style'         => '',
			);
		}
		if ( '' !== self::channel() ) {
			$up     = self::get_children( self::UPLOADS );
			$rows[] = array(
				'id'            => self::UPLOADS,
				'title'         => __( 'Latest videos', 'faith-tv-series' ),
				'description'   => '',
				'image'         => ! is_wp_error( $up ) && $up['videos'] ? $up['videos'][0]['image'] : '',
				'videos'        => is_wp_error( $up ) ? 0 : count( $up['videos'] ),
				'subcategories' => 0,
				'children'      => array(),
				'style'         => '',
			);
		}
		return $rows;
	}

	/** @internal { self: category, videos: [] } */
	public static function fetch_playlist( $id ) {
		if ( '' !== self::key() ) {
			$info = self::api( 'playlists', array( 'part' => 'snippet,contentDetails', 'id' => $id ) );
			if ( is_wp_error( $info ) ) {
				return $info;
			}
			if ( empty( $info['items'][0] ) ) {
				return new WP_Error( 'ftvs_gone', __( 'That is no longer on your channel.', 'faith-tv-series' ) );
			}
			$sn     = $info['items'][0]['snippet'];
			$videos = array();
			$token  = '';
			for ( $page = 0; $page < 10; $page++ ) {
				$args = array( 'part' => 'snippet,contentDetails', 'playlistId' => $id, 'maxResults' => 50 );
				if ( '' !== $token ) {
					$args['pageToken'] = $token;
				}
				$list = self::api( 'playlistItems', $args );
				if ( is_wp_error( $list ) ) {
					return $list;
				}
				foreach ( isset( $list['items'] ) ? $list['items'] : array() as $item ) {
					$vid = isset( $item['contentDetails']['videoId'] ) ? $item['contentDetails']['videoId'] : '';
					$s   = isset( $item['snippet'] ) ? $item['snippet'] : array();
					if ( ! self::is_video( $vid ) || in_array( isset( $s['title'] ) ? $s['title'] : '', array( 'Private video', 'Deleted video' ), true ) ) {
						continue;
					}
					$videos[] = self::video(
						$vid,
						$id,
						isset( $s['title'] ) ? $s['title'] : '',
						isset( $s['description'] ) ? $s['description'] : '',
						isset( $item['contentDetails']['videoPublishedAt'] ) ? $item['contentDetails']['videoPublishedAt'] : ( isset( $s['publishedAt'] ) ? $s['publishedAt'] : '' )
					);
				}
				$token = isset( $list['nextPageToken'] ) ? $list['nextPageToken'] : '';
				if ( '' === $token ) {
					break;
				}
			}
			self::add_lengths( $videos );
			return array(
				'self'   => self::series( $id, $sn['title'], isset( $sn['description'] ) ? $sn['description'] : '', $videos, self::best_thumb( isset( $sn['thumbnails'] ) ? $sn['thumbnails'] : array() ) ),
				'videos' => $videos,
			);
		}
		$feed = self::feed( array( 'playlist_id' => $id ) );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		foreach ( $feed['videos'] as $i => $video ) {
			$feed['videos'][ $i ]['parent'] = $id;
		}
		return array(
			'self'   => self::series( $id, $feed['title'], '', $feed['videos'], '' ),
			'videos' => $feed['videos'],
		);
	}

	/** @internal */
	public static function fetch_uploads( $channel ) {
		$channel_id = self::channel_id( $channel );
		if ( is_wp_error( $channel_id ) ) {
			return $channel_id;
		}
		if ( '' !== self::key() ) {
			$data = self::fetch_playlist( 'UU' . substr( $channel_id, 2 ) );
			return is_wp_error( $data ) ? $data : array(
				'categories' => array(),
				'videos'     => $data['videos'],
			);
		}
		$feed = self::feed( array( 'channel_id' => $channel_id ) );
		if ( is_wp_error( $feed ) ) {
			return $feed;
		}
		foreach ( $feed['videos'] as $i => $video ) {
			$feed['videos'][ $i ]['parent'] = self::UPLOADS;
		}
		return array(
			'categories' => array(),
			'videos'     => $feed['videos'],
		);
	}

	/** @internal Every playlist on the channel (needs a key). */
	public static function fetch_channel_playlists( $channel ) {
		$channel_id = self::channel_id( $channel );
		if ( is_wp_error( $channel_id ) ) {
			return $channel_id;
		}
		$out   = array();
		$token = '';
		for ( $page = 0; $page < 4; $page++ ) {
			$args = array( 'part' => 'snippet', 'channelId' => $channel_id, 'maxResults' => 50 );
			if ( '' !== $token ) {
				$args['pageToken'] = $token;
			}
			$list = self::api( 'playlists', $args );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( isset( $list['items'] ) ? $list['items'] : array() as $item ) {
				$out[] = array(
					'id'    => $item['id'],
					'title' => isset( $item['snippet']['title'] ) ? $item['snippet']['title'] : '',
				);
			}
			$token = isset( $list['nextPageToken'] ) ? $list['nextPageToken'] : '';
			if ( '' === $token ) {
				break;
			}
		}
		return $out;
	}

	/** Videos play in YouTube's own player (privacy-enhanced mode), not over HLS. */
	public static function get_video( $id ) {
		if ( ! self::is_video( $id ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a video on your channel.', 'faith-tv-series' ) );
		}
		return array(
			'id'       => $id,
			'hls'      => '',
			'embed'    => self::embed_url( $id ),
			'audio'    => '',
			'captions' => false,
			'related'  => array(),
			'series'   => array(),
		);
	}

	public static function embed_url( $id ) {
		return 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $id ) . '?autoplay=1&rel=0&modestbranding=1&playsinline=1&enablejsapi=1&origin=' . rawurlencode( home_url() );
	}

	public static function get_video_url( $id ) {
		return new WP_Error( 'ftvs_no_stream', __( 'This video plays in YouTube\'s player.', 'faith-tv-series' ) );
	}

	public static function category_link( $id ) {
		return self::is_playlist( $id ) ? 'https://www.youtube.com/playlist?list=' . rawurlencode( $id ) : self::channel_url();
	}

	public static function video_link( $video_id, $category_id ) {
		return 'https://www.youtube.com/watch?v=' . rawurlencode( $video_id );
	}

	public static function channel_url() {
		$channel = self::channel();
		if ( '' === $channel ) {
			return '';
		}
		if ( 0 === strpos( $channel, 'UC' ) ) {
			return 'https://www.youtube.com/channel/' . rawurlencode( $channel );
		}
		return 'https://www.youtube.com/' . ( '@' === $channel[0] ? $channel : '@' . $channel );
	}

	/**
	 * Parses what someone pasted: a playlist link, a channel link, @handle or ids.
	 *
	 * @return array { channel: string, playlists: string[] }
	 */
	public static function parse_input( $text ) {
		$out = array(
			'channel'   => '',
			'playlists' => array(),
		);
		foreach ( preg_split( '/[\s,]+/', (string) $text ) as $bit ) {
			$bit = trim( $bit );
			if ( '' === $bit ) {
				continue;
			}
			if ( preg_match( '/[?&]list=([A-Za-z0-9_-]+)/', $bit, $m ) || self::is_playlist( $bit ) ) {
				$id = isset( $m[1] ) ? $m[1] : $bit;
				if ( self::is_playlist( $id ) ) {
					$out['playlists'][] = $id;
				}
				continue;
			}
			if ( preg_match( '#youtube\.com/channel/(UC[A-Za-z0-9_-]{22})#', $bit, $m ) || preg_match( '/^(UC[A-Za-z0-9_-]{22})$/D', $bit, $m ) ) {
				$out['channel'] = $m[1];
				continue;
			}
			if ( preg_match( '#youtube\.com/(@[A-Za-z0-9._-]{3,100})#', $bit, $m ) || preg_match( '/^(@[A-Za-z0-9._-]{3,100})$/D', $bit, $m ) ) {
				$out['channel'] = $m[1];
			}
		}
		$out['playlists'] = array_values( array_unique( $out['playlists'] ) );
		return $out;
	}

	/**
	 * Checks what someone pasted while connecting (not cached).
	 *
	 * @return array|WP_Error Settings to save plus 'rows' for the preview.
	 */
	public static function lookup( $text, $key = '' ) {
		$in = self::parse_input( $text );
		if ( '' === $in['channel'] && ! $in['playlists'] ) {
			return new WP_Error( 'ftvs_lookup', __( 'Paste a YouTube playlist link or your channel link (youtube.com/@yourchurch).', 'faith-tv-series' ) );
		}
		$name = '';
		$logo = '';
		$rows = array();
		if ( '' !== $in['channel'] ) {
			$channel_id = self::channel_id( $in['channel'] );
			if ( is_wp_error( $channel_id ) ) {
				return $channel_id;
			}
			$feed = self::feed( array( 'channel_id' => $channel_id ) );
			if ( is_wp_error( $feed ) ) {
				return $feed;
			}
			$name   = $feed['title'];
			$rows[] = array(
				'id'    => self::UPLOADS,
				'title' => __( 'Latest videos', 'faith-tv-series' ),
				'image' => $feed['videos'] ? $feed['videos'][0]['image'] : '',
			);
		}
		foreach ( array_slice( $in['playlists'], 0, 30 ) as $pl ) {
			$feed = self::feed( array( 'playlist_id' => $pl ) );
			if ( is_wp_error( $feed ) ) {
				/* translators: %s: playlist id */
				return new WP_Error( 'ftvs_lookup', sprintf( __( 'We could not open the playlist %s. Is it public?', 'faith-tv-series' ), $pl ) );
			}
			$rows[] = array(
				'id'    => $pl,
				'title' => $feed['title'],
				'image' => $feed['videos'] ? $feed['videos'][0]['image'] : '',
			);
			if ( '' === $name && ! empty( $feed['author'] ) ) {
				$name = $feed['author'];
			}
		}
		return array(
			'source'       => 'youtube',
			'yt_channel'   => $in['channel'],
			'yt_playlists' => $in['playlists'],
			'yt_key'       => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key ),
			'church_name'  => '' !== $name ? $name : __( 'Your church', 'faith-tv-series' ),
			'church_logo'  => $logo,
			'rows'         => $rows,
		);
	}

	/* ---------- Plumbing ---------- */

	/** @return string|WP_Error The UC... id behind a @handle (or the id itself). */
	private static function channel_id( $channel ) {
		if ( preg_match( '/^UC[A-Za-z0-9_-]{22}$/D', $channel ) ) {
			return $channel;
		}
		$cached = get_option( 'ftvs_yt_handle_' . md5( $channel ) );
		if ( $cached ) {
			return $cached;
		}
		$response = wp_safe_remote_get( 'https://www.youtube.com/@' . rawurlencode( ltrim( $channel, '@' ) ), array( 'timeout' => 8 ) );
		$body     = is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response );
		if ( preg_match( '#<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})"#', $body, $m ) || preg_match( '/"(?:channelId|externalId)":"(UC[A-Za-z0-9_-]{22})"/', $body, $m ) ) {
			update_option( 'ftvs_yt_handle_' . md5( $channel ), $m[1], false );
			return $m[1];
		}
		/* translators: %s: YouTube handle */
		return new WP_Error( 'ftvs_lookup', sprintf( __( 'We could not find the YouTube channel %s.', 'faith-tv-series' ), $channel ) );
	}

	/** Public Atom feed: the newest 15 videos of a playlist or channel. */
	private static function feed( $args ) {
		$response = wp_safe_remote_get(
			add_query_arg( $args, self::FEED ),
			array(
				'timeout'    => wp_doing_cron() ? 12 : 6,
				'user-agent' => 'FaithTVSeries/' . FTVS_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		// YouTube's feeds sometimes answer 404 for a playlist that exists. Only a 404 that lasts a day means it was
		// removed; until then the saved videos stay on the page.
		$miss = 'ftvs_yt404_' . md5( add_query_arg( $args, self::FEED ) );
		if ( 404 === $code ) {
			$first = get_transient( $miss );
			if ( false === $first ) {
				$first = time();
				set_transient( $miss, $first, 3 * DAY_IN_SECONDS );
			}
			if ( time() - (int) $first < DAY_IN_SECONDS ) {
				return new WP_Error( 'ftvs_http', __( 'YouTube did not list this playlist just now.', 'faith-tv-series' ) );
			}
			return new WP_Error( 'ftvs_gone', __( 'That is no longer on your channel.', 'faith-tv-series' ) );
		}
		if ( 200 === $code && false !== get_transient( $miss ) ) {
			delete_transient( $miss );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'ftvs_http', sprintf( __( 'YouTube answered with status %d.', 'faith-tv-series' ), $code ) );
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( wp_remote_retrieve_body( $response ), 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return new WP_Error( 'ftvs_parse', __( 'YouTube sent something we could not read.', 'faith-tv-series' ) );
		}
		$out = array(
			'title'  => trim( (string) $xml->title ),
			'author' => trim( (string) $xml->author->name ),
			'videos' => array(),
		);
		foreach ( $xml->entry as $entry ) {
			$yt    = $entry->children( 'http://www.youtube.com/xml/schemas/2015' );
			$media = $entry->children( 'http://search.yahoo.com/mrss/' );
			$vid   = trim( (string) $yt->videoId );
			if ( ! self::is_video( $vid ) ) {
				continue;
			}
			$desc           = isset( $media->group ) ? trim( (string) $media->group->description ) : '';
			$out['videos'][] = self::video( $vid, '', trim( (string) $entry->title ), $desc, trim( (string) $entry->published ) );
		}
		return $out;
	}

	private static function api( $endpoint, $args ) {
		$args['key'] = self::key();
		$response    = wp_safe_remote_get( add_query_arg( $args, self::API . $endpoint ), array( 'timeout' => wp_doing_cron() ? 12 : 8 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$message = isset( $data['error']['message'] ) ? wp_strip_all_tags( $data['error']['message'] ) : '';
			/* translators: %s: error from YouTube */
			return new WP_Error( 'ftvs_http', sprintf( __( 'YouTube refused the request: %s', 'faith-tv-series' ), $message ) );
		}
		return is_array( $data ) ? $data : new WP_Error( 'ftvs_parse', __( 'YouTube sent something we could not read.', 'faith-tv-series' ) );
	}

	/** Durations come from a separate call (50 videos at a time). */
	private static function add_lengths( &$videos ) {
		$chunks = array_chunk( array_keys( $videos ), 50 );
		foreach ( $chunks as $chunk ) {
			$ids  = array();
			foreach ( $chunk as $i ) {
				$ids[] = $videos[ $i ]['id'];
			}
			$data = self::api( 'videos', array( 'part' => 'contentDetails', 'id' => implode( ',', $ids ) ) );
			if ( is_wp_error( $data ) ) {
				return;
			}
			$len = array();
			foreach ( isset( $data['items'] ) ? $data['items'] : array() as $item ) {
				$len[ $item['id'] ] = self::iso_seconds( isset( $item['contentDetails']['duration'] ) ? $item['contentDetails']['duration'] : '' );
			}
			foreach ( $chunk as $i ) {
				if ( isset( $len[ $videos[ $i ]['id'] ] ) ) {
					$videos[ $i ]['length'] = $len[ $videos[ $i ]['id'] ];
				}
			}
		}
	}

	private static function iso_seconds( $iso ) {
		if ( ! preg_match( '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/D', $iso, $m ) ) {
			return 0;
		}
		$m = array_pad( $m, 5, 0 );
		return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
	}

	private static function best_thumb( $thumbs ) {
		foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) {
			if ( ! empty( $thumbs[ $size ]['url'] ) ) {
				return esc_url_raw( $thumbs[ $size ]['url'] );
			}
		}
		return '';
	}

	private static function series( $id, $title, $description, $videos, $image ) {
		return array(
			'id'            => $id,
			'title'         => trim( (string) $title ),
			'description'   => trim( (string) $description ),
			'image'         => '' !== $image ? $image : ( $videos ? $videos[0]['image'] : '' ),
			'videos'        => count( $videos ),
			'subcategories' => 0,
		);
	}

	private static function video( $id, $parent, $title, $description, $added ) {
		return array(
			'id'          => $id,
			'parent'      => $parent,
			'title'       => trim( (string) $title ),
			'description' => trim( (string) $description ),
			'image'       => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
			'poster'      => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
			'length'      => 0,
			'added'       => (string) $added,
			'live'        => false,
			'speaker'     => '',
			'scripture'   => '',
			'tags'        => array(),
		);
	}
}
