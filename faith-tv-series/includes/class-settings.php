<?php
/**
 * Plugin settings (one option row). Each admin form sends only its own fields;
 * everything else keeps its saved value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Settings {

	const OPTION   = 'ftvs_settings';
	const LAYOUTS  = array( 'showcase', 'coverflow', 'list', 'row', 'grid' );
	const MLAYOUTS = array( 'auto', 'same', 'showcase', 'coverflow', 'list', 'row', 'grid' );

	public static function defaults() {
		return array(
			// Which church, and where its videos live.
			'source'        => '',
			'account_id'    => '',
			'tv_url'        => '',
			'fs_url'        => '',
			'fs_tenant'     => '',
			'church_name'   => '',
			'church_logo'   => '',
			// Look & feel defaults for every section.
			'accent'        => '#C40D3C',
			'layout'        => 'showcase',
			'mobile_layout' => 'auto',
			'label'         => '',
			'badge'         => 'New',
			'powered_by'    => 1,
			// Plumbing.
			'cache_minutes' => 15,
			'update_token'  => '',
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

	/** Merge changes into the saved settings (runs through sanitize()). */
	public static function update( $changes ) {
		update_option( self::OPTION, array_merge( self::all(), $changes ) );
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = self::all();
		$out   = $old;

		if ( isset( $input['source'] ) ) {
			$out['source'] = in_array( $input['source'], array( 'gideo', 'faithstream' ), true ) ? $input['source'] : '';
		}
		if ( isset( $input['account_id'] ) ) {
			$out['account_id'] = preg_replace( '/[^A-Za-z0-9_-]/', '', $input['account_id'] );
		}
		foreach ( array( 'tv_url', 'fs_url', 'church_logo' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = untrailingslashit( esc_url_raw( trim( $input[ $key ] ) ) );
			}
		}
		if ( isset( $input['fs_tenant'] ) ) {
			$out['fs_tenant'] = preg_replace( '/[^a-z0-9-]/', '', strtolower( $input['fs_tenant'] ) );
		}
		foreach ( array( 'church_name', 'label', 'badge' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		if ( isset( $input['accent'] ) ) {
			$hex           = sanitize_hex_color( $input['accent'] );
			$out['accent'] = $hex ? strtoupper( $hex ) : $old['accent'];
		}
		if ( isset( $input['layout'] ) && in_array( $input['layout'], self::LAYOUTS, true ) ) {
			$out['layout'] = $input['layout'];
		}
		if ( isset( $input['mobile_layout'] ) && in_array( $input['mobile_layout'], self::MLAYOUTS, true ) ) {
			$out['mobile_layout'] = $input['mobile_layout'];
		}
		if ( isset( $input['powered_by'] ) ) {
			$out['powered_by'] = empty( $input['powered_by'] ) ? 0 : 1;
		}
		if ( isset( $input['cache_minutes'] ) ) {
			$out['cache_minutes'] = max( 1, min( 1440, absint( $input['cache_minutes'] ) ) );
		}

		// The token is never shown again, so an empty box keeps the saved one.
		if ( ! empty( $input['update_token_remove'] ) ) {
			$out['update_token'] = '';
		} elseif ( isset( $input['update_token'] ) && '' !== trim( $input['update_token'] ) ) {
			$out['update_token'] = preg_replace( '/[^A-Za-z0-9_]/', '', $input['update_token'] );
		}
		if ( $out['update_token'] !== $old['update_token'] && class_exists( 'FTVS_Updater' ) ) {
			FTVS_Updater::forget();
		}

		unset( $out['update_token_remove'] );
		return $out;
	}

	/**
	 * Versions before 1.2 only worked with Faith Tabernacle's channel, and may never have saved
	 * any settings. Keep those sites connected; brand-new installs start unconnected.
	 */
	public static function maybe_migrate() {
		$saved = get_option( self::OPTION, false );
		if ( is_array( $saved ) ) {
			if ( ! isset( $saved['source'] ) ) {
				$saved['source'] = ! empty( $saved['account_id'] ) ? 'gideo' : '';
				update_option( self::OPTION, $saved );
			}
			return;
		}
		global $wpdb;
		$used_before = (bool) $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'ftvs\\_bk\\_%' LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		add_option(
			self::OPTION,
			$used_before
				? array(
					'source'      => 'gideo',
					'account_id'  => 'Faith-Tabernacle-1',
					'tv_url'      => 'https://tv.faithtabernacle.com',
					'church_name' => 'Faith Tabernacle',
					'church_logo' => 'https://tv.faithtabernacle.com/webtv-assets/logo.png',
				)
				: array()
		);
	}
}
