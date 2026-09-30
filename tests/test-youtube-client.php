<?php
/**
 * FTVS_YouTube_Client: what people paste, the id checks, the embed address, and reading
 * YouTube's public Atom feeds and Data API (stubbed).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ftvs_t_yt_feed( $title, $author, $entries ) {
	$xml = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns:yt="http://www.youtube.com/xml/schemas/2015" xmlns:media="http://search.yahoo.com/mrss/" xmlns="http://www.w3.org/2005/Atom">'
		. '<title>' . $title . '</title><author><name>' . $author . '</name></author>';
	foreach ( $entries as $e ) {
		$xml .= '<entry><yt:videoId>' . $e[0] . '</yt:videoId><title>' . $e[1] . '</title><published>' . $e[2] . '</published>'
			. '<media:group><media:title>' . $e[1] . '</media:title><media:description>' . $e[3] . '</media:description></media:group></entry>';
	}
	return ftvs_t_text( $xml . '</feed>', 200, array( 'content-type' => 'application/atom+xml' ) );
}

function ftvs_t_yt_entries() {
	return array(
		array( FTVS_T_VIDEO_A, 'Message One', '2026-09-27T16:00:00+00:00', 'About one' ),
		array( 'bad', 'Not a video id', '2026-09-20T16:00:00+00:00', '' ),
		array( FTVS_T_VIDEO_B, 'Message Two', '2026-09-20T16:00:00+00:00', 'About two' ),
	);
}

/* ---------------------------------------------------------------- ids */

function test_youtube_is_playlist() {
	foreach ( array( FTVS_T_PL1, FTVS_T_PL2, 'UUabcdefghijklmnop', 'OLAK5uy_kTest12345', 'FLxxxxxxxxxxxxxxxxx', 'LLabcdefghij123', 'RDdQw4w9WgXcQ', 'PL' . str_repeat( 'a', 10 ), 'PL' . str_repeat( 'a', 64 ) ) as $ok ) {
		assert_true( FTVS_YouTube_Client::is_playlist( $ok ), $ok );
	}
	foreach ( array( '', 'PL', 'PL' . str_repeat( 'a', 9 ), 'PL' . str_repeat( 'a', 65 ), 'pl' . str_repeat( 'a', 12 ), 'XX' . str_repeat( 'a', 12 ), 'PLabc.defghijkl', 'PLabc defghijkl', FTVS_T_VIDEO_A, FTVS_T_UCID ) as $bad ) {
		assert_false( FTVS_YouTube_Client::is_playlist( $bad ), 'should be rejected: ' . $bad );
	}
}

function test_youtube_is_video() {
	foreach ( array( FTVS_T_VIDEO_A, FTVS_T_VIDEO_B, '-_-_-_-_-_-', '12345678901' ) as $ok ) {
		assert_true( FTVS_YouTube_Client::is_video( $ok ), $ok );
	}
	foreach ( array( '', 'short', 'abcdefghij', 'abcdefghijkl', 'abc!efghijk', 'has space 1', 'abc/efghijk' ) as $bad ) {
		assert_false( FTVS_YouTube_Client::is_video( $bad ), 'should be rejected: ' . $bad );
	}
}

function test_youtube_is_id_covers_the_two_virtual_rows_playlists_and_videos() {
	assert_true( FTVS_YouTube_Client::is_id( 'yt-series' ) );
	assert_true( FTVS_YouTube_Client::is_id( 'yt-uploads' ) );
	assert_true( FTVS_YouTube_Client::is_id( FTVS_T_PL1 ) );
	assert_true( FTVS_YouTube_Client::is_id( FTVS_T_VIDEO_A ) );
	assert_false( FTVS_YouTube_Client::is_id( 'demo-hope-rising' ) );
	assert_false( FTVS_YouTube_Client::is_id( '' ) );
	assert_false( FTVS_YouTube_Client::is_id( 'yt-other' ) );
}

/* ---------------------------------------------------------------- what people paste */

function test_youtube_parse_input_playlist_links() {
	$in = FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/playlist?list=' . FTVS_T_PL1 );
	assert_same(
		array(
			'channel'   => '',
			'playlists' => array( FTVS_T_PL1 ),
		),
		$in
	);
	$in = FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/watch?v=' . FTVS_T_VIDEO_A . '&list=' . FTVS_T_PL2 . '&index=3' );
	assert_same( array( FTVS_T_PL2 ), $in['playlists'], 'a watch link that is inside a playlist gives the playlist' );
	$in = FTVS_YouTube_Client::parse_input( 'https://youtu.be/' . FTVS_T_VIDEO_A . '?list=' . FTVS_T_PL2 );
	assert_same( array( FTVS_T_PL2 ), $in['playlists'] );
}

function test_youtube_parse_input_bare_playlist_ids_and_duplicates() {
	$in = FTVS_YouTube_Client::parse_input( FTVS_T_PL1 . ', ' . FTVS_T_PL2 . "\n" . FTVS_T_PL1 );
	assert_same( array( FTVS_T_PL1, FTVS_T_PL2 ), $in['playlists'], 'separated by spaces, commas or lines; each once' );
}

function test_youtube_parse_input_handles_and_channel_links() {
	assert_same( '@GraceChurch', FTVS_YouTube_Client::parse_input( '@GraceChurch' )['channel'] );
	assert_same( '@GraceChurch', FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/@GraceChurch' )['channel'] );
	assert_same( '@GraceChurch', FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/@GraceChurch/videos' )['channel'] );
	assert_same( '@grace.church-1_a', FTVS_YouTube_Client::parse_input( '@grace.church-1_a' )['channel'] );
	assert_same( FTVS_T_UCID, FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/channel/' . FTVS_T_UCID )['channel'] );
	assert_same( FTVS_T_UCID, FTVS_YouTube_Client::parse_input( FTVS_T_UCID )['channel'], 'a bare channel id' );
}

function test_youtube_parse_input_channel_and_playlists_together() {
	$in = FTVS_YouTube_Client::parse_input( "@GraceChurch\nhttps://www.youtube.com/playlist?list=" . FTVS_T_PL1 . ' ' . FTVS_T_PL2 );
	assert_same(
		array(
			'channel'   => '@GraceChurch',
			'playlists' => array( FTVS_T_PL1, FTVS_T_PL2 ),
		),
		$in
	);
}

function test_youtube_parse_input_ignores_what_it_cannot_use() {
	$empty = array(
		'channel'   => '',
		'playlists' => array(),
	);
	assert_same( $empty, FTVS_YouTube_Client::parse_input( '' ) );
	assert_same( $empty, FTVS_YouTube_Client::parse_input( '   ' ) );
	assert_same( $empty, FTVS_YouTube_Client::parse_input( 'hello world' ) );
	assert_same( $empty, FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/watch?v=' . FTVS_T_VIDEO_A ), 'one video is not a series' );
	assert_same( $empty, FTVS_YouTube_Client::parse_input( '@ab' ), 'handles have at least 3 characters' );
	assert_same( $empty, FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/channel/UCshort' ) );
	assert_same( $empty, FTVS_YouTube_Client::parse_input( 'https://www.youtube.com/playlist?list=NOTAPLAYLIST' ) );
	assert_same( $empty, FTVS_YouTube_Client::parse_input( null ) );
}

/* ---------------------------------------------------------------- embed address, links */

function test_youtube_embed_url() {
	$url = FTVS_YouTube_Client::embed_url( FTVS_T_VIDEO_A );
	assert_matches( '#^https://www\.youtube-nocookie\.com/embed/' . FTVS_T_VIDEO_A . '\?#', $url, 'the privacy-enhanced player' );
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	assert_same( '1', $q['autoplay'] );
	assert_same( '0', $q['rel'] );
	assert_same( '1', $q['modestbranding'] );
	assert_same( '1', $q['playsinline'] );
	assert_same( '1', $q['enablejsapi'] );
	assert_same( home_url(), $q['origin'], 'the player is told which site it is on' );
}

function test_youtube_embed_url_encodes_the_id() {
	$url = FTVS_YouTube_Client::embed_url( 'a/b c?d' );
	assert_contains( '/embed/a%2Fb%20c%3Fd?', $url );
}

function test_youtube_get_video_plays_in_youtubes_player() {
	$video = FTVS_YouTube_Client::get_video( FTVS_T_VIDEO_A );
	assert_same( '', $video['hls'] );
	assert_same( FTVS_YouTube_Client::embed_url( FTVS_T_VIDEO_A ), $video['embed'] );
	assert_same( FTVS_T_VIDEO_A, $video['id'] );
	assert_wp_error( FTVS_YouTube_Client::get_video( 'nope' ), 'ftvs_bad_id' );
	assert_wp_error( FTVS_YouTube_Client::get_video( '' ), 'ftvs_bad_id' );
	assert_wp_error( FTVS_YouTube_Client::get_video_url( FTVS_T_VIDEO_A ), 'ftvs_no_stream' );
}

function test_youtube_channel_url_and_links() {
	ftvs_t_settings( array( 'yt_channel' => '@GraceChurch' ) );
	assert_same( 'https://www.youtube.com/@GraceChurch', FTVS_YouTube_Client::channel_url() );
	ftvs_t_settings( array( 'yt_channel' => 'GraceChurch' ) );
	assert_same( 'https://www.youtube.com/@GraceChurch', FTVS_YouTube_Client::channel_url(), 'a handle typed without the @' );
	ftvs_t_settings( array( 'yt_channel' => FTVS_T_UCID ) );
	assert_same( 'https://www.youtube.com/channel/' . FTVS_T_UCID, FTVS_YouTube_Client::channel_url() );
	ftvs_t_settings( array( 'yt_channel' => '' ) );
	assert_same( '', FTVS_YouTube_Client::channel_url() );

	assert_same( 'https://www.youtube.com/playlist?list=' . FTVS_T_PL1, FTVS_YouTube_Client::category_link( FTVS_T_PL1 ) );
	assert_same( 'https://www.youtube.com/watch?v=' . FTVS_T_VIDEO_A, FTVS_YouTube_Client::video_link( FTVS_T_VIDEO_A, FTVS_T_PL1 ) );
	ftvs_t_settings( array( 'yt_channel' => '@GraceChurch' ) );
	assert_same( 'https://www.youtube.com/@GraceChurch', FTVS_YouTube_Client::category_link( 'yt-uploads' ), 'a row that is not a playlist links to the channel' );
}

/* ---------------------------------------------------------------- public feeds */

function test_youtube_playlist_from_the_public_feed() {
	ftvs_t_settings( array( 'source' => 'youtube' ) );
	ftvs_t_route( 'feeds/videos.xml?playlist_id=' . FTVS_T_PL1, ftvs_t_yt_feed( 'Sunday Messages', 'Grace Church', ftvs_t_yt_entries() ) );
	$data = FTVS_YouTube_Client::fetch_playlist( FTVS_T_PL1 );
	assert_not_error( $data );
	assert_same( FTVS_T_PL1, $data['self']['id'] );
	assert_same( 'Sunday Messages', $data['self']['title'] );
	assert_same( 2, $data['self']['videos'], 'the entry with a bad video id is skipped' );
	assert_same( 'https://i.ytimg.com/vi/' . FTVS_T_VIDEO_A . '/mqdefault.jpg', $data['self']['image'], 'no artwork in a feed: the first video\'s picture' );
	assert_same( 0, $data['self']['subcategories'] );

	assert_same( array( FTVS_T_VIDEO_A, FTVS_T_VIDEO_B ), wp_list_pluck( $data['videos'], 'id' ) );
	assert_same(
		array(
			'id'          => FTVS_T_VIDEO_A,
			'parent'      => FTVS_T_PL1,
			'title'       => 'Message One',
			'description' => 'About one',
			'image'       => 'https://i.ytimg.com/vi/' . FTVS_T_VIDEO_A . '/mqdefault.jpg',
			'poster'      => 'https://i.ytimg.com/vi/' . FTVS_T_VIDEO_A . '/hqdefault.jpg',
			'length'      => 0,
			'added'       => '2026-09-27T16:00:00+00:00',
			'live'        => false,
			'speaker'     => '',
			'scripture'   => '',
			'tags'        => array(),
		),
		$data['videos'][0]
	);
}

function test_youtube_uploads_from_the_public_feed() {
	ftvs_t_settings( array( 'source' => 'youtube' ) );
	ftvs_t_route( 'feeds/videos.xml?channel_id=' . FTVS_T_UCID, ftvs_t_yt_feed( 'Grace Church', 'Grace Church', ftvs_t_yt_entries() ) );
	$data = FTVS_YouTube_Client::fetch_uploads( FTVS_T_UCID );
	assert_not_error( $data );
	assert_same( array(), $data['categories'] );
	assert_count( 2, $data['videos'] );
	assert_same( 'yt-uploads', $data['videos'][0]['parent'] );
}

function test_youtube_feed_errors() {
	ftvs_t_settings( array( 'source' => 'youtube' ) );
	ftvs_t_route( 'playlist_id=' . FTVS_T_PL1, ftvs_t_text( 'gone', 404 ) );
	assert_wp_error( FTVS_YouTube_Client::fetch_playlist( FTVS_T_PL1 ), 'ftvs_gone', 'a playlist that was deleted' );
	ftvs_t_route( 'playlist_id=' . FTVS_T_PL2, ftvs_t_text( 'oops', 500 ) );
	assert_wp_error( FTVS_YouTube_Client::fetch_playlist( FTVS_T_PL2 ), 'ftvs_http' );
	ftvs_t_route( 'playlist_id=PLzzzzzzzzzzzzzzzzzz', ftvs_t_text( '<feed><title>broken', 200 ) );
	assert_wp_error( FTVS_YouTube_Client::fetch_playlist( 'PLzzzzzzzzzzzzzzzzzz' ), 'ftvs_parse' );
	ftvs_t_route( 'playlist_id=PLyyyyyyyyyyyyyyyyyy', ftvs_t_neterr() );
	assert_wp_error( FTVS_YouTube_Client::fetch_playlist( 'PLyyyyyyyyyyyyyyyyyy' ), 'http_request_failed' );
}

function test_youtube_handle_is_resolved_once_and_remembered() {
	ftvs_t_settings( array( 'source' => 'youtube' ) );
	$handle = '@Grace' . substr( md5( uniqid( '', true ) ), 0, 8 );
	ftvs_t_route( 'youtube.com/' . $handle, ftvs_t_text( '<html><head><link rel="canonical" href="https://www.youtube.com/channel/' . FTVS_T_UCID . '"></head></html>' ) );
	ftvs_t_route( 'feeds/videos.xml?channel_id=' . FTVS_T_UCID, ftvs_t_yt_feed( 'Grace', 'Grace', ftvs_t_yt_entries() ) );

	assert_not_error( FTVS_YouTube_Client::fetch_uploads( $handle ) );
	assert_not_error( FTVS_YouTube_Client::fetch_uploads( $handle ) );
	assert_count( 1, ftvs_t_requests( 'youtube.com/' . $handle ), 'the handle page is fetched once, then the id is remembered' );
	assert_count( 2, ftvs_t_requests( 'feeds/videos.xml' ) );
}

function test_youtube_handle_found_in_page_data_or_not_at_all() {
	ftvs_t_settings( array( 'source' => 'youtube' ) );
	$handle = '@Data' . substr( md5( uniqid( '', true ) ), 0, 8 );
	ftvs_t_route( 'youtube.com/' . $handle, ftvs_t_text( '<script>var x = {"channelId":"' . FTVS_T_UCID . '","other":1}</script>' ) );
	ftvs_t_route( 'feeds/videos.xml?channel_id=' . FTVS_T_UCID, ftvs_t_yt_feed( 'Grace', 'Grace', array() ) );
	assert_not_error( FTVS_YouTube_Client::fetch_uploads( $handle ) );

	$missing = '@Nobody' . substr( md5( uniqid( '', true ) ), 0, 8 );
	ftvs_t_route( 'youtube.com/' . $missing, ftvs_t_text( '<html>404</html>', 404 ) );
	assert_wp_error( FTVS_YouTube_Client::fetch_uploads( $missing ), 'ftvs_lookup' );
}

function test_youtube_lookup_channel_and_playlists() {
	$handle = '@Church' . substr( md5( uniqid( '', true ) ), 0, 8 ); // never one the site has remembered
	ftvs_t_route( 'youtube.com/' . $handle, ftvs_t_text( '<link rel="canonical" href="https://www.youtube.com/channel/' . FTVS_T_UCID . '">' ) );
	ftvs_t_route( 'feeds/videos.xml?channel_id=' . FTVS_T_UCID, ftvs_t_yt_feed( 'Grace Church', 'Grace Church', ftvs_t_yt_entries() ) );
	ftvs_t_route( 'playlist_id=' . FTVS_T_PL1, ftvs_t_yt_feed( 'Sunday Messages', 'Grace Church', ftvs_t_yt_entries() ) );
	$found = FTVS_YouTube_Client::lookup( $handle . ' ' . FTVS_T_PL1, ' AIza-key!_1 ' );
	assert_not_error( $found );
	assert_same( 'youtube', $found['source'] );
	assert_same( $handle, $found['yt_channel'] );
	assert_same( array( FTVS_T_PL1 ), $found['yt_playlists'] );
	assert_same( 'AIza-key_1', $found['yt_key'] );
	assert_same( 'Grace Church', $found['church_name'] );
	assert_same( array( 'yt-uploads', FTVS_T_PL1 ), wp_list_pluck( $found['rows'], 'id' ) );
	assert_same( 'Sunday Messages', $found['rows'][1]['title'] );
}

function test_youtube_lookup_names_the_church_after_the_first_playlists_author() {
	ftvs_t_route( 'playlist_id=' . FTVS_T_PL1, ftvs_t_yt_feed( 'Messages', 'Grace Church', ftvs_t_yt_entries() ) );
	$found = FTVS_YouTube_Client::lookup( FTVS_T_PL1 );
	assert_same( 'Grace Church', $found['church_name'] );
	assert_same( '', $found['yt_channel'] );
	assert_same( '', $found['yt_key'] );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'playlist_id=', ftvs_t_yt_feed( 'Messages', '', array() ) );
	assert_same( 'Your church', FTVS_YouTube_Client::lookup( FTVS_T_PL1 )['church_name'], 'no author in the feed: a neutral name' );
}

function test_youtube_lookup_failures() {
	assert_wp_error( FTVS_YouTube_Client::lookup( 'hello' ), 'ftvs_lookup' );
	assert_count( 0, ftvs_t_requests(), 'nothing recognisable: no requests' );

	ftvs_t_route( 'playlist_id=' . FTVS_T_PL1, ftvs_t_text( 'private', 404 ) );
	$err = FTVS_YouTube_Client::lookup( FTVS_T_PL1 );
	assert_wp_error( $err, 'ftvs_lookup' );
	assert_contains( FTVS_T_PL1, $err->get_error_message() );
	assert_contains( 'public', $err->get_error_message() );
}

/* ---------------------------------------------------------------- Data API (with a key) */

function ftvs_t_yt_api_item( $video, $title, $when = '2026-09-27T16:00:00Z' ) {
	return array(
		'contentDetails' => array(
			'videoId'          => $video,
			'videoPublishedAt' => $when,
		),
		'snippet'        => array(
			'title'       => $title,
			'description' => 'About ' . $title,
			'publishedAt' => '2026-09-01T00:00:00Z',
		),
	);
}

function test_youtube_playlist_from_the_data_api_pages_and_durations() {
	ftvs_t_settings(
		array(
			'source' => 'youtube',
			'yt_key' => 'KEY123',
		)
	);
	ftvs_t_route(
		'youtube/v3/playlists?',
		ftvs_t_json(
			array(
				'items' => array(
					array(
						'snippet' => array(
							'title'       => 'Big Series',
							'description' => ' The description ',
							'thumbnails'  => array(
								'default' => array( 'url' => 'https://i.ytimg.com/vi/x/default.jpg' ),
								'high'    => array( 'url' => 'https://i.ytimg.com/vi/x/high.jpg' ),
							),
						),
					),
				),
			)
		)
	);
	ftvs_t_route(
		'youtube/v3/playlistItems?',
		function ( $url ) {
			if ( false !== strpos( $url, 'pageToken=TOKEN2' ) ) {
				return ftvs_t_json( array( 'items' => array( ftvs_t_yt_api_item( 'ccccccccccc', 'Third' ) ) ) );
			}
			return ftvs_t_json(
				array(
					'items'         => array(
						ftvs_t_yt_api_item( FTVS_T_VIDEO_A, 'First' ),
						ftvs_t_yt_api_item( 'zzzzzzzzzzz', 'Private video' ),
						ftvs_t_yt_api_item( 'not-an-id', 'Bad id' ),
						ftvs_t_yt_api_item( FTVS_T_VIDEO_B, 'Second' ),
					),
					'nextPageToken' => 'TOKEN2',
				)
			);
		}
	);
	ftvs_t_route(
		'youtube/v3/videos?',
		ftvs_t_json(
			array(
				'items' => array(
					array(
						'id'             => FTVS_T_VIDEO_A,
						'contentDetails' => array( 'duration' => 'PT1H2M3S' ),
					),
					array(
						'id'             => FTVS_T_VIDEO_B,
						'contentDetails' => array( 'duration' => 'PT45S' ),
					),
					array(
						'id'             => 'ccccccccccc',
						'contentDetails' => array( 'duration' => 'P0D' ),
					),
				),
			)
		)
	);

	$data = FTVS_YouTube_Client::fetch_playlist( FTVS_T_PL1 );
	assert_not_error( $data );
	assert_same( array( FTVS_T_VIDEO_A, FTVS_T_VIDEO_B, 'ccccccccccc' ), wp_list_pluck( $data['videos'], 'id' ), 'private videos and bad ids are skipped; the second page is read' );
	assert_same( array( 3723, 45, 0 ), wp_list_pluck( $data['videos'], 'length' ), 'ISO 8601 durations become seconds' );
	assert_same( 'Big Series', $data['self']['title'] );
	assert_same( 'The description', $data['self']['description'] );
	assert_same( 'https://i.ytimg.com/vi/x/high.jpg', $data['self']['image'], 'the best playlist picture' );
	assert_same( 3, $data['self']['videos'] );
	assert_same( '2026-09-27T16:00:00Z', $data['videos'][0]['added'], 'the date the video went up, not the date it joined the playlist' );

	foreach ( ftvs_t_requests( 'googleapis.com' ) as $request ) {
		assert_contains( 'key=KEY123', $request['url'] );
	}
	assert_count( 4, ftvs_t_requests( 'googleapis.com' ), 'playlist, two pages of items, one batch of durations' );
}

function test_youtube_data_api_playlist_that_does_not_exist_is_gone() {
	ftvs_t_settings(
		array(
			'source' => 'youtube',
			'yt_key' => 'KEY123',
		)
	);
	ftvs_t_route( 'youtube/v3/playlists?', ftvs_t_json( array( 'items' => array() ) ) );
	assert_wp_error( FTVS_YouTube_Client::fetch_playlist( FTVS_T_PL1 ), 'ftvs_gone' );
}

function test_youtube_data_api_errors_carry_youtubes_message() {
	ftvs_t_settings(
		array(
			'source' => 'youtube',
			'yt_key' => 'BADKEY',
		)
	);
	ftvs_t_route( 'youtube/v3/playlists?', ftvs_t_json( array( 'error' => array( 'message' => 'API key <b>not valid</b>. Please pass a valid API key.' ) ), 400 ) );
	$err = FTVS_YouTube_Client::fetch_playlist( FTVS_T_PL1 );
	assert_wp_error( $err, 'ftvs_http' );
	assert_contains( 'API key not valid. Please pass a valid API key.', $err->get_error_message() );
	assert_not_contains( '<b>', $err->get_error_message() );
}

function test_youtube_channel_playlists_are_listed_with_a_key() {
	ftvs_t_settings(
		array(
			'source' => 'youtube',
			'yt_key' => 'KEY123',
		)
	);
	ftvs_t_route(
		'youtube/v3/playlists?',
		function ( $url ) {
			if ( false !== strpos( $url, 'pageToken=P2' ) ) {
				return ftvs_t_json( array( 'items' => array( array( 'id' => 'PLthird0000000', 'snippet' => array( 'title' => 'Third' ) ) ) ) );
			}
			return ftvs_t_json(
				array(
					'items'         => array(
						array( 'id' => FTVS_T_PL1, 'snippet' => array( 'title' => 'First' ) ),
						array( 'id' => FTVS_T_PL2 ),
					),
					'nextPageToken' => 'P2',
				)
			);
		}
	);
	$list = FTVS_YouTube_Client::fetch_channel_playlists( FTVS_T_UCID );
	assert_same( array( FTVS_T_PL1, FTVS_T_PL2, 'PLthird0000000' ), wp_list_pluck( $list, 'id' ) );
	assert_same( array( 'First', '', 'Third' ), wp_list_pluck( $list, 'title' ) );
	assert_contains( 'channelId=' . FTVS_T_UCID, ftvs_t_requests( 'playlists' )[0]['url'] );
}

/* ---------------------------------------------------------------- the catalog view of a YouTube church */

function test_youtube_get_children_home_rows() {
	ftvs_t_settings(
		array(
			'source'       => 'youtube',
			'yt_channel'   => FTVS_T_UCID,
			'yt_playlists' => array( FTVS_T_PL1, FTVS_T_PL2 ),
			'yt_key'       => '',
			'cache_minutes' => 15,
		)
	);
	ftvs_t_route( 'playlist_id=' . FTVS_T_PL1, ftvs_t_yt_feed( 'First series', 'Grace', ftvs_t_yt_entries() ) );
	ftvs_t_route( 'playlist_id=' . FTVS_T_PL2, ftvs_t_yt_feed( 'Second series', 'Grace', array( array( FTVS_T_VIDEO_B, 'Only one', '2026-09-01T00:00:00+00:00', '' ) ) ) );
	ftvs_t_route( 'channel_id=' . FTVS_T_UCID, ftvs_t_yt_feed( 'Grace', 'Grace', ftvs_t_yt_entries() ) );

	$home = FTVS_YouTube_Client::get_children( '' );
	assert_not_error( $home );
	assert_same( array( 'yt-series', 'yt-uploads' ), wp_list_pluck( $home['categories'], 'id' ) );
	assert_same( 'Series', $home['categories'][0]['title'] );
	assert_same( 2, $home['categories'][0]['subcategories'] );
	assert_false( isset( $home['categories'][0]['children'] ), 'children are for the tree, not the row list' );
	assert_same( 'Latest videos', $home['categories'][1]['title'] );
	assert_same( 2, $home['categories'][1]['videos'] );

	$series = FTVS_YouTube_Client::get_children( 'yt-series' );
	assert_same( array( FTVS_T_PL1, FTVS_T_PL2 ), wp_list_pluck( $series['categories'], 'id' ) );

	$one = FTVS_YouTube_Client::get_children( FTVS_T_PL2 );
	assert_same( array( FTVS_T_VIDEO_B ), wp_list_pluck( $one['videos'], 'id' ) );

	$uploads = FTVS_YouTube_Client::get_children( 'yt-uploads' );
	assert_count( 2, $uploads['videos'] );

	assert_wp_error( FTVS_YouTube_Client::get_children( 'something-else' ), 'ftvs_bad_id' );
}

function test_youtube_church_with_only_a_channel_has_one_row() {
	ftvs_t_settings(
		array(
			'source'     => 'youtube',
			'yt_channel' => FTVS_T_UCID,
		)
	);
	ftvs_t_route( 'channel_id=' . FTVS_T_UCID, ftvs_t_yt_feed( 'Grace', 'Grace', ftvs_t_yt_entries() ) );
	$tree = FTVS_YouTube_Client::fetch_tree();
	assert_same( array( 'yt-uploads' ), wp_list_pluck( $tree, 'id' ) );
	assert_same( array(), $tree[0]['children'] );
}
