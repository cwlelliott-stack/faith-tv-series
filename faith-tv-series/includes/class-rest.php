<?php
/**
 * Read-only endpoints the page script uses when a visitor opens a series or
 * presses play. Everything served here is already public on the church's channel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Rest {

	const NS = 'faith-tv/v1';
	const ID = '(?P<id>[A-Za-z0-9_-]{1,128})';

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/category/' . self::ID,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'category' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/video/' . self::ID,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'video' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function category( WP_REST_Request $request ) {
		$id   = 'gideo' === FTVS_Catalog::source() ? strtolower( $request['id'] ) : $request['id'];
		$data = FTVS_Catalog::get_children( $id );
		if ( is_wp_error( $data ) ) {
			return new WP_Error( $data->get_error_code(), $data->get_error_message(), array( 'status' => 502 ) );
		}
		$data         = FTVS_Catalog::with_links( $data, $id );
		$data['link'] = FTVS_Catalog::category_link( $id );
		return self::cacheable( $data );
	}

	public static function video( WP_REST_Request $request ) {
		$id  = 'gideo' === FTVS_Catalog::source() ? strtolower( $request['id'] ) : $request['id'];
		$url = FTVS_Catalog::get_video_url( $id );
		if ( is_wp_error( $url ) ) {
			return new WP_Error( $url->get_error_code(), $url->get_error_message(), array( 'status' => 502 ) );
		}
		return self::cacheable( array( 'hls' => $url ) );
	}

	private static function cacheable( $data ) {
		$response = rest_ensure_response( $data );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}
}
