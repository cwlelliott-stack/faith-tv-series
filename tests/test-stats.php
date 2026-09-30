<?php
/**
 * FTVS_Stats: anonymous play counts for the dashboard and the Monday email. The counts are
 * kept in memory during the tests (ftvs_t_memory_stats), never in the ftvs_stats option.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ftvs_t_day( $days_ago ) {
	return wp_date( 'Y-m-d', time() - $days_ago * DAY_IN_SECONDS );
}

function ftvs_t_day_row( $plays, $extra = array() ) {
	return array_merge(
		array(
			'p'  => $plays,
			'c'  => 0,
			'e'  => 0,
			'l'  => 0,
			'em' => 0,
			'v'  => array(),
			'pg' => array(),
		),
		$extra
	);
}

/** A demo church whose videos the catalog knows, and the counts start empty. */
function ftvs_t_stats_setup() {
	ftvs_t_memory_stats();
	ftvs_t_demo();
	FTVS_Catalog::library();
}

function ftvs_t_record( $event, $id, $title = '', $path = '', $embed = false ) {
	FTVS_Stats::record( $event, $id, $title, $path, $embed );
}

/* ---------------------------------------------------------------- record */

function test_stats_record_ignores_unknown_events_and_bad_ids() {
	ftvs_t_stats_setup();
	ftvs_t_record( 'delete-everything', 'demo-hope-rising-1' );
	ftvs_t_record( 'play', '' );
	ftvs_t_record( 'play', '!!!' );
	ftvs_t_record( 'play', 'demo-never-heard-of-it-1' );
	ftvs_t_record( 'play', 'Not A Demo Id' );
	assert_same( 0, FTVS_Stats::summary( 1 )['plays'] );
	assert_same( array(), $GLOBALS['ftvs_t_stats'], 'nothing was even written' );
}

function test_stats_record_counts_each_kind_of_event() {
	ftvs_t_stats_setup();
	ftvs_t_record( 'play', 'demo-hope-rising-1', 'Hope Rising, Part 1', 'https://elsewhere.example.test/x?y=1', true );
	ftvs_t_record( 'play', 'demo-hope-rising-1' );
	ftvs_t_record( 'complete', 'demo-hope-rising-1' );
	ftvs_t_record( 'error', 'demo-hope-rising-2' );
	ftvs_t_record( 'live', 'main' );
	ftvs_t_record( 'live', 'main' );
	$summary = FTVS_Stats::summary( 1 );
	assert_same( 2, $summary['plays'] );
	assert_same( 1, $summary['finished'] );
	assert_same( 1, $summary['errors'] );
	assert_same( 2, $summary['live'], 'live counts need no known video' );
	assert_same( 1, $summary['embeds'] );
	assert_same( array( array( 'Hope Rising, Part 1', 2 ) ), $summary['videos'] );
}

function test_stats_record_cleans_the_id_and_keeps_only_the_path_on_this_site() {
	ftvs_t_stats_setup();
	ftvs_t_record( 'play', "demo-hope-rising-1 <>\n", 'T', home_url( '/sermons/easter/?token=secret&email=a@example.org#top' ) );
	ftvs_t_record( 'play', 'demo-hope-rising-1', 'T', '/relative/path?x=1' );
	ftvs_t_record( 'play', 'demo-hope-rising-1', 'T', 'https://evil.example.test/a/b?c=d' );
	ftvs_t_record( 'play', 'demo-hope-rising-1', 'T', '' );
	$pages = FTVS_Stats::summary( 1 )['pages'];
	$paths = array_column( $pages, 1, 0 );
	assert_same( 1, $paths['/sermons/easter/'], 'no query string (it can carry personal details) and no fragment' );
	assert_same( 1, $paths['/relative/path'] );
	assert_same( 1, $paths['evil.example.test'], 'another website is counted by its host only' );
	assert_true( isset( $paths['/relative/path'] ) && isset( $paths['evil.example.test'] ) && isset( $paths['/sermons/easter/'] ) );
}

function test_stats_record_remembers_the_title_of_played_videos_only() {
	ftvs_t_stats_setup();
	ftvs_t_record( 'complete', 'demo-hope-rising-2', 'Title from a complete event' );
	assert_false( isset( $GLOBALS['ftvs_t_stats']['titles']['demo-hope-rising-2'] ) );
	ftvs_t_record( 'play', 'demo-hope-rising-2', "  Hope <b>Rising</b>,\n Part 2 " );
	assert_same( 'Hope Rising, Part 2', $GLOBALS['ftvs_t_stats']['titles']['demo-hope-rising-2'] );
	ftvs_t_record( 'play', 'demo-hope-rising-3', str_repeat( 'x', 300 ) );
	assert_same( 120, strlen( $GLOBALS['ftvs_t_stats']['titles']['demo-hope-rising-3'] ) );
}

function test_stats_record_does_not_cut_a_title_in_the_middle_of_a_letter() {
	ftvs_t_stats_setup();
	ftvs_t_record( 'play', 'demo-hope-rising-1', 'a' . str_repeat( "\xc3\xa1", 100 ) ); // "aáááá..." is 201 bytes; 120 falls inside a letter
	$title = $GLOBALS['ftvs_t_stats']['titles']['demo-hope-rising-1'];
	assert_true( mb_check_encoding( $title, 'UTF-8' ), 'the saved title is still valid UTF-8' );
}

function test_stats_record_keeps_the_busiest_videos_and_pages_of_a_day() {
	ftvs_t_stats_setup();
	$videos = array();
	$pages  = array();
	for ( $i = 1; $i <= 260; $i++ ) {
		$videos[ 'old-video-' . $i ] = $i;
	}
	for ( $i = 1; $i <= 130; $i++ ) {
		$pages[ '/page-' . $i ] = $i;
	}
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => array( wp_date( 'Y-m-d' ) => ftvs_t_day_row( 1, array( 'v' => $videos, 'pg' => $pages ) ) ),
		'titles' => array(),
	);
	ftvs_t_record( 'play', 'demo-hope-rising-1', 'T', home_url( '/hot-page/' ) );
	$row = $GLOBALS['ftvs_t_stats']['days'][ wp_date( 'Y-m-d' ) ];
	assert_count( 200, $row['v'], 'the 200 most played videos of a day' );
	assert_count( 100, $row['pg'], 'and 100 pages' );
	assert_true( isset( $row['v']['old-video-260'] ) && isset( $row['pg']['/page-130'] ), 'the busiest stay' );
	assert_false( isset( $row['v']['old-video-1'] ) || isset( $row['pg']['/page-1'] ), 'the quietest go' );
}

function test_stats_record_keeps_sixty_days() {
	ftvs_t_stats_setup();
	$days = array();
	for ( $i = 1; $i <= 70; $i++ ) {
		$days[ ftvs_t_day( $i ) ] = ftvs_t_day_row( 1 );
	}
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => $days,
		'titles' => array(),
	);
	ftvs_t_record( 'play', 'demo-hope-rising-1' );
	$kept = array_keys( $GLOBALS['ftvs_t_stats']['days'] );
	assert_count( 60, $kept );
	assert_true( in_array( wp_date( 'Y-m-d' ), $kept, true ), 'today is kept' );
	assert_false( in_array( ftvs_t_day( 70 ), $kept, true ), 'the oldest days are dropped' );
}

function test_stats_record_keeps_at_most_six_hundred_titles() {
	ftvs_t_stats_setup();
	$titles = array();
	for ( $i = 1; $i <= 600; $i++ ) {
		$titles[ 'v' . $i ] = 'Title ' . $i;
	}
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => array(),
		'titles' => $titles,
	);
	ftvs_t_record( 'play', 'demo-hope-rising-1', 'One more' );
	assert_same( 500, count( $GLOBALS['ftvs_t_stats']['titles'] ), 'over 600 titles: trimmed to the newest 500' );
	assert_same( 'One more', $GLOBALS['ftvs_t_stats']['titles']['demo-hope-rising-1'] );
	assert_false( isset( $GLOBALS['ftvs_t_stats']['titles']['v1'] ) );
}

/* ---------------------------------------------------------------- summary */

function test_stats_summary_of_a_week_and_the_week_before() {
	ftvs_t_memory_stats();
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => array(
			ftvs_t_day( 0 ) => ftvs_t_day_row( 10, array( 'c' => 4, 'e' => 1, 'l' => 2, 'em' => 3, 'v' => array( 'a' => 6, 'b' => 4 ), 'pg' => array( '/x' => 10 ) ) ),
			ftvs_t_day( 6 ) => ftvs_t_day_row( 5, array( 'v' => array( 'a' => 1, 'c' => 4 ), 'pg' => array( '/y' => 5 ) ) ),
			ftvs_t_day( 7 ) => ftvs_t_day_row( 8 ),
			ftvs_t_day( 13 ) => ftvs_t_day_row( 2 ),
			ftvs_t_day( 14 ) => ftvs_t_day_row( 100 ),
		),
		'titles' => array( 'a' => 'Alpha' ),
	);
	$s = FTVS_Stats::summary( 7 );
	assert_same( 15, $s['plays'], 'today and six days ago: seven days in all' );
	assert_same( 10, $s['prev_plays'], 'the seven days before that' );
	assert_same( 4, $s['finished'] );
	assert_same( 1, $s['errors'] );
	assert_same( 2, $s['live'] );
	assert_same( 3, $s['embeds'] );
	assert_count( 7, $s['daily'] );
	assert_same( 10, $s['daily'][ ftvs_t_day( 0 ) ] );
	assert_same( 0, $s['daily'][ ftvs_t_day( 3 ) ], 'quiet days are zero' );
	assert_same( array( array( 'Alpha', 7 ), array( 'c', 4 ), array( 'b', 4 ) ), $s['videos'], 'most played first; a video without a saved title shows its id' );
	assert_same( array( array( '/x', 10 ), array( '/y', 5 ) ), $s['pages'] );
}

function test_stats_summary_with_no_data() {
	ftvs_t_memory_stats();
	$s = FTVS_Stats::summary( 7 );
	assert_same( 0, $s['plays'] );
	assert_same( array(), $s['videos'] );
	assert_count( 7, $s['daily'] );
	$one = FTVS_Stats::summary( 1 );
	assert_count( 1, $one['daily'] );
}

function test_stats_summary_lists_only_the_top_five() {
	ftvs_t_memory_stats();
	$videos = array();
	for ( $i = 1; $i <= 9; $i++ ) {
		$videos[ 'v' . $i ] = $i;
	}
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => array( ftvs_t_day( 0 ) => ftvs_t_day_row( 45, array( 'v' => $videos ) ) ),
		'titles' => array(),
	);
	$s = FTVS_Stats::summary( 7 );
	assert_same( array( 'v9', 'v8', 'v7', 'v6', 'v5' ), array_column( $s['videos'], 0 ) );
}

/* ---------------------------------------------------------------- Monday email */

function ftvs_t_stats_mail() {
	$mails = array();
	ftvs_t_add_filter(
		'pre_wp_mail',
		function ( $null, $atts ) use ( &$mails ) {
			$mails[] = $atts;
			return true;
		},
		10,
		2
	);
	FTVS_Stats::email();
	return $mails;
}

function test_stats_email_is_off_unless_turned_on_and_never_for_sample_videos() {
	ftvs_t_memory_stats();
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'stats_email' => 0 ) );
	assert_count( 0, ftvs_t_stats_mail() );
	ftvs_t_settings( array( 'source' => 'demo', 'stats_email' => 1 ) );
	assert_count( 0, ftvs_t_stats_mail() );
	ftvs_t_settings( array( 'stats_email' => 1 ) );
	assert_count( 0, ftvs_t_stats_mail(), 'no church connected' );
}

function test_stats_email_reports_last_weeks_numbers_to_the_admin() {
	ftvs_t_memory_stats();
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'stats_email' => 1 ) );
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => array( ftvs_t_day( 1 ) => ftvs_t_day_row( 1234, array( 'c' => 56, 'l' => 7, 'v' => array( 'a' => 900, 'b' => 334 ) ) ) ),
		'titles' => array( 'a' => 'Easter Sunday', 'b' => 'Hope Rising' ),
	);
	$mails = ftvs_t_stats_mail();
	assert_count( 1, $mails );
	assert_same( get_option( 'admin_email' ), $mails[0]['to'] );
	assert_contains( 'your videos last week', $mails[0]['subject'] );
	assert_contains( "Plays on the website: 1,234\n", $mails[0]['message'] );
	assert_contains( "Watched to the end: 56\n", $mails[0]['message'] );
	assert_contains( "Joined the live service: 7\n", $mails[0]['message'] );
	assert_contains( "Most watched:\n1. Easter Sunday (900)\n2. Hope Rising (334)\n", $mails[0]['message'] );
	assert_contains( admin_url( 'index.php' ), $mails[0]['message'] );
}

function test_stats_email_leaves_out_lines_with_nothing_to_say() {
	ftvs_t_memory_stats();
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'stats_email' => 1 ) );
	$mails = ftvs_t_stats_mail();
	assert_count( 1, $mails, 'a quiet week still gets its email' );
	assert_contains( "Plays on the website: 0\n", $mails[0]['message'] );
	assert_not_contains( 'Joined the live service', $mails[0]['message'] );
	assert_not_contains( 'Most watched', $mails[0]['message'] );
}

/* ---------------------------------------------------------------- dashboard widget */

function ftvs_t_widget() {
	ob_start();
	FTVS_Stats::widget();
	return ob_get_clean();
}

function test_stats_widget_shows_the_week() {
	ftvs_t_memory_stats();
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => array(
			ftvs_t_day( 0 ) => ftvs_t_day_row( 30, array( 'c' => 12, 'e' => 2, 'l' => 5, 'v' => array( 'a' => 30 ), 'pg' => array( '/watch/' => 30 ) ) ),
			ftvs_t_day( 8 ) => ftvs_t_day_row( 20 ),
		),
		'titles' => array( 'a' => 'Easter <Sunday>' ),
	);
	$html = ftvs_t_widget();
	assert_contains( '>30</strong><span>plays', $html );
	assert_contains( '(+50% vs. the week before)', $html );
	assert_contains( '>12</strong><span>watched to the end', $html );
	assert_contains( 'joined live', $html );
	assert_contains( 'Easter &lt;Sunday&gt;', $html, 'titles are escaped' );
	assert_contains( '<code>/watch/</code>', $html );
	assert_contains( '2 plays could not start.', $html );
	assert_not_contains( 'No plays on the website yet', $html );
}

function test_stats_widget_an_empty_week_and_a_drop() {
	ftvs_t_memory_stats();
	assert_contains( 'No plays on the website yet this week.', ftvs_t_widget() );
	$GLOBALS['ftvs_t_stats'] = array(
		'days'   => array(
			ftvs_t_day( 0 ) => ftvs_t_day_row( 5 ),
			ftvs_t_day( 8 ) => ftvs_t_day_row( 20 ),
		),
		'titles' => array(),
	);
	assert_contains( '(-75% vs. the week before)', ftvs_t_widget() );
	$GLOBALS['ftvs_t_stats']['days'][ ftvs_t_day( 0 ) ]['e'] = 1;
	assert_contains( '1 play could not start.', ftvs_t_widget(), 'singular' );
}

/** Is the plugin's box among the dashboard boxes that have been added? */
function ftvs_t_has_dashboard_box( $boxes = null ) {
	$boxes = null === $boxes ? $GLOBALS['wp_meta_boxes'] : $boxes;
	foreach ( (array) $boxes as $key => $value ) {
		if ( 'ftvs_stats' === $key || ( is_array( $value ) && ftvs_t_has_dashboard_box( $value ) ) ) {
			return true;
		}
	}
	return false;
}

function test_stats_dashboard_widget_only_for_admins_of_a_connected_church() {
	global $wp_meta_boxes;
	$saved = $wp_meta_boxes;
	ftvs_t_cleanup(
		function () use ( $saved ) {
			$GLOBALS['wp_meta_boxes'] = $saved;
		}
	);
	$wp_meta_boxes = array();
	$screen        = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
	ftvs_t_cleanup(
		function () use ( $screen ) {
			$GLOBALS['current_screen'] = $screen;
		}
	);
	require_once ABSPATH . 'wp-admin/includes/dashboard.php';
	require_once ABSPATH . 'wp-admin/includes/template.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'dashboard' ); // a meta box needs a screen to be added to
	ftvs_t_settings( array( 'source' => 'gideo', 'account_id' => 'abc', 'count_plays' => 1 ) );
	FTVS_Stats::dashboard(); // a visitor
	assert_false( ftvs_t_has_dashboard_box() );
	ftvs_t_admin();
	FTVS_Stats::dashboard();
	assert_true( ftvs_t_has_dashboard_box(), 'an administrator sees it' );

	$GLOBALS['wp_meta_boxes'] = array();
	ftvs_t_settings( array( 'count_plays' => 0, 'source' => 'gideo', 'account_id' => 'abc' ) );
	FTVS_Stats::dashboard();
	assert_false( ftvs_t_has_dashboard_box(), 'not when counting plays is switched off' );
	ftvs_t_settings( array( 'count_plays' => 1 ) );
	FTVS_Stats::dashboard();
	assert_false( ftvs_t_has_dashboard_box(), 'not before a church is connected' );
}
