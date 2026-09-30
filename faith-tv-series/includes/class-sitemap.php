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

	public function get_url_list( $page_num, $object_subtype = '' ) {
		return array_slice( FTVS_Watch::sitemap_entries(), ( max( 1, (int) $page_num ) - 1 ) * self::PER_PAGE, self::PER_PAGE );
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return max( 1, (int) ceil( count( FTVS_Watch::sitemap_entries() ) / self::PER_PAGE ) );
	}
}
