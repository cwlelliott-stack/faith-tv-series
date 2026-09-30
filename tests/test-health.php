<?php
/**
 * FTVS_Health: finding the video sections on the site, checking them, and the alerts and
 * Site Health results built on that.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The video sections found in some post content (and optionally Elementor data), without any post in the database. */
function ftvs_t_sections_in( $content, $elementor = null ) {
	$post = new WP_Post(
		(object) array(
			'ID'           => 999999999,
			'post_content' => $content,
		)
	);
	if ( null !== $elementor ) {
		ftvs_t_add_filter(
			'get_post_metadata',
			function ( $check, $object_id, $meta_key ) use ( $elementor ) {
				return ( 999999999 === (int) $object_id && '_elementor_data' === $meta_key ) ? $elementor : $check;
			},
			10,
			3
		);
	}
	return ftvs_t_private( 'FTVS_Health', 'sections_in', array( $post ) );
}

/** Puts sections in the "where used" cache, as if these pages existed, and returns the rows. */
function ftvs_t_used( $sections, $status = 'publish' ) {
	$rows = array();
	foreach ( $sections as $i => $section ) {
		$rows[] = array_merge(
			array(
				'post'   => 1000 + $i,
				'title'  => 'Page ' . ( $i + 1 ),
				'status' => $status,
				'url'    => home_url( '/page-' . ( $i + 1 ) . '/' ),
				'edit'   => '',
			),
			$section
		);
	}
	set_transient( FTVS_Health::USED, $rows, HOUR_IN_SECONDS );
	return $rows;
}

function ftvs_t_section( $tag, $atts ) {
	return ftvs_t_private( 'FTVS_Health', 'section', array( 'shortcode', $tag, $atts ) );
}

/* ---------------------------------------------------------------- finding sections: shortcodes */

function test_health_sections_in_a_series_shortcode() {
	$found = ftvs_t_sections_in( 'Intro [faith_tv_series category="kids-rock" layout="row" from="2026-04-01" until="2026-04-30" otherwise="@newest"] outro' );
	assert_same(
		array(
			array(
				'kind'      => 'shortcode',
				'type'      => 'series',
				'category'  => 'kids-rock',
				'video'     => '',
				'layout'    => 'row',
				'from'      => '2026-04-01',
				'until'     => '2026-04-30',
				'otherwise' => '@newest',
			),
		),
		$found
	);
}

function test_health_sections_in_the_other_shortcodes() {
	$found = ftvs_t_sections_in( '[faith_tv_series video="abc"] [faith_tv_live] [faith_tv_library category="sermons" layout="default"] [faith_tv_series]' );
	assert_count( 4, $found );
	assert_same( 'abc', $found[0]['video'] );
	assert_same( 'live', $found[1]['type'] );
	assert_same( 'live', $found[1]['category'] );
	assert_same( 'library', $found[2]['type'] );
	assert_same( 'sermons', $found[2]['category'] );
	assert_same( '', $found[2]['layout'], 'layout="default" means the site\'s layout' );
	assert_same( '', $found[3]['category'], 'a series shortcode with nothing chosen' );
}

function test_health_sections_in_ignores_other_shortcodes_and_plain_text() {
	assert_same( array(), ftvs_t_sections_in( '[gallery ids="1,2"] [faith_tv_other] Faith TV Series is great.' ) );
	assert_same( array(), ftvs_t_sections_in( '' ) );
}

function test_health_sections_in_the_faithstream_shortcode_from_faith_streams_embed_page() {
	$found = ftvs_t_sections_in( '[faithstream category="kids-rock" layout="carousel"] [faithstream video="my-video"] [faithstream live="main"] [faithstream library="all"]' );
	assert_count( 4, $found );
	assert_same( 'kids-rock', $found[0]['category'] );
	assert_same( 'carousel', $found[0]['layout'] );
	assert_same( 'video:my-video', $found[1]['category'], 'a single video is marked so it is not mistaken for a category' );
	assert_same( 'my-video', $found[1]['video'] );
	assert_same( 'live', $found[2]['category'] );
	assert_same( '', $found[3]['category'] );
}

function test_health_used_categories_leaves_out_live_and_single_videos() {
	ftvs_t_used(
		array(
			ftvs_t_section( 'faith_tv_series', array( 'category' => 'kids-rock', 'otherwise' => 'elevate' ) ),
			ftvs_t_section( 'faithstream', array( 'live' => 'main' ) ),
			ftvs_t_section( 'faithstream', array( 'video' => 'my-video' ) ),
			ftvs_t_section( 'faith_tv_live', array() ),
			ftvs_t_section( 'faith_tv_series', array( 'category' => 'kids-rock' ) ),
			ftvs_t_section( 'faith_tv_series', array() ),
		)
	);
	assert_same( array( 'kids-rock', 'elevate' ), FTVS_Health::used_categories(), 'each once, and only real categories' );
}

/* ---------------------------------------------------------------- finding sections: blocks and Elementor */

function test_health_sections_in_blocks_including_nested_ones() {
	$content = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->'
		. '<!-- wp:faith-tv/series {"category":"kids-rock","layout":"grid","from":"2026-04-01"} /-->'
		. '<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">'
		. '<!-- wp:faith-tv/live /-->'
		. '<!-- wp:faith-tv/library {"category":"sermons"} /-->'
		. '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->';
	$found   = ftvs_t_sections_in( $content );
	assert_count( 3, $found );
	assert_same( 'block', $found[0]['kind'] );
	assert_same( 'series', $found[0]['type'] );
	assert_same( 'kids-rock', $found[0]['category'] );
	assert_same( 'grid', $found[0]['layout'] );
	assert_same( '2026-04-01', $found[0]['from'] );
	assert_same( 'live', $found[1]['type'], 'blocks inside groups and columns are found too' );
	assert_same( 'library', $found[2]['type'] );
	assert_same( 'sermons', $found[2]['category'] );
}

function ftvs_t_elementor( $widgets ) {
	return wp_json_encode(
		array(
			array(
				'elType'   => 'section',
				'elements' => array(
					array(
						'elType'   => 'column',
						'elements' => array_merge(
							array(
								array(
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => 'Not ours' ),
								),
							),
							$widgets
						),
					),
				),
			),
		)
	);
}

function test_health_sections_in_elementor_widgets() {
	$found = ftvs_t_sections_in(
		'',
		ftvs_t_elementor(
			array(
				array(
					'elType'     => 'widget',
					'widgetType' => 'faith_tv_series',
					'settings'   => array(
						'category'    => 'custom',
						'category_id' => 'kids-rock',
						'layout'      => 'grid',
						'from'        => '2026-04-01',
					),
				),
				array(
					'elType'     => 'widget',
					'widgetType' => 'faith_tv_series',
					'settings'   => array( 'category' => '@newest' ),
				),
				array(
					'elType'     => 'widget',
					'widgetType' => 'faith_tv_live',
					'settings'   => array(),
				),
			)
		)
	);
	assert_count( 3, $found );
	assert_same( 'elementor', $found[0]['kind'] );
	assert_same( 'kids-rock', $found[0]['category'], '"Custom" reads the typed id' );
	assert_same( 'grid', $found[0]['layout'] );
	assert_same( '@newest', $found[1]['category'] );
	assert_same( 'live', $found[2]['type'] );
}

function test_health_sections_in_an_elementor_sermon_library_widget_is_a_library() {
	$found = ftvs_t_sections_in(
		'',
		ftvs_t_elementor(
			array(
				array(
					'elType'     => 'widget',
					'widgetType' => 'faith_tv_library',
					'settings'   => array(),
				),
			)
		)
	);
	assert_count( 1, $found );
	assert_same( 'library', $found[0]['type'], 'a library widget with no category shows the whole channel' );
}

/* ---------------------------------------------------------------- checking sections */

function ftvs_t_health_church() {
	ftvs_t_faithstream_with_home();
	ftvs_t_route( 'categories/kids-rock?', ftvs_t_json( ftvs_t_fs_category_page( 'kids-rock', ftvs_t_fs_videos( 'kr', 1, 3 ), 3 ) ) );
	ftvs_t_route( 'categories/elevate?', ftvs_t_json( ftvs_t_fs_category_page( 'elevate', array(), 0 ) ) );
	ftvs_t_route( 'categories/removed-series?', ftvs_t_text( '{"detail":"Category not found"}', 404 ) );
	ftvs_t_route( 'categories/_m', ftvs_t_json( ftvs_t_fs_category_page( '_msall', array(), 0 ) ) );
}

function test_health_check_used_says_ok_for_a_category_with_videos() {
	ftvs_t_health_church();
	ftvs_t_used( array( ftvs_t_section( 'faith_tv_series', array( 'category' => 'kids-rock' ) ) ) );
	$rows = FTVS_Health::check_used();
	assert_true( $rows[0]['ok'] );
	assert_same( '', $rows[0]['problem'] );
}

function test_health_check_used_explains_each_kind_of_problem() {
	ftvs_t_health_church();
	ftvs_t_used(
		array(
			ftvs_t_section( 'faith_tv_series', array( 'category' => 'removed-series' ) ),
			ftvs_t_section( 'faith_tv_series', array( 'category' => 'Kids Rock Renamed' ) ),
			ftvs_t_section( 'faith_tv_series', array( 'category' => 'elevate' ) ),
			ftvs_t_section( 'faith_tv_series', array() ),
		)
	);
	$rows = FTVS_Health::check_used();
	assert_false( $rows[0]['ok'] );
	assert_contains( 'removed or renamed', $rows[0]['problem'] );
	assert_false( $rows[1]['ok'] );
	assert_contains( 'No category on your channel is named "Kids Rock Renamed"', $rows[1]['problem'] );
	assert_false( $rows[2]['ok'] );
	assert_contains( 'nothing published yet', $rows[2]['problem'] );
	assert_false( $rows[3]['ok'] );
	assert_contains( 'Pick a category', $rows[3]['problem'] );
}

function test_health_check_used_does_not_check_single_videos_live_blocks_or_the_whole_library() {
	ftvs_t_health_church();
	ftvs_t_used(
		array(
			ftvs_t_section( 'faith_tv_series', array( 'video' => 'anything' ) ),
			ftvs_t_section( 'faith_tv_live', array() ),
			ftvs_t_section( 'faith_tv_library', array() ),
		)
	);
	foreach ( FTVS_Health::check_used() as $row ) {
		assert_true( $row['ok'], $row['type'] );
	}
	assert_count( 0, ftvs_t_requests( 'categories/' ), 'none of these needs a category from the platform' );
}

function test_health_check_used_stores_the_result_for_the_alerts() {
	ftvs_t_health_church();
	ftvs_t_used( array( ftvs_t_section( 'faith_tv_series', array( 'category' => 'kids-rock' ) ) ) );
	FTVS_Health::check_used( true );
	$stored = get_option( 'ftvs_used_status' );
	assert_true( abs( $stored['t'] - time() ) <= 3 );
	assert_true( $stored['rows'][0]['ok'] );
}

function test_health_check_used_embeds_from_faith_streams_embed_page_are_not_broken() {
	ftvs_t_health_church();
	ftvs_t_route( 'categories/live?', ftvs_t_text( '{"detail":"Category not found"}', 404 ) ); // there is no category called "live"
	ftvs_t_used(
		array(
			ftvs_t_section( 'faithstream', array( 'live' => 'main' ) ),
			ftvs_t_section( 'faithstream', array( 'library' => 'all' ) ),
			ftvs_t_section( 'faithstream', array( 'video' => 'my-video' ) ),
			ftvs_t_section( 'faithstream', array( 'category' => 'kids-rock' ) ),
		)
	);
	foreach ( FTVS_Health::check_used() as $row ) {
		assert_true( $row['ok'], $row['category'] . ' -> ' . $row['problem'] );
	}
}

/* ---------------------------------------------------------------- dated sections */

function test_health_schedule_switches_clears_page_caches_right_after_a_dated_section_changes() {
	ftvs_t_timezone( 'UTC' );
	$soon = gmdate( 'Y-m-d H:i', time() + 30 * MINUTE_IN_SECONDS );
	$far  = gmdate( 'Y-m-d H:i', time() + 3 * HOUR_IN_SECONDS );
	$past = gmdate( 'Y-m-d H:i', time() - HOUR_IN_SECONDS );
	ftvs_t_used(
		array(
			ftvs_t_section( 'faith_tv_series', array( 'category' => 'a', 'from' => $soon, 'until' => $far ) ),
			ftvs_t_section( 'faith_tv_series', array( 'category' => 'b', 'from' => $past, 'until' => 'not a date' ) ),
		)
	);
	$t_soon = FTVS_Renderer::local_time( $soon );
	ftvs_t_private( 'FTVS_Health', 'schedule_switches' );
	assert_same( $t_soon + 5, wp_next_scheduled( FTVS_Purge::AT, array( $t_soon ) ), 'five seconds after the section switches' );
	assert_false( wp_next_scheduled( FTVS_Purge::AT, array( FTVS_Renderer::local_time( $far ) ) ), 'a switch more than an hour away waits for a later check' );
	assert_false( wp_next_scheduled( FTVS_Purge::AT, array( FTVS_Renderer::local_time( $past ) ) ), 'and one that has passed needs nothing' );

	ftvs_t_private( 'FTVS_Health', 'schedule_switches' );
	$events = 0;
	foreach ( (array) _get_cron_array() as $time => $hooks ) {
		if ( isset( $hooks[ FTVS_Purge::AT ] ) && $time === $t_soon + 5 ) {
			$events += count( $hooks[ FTVS_Purge::AT ] );
		}
	}
	assert_same( 1, $events, 'asking twice does not schedule it twice' );
}

/* ---------------------------------------------------------------- alerts */

/** Runs the daily alert check; returns the emails it "sent". */
function ftvs_t_alerts() {
	$mails = array();
	ftvs_t_add_filter(
		'pre_wp_mail',
		function ( $null, $atts ) use ( &$mails ) {
			$mails[] = $atts;
			return true; // pretend it was sent
		},
		10,
		2
	);
	ftvs_t_private( 'FTVS_Health', 'maybe_alert' );
	return $mails;
}

function ftvs_t_alert_state( $health = array(), $rows = array() ) {
	delete_transient( 'ftvs_alerted' );
	update_option( FTVS_Cache::HEALTH, array_merge( FTVS_Cache::health(), $health ), false );
	update_option( 'ftvs_used_status', array( 't' => time(), 'rows' => $rows ), false );
}

function test_health_no_alert_when_everything_works() {
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'alert_email' => 1 ) );
	ftvs_t_alert_state();
	assert_count( 0, ftvs_t_alerts() );
	assert_false( get_transient( 'ftvs_alerted' ) );
}

function test_health_alert_when_the_platform_keeps_failing() {
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'alert_email' => 1 ) );
	ftvs_t_alert_state(
		array(
			'fails'      => 5,
			'failing_at' => time() - 3 * HOUR_IN_SECONDS,
			'error'      => 'Connection timed out',
		)
	);
	$mails = ftvs_t_alerts();
	assert_count( 1, $mails );
	assert_same( get_option( 'admin_email' ), $mails[0]['to'] );
	assert_contains( 'a video section needs attention', $mails[0]['subject'] );
	assert_contains( 'has not answered', $mails[0]['message'] );
	assert_contains( 'Connection timed out', $mails[0]['message'] );
	assert_contains( 'faith-stream-health', $mails[0]['message'], 'with a link to the Health page' );
	assert_true( (bool) get_transient( 'ftvs_alerted' ), 'at most one a day' );
	assert_count( 0, ftvs_t_alerts(), 'the second check the same day says nothing' );
}

function test_health_no_alert_for_a_short_or_shallow_outage() {
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'alert_email' => 1 ) );
	ftvs_t_alert_state( array( 'fails' => 5, 'failing_at' => time() - 10 * MINUTE_IN_SECONDS ) );
	assert_count( 0, ftvs_t_alerts(), 'failing for only ten minutes' );
	ftvs_t_alert_state( array( 'fails' => 2, 'failing_at' => time() - 5 * HOUR_IN_SECONDS ) );
	assert_count( 0, ftvs_t_alerts(), 'only two failures in a row' );
}

function test_health_alert_names_a_published_section_that_is_broken() {
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'alert_email' => 1 ) );
	ftvs_t_alert_state(
		array(),
		array(
			array(
				'ok'      => false,
				'status'  => 'publish',
				'title'   => 'Sunday Live',
				'problem' => 'This category was removed or renamed on your channel. Pick it again.',
			),
			array(
				'ok'      => false,
				'status'  => 'draft',
				'title'   => 'Unfinished page',
				'problem' => 'Pick a category.',
			),
			array(
				'ok'      => true,
				'status'  => 'publish',
				'title'   => 'Fine page',
				'problem' => '',
			),
		)
	);
	$mails = ftvs_t_alerts();
	assert_count( 1, $mails );
	assert_contains( 'The video section on "Sunday Live" is not showing: This category was removed or renamed', $mails[0]['message'] );
	assert_not_contains( 'Unfinished page', $mails[0]['message'], 'a draft is not on the site yet' );
	assert_not_contains( 'Fine page', $mails[0]['message'] );
}

function test_health_alerts_can_be_switched_off_and_skip_sample_videos() {
	ftvs_t_alert_state( array( 'fails' => 9, 'failing_at' => time() - DAY_IN_SECONDS ) );
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'alert_email' => 0 ) );
	assert_count( 0, ftvs_t_alerts(), 'switched off in the settings' );
	ftvs_t_settings( array( 'source' => 'demo', 'alert_email' => 1 ) );
	assert_count( 0, ftvs_t_alerts(), 'sample videos never alert' );
}

/* ---------------------------------------------------------------- Site Health and diagnostics */

function test_health_site_health_tests_are_registered() {
	$tests = FTVS_Health::tests( array() );
	assert_has_key( 'ftvs_connection', $tests['direct'] );
	assert_has_key( 'ftvs_rest', $tests['async'] );
	assert_true( false !== has_filter( 'site_status_tests', array( 'FTVS_Health', 'tests' ) ) );
}

function test_health_connection_result() {
	$none = FTVS_Health::test_connection();
	assert_same( 'recommended', $none['status'], 'nothing connected yet' );

	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc' ) );
	update_option( FTVS_Cache::HEALTH, array( 'last_ok' => time() - 120 ), false );
	$good = FTVS_Health::test_connection();
	assert_same( 'good', $good['status'] );
	assert_contains( 'Last checked 2 minutes ago.', $good['description'] );

	update_option( FTVS_Cache::HEALTH, array( 'fails' => 4, 'failing_at' => time() - 2 * HOUR_IN_SECONDS, 'error' => 'Timed out' ), false );
	$bad = FTVS_Health::test_connection();
	assert_same( 'critical', $bad['status'] );
	assert_contains( 'Timed out', $bad['description'] );

	update_option( FTVS_Cache::HEALTH, array( 'fails' => 1, 'failing_at' => time() - 5 * MINUTE_IN_SECONDS ), false );
	assert_same( 'good', FTVS_Health::test_connection()['status'], 'a failure only ten minutes old is not critical yet' );
}

function test_health_rest_result_checks_the_ping_address() {
	ftvs_t_route( 'faith-tv/v1/ping', ftvs_t_json( array( 'ok' => true ) ) );
	$result = FTVS_Health::test_rest();
	assert_same( 'good', $result['status'] );
	assert_contains( 'faith-tv/v1/ping', ftvs_t_requests()[0]['url'] );
	assert_same( 'no-cache', ftvs_t_requests()[0]['args']['headers']['Cache-Control'] );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'faith-tv/v1/ping', ftvs_t_text( 'Forbidden', 403 ) );
	assert_same( 'recommended', FTVS_Health::test_rest()['status'], 'blocked by a security plugin: a suggestion, not an alarm' );
	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_route( 'faith-tv/v1/ping', ftvs_t_neterr() );
	assert_same( 'recommended', FTVS_Health::test_rest()['status'] );
}

function test_health_facts_and_diagnostics_are_plain_text_for_support() {
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'tv_url' => 'https://tv.example.test' ) );
	ftvs_t_used( array( ftvs_t_section( 'faith_tv_series', array( 'category' => 'abc' ) ) ) );
	$facts = FTVS_Health::facts();
	assert_same( FTVS_VERSION, $facts['version'][1] );
	assert_same( 'gideo', $facts['source'][1] );
	assert_same( 'https://tv.example.test', $facts['channel'][1] );
	assert_contains( PHP_VERSION, $facts['wp'][1] );
	assert_same( rest_url( 'faith-tv/v1/' ), $facts['rest'][1] );
	foreach ( $facts as $key => $fact ) {
		assert_true( is_string( $fact[0] ) && is_string( $fact[1] ), $key );
	}
	$info = FTVS_Health::debug_information( array() );
	assert_same( array_keys( $facts ), array_keys( $info['faith-tv-series']['fields'] ) );

	ftvs_t_route( 'ott.gideo.video', ftvs_t_text( 'down', 503 ) );
	$text = FTVS_Health::diagnostics();
	assert_contains( 'Faith TV Series diagnostics', $text );
	assert_contains( 'Site: ' . home_url( '/' ), $text );
	assert_contains( 'Plugin version: ' . FTVS_VERSION, $text );
	assert_contains( '- Page 1 (shortcode, series, abc):', $text );
}
