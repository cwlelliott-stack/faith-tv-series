<?php
/**
 * FTVS_Podcast: the podcast feed built from the messages that have audio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A Faith Stream church with three messages in one category; $with_audio lists the slugs that
 * have an audio file. Message 1 has characters that must be escaped in XML.
 */
function ftvs_t_podcast_church( $with_audio = array( 'msg-1', 'msg-3' ), $settings = array() ) {
	ftvs_t_faithstream(
		array_merge(
			array(
				'church_name' => 'Grace & Truth Church',
				'church_logo' => 'https://cdn.example.test/logo.png',
				'podcast'     => 1,
			),
			$settings
		)
	);
	ftvs_t_route(
		'/api/public/home',
		ftvs_t_json(
			array(
				'rows' => array(
					array(
						'category' => array(
							'slug' => 'sermons',
							'name' => 'Sermons',
						),
						'style'    => 'tiles',
						'videos'   => array(),
						'children' => array(),
					),
				),
			)
		)
	);
	$videos = array(
		array(
			'slug'          => 'msg-1',
			'title'         => 'Grace & Truth <Part 1>',
			'description'   => 'Faith, hope & "love" <b>today</b>',
			'thumbnail_url' => 'https://img.example.test/1.jpg',
			'duration_s'    => 1815.4,
			'published_at'  => '2026-09-27T16:00:00Z',
			'speaker'       => 'Pastor Sam & Co',
		),
		array(
			'slug'         => 'msg-2',
			'title'        => 'Message Two',
			'published_at' => '2026-09-20T16:00:00Z',
		),
		array(
			'slug'         => 'msg-3',
			'title'        => 'Message Three',
			'published_at' => '2026-09-13T16:00:00Z',
		),
	);
	ftvs_t_route( 'categories/_m', ftvs_t_json( ftvs_t_fs_category_page( '_msall', array(), 0 ) ) ); // series built by hand, if the site has any
	ftvs_t_route( 'categories/sermons?', ftvs_t_json( ftvs_t_fs_category_page( 'sermons', $videos, 3 ) ) );
	foreach ( $videos as $video ) {
		$body = array(
			'video' => array(
				'slug'    => $video['slug'],
				'title'   => $video['title'],
				'hls_url' => 'https://stream.mux.test/' . $video['slug'] . '.m3u8',
			),
		);
		if ( in_array( $video['slug'], $with_audio, true ) ) {
			$body['video']['audio_url'] = 'https://cdn.example.test/audio/' . $video['slug'] . '.m4a';
		}
		ftvs_t_route( '/api/public/videos/' . $video['slug'], ftvs_t_json( $body ) );
	}
}

function ftvs_t_podcast_xml() {
	return ftvs_t_private( 'FTVS_Podcast', 'build' );
}

/** @return SimpleXMLElement */
function ftvs_t_parse_feed( $xml ) {
	$previous = libxml_use_internal_errors( true );
	$feed     = simplexml_load_string( $xml );
	$errors   = libxml_get_errors();
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	assert_true( false !== $feed, 'the feed is well-formed XML: ' . ( $errors ? trim( $errors[0]->message ) : '' ) );
	return $feed;
}

/* ---------------------------------------------------------------- episodes */

function test_podcast_episodes_are_the_messages_that_have_audio() {
	ftvs_t_podcast_church();
	$episodes = FTVS_Podcast::episodes();
	assert_same( array( 'msg-1', 'msg-3' ), wp_list_pluck( $episodes, 'id' ), 'newest first; the one without audio is left out' );
	assert_same( 'https://cdn.example.test/audio/msg-1.m4a', $episodes[0]['audio'] );
}

function test_podcast_episodes_stop_at_the_limit() {
	ftvs_t_podcast_church( array( 'msg-1', 'msg-2', 'msg-3' ) );
	assert_count( 2, FTVS_Podcast::episodes( 2 ) );
}

function test_podcast_episodes_none_when_nothing_has_audio_or_nothing_is_connected() {
	ftvs_t_podcast_church( array() );
	assert_same( array(), FTVS_Podcast::episodes() );
	ftvs_t_settings( array() );
	assert_same( array(), FTVS_Podcast::episodes() );
}

function test_podcast_episodes_only_look_at_a_limited_number_of_videos_without_audio() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array( array( 'category' => array( 'slug' => 'big', 'name' => 'Big' ), 'style' => 'tiles', 'videos' => array(), 'children' => array() ) ) ) ) );
	ftvs_t_route( 'categories/_m', ftvs_t_json( ftvs_t_fs_category_page( '_msall', array(), 0 ) ) );
	ftvs_t_route( 'categories/big?', ftvs_t_json( ftvs_t_fs_category_page( 'big', ftvs_t_fs_videos( 'b', 1, 100 ), 100 ) ) );
	ftvs_t_route( '/api/public/videos/', ftvs_t_json( array( 'video' => array( 'slug' => 'x', 'title' => 'x' ) ) ) );
	assert_same( array(), FTVS_Podcast::episodes( 5 ) );
	assert_count( 10, ftvs_t_requests( '/api/public/videos/' ), 'no audio on the ten newest: the channel makes no audio, so it stops there instead of asking about hundreds of videos' );
}

/* ---------------------------------------------------------------- the feed */

function test_podcast_feed_is_valid_xml_with_the_channel_details() {
	ftvs_t_podcast_church( array( 'msg-1', 'msg-3' ), array( 'podcast_author' => 'Pastor Sam' ) );
	$xml  = ftvs_t_podcast_xml();
	$feed = ftvs_t_parse_feed( $xml );
	assert_matches( '/^<\?xml version="1.0" encoding="UTF-8"\?>/', $xml );
	assert_same( '2.0', (string) $feed['version'] );
	$channel = $feed->channel;
	assert_same( 'Grace & Truth Church', (string) $channel->title, 'no podcast title set: the church\'s name' );
	assert_same( home_url( '/' ), (string) $channel->link );
	assert_same( 'Messages from Grace & Truth Church.', (string) $channel->description );
	assert_same( str_replace( '_', '-', get_locale() ), (string) $channel->language );
	$itunes = $channel->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
	assert_same( 'Pastor Sam', (string) $itunes->author );
	assert_same( 'false', (string) $itunes->explicit );
	assert_same( 'https://cdn.example.test/logo.png', (string) $itunes->image->attributes()['href'], 'no podcast picture set: the church logo' );
	assert_same( 'Religion & Spirituality', (string) $itunes->category->attributes()['text'] );
	$atom = $channel->children( 'http://www.w3.org/2005/Atom' );
	assert_same( FTVS_Podcast::url(), (string) $atom->link->attributes()['href'] );
	assert_same( 'self', (string) $atom->link->attributes()['rel'] );
}

function test_podcast_feed_uses_the_podcast_settings_when_set() {
	ftvs_t_podcast_church(
		array( 'msg-1' ),
		array(
			'podcast_title'  => 'The Grace Podcast',
			'podcast_author' => 'Pastor Sam <Sam>',
			'podcast_image'  => 'https://cdn.example.test/podcast.jpg',
		)
	);
	$feed   = ftvs_t_parse_feed( ftvs_t_podcast_xml() );
	$itunes = $feed->channel->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
	assert_same( 'The Grace Podcast', (string) $feed->channel->title );
	assert_same( 'Pastor Sam <Sam>', (string) $itunes->author );
	assert_same( 'https://cdn.example.test/podcast.jpg', (string) $itunes->image->attributes()['href'] );
}

function test_podcast_feed_leaves_out_the_picture_when_there_is_none() {
	ftvs_t_podcast_church( array( 'msg-1' ), array( 'church_logo' => '' ) );
	$xml = ftvs_t_podcast_xml();
	$feed = ftvs_t_parse_feed( $xml );
	assert_same( 0, count( $feed->channel->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' )->image ), 'an itunes:image with no address would fail Apple\'s validation' );
}

function test_podcast_feed_items() {
	ftvs_t_podcast_church();
	$feed  = ftvs_t_parse_feed( ftvs_t_podcast_xml() );
	$items = $feed->channel->item;
	assert_same( 2, count( $items ) );

	$first  = $items[0];
	$itunes = $first->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
	assert_same( 'Grace & Truth <Part 1>', (string) $first->title, 'special characters survive escaping' );
	assert_same( 'faith-tv:msg-1', (string) $first->guid );
	assert_same( 'false', (string) $first->guid['isPermaLink'] );
	assert_same( 'Faith, hope & "love" <b>today</b>', (string) $first->description );
	assert_same( 'https://cdn.example.test/audio/msg-1.m4a', (string) $first->enclosure['url'] );
	assert_same( 'audio/mp4', (string) $first->enclosure['type'] );
	assert_same( '1815', (string) $itunes->duration );
	assert_same( 'https://img.example.test/1.jpg', (string) $itunes->image->attributes()['href'] );
	assert_same( 'Pastor Sam & Co', (string) $itunes->author );
	assert_same( 'Sun, 27 Sep 2026 16:00:00 +0000', (string) $first->pubDate );
	assert_same( 'faith-tv:msg-3', (string) $items[1]->guid );
	assert_same( 'Message Three', (string) $items[1]->title );
	assert_same( 'Message Three', (string) $items[1]->description, 'no description: the title' );
	assert_same( 0, count( $items[1]->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' )->duration ), 'no length known: no duration' );
}

function test_podcast_feed_items_link_to_their_message_page() {
	$page = ftvs_t_page();
	ftvs_t_podcast_church( array( 'msg-1' ), array( 'watch_page_id' => $page->ID ) );
	ftvs_t_permalinks( '/%postname%/' );
	$feed = ftvs_t_parse_feed( ftvs_t_podcast_xml() );
	assert_same( get_permalink( $page ), (string) $feed->channel->link, 'the channel links to the Watch page' );
	assert_same( FTVS_Watch::url( 'msg-1' ), (string) $feed->channel->item[0]->link );
}

function test_podcast_feed_without_episodes_is_still_a_valid_feed() {
	ftvs_t_podcast_church( array() );
	$feed = ftvs_t_parse_feed( ftvs_t_podcast_xml() );
	assert_same( 0, count( $feed->channel->item ) );
	assert_same( 'Grace & Truth Church', (string) $feed->channel->title );
}

/* ---------------------------------------------------------------- registration, caching */

function test_podcast_feed_is_registered() {
	global $wp_rewrite;
	assert_in_array( FTVS_Podcast::FEED, $wp_rewrite->feeds );
	assert_contains( 'faith-tv', FTVS_Podcast::url() );
}

function test_podcast_forget_clears_the_cached_feed_and_runs_for_new_videos() {
	set_transient( FTVS_Podcast::CACHE, '<rss/>', HOUR_IN_SECONDS );
	FTVS_Podcast::forget();
	assert_false( get_transient( FTVS_Podcast::CACHE ) );
	assert_true( false !== has_action( 'ftvs_new_videos', array( 'FTVS_Podcast', 'forget' ) ), 'a new video makes the feed rebuild' );
}

function test_podcast_background_build_looks_up_each_audio_size_once() {
	delete_option( FTVS_Podcast::SIZES );
	ftvs_t_podcast_church();
	ftvs_t_route( 'cdn.example.test/audio/msg-1.m4a', ftvs_t_text( '', 200, array( 'content-length' => '12345678' ) ) );
	ftvs_t_route( 'cdn.example.test/audio/msg-3.m4a', ftvs_t_text( '', 404 ) );
	$feed = ftvs_t_parse_feed( ftvs_t_podcast_xml() );
	assert_same( '0', (string) $feed->channel->item[0]->enclosure['length'], 'a visitor\'s request never waits for sizes' );
	assert_count( 0, ftvs_t_requests( 'cdn.example.test/audio/' ) );

	FTVS_Podcast::rebuild();
	$feed = ftvs_t_parse_feed( get_transient( FTVS_Podcast::CACHE ) );
	assert_same( '12345678', (string) $feed->channel->item[0]->enclosure['length'], 'the background build asks the audio host' );
	assert_same( '0', (string) $feed->channel->item[1]->enclosure['length'], 'not found: unknown' );
	assert_count( 2, ftvs_t_requests( 'cdn.example.test/audio/' ) );

	FTVS_Podcast::rebuild();
	assert_count( 3, ftvs_t_requests( 'cdn.example.test/audio/' ), 'a known size is not asked again (only the unknown one)' );
	$feed = ftvs_t_parse_feed( ftvs_t_podcast_xml() );
	assert_same( '12345678', (string) $feed->channel->item[0]->enclosure['length'], 'and the saved size is used everywhere' );
	delete_option( FTVS_Podcast::SIZES );
	delete_transient( FTVS_Podcast::CACHE );
}
