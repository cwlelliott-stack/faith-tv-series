<?php
/**
 * FTVS_Catalog: the switchboard in front of the platform clients.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A platform added through the ftvs_sources filter (the catalog only needs these methods). */
class FTVS_T_Fake_Client {

	public static function is_id( $value ) {
		return 'fake-id' === $value;
	}

	public static function get_children( $id = '' ) {
		return array(
			'categories' => array(),
			'videos'     => array(),
		);
	}

	public static function get_video( $id ) {
		return new WP_Error( 'ftvs_gone', 'gone' );
	}
}

/* ---------------------------------------------------------------- which platform */

function test_catalog_sources() {
	assert_same(
		array(
			'faithstream' => 'FTVS_FaithStream_Client',
			'gideo'       => 'FTVS_Gideo_Client',
			'youtube'     => 'FTVS_YouTube_Client',
			'demo'        => 'FTVS_Demo_Client',
		),
		FTVS_Catalog::sources()
	);
}

function test_catalog_source_is_the_saved_one_when_it_is_known() {
	foreach ( array( 'faithstream', 'gideo', 'youtube', 'demo' ) as $source ) {
		ftvs_t_settings( array( 'source' => $source ) );
		assert_same( $source, FTVS_Catalog::source() );
	}
	ftvs_t_settings( array( 'source' => 'nonsense' ) );
	assert_same( '', FTVS_Catalog::source() );
	ftvs_t_settings( array( 'source' => '' ) );
	assert_same( '', FTVS_Catalog::source() );
	assert_same( 'FTVS_Gideo_Client', FTVS_Catalog::client(), 'with nothing connected the Gideo client stands in' );
}

function test_catalog_a_source_can_be_added_or_removed_with_the_filter() {
	ftvs_t_add_filter(
		'ftvs_sources',
		function ( $sources ) {
			$sources['fake'] = 'FTVS_T_Fake_Client';
			unset( $sources['gideo'] );
			return $sources;
		}
	);
	ftvs_t_settings( array( 'source' => 'fake' ) );
	assert_same( 'fake', FTVS_Catalog::source() );
	assert_same( 'FTVS_T_Fake_Client', FTVS_Catalog::client() );
	assert_true( FTVS_Catalog::is_id( 'fake-id' ) );
	assert_false( FTVS_Catalog::is_id( 'other' ) );

	ftvs_t_settings( array( 'source' => 'gideo' ) );
	assert_same( '', FTVS_Catalog::source(), 'a source taken out by the filter is not used, even if it is what was saved' );
}

function test_catalog_connected() {
	ftvs_t_settings( array() );
	assert_false( FTVS_Catalog::connected() );

	ftvs_t_settings( array( 'source' => 'gideo' ) );
	assert_false( FTVS_Catalog::connected(), 'Gideo needs an account id' );
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'Faith-Tabernacle-1' ) );
	assert_true( FTVS_Catalog::connected() );

	ftvs_t_settings( array( 'source' => 'faithstream', 'fs_url' => FTVS_T_FS ) );
	assert_false( FTVS_Catalog::connected(), 'Faith Stream needs the address and the church ID' );
	ftvs_t_settings( array( 'source' => 'faithstream', 'fs_tenant' => 'grace' ) );
	assert_false( FTVS_Catalog::connected() );
	ftvs_t_settings( array( 'source' => 'faithstream', 'fs_url' => FTVS_T_FS, 'fs_tenant' => 'grace' ) );
	assert_true( FTVS_Catalog::connected() );

	ftvs_t_settings( array( 'source' => 'youtube' ) );
	assert_false( FTVS_Catalog::connected() );
	ftvs_t_settings( array( 'source' => 'youtube', 'yt_channel' => '@GraceChurch' ) );
	assert_true( FTVS_Catalog::connected(), 'a channel is enough' );
	ftvs_t_settings( array( 'source' => 'youtube', 'yt_playlists' => array( FTVS_T_PL1 ) ) );
	assert_true( FTVS_Catalog::connected(), 'and so are playlists' );

	ftvs_t_demo();
	assert_true( FTVS_Catalog::connected() );
	assert_true( FTVS_Catalog::is_demo() );

	ftvs_t_gideo();
	assert_false( FTVS_Catalog::is_demo() );
}

function test_catalog_identity_changes_with_the_church() {
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'Faith-Tabernacle-1' ) );
	assert_same( 'Faith-Tabernacle-1', FTVS_Catalog::identity(), 'the bare account id, as 1.1 used, so old caches carry over' );

	ftvs_t_settings( array( 'source' => 'faithstream', 'fs_url' => FTVS_T_FS, 'fs_tenant' => 'grace' ) );
	assert_same( 'f:' . FTVS_T_FS . '|grace', FTVS_Catalog::identity() );
	ftvs_t_settings( array( 'source' => 'faithstream', 'fs_url' => FTVS_T_FS, 'fs_tenant' => 'elevate' ) );
	assert_same( 'f:' . FTVS_T_FS . '|elevate', FTVS_Catalog::identity(), 'another church on the same server' );

	ftvs_t_settings( array( 'source' => 'youtube', 'yt_channel' => '@A', 'yt_playlists' => array() ) );
	$one = FTVS_Catalog::identity();
	ftvs_t_settings( array( 'source' => 'youtube', 'yt_channel' => '@A', 'yt_playlists' => array( FTVS_T_PL1 ) ) );
	$two = FTVS_Catalog::identity();
	assert_matches( '/^y:[0-9a-f]{32}$/', $one );
	assert_not_same( $one, $two );

	ftvs_t_demo();
	assert_same( 'demo', FTVS_Catalog::identity() );
	ftvs_t_settings( array() );
	assert_same( '', FTVS_Catalog::identity() );
}

function test_catalog_only_faith_stream_knows_what_is_live() {
	ftvs_t_faithstream();
	assert_true( FTVS_Catalog::has_live() );
	ftvs_t_gideo();
	assert_false( FTVS_Catalog::has_live() );
	ftvs_t_settings( array( 'source' => 'youtube', 'yt_channel' => '@A' ) );
	assert_false( FTVS_Catalog::has_live() );
	ftvs_t_demo();
	assert_false( FTVS_Catalog::has_live() );
	ftvs_t_settings( array( 'source' => 'faithstream' ) );
	assert_false( FTVS_Catalog::has_live(), 'not connected yet' );
}

function test_catalog_channel_url_and_host() {
	ftvs_t_faithstream( array( 'fs_url' => 'https://www.stream.example.test' ) );
	assert_same( 'https://www.stream.example.test/?tenant=' . FTVS_Settings::get( 'fs_tenant' ), FTVS_Catalog::channel_url() );
	assert_same( 'stream.example.test', FTVS_Catalog::channel_host(), '"www." is dropped' );

	ftvs_t_gideo( array( 'tv_url' => 'https://tv.grace.church' ) );
	assert_same( 'https://tv.grace.church', FTVS_Catalog::channel_url() );
	assert_same( 'tv.grace.church', FTVS_Catalog::channel_host() );

	ftvs_t_gideo( array( 'tv_url' => '' ) );
	assert_same( '', FTVS_Catalog::channel_url() );
	assert_same( '', FTVS_Catalog::channel_host() );

	ftvs_t_settings( array( 'source' => 'youtube', 'yt_channel' => '@GraceChurch' ) );
	assert_same( 'https://www.youtube.com/@GraceChurch', FTVS_Catalog::channel_url() );
	assert_same( 'youtube.com', FTVS_Catalog::channel_host() );

	ftvs_t_demo();
	assert_same( '', FTVS_Catalog::channel_url() );
}

/* ---------------------------------------------------------------- find_category */

function test_catalog_find_category_automatic_picks() {
	assert_same(
		array(
			'id'    => '@newest',
			'title' => 'Latest messages',
		),
		FTVS_Catalog::find_category( '@newest' ),
		'they work before anything is connected'
	);
	assert_same( '@featured', FTVS_Catalog::find_category( '@FEATURED' )['id'], 'case does not matter' );
	assert_same( 'Featured', FTVS_Catalog::find_category( ' @featured ' )['title'] );
}

function test_catalog_find_category_needs_something_to_look_for() {
	assert_wp_error( FTVS_Catalog::find_category( '' ), 'ftvs_no_category' );
	assert_wp_error( FTVS_Catalog::find_category( "  \n " ), 'ftvs_no_category' );
	assert_wp_error( FTVS_Catalog::find_category( 'kids-rock' ), 'ftvs_not_connected' );
}

function test_catalog_find_category_series_you_built_that_no_longer_exist() {
	$err = FTVS_Catalog::find_category( '_ms999999999' );
	assert_wp_error( $err, 'ftvs_not_found' );
	assert_contains( 'deleted', $err->get_error_message() );
}

function test_catalog_find_category_gideo_ids_are_lower_case() {
	ftvs_t_gideo();
	$found = FTVS_Catalog::find_category( strtoupper( FTVS_T_CAT_A ) );
	assert_same( FTVS_T_CAT_A, $found['id'], 'Gideo ids are lower case, however they were typed' );
	assert_count( 0, ftvs_t_requests(), 'an id is taken as it is: nothing is looked up' );
}

function test_catalog_find_category_by_slug_and_by_name_on_faith_stream() {
	ftvs_t_faithstream_with_home();
	$found = FTVS_Catalog::find_category( 'kids-rock' );
	assert_same( 'kids-rock', $found['id'], 'a slug is used as it is' );

	$found = FTVS_Catalog::find_category( 'Kids Rock' );
	assert_same( 'kids-rock', $found['id'] );
	assert_same( 'Kids Rock', $found['title'] );

	$found = FTVS_Catalog::find_category( "  community   &  EVENTS " );
	assert_same( 'community-events', $found['id'], 'names match ignoring case and repeated spaces' );

	$found = FTVS_Catalog::find_category( 'Long Game' );
	assert_same( 'long-game', $found['id'], 'a series inside a row is found by name too' );
}

function test_catalog_find_category_home_rows_win_over_a_series_with_the_same_name() {
	ftvs_t_faithstream_with_home();
	// "Faith TV Mini Series" is a home row; "FAITH TV Mini Series" is also a series inside Featured.
	$found = FTVS_Catalog::find_category( 'faith tv mini series' );
	assert_same( 'faith-tv-mini-series-2', $found['id'], 'the home row, not the featured copy' );
}

function test_catalog_find_category_unknown_name() {
	ftvs_t_faithstream_with_home();
	$err = FTVS_Catalog::find_category( 'No Such Category' );
	assert_wp_error( $err, 'ftvs_not_found' );
	assert_contains( 'No Such Category', $err->get_error_message() );
}

function test_catalog_find_category_passes_a_platform_error_on() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/home', ftvs_t_text( 'down', 503 ) );
	assert_wp_error( FTVS_Catalog::find_category( 'Kids Rock' ), 'ftvs_http' );
}

function test_catalog_title_of() {
	ftvs_t_faithstream_with_home();
	assert_same( 'Kids Rock', FTVS_Catalog::title_of( 'kids-rock' ) );
	assert_same( 'Long Game', FTVS_Catalog::title_of( 'long-game' ), 'series inside a row' );
	assert_same( '', FTVS_Catalog::title_of( 'nothing-here' ) );
	ftvs_t_settings( array() );
	assert_same( '', FTVS_Catalog::title_of( 'kids-rock' ), 'nothing connected' );
}

/* ---------------------------------------------------------------- children, tree, known ids */

function test_catalog_get_children_errors() {
	assert_wp_error( FTVS_Catalog::get_children( 'kids-rock' ), 'ftvs_not_connected' );
	assert_wp_error( FTVS_Catalog::get_children( '' ), 'ftvs_not_connected' );

	ftvs_t_faithstream();
	assert_wp_error( FTVS_Catalog::get_children( 'Not A Slug' ), 'ftvs_bad_id' );
	assert_wp_error( FTVS_Catalog::get_children( '../../etc/passwd' ), 'ftvs_bad_id' );
	ftvs_t_gideo();
	assert_wp_error( FTVS_Catalog::get_children( 'kids-rock' ), 'ftvs_bad_id', 'Gideo ids are 32 hex digits' );
	assert_count( 0, ftvs_t_requests(), 'a bad id never reaches the platform' );
}

function test_catalog_get_children_of_a_deleted_series_you_built() {
	assert_wp_error( FTVS_Catalog::get_children( '_ms999999999' ), 'ftvs_gone' );
	assert_wp_error( FTVS_Catalog::get_video( '_mv999999999x00000000' ), 'ftvs_gone' );
}

function test_catalog_get_children_home_lists_the_rows_on_faith_stream() {
	ftvs_t_faithstream_with_home();
	$home = FTVS_Catalog::get_children( '' );
	assert_not_error( $home );
	assert_same( count( ftvs_t_sample_home()['rows'] ), count( $home['categories'] ) );
	assert_same( 'welcome', $home['categories'][0]['id'] );
	assert_same( array(), $home['videos'] );
}

function test_catalog_get_tree_faith_stream() {
	ftvs_t_faithstream_with_home();
	$tree = FTVS_Catalog::get_tree();
	assert_not_error( $tree );
	$tree = array_values(
		array_filter(
			$tree,
			function ( $row ) {
				return ! FTVS_Manual::owns( $row['id'] );
			}
		)
	);
	assert_same( count( ftvs_t_sample_home()['rows'] ), count( $tree ) );
	assert_same( 'hero', $tree[0]['style'] );
	assert_same( 'slider', $tree[1]['style'] );
	assert_count( 5, $tree[1]['children'] );
	assert_count( 15, $tree[2]['children'] );
	assert_same( 'faith-tv-mini-series-2', $tree[2]['id'] );
}

function test_catalog_get_tree_without_a_church() {
	$tree = FTVS_Catalog::get_tree();
	if ( is_wp_error( $tree ) ) {
		assert_wp_error( $tree, 'ftvs_not_connected' );
	} else {
		assert_same( array( FTVS_Manual::ROW ), wp_list_pluck( $tree, 'id' ), 'only the series built by hand, when the site has some' );
	}
}

function test_catalog_is_known_only_after_the_catalog_has_shown_it() {
	ftvs_t_faithstream_with_home();
	assert_true( FTVS_Catalog::is_known( 'kids-rock' ), 'a home row' );
	assert_true( FTVS_Catalog::is_known( 'long-game' ), 'a series inside a row' );
	assert_false( FTVS_Catalog::is_known( 'never-seen-before' ) );
	assert_false( FTVS_Catalog::is_known( 'Not A Slug' ), 'not even the right shape' );
	assert_false( FTVS_Catalog::is_known( '' ) );
	assert_false( FTVS_Catalog::is_known( '_ms999999999' ), 'a series built by hand that was deleted' );
	assert_false( FTVS_Catalog::is_known( 'outrageous-part-4' ), 'a video is known once a page has listed it' );
	FTVS_Catalog::newest( 500 );
	assert_true( FTVS_Catalog::is_known( 'outrageous-part-4' ) );
}

function test_catalog_on_gone_forgets_the_id() {
	ftvs_t_faithstream_with_home();
	FTVS_Catalog::get_tree();
	$option = 'ftvs_known_' . md5( FTVS_Catalog::identity() );
	assert_has_key( 'kids-rock', get_option( $option ) );
	FTVS_Catalog::on_gone( 'c_kids-rock' );
	assert_false( isset( get_option( $option )['kids-rock'] ), 'links to a removed category stop working' );
	assert_true( isset( get_option( $option )['elevate'] ), 'others are kept' );
	$before = get_option( $option );
	FTVS_Catalog::on_gone( 'tree' ); // not a category or video key
	FTVS_Catalog::on_gone( 'h_something' );
	assert_same( $before, get_option( $option ) );
	FTVS_Catalog::on_gone( 'v_long-game' );
	assert_false( isset( get_option( $option )['long-game'] ), 'video keys start with v_' );
	assert_true( (bool) wp_next_scheduled( FTVS_Purge::CRON ), 'and page caches are asked to clear' );
}

/* ---------------------------------------------------------------- newest, featured, library, search */

function test_catalog_newest_on_faith_stream_is_deduplicated_and_sorted() {
	ftvs_t_faithstream_with_home();
	$data = FTVS_Catalog::newest();
	assert_not_error( $data );
	assert_same( array(), $data['categories'] );
	$videos = $data['videos'];
	assert_count( 24, $videos, 'the 24 newest' );
	assert_same( 'outrageous-part-4', $videos[0]['id'], 'listed under two rows, shown once, and first' );
	assert_count( 24, array_unique( wp_list_pluck( $videos, 'id' ) ) );
	$times = array_map(
		function ( $v ) {
			return strtotime( $v['added'] );
		},
		$videos
	);
	$sorted = $times;
	rsort( $sorted );
	assert_same( $sorted, $times, 'newest first' );
	assert_count( 5, FTVS_Catalog::newest( 5 )['videos'], 'the limit can be changed' );
}

function test_catalog_newest_on_faith_stream_passes_errors_on() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/home', ftvs_t_text( 'down', 500 ) );
	assert_wp_error( FTVS_Catalog::newest(), 'ftvs_http' );
	assert_wp_error( FTVS_Catalog::featured(), 'ftvs_http' );
}

function test_catalog_newest_and_featured_need_a_church() {
	assert_wp_error( FTVS_Catalog::featured(), 'ftvs_not_connected' );
}

function test_catalog_featured_on_faith_stream_is_the_hero_and_the_slider() {
	ftvs_t_faithstream_with_home();
	$sample = ftvs_t_sample_home();
	// The slider row's own listing (it lets a series without artwork borrow its first episode's picture).
	ftvs_t_route( 'categories/featured?', ftvs_t_json( ftvs_t_fs_category_page( 'featured', array(), 0, array( 'children' => $sample['rows'][1]['children'] ) ) ) );
	$data = FTVS_Catalog::featured();
	assert_not_error( $data );
	assert_same( array( 'welcome-to-faith-tv' ), wp_list_pluck( $data['videos'], 'id' ), 'the hero row\'s video' );
	assert_same( array( 'faith-tv-mini-series', 'that-kids-rock-show', 'kids-rock-mini-series', 'faith-stories', 'latest-sermons' ), wp_list_pluck( $data['categories'], 'id' ), 'the series in the slider row' );
}

function test_catalog_featured_slider_falls_back_to_the_home_answer_when_its_listing_fails() {
	ftvs_t_faithstream_with_home();
	ftvs_t_route( 'categories/featured?', ftvs_t_text( 'boom', 500 ) );
	$data = FTVS_Catalog::featured();
	assert_not_error( $data );
	assert_count( 5, $data['categories'] );
	assert_same( array( 'welcome-to-faith-tv' ), wp_list_pluck( $data['videos'], 'id' ) );
}

function test_catalog_featured_slider_series_borrow_their_first_episodes_picture() {
	ftvs_t_faithstream_with_home();
	$sample   = ftvs_t_sample_home();
	$children = $sample['rows'][1]['children'];
	$children[0]['thumbnail_url'] = null; // "FAITH TV Mini Series" has no artwork of its own
	ftvs_t_route(
		'categories/featured?',
		ftvs_t_json(
			ftvs_t_fs_category_page(
				'featured',
				array(),
				0,
				array(
					'children' => $children,
					'sections' => array(
						array(
							'category' => array( 'slug' => $children[0]['slug'] ),
							'videos'   => array( array( 'thumbnail_url' => '/media/first-episode.jpg' ) ),
						),
					),
				)
			)
		)
	);
	$data = FTVS_Catalog::featured();
	assert_same( FTVS_T_FS . '/media/first-episode.jpg', $data['categories'][0]['image'] );
}

function test_catalog_featured_falls_back_to_the_first_row() {
	ftvs_t_demo();
	$data = FTVS_Catalog::featured();
	assert_not_error( $data );
	assert_count( 6, $data['categories'], 'the first row\'s series' );

	// A Faith Stream church that shows no hero or slider row.
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/home',
		ftvs_t_json(
			array(
				'rows' => array(
					array(
						'category' => array( 'slug' => 'plain', 'name' => 'Plain' ),
						'style'    => 'tiles',
						'videos'   => array(),
						'children' => array( array( 'slug' => 'kid', 'name' => 'Kid' ) ),
					),
				),
			)
		)
	);
	ftvs_t_route( 'categories/plain?', ftvs_t_json( ftvs_t_fs_category_page( 'plain', array(), 0, array( 'children' => array( array( 'slug' => 'kid', 'name' => 'Kid' ) ) ) ) ) );
	$data = FTVS_Catalog::featured();
	assert_not_error( $data );
	assert_same( array( 'kid' ), wp_list_pluck( $data['categories'], 'id' ), 'no hero or slider: what is inside the first home row' );
}

function test_catalog_library_is_every_video_newest_first_once() {
	ftvs_t_demo();
	$library = FTVS_Catalog::library();
	assert_not_error( $library );
	$ids = wp_list_pluck( $library, 'id' );
	assert_same( count( $ids ), count( array_unique( $ids ) ) );
	$times = array_map(
		function ( $v ) {
			return strtotime( $v['added'] );
		},
		array_values(
			array_filter(
				$library,
				function ( $v ) {
					return ! FTVS_Manual::owns( $v['id'] );
				}
			)
		)
	);
	$sorted = $times;
	rsort( $sorted );
	assert_same( $sorted, $times );
	$by_id = array_column( $library, null, 'id' );
	assert_same( 'Rooted', $by_id['demo-rooted-2']['series'], 'each video knows its series\' name' );
}

function test_catalog_library_without_a_church() {
	assert_wp_error( FTVS_Catalog::library(), 'ftvs_not_connected' );
}

function test_catalog_search() {
	ftvs_t_demo();
	assert_same( array(), FTVS_Catalog::search( '' ) );
	assert_same( array(), FTVS_Catalog::search( ' a ' ), 'one letter is not a search' );
	$hope = FTVS_Catalog::search( 'hope rising' );
	assert_same( array( 'demo-hope-rising-1', 'demo-hope-rising-2', 'demo-hope-rising-3', 'demo-hope-rising-4' ), wp_list_pluck( $hope, 'id' ), 'every word must match; newest first' );
	assert_same( 4, count( FTVS_Catalog::search( 'RISING hope' ) ), 'in any order and case' );
	assert_same( array(), FTVS_Catalog::search( 'hope zzzzz' ) );
	assert_count( 10, FTVS_Catalog::search( 'rivera' ), 'the speaker is searched too: Hope Rising 4, The Way Home 3, Unshaken 3' );
	assert_true( count( FTVS_Catalog::search( 'John 3:16' ) ) >= 1, 'and the scripture' );
	assert_count( 4, FTVS_Catalog::search( 'gospel of john' ), 'and the description' );
	assert_count( 5, FTVS_Catalog::search( 'rooted' ), 'and the series name' );
}

function test_catalog_search_also_asks_faith_stream_and_merges() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array( array( 'category' => array( 'slug' => 'sermons', 'name' => 'Sermons' ), 'videos' => array(), 'children' => array() ) ) ) ) );
	ftvs_t_route( 'categories/sermons?', ftvs_t_json( ftvs_t_fs_category_page( 'sermons', ftvs_t_fs_videos( 'msg', 1, 3 ), 3 ) ) );
	// A site with series built by hand also asks for their pseudo-row (see the known-bug test below).
	ftvs_t_route( 'categories/_m', ftvs_t_json( ftvs_t_fs_category_page( '_msall', array(), 0 ) ) );
	ftvs_t_route(
		'/api/public/search?q=romans',
		ftvs_t_json(
			array(
				'videos' => array(
					array(
						'slug'  => 'msg-2',
						'title' => 'Message 2',
					),
					array(
						'slug'  => 'only-in-search',
						'title' => 'Found by scripture',
					),
				),
			)
		)
	);
	$found = FTVS_Catalog::search( 'romans' );
	assert_not_error( $found );
	assert_same( array( 'msg-2', 'only-in-search' ), wp_list_pluck( $found, 'id' ), 'scripture and tags are searched on the platform; a video the library also has keeps the library\'s details' );
	$by_id = array_column( $found, null, 'id' );
	assert_same( 'sermons', $by_id['msg-2']['parent'] );
}

/** Stands in for the Faith Stream client: answers like the real API, which knows nothing of hand-built series. */
class FTVS_T_FS_Like_Client {

	public static $asked = array();

	public static function is_id( $value ) {
		return 1 === preg_match( '/^[a-z0-9_-]+$/', $value );
	}

	public static function get_children( $id = '' ) {
		return array(
			'categories' => array(),
			'videos'     => array(),
		);
	}

	public static function fetch_tree() {
		$row = function ( $id ) {
			return array(
				'id'            => $id,
				'title'         => $id,
				'description'   => '',
				'image'         => '',
				'videos'        => 0,
				'subcategories' => 0,
				'children'      => array(),
				'style'         => 'tiles',
			);
		};
		// What FTVS_Catalog::get_tree() hands on when the site also has series built by hand:
		// the platform's rows, then the pseudo-row of the hand-built ones.
		return array( $row( 'row-a' ), $row( FTVS_Manual::ROW ) );
	}

	public static function fetch_all_under( $id ) {
		self::$asked[] = $id;
		if ( FTVS_Manual::owns( $id ) ) {
			return new WP_Error( 'ftvs_gone', 'Category not found' ); // the real API answers 404
		}
		return ftvs_t_videos( $id, 1, 2 );
	}
}

function test_catalog_library_never_asks_the_platform_for_the_series_built_by_hand() {
	FTVS_T_FS_Like_Client::$asked = array();
	ftvs_t_add_filter(
		'ftvs_sources',
		function ( $sources ) {
			$sources['faithstream'] = 'FTVS_T_FS_Like_Client';
			return $sources;
		}
	);
	ftvs_t_faithstream();
	$library = FTVS_Catalog::library();
	assert_not_error( $library, 'one hand-built series must not break search, the library, message pages and the podcast' );
	assert_same( array( 'row-a' ), FTVS_T_FS_Like_Client::$asked, 'the platform is asked about its own rows only' );
}

/* ---------------------------------------------------------------- links, one video, on_changed */

function test_catalog_links_for_automatic_and_hand_built_ids_are_empty() {
	ftvs_t_faithstream();
	assert_same( '', FTVS_Catalog::category_link( '@newest' ) );
	assert_same( '', FTVS_Catalog::category_link( '' ) );
	assert_same( '', FTVS_Catalog::category_link( '_ms5' ) );
	assert_same( '', FTVS_Catalog::video_link( '_mv5x00000000', '_ms5' ) );
	assert_contains( '/browse/kids-rock?tenant=', FTVS_Catalog::category_link( 'kids-rock' ) );
	assert_contains( '/watch/v1?tenant=', FTVS_Catalog::video_link( 'v1', 'kids-rock' ) );
}

function test_catalog_with_links_adds_channel_and_watch_page_links() {
	ftvs_t_demo();
	$data = FTVS_Catalog::with_links(
		array(
			'categories' => array( array( 'id' => 'demo-rooted' ) ),
			'videos'     => array(
				array(
					'id'     => 'demo-rooted-1',
					'parent' => 'demo-rooted',
				),
			),
		),
		'demo-rooted'
	);
	assert_same( '', $data['categories'][0]['link'] );
	assert_same( '', $data['videos'][0]['link'] );
	assert_same( '', $data['videos'][0]['watch'], 'no Watch page set up: no page of our own' );
}

function test_catalog_get_video_url() {
	ftvs_t_demo();
	assert_same( FTVS_Demo_Client::STREAM, FTVS_Catalog::get_video_url( 'demo-hope-rising-1' ) );
	assert_wp_error( FTVS_Catalog::get_video_url( 'demo-nope-1' ), 'ftvs_gone' );
	assert_wp_error( FTVS_Catalog::get_video( 'Not A Demo Id' ), 'ftvs_bad_id' );

	ftvs_t_settings( array( 'source' => 'youtube', 'yt_channel' => '@A' ) );
	assert_wp_error( FTVS_Catalog::get_video_url( FTVS_T_VIDEO_A ), 'ftvs_no_stream', 'YouTube plays in its own player, not over HLS' );
}

function test_catalog_get_video_when_nothing_is_connected() {
	assert_wp_error( FTVS_Catalog::get_video( 'anything' ), 'ftvs_not_connected' );
}

function test_catalog_changed_reports_only_videos_that_are_new() {
	ftvs_t_faithstream();
	$new = array();
	ftvs_t_add_filter(
		'ftvs_new_videos',
		function ( $videos ) use ( &$new ) {
			$new[] = wp_list_pluck( $videos, 'id' );
		}
	);
	$old  = array(
		'categories' => array(),
		'videos'     => ftvs_t_videos( 'v', 1, 3 ),
	);
	$data = array(
		'categories' => array(),
		'videos'     => ftvs_t_videos( 'v', 1, 5 ),
	);
	FTVS_Catalog::on_changed( 'c_series', $data, $old );
	assert_same( array( array( 'v-4', 'v-5' ) ), $new );
	assert_true( (bool) wp_next_scheduled( FTVS_Purge::CRON ), 'page caches are asked to clear' );

	FTVS_Catalog::on_changed( 'c_series', $data, null );
	assert_count( 1, $new, 'the first ever fetch is not "new videos"' );
	FTVS_Catalog::on_changed( 'v_v-4', $data, $old );
	assert_count( 1, $new, 'a single video\'s stream address changing is not news' );
	FTVS_Catalog::on_changed( 'c_series', $old, $data );
	assert_count( 1, $new, 'videos going away are not "new"' );
}

function test_catalog_changed_understands_home_and_library_answers() {
	ftvs_t_faithstream();
	$new = array();
	ftvs_t_add_filter(
		'ftvs_new_videos',
		function ( $videos ) use ( &$new ) {
			$new[] = wp_list_pluck( $videos, 'id' );
		}
	);
	$row = function ( $from, $to ) {
		return array(
			'rows' => array(
				array(
					'category' => array( 'id' => 'r' ),
					'videos'   => ftvs_t_videos( 'h', $from, $to ),
				),
			),
		);
	};
	FTVS_Catalog::on_changed( 'home', $row( 1, 2 ), $row( 1, 1 ) );
	assert_same( array( array( 'h-2' ) ), $new, 'the home rows' );

	FTVS_Catalog::on_changed( 'library', ftvs_t_videos( 'l', 1, 3 ), ftvs_t_videos( 'l', 1, 2 ) );
	assert_same( array( 'h-2' ), $new[0] );
	assert_same( array( 'l-3' ), $new[1], 'and the library, which is a plain list' );
}

/** Playing the church's live stream changes its answer all the time (viewers, status): that is not a catalog change. */
function test_catalog_a_live_channel_update_does_not_clear_the_page_caches() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/live',
		ftvs_t_sequence(
			array(
				ftvs_t_json( array( ftvs_t_live_channel( array( 'viewers' => 10 ) ) ) ),
				ftvs_t_json( array( ftvs_t_live_channel( array( 'viewers' => 25 ) ) ) ),
			)
		)
	);
	wp_clear_scheduled_hook( FTVS_Purge::CRON );
	$job = array( 'FTVS_FaithStream_Client', 'fetch_live', array() );
	assert_same( 10, FTVS_Cache::refresh( 'live', FTVS_FaithStream_Client::LIVE_TTL, $job )[0]['viewers'] );
	assert_same( 25, FTVS_Cache::refresh( 'live', FTVS_FaithStream_Client::LIVE_TTL, $job )[0]['viewers'], 'the second answer differs from the saved one' );
	assert_false( wp_next_scheduled( FTVS_Purge::CRON ), 'a changing viewer count must not purge every page cache on the site' );
}

function test_catalog_new_videos_reach_the_churchs_webhook() {
	ftvs_t_faithstream( array( 'new_webhook' => 'https://hooks.example.test/new' ) );
	ftvs_t_route( 'https://hooks.example.test/new', ftvs_t_json( array( 'ok' => true ) ) );
	FTVS_Catalog::on_changed(
		'c_series',
		array(
			'categories' => array(),
			'videos'     => ftvs_t_videos( 'v', 1, 2 ),
		),
		array(
			'categories' => array(),
			'videos'     => ftvs_t_videos( 'v', 1, 1 ),
		)
	);
	$requests = ftvs_t_requests( 'hooks.example.test' );
	assert_count( 1, $requests );
	$sent = json_decode( $requests[0]['args']['body'], true );
	assert_same( 'new_videos', $sent['event'] );
	assert_same( 'faith-tv-website', $sent['source'] );
	assert_same( array( 'v-2' ), wp_list_pluck( $sent['videos'], 'id' ) );
	assert_contains( '/watch/v-2?tenant=', $sent['videos'][0]['url'] );
}

function test_catalog_new_videos_are_not_announced_for_sample_videos() {
	ftvs_t_demo( array( 'new_webhook' => 'https://hooks.example.test/new' ) );
	do_action( 'ftvs_new_videos', array( array( 'id' => 'x', 'title' => 'x', 'speaker' => '', 'image' => '', 'added' => '', 'parent' => '' ) ) );
	assert_count( 0, ftvs_t_requests(), 'sample videos never go to the church\'s follow-up system' );
}

function test_catalog_changed_never_announces_a_video_seen_here_before() {
	ftvs_t_faithstream();
	$new = array();
	ftvs_t_add_filter(
		'ftvs_new_videos',
		function ( $videos ) use ( &$new ) {
			$new[] = wp_list_pluck( $videos, 'id' );
		}
	);
	// All six were listed once (say the library), then one answer came back short.
	ftvs_t_private( 'FTVS_Catalog', 'learn', array( '', array( 'categories' => array(), 'videos' => ftvs_t_videos( 'seen', 1, 6 ) ) ) );
	FTVS_Catalog::on_changed( 'c_series', array( 'categories' => array(), 'videos' => ftvs_t_videos( 'seen', 1, 7 ) ), array( 'categories' => array(), 'videos' => ftvs_t_videos( 'seen', 1, 2 ) ) );
	assert_same( array( array( 'seen-7' ) ), $new, 'only the video never seen before is new' );
}

function test_catalog_gone_clears_page_caches_only_for_something_known() {
	ftvs_t_faithstream();
	wp_clear_scheduled_hook( FTVS_Purge::CRON );
	FTVS_Catalog::on_gone( 'c_never-listed-here' );
	assert_false( wp_next_scheduled( FTVS_Purge::CRON ), 'a page that points at an old category must not purge every hour' );
	ftvs_t_private( 'FTVS_Catalog', 'learn', array( 'was-listed', array( 'categories' => array(), 'videos' => array() ) ) );
	FTVS_Catalog::on_gone( 'c_was-listed' );
	assert_true( (bool) wp_next_scheduled( FTVS_Purge::CRON ) );
	wp_clear_scheduled_hook( FTVS_Purge::CRON );
}
