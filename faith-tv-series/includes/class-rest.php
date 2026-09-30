<?php
/**
 * Endpoints the page script uses. Reads are public: everything served here is already public
 * on the church's channel, and only ids already seen on this site are fetched.
 *
 *   GET  category/<id>   a series' episodes (or a row's series)
 *   GET  video/<id>      how to play one video, plus related videos
 *   GET  live            Sunday live state (never cached by the browser)
 *   GET  library         every message, filtered and paged (sermon library)
 *   GET  search?q=       search messages
 *   POST stats           anonymous play counts for the dashboard
 *   POST remind          "Remind me" sign-ups, passed on to the church's follow-up system
 *   POST refresh         Faith Stream says something changed (signed)
 *   GET  ping            Site Health checks that visitors can reach these endpoints
 *   GET  admin/categories  the category list for the block editor (editors only)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Rest {

	const NS = 'faith-tv/v1';
	const ID = '(?P<id>[A-Za-z0-9_-]{1,128})';

	public static function register_routes() {
		$public = '__return_true';
		$routes = array(
			'/category/' . self::ID => array( 'GET', 'category', $public ),
			'/video/' . self::ID    => array( 'GET', 'video', $public ),
			'/live'                 => array( 'GET', 'live', $public ),
			'/library'              => array( 'GET', 'library', $public ),
			'/search'               => array( 'GET', 'search', $public ),
			'/stats'                => array( 'POST', 'stats', $public ),
			'/remind'               => array( 'POST', 'remind', $public ),
			'/refresh'              => array( 'POST', 'refresh', $public ),
			'/ping'                 => array( 'GET', 'ping', $public ),
			'/admin/categories'     => array(
				'GET',
				'admin_categories',
				function () {
					return current_user_can( 'edit_posts' );
				},
			),
		);
		foreach ( $routes as $path => $route ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $route[0],
					'callback'            => array( __CLASS__, $route[1] ),
					'permission_callback' => $route[2],
				)
			);
		}
	}

	private static function id( WP_REST_Request $request ) {
		return 'gideo' === FTVS_Catalog::source() ? strtolower( $request['id'] ) : (string) $request['id'];
	}

	/** Sample videos are for editors only. */
	private static function hidden() {
		return FTVS_Catalog::is_demo() && ! current_user_can( 'edit_posts' );
	}

	public static function category( WP_REST_Request $request ) {
		$id = self::id( $request );
		if ( self::hidden() || ! FTVS_Catalog::is_known( $id ) ) {
			return self::unknown();
		}
		$data = FTVS_Catalog::get_children( $id );
		if ( is_wp_error( $data ) ) {
			return self::upstream( $data );
		}
		$data         = FTVS_Catalog::with_links( $data, $id );
		$data['link'] = FTVS_Catalog::category_link( $id );
		if ( empty( $data['self'] ) ) {
			$data['self'] = array( 'id' => $id, 'title' => FTVS_Catalog::title_of( $id ) );
		}
		return self::cacheable( $data );
	}

	public static function video( WP_REST_Request $request ) {
		$id = self::id( $request );
		if ( self::hidden() || ! FTVS_Catalog::is_known( $id ) ) {
			return self::unknown();
		}
		$video = FTVS_Catalog::get_video( $id );
		if ( is_wp_error( $video ) ) {
			return self::upstream( $video );
		}
		$related = array();
		foreach ( isset( $video['related'] ) ? $video['related'] : array() as $r ) {
			$r['link']  = FTVS_Catalog::video_link( $r['id'], $r['parent'] );
			$r['watch'] = FTVS_Watch::url( $r['id'] );
			$related[]  = $r;
		}
		$out = array(
			'hls'      => isset( $video['hls'] ) ? $video['hls'] : '',
			'embed'    => isset( $video['embed'] ) ? $video['embed'] : '',
			'captions' => ! empty( $video['captions'] ),
			// Audio only (Listen mode), when the platform made an audio file of the message.
			'audio'    => isset( $video['audio'] ) ? (string) $video['audio'] : '',
			'related'  => array_slice( $related, 0, 8 ),
			'series'   => isset( $video['series'] ) ? $video['series'] : array(),
			'watch'    => FTVS_Watch::url( $id ),
		);
		// Title and picture for a player opened from a shared link (not from a card on the page).
		if ( $request['fresh'] === null ) {
			$item = FTVS_Catalog::find_video( $id );
			if ( $item ) {
				$out['item'] = json_decode( FTVS_Renderer::item_json( $item ), true );
			}
		}
		if ( '' === $out['hls'] && '' === $out['embed'] ) {
			return self::upstream( new WP_Error( 'ftvs_no_stream', __( 'This video is not ready to play yet.', 'faith-tv-series' ) ) );
		}
		// Signed addresses expire, so browsers must ask again rather than reuse an old answer.
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'private, max-age=60' );
		return $response;
	}

	public static function live( WP_REST_Request $request ) {
		if ( self::hidden() ) {
			return self::unknown();
		}
		$channel  = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $request['channel'] ) );
		$response = rest_ensure_response( FTVS_Live::state( $channel ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** The sermon library: newest first, filtered by speaker, series, year or words; 24 at a time. */
	public static function library( WP_REST_Request $request ) {
		if ( self::hidden() ) {
			return self::unknown();
		}
		$q = self::query( $request['q'] );
		// Searching asks the platform each time, so it shares the search route's limit.
		if ( '' !== $q && self::limited( 'search', 30, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ftvs_slow_down', __( 'Too many searches. Try again in a minute.', 'faith-tv-series' ), array( 'status' => 429 ) );
		}
		$list = '' !== $q ? FTVS_Catalog::search( $q ) : FTVS_Catalog::library();
		if ( is_wp_error( $list ) ) {
			return self::upstream( $list );
		}
		$scope = trim( (string) $request['category'] );
		if ( '' !== $scope && '@' !== $scope[0] ) {
			$in    = self::ids_under( $scope );
			$list  = array_values(
				array_filter(
					$list,
					function ( $v ) use ( $in ) {
						return isset( $in[ $v['parent'] ] );
					}
				)
			);
		}
		$facets = self::facets( $list );
		foreach ( array( 'speaker', 'series' ) as $key ) {
			$want = trim( sanitize_text_field( (string) $request[ $key ] ) );
			if ( '' !== $want ) {
				$list = array_values(
					array_filter(
						$list,
						function ( $v ) use ( $key, $want ) {
							return isset( $v[ $key ] ) && strtolower( $v[ $key ] ) === strtolower( $want );
						}
					)
				);
			}
		}
		$year = absint( $request['year'] );
		if ( $year ) {
			$list = array_values(
				array_filter(
					$list,
					function ( $v ) use ( $year ) {
						return (int) substr( (string) $v['added'], 0, 4 ) === $year;
					}
				)
			);
		}
		$per  = max( 1, min( 48, absint( $request['per'] ? $request['per'] : 24 ) ) );
		$page = max( 1, absint( $request['page'] ) );
		$out  = array(
			'total'  => count( $list ),
			'page'   => $page,
			'videos' => FTVS_Catalog::with_links(
				array(
					'categories' => array(),
					'videos'     => array_slice( $list, ( $page - 1 ) * $per, $per ),
				),
				''
			)['videos'],
			'facets' => $facets,
		);
		return self::cacheable( $out );
	}

	public static function search( WP_REST_Request $request ) {
		if ( self::hidden() ) {
			return self::unknown();
		}
		$q = self::query( $request['q'] );
		if ( strlen( $q ) < 2 ) {
			return self::cacheable( array( 'videos' => array() ) );
		}
		if ( self::limited( 'search', 30, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'ftvs_slow_down', __( 'Too many searches. Try again in a minute.', 'faith-tv-series' ), array( 'status' => 429 ) );
		}
		$list = FTVS_Catalog::search( $q );
		if ( is_wp_error( $list ) ) {
			return self::upstream( $list );
		}
		$data = FTVS_Catalog::with_links(
			array(
				'categories' => array(),
				'videos'     => array_slice( $list, 0, 30 ),
			),
			''
		);
		return self::cacheable( array( 'videos' => $data['videos'] ) );
	}

	/** Anonymous play counts (no names, no addresses) for the dashboard's "This week". */
	public static function stats( WP_REST_Request $request ) {
		if ( ! FTVS_Settings::get( 'count_plays' ) || self::limited( 'stats', 60, MINUTE_IN_SECONDS ) ) {
			return rest_ensure_response( array( 'ok' => false ) );
		}
		$body = json_decode( $request->get_body(), true );
		$body = is_array( $body ) ? $body : $request->get_params();
		$id = isset( $body['id'] ) && is_string( $body['id'] ) ? $body['id'] : '';
		// The title the dashboard and the weekly email show: the channel's own when it is known here, so a
		// visitor can't choose what the admin reads.
		$title = FTVS_Catalog::cached_title( $id );
		if ( '' === $title && isset( $body['t'] ) && is_string( $body['t'] ) ) {
			$title = $body['t'];
		}
		FTVS_Stats::record(
			isset( $body['e'] ) && is_string( $body['e'] ) ? $body['e'] : '',
			$id,
			$title,
			isset( $body['p'] ) ? (string) $body['p'] : '',
			! empty( $body['embed'] )
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function remind( WP_REST_Request $request ) {
		if ( self::limited( 'remind', 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'ftvs_slow_down', __( 'Too many sign-ups from here. Try again later.', 'faith-tv-series' ), array( 'status' => 429 ) );
		}
		$result = FTVS_Followup::remind( $request->get_json_params() ? $request->get_json_params() : $request->get_params() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	/** Faith Stream tells the site the moment something is published, changed or removed. */
	public static function refresh( WP_REST_Request $request ) {
		$secret = (string) get_option( 'ftvs_refresh_secret', '' );
		$sig    = (string) $request->get_header( 'x_faithstream_signature' );
		$body   = (string) $request->get_body();
		if ( '' === $secret || ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $sig ) ) {
			return new WP_Error( 'ftvs_bad_signature', 'Bad signature', array( 'status' => 401 ) );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['ts'] ) || abs( time() - (int) $data['ts'] ) > 600 ) {
			return new WP_Error( 'ftvs_stale', 'Stale or unreadable ping', array( 'status' => 400 ) );
		}
		// Pings seen in the last ten minutes (the window a ping is accepted in): a replayed one does nothing.
		$seen = get_transient( 'ftvs_refresh_seen' );
		$seen = is_array( $seen ) ? $seen : array();
		if ( in_array( md5( $body ), $seen, true ) ) {
			return rest_ensure_response( array( 'ok' => true, 'repeat' => true ) );
		}
		$seen[] = md5( $body );
		set_transient( 'ftvs_refresh_seen', array_slice( $seen, -50 ), 10 * MINUTE_IN_SECONDS );
		update_option( 'ftvs_last_ping', array( 't' => time(), 'event' => isset( $data['event'] ) ? preg_replace( '/[^a-z0-9._-]/', '', strtolower( (string) $data['event'] ) ) : '' ), false );
		FTVS_Cache::expire(); // visitors keep the saved copies while the new ones load in the background
		FTVS_Health::warm_soon();
		FTVS_Purge::soon();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function ping() {
		$response = rest_ensure_response( array( 'ok' => true, 'version' => FTVS_VERSION ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function admin_categories() {
		$tree = FTVS_Catalog::get_tree();
		$out  = array(
			array( 'value' => FTVS_Catalog::NEWEST, 'label' => __( 'Automatic: newest messages', 'faith-tv-series' ) ),
			array( 'value' => FTVS_Catalog::FEATURED, 'label' => __( 'Automatic: what we feature', 'faith-tv-series' ) ),
		);
		if ( ! is_wp_error( $tree ) ) {
			foreach ( $tree as $row ) {
				$out[] = array( 'value' => $row['id'], 'label' => $row['title'] );
				foreach ( $row['children'] as $child ) {
					$out[] = array( 'value' => $child['id'], 'label' => $row['title'] . ' / ' . $child['title'] );
				}
			}
		}
		return rest_ensure_response( $out );
	}

	/** Speakers, series and years in a list, with counts, for the library's filter chips. */
	private static function facets( $list ) {
		$out = array(
			'speaker' => array(),
			'series'  => array(),
			'year'    => array(),
		);
		foreach ( $list as $v ) {
			foreach ( array( 'speaker', 'series' ) as $key ) {
				$name = isset( $v[ $key ] ) ? trim( (string) $v[ $key ] ) : '';
				if ( '' !== $name ) {
					$out[ $key ][ $name ] = isset( $out[ $key ][ $name ] ) ? $out[ $key ][ $name ] + 1 : 1;
				}
			}
			$year = (int) substr( (string) $v['added'], 0, 4 );
			if ( $year > 1990 ) {
				$out['year'][ $year ] = isset( $out['year'][ $year ] ) ? $out['year'][ $year ] + 1 : 1;
			}
		}
		arsort( $out['speaker'] );
		arsort( $out['series'] );
		krsort( $out['year'] );
		return array(
			'speaker' => array_slice( $out['speaker'], 0, 20, true ),
			'series'  => array_slice( $out['series'], 0, 40, true ),
			'year'    => $out['year'],
		);
	}

	/** A category and every series below it (one level, as the tree knows it). */
	private static function ids_under( $id ) {
		$ids  = array( $id => 1 );
		$tree = FTVS_Catalog::get_tree();
		if ( ! is_wp_error( $tree ) ) {
			foreach ( $tree as $row ) {
				if ( $row['id'] === $id ) {
					foreach ( $row['children'] as $child ) {
						$ids[ $child['id'] ] = 1;
					}
				}
			}
		}
		return $ids;
	}

	/** Search words: plain text, at most 100 characters. */
	private static function query( $raw ) {
		$q = is_string( $raw ) ? trim( sanitize_text_field( $raw ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 100 ) : substr( $q, 0, 100 );
	}

	/** A small per-visitor limit (visitor = a hash of the address, kept a few minutes). */
	public static function limited( $bucket, $max, $window ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * The visitor's address for the limits. Behind a proxy or CDN that doesn't pass the real address on,
		 * return it from the header your host sets (for example CF-Connecting-IP).
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'ftvs_client_ip', $ip );
		// IPv6: one household usually holds a whole /64, so a visitor can't dodge the limit by changing address.
		if ( false !== strpos( $ip, ':' ) && function_exists( 'inet_pton' ) ) {
			$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				$ip = bin2hex( substr( $packed, 0, 8 ) ) . '/64';
			}
		}
		$key = 'ftvs_rl_' . md5( $bucket . '|' . $ip . '|' . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, $window );
		return false;
	}

	/** Only ids already seen on this site are fetched, so made-up ids never reach the platform. */
	private static function unknown() {
		return new WP_Error( 'ftvs_unknown', __( 'Not found on this channel.', 'faith-tv-series' ), array( 'status' => 404 ) );
	}

	private static function upstream( WP_Error $error ) {
		$status = 'ftvs_gone' === $error->get_error_code() ? 404 : 502;
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => $status ) );
	}

	private static function cacheable( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}
}
