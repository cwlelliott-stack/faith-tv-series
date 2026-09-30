<?php
/**
 * FTVS_Gideo_Client: Gideo's legacy XML API, parsed from stubbed answers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ftvs_t_gideo_xml( $inner ) {
	return ftvs_t_text( '<?xml version="1.0" encoding="UTF-8"?><response>' . $inner . '</response>', 200, array( 'content-type' => 'application/xml' ) );
}

/** What a category listing looks like: two series, four "videos" of which two are real. */
function ftvs_t_gideo_listing() {
	return ftvs_t_gideo_xml(
		'<category id="' . FTVS_T_CAT_A . '"><title>  Mini Series </title>'
		. "<description><![CDATA[Line one\r\nLine two\rLine three ]]></description>"
		. '<fhdimage>https://cdn.gideo.video/img/a-fhd.jpg</fhdimage><image>https://cdn.gideo.video/img/a.jpg</image>'
		. '<videos>3</videos><subcategories>2</subcategories></category>'
		. '<category id="' . FTVS_T_CAT_B . '"><title>No pictures</title><description></description>'
		. '<fhdimage>https://cdn.gideo.video/img/no-image.png</fhdimage><image>http://insecure.example.test/b.jpg</image>'
		. '<videos>0</videos><subcategories>0</subcategories></category>'
		. '<video id="v1"><type>video</type><parent>' . FTVS_T_CAT_A . '</parent><title> Episode 1 </title><description>First</description>'
		. '<image>https://cdn.gideo.video/img/v1.jpg</image><fhdimage>https://cdn.gideo.video/img/v1-fhd.jpg</fhdimage>'
		. '<length>1800</length><added>2023-05-14 10:00:00</added><live>0</live><placeholder>0</placeholder><ad>0</ad></video>'
		. '<video id="placeholder1"><type>video</type><parent>' . FTVS_T_CAT_A . '</parent><title>Coming soon</title><placeholder>1</placeholder></video>'
		. '<video id="ad1"><type>video</type><parent>' . FTVS_T_CAT_A . '</parent><title>Sponsor</title><ad>1</ad></video>'
		. '<video id="audio1"><type>audio</type><parent>' . FTVS_T_CAT_A . '</parent><title>Podcast</title></video>'
		. '<video id="v5"><parent>' . FTVS_T_CAT_A . '</parent><title>No type, no picture</title>'
		. '<image>https://cdn.gideo.video/img/no-image.png</image><fhdimage></fhdimage><length>60</length><added>2023-05-21 10:00:00</added></video>'
		. '<video id="v6"><type>video</type><parent>' . FTVS_T_CAT_B . '</parent><title>Streaming now</title><live>1</live>'
		. '<image>https://cdn.gideo.video/img/v6.jpg</image><length>0</length></video>'
	);
}

/* ---------------------------------------------------------------- parsing */

function test_gideo_parse_categories() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_listing() );
	$out = FTVS_Gideo_Client::fetch_children( 'acct', '' );
	assert_not_error( $out );
	assert_count( 2, $out['categories'] );

	assert_same(
		array(
			'id'            => FTVS_T_CAT_A,
			'title'         => 'Mini Series',
			'description'   => "Line one\nLine two\nLine three",
			'image'         => 'https://cdn.gideo.video/img/a-fhd.jpg',
			'videos'        => 3,
			'subcategories' => 2,
		),
		$out['categories'][0],
		'title trimmed, line endings unified, the HD picture preferred'
	);
	assert_same( '', $out['categories'][1]['image'], 'Gideo\'s "no-image" placeholder and http pictures are not used' );
	assert_same( '', $out['categories'][1]['description'] );
}

function test_gideo_parse_skips_placeholders_ads_and_non_video_types() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_listing() );
	$out = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A );
	assert_same( array( 'v1', 'v5', 'v6' ), wp_list_pluck( $out['videos'], 'id' ), 'placeholder, ad and audio entries are skipped; a missing type counts as video' );
}

function test_gideo_parse_video_fields() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_listing() );
	$videos = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A )['videos'];
	assert_same(
		array(
			'id'          => 'v1',
			'parent'      => FTVS_T_CAT_A,
			'title'       => 'Episode 1',
			'description' => 'First',
			'image'       => 'https://cdn.gideo.video/img/v1.jpg',
			'poster'      => 'https://cdn.gideo.video/img/v1-fhd.jpg',
			'length'      => 1800,
			'added'       => '2023-05-14 10:00:00',
			'live'        => false,
			'speaker'     => '',
			'scripture'   => '',
			'tags'        => array(),
		),
		$videos[0],
		'card picture is the normal image, the big one the HD image'
	);
}

function test_gideo_parse_video_without_a_usable_picture() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_listing() );
	$videos = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A )['videos'];
	assert_same( 'v5', $videos[1]['id'] );
	assert_same( '', $videos[1]['image'], 'the placeholder picture is dropped so the page can show its own' );
	assert_same( '', $videos[1]['poster'] );
	assert_same( 60, $videos[1]['length'] );
}

function test_gideo_parse_live_flag_and_empty_length() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_listing() );
	$videos = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A )['videos'];
	assert_same( 'v6', $videos[2]['id'] );
	assert_true( $videos[2]['live'] );
	assert_same( 0, $videos[2]['length'] );
	assert_same( '', $videos[2]['added'] );
	assert_same( 'https://cdn.gideo.video/img/v6.jpg', $videos[2]['poster'], 'no HD picture: the normal one' );
}

function test_gideo_parse_an_empty_listing() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_xml( '' ) );
	assert_same(
		array(
			'categories' => array(),
			'videos'     => array(),
		),
		FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_C )
	);
}

function test_gideo_parse_unreadable_xml() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_text( '<response><category id="x"><title>oops</response>' ) );
	assert_wp_error( FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A ), 'ftvs_parse' );
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_text( '' ) );
	assert_wp_error( FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_B ), 'ftvs_parse' );
}

function test_gideo_parse_does_not_expand_external_entities() {
	ftvs_t_gideo();
	$xml = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><response><video id="v1"><title>&xxe;</title></video></response>';
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_text( $xml ) );
	$out = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A );
	if ( ! is_wp_error( $out ) ) {
		assert_not_contains( 'root:', $out['videos'] ? $out['videos'][0]['title'] : '', 'a file on the server must never end up in a video title' );
	}
}

function test_gideo_xml_error_element() {
	ftvs_t_gideo();
	foreach ( array( 'Category not found', 'Category does not exist', 'No such category', 'Invalid category id' ) as $message ) {
		ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_xml( '<error><message>' . $message . '</message></error>' ) );
		assert_wp_error( FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A ), 'ftvs_gone', $message );
		$GLOBALS['ftvs_t_routes'] = array();
	}
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_xml( '<error><message>Rate limit exceeded</message></error>' ) );
	$err = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A );
	assert_wp_error( $err, 'ftvs_upstream' );
	assert_same( 'Rate limit exceeded', $err->get_error_message() );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_xml( '<error/>' ) );
	$err = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A );
	assert_wp_error( $err, 'ftvs_upstream' );
	assert_same( 'Gideo returned an error.', $err->get_error_message(), 'an empty error gets a plain message' );
}

/* ---------------------------------------------------------------- HTTP status */

function test_gideo_category_404_means_gone() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_text( 'Not found', 404 ) );
	assert_wp_error( FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A ), 'ftvs_gone' );
}

function test_gideo_home_404_is_not_gone() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_text( 'Not found', 404 ) );
	$err = FTVS_Gideo_Client::fetch_children( 'acct', '' );
	assert_wp_error( $err, 'ftvs_http', 'the home rows never count as removed' );
	assert_same( array( 'status' => 404 ), $err->get_error_data() );
}

function test_gideo_other_statuses_and_transport_errors_pass_through() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_text( 'oops', 500 ) );
	$err = FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A );
	assert_wp_error( $err, 'ftvs_http' );
	assert_contains( '500', $err->get_error_message() );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_neterr() );
	assert_wp_error( FTVS_Gideo_Client::fetch_children( 'acct', FTVS_T_CAT_A ), 'http_request_failed' );
}

function test_gideo_request_carries_the_account_and_category() {
	ftvs_t_gideo();
	ftvs_t_route( 'ott.gideo.video/api/legacy', ftvs_t_gideo_xml( '' ) );
	FTVS_Gideo_Client::fetch_children( 'My-Acct_1', FTVS_T_CAT_A );
	$requests = ftvs_t_requests();
	assert_count( 1, $requests );
	assert_matches( '#^https://ott\.gideo\.video/api/legacy\?#', $requests[0]['url'] );
	parse_str( (string) wp_parse_url( $requests[0]['url'], PHP_URL_QUERY ), $q );
	assert_same(
		array(
			'cmd'        => 'getCategoryChildren',
			'AccountID'  => 'My-Acct_1',
			'CategoryID' => FTVS_T_CAT_A,
		),
		$q
	);
	assert_contains( 'FaithTVSeries/' . FTVS_VERSION, $requests[0]['args']['user-agent'] );
}

/* ---------------------------------------------------------------- stream address */

function test_gideo_video_url_picks_the_first_https_hls_stream() {
	ftvs_t_gideo();
	ftvs_t_route(
		'cmd=getVideoUrls',
		ftvs_t_json(
			array(
				'urls' => array(
					array(
						'url'          => 'http://insecure.example.test/a.m3u8',
						'streamFormat' => 'hls',
					),
					array(
						'url'          => 'https://cdn.example.test/a.mp4',
						'streamFormat' => 'mp4',
					),
					array(
						'url'          => 'https://cdn.example.test/a.m3u8',
						'streamFormat' => 'hls',
					),
					array(
						'url'          => 'https://cdn.example.test/b.m3u8',
						'streamFormat' => 'hls',
					),
				),
			)
		)
	);
	assert_same( 'https://cdn.example.test/a.m3u8', FTVS_Gideo_Client::fetch_video_url( 'v1' ) );
	parse_str( (string) wp_parse_url( ftvs_t_requests()[0]['url'], PHP_URL_QUERY ), $q );
	assert_same( 'getVideoUrls', $q['cmd'] );
	assert_same( 'v1', $q['videoId'] );
}

function test_gideo_video_url_without_a_stream() {
	ftvs_t_gideo();
	ftvs_t_route( 'cmd=getVideoUrls', ftvs_t_json( array( 'urls' => array( array( 'url' => 'https://cdn.example.test/a.mp4', 'streamFormat' => 'mp4' ) ) ) ) );
	assert_wp_error( FTVS_Gideo_Client::fetch_video_url( 'v1' ), 'ftvs_no_stream' );
	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'cmd=getVideoUrls', ftvs_t_text( 'not json' ) );
	assert_wp_error( FTVS_Gideo_Client::fetch_video_url( 'v2' ), 'ftvs_no_stream' );
	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'cmd=getVideoUrls', ftvs_t_text( 'nope', 404 ) );
	assert_wp_error( FTVS_Gideo_Client::fetch_video_url( 'v3' ), 'ftvs_http' );
}

function test_gideo_get_video_is_just_the_stream() {
	ftvs_t_gideo();
	ftvs_t_route( 'cmd=getVideoUrls', ftvs_t_json( array( 'urls' => array( array( 'url' => 'https://cdn.example.test/a.m3u8', 'streamFormat' => 'hls' ) ) ) ) );
	$video = FTVS_Gideo_Client::get_video( 'v1' );
	assert_same( 'https://cdn.example.test/a.m3u8', $video['hls'] );
	assert_same( 'v1', $video['id'] );
	assert_same( array(), $video['related'] );
	assert_same( array(), $video['series'] );
}

/* ---------------------------------------------------------------- tree */

function test_gideo_fetch_tree_adds_one_level_of_children() {
	ftvs_t_gideo();
	$home = '<category id="' . FTVS_T_CAT_A . '"><title>Series</title><fhdimage></fhdimage><image></image><videos>0</videos><subcategories>2</subcategories></category>'
		. '<category id="' . FTVS_T_CAT_B . '"><title>Kids</title><image>https://cdn.gideo.video/img/kids.jpg</image><videos>4</videos><subcategories>0</subcategories></category>';
	$kids = '<category id="' . FTVS_T_CAT_C . '"><title>First series</title><image>https://cdn.gideo.video/img/first.jpg</image><videos>3</videos><subcategories>0</subcategories></category>'
		. '<category id="dddddddddddddddddddddddddddddddd"><title>Second</title><image>https://cdn.gideo.video/img/second.jpg</image><videos>2</videos><subcategories>0</subcategories></category>';
	ftvs_t_route( 'CategoryID=' . FTVS_T_CAT_A, ftvs_t_gideo_xml( $kids ) );
	ftvs_t_route(
		function ( $url ) {
			return (bool) preg_match( '/CategoryID=?$/', $url ); // WordPress drops the "=" of an empty value
		},
		ftvs_t_gideo_xml( $home )
	);
	$tree = FTVS_Gideo_Client::fetch_tree();
	assert_not_error( $tree );
	assert_count( 2, $tree );
	assert_same( FTVS_T_CAT_A, $tree[0]['id'] );
	assert_count( 2, $tree[0]['children'] );
	assert_same( 2, $tree[0]['subcategories'] );
	assert_same( '', $tree[0]['style'] );
	assert_same( 'https://cdn.gideo.video/img/first.jpg', $tree[0]['image'], 'a row with no picture uses its first series\'' );
	assert_same( array(), $tree[1]['children'] );
	assert_same( 'https://cdn.gideo.video/img/kids.jpg', $tree[1]['image'] );
	assert_count( 2, ftvs_t_requests(), 'one request for the home rows and one for the row that holds series' );
}

function test_gideo_fetch_tree_gives_up_on_a_half_list() {
	ftvs_t_gideo();
	$home = '<category id="' . FTVS_T_CAT_A . '"><title>Series</title><image></image><videos>0</videos><subcategories>2</subcategories></category>';
	ftvs_t_route( 'CategoryID=' . FTVS_T_CAT_A, ftvs_t_text( 'boom', 500 ) );
	ftvs_t_route(
		function ( $url ) {
			return (bool) preg_match( '/CategoryID=?$/', $url ); // WordPress drops the "=" of an empty value
		},
		ftvs_t_gideo_xml( $home )
	);
	assert_wp_error( FTVS_Gideo_Client::fetch_tree(), 'ftvs_http', 'a failing row fails the whole tree, so the last complete one is served' );
}

/* ---------------------------------------------------------------- connecting */

function test_gideo_lookup_domain() {
	ftvs_t_route( 'cmd=getSettings', ftvs_t_json( array( 'accountId' => 'Grace Church-1!', 'title' => ' <b>Grace</b> Church ' ) ) );
	ftvs_t_route( 'cmd=getCategoryChildren', ftvs_t_gideo_listing() );
	$found = FTVS_Gideo_Client::lookup_domain( ' HTTPS://TV.Grace.Church/watch?x=1#top ' );
	assert_not_error( $found );
	assert_same( 'gideo', $found['source'] );
	assert_same( 'GraceChurch-1', $found['account_id'] );
	assert_same( 'https://tv.grace.church', $found['tv_url'] );
	assert_same( 'Grace Church', $found['church_name'] );
	assert_same( 'https://tv.grace.church/webtv-assets/logo.png', $found['church_logo'] );
	assert_count( 2, $found['rows'] );

	$requests = ftvs_t_requests();
	parse_str( (string) wp_parse_url( $requests[0]['url'], PHP_URL_QUERY ), $q );
	assert_same( 'tv.grace.church', $q['domain'] );
	parse_str( (string) wp_parse_url( $requests[1]['url'], PHP_URL_QUERY ), $q );
	assert_same( 'GraceChurch-1', $q['AccountID'] );
}

function test_gideo_lookup_domain_uses_the_host_when_gideo_sends_no_title() {
	ftvs_t_route( 'cmd=getSettings', ftvs_t_json( array( 'accountId' => 'abc' ) ) );
	ftvs_t_route( 'cmd=getCategoryChildren', ftvs_t_text( 'down', 503 ) );
	$found = FTVS_Gideo_Client::lookup_domain( 'tv.grace.church' );
	assert_same( 'tv.grace.church', $found['church_name'] );
	assert_same( array(), $found['rows'], 'the church is still found when its rows cannot be read' );
}

function test_gideo_lookup_domain_bad_input_never_reaches_the_network() {
	foreach ( array( '', 'localhost', 'not a host', 'tv_church.com', 'tv.church.c', 'http://', "evil.com\nHost: x", '999' ) as $bad ) {
		assert_wp_error( FTVS_Gideo_Client::lookup_domain( $bad ), 'ftvs_lookup', $bad );
	}
	assert_count( 0, ftvs_t_requests() );
}

function test_gideo_lookup_domain_unknown_site() {
	ftvs_t_route( 'cmd=getSettings', ftvs_t_json( array( 'error' => 'unknown domain' ) ) );
	$err = FTVS_Gideo_Client::lookup_domain( 'tv.nowhere.church' );
	assert_wp_error( $err, 'ftvs_lookup' );
	assert_contains( 'tv.nowhere.church', $err->get_error_message() );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'cmd=getSettings', ftvs_t_neterr() );
	assert_wp_error( FTVS_Gideo_Client::lookup_domain( 'tv.nowhere.church' ), 'ftvs_lookup' );
}

function test_gideo_lookup_account() {
	ftvs_t_route( 'cmd=getCategoryChildren', ftvs_t_gideo_listing() );
	$found = FTVS_Gideo_Client::lookup_account( ' Faith-Tabernacle_1 !' );
	assert_not_error( $found );
	assert_same( 'Faith-Tabernacle_1', $found['account_id'] );
	assert_same( '', $found['tv_url'] );
	assert_same( 'Faith-Tabernacle_1', $found['church_name'] );
	assert_count( 2, $found['rows'] );
}

function test_gideo_lookup_account_failures() {
	assert_wp_error( FTVS_Gideo_Client::lookup_account( '' ), 'ftvs_lookup' );
	assert_wp_error( FTVS_Gideo_Client::lookup_account( '!!!' ), 'ftvs_lookup' );
	assert_count( 0, ftvs_t_requests(), 'nothing left of the id after cleaning: no request' );

	ftvs_t_route( 'cmd=getCategoryChildren', ftvs_t_gideo_xml( '' ) );
	assert_wp_error( FTVS_Gideo_Client::lookup_account( 'valid-but-empty' ), 'ftvs_lookup', 'an account with no categories did not work' );
	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'cmd=getCategoryChildren', ftvs_t_text( 'no', 500 ) );
	assert_wp_error( FTVS_Gideo_Client::lookup_account( 'valid-but-broken' ), 'ftvs_lookup' );
}

/* ---------------------------------------------------------------- ids and links */

function test_gideo_is_id() {
	assert_true( FTVS_Gideo_Client::is_id( FTVS_T_CAT_A ) );
	assert_true( FTVS_Gideo_Client::is_id( '0123456789ABCDEFabcdef0123456789' ), 'hex in either case' );
	foreach ( array( '', 'abc', str_repeat( 'a', 31 ), str_repeat( 'a', 33 ), str_repeat( 'g', 32 ), 'demo-hope-rising' ) as $bad ) {
		assert_false( FTVS_Gideo_Client::is_id( $bad ), $bad );
	}
}

function test_gideo_links_need_a_tv_website() {
	ftvs_t_gideo( array( 'tv_url' => 'https://tv.grace.church/' ) );
	assert_same( 'https://tv.grace.church/program-group/' . FTVS_T_CAT_A, FTVS_Gideo_Client::category_link( FTVS_T_CAT_A ) );
	assert_same( 'https://tv.grace.church/program-group/' . FTVS_T_CAT_A . '/program/v1', FTVS_Gideo_Client::video_link( 'v1', FTVS_T_CAT_A ) );

	ftvs_t_gideo( array( 'tv_url' => '' ) );
	assert_same( '', FTVS_Gideo_Client::category_link( FTVS_T_CAT_A ), 'connected by account id only: no channel address, so cards open the player' );
	assert_same( '', FTVS_Gideo_Client::video_link( 'v1', FTVS_T_CAT_A ) );
}
