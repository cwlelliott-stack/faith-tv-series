<?php
/**
 * FTVS_Live: when the next service is, whether "now" counts as live, and how a pasted live
 * link is played. Plus the Live block and the "We're live" bar built on it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ftvs_t_utc( $y, $m, $d, $h = 0, $i = 0 ) {
	return gmmktime( $h, $i, 0, $m, $d, $y );
}

/* ---------------------------------------------------------------- next_service */

function test_live_next_service_none_configured() {
	assert_same( 0, FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 1, 12 ) ) );
}

function test_live_next_service_this_sunday() {
	ftvs_t_timezone( 'UTC' );
	ftvs_t_settings(
		array(
			'services'       => array(
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
			'service_length' => 90,
		)
	);
	$sunday = ftvs_t_utc( 2026, 10, 4, 10, 30 );
	assert_same( $sunday, FTVS_Live::next_service( ftvs_t_utc( 2026, 9, 30, 12 ) ), 'from Wednesday' );
	assert_same( $sunday, FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 3, 23, 59 ) ), 'from Saturday night' );
	assert_same( $sunday, FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 0, 0 ) ), 'from Sunday midnight' );
	assert_same( $sunday, FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 10, 0 ) ), 'half an hour before' );
}

function test_live_next_service_a_service_in_progress_still_counts() {
	ftvs_t_timezone( 'UTC' );
	ftvs_t_settings(
		array(
			'services'       => array(
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
			'service_length' => 90,
		)
	);
	$sunday = ftvs_t_utc( 2026, 10, 4, 10, 30 );
	assert_same( $sunday, FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 10, 30 ) ), 'the minute it starts' );
	assert_same( $sunday, FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 11, 59 ) ), 'a minute before it ends' );
	assert_same( ftvs_t_utc( 2026, 10, 11, 10, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 12, 0 ) ), 'the moment it ends: next week' );
	assert_same( ftvs_t_utc( 2026, 10, 11, 10, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 5, 8, 0 ) ), 'Monday' );
}

function test_live_next_service_uses_the_service_length() {
	ftvs_t_timezone( 'UTC' );
	ftvs_t_settings(
		array(
			'services'       => array(
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
			'service_length' => 30,
		)
	);
	assert_same( ftvs_t_utc( 2026, 10, 4, 10, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 10, 59 ) ) );
	assert_same( ftvs_t_utc( 2026, 10, 11, 10, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 11, 0 ) ), '30 minutes long: over at 11:00' );
}

function test_live_next_service_picks_the_soonest_of_several() {
	ftvs_t_timezone( 'UTC' );
	ftvs_t_settings(
		array(
			'services'       => array(
				array(
					'day'  => 3,
					'time' => '19:00',
				),
				array(
					'day'  => 0,
					'time' => '12:30',
				),
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
			'service_length' => 90,
		)
	);
	assert_same( ftvs_t_utc( 2026, 10, 4, 10, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 1, 12 ) ), 'Thursday: Sunday morning is next' );
	assert_same( ftvs_t_utc( 2026, 10, 4, 10, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 11, 0 ) ), 'during the first service' );
	assert_same( ftvs_t_utc( 2026, 10, 4, 12, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 12, 0 ) ), 'between the two Sunday services' );
	assert_same( ftvs_t_utc( 2026, 10, 7, 19, 0 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 14, 30 ) ), 'after the last Sunday service: Wednesday evening' );
	assert_same( ftvs_t_utc( 2026, 10, 7, 19, 0 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 7, 8, 0 ) ), 'Wednesday morning' );
	assert_same( ftvs_t_utc( 2026, 10, 11, 10, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 7, 21, 0 ) ), 'after Wednesday evening: next Sunday' );
}

function test_live_next_service_is_in_the_sites_time_zone() {
	ftvs_t_timezone( 'America/Chicago' ); // UTC-5 until Nov 1 2026, UTC-6 after
	ftvs_t_settings(
		array(
			'services'       => array(
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
			'service_length' => 90,
		)
	);
	assert_same( ftvs_t_utc( 2026, 10, 4, 15, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 14, 0 ) ), '09:00 in Chicago on Sunday' );
	assert_same( ftvs_t_utc( 2026, 10, 4, 15, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 3, 0 ) ), 'still Saturday night in Chicago, so the same Sunday' );
	assert_same( ftvs_t_utc( 2026, 10, 11, 15, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 4, 18, 0 ) ), '13:00 in Chicago: the service is over' );
}

function test_live_next_service_keeps_the_clock_time_across_a_daylight_saving_change() {
	ftvs_t_timezone( 'America/Chicago' ); // clocks go back on Sunday 2026-11-01
	ftvs_t_settings(
		array(
			'services'       => array(
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
			'service_length' => 90,
		)
	);
	assert_same( ftvs_t_utc( 2026, 11, 1, 16, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 28, 12 ) ), '10:30 is 16:30 UTC once winter time starts' );
	assert_same( ftvs_t_utc( 2026, 10, 25, 15, 30 ), FTVS_Live::next_service( ftvs_t_utc( 2026, 10, 21, 12 ) ), 'and 15:30 UTC the week before' );
}

function test_live_next_service_defaults_to_now() {
	ftvs_t_settings(
		array(
			'services'       => array(
				array( 'day' => 0, 'time' => '12:00' ),
				array( 'day' => 1, 'time' => '12:00' ),
				array( 'day' => 2, 'time' => '12:00' ),
				array( 'day' => 3, 'time' => '12:00' ),
				array( 'day' => 4, 'time' => '12:00' ),
				array( 'day' => 5, 'time' => '12:00' ),
				array( 'day' => 6, 'time' => '12:00' ),
			),
			'service_length' => 60,
		)
	);
	$next = FTVS_Live::next_service();
	assert_true( $next > time() - 3600, 'never further back than the length of a service' );
	assert_true( $next <= time() + DAY_IN_SECONDS + 3600, 'a service every day: at most a day away' );
}

/* ---------------------------------------------------------------- manual_player */

function test_live_manual_player_hls() {
	assert_same(
		array(
			'kind' => 'hls',
			'src'  => 'https://live.example.test/church/stream.m3u8',
			'id'   => '',
		),
		FTVS_Live::manual_player( 'https://live.example.test/church/stream.m3u8' )
	);
	$play = FTVS_Live::manual_player( 'https://live.example.test/x/index.M3U8?token=abc' );
	assert_same( 'hls', $play['kind'], 'the extension is checked on the path, not the query, and in any case' );
}

function test_live_manual_player_only_https() {
	assert_same( null, FTVS_Live::manual_player( 'http://live.example.test/stream.m3u8' ) );
	assert_same( null, FTVS_Live::manual_player( 'javascript:alert(1)' ) );
	assert_same( null, FTVS_Live::manual_player( '//live.example.test/stream.m3u8' ) );
	assert_same( null, FTVS_Live::manual_player( 'live.example.test/stream.m3u8' ) );
	assert_same( null, FTVS_Live::manual_player( '' ) );
	assert_same( null, FTVS_Live::manual_player( 'data:text/html,<script>alert(1)</script>' ) );
}

function test_live_manual_player_youtube_links() {
	$id   = 'dQw4w9WgXcQ';
	$want = array(
		'kind' => 'embed',
		'src'  => FTVS_YouTube_Client::embed_url( $id ),
		'id'   => '',
	);
	foreach ( array(
		'https://www.youtube.com/watch?v=' . $id,
		'https://www.youtube.com/watch?feature=share&v=' . $id,
		'https://youtube.com/watch?v=' . $id . '&t=30s',
		'https://m.youtube.com/watch?v=' . $id,
		'https://www.youtube.com/live/' . $id . '?feature=share',
		'https://youtu.be/' . $id,
		'https://youtu.be/' . $id . '?si=abc',
		'https://www.youtube.com/embed/' . $id,
	) as $url ) {
		assert_same( $want, FTVS_Live::manual_player( $url ), $url );
	}
}

function test_live_manual_player_youtube_channel_live_stream() {
	$uc = 'UC1234567890abcdefghij_-';
	assert_same(
		array(
			'kind' => 'embed',
			'src'  => 'https://www.youtube-nocookie.com/embed/live_stream?autoplay=1&channel=' . $uc,
			'id'   => '',
		),
		FTVS_Live::manual_player( 'https://www.youtube.com/channel/' . $uc )
	);
}

function test_live_manual_player_youtube_links_it_cannot_play() {
	assert_same( null, FTVS_Live::manual_player( 'https://www.youtube.com/@GraceChurch' ), 'a handle needs a lookup, so it cannot be embedded here' );
	assert_same( null, FTVS_Live::manual_player( 'https://www.youtube.com/playlist?list=PLrEnWoR732-BHrPp_Pm8_VleD68f9s14-' ) );
	assert_same( null, FTVS_Live::manual_player( 'https://www.youtube.com/' ) );
}

function test_live_manual_player_vimeo() {
	assert_same(
		array(
			'kind' => 'embed',
			'src'  => 'https://vimeo.com/event/123456/embed?autoplay=1',
			'id'   => '',
		),
		FTVS_Live::manual_player( 'https://vimeo.com/event/123456' ),
		'a Vimeo live event'
	);
	assert_same( 'https://vimeo.com/event/987/embed?autoplay=1', FTVS_Live::manual_player( 'https://vimeo.com/event/987/embed' )['src'] );
	assert_same( 'https://player.vimeo.com/video/76979871?autoplay=1', FTVS_Live::manual_player( 'https://vimeo.com/76979871' )['src'], 'a normal Vimeo video' );
}

function test_live_manual_player_other_players_embed_the_address_itself() {
	$url  = 'https://boxcast.tv/view/grace-church-sunday?autoplay=1';
	assert_same(
		array(
			'kind' => 'embed',
			'src'  => $url,
			'id'   => '',
		),
		FTVS_Live::manual_player( $url )
	);
}

function test_live_manual_player_lookalike_youtube_hosts_are_not_youtube() {
	$fake = FTVS_Live::manual_player( 'https://youtube.com.evil.example.test/watch?v=dQw4w9WgXcQ' );
	assert_same( 'https://youtube.com.evil.example.test/watch?v=dQw4w9WgXcQ', $fake['src'], 'treated as any other player address, never as YouTube' );
	$fake = FTVS_Live::manual_player( 'https://notyoutube.com/watch?v=dQw4w9WgXcQ' );
	assert_not_contains( 'youtube-nocookie', $fake['src'] );
}

/* ---------------------------------------------------------------- state() without a platform */

function test_live_state_with_nothing_set_up() {
	$state = FTVS_Live::state();
	assert_same( 'idle', $state['status'] );
	assert_same( '', $state['next'] );
	assert_same( '', $state['next_label'] );
	assert_same( null, $state['play'] );
	assert_same( null, $state['replay'] );
	assert_same( 'Sunday service', $state['title'] );
	assert_same( 0, $state['viewers'] );
	foreach ( array( 'status', 'title', 'image', 'next', 'next_label', 'viewers', 'chat', 'link', 'play', 'replay' ) as $key ) {
		assert_has_key( $key, $state );
	}
}

function test_live_state_shows_the_next_service() {
	ftvs_t_timezone( 'UTC' );
	ftvs_t_settings(
		array(
			'services' => array(
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
		)
	);
	$state = FTVS_Live::state();
	assert_same( 'idle', $state['status'] );
	assert_not_same( '', $state['next'] );
	assert_true( false !== strtotime( $state['next'] ), 'an ISO 8601 time' );
	assert_same( 0, (int) wp_date( 'w', strtotime( $state['next'] ) ) );
	assert_contains( 'Sunday', $state['next_label'] );
}

function test_live_state_pasted_link_goes_live_during_the_service() {
	$start = time() - 5 * MINUTE_IN_SECONDS;
	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( $start ) ),
			'live_url' => 'https://live.example.test/church.m3u8',
		)
	);
	$state = FTVS_Live::state();
	assert_same( 'live', $state['status'] );
	assert_same(
		array(
			'kind' => 'hls',
			'src'  => 'https://live.example.test/church.m3u8',
			'id'   => '',
		),
		$state['play']
	);
	assert_same( 'https://live.example.test/church.m3u8', $state['link'] );
}

function test_live_state_pasted_youtube_link_plays_in_the_embed() {
	$start = time() - 20 * MINUTE_IN_SECONDS;
	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( $start ) ),
			'live_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
		)
	);
	$state = FTVS_Live::state();
	assert_same( 'live', $state['status'] );
	assert_same( 'embed', $state['play']['kind'] );
	assert_contains( 'youtube-nocookie.com/embed/dQw4w9WgXcQ', $state['play']['src'] );
}

function test_live_state_people_arrive_ten_minutes_early() {
	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( time() + 5 * MINUTE_IN_SECONDS ) ),
			'live_url' => 'https://live.example.test/church.m3u8',
		)
	);
	assert_same( 'live', FTVS_Live::state()['status'], '5 minutes before the start: the stream is shown' );

	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( time() + 20 * MINUTE_IN_SECONDS ) ),
			'live_url' => 'https://live.example.test/church.m3u8',
		)
	);
	$state = FTVS_Live::state();
	assert_same( 'idle', $state['status'], '20 minutes before: not yet' );
	assert_same( null, $state['play'] );
}

function test_live_state_is_idle_after_the_service_and_without_a_link() {
	ftvs_t_settings(
		array(
			'services'       => array( ftvs_t_service_at( time() - 3 * HOUR_IN_SECONDS ) ),
			'service_length' => 90,
			'live_url'       => 'https://live.example.test/church.m3u8',
		)
	);
	assert_same( 'idle', FTVS_Live::state()['status'], 'the service ended an hour and a half ago' );

	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( time() - 5 * MINUTE_IN_SECONDS ) ),
			'live_url' => '',
		)
	);
	assert_same( 'idle', FTVS_Live::state()['status'], 'service time, but no live link to show' );

	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( time() - 5 * MINUTE_IN_SECONDS ) ),
			'live_url' => 'https://www.youtube.com/@GraceChurch',
		)
	);
	assert_same( 'idle', FTVS_Live::state()['status'], 'a link that cannot be played is not shown as live' );
}

/* ---------------------------------------------------------------- state() with a Faith Stream church */

function test_live_state_from_a_live_faith_stream_channel() {
	$tenant = ftvs_t_faithstream();
	ftvs_t_route( '/api/public/live', ftvs_t_json( array( ftvs_t_live_channel() ) ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( ftvs_t_sample_home() ) );
	$state = FTVS_Live::state();
	assert_same( 'live', $state['status'] );
	assert_same(
		array(
			'kind' => 'hls',
			'src'  => 'https://stream.mux.test/live.m3u8',
			'id'   => 'main',
		),
		$state['play']
	);
	assert_same( 37, $state['viewers'] );
	assert_same( 'Sunday Service', $state['title'] );
	assert_same( FTVS_T_FS . '/media/live.jpg', $state['image'] );
	assert_same( FTVS_T_FS . '/live/main?tenant=' . $tenant, $state['chat'], 'chat is on: the chat link is the channel page' );
	assert_same( 'outrageous-part-4', $state['replay']['id'], 'no recording of its own: the newest message' );
	assert_true( isset( $state['replay']['watch'] ) );
}

function test_live_state_channel_without_chat_or_stream() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/live', ftvs_t_json( array( ftvs_t_live_channel( array( 'chat_enabled' => false ) ) ) ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	assert_same( '', FTVS_Live::state()['chat'] );

	ftvs_t_faithstream();
	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( '/api/public/live', ftvs_t_json( array( ftvs_t_live_channel( array( 'hls_url' => '' ) ) ) ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	assert_same( 'idle', FTVS_Live::state()['status'], '"live" without a stream address cannot be played' );
}

function test_live_state_idle_channel_announces_its_scheduled_start_and_replay() {
	ftvs_t_faithstream();
	$soon = gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * HOUR_IN_SECONDS );
	ftvs_t_route(
		'/api/public/live',
		ftvs_t_json(
			array(
				ftvs_t_live_channel(
					array(
						'status'           => 'idle',
						'hls_url'          => '',
						'scheduled_at'     => $soon,
						'latest_recording' => array(
							'slug'  => 'last-sunday',
							'title' => 'Last Sunday',
						),
					)
				),
			)
		)
	);
	$state = FTVS_Live::state();
	assert_same( 'idle', $state['status'] );
	assert_same( gmdate( 'c', strtotime( $soon ) ), $state['next'], 'no service times set: the channel\'s scheduled start is the next service' );
	assert_same( 'last-sunday', $state['replay']['id'], 'the channel\'s own latest recording' );
	assert_count( 0, ftvs_t_requests( '/api/public/home' ), 'no need to look up the newest message' );
}

function test_live_state_a_scheduled_start_only_wins_when_it_is_sooner() {
	$later = time() + 3 * DAY_IN_SECONDS;
	$soon  = time() + 3 * HOUR_IN_SECONDS;
	ftvs_t_settings(
		array(
			'source'    => 'faithstream',
			'fs_url'    => FTVS_T_FS,
			'fs_tenant' => 'ut' . substr( md5( uniqid( '', true ) ), 0, 10 ),
			'services'  => array( ftvs_t_service_at( $soon ) ),
		)
	);
	ftvs_t_route( '/api/public/live', ftvs_t_json( array( ftvs_t_live_channel( array( 'status' => 'idle', 'hls_url' => '', 'scheduled_at' => gmdate( 'c', $later ) ) ) ) ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	$state = FTVS_Live::state();
	assert_true( abs( strtotime( $state['next'] ) - $soon ) < 120, 'the service in 3 hours beats a channel scheduled in 3 days' );

	$past = gmdate( 'c', time() - HOUR_IN_SECONDS );
	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( '/api/public/live', ftvs_t_json( array( ftvs_t_live_channel( array( 'status' => 'idle', 'hls_url' => '', 'scheduled_at' => $past ) ) ) ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	ftvs_t_settings(
		array(
			'source'    => 'faithstream',
			'fs_url'    => FTVS_T_FS,
			'fs_tenant' => 'ut' . substr( md5( uniqid( '', true ) ), 0, 10 ),
		)
	);
	assert_same( '', FTVS_Live::state()['next'], 'a scheduled start in the past is ignored' );
}

function test_live_state_picks_the_channel_asked_for_then_the_live_one_then_the_first() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/live',
		ftvs_t_json(
			array(
				ftvs_t_live_channel(
					array(
						'slug'    => 'main',
						'title'   => 'Main',
						'status'  => 'idle',
						'hls_url' => '',
					)
				),
				ftvs_t_live_channel(
					array(
						'slug'  => 'chapel',
						'title' => 'Chapel',
					)
				),
			)
		)
	);
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	assert_same( 'Chapel', FTVS_Live::state()['title'], 'nothing asked for: the one that is live' );
	assert_same( 'Main', FTVS_Live::state( 'main' )['title'], 'the one asked for, even when idle' );
	assert_same( 'idle', FTVS_Live::state( 'main' )['status'] );
	assert_same( 'Chapel', FTVS_Live::state( 'no-such-channel' )['title'], 'an unknown channel: the live one' );
	assert_count( 1, ftvs_t_requests( '/api/public/live' ), 'all of that from one (cached) request' );
}

function test_live_state_prefers_the_channel_chosen_in_settings() {
	ftvs_t_faithstream( array( 'live_channel' => 'main' ) );
	ftvs_t_route(
		'/api/public/live',
		ftvs_t_json(
			array(
				ftvs_t_live_channel( array( 'slug' => 'chapel', 'title' => 'Chapel' ) ),
				ftvs_t_live_channel( array( 'slug' => 'main', 'title' => 'Main', 'status' => 'idle', 'hls_url' => '' ) ),
			)
		)
	);
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	assert_same( 'Main', FTVS_Live::state()['title'] );
}

function test_live_state_survives_the_platform_being_down() {
	ftvs_t_faithstream(
		array(
			'services' => array( ftvs_t_service_at( time() + 2 * HOUR_IN_SECONDS ) ),
		)
	);
	ftvs_t_route( '/api/public/live', ftvs_t_neterr() );
	ftvs_t_route( '/api/public/home', ftvs_t_neterr() );
	$state = FTVS_Live::state();
	assert_same( 'idle', $state['status'] );
	assert_true( '' !== $state['next'], 'the times typed into the settings still work' );
	assert_same( null, $state['replay'] );
}

/* ---------------------------------------------------------------- the Live block and the bar */

function test_live_block_needs_something_to_show() {
	ftvs_t_admin();
	$html = FTVS_Renderer::live( array() );
	assert_contains( 'ftvs-notice', $html );
	assert_contains( 'Add your service times or live link', $html );
	wp_set_current_user( 0 );
	assert_matches( '/^<!-- .*-->$/s', FTVS_Renderer::live( array() ), 'visitors only get a comment' );
}

function test_live_block_idle_with_service_times() {
	ftvs_t_timezone( 'UTC' );
	ftvs_t_settings(
		array(
			'services' => array(
				array(
					'day'  => 0,
					'time' => '10:30',
				),
			),
			'theme'    => 'light',
		)
	);
	$html = FTVS_Renderer::live( array( 'title' => 'Join us', 'eyebrow' => 'Sundays' ) );
	assert_contains( 'ftvs--live', $html );
	assert_contains( 'is-idle', $html );
	assert_contains( 'ftvs--light', $html );
	assert_contains( 'Next service: Sunday', $html );
	assert_contains( 'Join us', $html );
	assert_contains( 'Join us online', $html );
	assert_not_contains( 'data-ftvs-remind-open', $html, 'reminders are off' );
	preg_match( '/data-state="([^"]*)"/', $html, $m );
	$state = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	assert_same( 'idle', $state['status'], 'the script starts from the same state the page was built with' );
	assert_contains( 'data-replay="1"', $html );
}

function test_live_block_live_state_and_replay_switch() {
	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( time() - 5 * MINUTE_IN_SECONDS ) ),
			'live_url' => 'https://live.example.test/church.m3u8',
		)
	);
	$html = FTVS_Renderer::live( array( 'replay' => 'no' ) );
	assert_contains( 'is-live', $html );
	assert_contains( 'The service is streaming now.', $html );
	assert_contains( 'Watch live', $html );
	assert_contains( 'data-replay="0"', $html );
}

function test_live_block_remind_me_form_needs_both_switch_and_webhook() {
	$base = array( 'services' => array( ftvs_t_service_at( time() + DAY_IN_SECONDS ) ) );
	ftvs_t_settings( $base + array( 'remind' => 1, 'remind_webhook' => 'https://hooks.example.test/x' ) );
	$html = FTVS_Renderer::live( array() );
	assert_contains( 'data-ftvs-remind-open', $html );
	assert_contains( 'data-ftvs-remind', $html );
	assert_not_contains( 'data-ftvs-remind-open', FTVS_Renderer::live( array( 'remind' => 'no' ) ), 'the block can turn it off' );

	ftvs_t_settings( $base + array( 'remind' => 1, 'remind_webhook' => '' ) );
	assert_not_contains( 'data-ftvs-remind', FTVS_Renderer::live( array() ), 'on, but nowhere to send the sign-ups' );
	ftvs_t_settings( $base + array( 'remind' => 0, 'remind_webhook' => 'https://hooks.example.test/x' ) );
	assert_not_contains( 'data-ftvs-remind', FTVS_Renderer::live( array() ) );
}

function test_live_bar() {
	ftvs_t_settings(
		array(
			'live_bar'  => 1,
			'live_url'  => 'https://live.example.test/church.m3u8',
			'live_page' => 'https://example.org/live/',
		)
	);
	ob_start();
	FTVS_Live::bar();
	$html = ob_get_clean();
	assert_contains( 'data-ftvs-livebar', $html );
	assert_contains( 'data-href="https://example.org/live/"', $html );

	foreach ( array(
		'switched off'         => array( 'live_bar' => 0 ),
		'nothing to be live with' => array( 'live_url' => '' ),
		'sample videos'        => array( 'source' => 'demo' ),
	) as $why => $change ) {
		ftvs_t_settings(
			array_merge(
				array(
					'live_bar' => 1,
					'live_url' => 'https://live.example.test/church.m3u8',
				),
				$change
			)
		);
		ob_start();
		FTVS_Live::bar();
		assert_same( '', ob_get_clean(), $why );
	}

	ftvs_t_settings( array( 'live_bar' => 1, 'live_url' => 'https://live.example.test/church.m3u8' ) );
	$_GET['ftvs_embed'] = 'x';
	ob_start();
	FTVS_Live::bar();
	assert_same( '', ob_get_clean(), 'not inside an embed frame' );
}
