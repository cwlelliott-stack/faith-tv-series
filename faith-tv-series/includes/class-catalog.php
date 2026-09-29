<?php
/**
 * The connected church's video catalog, whichever platform it lives on.
 *
 * Both clients return the same shapes:
 *   category { id, title, description, image, videos, subcategories }
 *   video    { id, parent, title, description, image, poster, length, added, live }
 * Ids are Gideo's 32-character ids or Faith Stream slugs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Catalog {

	const TREE_TTL = 6 * HOUR_IN_SECONDS;

	/** 'gideo', 'faithstream', or '' when no church is connected yet. */
	public static function source() {
		$source = (string) FTVS_Settings::get( 'source' );
		return in_array( $source, array( 'gideo', 'faithstream' ), true ) ? $source : '';
	}

	public static function connected() {
		$source = self::source();
		if ( 'gideo' === $source ) {
			return '' !== (string) FTVS_Settings::get( 'account_id' );
		}
		if ( 'faithstream' === $source ) {
			return '' !== (string) FTVS_Settings::get( 'fs_url' ) && '' !== (string) FTVS_Settings::get( 'fs_tenant' );
		}
		return false;
	}

	/**
	 * Cache namespace: changes whenever a different church is connected.
	 * For Gideo it is the bare account id, the same key 1.1 used, so caches and
	 * outage backups carry over through the update.
	 */
	public static function identity() {
		if ( 'faithstream' === self::source() ) {
			return 'f:' . FTVS_Settings::get( 'fs_url' ) . '|' . FTVS_Settings::get( 'fs_tenant' );
		}
		return (string) FTVS_Settings::get( 'account_id' );
	}

	private static function client() {
		return 'faithstream' === self::source() ? 'FTVS_FaithStream_Client' : 'FTVS_Gideo_Client';
	}

	private static function not_connected() {
		return new WP_Error( 'ftvs_not_connected', __( 'No church is connected yet. Go to Faith Stream > Church in the WordPress admin.', 'faith-tv-series' ) );
	}

	/**
	 * Sub-categories and videos directly inside a category ('' = the home rows).
	 *
	 * @return array|WP_Error { categories: array, videos: array }
	 */
	public static function get_children( $id = '' ) {
		if ( ! self::connected() ) {
			return self::not_connected();
		}
		if ( '' !== $id && ! self::is_id( $id ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a category on your channel.', 'faith-tv-series' ) );
		}
		$data = call_user_func( array( self::client(), 'get_children' ), $id );
		if ( ! is_wp_error( $data ) ) {
			self::learn( $id, $data );
		}
		return $data;
	}

	/**
	 * Whether an id has been seen in the church's catalog. Public endpoints (REST and embeds)
	 * only fetch known ids, so a visitor can't make the site call the platform for made-up ones.
	 */
	public static function is_known( $id ) {
		if ( ! self::is_id( $id ) ) {
			return false;
		}
		$known = self::known();
		if ( isset( $known[ $id ] ) ) {
			return true;
		}
		// Not seen yet on this site: filling the category list teaches it every row and series.
		self::get_tree();
		$known = self::known();
		return isset( $known[ $id ] );
	}

	private static function known_key() {
		return 'ftvs_known_' . md5( self::identity() );
	}

	private static function known() {
		$known = get_option( self::known_key(), array() );
		return is_array( $known ) ? $known : array();
	}

	/** Remember the category and everything listed in it (writes only when something is new). */
	private static function learn( $id, $data ) {
		$known = self::known();
		$ids   = array_merge( '' === $id ? array() : array( $id ), wp_list_pluck( $data['categories'], 'id' ), wp_list_pluck( $data['videos'], 'id' ) );
		$new   = false;
		foreach ( $ids as $one ) {
			if ( is_string( $one ) && '' !== $one && ! isset( $known[ $one ] ) ) {
				$known[ $one ] = 1;
				$new           = true;
			}
		}
		if ( $new ) {
			update_option( self::known_key(), $known, false );
		}
	}

	/** @return string|WP_Error HLS address. */
	public static function get_video_url( $id ) {
		if ( ! self::connected() ) {
			return self::not_connected();
		}
		if ( ! self::is_id( $id ) ) {
			return new WP_Error( 'ftvs_bad_id', __( 'That is not a video on your channel.', 'faith-tv-series' ) );
		}
		return call_user_func( array( self::client(), 'get_video_url' ), $id );
	}

	public static function is_id( $value ) {
		return is_string( $value ) && call_user_func( array( self::client(), 'is_id' ), $value );
	}

	public static function category_link( $id ) {
		return call_user_func( array( self::client(), 'category_link' ), $id );
	}

	public static function video_link( $video_id, $category_id ) {
		return call_user_func( array( self::client(), 'video_link' ), $video_id, $category_id );
	}

	/** Web address of the church's channel, for "Watch on ..." links. */
	public static function channel_url() {
		return 'faithstream' === self::source() ? (string) FTVS_Settings::get( 'fs_url' ) : (string) FTVS_Settings::get( 'tv_url' );
	}

	public static function channel_host() {
		$host = wp_parse_url( self::channel_url(), PHP_URL_HOST );
		return $host ? preg_replace( '/^www\./', '', $host ) : '';
	}

	/**
	 * Home rows plus one level of children. For the admin pages, the Elementor picker,
	 * and looking categories up by name.
	 *
	 * @return array|WP_Error List of categories, each with 'children'.
	 */
	public static function get_tree() {
		if ( ! self::connected() ) {
			return self::not_connected();
		}
		return FTVS_Cache::remember(
			'tree',
			self::TREE_TTL,
			function () {
				$home = FTVS_Catalog::get_children( '' );
				if ( is_wp_error( $home ) ) {
					return $home;
				}
				$tree = array();
				foreach ( $home['categories'] as $row ) {
					$row['children'] = array();
					// Faith Stream's home feed doesn't say which rows hold series, so look inside every row.
					if ( $row['subcategories'] > 0 || 'faithstream' === FTVS_Catalog::source() ) {
						$inside = FTVS_Catalog::get_children( $row['id'] );
						if ( is_wp_error( $inside ) ) {
							// Don't keep a half list for hours; the last full one is served instead.
							return $inside;
						}
						if ( ! is_wp_error( $inside ) ) {
							$row['children']      = $inside['categories'];
							$row['subcategories'] = count( $inside['categories'] );
							if ( '' === $row['image'] ) {
								$row['image'] = $inside['categories'] ? $inside['categories'][0]['image'] : ( $inside['videos'] ? $inside['videos'][0]['image'] : '' );
							}
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
		if ( '' === $needle ) {
			return new WP_Error( 'ftvs_no_category', __( 'Pick a category.', 'faith-tv-series' ) );
		}
		if ( ! self::connected() ) {
			return self::not_connected();
		}
		if ( self::is_id( $needle ) ) {
			return array(
				'id'    => 'gideo' === self::source() ? strtolower( $needle ) : $needle,
				'title' => '',
			);
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
		return new WP_Error( 'ftvs_not_found', sprintf( __( 'No category on your channel is named "%s".', 'faith-tv-series' ), $needle ) );
	}

	/** Title of a category found in the tree (for labels), or ''. */
	public static function title_of( $id ) {
		$tree = self::get_tree();
		if ( is_wp_error( $tree ) ) {
			return '';
		}
		foreach ( $tree as $row ) {
			if ( $row['id'] === $id ) {
				return $row['title'];
			}
			foreach ( $row['children'] as $child ) {
				if ( $child['id'] === $id ) {
					return $child['title'];
				}
			}
		}
		return '';
	}

	/** Adds each item's web link, for the page script. */
	public static function with_links( $data, $category_id ) {
		foreach ( $data['categories'] as $i => $cat ) {
			$data['categories'][ $i ]['link'] = self::category_link( $cat['id'] );
		}
		foreach ( $data['videos'] as $i => $video ) {
			$data['videos'][ $i ]['link'] = self::video_link( $video['id'], $video['parent'] ? $video['parent'] : $category_id );
		}
		return $data;
	}

	private static function name_key( $title ) {
		return strtolower( preg_replace( '/\s+/', ' ', trim( $title ) ) );
	}
}
