<?php
/**
 * Every place that decides "is this an id?" with a regular expression.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function test_ids_a_trailing_newline_is_not_part_of_an_id() {
	// In PCRE "$" also matches just before a final newline, so "abc\n" passes "^abc$".
	$checks   = array(
		'Faith Stream slug'    => FTVS_FaithStream_Client::is_id( "kids-rock\n" ),
		'Gideo category id'    => FTVS_Gideo_Client::is_id( FTVS_T_CAT_A . "\n" ),
		'YouTube video id'     => FTVS_YouTube_Client::is_video( FTVS_T_VIDEO_A . "\n" ),
		'YouTube playlist id'  => FTVS_YouTube_Client::is_playlist( FTVS_T_PL1 . "\n" ),
		'sample church id'     => FTVS_Demo_Client::is_id( "demo-hope-rising\n" ),
		'hand-built series id' => FTVS_Manual::owns( "_ms12\n" ),
	);
	$accepted = array_keys( array_filter( $checks ) );
	assert_same( array(), $accepted, 'these accept an id with a trailing newline' );
}

function test_ids_the_platforms_agree_on_what_is_not_an_id() {
	foreach ( array( '', ' ', '../', '..', '.', '/', 'a/b', 'a b', "a\tb", 'a?b', 'a#b', 'a&b', '%2e%2e', '<script>', "a\0b" ) as $bad ) {
		assert_false( FTVS_FaithStream_Client::is_id( $bad ), 'Faith Stream: ' . wp_json_encode( $bad ) );
		assert_false( FTVS_Gideo_Client::is_id( $bad ), 'Gideo: ' . wp_json_encode( $bad ) );
		assert_false( FTVS_YouTube_Client::is_video( $bad ), 'YouTube video: ' . wp_json_encode( $bad ) );
		assert_false( FTVS_YouTube_Client::is_playlist( $bad ), 'YouTube playlist: ' . wp_json_encode( $bad ) );
		assert_false( FTVS_Demo_Client::is_id( $bad ), 'sample church: ' . wp_json_encode( $bad ) );
		assert_false( FTVS_Manual::owns( $bad ), 'hand-built: ' . wp_json_encode( $bad ) );
	}
}
