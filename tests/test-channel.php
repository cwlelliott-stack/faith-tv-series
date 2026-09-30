<?php
/**
 * FTVS_Channel: the whole channel on one page (home, series, groups of series, one video, search, live),
 * its addresses, the row layouts, the REST view endpoint and the Channel page settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------- fixtures */

/** A Faith Stream home: a pinned banner row, a Featured slider, a row of series, a row of videos. */
function ftvs_t_ch_fs_home() {
	$v = function ( $slug, $title, $at = '2026-09-01T10:00:00Z' ) {
		return array(
			'id'            => 'id-' . $slug,
			'slug'          => $slug,
			'title'         => $title,
			'thumbnail_url' => '/media/v/' . $slug . '.jpg',
			'duration_s'    => 65.5,
			'published_at'  => $at,
			'speaker'       => '',
		);
	};
	$c = function ( $slug, $name, $count, $desc = '' ) {
		return array(
			'id'            => 'c-' . $slug,
			'slug'          => $slug,
			'name'          => $name,
			'kind'          => 'category',
			'thumbnail_url' => '/media/c/' . $slug . '.jpg',
			'description'   => $desc,
			'video_count'   => $count,
		);
	};
	return array(
		'hero'        => $v( 'welcome-to-faith-tv', 'Welcome to Faith TV' ),
		'hero_pinned' => true,
		'live'        => null,
		'newest'      => $v( 'outrageous-part-4', 'Outrageous Part 4', '2026-09-27T16:56:20Z' ),
		'rows'        => array(
			array(
				'category' => $c( 'welcome', 'Welcome', 1 ),
				'style'    => 'hero',
				'videos'   => array( $v( 'welcome-to-faith-tv', 'Welcome to Faith TV' ) ),
				'children' => array(),
			),
			array(
				'category' => $c( 'featured', 'Featured', 0 ),
				'style'    => 'slider',
				'videos'   => array( $v( 'outrageous-part-4', 'Outrageous Part 4' ) ),
				'children' => array( $c( 'long-game', 'Long Game', 3, 'Our faith in God is a long game.' ), $c( 'kids-rock-show', 'That Kids Rock Show', 10, 'Fun for the family.' ) ),
			),
			array(
				'category' => $c( 'kids-rock', 'Kids Rock', 0 ),
				'style'    => 'tiles',
				'videos'   => array( $v( 'luke-10-19', 'Luke 10:19' ) ),
				'children' => array( $c( 'action-songs', 'Action Songs', 38 ), $c( 'game-time', 'It\'s Game Time!', 50 ) ),
			),
			array(
				'category' => $c( 'faith-stories', 'Faith Stories', 2 ),
				'style'    => 'videos',
				'videos'   => array( $v( 'a-place-to-grow', 'A Place to Grow' ), $v( 'made-new', 'Made New' ) ),
				'children' => array(),
			),
		),
		'continue_watching' => array(),
	);
}

function ftvs_t_ch_fs( $extra = array() ) {
	$tenant = ftvs_t_faithstream( $extra );
	ftvs_t_route( '/api/public/home', ftvs_t_json( ftvs_t_ch_fs_home() ) );
	ftvs_t_route( '/api/public/live', ftvs_t_json( array() ) );
	return $tenant;
}

/** Gideo home rows: Welcome (one video), Featured (series), Kids Rock (series), Faith Stories (videos). */
function ftvs_t_ch_gideo() {
	ftvs_t_gideo();
	$cat = function ( $id, $title, $videos, $subs, $desc = '' ) {
		return '<category id="' . $id . '"><title>' . $title . '</title><description>' . $desc . '</description><image>https://cdn.gideo.video/img/' . $id . '.jpg</image><videos>' . $videos . '</videos><subcategories>' . $subs . '</subcategories></category>';
	};
	$vid = function ( $id, $parent, $title, $added ) {
		return '<video id="' . $id . '"><type>video</type><parent>' . $parent . '</parent><title>' . $title . '</title><image>https://cdn.gideo.video/img/' . $id . '.jpg</image><length>1800</length><added>' . $added . '</added></video>';
	};
	$welcome = str_repeat( '1', 32 );
	$feat    = str_repeat( '2', 32 );
	$kids    = str_repeat( '3', 32 );
	$stories = str_repeat( '4', 32 );
	$show    = str_repeat( '5', 32 );
	$season  = str_repeat( '6', 32 );
	$songs   = str_repeat( '7', 32 );
	$answers = array(
		''       => $cat( $welcome, 'Welcome', 1, 0 ) . $cat( $feat, 'Featured', 0, 1 ) . $cat( $kids, 'Kids Rock', 0, 2 ) . $cat( $stories, 'Faith Stories', 2, 0 ),
		$welcome => $vid( 'aa01', $welcome, 'Welcome to Faith TV', '2023-10-13 11:37:53' ),
		$feat    => $cat( $show, 'That Kids Rock Show', 0, 1, 'Fun for the family.' ),
		$kids    => $cat( $show, 'That Kids Rock Show', 0, 1 ) . $cat( $songs, 'Action Songs', 2, 0 ),
		$stories => $vid( 'bb01', $stories, 'A Place to Grow', '2026-08-10 07:36:37' ) . $vid( 'bb02', $stories, 'Made New', '2026-07-01 10:00:00' ),
		$show    => $cat( $season, 'Season 5', 2, 0 ),
		$season  => $vid( 'cc01', $season, 'Season 5 Episode 1', '2026-06-01 10:00:00' ) . $vid( 'cc02', $season, 'Season 5 Episode 2', '2026-06-08 10:00:00' ),
		$songs   => $vid( 'dd01', $songs, 'Jesus Loves Me', '2026-05-01 10:00:00' ) . $vid( 'dd02', $songs, 'This Little Light', '2026-05-02 10:00:00' ),
	);
	ftvs_t_route(
		'cmd=getCategoryChildren',
		function ( $url ) use ( $answers ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$id = isset( $q['CategoryID'] ) ? (string) $q['CategoryID'] : '';
			return isset( $answers[ $id ] ) ? ftvs_t_gideo_xml( $answers[ $id ] ) : ftvs_t_text( 'not found', 404 );
		}
	);
	ftvs_t_route( 'cmd=getVideoUrls', ftvs_t_json( array( 'urls' => array( array( 'streamFormat' => 'hls', 'url' => 'https://cdn.gideo.video/x/hls/master.m3u8' ) ) ) ) );
	return compact( 'welcome', 'feat', 'kids', 'stories', 'show', 'season', 'songs' );
}

/** A Faith Stream category page with the name and description the home rows gave it. */
function ftvs_t_ch_series_page( $slug, $name, $videos, $total, $extra = array() ) {
	$page                            = ftvs_t_fs_category_page( $slug, $videos, $total, $extra );
	$page['category']['name']        = $name;
	$page['category']['description'] = 'long-game' === $slug ? 'Our faith in God is a long game.' : '';
	return $page;
}

/** No page: links are relative (the channel is on whatever page shows it). */
function ftvs_t_ch_ctx() {
	return FTVS_Channel::context( 0 );
}

/** The channel on the Watch page with tidy addresses. */
function ftvs_t_ch_watch_ctx( $extra = array() ) {
	$page = ftvs_t_page();
	ftvs_t_settings( array_merge( $GLOBALS['ftvs_t_settings'], array( 'watch_page_id' => $page->ID ), $extra ) );
	ftvs_t_permalinks( '/%postname%/' );
	return FTVS_Channel::context( $page->ID );
}

function ftvs_t_ch_view( $view, $id = '', $in = '', $q = '', $ctx = null ) {
	return FTVS_Channel::view( FTVS_Channel::route( $view, $id, $in, $q ), null === $ctx ? ftvs_t_ch_ctx() : $ctx );
}

/* ---------------------------------------------------------------- routes and addresses */

function test_channel_route_reads_only_known_views() {
	ftvs_t_faithstream();
	assert_same( 'home', FTVS_Channel::route( 'admin' )['v'], 'anything unknown is home' );
	assert_same( 'home', FTVS_Channel::route( 'series', '../etc' )['v'], 'a bad id is home' );
	assert_same( 'home', FTVS_Channel::route( 'video', '' )['v'] );
	$r = FTVS_Channel::route( 'video', 'part-2', 'long-game' );
	assert_same( array( 'v' => 'video', 'id' => 'part-2', 'in' => 'long-game', 'q' => '' ), $r );
	assert_same( '', FTVS_Channel::route( 'video', 'part-2', 'x y' )['in'], 'a bad series id is dropped, the video stays' );
	assert_same( '', FTVS_Channel::route( 'series', 'long-game', 'other' )['in'], 'only videos carry a series' );
	assert_same( 'now', FTVS_Channel::route( 'live' )['id'], 'no channel: whichever is live' );
	assert_same( 'sunday-service', FTVS_Channel::route( 'live', 'Sunday-Service!' )['id'] );
	$long = FTVS_Channel::route( 'search', '', '', '  ' . str_repeat( 'a', 150 ) . ' ' );
	assert_same( 100, strlen( $long['q'] ), 'search words are trimmed and capped' );
	assert_same( 'kids rock', FTVS_Channel::route( 'search', '', '', '<b>kids</b> rock' )['q'], 'tags are stripped' );
}

function test_channel_route_lowercases_gideo_ids() {
	ftvs_t_gideo();
	assert_same( str_repeat( 'a', 32 ), FTVS_Channel::route( 'series', str_repeat( 'A', 32 ) )['id'] );
}

function test_channel_links_on_the_watch_page_are_tidy() {
	ftvs_t_faithstream();
	$ctx  = ftvs_t_ch_watch_ctx();
	$uri  = get_page_uri( $ctx['page'] );
	$base = home_url( user_trailingslashit( $uri ) ); // the way message pages are addressed (FTVS_Watch::url)
	assert_true( $ctx['watch'] && $ctx['pretty'] );
	assert_same( $base, FTVS_Channel::link( 'home', '', '', $ctx ) );
	assert_same( home_url( user_trailingslashit( $uri . '/series/long-game' ) ), FTVS_Channel::link( 'series', 'long-game', '', $ctx ) );
	assert_not_contains( '?', FTVS_Channel::link( 'series', 'long-game', '', $ctx ), 'never a tidy path glued to ?page_id=' );
	assert_same( FTVS_Watch::url( 'part-2' ), FTVS_Channel::link( 'video', 'part-2', '', $ctx ), 'a message is its own page' );
	assert_same( add_query_arg( 'ftvs_series', 'long-game', FTVS_Watch::url( 'part-2' ) ), FTVS_Channel::link( 'video', 'part-2', 'long-game', $ctx ) );
	assert_same( home_url( user_trailingslashit( $uri . '/live/now' ) ), FTVS_Channel::link( 'live', 'now', '', $ctx ) );
	assert_same( add_query_arg( 'ftvs_q', 'kids%20rock', $base ), FTVS_Channel::link( 'search', '', '', $ctx, 'kids rock' ) );
}

function test_channel_links_elsewhere_use_the_address_query() {
	ftvs_t_faithstream();
	$ctx = ftvs_t_ch_ctx();
	assert_false( $ctx['pretty'] );
	assert_same( '?ftvs_series=long-game', FTVS_Channel::link( 'series', 'long-game', '', $ctx ) );
	assert_same( '?ftvs_video=part-2&ftvs_series=long-game', FTVS_Channel::link( 'video', 'part-2', 'long-game', $ctx ) );
	assert_same( '?ftvs_live=now', FTVS_Channel::link( 'live', 'now', '', $ctx ) );
	$page = ftvs_t_page();
	ftvs_t_settings( array_merge( $GLOBALS['ftvs_t_settings'], array( 'watch_page_id' => $page->ID ) ) );
	ftvs_t_permalinks( '' );
	$ctx = FTVS_Channel::context( $page->ID );
	assert_true( $ctx['watch'] );
	assert_false( $ctx['pretty'], 'plain permalinks: no tidy addresses, even on the Watch page' );
	assert_same( add_query_arg( 'ftvs_series', 'long-game', get_permalink( $page ) ), FTVS_Channel::link( 'series', 'long-game', '', $ctx ) );
	assert_same( FTVS_Watch::url( 'part-2' ), FTVS_Channel::link( 'video', 'part-2', '', $ctx ), 'messages keep their message page (?page_id=..&ftvs_video=..)' );
}

function test_channel_context_ignores_pages_visitors_cannot_see() {
	$draft = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => 'Channel draft (test)',
		)
	);
	ftvs_t_cleanup(
		function () use ( $draft ) {
			wp_delete_post( $draft, true );
		}
	);
	assert_same( 0, FTVS_Channel::context( $draft )['page'], 'a visitor asking for a draft gets relative links' );
	ftvs_t_admin();
	assert_same( $draft, FTVS_Channel::context( $draft )['page'], 'an editor previewing it gets its own' );
	assert_same( 0, FTVS_Channel::context( 999999999 )['page'] );
}

function test_channel_request_variables_are_registered() {
	$vars = FTVS_Channel::query_vars( array() );
	assert_in_array( 'ftvs_series', $vars );
	assert_in_array( 'ftvs_live', $vars );
	assert_true( shortcode_exists( 'faith_tv_channel' ) );
	assert_true( class_exists( 'WP_Block_Type_Registry' ) && WP_Block_Type_Registry::get_instance()->is_registered( 'faith-tv/channel' ), 'the block' );
}

function test_channel_page_detection() {
	$make = function ( $content, $meta = null ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Channel detection (test)',
				'post_content' => $content,
			)
		);
		if ( null !== $meta ) {
			update_post_meta( $id, '_elementor_data', $meta );
		}
		ftvs_t_cleanup(
			function () use ( $id ) {
				wp_delete_post( $id, true );
			}
		);
		return $id;
	};
	assert_true( FTVS_Channel::page_has_channel( $make( 'Hello [faith_tv_channel] there' ) ), 'shortcode' );
	assert_true( FTVS_Channel::page_has_channel( $make( '<!-- wp:faith-tv/channel {"align":"full"} /-->' ) ), 'block' );
	assert_true( FTVS_Channel::page_has_channel( $make( '', '[{"elements":[{"widgetType":"faith_tv_channel"}]}]' ) ), 'Elementor widget' );
	assert_false( FTVS_Channel::page_has_channel( $make( '[faith_tv_series category="x"]' ) ), 'another section' );
	assert_false( FTVS_Channel::page_has_channel( 0 ) );
}

/* ---------------------------------------------------------------- home rows */

function test_channel_home_rows_follow_faith_stream_layouts() {
	ftvs_t_ch_fs();
	$rows = FTVS_Channel::home_rows();
	assert_not_error( $rows );
	assert_same( array( 'hero', 'slider', 'tiles', 'videos' ), wp_list_pluck( $rows, 'style' ) );
	assert_same( array( 'hero', 'slider', 'tiles', 'videos' ), wp_list_pluck( $rows, 'auto' ) );
	assert_same( array( 'long-game', 'kids-rock-show' ), wp_list_pluck( $rows[1]['categories'], 'id' ) );
}

function test_channel_home_rows_can_be_changed_or_hidden() {
	ftvs_t_ch_fs(
		array(
			'channel_rows' => array(
				'kids-rock'     => 'videos',
				'faith-stories' => 'hide',
				'featured'      => 'auto',
				'welcome'       => 'bogus',
			),
		)
	);
	$rows = FTVS_Channel::home_rows();
	assert_same( array( 'hero', 'slider', 'videos', 'hide' ), wp_list_pluck( $rows, 'style' ), 'auto and unknown values keep the channel\'s own' );
	assert_same( 'tiles', $rows[2]['auto'], 'what automatic would be, for the admin screen' );
}

function test_channel_home_rows_guess_gideo_layouts_like_the_tv_site() {
	ftvs_t_ch_gideo();
	$rows = FTVS_Channel::home_rows();
	assert_not_error( $rows );
	assert_same( array( 'Welcome', 'Featured', 'Kids Rock', 'Faith Stories' ), wp_list_pluck( $rows, 'title' ) );
	assert_same( array( 'hero', 'slider', 'tiles', 'videos' ), wp_list_pluck( $rows, 'style' ), 'first row of videos = banner, "Featured" = slider, series = tiles' );
}

function test_channel_home_view_faith_stream() {
	ftvs_t_ch_fs();
	$view = ftvs_t_ch_view( 'home' );
	$html = $view['html'];
	assert_true( $view['found'] );
	assert_contains( 'class="ftvc-hero"', $html );
	assert_contains( 'Welcome to Faith TV</h2>', $html, 'the pinned banner' );
	assert_contains( 'data-ftvc="video|welcome-to-faith-tv|welcome"', $html, 'its Watch now button plays inside the channel' );
	assert_contains( '>1m</p>', $html, 'a pinned banner shows its length the TV site\'s way' );
	assert_not_contains( '>Welcome</a></h3>', $html, 'the banner\'s row is not repeated below it' );
	assert_contains( 'data-style="slider"', $html );
	assert_contains( 'Our faith in God is a long game.', $html, 'Featured slides carry their description' );
	assert_contains( 'data-ftvc="series|long-game|"', $html );
	assert_contains( 'It&#039;s Game Time!', $html, 'names are escaped' );
	assert_contains( 'data-ftvc="video|a-place-to-grow|faith-stories"', $html, 'a video row plays in its own row\'s series' );
	assert_contains( 'data-ftvc-home data-live="0"', $html );
	assert_same( FTVS_T_FS . '/media/v/welcome-to-faith-tv.jpg', $view['backdrop'] );
}

function test_channel_home_view_without_a_pinned_banner_shows_the_newest_message() {
	ftvs_t_ch_fs( array( 'channel_rows' => array( 'welcome' => 'videos' ) ) );
	$html = ftvs_t_ch_view( 'home' )['html'];
	assert_contains( 'Latest message', $html );
	assert_contains( 'Outrageous Part 4</h2>', $html, 'Faith Stream\'s newest' );
	assert_contains( '>Welcome</a></h3>', $html, 'the Welcome row is an ordinary row now' );
}

function test_channel_home_view_hides_hidden_rows_everywhere() {
	ftvs_t_ch_fs( array( 'channel_rows' => array( 'kids-rock' => 'hide' ) ) );
	$html = FTVS_Channel::render();
	assert_not_contains( 'data-ftvc="series|kids-rock|"', $html, 'not a row, and not in the menu' );
	assert_not_contains( 'Action Songs', $html, 'nor its series' );
	assert_contains( 'data-ftvc="series|faith-stories|"', $html );
}

function test_channel_home_view_gideo() {
	$ids  = ftvs_t_ch_gideo();
	$view = ftvs_t_ch_view( 'home' );
	$html = $view['html'];
	assert_contains( 'Welcome to Faith TV</h2>', $html, 'the Welcome row\'s video is the banner' );
	assert_contains( 'data-ftvc="video|aa01|' . $ids['welcome'] . '"', $html );
	assert_contains( 'Fun for the family.', $html, 'the slider shows descriptions' );
	assert_contains( 'data-ftvc="video|bb01|' . $ids['stories'] . '"', $html );
	assert_same( array(), ftvs_t_requests( 'CategoryID=' . $ids['show'] ), 'series below the rows are not asked for on the home view' );
}

function test_channel_home_view_says_so_when_the_platform_is_down() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/', ftvs_t_neterr() );
	$view = ftvs_t_ch_view( 'home' );
	assert_contains( 'aren&#039;t loading right now', $view['html'] );
	assert_not_contains( 'Could not resolve host', $view['html'], 'visitors never see the technical reason' );
	ftvs_t_admin();
	assert_contains( 'Could not resolve host', ftvs_t_ch_view( 'home' )['html'], 'editors do' );
}

/* ---------------------------------------------------------------- series and groups */

function test_channel_series_view_lists_episodes_with_a_play_button() {
	ftvs_t_ch_fs();
	FTVS_Channel::home_rows(); // the site has seen the channel's rows (what makes a series known)
	ftvs_t_route( 'categories/long-game?', ftvs_t_json( ftvs_t_ch_series_page( 'long-game', 'Long Game', ftvs_t_fs_videos( 'ep', 1, 3 ), 3, array( 'breadcrumbs' => array( array( 'slug' => 'featured', 'name' => 'Featured' ), array( 'slug' => 'long-game', 'name' => 'Long Game' ) ) ) ) ) );
	$view = ftvs_t_ch_view( 'series', 'long-game' );
	$html = $view['html'];
	assert_true( $view['found'] );
	assert_same( 'Long Game', $view['title'] );
	assert_contains( 'Our faith in God is a long game.', $html, 'the description the channel gave on the home row' );
	assert_contains( 'Play from the start', $html );
	assert_contains( 'data-ftvc="video|ep-1|long-game"', $html );
	assert_same( 3, substr_count( $html, 'class="ftvc-card"' ) );
	assert_contains( 'data-ftvc="series|featured|"', $html, 'breadcrumbs from Faith Stream' );
	assert_contains( 'data-ftvc="series|featured|" data-ftvc-back', $html, 'Back goes to the series above' );
}

function test_channel_series_view_of_an_unknown_id_is_not_found_and_asks_nobody() {
	ftvs_t_ch_fs();
	$view = ftvs_t_ch_view( 'series', 'made-up-series' );
	assert_false( $view['found'] );
	assert_contains( 'We couldn&#039;t find that', $view['html'] );
	assert_same( array(), ftvs_t_requests( 'made-up-series' ), 'made-up ids never reach the platform' );
}

function test_channel_group_view_on_faith_stream_uses_the_sections_it_sends() {
	ftvs_t_ch_fs();
	FTVS_Channel::home_rows();
	$page = ftvs_t_fs_category_page(
		'kids-rock',
		ftvs_t_fs_videos( 'kr', 1, 4 ),
		4,
		array(
			'has_own_videos' => false,
			'children'       => array(
				array( 'slug' => 'action-songs', 'name' => 'Action Songs', 'video_count' => 2 ),
				array( 'slug' => 'game-time', 'name' => 'Game Time', 'video_count' => 2 ),
			),
			'sections'       => array(
				array(
					'category' => array( 'slug' => 'action-songs', 'name' => 'Action Songs' ),
					'videos'   => ftvs_t_fs_videos( 'song', 1, 2 ),
					'children' => array(),
				),
				array(
					'category' => array( 'slug' => 'game-time', 'name' => 'Game Time' ),
					'videos'   => ftvs_t_fs_videos( 'game', 1, 2 ),
					'children' => array(),
				),
			),
		)
	);
	ftvs_t_route( 'categories/kids-rock?', ftvs_t_json( $page ) );
	$html = ftvs_t_ch_view( 'series', 'kids-rock' )['html'];
	assert_contains( '>Action Songs</a></h3>', $html, 'one row per series' );
	assert_contains( '>Game Time</a></h3>', $html );
	assert_contains( 'data-ftvc="video|song-1|action-songs"', $html, 'each row plays in its own series' );
	assert_not_contains( 'data-ftvc="video|kr-1', $html, 'the group\'s own list (everything below it) is not shown twice' );
	assert_contains( '2 series', $html );
	assert_same( array(), ftvs_t_requests( 'categories/action-songs' ), 'no request per series: Faith Stream sent them' );
}

function test_channel_group_view_on_gideo_lists_each_series_and_seasons() {
	$ids = ftvs_t_ch_gideo();
	FTVS_Channel::home_rows();
	$html = ftvs_t_ch_view( 'series', $ids['kids'] )['html'];
	assert_contains( '>That Kids Rock Show</a></h3>', $html );
	assert_contains( '>Action Songs</a></h3>', $html );
	assert_contains( 'data-ftvc="series|' . $ids['season'] . '|"', $html, 'a series of seasons shows its seasons as tiles' );
	assert_contains( 'data-ftvc="video|dd01|' . $ids['songs'] . '"', $html );

	// A season, two levels below the rows: the site learned it from its series' listing.
	$season = ftvs_t_ch_view( 'series', $ids['season'] );
	assert_true( $season['found'] );
	assert_same( 'Season 5', $season['title'] );
	assert_contains( 'data-ftvc="series|' . $ids['show'] . '|" data-ftvc-back', $season['html'], 'Back goes to the show' );
	assert_contains( '>Kids Rock</a></li>', $season['html'], 'breadcrumbs from what the site has seen' );
	assert_contains( '>That Kids Rock Show</a></li>', $season['html'] );
}

/* ---------------------------------------------------------------- one video */

function test_channel_video_view_plays_in_its_series() {
	$ids = ftvs_t_ch_gideo();
	FTVS_Channel::home_rows();
	ftvs_t_ch_view( 'series', $ids['kids'] );
	ftvs_t_ch_view( 'series', $ids['show'] );
	$view = ftvs_t_ch_view( 'video', 'cc01', $ids['season'] );
	$html = $view['html'];
	assert_true( $view['found'], 'a video inside a season (deeper than the library) still has a page' );
	assert_same( 'Season 5 Episode 1', $view['title'] );
	assert_contains( 'More from Season 5', $html );
	assert_contains( 'aria-current="true"', $html, 'the playing episode is marked' );
	assert_contains( 'data-ftvc-start', $html, 'the play button' );
	assert_contains( '<video class="ftvc-stage__video" playsinline controls preload="none"', $html, 'nothing loads until someone presses play' );
	preg_match( '/data-ftvc-eps="([^"]*)"/', $html, $m );
	$eps = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	assert_same( array( 'cc01', 'cc02' ), wp_list_pluck( $eps, 'id' ), 'the episode list for "Up next"' );
	preg_match( '/data-ftvc-video="([^"]*)"/', $html, $m );
	$item = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	assert_same( 'cc01', $item['id'] );
	assert_same( $ids['season'], $item['parent'], 'progress is saved under the series it was watched in' );
}

function test_channel_video_view_of_an_unknown_video_is_not_found() {
	ftvs_t_ch_fs();
	$view = ftvs_t_ch_view( 'video', 'never-published' );
	assert_false( $view['found'] );
	assert_same( array(), ftvs_t_requests( 'videos/never-published' ) );
}

function test_channel_video_view_escapes_what_the_platform_sends() {
	ftvs_t_ch_fs();
	FTVS_Channel::home_rows();
	$videos             = ftvs_t_fs_videos( 'x', 1, 2 );
	$videos[0]['title'] = '<script>alert(1)</script> & more';
	ftvs_t_route( 'categories/faith-stories?', ftvs_t_json( ftvs_t_fs_category_page( 'faith-stories', $videos, 2 ) ) );
	ftvs_t_route( 'videos/x-1', ftvs_t_json( array( 'video' => $videos[0] + array( 'description' => '<img src=x onerror=alert(1)>', 'hls_url' => 'https://stream.mux.com/a.m3u8' ), 'categories' => array(), 'related' => array() ) ) );
	FTVS_Catalog::get_children( 'faith-stories' );
	$html = ftvs_t_ch_view( 'video', 'x-1', 'faith-stories' )['html'];
	assert_not_contains( '<script>alert', $html );
	assert_not_contains( '<img src=x', $html );
	assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; more', $html );
}

/* ---------------------------------------------------------------- search and live */

function test_channel_search_view_needs_two_letters_and_finds_series_too() {
	ftvs_t_ch_fs();
	$none = ftvs_t_ch_view( 'search', '', '', 'k' );
	assert_contains( 'Type at least two letters.', $none['html'] );
	ftvs_t_route( '/api/public/search', ftvs_t_json( array( 'videos' => array(), 'categories' => array() ) ) );
	ftvs_t_route( '/api/public/categories/', ftvs_t_json( ftvs_t_fs_category_page( 'x', array(), 0 ) ) );
	$html = ftvs_t_ch_view( 'search', '', '', 'game' )['html'];
	assert_contains( 'Results for &quot;game&quot;', $html );
	assert_contains( 'data-ftvc="series|long-game|"', $html, 'a series whose name matches' );
}

function test_channel_live_view_when_nothing_is_live() {
	ftvs_t_ch_fs();
	$view = ftvs_t_ch_view( 'live', 'now' );
	assert_contains( 'We are not live right now', $view['html'] );
	assert_contains( '&quot;on&quot;:false', $view['html'] );
}

function test_channel_home_view_turns_into_live_during_a_service_with_a_pasted_link() {
	ftvs_t_ch_gideo();
	$now = time();
	ftvs_t_timezone( 'UTC' );
	ftvs_t_settings(
		array_merge(
			$GLOBALS['ftvs_t_settings'],
			array(
				'live_url'       => 'https://www.youtube.com/watch?v=' . FTVS_T_VIDEO_A,
				'services'       => array( array( 'day' => (int) gmdate( 'w', $now ), 'time' => gmdate( 'H:i', $now - 60 ) ) ),
				'service_length' => 90,
			)
		)
	);
	$html = ftvs_t_ch_view( 'home' )['html'];
	assert_contains( 'ftvc-hero--live', $html );
	assert_contains( 'data-ftvc="live|now|"', $html );
	assert_contains( 'data-live="1"', $html );
	assert_contains( '>Welcome</a></h3>', $html, 'the banner\'s row shows as a row while live' );
}

/* ---------------------------------------------------------------- the shell, REST, settings */

function test_channel_render_prints_the_bar_and_the_view_for_the_address() {
	ftvs_t_ch_fs( array( 'channel_name' => 'Faith TV' ) );
	$html = FTVS_Channel::render();
	assert_contains( 'data-ftvs-channel', $html );
	assert_contains( '<b>Faith</b> TV', $html, 'two words: the last one white, like FAITH TV' );
	assert_contains( 'name="ftvs_q"', $html, 'search works without the page script too' );
	assert_contains( 'data-ftvc="series|faith-stories|">Faith Stories</a>', $html, 'the menu lists the rows' );
	assert_contains( 'data-state="{&quot;v&quot;:&quot;home&quot;', $html );
	assert_true( wp_style_is( 'faith-tv-channel', 'enqueued' ) );
	assert_true( wp_script_is( 'faith-tv-series', 'enqueued' ) );

	$_GET['ftvs_q'] = 'grace';
	ftvs_t_route( '/api/public/search', ftvs_t_json( array( 'videos' => array(), 'categories' => array() ) ) );
	ftvs_t_route( '/api/public/categories/', ftvs_t_json( ftvs_t_fs_category_page( 'x', array(), 0 ) ) );
	$search = FTVS_Channel::render();
	assert_contains( 'Results for &quot;grace&quot;', $search, 'the address asks for search' );
	assert_contains( 'value="grace"', $search );
}

function test_channel_render_logo_and_backdrop_settings() {
	ftvs_t_ch_fs( array( 'channel_logo' => 'https://img.example.test/logo.png', 'channel_backdrop' => 0 ) );
	$html = FTVS_Channel::render();
	assert_contains( '<img class="ftvc-mark__logo" src="https://img.example.test/logo.png"', $html );
	assert_not_contains( 'ftvc--backdrop', $html );
	assert_contains( 'ftvc--backdrop', FTVS_Channel::render( array( 'backdrop' => 'on' ) ), 'the block or widget can turn it on' );
}

function test_channel_render_sample_videos_only_for_editors() {
	ftvs_t_demo();
	assert_contains( 'shown only to editors', FTVS_Channel::render() );
}

function test_channel_rest_answers_a_view_with_its_address_and_title() {
	ftvs_t_ch_fs();
	FTVS_Channel::home_rows();
	ftvs_t_route( 'categories/long-game?', ftvs_t_json( ftvs_t_ch_series_page( 'long-game', 'Long Game', ftvs_t_fs_videos( 'ep', 1, 2 ), 2 ) ) );
	$request = new WP_REST_Request( 'GET', '/faith-tv/v1/channel' );
	$request->set_query_params( array( 'view' => 'series', 'id' => 'long-game' ) );
	$response = rest_do_request( $request );
	assert_same( 200, $response->get_status() );
	$data = $response->get_data();
	assert_contains( 'Long Game', $data['html'] );
	assert_same( 'Long Game', $data['title'] );
	assert_same( '?ftvs_series=long-game', $data['url'] );
	assert_same( array( 'v' => 'series', 'id' => 'long-game', 'in' => '', 'q' => '' ), $data['state'] );
	assert_contains( 'Long Game', $data['document'] );
	assert_true( $data['found'] );
}

function test_channel_rest_hides_sample_videos_from_visitors() {
	ftvs_t_demo();
	$request = new WP_REST_Request( 'GET', '/faith-tv/v1/channel' );
	assert_same( 404, rest_do_request( $request )->get_status() );
}

function test_channel_settings_keep_only_real_row_layouts() {
	$out = FTVS_Settings::sanitize(
		array(
			'channel_rows'     => array(
				'kids-rock'     => 'tiles',
				'welcome'       => 'auto',
				'__'            => '',
				'bad id'        => 'hide',
				'faith-stories' => 'carousel',
				'archive'       => 'hide',
			),
			'channel_name'     => ' <b>Faith</b> TV ',
			'channel_logo'     => 'javascript:alert(1)',
			'channel_backdrop' => '0',
		)
	);
	assert_same( array( 'kids-rock' => 'tiles', 'archive' => 'hide' ), $out['channel_rows'] );
	assert_same( 'Faith TV', $out['channel_name'] );
	assert_same( '', $out['channel_logo'] );
	assert_same( 0, $out['channel_backdrop'] );
	assert_same( 1, FTVS_Settings::defaults()['channel_backdrop'] );
}

function test_channel_faith_stream_listing_keeps_breadcrumbs_and_sections() {
	ftvs_t_faithstream();
	$page = ftvs_t_fs_category_page(
		'kids-rock',
		array(),
		0,
		array(
			'has_own_videos' => false,
			'breadcrumbs'    => array( array( 'slug' => 'kids-rock', 'name' => 'Kids Rock' ) ),
			'children'       => array( array( 'slug' => 'show', 'name' => 'Show' ) ),
			'sections'       => array(
				array(
					'category' => array( 'slug' => 'show', 'name' => 'Show', 'thumbnail_url' => null ),
					'videos'   => ftvs_t_fs_videos( 's', 1, 1 ),
					'children' => array( array( 'slug' => 'season-1', 'name' => 'Season 1' ) ),
				),
			),
		)
	);
	ftvs_t_route( 'categories/kids-rock?', ftvs_t_json( $page ) );
	$out = FTVS_FaithStream_Client::fetch_children( 'kids-rock' );
	assert_same( array( array( 'id' => 'kids-rock', 'title' => 'Kids Rock' ) ), $out['crumbs'] );
	assert_count( 1, $out['sections'] );
	assert_same( 'show', $out['sections'][0]['category']['id'] );
	assert_same( FTVS_T_FS . '/media/t/1.jpg', $out['sections'][0]['category']['image'], 'a series without a picture borrows its first episode\'s' );
	assert_same( array( 's-1' ), wp_list_pluck( $out['sections'][0]['videos'], 'id' ) );
	assert_same( array( 'season-1' ), wp_list_pluck( $out['sections'][0]['categories'], 'id' ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( ftvs_t_ch_fs_home() ) );
	FTVS_Catalog::get_tree();
	$rest = rest_do_request( new WP_REST_Request( 'GET', '/faith-tv/v1/category/kids-rock' ) );
	assert_false( isset( $rest->get_data()['sections'] ), 'the pop-up player does not get the group rows' );
}

/* ---------------------------------------------------------------- message pages on the channel's page */

function test_channel_message_pages_leave_the_page_to_the_channel() {
	$page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Channel Watch page (test)',
			'post_content' => '<!-- wp:faith-tv/channel {"align":"full"} /-->',
		)
	);
	ftvs_t_cleanup(
		function () use ( $page ) {
			wp_delete_post( $page, true );
		}
	);
	ftvs_t_faithstream( array( 'watch_page_id' => $page ) );
	ftvs_t_permalinks( '/%postname%/' );
	assert_true( FTVS_Channel::on_watch_page() );
	$ctx = FTVS_Channel::context( $page );
	assert_true( $ctx['watch'] && $ctx['pretty'] );
	assert_same( FTVS_Watch::url( 'part-2' ), FTVS_Channel::link( 'video', 'part-2', '', $ctx ), 'a message opened in the channel is the message\'s own page' );
	ftvs_t_watch_static( 'video', ftvs_t_watch_video(), null );
	assert_same( ftvs_t_watch_video()['id'], FTVS_Channel::resolve_video( ftvs_t_watch_video()['id'] )['id'], 'the message the Watch page found is the one the channel shows' );
	ftvs_t_faithstream( array( 'watch_page_id' => ftvs_t_page()->ID ) );
	assert_false( FTVS_Channel::on_watch_page(), 'a Watch page without the channel' );
}

function test_channel_unknown_series_is_not_guessed_into_another_page() {
	ftvs_t_add_filter(
		'pre_option_permalink_structure',
		function () {
			return '/%postname%/';
		}
	);
	global $wp_query;
	$saved = $wp_query->query_vars;
	ftvs_t_cleanup(
		function () use ( $saved ) {
			global $wp_query;
			$wp_query->query_vars = $saved;
			$wp_query->is_404     = false;
		}
	);
	$wp_query->set( 'ftvs_series', 'nope' );
	$wp_query->set_404();
	assert_false( FTVS_Watch::no_guessing( 'https://example.test/somewhere/' ) );
}

/* ---------------------------------------------------------------- Health knows the channel */

function test_channel_health_lists_the_channel_and_does_not_call_it_broken() {
	ftvs_t_ch_fs();
	// Health checks every section on the site (the test site has others): each category answers empty.
	ftvs_t_route( '/api/public/categories/', ftvs_t_json( ftvs_t_fs_category_page( 'x', array(), 0 ) ) );
	$page = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Channel health (test)',
			'post_content' => '<!-- wp:faith-tv/channel {"align":"full"} /-->' . "\n\n" . '[faith_tv_channel]',
		)
	);
	ftvs_t_cleanup(
		function () use ( $page ) {
			wp_delete_post( $page, true );
			delete_transient( FTVS_Health::USED );
		}
	);
	FTVS_Health::where_used( true );
	$mine = array_values(
		array_filter(
			FTVS_Health::check_used(),
			function ( $row ) use ( $page ) {
				return (int) $row['post'] === (int) $page;
			}
		)
	);
	assert_count( 2, $mine, 'the block and the shortcode' );
	foreach ( $mine as $row ) {
		assert_same( 'channel', $row['type'] );
		assert_same( '', $row['category'], 'no category to keep fresh' );
		assert_true( $row['ok'], 'a working channel is not a problem: ' . $row['problem'] );
	}
	$GLOBALS['ftvs_t_routes'] = array(); // Faith Stream stops answering...
	ftvs_t_faithstream(); // ...for a church this site has nothing saved for: the channel's rows can't be listed
	ftvs_t_route( '/api/public/', ftvs_t_neterr() );
	$broken = array_values(
		array_filter(
			FTVS_Health::check_used(),
			function ( $row ) use ( $page ) {
				return (int) $row['post'] === (int) $page;
			}
		)
	);
	assert_false( $broken[0]['ok'], 'a channel whose rows cannot be listed is a problem' );
}

/* ---------------------------------------------------------------- the page's name and the theme's title */

function test_channel_is_called_faith_tv_unless_named() {
	ftvs_t_ch_fs( array( 'church_name' => 'Faith Tabernacle' ) );
	assert_same( 'Faith TV', FTVS_Channel::name(), 'not the church\'s name: the channel is Faith TV' );
	assert_contains( '<b>Faith</b> TV', FTVS_Channel::render(), 'FAITH in the church color, TV in white' );
	ftvs_t_faithstream( array( 'channel_name' => 'Grace TV' ) );
	assert_same( 'Grace TV', FTVS_Channel::name() );
}

function test_channel_create_page_is_named_faith_tv() {
	ftvs_t_faithstream( array( 'watch_page_id' => ftvs_t_page()->ID ) ); // a Watch page already: nothing is saved
	ftvs_t_admin();
	$id = FTVS_Channel::create_page();
	assert_not_error( $id );
	ftvs_t_cleanup(
		function () use ( $id ) {
			wp_delete_post( $id, true );
		}
	);
	assert_same( 'Faith TV', get_the_title( $id ) );
	assert_same( 'draft', get_post_status( $id ) );
	assert_matches( '/^faith-tv(-\d+)?$/', get_post( $id )->post_name, 'at /faith-tv/ (or -2 when that address is taken)' );
	assert_true( FTVS_Channel::page_has_channel( $id ) );
	assert_contains( '"align":"full"', get_post_field( 'post_content', $id ) );
}

function test_channel_page_leaves_out_hello_elementors_page_title() {
	assert_true( FTVS_Channel::theme_title( true ), 'not on a channel page: the theme decides' );
	assert_true( has_filter( 'hello_elementor_page_title', array( 'FTVS_Channel', 'theme_title' ) ) > 0 );
}
