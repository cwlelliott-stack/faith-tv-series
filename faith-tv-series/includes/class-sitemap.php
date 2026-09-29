<?php
/**
 * Lists every message's page (/watch/<video>/) in WordPress's sitemap (wp-sitemap.xml).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Sitemap extends WP_Sitemaps_Provider {

	const PER_PAGE = 1000;

	public function __construct() {
		$this->name        = 'faithtv';
		$this->object_type = 'faithtv';
	}

	private function videos() {
		$library = FTVS_Catalog::library();
		return is_wp_error( $library ) ? array() : $library;
	}

	public function get_url_list( $page_num, $object_subtype = '' ) {
		$out = array();
		foreach ( array_slice( $this->videos(), ( max( 1, (int) $page_num ) - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $video ) {
			$url = FTVS_Watch::url( $video['id'] );
			if ( '' === $url ) {
				continue;
			}
			$entry = array( 'loc' => $url );
			$t     = $video['added'] ? strtotime( $video['added'] ) : false;
			if ( $t ) {
				$entry['lastmod'] = gmdate( 'c', $t );
			}
			$out[] = $entry;
		}
		return $out;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return max( 1, (int) ceil( count( $this->videos() ) / self::PER_PAGE ) );
	}
}
