<?php
/**
 * The /wp-json/faith-tv/v1 routes, called in-process with rest_do_request() (no web server).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FTVS_T_REFRESH_SECRET = 'refresh-secret-only-for-tests';

/** @return WP_REST_Response */
function ftvs_t_rest( $method, $route, $params = array(), $body = null, $headers = array() ) {
	$request = new WP_REST_Request( $method, '/faith-tv/v1' . $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	if ( null !== $body ) {
		$request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
		$request->set_header( 'content-type', 'application/json' );
	}
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}
	return rest_do_request( $request );
}

function ftvs_t_refresh_secret( $secret = FTVS_T_REFRESH_SECRET ) {
	ftvs_t_add_filter(
		'pre_option_ftvs_refresh_secret',
		function () use ( $secret ) {
			return $secret;
		},
		10,
		0
	);
	delete_transient( 'ftvs_refresh_seen' );
}

/** POST /refresh the way Faith Stream does: body plus "sha256=<hmac>" in X-FaithStream-Signature. */
function ftvs_t_refresh( $payload, $secret = FTVS_T_REFRESH_SECRET, $signature = null ) {
	$body = is_string( $payload ) ? $payload : wp_json_encode( $payload );
	$sig  = null === $signature ? 'sha256=' . hash_hmac( 'sha256', $body, $secret ) : $signature;
	return ftvs_t_rest( 'POST', '/refresh', array(), $body, '' === $sig ? array() : array( 'X-FaithStream-Signature' => $sig ) );
}

function ftvs_t_ping_body( $extra = array() ) {
	return array_merge(
		array(
			'ts'    => time(),
			'event' => 'video.published',
		),
		$extra
	);
}

/* ---------------------------------------------------------------- registration */

function test_rest_routes_are_registered() {
	$routes = rest_get_server()->get_routes( 'faith-tv/v1' );
	$want   = array(
		'/faith-tv/v1/category/(?P<id>[A-Za-z0-9_-]{1,128})' => 'GET',
		'/faith-tv/v1/video/(?P<id>[A-Za-z0-9_-]{1,128})'    => 'GET',
		'/faith-tv/v1/live'                                   => 'GET',
		'/faith-tv/v1/library'                                => 'GET',
		'/faith-tv/v1/search'                                 => 'GET',
		'/faith-tv/v1/stats'                                  => 'POST',
		'/faith-tv/v1/remind'                                 => 'POST',
		'/faith-tv/v1/refresh'                                => 'POST',
		'/faith-tv/v1/ping'                                   => 'GET',
		'/faith-tv/v1/admin/categories'                       => 'GET',
	);
	foreach ( $want as $route => $method ) {
		assert_has_key( $route, $routes, $route );
		assert_true( isset( $routes[ $route ][0]['methods'][ $method ] ), $route . ' answers ' . $method );
	}
}

function test_rest_route_ids_cannot_carry_slashes_or_spaces() {
	ftvs_t_demo();
	ftvs_t_admin();
	assert_same( 404, ftvs_t_rest( 'GET', '/category/has space' )->get_status() );
	assert_same( 404, ftvs_t_rest( 'GET', '/video/a/b' )->get_status() );
	assert_same( 404, ftvs_t_rest( 'GET', '/category/' . str_repeat( 'a', 129 ) )->get_status(), 'ids are at most 128 characters' );
}

/* ---------------------------------------------------------------- ping */

function test_rest_ping() {
	$response = ftvs_t_rest( 'GET', '/ping' );
	assert_same( 200, $response->get_status() );
	assert_same(
		array(
			'ok'      => true,
			'version' => FTVS_VERSION,
		),
		$response->get_data()
	);
	assert_same( 'no-store', $response->get_headers()['Cache-Control'], 'Site Health must see the live answer, not a cached one' );
}

function test_rest_ping_needs_no_church_and_no_login() {
	assert_same( 200, ftvs_t_rest( 'GET', '/ping' )->get_status() );
	assert_false( FTVS_Catalog::connected() );
}

/* ---------------------------------------------------------------- live */

function test_rest_live_with_nothing_set_up() {
	$response = ftvs_t_rest( 'GET', '/live' );
	assert_same( 200, $response->get_status() );
	$data = $response->get_data();
	assert_same( 'idle', $data['status'] );
	assert_same( 'Sunday service', $data['title'] );
	assert_same( 'no-store', $response->get_headers()['Cache-Control'], 'live state is never cached by the browser' );
}

function test_rest_live_reports_the_stream_during_the_service() {
	ftvs_t_settings(
		array(
			'services' => array( ftvs_t_service_at( time() - 10 * MINUTE_IN_SECONDS ) ),
			'live_url' => 'https://live.example.test/church.m3u8',
		)
	);
	$data = ftvs_t_rest( 'GET', '/live' )->get_data();
	assert_same( 'live', $data['status'] );
	assert_same( 'hls', $data['play']['kind'] );
	assert_same( 'https://live.example.test/church.m3u8', $data['play']['src'] );
}

function test_rest_live_passes_a_cleaned_channel_to_the_state() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/live',
		ftvs_t_json(
			array(
				ftvs_t_live_channel( array( 'slug' => 'main', 'title' => 'Main', 'status' => 'idle', 'hls_url' => '' ) ),
				ftvs_t_live_channel( array( 'slug' => 'chapel', 'title' => 'Chapel' ) ),
			)
		)
	);
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	assert_same( 'Main', ftvs_t_rest( 'GET', '/live', array( 'channel' => 'MAIN!' ) )->get_data()['title'], 'upper case and junk are cleaned to a channel id' );
	assert_same( 'Chapel', ftvs_t_rest( 'GET', '/live' )->get_data()['title'] );
}

function test_rest_live_sample_church_is_for_editors_only() {
	ftvs_t_demo();
	$response = ftvs_t_rest( 'GET', '/live' );
	assert_same( 404, $response->get_status() );
	assert_same( 'ftvs_unknown', $response->get_data()['code'] );
	ftvs_t_admin();
	assert_same( 200, ftvs_t_rest( 'GET', '/live' )->get_status() );
}

/* ---------------------------------------------------------------- refresh (signed pings from Faith Stream) */

function test_rest_refresh_without_a_signature_is_refused() {
	ftvs_t_refresh_secret();
	$response = ftvs_t_refresh( ftvs_t_ping_body(), FTVS_T_REFRESH_SECRET, '' );
	assert_same( 401, $response->get_status() );
	assert_same( 'ftvs_bad_signature', $response->get_data()['code'] );
}

function test_rest_refresh_with_a_wrong_signature_is_refused() {
	ftvs_t_refresh_secret();
	assert_same( 401, ftvs_t_refresh( ftvs_t_ping_body(), 'a-different-secret' )->get_status(), 'signed with another secret' );
	assert_same( 401, ftvs_t_refresh( ftvs_t_ping_body(), FTVS_T_REFRESH_SECRET, 'sha256=' . str_repeat( '0', 64 ) )->get_status(), 'made-up signature' );
	assert_same( 401, ftvs_t_refresh( ftvs_t_ping_body(), FTVS_T_REFRESH_SECRET, hash_hmac( 'sha256', wp_json_encode( ftvs_t_ping_body() ), FTVS_T_REFRESH_SECRET ) )->get_status(), 'missing the "sha256=" prefix' );
}

function test_rest_refresh_signature_covers_the_exact_body() {
	ftvs_t_refresh_secret();
	$signed = wp_json_encode( ftvs_t_ping_body( array( 'event' => 'video.published' ) ) );
	$sig    = 'sha256=' . hash_hmac( 'sha256', $signed, FTVS_T_REFRESH_SECRET );
	$other  = wp_json_encode( ftvs_t_ping_body( array( 'event' => 'video.deleted' ) ) );
	assert_same( 401, ftvs_t_refresh( $other, FTVS_T_REFRESH_SECRET, $sig )->get_status() );
	assert_same( 401, ftvs_t_refresh( $signed . ' ', FTVS_T_REFRESH_SECRET, $sig )->get_status(), 'even a trailing space' );
}

function test_rest_refresh_is_refused_when_no_secret_is_set_up() {
	ftvs_t_refresh_secret( '' );
	// Somebody who knows there is no secret signs with an empty key.
	$body = wp_json_encode( ftvs_t_ping_body() );
	assert_same( 401, ftvs_t_refresh( $body, '' )->get_status(), 'an empty secret is not a secret' );
	assert_same( 401, ftvs_t_refresh( $body, 'anything' )->get_status() );
}

function test_rest_refresh_accepts_a_correctly_signed_ping() {
	ftvs_t_refresh_secret();
	$gen = (int) get_option( 'ftvs_cache_gen', 1 );
	$now = time();
	$response = ftvs_t_refresh( ftvs_t_ping_body( array( 'ts' => $now ) ) );
	assert_same( 200, $response->get_status() );
	assert_same( array( 'ok' => true ), $response->get_data() );

	assert_same( $gen + 1, (int) get_option( 'ftvs_cache_gen' ), 'every saved answer is dropped: the next visit fetches fresh ones' );
	$ping = get_option( 'ftvs_last_ping' );
	assert_true( abs( $ping['t'] - time() ) <= 3 );
	assert_same( 'video.published', $ping['event'] );
	assert_true( (bool) wp_next_scheduled( FTVS_Health::WARM_NOW ), 'the background refetch is scheduled' );
	assert_true( (bool) wp_next_scheduled( FTVS_Purge::CRON ), 'and page caches are asked to clear' );
}

function test_rest_refresh_the_same_ping_twice_is_only_acted_on_once() {
	ftvs_t_refresh_secret();
	$body = ftvs_t_ping_body( array( 'event' => 'video.updated' ) );
	assert_same( array( 'ok' => true ), ftvs_t_refresh( $body )->get_data() );
	$gen = (int) get_option( 'ftvs_cache_gen' );
	$out = ftvs_t_refresh( $body );
	assert_same( 200, $out->get_status() );
	assert_same(
		array(
			'ok'     => true,
			'repeat' => true,
		),
		$out->get_data(),
		'a retry of the same delivery'
	);
	assert_same( $gen, (int) get_option( 'ftvs_cache_gen' ), 'nothing was cleared again' );

	$next = ftvs_t_refresh( ftvs_t_ping_body( array( 'event' => 'video.updated', 'ts' => time() + 1 ) ) );
	assert_same( array( 'ok' => true ), $next->get_data(), 'a different ping is a new one' );
	assert_same( $gen + 1, (int) get_option( 'ftvs_cache_gen' ) );
}

function test_rest_refresh_stale_or_missing_time_is_refused() {
	ftvs_t_refresh_secret();
	$stale = ftvs_t_refresh( array( 'ts' => time() - HOUR_IN_SECONDS, 'event' => 'video.published' ) );
	assert_same( 400, $stale->get_status() );
	assert_same( 'ftvs_stale', $stale->get_data()['code'] );

	assert_same( 400, ftvs_t_refresh( array( 'ts' => time() + HOUR_IN_SECONDS ) )->get_status(), 'from the future too' );
	assert_same( 400, ftvs_t_refresh( array( 'event' => 'video.published' ) )->get_status(), 'no time at all' );
	assert_same( 400, ftvs_t_refresh( array( 'ts' => 0 ) )->get_status() );
	assert_same( 400, ftvs_t_refresh( 'not json' )->get_status(), 'a correctly signed body that is not JSON' );
	assert_same( 400, ftvs_t_refresh( '"a string"' )->get_status() );
	assert_same( 200, ftvs_t_refresh( array( 'ts' => time() - 590 ) )->get_status(), 'ten minutes is the limit; just inside it is fine' );
	assert_same( 400, ftvs_t_refresh( array( 'ts' => time() - 610 ) )->get_status(), 'and just outside is not' );
}

function test_rest_refresh_the_event_name_is_cleaned_before_it_is_saved() {
	ftvs_t_refresh_secret();
	ftvs_t_refresh( ftvs_t_ping_body( array( 'event' => "Video.Published<script>alert('x')</script> /x" ) ) );
	$event = get_option( 'ftvs_last_ping' )['event'];
	assert_matches( '/^[a-z0-9._-]*$/', $event, 'only lower-case letters, digits, dots, dashes and underscores' );
	assert_contains( 'video.published', $event );

	ftvs_t_refresh( array( 'ts' => time() + 2 ) );
	assert_same( '', get_option( 'ftvs_last_ping' )['event'], 'no event named: an empty one' );
}

function test_rest_refresh_only_answers_posts() {
	ftvs_t_refresh_secret();
	assert_same( 404, ftvs_t_rest( 'GET', '/refresh' )->get_status() );
}

/* ---------------------------------------------------------------- the categories and videos of a church (sample church) */

function test_rest_category_returns_the_episodes_with_links() {
	ftvs_t_demo();
	ftvs_t_admin();
	$response = ftvs_t_rest( 'GET', '/category/demo-hope-rising' );
	assert_same( 200, $response->get_status() );
	$data = $response->get_data();
	assert_same( array( 'demo-hope-rising-1', 'demo-hope-rising-2', 'demo-hope-rising-3', 'demo-hope-rising-4' ), wp_list_pluck( $data['videos'], 'id' ) );
	assert_same( 'Hope Rising', $data['self']['title'] );
	assert_same( 'demo-hope-rising', $data['self']['id'] );
	assert_same( '', $data['link'] );
	assert_has_key( 'link', $data['videos'][0] );
	assert_has_key( 'watch', $data['videos'][0] );
	assert_same( 'public, max-age=300', $response->get_headers()['Cache-Control'] );
}

function test_rest_category_of_series_returns_categories() {
	ftvs_t_demo();
	ftvs_t_admin();
	$data = ftvs_t_rest( 'GET', '/category/demo-sermon-series' )->get_data();
	assert_count( 6, $data['categories'] );
	assert_has_key( 'link', $data['categories'][0] );
	assert_same( array(), $data['videos'] );
}

function test_rest_category_unknown_ids_are_refused_without_asking_the_platform() {
	ftvs_t_demo();
	ftvs_t_admin();
	$response = ftvs_t_rest( 'GET', '/category/demo-never-heard-of-it' );
	assert_same( 404, $response->get_status() );
	assert_same( 'ftvs_unknown', $response->get_data()['code'] );
	assert_same( 404, ftvs_t_rest( 'GET', '/category/UPPER' )->get_status(), 'not an id this platform uses' );
}

function test_rest_sample_church_is_for_editors_only() {
	ftvs_t_demo();
	FTVS_Catalog::library(); // the ids are known, so only the sample rule can be what says no
	foreach ( array(
		'/category/demo-hope-rising' => array(),
		'/video/demo-hope-rising-1'  => array(),
		'/library'                   => array(),
		'/search'                    => array( 'q' => 'hope' ),
	) as $route => $params ) {
		$response = ftvs_t_rest( 'GET', $route, $params );
		assert_same( 404, $response->get_status(), $route . ' for a visitor' );
		assert_same( 'ftvs_unknown', $response->get_data()['code'], $route );
	}
	ftvs_t_admin();
	assert_same( 200, ftvs_t_rest( 'GET', '/category/demo-hope-rising' )->get_status() );
}

function test_rest_video_returns_how_to_play_it() {
	ftvs_t_demo();
	ftvs_t_admin();
	FTVS_Catalog::library(); // like the page that showed the card: the ids are known
	$response = ftvs_t_rest( 'GET', '/video/demo-hope-rising-1' );
	assert_same( 200, $response->get_status() );
	$data = $response->get_data();
	assert_same( FTVS_Demo_Client::STREAM, $data['hls'] );
	assert_same( '', $data['embed'] );
	assert_false( $data['captions'] );
	assert_same( array(), $data['related'] );
	assert_same( 'private, max-age=60', $response->get_headers()['Cache-Control'], 'signed stream addresses expire, so browsers must ask again' );
	assert_same( 'demo-hope-rising-1', $data['item']['id'], 'a player opened from a shared link also gets the title and picture' );
	assert_same( 'Hope Rising, Part 1', $data['item']['title'] );

	$fresh = ftvs_t_rest( 'GET', '/video/demo-hope-rising-1', array( 'fresh' => '1' ) )->get_data();
	assert_false( isset( $fresh['item'] ), 'opened from a card on the page: the page already has it' );
}

function test_rest_video_unknown_id() {
	ftvs_t_demo();
	ftvs_t_admin();
	FTVS_Catalog::library();
	$response = ftvs_t_rest( 'GET', '/video/demo-hope-rising-99' );
	assert_same( 404, $response->get_status() );
	assert_same( 'ftvs_unknown', $response->get_data()['code'] );
}

/* ---------------------------------------------------------------- a Faith Stream church behind the routes */

/** A Faith Stream church whose home payload is the real sample, so its ids are "known". */
function ftvs_t_rest_faithstream() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/home', ftvs_t_json( ftvs_t_sample_home() ) );
	FTVS_Catalog::newest( 500 ); // shows every home-row video on a page, so their ids become known
}

function test_rest_video_from_faith_stream_with_related_videos() {
	ftvs_t_rest_faithstream();
	ftvs_t_route(
		'/api/public/videos/outrageous-part-4',
		ftvs_t_json(
			array(
				'video'      => array(
					'slug'          => 'outrageous-part-4',
					'title'         => 'Outrageous Part 4',
					'thumbnail_url' => '/media/x.jpg',
					'hls_url'       => 'https://stream.mux.test/x.m3u8',
					'captions'      => array( 'en' ),
				),
				'categories' => array( array( 'slug' => 'outrageous', 'name' => 'Outrageous' ) ),
				'related'    => array_map(
					function ( $i ) {
						return array(
							'slug'  => 'related-' . $i,
							'title' => 'Related ' . $i,
						);
					},
					range( 1, 12 )
				),
			)
		)
	);
	$data = ftvs_t_rest( 'GET', '/video/outrageous-part-4', array( 'fresh' => 1 ) )->get_data();
	assert_same( 'https://stream.mux.test/x.m3u8', $data['hls'] );
	assert_true( $data['captions'] );
	assert_count( 8, $data['related'], 'at most eight related videos' );
	assert_has_key( 'link', $data['related'][0] );
	assert_has_key( 'watch', $data['related'][0] );
	assert_same( array( array( 'id' => 'outrageous', 'title' => 'Outrageous' ) ), $data['series'] );
}

function test_rest_video_without_a_stream_is_a_bad_gateway() {
	ftvs_t_rest_faithstream();
	ftvs_t_route( '/api/public/videos/outrageous-part-4', ftvs_t_json( array( 'video' => array( 'slug' => 'outrageous-part-4', 'title' => 'Processing' ) ) ) );
	$response = ftvs_t_rest( 'GET', '/video/outrageous-part-4', array( 'fresh' => 1 ) );
	assert_same( 502, $response->get_status() );
	assert_same( 'ftvs_no_stream', $response->get_data()['code'] );
}

function test_rest_a_removed_video_is_404_and_a_platform_error_is_502() {
	ftvs_t_rest_faithstream();
	ftvs_t_route( '/api/public/videos/outrageous-part-4', ftvs_t_text( '', 404 ) );
	ftvs_t_route( '/api/public/videos/outrageous-part-3', ftvs_t_text( 'boom', 500 ) );
	$gone = ftvs_t_rest( 'GET', '/video/outrageous-part-4', array( 'fresh' => 1 ) );
	assert_same( 404, $gone->get_status() );
	assert_same( 'ftvs_gone', $gone->get_data()['code'] );
	$err = ftvs_t_rest( 'GET', '/video/outrageous-part-3', array( 'fresh' => 1 ) );
	assert_same( 502, $err->get_status() );
	assert_same( 'ftvs_http', $err->get_data()['code'] );
}

function test_rest_category_from_faith_stream() {
	ftvs_t_rest_faithstream();
	ftvs_t_route( 'categories/kids-rock?', ftvs_t_json( ftvs_t_fs_category_page( 'kids-rock', ftvs_t_fs_videos( 'kr', 1, 3 ), 3 ) ) );
	$data = ftvs_t_rest( 'GET', '/category/kids-rock' )->get_data();
	assert_count( 3, $data['videos'] );
	assert_contains( '/browse/kids-rock?tenant=', $data['link'] );
	assert_contains( '/watch/kr-1?tenant=', $data['videos'][0]['link'] );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_rest_faithstream();
	ftvs_t_route( 'categories/', ftvs_t_neterr( 'timed out' ) );
	$response = ftvs_t_rest( 'GET', '/category/kids-rock' );
	assert_same( 502, $response->get_status(), 'the platform is not answering' );
}

/* ---------------------------------------------------------------- library and search */

function test_rest_library_is_paged_and_faceted() {
	ftvs_t_demo();
	ftvs_t_admin();
	$response = ftvs_t_rest( 'GET', '/library', array( 'category' => 'demo-rooted', 'per' => 2, 'page' => 2 ) );
	assert_same( 200, $response->get_status() );
	$data = $response->get_data();
	assert_same( 5, $data['total'] );
	assert_same( 2, $data['page'] );
	assert_same( array( 'demo-rooted-3', 'demo-rooted-4' ), wp_list_pluck( $data['videos'], 'id' ) );
	assert_has_key( 'watch', $data['videos'][0] );
	assert_same( array( 'Rev. Dana Brooks' => 5 ), $data['facets']['speaker'] );
	assert_same( array( 'Rooted' => 5 ), $data['facets']['series'] );
	assert_same( 'public, max-age=300', $response->get_headers()['Cache-Control'] );
}

function test_rest_library_filters() {
	ftvs_t_demo();
	ftvs_t_admin();
	$by_speaker = ftvs_t_rest( 'GET', '/library', array( 'category' => 'demo-sermon-series', 'speaker' => 'rev. dana brooks' ) )->get_data();
	assert_same( 9, $by_speaker['total'], 'Rooted (5) and Grace Upon Grace (4); the speaker filter ignores case' );
	$by_series = ftvs_t_rest( 'GET', '/library', array( 'series' => 'the way home' ) )->get_data();
	assert_same( 3, $by_series['total'] );
	$rooted    = ftvs_t_rest( 'GET', '/library', array( 'category' => 'demo-rooted' ) )->get_data()['videos'];
	$this_year = (int) substr( $rooted[0]['added'], 0, 4 );
	$in_year   = count(
		array_filter(
			$rooted,
			function ( $v ) use ( $this_year ) {
				return (int) substr( $v['added'], 0, 4 ) === $this_year;
			}
		)
	);
	assert_same( $in_year, ftvs_t_rest( 'GET', '/library', array( 'category' => 'demo-rooted', 'year' => $this_year ) )->get_data()['total'], 'the parts dated in that year' );
	assert_same( 0, ftvs_t_rest( 'GET', '/library', array( 'category' => 'demo-rooted', 'year' => 1999 ) )->get_data()['total'] );
}

function test_rest_library_search_and_page_size_limits() {
	ftvs_t_demo();
	ftvs_t_admin();
	$data = ftvs_t_rest( 'GET', '/library', array( 'q' => 'hope rising' ) )->get_data();
	assert_same( 4, $data['total'], 'every word must match' );
	assert_same( 0, ftvs_t_rest( 'GET', '/library', array( 'q' => 'zzzzzz nothing' ) )->get_data()['total'] );
	$big = ftvs_t_rest( 'GET', '/library', array( 'per' => 500 ) )->get_data();
	assert_same( min( 48, $big['total'] ), count( $big['videos'] ), 'a page is at most 48 however many are asked for' );
	assert_same( 1, count( ftvs_t_rest( 'GET', '/library', array( 'per' => 1 ) )->get_data()['videos'] ) );
	assert_same( min( 24, $big['total'] ), count( ftvs_t_rest( 'GET', '/library', array( 'per' => 0 ) )->get_data()['videos'] ), 'no size given: 24' );
	assert_same( 1, ftvs_t_rest( 'GET', '/library', array( 'page' => 0, 'per' => 1 ) )->get_data()['page'], 'page numbers start at 1' );
}

function test_rest_search() {
	ftvs_t_demo();
	ftvs_t_admin();
	$data = ftvs_t_rest( 'GET', '/search', array( 'q' => 'Rooted' ) )->get_data();
	assert_count( 5, $data['videos'] );
	assert_same( 'demo-rooted-1', $data['videos'][0]['id'] );
	assert_has_key( 'watch', $data['videos'][0] );
	assert_same( array( 'videos' => array() ), ftvs_t_rest( 'GET', '/search', array( 'q' => 'a' ) )->get_data(), 'one letter is not a search' );
	assert_same( array( 'videos' => array() ), ftvs_t_rest( 'GET', '/search' )->get_data() );
}

function test_rest_search_is_rate_limited() {
	ftvs_t_demo();
	ftvs_t_admin();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	for ( $i = 0; $i < 30; $i++ ) {
		assert_same( 200, ftvs_t_rest( 'GET', '/search', array( 'q' => 'hope' ) )->get_status(), 'search ' . ( $i + 1 ) );
	}
	$response = ftvs_t_rest( 'GET', '/search', array( 'q' => 'hope' ) );
	assert_same( 429, $response->get_status(), 'the 31st search in a minute' );
	assert_same( 'ftvs_slow_down', $response->get_data()['code'] );
	unset( $_SERVER['REMOTE_ADDR'] );
}

function test_rest_limited_counts_per_visitor_and_bucket() {
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	$bucket                 = 'ut' . wp_rand();
	assert_false( FTVS_Rest::limited( $bucket, 3, 60 ) );
	assert_false( FTVS_Rest::limited( $bucket, 3, 60 ) );
	assert_false( FTVS_Rest::limited( $bucket, 3, 60 ) );
	assert_true( FTVS_Rest::limited( $bucket, 3, 60 ), 'the fourth is over the limit' );
	assert_false( FTVS_Rest::limited( $bucket . 'other', 3, 60 ), 'another bucket has its own count' );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.8';
	assert_false( FTVS_Rest::limited( $bucket, 3, 60 ), 'and so does another visitor' );
	unset( $_SERVER['REMOTE_ADDR'] );
}

/* ---------------------------------------------------------------- stats */

function test_rest_stats_counts_plays_of_known_videos() {
	ftvs_t_memory_stats();
	ftvs_t_demo();
	FTVS_Catalog::library(); // teaches the ids
	$response = ftvs_t_rest(
		'POST',
		'/stats',
		array(),
		array(
			'e'     => 'play',
			'id'    => 'demo-hope-rising-1',
			't'     => 'Hope Rising, Part 1',
			'p'     => home_url( '/sermons/?token=secret&email=a@b.co' ),
			'embed' => false,
		)
	);
	assert_same( array( 'ok' => true ), $response->get_data() );
	ftvs_t_rest( 'POST', '/stats', array(), array( 'e' => 'play', 'id' => 'demo-hope-rising-1', 't' => 'Hope Rising, Part 1', 'p' => 'https://elsewhere.example.test/blog?x=1', 'embed' => true ) );
	ftvs_t_rest( 'POST', '/stats', array(), array( 'e' => 'complete', 'id' => 'demo-hope-rising-1' ) );
	ftvs_t_rest( 'POST', '/stats', array(), array( 'e' => 'play', 'id' => 'demo-made-up-99' ) );
	ftvs_t_rest( 'POST', '/stats', array(), array( 'e' => 'delete-everything', 'id' => 'demo-hope-rising-1' ) );
	ftvs_t_rest( 'POST', '/stats', array(), array( 'e' => 'live', 'id' => 'main' ) );

	$summary = FTVS_Stats::summary( 1 );
	assert_same( 2, $summary['plays'] );
	assert_same( 1, $summary['finished'] );
	assert_same( 1, $summary['embeds'] );
	assert_same( 1, $summary['live'], 'live counts need no known video' );
	assert_same( array( array( 'Hope Rising, Part 1', 2 ) ), $summary['videos'] );
	assert_same( array( array( '/sermons/', 1 ), array( 'elsewhere.example.test', 1 ) ), $summary['pages'], 'no query strings (they can carry personal details); other sites by host' );
}

function test_rest_stats_off_switch() {
	ftvs_t_memory_stats();
	ftvs_t_settings( array( 'count_plays' => 0 ) );
	$response = ftvs_t_rest( 'POST', '/stats', array(), array( 'e' => 'live', 'id' => 'main' ) );
	assert_same( array( 'ok' => false ), $response->get_data() );
	assert_same( 0, FTVS_Stats::summary( 1 )['live'] );
}

/* ---------------------------------------------------------------- remind (sign-ups) */

function test_rest_remind_is_off_until_set_up() {
	ftvs_t_settings( array( 'remind' => 0 ) );
	$response = ftvs_t_rest( 'POST', '/remind', array(), array( 'email' => 'a@example.org' ) );
	assert_same( 404, $response->get_status() );
	assert_same( 'ftvs_off', $response->get_data()['code'] );

	ftvs_t_settings( array( 'remind' => 1, 'remind_webhook' => '' ) );
	assert_same( 404, ftvs_t_rest( 'POST', '/remind', array(), array( 'email' => 'a@example.org' ) )->get_status(), 'on, but nowhere to send it' );
	assert_count( 0, ftvs_t_requests() );
}

function test_rest_remind_passes_the_sign_up_to_the_churchs_webhook() {
	ftvs_t_settings( array( 'remind' => 1, 'remind_webhook' => 'https://hooks.example.test/inbound/abc' ) );
	ftvs_t_route( 'https://hooks.example.test/inbound/abc', ftvs_t_json( array( 'ok' => true ) ) );
	$queued   = FTVS_Followup::pending();
	$response = ftvs_t_rest(
		'POST',
		'/remind',
		array(),
		array(
			'email' => 'visitor@example.org',
			'kind'  => 'live',
			'video' => 'demo-hope-rising-1',
			'title' => 'Hope <b>Rising</b>',
			'page'  => 'https://example.org/live/',
		)
	);
	assert_same( array( 'ok' => true ), $response->get_data() );
	$requests = ftvs_t_requests( 'hooks.example.test' );
	assert_count( 1, $requests );
	assert_same( 'POST', $requests[0]['args']['method'] );
	$sent = json_decode( $requests[0]['args']['body'], true );
	assert_subset(
		array(
			'source'      => 'faith-tv-website',
			'kind'        => 'live',
			'email'       => 'visitor@example.org',
			'phone'       => '',
			'sms_consent' => false,
			'video_id'    => 'demo-hope-rising-1',
			'video_title' => 'Hope Rising',
			'page'        => 'https://example.org/live/',
			'site'        => home_url( '/' ),
		),
		$sent
	);
	assert_same( '', $sent['consent_text'], 'no phone number: no texting consent text' );
	assert_same( $queued, FTVS_Followup::pending(), 'delivered, so nothing was queued for a retry' );
}

/* ---------------------------------------------------------------- editors only */

function test_rest_admin_categories_is_for_editors() {
	$response = ftvs_t_rest( 'GET', '/admin/categories' );
	assert_true( in_array( $response->get_status(), array( 401, 403 ), true ), 'a visitor is refused (got ' . $response->get_status() . ')' );
	assert_same( 'rest_forbidden', $response->get_data()['code'] );
}

function test_rest_admin_categories_lists_the_automatic_picks_then_the_tree() {
	ftvs_t_demo();
	ftvs_t_admin();
	$response = ftvs_t_rest( 'GET', '/admin/categories' );
	assert_same( 200, $response->get_status() );
	$list = $response->get_data();
	assert_same( '@newest', $list[0]['value'] );
	assert_same( '@featured', $list[1]['value'] );
	$labels = wp_list_pluck( $list, 'label', 'value' );
	assert_same( 'Sermon Series', $labels['demo-sermon-series'] );
	assert_same( 'Sermon Series / Hope Rising', $labels['demo-hope-rising'] );
	assert_same( 'Kids Church', $labels['demo-kids'] );
}

function test_rest_admin_categories_without_a_church_still_offers_the_automatic_picks() {
	ftvs_t_admin();
	$list = ftvs_t_rest( 'GET', '/admin/categories' )->get_data();
	assert_same( '@newest', $list[0]['value'] );
	assert_same( '@featured', $list[1]['value'] );
}
