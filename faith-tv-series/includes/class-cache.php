<?php
/**
 * Caching for everything read from the church's video platform.
 *
 * Every good answer is also kept as a backup, so if the platform is down the site keeps
 * showing the last list it got instead of an empty section.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Cache {

	const ERROR_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * @param string   $key   What is being cached (unique within the connected church).
	 * @param int      $ttl   Seconds to keep a good answer.
	 * @param callable $fetch Returns the data or a WP_Error.
	 * @return mixed|WP_Error
	 */
	public static function remember( $key, $ttl, $fetch ) {
		$hash      = md5( FTVS_Catalog::identity() . '|' . $key );
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
				// Serve the last good copy and try again in a few minutes.
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

	/**
	 * Drops every cached answer (backups stay as the outage fallback).
	 * Bumping a generation number works with any object cache, not just the options table.
	 */
	public static function clear() {
		update_option( 'ftvs_cache_gen', (int) get_option( 'ftvs_cache_gen', 1 ) + 1, true );
	}
}
