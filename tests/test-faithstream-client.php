<?php
/**
 * FTVS_FaithStream_Client: turning Faith Stream's public API answers into the plugin's shapes.
 * Every HTTP request is a stub; the "home" payload is the real one saved in poc/faithstream-sample.json.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------- tiny pure helpers */

function test_fs_is_id() {
	foreach ( array( 'a', 'kids-rock', 'kids-rock-mini-series-2', '2026-easter', '0abc', str_repeat( 'a', 128 ) ) as $ok ) {
		assert_true( FTVS_FaithStream_Client::is_id( $ok ), $ok );
	}
	foreach ( array( '', '-lead', 'Upper', 'has space', 'under_score', 'slash/es', 'dot.dot', str_repeat( 'a', 129 ), '../etc' ) as $bad ) {
		assert_false( FTVS_FaithStream_Client::is_id( $bad ), 'should be rejected: ' . $bad );
	}
}

function test_fs_absolute_urls() {
	ftvs_t_faithstream();
	assert_same( FTVS_T_FS . '/media/a.jpg', FTVS_FaithStream_Client::absolute( '/media/a.jpg' ), 'relative to the connected server' );
	assert_same( 'https://other.example.test/media/a.jpg', FTVS_FaithStream_Client::absolute( '/media/a.jpg', 'https://other.example.test' ), 'or to the base it is given' );
	assert_same( 'https://cdn.example.test/a.jpg', FTVS_FaithStream_Client::absolute( 'https://cdn.example.test/a.jpg' ) );
	assert_same( 'http://cdn.example.test/a.jpg', FTVS_FaithStream_Client::absolute( 'http://cdn.example.test/a.jpg' ), 'pictures may be http' );
	assert_same( '', FTVS_FaithStream_Client::absolute( '' ) );
	assert_same( '', FTVS_FaithStream_Client::absolute( 'javascript:alert(1)' ) );
	assert_same( '', FTVS_FaithStream_Client::absolute( 'data:text/html;base64,AAAA' ) );
	assert_same( '', FTVS_FaithStream_Client::absolute( 'ftp://files.example.test/a.jpg' ) );
	assert_same( '', FTVS_FaithStream_Client::absolute( 'media/no-leading-slash.jpg' ) );
}

function test_fs_sized_adds_a_mux_width_only_when_unsigned() {
	$mux = 'https://image.mux.com/abc123/thumbnail.jpg';
	parse_str( (string) wp_parse_url( FTVS_FaithStream_Client::sized( $mux, 1280 ), PHP_URL_QUERY ), $q );
	assert_same( array( 'width' => '1280' ), $q, 'no query yet: a width is added' );

	parse_str( (string) wp_parse_url( FTVS_FaithStream_Client::sized( $mux . '?width=640', 1280 ), PHP_URL_QUERY ), $q );
	assert_same( array( 'width' => '1280' ), $q, 'the small width is replaced, not repeated' );

	parse_str( (string) wp_parse_url( FTVS_FaithStream_Client::sized( $mux . '?time=5&width=640', 1280 ), PHP_URL_QUERY ), $q );
	assert_same(
		array(
			'time'  => '5',
			'width' => '1280',
		),
		$q,
		'other parameters stay'
	);

	$signed = $mux . '?width=640&token=eyJhbGciOi.signed';
	assert_same( $signed, FTVS_FaithStream_Client::sized( $signed, 1280 ), 'a signed picture cannot be resized: the token covers its width' );

	assert_same( '', FTVS_FaithStream_Client::sized( '', 1280 ) );
	$other = 'https://cdn.example.test/a.jpg';
	assert_same( $other, FTVS_FaithStream_Client::sized( $other, 1280 ), 'only Mux pictures can be resized' );
	assert_same( 'https://image.mux.com/abc123/thumbnail.jpg?width=320', FTVS_FaithStream_Client::sized( 'https://image.mux.com/abc123/thumbnail.jpg?width=320', 320 ) );
}

function test_fs_links_carry_the_tenant() {
	$tenant = ftvs_t_faithstream();
	assert_same( FTVS_T_FS . '/browse/kids-rock?tenant=' . $tenant, FTVS_FaithStream_Client::category_link( 'kids-rock' ) );
	assert_same( FTVS_T_FS . '/watch/part-1?tenant=' . $tenant, FTVS_FaithStream_Client::video_link( 'part-1', 'any' ) );
	assert_same( FTVS_T_FS . '/live/main?tenant=' . $tenant, FTVS_FaithStream_Client::live_link( 'main' ) );
	assert_same( FTVS_T_FS . '/browse/a%20b?tenant=' . $tenant, FTVS_FaithStream_Client::category_link( 'a b' ), 'slugs are encoded' );
}

/* ---------------------------------------------------------------- normalisers */

function test_fs_category_normaliser() {
	ftvs_t_faithstream();
	$cat = FTVS_FaithStream_Client::category(
		array(
			'id'            => 'x1',
			'slug'          => 'kids-rock',
			'name'          => '  Kids Rock  ',
			'description'   => "One\r\nTwo\rThree ",
			'thumbnail_url' => '/media/k.jpg',
			'video_count'   => '7',
			'children_count' => 3,
		)
	);
	assert_same(
		array(
			'id'            => 'kids-rock',
			'title'         => 'Kids Rock',
			'description'   => "One\nTwo\nThree",
			'image'         => FTVS_T_FS . '/media/k.jpg',
			'videos'        => 7,
			'subcategories' => 3,
		),
		$cat
	);
}

function test_fs_category_normaliser_borrows_the_first_videos_picture_and_counts_children() {
	ftvs_t_faithstream();
	$row = array(
		'videos'   => array( array( 'thumbnail_url' => '/media/first.jpg' ) ),
		'children' => array( array( 'slug' => 'a' ), array( 'slug' => 'b' ) ),
	);
	$cat = FTVS_FaithStream_Client::category(
		array(
			'slug'          => 'folder',
			'name'          => 'Folder',
			'thumbnail_url' => null,
		),
		$row
	);
	assert_same( FTVS_T_FS . '/media/first.jpg', $cat['image'], 'no picture of its own: uses its first episode' );
	assert_same( 2, $cat['subcategories'], 'children_count missing: counts the row children' );
	assert_same( 0, $cat['videos'] );

	$cat = FTVS_FaithStream_Client::category(
		array(
			'slug' => 'bare',
			'name' => 'Bare',
		)
	);
	assert_same( '', $cat['image'] );
	assert_same( '', $cat['description'] );
}

function test_fs_category_normaliser_rejects_unsafe_pictures() {
	ftvs_t_faithstream();
	$cat = FTVS_FaithStream_Client::category(
		array(
			'slug'          => 'x',
			'name'          => 'X',
			'thumbnail_url' => 'javascript:alert(1)',
		)
	);
	assert_same( '', $cat['image'] );
}

function test_fs_video_normaliser() {
	ftvs_t_faithstream();
	$video = FTVS_FaithStream_Client::video(
		array(
			'id'            => 'abc',
			'slug'          => 'outrageous-part-4',
			'title'         => ' Outrageous Part 4 ',
			'description'   => "  Line one\r\nLine two ",
			'thumbnail_url' => 'https://image.mux.com/pb123/thumbnail.jpg?width=640',
			'duration_s'    => 2220.542,
			'published_at'  => '2026-09-27T16:56:20Z',
			'speaker'       => ' Pastor Sam ',
			'scripture'     => ' Romans 8 ',
			'tags'          => array( 'faith', '', 5, 'hope' ),
		),
		'outrageous'
	);
	assert_same( 'outrageous-part-4', $video['id'], 'the slug is the id' );
	assert_same( 'outrageous', $video['parent'] );
	assert_same( 'Outrageous Part 4', $video['title'] );
	assert_matches( '/^Line one\R+Line two$/', $video['description'], 'trimmed, line breaks kept' );
	assert_same( 'https://image.mux.com/pb123/thumbnail.jpg?width=640', $video['image'] );
	assert_contains( 'width=1280', $video['poster'], 'the big picture is a 1280 wide copy' );
	assert_not_contains( 'width=640', $video['poster'] );
	assert_same( 2221, $video['length'], 'seconds are rounded' );
	assert_same( '2026-09-27T16:56:20Z', $video['added'] );
	assert_same( false, $video['live'] );
	assert_same( 'Pastor Sam', $video['speaker'] );
	assert_same( 'Romans 8', $video['scripture'] );
	assert_same( array( 'faith', '5', 'hope' ), $video['tags'], 'tags are strings, empties dropped' );
}

function test_fs_video_normaliser_minimal_payload() {
	ftvs_t_faithstream();
	$video = FTVS_FaithStream_Client::video( array( 'slug' => 'just-a-slug' ), '' );
	assert_same( 'just-a-slug', $video['id'] );
	assert_same( '', $video['title'] );
	assert_same( '', $video['image'] );
	assert_same( '', $video['poster'] );
	assert_same( 0, $video['length'] );
	assert_same( '', $video['added'] );
	assert_same( array(), $video['tags'] );
}

function test_fs_channel_normaliser_live() {
	$tenant = ftvs_t_faithstream();
	$ch     = FTVS_FaithStream_Client::channel(
		array(
			'slug'             => 'main',
			'name'             => 'Main Campus',
			'title'            => ' Sunday Service ',
			'status'           => 'live',
			'thumbnail_url'    => '/media/live.jpg',
			'hls_url'          => 'https://stream.mux.test/live.m3u8',
			'viewers'          => '42',
			'scheduled_at'     => '2026-10-04T15:30:00Z',
			'started_at'       => '2026-10-04T15:31:00Z',
			'chat_enabled'     => true,
			'latest_recording' => array(
				'slug'  => 'last-week',
				'title' => 'Last week',
			),
		)
	);
	assert_same( 'main', $ch['id'] );
	assert_same( 'Sunday Service', $ch['title'], 'the title beats the channel name' );
	assert_same( 'Main Campus', $ch['name'] );
	assert_same( 'live', $ch['status'] );
	assert_same( FTVS_T_FS . '/media/live.jpg', $ch['image'] );
	assert_same( 'https://stream.mux.test/live.m3u8', $ch['hls'] );
	assert_same( 42, $ch['viewers'] );
	assert_same( '2026-10-04T15:30:00Z', $ch['scheduled'] );
	assert_true( $ch['chat'] );
	assert_same( 'last-week', $ch['replay']['id'] );
	assert_same( FTVS_T_FS . '/live/main?tenant=' . $tenant, $ch['link'] );
}

function test_fs_channel_normaliser_idle_channel_and_unsafe_stream() {
	ftvs_t_faithstream();
	$ch = FTVS_FaithStream_Client::channel(
		array(
			'slug'    => 'chapel',
			'name'    => 'Chapel',
			'hls_url' => 'http://insecure.example.test/live.m3u8',
		)
	);
	assert_same( 'Chapel', $ch['title'], 'no title: the name' );
	assert_same( 'idle', $ch['status'] );
	assert_same( '', $ch['hls'], 'a stream must be https' );
	assert_same( null, $ch['replay'] );
	assert_false( $ch['chat'] );
	assert_same( 0, $ch['viewers'] );
}

/* ---------------------------------------------------------------- the real home payload */

function test_fs_home_sample_is_normalised() {
	ftvs_t_faithstream();
	$sample = ftvs_t_sample_home();
	ftvs_t_route( '/api/public/home', ftvs_t_json( $sample ) );

	$home = FTVS_FaithStream_Client::fetch_home();
	assert_not_error( $home );
	assert_count( count( $sample['rows'] ), $home['rows'] );
	assert_same( null, $home['live'], 'nothing live in the sample' );

	$first = $home['rows'][0];
	assert_same( 'welcome', $first['category']['id'] );
	assert_same( 'hero', $first['style'] );
	assert_count( 1, $first['videos'] );
	assert_same( 'welcome-to-faith-tv', $first['videos'][0]['id'] );
	assert_same( 'welcome', $first['videos'][0]['parent'], 'each video knows the row it was listed in' );

	$featured = $home['rows'][1];
	assert_same( 'featured', $featured['category']['id'] );
	assert_same( 'slider', $featured['style'] );
	assert_count( 5, $featured['children'] );
	assert_same( 'faith-tv-mini-series', $featured['children'][0]['id'] );
	assert_same( 'FAITH TV Mini Series', $featured['children'][0]['title'] );
	assert_same( 5, $featured['category']['subcategories'] );

	foreach ( $home['rows'] as $row ) {
		assert_true( FTVS_FaithStream_Client::is_id( $row['category']['id'] ), $row['category']['id'] );
		assert_not_same( '', $row['category']['title'] );
		foreach ( $row['videos'] as $video ) {
			assert_same( $row['category']['id'], $video['parent'] );
			assert_true( 0 === strpos( $video['image'], FTVS_T_FS . '/media/' ), 'picture addresses are made absolute: ' . $video['image'] );
			assert_true( $video['length'] > 0, $video['id'] . ' has a length' );
		}
	}
	// A folder with no picture of its own borrows its first episode's.
	$mini = $home['rows'][2];
	assert_same( 'faith-tv-mini-series-2', $mini['category']['id'] );
	assert_same( $mini['videos'][0]['image'], $mini['category']['image'] );

	$requests = ftvs_t_requests( '/api/public/home' );
	assert_count( 1, $requests );
	assert_same( FTVS_T_FS . '/api/public/home?thumb_width=640', $requests[0]['url'] );
	assert_same( $GLOBALS['ftvs_t_settings']['fs_tenant'], $requests[0]['args']['headers']['X-Tenant'], 'the church is chosen with the X-Tenant header' );
}

function test_fs_home_skips_rows_and_videos_without_a_slug() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/home',
		ftvs_t_json(
			array(
				'rows' => array(
					array(
						'category' => array( 'name' => 'No slug' ),
						'videos'   => array(),
					),
					array(
						'category' => array(
							'slug' => 'good',
							'name' => 'Good',
						),
						'videos'   => array( array( 'title' => 'no slug' ), array( 'slug' => 'ok', 'title' => 'OK' ) ),
						'children' => array( array( 'name' => 'nameless' ), array( 'slug' => 'kid', 'name' => 'Kid' ) ),
					),
				),
			)
		)
	);
	$home = FTVS_FaithStream_Client::fetch_home();
	assert_count( 1, $home['rows'] );
	assert_count( 1, $home['rows'][0]['videos'] );
	assert_count( 1, $home['rows'][0]['children'] );
	assert_same( '', $home['rows'][0]['style'], 'a missing style is an empty string' );
}

function test_fs_home_reports_a_live_channel() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/home',
		ftvs_t_json(
			array(
				'rows' => array(),
				'live' => array(
					'slug'    => 'main',
					'name'    => 'Main',
					'status'  => 'live',
					'hls_url' => 'https://stream.mux.test/x.m3u8',
				),
			)
		)
	);
	$home = FTVS_FaithStream_Client::fetch_home();
	assert_same( 'main', $home['live']['id'] );
	assert_same( 'live', $home['live']['status'] );
}

/* ---------------------------------------------------------------- fetch_children paging */

function test_fs_fetch_children_pages_by_total() {
	$tenant = ftvs_t_faithstream();
	// A video filed in two places is listed once, so page 1 holds 199, not 200. The total says 222.
	ftvs_t_route( 'categories/big-series?per_page=200&page=1&', ftvs_t_json( ftvs_t_fs_category_page( 'big-series', ftvs_t_fs_videos( 'msg', 1, 199 ), 222 ) ) );
	ftvs_t_route( 'categories/big-series?per_page=200&page=2&', ftvs_t_json( ftvs_t_fs_category_page( 'big-series', ftvs_t_fs_videos( 'msg', 200, 222 ), 222 ) ) );

	$out = FTVS_FaithStream_Client::fetch_children( 'big-series' );
	assert_not_error( $out );
	assert_count( 222, $out['videos'], 'all 222 come back' );
	assert_count( 222, array_unique( wp_list_pluck( $out['videos'], 'id' ) ), 'none twice' );
	assert_same( 'msg-1', $out['videos'][0]['id'] );
	assert_same( 'msg-222', $out['videos'][221]['id'] );
	assert_same( 'big-series', $out['videos'][100]['parent'] );
	assert_same( 'big-series', $out['self']['id'] );
	assert_same( array(), $out['categories'] );

	$requests = ftvs_t_requests( '/api/public/categories/' );
	assert_count( 2, $requests, 'two pages, and no third' );
	assert_same( $tenant, $requests[1]['args']['headers']['X-Tenant'] );
}

function test_fs_fetch_children_one_short_page_is_one_request() {
	ftvs_t_faithstream();
	ftvs_t_route( 'categories/small?', ftvs_t_json( ftvs_t_fs_category_page( 'small', ftvs_t_fs_videos( 'm', 1, 5 ), 5 ) ) );
	$out = FTVS_FaithStream_Client::fetch_children( 'small' );
	assert_count( 5, $out['videos'] );
	assert_count( 1, ftvs_t_requests( 'categories/small' ) );
}

function test_fs_fetch_children_exactly_two_hundred_does_not_ask_for_a_second_page() {
	ftvs_t_faithstream();
	ftvs_t_route( 'categories/exact?', ftvs_t_json( ftvs_t_fs_category_page( 'exact', ftvs_t_fs_videos( 'm', 1, 200 ), 200 ) ) );
	$out = FTVS_FaithStream_Client::fetch_children( 'exact' );
	assert_count( 200, $out['videos'] );
	assert_count( 1, ftvs_t_requests( 'categories/exact' ), 'the total says there is nothing more' );
}

function test_fs_fetch_children_stops_at_an_empty_page() {
	ftvs_t_faithstream();
	ftvs_t_route( 'categories/liar?per_page=200&page=1&', ftvs_t_json( ftvs_t_fs_category_page( 'liar', ftvs_t_fs_videos( 'm', 1, 200 ), 900 ) ) );
	ftvs_t_route( 'categories/liar?per_page=200&page=2&', ftvs_t_json( ftvs_t_fs_category_page( 'liar', array(), 900 ) ) );
	$out = FTVS_FaithStream_Client::fetch_children( 'liar' );
	assert_count( 200, $out['videos'] );
	assert_count( 2, ftvs_t_requests( 'categories/liar' ), 'an empty page ends the loop even if the total promised more' );
}

function test_fs_fetch_children_reads_at_most_five_pages() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'categories/huge?',
		function ( $url ) {
			preg_match( '/page=(\d+)/', $url, $m );
			$from = ( (int) $m[1] - 1 ) * 200 + 1;
			return ftvs_t_json( ftvs_t_fs_category_page( 'huge', ftvs_t_fs_videos( 'h', $from, $from + 199 ), 5000 ) );
		}
	);
	$out = FTVS_FaithStream_Client::fetch_children( 'huge' );
	assert_count( 1000, $out['videos'], 'a big archive is cut at 1,000 videos' );
	assert_count( 5, ftvs_t_requests( 'categories/huge' ) );
}

function test_fs_fetch_children_page_two_failing_fails_the_whole_answer() {
	ftvs_t_faithstream();
	ftvs_t_route( 'categories/flaky?per_page=200&page=1&', ftvs_t_json( ftvs_t_fs_category_page( 'flaky', ftvs_t_fs_videos( 'm', 1, 200 ), 300 ) ) );
	ftvs_t_route( 'categories/flaky?per_page=200&page=2&', ftvs_t_text( 'boom', 500 ) );
	$out = FTVS_FaithStream_Client::fetch_children( 'flaky' );
	assert_wp_error( $out, 'ftvs_http', 'a half list is never cached; the last complete one is served instead' );
}

function test_fs_fetch_children_folder_lists_its_series_not_their_episodes() {
	ftvs_t_faithstream();
	$page = ftvs_t_fs_category_page(
		'folder',
		ftvs_t_fs_videos( 'ep', 1, 30 ),
		30,
		array(
			'has_own_videos' => false,
			'children'       => array(
				array(
					'id'            => 'k1',
					'slug'          => 'kid-a',
					'name'          => 'Kid A',
					'thumbnail_url' => null,
					'video_count'   => 12,
				),
				array(
					'id'            => 'k2',
					'slug'          => 'kid-b',
					'name'          => 'Kid B',
					'thumbnail_url' => '/media/b.jpg',
					'video_count'   => 18,
				),
			),
			'sections'       => array(
				array(
					'category' => array( 'slug' => 'kid-a' ),
					'videos'   => array( array( 'thumbnail_url' => '/media/a-first.jpg' ) ),
				),
			),
		)
	);
	ftvs_t_route( 'categories/folder?', ftvs_t_json( $page ) );
	$out = FTVS_FaithStream_Client::fetch_children( 'folder' );
	assert_count( 2, $out['categories'] );
	assert_same( array(), $out['videos'], 'the descendants listed by a folder belong to its series, not to the folder' );
	assert_same( FTVS_T_FS . '/media/a-first.jpg', $out['categories'][0]['image'], 'a series without a picture borrows its first episode\'s' );
	assert_same( FTVS_T_FS . '/media/b.jpg', $out['categories'][1]['image'] );
	assert_count( 1, ftvs_t_requests( 'categories/folder' ) );
}

function test_fs_fetch_children_series_and_own_videos_together() {
	ftvs_t_faithstream();
	$page = ftvs_t_fs_category_page(
		'mixed',
		ftvs_t_fs_videos( 'ep', 1, 3 ),
		3,
		array(
			'children' => array(
				array(
					'slug' => 'sub',
					'name' => 'Sub',
				),
			),
		)
	);
	ftvs_t_route( 'categories/mixed?', ftvs_t_json( $page ) );
	$out = FTVS_FaithStream_Client::fetch_children( 'mixed' );
	assert_count( 1, $out['categories'] );
	assert_count( 3, $out['videos'] );
}

function test_fs_fetch_children_encodes_the_slug_in_the_address() {
	ftvs_t_faithstream();
	ftvs_t_route( 'categories/', ftvs_t_json( ftvs_t_fs_category_page( 'x', array(), 0 ) ) );
	FTVS_FaithStream_Client::fetch_children( 'a/b?c' );
	$requests = ftvs_t_requests( 'categories/' );
	assert_contains( '/api/public/categories/a%2Fb%3Fc?per_page=200', $requests[0]['url'] );
}

/* ---------------------------------------------------------------- errors */

function test_fs_category_404_means_gone() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/categories/removed', ftvs_t_text( '{"error":"not found"}', 404 ) );
	assert_wp_error( FTVS_FaithStream_Client::fetch_children( 'removed' ), 'ftvs_gone' );
	assert_wp_error( FTVS_FaithStream_Client::fetch_all_under( 'removed' ), 'ftvs_gone' );
}

function test_fs_video_404_means_gone() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/videos/removed', ftvs_t_text( '', 404 ) );
	assert_wp_error( FTVS_FaithStream_Client::fetch_video( 'removed' ), 'ftvs_gone' );
}

function test_fs_home_404_is_not_gone() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/home', ftvs_t_text( 'nope', 404 ) );
	$out = FTVS_FaithStream_Client::fetch_home();
	assert_wp_error( $out, 'ftvs_http', 'the home rows can never be "removed": a 404 there is a connection problem' );
	assert_contains( '404', $out->get_error_message() );
}

function test_fs_other_failures_are_not_gone() {
	ftvs_t_faithstream();
	ftvs_t_route( '/categories/boom', ftvs_t_text( 'oops', 500 ) );
	assert_wp_error( FTVS_FaithStream_Client::fetch_children( 'boom' ), 'ftvs_http' );
	ftvs_t_route( '/categories/junk', ftvs_t_text( '<html>not json</html>', 200 ) );
	assert_wp_error( FTVS_FaithStream_Client::fetch_children( 'junk' ), 'ftvs_parse' );
	ftvs_t_route( '/categories/scalar', ftvs_t_text( '"just a string"', 200 ) );
	assert_wp_error( FTVS_FaithStream_Client::fetch_children( 'scalar' ), 'ftvs_parse' );
	ftvs_t_route( '/categories/down', ftvs_t_neterr( 'Connection refused' ) );
	assert_wp_error( FTVS_FaithStream_Client::fetch_children( 'down' ), 'http_request_failed', 'transport errors pass through untouched (the cache treats them as "platform down")' );
}

/* ---------------------------------------------------------------- one video, live, search, everything under */

function test_fs_fetch_video_details_and_stream() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/videos/part-1',
		ftvs_t_json(
			array(
				'video'        => array(
					'id'            => 'v1',
					'slug'          => 'part-1',
					'title'         => 'Part 1',
					'thumbnail_url' => 'https://image.mux.com/abc/thumbnail.jpg?width=640',
					'duration_s'    => 1800.4,
					'hls_url'       => 'https://stream.mux.test/abc.m3u8?token=t',
					'audio_url'     => '/media/audio/part-1.m4a',
					'captions'      => array( 'en' ),
				),
				'categories'   => array(
					array(
						'slug' => 'series-a',
						'name' => ' Series A ',
					),
					array( 'name' => 'no slug' ),
				),
				'related'      => array(
					array(
						'slug'  => 'part-2',
						'title' => 'Part 2',
					),
					array( 'title' => 'no slug' ),
				),
				'chat_enabled' => true,
			)
		)
	);
	$video = FTVS_FaithStream_Client::fetch_video( 'part-1' );
	assert_not_error( $video );
	assert_same( 'part-1', $video['id'] );
	assert_same( 'series-a', $video['parent'], 'filed under its first category' );
	assert_same( 1800, $video['length'] );
	assert_same( 'https://stream.mux.test/abc.m3u8?token=t', $video['hls'] );
	assert_same( FTVS_T_FS . '/media/audio/part-1.m4a', $video['audio'] );
	assert_true( $video['captions'] );
	assert_true( $video['chat'] );
	assert_same(
		array(
			array(
				'id'    => 'series-a',
				'title' => 'Series A',
			),
		),
		$video['series']
	);
	assert_count( 1, $video['related'] );
	assert_same( 'part-2', $video['related'][0]['id'] );
}

function test_fs_fetch_video_refuses_streams_that_are_not_https() {
	ftvs_t_faithstream(); // https://stream.example.test
	ftvs_t_route(
		'/api/public/videos/insecure',
		ftvs_t_json(
			array(
				'video' => array(
					'slug'      => 'insecure',
					'title'     => 'X',
					'hls_url'   => 'http://stream.mux.test/abc.m3u8',
					'audio_url' => 'javascript:alert(1)',
				),
			)
		)
	);
	$video = FTVS_FaithStream_Client::fetch_video( 'insecure' );
	assert_same( '', $video['hls'] );
	assert_same( '', $video['audio'] );
	assert_same( '', $video['parent'], 'no categories: no parent' );
	assert_false( $video['captions'] );
	assert_false( $video['chat'] );
}

function test_fs_get_video_url_needs_a_stream() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/videos/no-stream', ftvs_t_json( array( 'video' => array( 'slug' => 'no-stream', 'title' => 'Processing' ) ) ) );
	assert_wp_error( FTVS_FaithStream_Client::get_video_url( 'no-stream' ), 'ftvs_no_stream' );

	ftvs_t_route( '/api/public/videos/ready', ftvs_t_json( array( 'video' => array( 'slug' => 'ready', 'hls_url' => 'https://stream.mux.test/r.m3u8' ) ) ) );
	assert_same( 'https://stream.mux.test/r.m3u8', FTVS_FaithStream_Client::get_video_url( 'ready' ) );
}

function test_fs_fetch_video_without_a_video_is_unreadable() {
	ftvs_t_faithstream();
	ftvs_t_route( '/api/public/videos/empty', ftvs_t_json( array( 'video' => array( 'title' => 'no slug' ) ) ) );
	assert_wp_error( FTVS_FaithStream_Client::fetch_video( 'empty' ), 'ftvs_parse' );
}

function test_fs_fetch_live_keeps_channels_with_a_slug() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/live',
		ftvs_t_json(
			array(
				array(
					'slug'   => 'main',
					'name'   => 'Main',
					'status' => 'live',
				),
				array( 'name' => 'no slug' ),
				'junk',
				array(
					'slug' => 'chapel',
					'name' => 'Chapel',
				),
			)
		)
	);
	$live = FTVS_FaithStream_Client::fetch_live();
	assert_same( array( 'main', 'chapel' ), wp_list_pluck( $live, 'id' ) );
}

function test_fs_fetch_search() {
	ftvs_t_faithstream();
	ftvs_t_route(
		'/api/public/search?q=grace%20and%20mercy',
		ftvs_t_json(
			array(
				'videos'     => array(
					array(
						'slug'  => 'amazing-grace',
						'title' => 'Amazing Grace',
					),
					array( 'title' => 'no slug' ),
				),
				'categories' => array(
					array(
						'slug' => 'grace',
						'name' => 'Grace',
					),
				),
			)
		)
	);
	$out = FTVS_FaithStream_Client::fetch_search( 'grace and mercy' );
	assert_same( array( 'amazing-grace' ), wp_list_pluck( $out['videos'], 'id' ) );
	assert_same( array( 'grace' ), wp_list_pluck( $out['categories'], 'id' ) );
}

function test_fs_fetch_all_under_walks_series_of_a_category_with_its_own_videos() {
	ftvs_t_faithstream();
	$top = ftvs_t_fs_category_page(
		'top',
		ftvs_t_fs_videos( 'own', 1, 2 ),
		2,
		array(
			'children' => array(
				array(
					'slug' => 'kid-a',
					'name' => 'A',
				),
				array(
					'slug' => 'kid-b',
					'name' => 'B',
				),
			),
		)
	);
	ftvs_t_route( 'categories/top?', ftvs_t_json( $top ) );
	ftvs_t_route( 'categories/kid-a?', ftvs_t_json( ftvs_t_fs_category_page( 'kid-a', ftvs_t_fs_videos( 'a', 1, 3 ), 3 ) ) );
	ftvs_t_route( 'categories/kid-b?', ftvs_t_json( ftvs_t_fs_category_page( 'kid-b', ftvs_t_fs_videos( 'b', 1, 4 ), 4 ) ) );
	$all = FTVS_FaithStream_Client::fetch_all_under( 'top' );
	assert_count( 9, $all );
	assert_same( 'top', $all[0]['parent'] );
	assert_same( 'kid-a', $all[2]['parent'] );
	assert_same( 'kid-b', $all[5]['parent'] );
}

function test_fs_fetch_all_under_a_folder_lists_everything_in_one_go() {
	ftvs_t_faithstream();
	$folder = ftvs_t_fs_category_page(
		'folder',
		ftvs_t_fs_videos( 'ep', 1, 10 ),
		10,
		array(
			'has_own_videos' => false,
			'children'       => array(
				array(
					'slug' => 'kid-a',
					'name' => 'A',
				),
			),
		)
	);
	ftvs_t_route( 'categories/folder?', ftvs_t_json( $folder ) );
	$all = FTVS_FaithStream_Client::fetch_all_under( 'folder' );
	assert_count( 10, $all, 'a folder lists all its descendants itself, so its series are not walked again' );
	assert_count( 1, ftvs_t_requests( '/api/public/categories/' ) );
}

/* ---------------------------------------------------------------- lookup (connecting) */

function ftvs_t_fs_tenant_answer( $slug = 'mychurch' ) {
	return array(
		'id'              => 't1',
		'name'            => 'My Church',
		'slug'            => $slug,
		'logo_url'        => '/media/logo.png',
		'theme'           => array(
			'primary_color' => '#123456',
			'accent_color'  => '#ABCDEF',
		),
		'settings_public' => array(
			'default_scheme' => 'light',
			'features'       => array( 'hide_powered_by', 'live', 42 ),
		),
	);
}

function test_fs_lookup_with_tenant_in_the_address() {
	ftvs_t_route( '/api/tenant?tenant=mychurch', ftvs_t_json( ftvs_t_fs_tenant_answer() ) );
	ftvs_t_route( '/api/public/home?thumb_width=640', ftvs_t_json( ftvs_t_sample_home() ) );

	$found = FTVS_FaithStream_Client::lookup( 'https://stream.example.test/?tenant=MyChurch' );
	assert_not_error( $found );
	assert_same( 'faithstream', $found['source'] );
	assert_same( FTVS_T_FS, $found['fs_url'] );
	assert_same( 'mychurch', $found['fs_tenant'], 'the tenant is lower-cased' );
	assert_same( 'My Church', $found['church_name'] );
	assert_same( FTVS_T_FS . '/media/logo.png', $found['church_logo'] );
	assert_count( count( ftvs_t_sample_home()['rows'] ), $found['rows'], 'the preview lists the home rows' );
	assert_same( 'welcome', $found['rows'][0]['id'] );
	assert_same(
		array(
			'primary' => '#123456',
			'accent'  => '#ABCDEF',
			'scheme'  => 'light',
		),
		$found['colors']
	);
	assert_same( array( 'hide_powered_by', 'live' ), $found['features'], 'only text feature names are kept' );

	$requests = ftvs_t_requests();
	assert_count( 2, $requests );
	assert_same( FTVS_T_FS . '/api/tenant?tenant=mychurch', $requests[0]['url'] );
	assert_same( 'mychurch', $requests[0]['args']['headers']['X-Tenant'] );
	assert_same( FTVS_T_FS . '/api/public/home?thumb_width=640', $requests[1]['url'] );
}

function test_fs_lookup_with_a_plain_address_lets_the_server_pick_the_church() {
	ftvs_t_route( '/api/tenant', ftvs_t_json( ftvs_t_fs_tenant_answer( 'grace' ) ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );

	$found    = FTVS_FaithStream_Client::lookup( 'stream.grace.test' );
	$requests = ftvs_t_requests();
	assert_not_error( $found );
	assert_same( 'https://stream.grace.test/api/tenant', $requests[0]['url'], 'no scheme typed: https is assumed, and no ?tenant=' );
	assert_false( isset( $requests[0]['args']['headers']['X-Tenant'] ), 'no tenant known yet, so no header' );
	assert_same( 'grace', $found['fs_tenant'], 'the server tells us the church slug' );
	assert_same( 'https://stream.grace.test', $found['fs_url'] );
	assert_same( 'grace', $requests[1]['args']['headers']['X-Tenant'], 'the home request uses it' );
	assert_same( array(), $found['rows'] );
}

function test_fs_lookup_keeps_port_and_scheme_and_drops_the_path() {
	ftvs_t_route( '/api/tenant', ftvs_t_json( ftvs_t_fs_tenant_answer() ) );
	ftvs_t_route( '/api/public/home', ftvs_t_text( 'down', 503 ) );

	$found = FTVS_FaithStream_Client::lookup( 'http://localhost:3000/some/page?tenant=abc#top' );
	assert_not_error( $found );
	assert_same( 'http://localhost:3000', $found['fs_url'] );
	assert_same( array(), $found['rows'], 'the church is found even if its home rows cannot be read' );
	assert_contains( 'http://localhost:3000/api/tenant?tenant=abc', ftvs_t_requests()[0]['url'] );
}

function test_fs_lookup_explicit_tenant_argument_beats_the_address() {
	ftvs_t_route( '/api/tenant?tenant=fromarg', ftvs_t_json( ftvs_t_fs_tenant_answer( 'fromarg' ) ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	$found = FTVS_FaithStream_Client::lookup( 'https://stream.example.test/?tenant=fromaddress', ' FromArg ' );
	assert_same( 'fromarg', $found['fs_tenant'] );
}

function test_fs_lookup_rejects_bad_input_without_asking_the_network() {
	assert_wp_error( FTVS_FaithStream_Client::lookup( '' ), 'ftvs_lookup' );
	assert_wp_error( FTVS_FaithStream_Client::lookup( '   ' ), 'ftvs_lookup' );
	assert_wp_error( FTVS_FaithStream_Client::lookup( 'https://stream.example.test/?tenant=bad%20tenant!' ), 'ftvs_lookup' );
	assert_wp_error( FTVS_FaithStream_Client::lookup( 'stream.example.test', '../etc' ), 'ftvs_lookup' );
	assert_count( 0, ftvs_t_requests() );
}

function test_fs_lookup_church_not_found() {
	ftvs_t_route( '/api/tenant', ftvs_t_text( '{"error":"unknown church"}', 404 ) );
	$err = FTVS_FaithStream_Client::lookup( 'https://stream.example.test/?tenant=nobody' );
	assert_wp_error( $err, 'ftvs_lookup' );
	assert_contains( 'could not find that church', $err->get_error_message() );

	$err = FTVS_FaithStream_Client::lookup( 'https://stream.example.test' );
	assert_wp_error( $err, 'ftvs_lookup' );
	assert_contains( 'church ID', $err->get_error_message(), 'with no id typed, the message suggests adding one' );
}

function test_fs_lookup_answer_without_a_name_or_slug_is_not_a_church() {
	ftvs_t_route( '/api/tenant', ftvs_t_json( array( 'name' => 'Nameless slug' ) ) );
	assert_wp_error( FTVS_FaithStream_Client::lookup( 'stream.example.test' ), 'ftvs_lookup' );
}

function test_fs_lookup_colors_and_features_are_sanitized() {
	$answer                                   = ftvs_t_fs_tenant_answer();
	$answer['theme']['primary_color']         = 'red; background: url(x)';
	$answer['theme']['accent_color']          = '#zzz';
	$answer['settings_public']['default_scheme'] = 'purple';
	$answer['settings_public']['features']    = 'not a list';
	ftvs_t_route( '/api/tenant', ftvs_t_json( $answer ) );
	ftvs_t_route( '/api/public/home', ftvs_t_json( array( 'rows' => array() ) ) );
	$found = FTVS_FaithStream_Client::lookup( 'stream.example.test' );
	assert_same(
		array(
			'primary' => '',
			'accent'  => '',
			'scheme'  => 'dark',
		),
		$found['colors']
	);
	assert_same( null, $found['features'], 'no list: the plan is unknown, which means everything is allowed' );
}
