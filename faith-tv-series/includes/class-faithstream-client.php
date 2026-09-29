<?php
/**
 * Reads a church's public catalog from Faith Stream.
 *
 *   GET {base}/api/public/home                  home rows
 *   GET {base}/api/public/categories/{slug}     a category's series and videos
 *   GET {base}/api/public/videos/{slug}         one video, with its HLS address
 *   GET {base}/api/tenant                       church name and logo
 * The church is chosen with the X-Tenant header.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_FaithStream_Client {

	// Short: a church using signed Mux playback gets tokens that expire after an hour.
	const VIDEO_TTL = 5 * MINUTE_IN_SECONDS;

	public static function base() {
		return untrailingslashit( (string) FTVS_Settings::get( 'fs_url' ) );
	}

	public static function tenant() {
		return (string) FTVS_Settings::get( 'fs_tenant' );
	}

	public static function is_id( $value ) {
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{0,127}$/', $value );
	}

	public static function get_children( $slug = '' ) {
		$ttl = 60 * (int) FTVS_Settings::get( 'cache_minutes' );
		return FTVS_Cache::remember(
			'c_' . $slug,
			$ttl,
			function () use ( $slug ) {
				if ( '' === $slug ) {
					$home = FTVS_FaithStream_Client::get( '/api/public/home' );
					if ( is_wp_error( $home ) ) {
						return $home;
					}
					$out = array(
						'categories' => array(),
						'videos'     => array(),
					);
					foreach ( isset( $home['rows'] ) ? (array) $home['rows'] : array() as $row ) {
						if ( ! empty( $row['category'] ) ) {
							$out['categories'][] = FTVS_FaithStream_Client::category( $row['category'], $row );
						}
					}
					return $out;
				}
				$page = FTVS_FaithStream_Client::get( '/api/public/categories/' . rawurlencode( $slug ) . '?per_page=200' );
				if ( is_wp_error( $page ) ) {
					return $page;
				}
				$out = array(
					'categories' => array(),
					'videos'     => array(),
				);
				// A series without its own picture borrows its first episode's (from "sections").
				$fallback = array();
				foreach ( isset( $page['sections'] ) ? (array) $page['sections'] : array() as $section ) {
					if ( ! empty( $section['category']['slug'] ) && ! empty( $section['videos'][0]['thumbnail_url'] ) ) {
						$fallback[ $section['category']['slug'] ] = $section['videos'][0]['thumbnail_url'];
					}
				}
				foreach ( isset( $page['children'] ) ? (array) $page['children'] : array() as $child ) {
					if ( empty( $child['thumbnail_url'] ) && isset( $child['slug'], $fallback[ $child['slug'] ] ) ) {
						$child['thumbnail_url'] = $fallback[ $child['slug'] ];
					}
					$out['categories'][] = FTVS_FaithStream_Client::category( $child );
				}
				// A folder with no videos of its own lists every episode below it; those belong to the series.
				$own = ! ( isset( $page['has_own_videos'] ) && ! $page['has_own_videos'] && $out['categories'] );
				if ( $own ) {
					foreach ( isset( $page['videos'] ) ? (array) $page['videos'] : array() as $video ) {
						$out['videos'][] = FTVS_FaithStream_Client::video( $video, $slug );
					}
				}
				return $out;
			}
		);
	}

	public static function get_video_url( $slug ) {
		return FTVS_Cache::remember(
			'v_' . $slug,
			self::VIDEO_TTL,
			function () use ( $slug ) {
				$data = FTVS_FaithStream_Client::get( '/api/public/videos/' . rawurlencode( $slug ) );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				// Churches on Faith Stream's own storage get a path on the Faith Stream server.
				$url = isset( $data['video']['hls_url'] ) ? FTVS_FaithStream_Client::absolute( (string) $data['video']['hls_url'] ) : '';
				return 0 === strpos( $url, 'https://' )
					? esc_url_raw( $url )
					: new WP_Error( 'ftvs_no_stream', __( 'This video is not ready to play yet.', 'faith-tv-series' ) );
			}
		);
	}

	public static function category_link( $slug ) {
		return add_query_arg( 'tenant', self::tenant(), self::base() . '/browse/' . rawurlencode( $slug ) );
	}

	public static function video_link( $slug, $category_id ) {
		return add_query_arg( 'tenant', self::tenant(), self::base() . '/watch/' . rawurlencode( $slug ) );
	}

	/**
	 * Finds a church on a Faith Stream server (used while connecting; not cached).
	 *
	 * @return array|WP_Error Settings to save plus 'rows' for the preview.
	 */
	public static function lookup( $base, $tenant ) {
		$base = trim( (string) $base );
		if ( '' !== $base && ! preg_match( '#^https?://#i', $base ) ) {
			$base = 'https://' . $base;
		}
		$base   = untrailingslashit( esc_url_raw( $base ) );
		$tenant = strtolower( trim( (string) $tenant ) );
		if ( '' === $base || ! self::is_id( $tenant ) ) {
			return new WP_Error( 'ftvs_lookup', __( 'Type your Faith Stream address and church ID.', 'faith-tv-series' ) );
		}
		$church = self::get( '/api/tenant', $base, $tenant );
		if ( is_wp_error( $church ) || empty( $church['name'] ) ) {
			return new WP_Error( 'ftvs_lookup', __( 'We could not find that church on that Faith Stream address. Check both and try again.', 'faith-tv-series' ) );
		}
		$home = self::get( '/api/public/home', $base, $tenant );
		$rows = array();
		foreach ( is_wp_error( $home ) || empty( $home['rows'] ) ? array() : $home['rows'] as $row ) {
			if ( ! empty( $row['category'] ) ) {
				$rows[] = self::category( $row['category'], $row, $base );
			}
		}
		return array(
			'source'      => 'faithstream',
			'fs_url'      => $base,
			'fs_tenant'   => $tenant,
			'church_name' => (string) $church['name'],
			'church_logo' => empty( $church['logo_url'] ) ? '' : self::absolute( $church['logo_url'], $base ),
			'rows'        => $rows,
		);
	}

	/** @internal */
	public static function get( $path, $base = null, $tenant = null ) {
		$base   = null === $base ? self::base() : $base;
		$tenant = null === $tenant ? self::tenant() : $tenant;
		$response = wp_safe_remote_get(
			$base . $path,
			array(
				'timeout'    => 8,
				'user-agent' => 'FaithTVSeries/' . FTVS_VERSION . '; ' . home_url( '/' ),
				'headers'    => array(
					'Accept'   => 'application/json',
					'X-Tenant' => $tenant,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
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
			'subcategories' => isset( $c['children_count'] ) ? (int) $c['children_count'] : 0,
		);
	}

	/** @internal */
	public static function video( $v, $parent ) {
		$image = empty( $v['thumbnail_url'] ) ? '' : self::absolute( $v['thumbnail_url'] );
		return array(
			'id'          => (string) $v['slug'],
			'parent'      => (string) $parent,
			'title'       => trim( (string) ( isset( $v['title'] ) ? $v['title'] : '' ) ),
			'description' => trim( (string) ( isset( $v['description'] ) ? $v['description'] : '' ) ),
			'image'       => $image,
			'poster'      => $image,
			'length'      => isset( $v['duration_s'] ) ? (int) round( (float) $v['duration_s'] ) : 0,
			'added'       => isset( $v['published_at'] ) ? (string) $v['published_at'] : '',
			'live'        => false,
		);
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
