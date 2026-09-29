<?php
/**
 * Reads a church's public catalog from Gideo (the platform behind tv.<church> TV sites and apps).
 *
 * Gideo's legacy API is public (no key):
 *   getSettings          JSON: the account id and name behind a TV website (used while connecting)
 *   getCategoryChildren  XML: a category's sub-categories and videos (empty id = home rows)
 *   getVideoUrls         JSON: the HLS address for one video
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Gideo_Client {

	const API     = 'https://ott.gideo.video/api/legacy';
	const URL_TTL = 12 * HOUR_IN_SECONDS;

	public static function account() {
		return (string) FTVS_Settings::get( 'account_id' );
	}

	public static function is_id( $value ) {
		return 1 === preg_match( '/^[a-f0-9]{32}$/i', $value );
	}

	public static function get_children( $category_id = '' ) {
		$ttl = 60 * (int) FTVS_Settings::get( 'cache_minutes' );
		return FTVS_Cache::remember(
			'c_' . $category_id,
			$ttl,
			function () use ( $category_id ) {
				return FTVS_Gideo_Client::fetch_children( FTVS_Gideo_Client::account(), $category_id );
			}
		);
	}

	public static function get_video_url( $video_id ) {
		return FTVS_Cache::remember(
			'v_' . $video_id,
			self::URL_TTL,
			function () use ( $video_id ) {
				$body = FTVS_Gideo_Client::request(
					array(
						'cmd'       => 'getVideoUrls',
						'accountId' => FTVS_Gideo_Client::account(),
						'videoId'   => $video_id,
					)
				);
				if ( is_wp_error( $body ) ) {
					return $body;
				}
				$data = json_decode( $body, true );
				if ( ! empty( $data['urls'] ) && is_array( $data['urls'] ) ) {
					foreach ( $data['urls'] as $entry ) {
						$url = isset( $entry['url'] ) ? $entry['url'] : '';
						if ( 'hls' === ( isset( $entry['streamFormat'] ) ? $entry['streamFormat'] : '' ) && 0 === strpos( $url, 'https://' ) ) {
							return esc_url_raw( $url );
						}
					}
				}
				return new WP_Error( 'ftvs_no_stream', __( 'This video has no playable stream.', 'faith-tv-series' ) );
			}
		);
	}

	public static function category_link( $id ) {
		return untrailingslashit( (string) FTVS_Settings::get( 'tv_url' ) ) . '/program-group/' . $id;
	}

	public static function video_link( $video_id, $category_id ) {
		return self::category_link( $category_id ) . '/program/' . $video_id;
	}

	/**
	 * Finds the church behind a TV website address (used while connecting; not cached).
	 *
	 * @return array|WP_Error Settings to save plus 'rows' for the preview.
	 */
	public static function lookup_domain( $address ) {
		$host = strtolower( trim( (string) $address ) );
		$host = preg_replace( '#^https?://#', '', $host );
		$host = preg_replace( '#[/?\#].*$#', '', $host );
		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ) {
			return new WP_Error( 'ftvs_lookup', __( 'Type your TV website address, for example tv.yourchurch.com.', 'faith-tv-series' ) );
		}
		$response = wp_remote_get(
			add_query_arg( array( 'cmd' => 'getSettings', 'domain' => rawurlencode( $host ) ), self::API ),
			array(
				'timeout' => 8,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		$data = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['accountId'] ) ) {
			/* translators: %s: website address */
			return new WP_Error( 'ftvs_lookup', sprintf( __( 'We could not find a Gideo channel at %s. Check the spelling, or use your Gideo account ID instead.', 'faith-tv-series' ), $host ) );
		}
		$account = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $data['accountId'] );
		$home    = self::fetch_children( $account, '' );
		return array(
			'source'      => 'gideo',
			'account_id'  => $account,
			'tv_url'      => 'https://' . $host,
			'church_name' => ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : $host,
			'church_logo' => 'https://' . $host . '/webtv-assets/logo.png',
			'rows'        => is_wp_error( $home ) ? array() : $home['categories'],
		);
	}

	/** Connecting with a Gideo account id instead of a website address. */
	public static function lookup_account( $account ) {
		$account = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $account );
		$home    = '' === $account ? null : self::fetch_children( $account, '' );
		if ( ! $home || is_wp_error( $home ) || ! $home['categories'] ) {
			return new WP_Error( 'ftvs_lookup', __( 'That Gideo account ID did not work.', 'faith-tv-series' ) );
		}
		return array(
			'source'      => 'gideo',
			'account_id'  => $account,
			'tv_url'      => '',
			'church_name' => $account,
			'church_logo' => '',
			'rows'        => $home['categories'],
		);
	}

	/** @internal */
	public static function fetch_children( $account, $category_id ) {
		$body = self::request(
			array(
				'cmd'        => 'getCategoryChildren',
				'AccountID'  => $account,
				'CategoryID' => $category_id,
			)
		);
		return is_wp_error( $body ) ? $body : self::parse_children( $body );
	}

	/** @internal */
	public static function request( $args ) {
		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $args ), self::API ),
			array(
				'timeout'    => 8,
				'user-agent' => 'FaithTVSeries/' . FTVS_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'ftvs_http', sprintf( __( 'Gideo answered with status %d.', 'faith-tv-series' ), $code ) );
		}
		return wp_remote_retrieve_body( $response );
	}

	private static function parse_children( $body ) {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return new WP_Error( 'ftvs_parse', __( 'Gideo sent something we could not read.', 'faith-tv-series' ) );
		}
		if ( isset( $xml->error ) ) {
			$message = trim( (string) $xml->error->message );
			return new WP_Error( 'ftvs_upstream', '' !== $message ? $message : __( 'Gideo returned an error.', 'faith-tv-series' ) );
		}

		$out = array(
			'categories' => array(),
			'videos'     => array(),
		);

		foreach ( $xml->category as $c ) {
			$out['categories'][] = array(
				'id'            => (string) $c['id'],
				'title'         => self::text( $c->title ),
				'description'   => self::text( $c->description ),
				'image'         => self::image( $c->fhdimage, $c->image ),
				'videos'        => (int) $c->videos,
				'subcategories' => (int) $c->subcategories,
			);
		}

		foreach ( $xml->video as $v ) {
			$type = (string) $v->type;
			if ( '1' === (string) $v->placeholder || '1' === (string) $v->ad || ( '' !== $type && 'video' !== $type ) ) {
				continue;
			}
			$out['videos'][] = array(
				'id'          => (string) $v['id'],
				'parent'      => (string) $v->parent,
				'title'       => self::text( $v->title ),
				'description' => self::text( $v->description ),
				'image'       => self::image( $v->image, $v->fhdimage ),
				'poster'      => self::image( $v->fhdimage, $v->image ),
				'length'      => (int) $v->length,
				'added'       => (string) $v->added,
				'live'        => '1' === (string) $v->live,
			);
		}

		return $out;
	}

	private static function text( $node ) {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $node ) );
	}

	/** First usable https image, skipping Gideo's "no-image" placeholder. */
	private static function image( ...$nodes ) {
		foreach ( $nodes as $node ) {
			$url = trim( (string) $node );
			if ( 0 === strpos( $url, 'https://' ) && false === strpos( $url, '/no-image.' ) ) {
				return esc_url_raw( $url );
			}
		}
		return '';
	}
}
