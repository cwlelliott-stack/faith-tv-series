<?php
/**
 * "Try it with sample videos": a small made-up church, so a webmaster can see every layout on
 * their own site before connecting anything. Sample sections only ever show to people who can
 * edit the site (FTVS_Renderer checks), never to visitors. Pictures ship with the plugin; the
 * player uses Mux's public test stream.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Demo_Client {

	const STREAM = 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8';

	public static function is_id( $value ) {
		return 1 === preg_match( '/^demo-[a-z0-9-]{1,80}$/', $value );
	}

	/** The whole sample catalog. */
	private static function data() {
		$series = array(
			'hope-rising'      => array( __( 'Hope Rising', 'faith-tv-series' ), __( 'Four messages on holding on when life lets go of you.', 'faith-tv-series' ), 4, 'Pastor Sam Rivera' ),
			'the-way-home'     => array( __( 'The Way Home', 'faith-tv-series' ), __( 'The story of the prodigal son, from three sides.', 'faith-tv-series' ), 3, 'Pastor Sam Rivera' ),
			'rooted'           => array( __( 'Rooted', 'faith-tv-series' ), __( 'Habits that keep your faith steady through every season.', 'faith-tv-series' ), 5, 'Rev. Dana Brooks' ),
			'unshaken'         => array( __( 'Unshaken', 'faith-tv-series' ), __( 'Finding peace when the news, the bills and the doctor all say otherwise.', 'faith-tv-series' ), 3, 'Pastor Sam Rivera' ),
			'grace-upon-grace' => array( __( 'Grace Upon Grace', 'faith-tv-series' ), __( 'A walk through the Gospel of John.', 'faith-tv-series' ), 4, 'Rev. Dana Brooks' ),
			'every-good-gift'  => array( __( 'Every Good Gift', 'faith-tv-series' ), __( 'Generosity, gratitude and the God who gives first.', 'faith-tv-series' ), 3, 'Guest: Dr. Lee Park' ),
		);
		$books  = array( 'John 3:16', 'Psalm 46:1', 'Luke 15:11-32', 'Romans 8:28', 'James 1:17', 'Isaiah 40:31' );
		$cats   = array();
		$videos = array();
		$day    = strtotime( 'last sunday' );
		$n      = 0;
		foreach ( $series as $slug => $info ) {
			$id                           = 'demo-' . $slug;
			$cats[ $id ]                  = array(
				'id'            => $id,
				'title'         => $info[0],
				'description'   => $info[1],
				'image'         => self::art( $slug ),
				'videos'        => $info[2],
				'subcategories' => 0,
			);
			$videos[ $id ]                = array();
			for ( $i = 1; $i <= $info[2]; $i++ ) {
				$videos[ $id ][] = array(
					'id'          => $id . '-' . $i,
					'parent'      => $id,
					/* translators: 1: series name, 2: part number */
					'title'       => sprintf( __( '%1$s, Part %2$d', 'faith-tv-series' ), $info[0], $i ),
					'description' => $info[1],
					'image'       => self::art( $slug ),
					'poster'      => self::art( $slug ),
					'length'      => 1800 + 137 * $i,
					'added'       => gmdate( 'Y-m-d\TH:i:s\Z', $day - DAY_IN_SECONDS * 7 * $n++ ),
					'live'        => false,
					'speaker'     => $info[3],
					'scripture'   => $books[ $n % count( $books ) ],
					'tags'        => array(),
				);
			}
		}
		$rows = array(
			'demo-sermon-series' => array(
				'id'            => 'demo-sermon-series',
				'title'         => __( 'Sermon Series', 'faith-tv-series' ),
				'description'   => __( 'Sample series to try the layouts with.', 'faith-tv-series' ),
				'image'         => self::art( 'hope-rising' ),
				'videos'        => 0,
				'subcategories' => count( $cats ),
			),
			'demo-kids'          => array(
				'id'            => 'demo-kids',
				'title'         => __( 'Kids Church', 'faith-tv-series' ),
				'description'   => '',
				'image'         => self::art( 'kids-church' ),
				'videos'        => 2,
				'subcategories' => 0,
			),
		);
		$kids = array();
		for ( $i = 1; $i <= 2; $i++ ) {
			$kids[] = array(
				'id'          => 'demo-kids-' . $i,
				'parent'      => 'demo-kids',
				/* translators: %d: lesson number */
				'title'       => sprintf( __( 'Kids Church, Lesson %d', 'faith-tv-series' ), $i ),
				'description' => __( 'A short lesson and a song for kids.', 'faith-tv-series' ),
				'image'       => self::art( 'kids-church' ),
				'poster'      => self::art( 'kids-church' ),
				'length'      => 600,
				'added'       => gmdate( 'Y-m-d\TH:i:s\Z', $day - DAY_IN_SECONDS * $i ),
				'live'        => false,
				'speaker'     => '',
				'scripture'   => '',
				'tags'        => array(),
			);
		}
		$videos['demo-kids'] = $kids;
		return array(
			'rows'   => $rows,
			'series' => $cats,
			'videos' => $videos,
		);
	}

	private static function art( $slug ) {
		return FTVS_URL . 'assets/demo/' . $slug . '.svg';
	}

	public static function get_children( $id = '' ) {
		$d = self::data();
		if ( '' === $id ) {
			return array(
				'categories' => array_values( $d['rows'] ),
				'videos'     => array(),
			);
		}
		if ( 'demo-sermon-series' === $id ) {
			return array(
				'categories' => array_values( $d['series'] ),
				'videos'     => array(),
			);
		}
		if ( isset( $d['videos'][ $id ] ) ) {
			return array(
				'categories' => array(),
				'videos'     => $d['videos'][ $id ],
			);
		}
		return new WP_Error( 'ftvs_gone', __( 'That is no longer on your channel.', 'faith-tv-series' ) );
	}

	public static function fetch_tree() {
		$d    = self::data();
		$tree = array();
		foreach ( $d['rows'] as $id => $row ) {
			$row['children'] = 'demo-sermon-series' === $id ? array_values( $d['series'] ) : array();
			$row['style']    = '';
			$tree[]          = $row;
		}
		return $tree;
	}

	public static function get_video( $id ) {
		foreach ( self::data()['videos'] as $list ) {
			foreach ( $list as $video ) {
				if ( $video['id'] === $id ) {
					$video['hls']      = self::STREAM;
					$video['audio']    = '';
					$video['captions'] = false;
					$video['series']   = array();
					$video['related']  = array();
					return $video;
				}
			}
		}
		return new WP_Error( 'ftvs_gone', __( 'That is no longer on your channel.', 'faith-tv-series' ) );
	}

	public static function get_video_url( $id ) {
		$video = self::get_video( $id );
		return is_wp_error( $video ) ? $video : $video['hls'];
	}

	public static function category_link( $id ) {
		return '';
	}

	public static function video_link( $video_id, $category_id ) {
		return '';
	}
}
