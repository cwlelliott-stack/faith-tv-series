<?php
/**
 * Plugin settings (one option row).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Settings {

	const OPTION = 'ftvs_settings';

	public static function defaults() {
		return array(
			'account_id'    => 'Faith-Tabernacle-1',
			'tv_url'        => 'https://tv.faithtabernacle.com',
			'cache_minutes' => 15,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		$account           = isset( $input['account_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', $input['account_id'] ) : '';
		$out['account_id'] = '' !== $account ? $account : $defaults['account_id'];

		$tv            = isset( $input['tv_url'] ) ? esc_url_raw( trim( $input['tv_url'] ) ) : '';
		$out['tv_url'] = '' !== $tv ? untrailingslashit( $tv ) : $defaults['tv_url'];

		$minutes              = isset( $input['cache_minutes'] ) ? absint( $input['cache_minutes'] ) : $defaults['cache_minutes'];
		$out['cache_minutes'] = max( 1, min( 1440, $minutes ) );

		// A different account means different catalog data.
		$old = self::all();
		if ( $old['account_id'] !== $out['account_id'] ) {
			FTVS_Gideo_Client::clear_cache();
		}

		return $out;
	}
}
