<?php
/**
 * Reads the public Faith TV catalog from Gideo and caches it.
 *
 * Gideo's legacy API is public (no key):
 *   getCategoryChildren  XML list of a category's sub-categories and videos (empty id = home rows)
 *   getVideoUrls         JSON with the HLS address for one video
 *
 * Every successful answer is also kept as a backup, so if Gideo is down the
 * site keeps showing the last good list instead of an empty section.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Gideo_Client {

	const API       = 'https://ott.gideo.video/api/legacy';
	const TREE_TTL  = 6 * HOUR_IN_SECONDS;
	const URL_TTL   = 12 * HOUR_IN_SECONDS;
	const ERROR_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Sub-categories and videos directly inside a category.
	 *
	 * @param string $category_id 32-character Gideo id, or '' for the home rows.
	 * @return array|WP_Error { categories: array, videos: array }
	 */
	public static function get_children( $category_id = '' ) {
		if ( '' !== $category_id && ! self::is_id( $category_id ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a Faith TV category id.', 'faith-tv-series' ) );
		}
		$ttl = 60 * (int) FTVS_Settings::get( 'cache_minutes' );
		return self::cached(
			'c_' . $category_id,
			$ttl,
			function () use ( $category_id ) {
				$body = self::request(
					array(
						'cmd'        => 'getCategoryChildren',
						'AccountID'  => FTVS_Settings::get( 'account_id' ),
						'CategoryID' => $category_id,
					)
				);
				return is_wp_error( $body ) ? $body : self::parse_children( $body );
			}
		);
	}

	/**
	 * HLS address for one video.
	 *
	 * @return string|WP_Error
	 */
	public static function get_video_url( $video_id ) {
		if ( ! self::is_id( $video_id ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a Faith TV video id.', 'faith-tv-series' ) );
		}
		return self::cached(
			'v_' . $video_id,
			self::URL_TTL,
			function () use ( $video_id ) {
				$body = self::request(
					array(
						'cmd'       => 'getVideoUrls',
						'accountId' => FTVS_Settings::get( 'account_id' ),
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
				return new WP_Error( 'ftvs_no_stream', __( 'Faith TV has no playable stream for this video.', 'faith-tv-series' ) );
			}
		);
	}

	/**
	 * Home rows plus one level of children. Used by the settings page and the
	 * Elementor category picker, and to look categories up by name.
	 *
	 * @return array|WP_Error List of { id, title, videos, subcategories, children: [...] }.
	 */
	public static function get_tree() {
		return self::cached(
			'tree',
			self::TREE_TTL,
			function () {
				$home = self::get_children( '' );
				if ( is_wp_error( $home ) ) {
					return $home;
				}
				$tree = array();
				foreach ( $home['categories'] as $row ) {
					$row['children'] = array();
					if ( $row['subcategories'] > 0 ) {
						$inside = self::get_children( $row['id'] );
						if ( ! is_wp_error( $inside ) ) {
							$row['children'] = $inside['categories'];
						}
					}
					$tree[] = $row;
				}
				return $tree;
			}
		);
	}

	/**
	 * Accepts a category id or a category name ("Faith TV Mini Series").
	 *
	 * @return array|WP_Error { id, title }
	 */
	public static function find_category( $needle ) {
		$needle = trim( (string) $needle );
		if ( self::is_id( $needle ) ) {
			return array(
				'id'    => strtolower( $needle ),
				'title' => '',
			);
		}
		if ( '' === $needle ) {
			return new WP_Error( 'ftvs_no_category', __( 'Pick a Faith TV category.', 'faith-tv-series' ) );
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
		return new WP_Error( 'ftvs_not_found', sprintf( __( 'No Faith TV category is named "%s".', 'faith-tv-series' ), $needle ) );
	}

	public static function is_id( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/i', $value );
	}

	/**
	 * Drops every cached answer (backups are kept as the outage fallback).
	 * Bumping a generation number works with any object cache, not just the options table.
	 */
	public static function clear_cache() {
		update_option( 'ftvs_cache_gen', (int) get_option( 'ftvs_cache_gen', 1 ) + 1, true );
	}

	private static function name_key( $title ) {
		return strtolower( preg_replace( '/\s+/', ' ', trim( $title ) ) );
	}

	private static function cached( $key, $ttl, $fetch ) {
		$hash      = md5( FTVS_Settings::get( 'account_id' ) . '|' . $key );
		$transient = 'ftvs_' . (int) get_option( 'ftvs_cache_gen', 1 ) . '_' . $hash;
		$backup    = 'ftvs_bk_' . $hash;

		$hit = get_transient( $transient );
		if ( false !== $hit ) {
			return is_array( $hit ) && isset( $hit['__error'] ) ? new WP_Error( 'ftvs_upstream', $hit['__error'] ) : $hit;
		}

		$data = $fetch();

		if ( is_wp_error( $data ) ) {
			$last = get_option( $backup );
			if ( false !== $last ) {
				// Serve the last good copy and try Gideo again in a few minutes.
				set_transient( $transient, $last, self::ERROR_TTL );
				return $last;
			}
			set_transient( $transient, array( '__error' => $data->get_error_message() ), self::ERROR_TTL );
			return $data;
		}

		set_transient( $transient, $data, $ttl );
		update_option( $backup, $data, false );
		return $data;
	}

	private static function request( $args ) {
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
			return new WP_Error( 'ftvs_http', sprintf( __( 'Faith TV answered with status %d.', 'faith-tv-series' ), $code ) );
		}
		return wp_remote_retrieve_body( $response );
	}

	private static function parse_children( $body ) {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $xml ) {
			return new WP_Error( 'ftvs_parse', __( 'Faith TV sent something we could not read.', 'faith-tv-series' ) );
		}
		if ( isset( $xml->error ) ) {
			$message = trim( (string) $xml->error->message );
			return new WP_Error( 'ftvs_upstream', '' !== $message ? $message : __( 'Faith TV returned an error.', 'faith-tv-series' ) );
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
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $node );
		return trim( $text );
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
