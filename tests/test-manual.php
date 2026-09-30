<?php
/**
 * FTVS_Manual: series a webmaster builds by hand (a title, a picture, a list of video links).
 *
 * The tests create one temporary "series" post (deleted when the test ends). They avoid the
 * calls that read the list of all published series (tree_row, library): that list is kept for
 * the whole request, so it must not be filled while a test post exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FTVS_T_YT_OEMBED = 'https://www.youtube.com/oembed';

/** A series post with its episodes already looked up (as save() leaves them). */
function ftvs_t_series( $status = 'publish', $episodes = null, $title = 'A test series' ) {
	$id = wp_insert_post(
		array(
			'post_type'    => FTVS_Manual::TYPE,
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => 'All <b>about</b> the series',
		)
	);
	assert_not_error( $id );
	ftvs_t_cleanup(
		function () use ( $id ) {
			wp_delete_post( $id, true );
		}
	);
	if ( null === $episodes ) {
		$episodes = array(
			array(
				'url'   => 'https://live.example.test/one.m3u8',
				'kind'  => 'hls',
				'embed' => '',
				'title' => '',
				'image' => '',
			),
			array(
				'url'   => 'https://vimeo.com/76979871',
				'kind'  => 'embed',
				'embed' => 'https://player.vimeo.com/video/76979871?autoplay=1',
				'title' => 'Second episode',
				'image' => 'https://i.vimeocdn.example.test/two.jpg',
			),
		);
	}
	update_post_meta( $id, FTVS_Manual::DONE, $episodes );
	return $id;
}

/** The id of the n-th episode of the default test series (ids come from the episode's link). */
function ftvs_t_ep( $series_id, $n ) {
	$urls = array( 'https://live.example.test/one.m3u8', 'https://vimeo.com/76979871' );
	return '_mv' . $series_id . 'x' . FTVS_Manual::key( isset( $urls[ $n ] ) ? $urls[ $n ] : 'not-an-episode-' . $n );
}

function ftvs_t_oembed_answer( $iframe_src, $title = 'A video', $thumb = 'https://i.example.test/t.jpg' ) {
	return ftvs_t_json(
		array(
			'version'       => '1.0',
			'type'          => 'video',
			'title'         => $title,
			'thumbnail_url' => $thumb,
			'html'          => '<iframe width="1280" height="720" src="' . $iframe_src . '" frameborder="0" allowfullscreen></iframe>',
		)
	);
}

function ftvs_t_save_series( $id, $lines, $nonce = true ) {
	$_POST = array(
		'ftvs_series_nonce' => $nonce ? wp_create_nonce( 'ftvs_series_save' ) : 'wrong',
		'ftvs_episodes'     => $lines,
	);
	FTVS_Manual::save( $id, get_post( $id ) );
}

function ftvs_t_saved_episodes( $id ) {
	$done = get_post_meta( $id, FTVS_Manual::DONE, true );
	return is_array( $done ) ? $done : array();
}

/* ---------------------------------------------------------------- ids */

function test_manual_owns_only_its_own_ids() {
	foreach ( array( '_ms12', '_ms1', '_mv12x0123abcd', '_mv1xdeadbeef', '_msall' ) as $id ) {
		assert_true( FTVS_Manual::owns( $id ), $id );
	}
	foreach ( array( '', '_ms', '_mv12', '_mv12x', '_mv12x3', '_mv12x0123ABCD', '_msALL', '_mx12', 'ms12', 'demo-rooted', 'kids-rock', ' _ms12', '_ms12x', 12, null, array( '_ms1' ) ) as $id ) {
		assert_false( FTVS_Manual::owns( $id ), var_export( $id, true ) ); // phpcs:ignore
	}
}

/* ---------------------------------------------------------------- a published series */

function test_manual_a_published_series_and_its_episodes() {
	$id = ftvs_t_series();
	assert_true( FTVS_Manual::exists( '_ms' . $id ) );
	assert_true( FTVS_Manual::exists( ftvs_t_ep( $id, 0 ) ) );
	assert_true( FTVS_Manual::exists( ftvs_t_ep( $id, 1 ) ) );
	assert_false( FTVS_Manual::exists( ftvs_t_ep( $id, 2 ) ), 'there are only two episodes' );
	assert_false( FTVS_Manual::exists( '_ms' . ( $id + 100000 ) ) );
	assert_same( 'A test series', FTVS_Manual::title( '_ms' . $id ) );
	assert_same( '', FTVS_Manual::title( '_ms' . ( $id + 100000 ) ) );
	assert_same( 'Series', FTVS_Manual::title( FTVS_Manual::ROW ) );
}

function test_manual_children_of_a_series_are_its_episodes() {
	$id   = ftvs_t_series();
	$data = FTVS_Manual::get_children( '_ms' . $id );
	assert_not_error( $data );
	assert_same( array(), $data['categories'] );
	assert_same( array( ftvs_t_ep( $id, 0 ), ftvs_t_ep( $id, 1 ) ), wp_list_pluck( $data['videos'], 'id' ) );
	$first  = $data['videos'][0];
	$second = $data['videos'][1];
	assert_same( '_ms' . $id, $first['parent'] );
	assert_same( 'Episode 1', $first['title'], 'no name given: numbered' );
	assert_same( 'Second episode', $second['title'] );
	assert_same( '', $first['image'], 'no featured image and no episode picture' );
	assert_same( 'https://i.vimeocdn.example.test/two.jpg', $second['image'] );
	assert_same( $second['image'], $second['poster'] );
	assert_matches( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $first['added'] );
	foreach ( array( 'description', 'speaker', 'scripture' ) as $key ) {
		assert_same( '', $first[ $key ] );
	}
	assert_same( array(), $first['tags'] );
	assert_same( 0, $first['length'] );
}

function test_manual_a_draft_series_is_not_shown() {
	$id = ftvs_t_series( 'draft' );
	assert_false( FTVS_Manual::exists( '_ms' . $id ) );
	assert_wp_error( FTVS_Manual::get_children( '_ms' . $id ), 'ftvs_gone' );
	assert_wp_error( FTVS_Manual::get_video( ftvs_t_ep( $id, 0 ) ), 'ftvs_gone' );
}

function test_manual_a_stream_and_an_embedded_video() {
	$id = ftvs_t_series();
	$hls = FTVS_Manual::get_video( ftvs_t_ep( $id, 0 ) );
	assert_not_error( $hls );
	assert_same( 'https://live.example.test/one.m3u8', $hls['hls'] );
	assert_same( '', $hls['embed'] );
	assert_same( array(), $hls['related'] );
	assert_same(
		array(
			array(
				'id'    => '_ms' . $id,
				'title' => 'A test series',
			),
		),
		$hls['series']
	);
	$embed = FTVS_Manual::get_video( ftvs_t_ep( $id, 1 ) );
	assert_same( '', $embed['hls'] );
	assert_same( 'https://player.vimeo.com/video/76979871?autoplay=1', $embed['embed'] );
	assert_same( 'Second episode', $embed['title'] );
	assert_false( $embed['captions'] );
}

function test_manual_get_video_with_bad_or_missing_ids() {
	$id = ftvs_t_series();
	assert_wp_error( FTVS_Manual::get_video( '_ms' . $id ), 'ftvs_bad_id', 'a series is not a video' );
	assert_wp_error( FTVS_Manual::get_video( 'demo-x' ), 'ftvs_bad_id' );
	assert_wp_error( FTVS_Manual::get_video( ftvs_t_ep( $id, 9 ) ), 'ftvs_gone' );
	assert_wp_error( FTVS_Manual::get_video( '_mv' . ( $id + 100000 ) . 'x' . FTVS_Manual::key( 'https://live.example.test/one.m3u8' ) ), 'ftvs_gone' );
}

function test_manual_the_catalog_serves_hand_built_ids_without_a_platform() {
	$id = ftvs_t_series();
	assert_same(
		array(
			'id'    => '_ms' . $id,
			'title' => 'A test series',
		),
		FTVS_Catalog::find_category( '_ms' . $id )
	);
	assert_true( FTVS_Catalog::is_known( ftvs_t_ep( $id, 1 ) ) );
	assert_false( FTVS_Catalog::is_known( ftvs_t_ep( $id, 7 ) ) );
	assert_count( 2, FTVS_Catalog::get_children( '_ms' . $id )['videos'] );
	assert_same( 'https://live.example.test/one.m3u8', FTVS_Catalog::get_video_url( ftvs_t_ep( $id, 0 ) ) );
	assert_wp_error( FTVS_Catalog::get_video_url( ftvs_t_ep( $id, 1 ) ), 'ftvs_no_stream', 'an embedded video has no HLS address' );
	assert_count( 0, ftvs_t_requests() );
}

/* ---------------------------------------------------------------- looking a link up */

function test_manual_resolve_a_stream_address() {
	$url = 'https://live.example.test/church/stream.m3u8?token=abc';
	assert_same(
		array(
			'url'   => $url,
			'kind'  => 'hls',
			'embed' => '',
			'title' => '',
			'image' => '',
		),
		FTVS_Manual::resolve( $url ),
		'no request is made for a stream'
	);
	assert_same( 'hls', FTVS_Manual::resolve( 'https://live.example.test/x.M3U8' )['kind'] );
	assert_count( 0, ftvs_t_requests() );
}

function test_manual_resolve_a_youtube_link_plays_in_the_privacy_enhanced_player() {
	ftvs_t_route( FTVS_T_YT_OEMBED, ftvs_t_oembed_answer( 'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed', 'Never Gonna Give You Up', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' ) );
	$ep = FTVS_Manual::resolve( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
	assert_same( 'embed', $ep['kind'] );
	assert_same( FTVS_YouTube_Client::embed_url( 'dQw4w9WgXcQ' ), $ep['embed'], 'youtube-nocookie.com with autoplay and the JS API' );
	assert_same( 'Never Gonna Give You Up', $ep['title'] );
	assert_same( 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $ep['image'] );
	assert_same( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', $ep['url'] );
}

function test_manual_resolve_a_vimeo_link_keeps_its_own_parameters_and_adds_autoplay() {
	ftvs_t_route( 'vimeo.com/api/oembed', ftvs_t_oembed_answer( 'https://player.vimeo.com/video/76979871?h=abc123&amp;app_id=122963', 'A Vimeo talk' ) );
	$ep = FTVS_Manual::resolve( 'https://vimeo.com/76979871' );
	assert_same( 'embed', $ep['kind'] );
	parse_str( (string) wp_parse_url( $ep['embed'], PHP_URL_QUERY ), $q );
	assert_same( 'abc123', $q['h'], 'a private video\'s hash is kept' );
	assert_same( '1', $q['autoplay'] );
	assert_same( '1', $q['api'] );
	assert_same( '1', $q['dnt'], 'Vimeo is asked not to track viewers' );
	assert_matches( '#^https://player\.vimeo\.com/video/76979871\?#', $ep['embed'] );
}

function test_manual_resolve_other_players_get_autoplay() {
	ftvs_t_route( 'dailymotion.com/services/oembed', ftvs_t_oembed_answer( 'https://www.dailymotion.com/embed/video/x8abcd', 'On Dailymotion' ) );
	$ep = FTVS_Manual::resolve( 'https://www.dailymotion.com/video/x8abcd' );
	assert_same( 'embed', $ep['kind'] );
	assert_same( 'https://www.dailymotion.com/embed/video/x8abcd?autoplay=1', $ep['embed'] );
}

function test_manual_resolve_gives_up_on_links_that_cannot_be_embedded() {
	ftvs_t_route( FTVS_T_YT_OEMBED, ftvs_t_text( 'Not Found', 404 ) );
	assert_same( null, FTVS_Manual::resolve( 'https://www.youtube.com/watch?v=aaaaaaaaaaa' ), 'the provider does not know the video' );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( FTVS_T_YT_OEMBED, ftvs_t_json( array( 'type' => 'video', 'title' => 'No player', 'html' => '<p>no iframe here</p>' ) ) );
	assert_same( null, FTVS_Manual::resolve( 'https://www.youtube.com/watch?v=bbbbbbbbbbb' ), 'an answer without an iframe' );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'https://nowhere.example.test/', ftvs_t_text( '<html><body>nothing to embed</body></html>' ) );
	assert_same( null, FTVS_Manual::resolve( 'https://nowhere.example.test/watch/1' ), 'a site WordPress cannot embed' );
}

/* ---------------------------------------------------------------- saving the list */

function test_manual_save_reads_one_link_per_line_with_optional_names() {
	ftvs_t_admin();
	$id = ftvs_t_series( 'draft', array() );
	ftvs_t_save_series(
		$id,
		implode(
			"\r\n",
			array(
				'https://live.example.test/a.m3u8 | First',
				'http://insecure.example.test/b.m3u8',
				'javascript:alert(1)',
				'',
				'not a link',
				'https://live.example.test/c.m3u8|  Third  ',
				'https://live.example.test/d.m3u8',
			)
		)
	);
	$eps = ftvs_t_saved_episodes( $id );
	assert_same( array( 'https://live.example.test/a.m3u8', 'https://live.example.test/c.m3u8', 'https://live.example.test/d.m3u8' ), wp_list_pluck( $eps, 'url' ), 'only https links count' );
	assert_same( array( 'First', 'Third', '' ), wp_list_pluck( $eps, 'title' ) );
	assert_same( array( 'hls', 'hls', 'hls' ), wp_list_pluck( $eps, 'kind' ) );
	assert_contains( 'https://live.example.test/a.m3u8 | First', (string) get_post_meta( $id, FTVS_Manual::META, true ), 'what was typed is kept for the edit screen' );
	assert_true( (bool) wp_next_scheduled( FTVS_Purge::CRON ), 'page caches are asked to clear' );
}

function test_manual_save_looks_each_new_link_up_once() {
	ftvs_t_admin();
	$id = ftvs_t_series( 'draft', array() );
	ftvs_t_route( FTVS_T_YT_OEMBED, ftvs_t_oembed_answer( 'https://www.youtube.com/embed/dQw4w9WgXcQ?feature=oembed', 'Original title' ) );
	$link = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
	ftvs_t_save_series( $id, $link );
	assert_count( 1, ftvs_t_requests( FTVS_T_YT_OEMBED ) );
	assert_same( 'Original title', ftvs_t_saved_episodes( $id )[0]['title'] );

	ftvs_t_save_series( $id, $link . ' | My own name' );
	assert_count( 1, ftvs_t_requests( FTVS_T_YT_OEMBED ), 'saving again does not ask YouTube again: visitors never wait on other sites' );
	$eps = ftvs_t_saved_episodes( $id );
	assert_same( 'My own name', $eps[0]['title'] );
	assert_same( FTVS_YouTube_Client::embed_url( 'dQw4w9WgXcQ' ), $eps[0]['embed'] );
}

function test_manual_save_skips_a_link_that_cannot_be_looked_up() {
	ftvs_t_admin();
	$id = ftvs_t_series( 'draft', array() );
	ftvs_t_route( FTVS_T_YT_OEMBED, ftvs_t_text( 'Not Found', 404 ) );
	ftvs_t_save_series( $id, "https://www.youtube.com/watch?v=aaaaaaaaaaa\nhttps://live.example.test/ok.m3u8" );
	assert_same( array( 'https://live.example.test/ok.m3u8' ), wp_list_pluck( ftvs_t_saved_episodes( $id ), 'url' ) );
}

function test_manual_save_keeps_at_most_a_hundred_episodes() {
	ftvs_t_admin();
	$id    = ftvs_t_series( 'draft', array() );
	$lines = array();
	for ( $i = 1; $i <= 105; $i++ ) {
		$lines[] = 'https://live.example.test/ep' . $i . '.m3u8';
	}
	ftvs_t_save_series( $id, implode( "\n", $lines ) );
	$eps = ftvs_t_saved_episodes( $id );
	assert_count( 100, $eps );
	assert_same( 'https://live.example.test/ep100.m3u8', $eps[99]['url'] );
}

function test_manual_save_refuses_a_bad_nonce_and_a_visitor() {
	$id = ftvs_t_series( 'draft', array() );
	ftvs_t_admin();
	ftvs_t_save_series( $id, 'https://live.example.test/a.m3u8', false );
	assert_same( array(), ftvs_t_saved_episodes( $id ), 'a form that was not made by this page' );
	assert_same( '', get_post_meta( $id, FTVS_Manual::META, true ) );

	wp_set_current_user( 0 );
	ftvs_t_save_series( $id, 'https://live.example.test/a.m3u8' );
	assert_same( array(), ftvs_t_saved_episodes( $id ), 'someone who cannot edit the post' );
}

function test_manual_save_ignores_a_request_without_the_form() {
	$id = ftvs_t_series( 'draft', array() );
	ftvs_t_admin();
	$_POST = array();
	FTVS_Manual::save( $id, get_post( $id ) );
	assert_same( array(), ftvs_t_saved_episodes( $id ) );
}

/* ---------------------------------------------------------------- the edit screen */

function test_manual_the_edit_box_shows_what_was_typed_and_what_was_found() {
	ftvs_t_admin();
	$id = ftvs_t_series();
	update_post_meta( $id, FTVS_Manual::META, "https://live.example.test/one.m3u8\nhttps://vimeo.com/76979871 | Second episode" );
	ob_start();
	FTVS_Manual::box( get_post( $id ) );
	$html = ob_get_clean();
	assert_contains( 'name="ftvs_series_nonce"', $html );
	assert_contains( '<textarea name="ftvs_episodes"', $html );
	assert_contains( "https://live.example.test/one.m3u8\nhttps://vimeo.com/76979871 | Second episode", $html );
	assert_contains( '(stream)', $html );
	assert_contains( 'Second episode', $html );
	assert_contains( 'vimeo.com', $html );
}

function test_manual_series_are_the_whole_library_when_no_platform_is_connected() {
	ftvs_t_settings( array( 'source' => '' ) );
	$id = ftvs_t_series();
	$library = FTVS_Catalog::library();
	assert_not_error( $library, 'the sermon library, search and message pages work with hand-built series alone' );
	assert_count( 2, $library );
	assert_same( 'A test series', $library[0]['series'] );
	assert_not_error( FTVS_Catalog::newest() );
	$featured = FTVS_Catalog::featured();
	assert_not_error( $featured );
	assert_same( '_ms' . $id, $featured['categories'][0]['id'] );
	assert_count( 0, ftvs_t_requests(), 'nothing is asked of any platform' );
}

function test_manual_episode_ids_stay_the_same_when_episodes_move() {
	$id    = ftvs_t_series();
	$first = ftvs_t_ep( $id, 0 );
	$eps   = get_post_meta( $id, FTVS_Manual::DONE, true );
	update_post_meta( $id, FTVS_Manual::DONE, array_reverse( $eps ) ); // the church put the second one first
	FTVS_Manual::forget_posts();
	assert_true( FTVS_Manual::exists( $first ), 'a shared link to the moved episode still works' );
	assert_same( 'https://live.example.test/one.m3u8', FTVS_Manual::get_video( $first )['hls'] );
	$ids = wp_list_pluck( FTVS_Manual::get_children( '_ms' . $id )['videos'], 'id' );
	assert_same( array( ftvs_t_ep( $id, 1 ), $first ), $ids, 'the order changed, the ids did not' );
}
