<?php
/**
 * FTVS_Followup: "Remind me" sign-ups and new-video notices handed to the church's webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FTVS_T_HOOK = 'https://hooks.example.test/inbound/abc';

function ftvs_t_followup_on( $extra = array() ) {
	ftvs_t_settings(
		array_merge(
			array(
				'remind'         => 1,
				'remind_webhook' => FTVS_T_HOOK,
				'church_name'    => 'Grace Church',
			),
			$extra
		)
	);
	update_option( FTVS_Followup::RETRY, array(), false );
	wp_clear_scheduled_hook( FTVS_Followup::CRON );
}

/** The JSON bodies sent to the webhook so far. */
function ftvs_t_sent() {
	$out = array();
	foreach ( ftvs_t_requests( FTVS_T_HOOK ) as $request ) {
		$out[] = json_decode( $request['args']['body'], true );
	}
	return $out;
}

/* ---------------------------------------------------------------- consent wording */

function test_followup_consent_text_names_the_church() {
	ftvs_t_settings( array( 'church_name' => 'Grace Church' ) );
	$text = FTVS_Followup::consent_text();
	assert_contains( 'Grace Church', $text );
	assert_contains( 'Reply STOP to stop.', $text );
	assert_contains( 'Message and data rates may apply.', $text );
}

function test_followup_consent_text_falls_back_to_the_site_name_and_can_be_customised() {
	ftvs_t_settings( array( 'church_name' => '' ) );
	assert_contains( get_bloginfo( 'name' ), FTVS_Followup::consent_text() );
	ftvs_t_settings( array( 'remind_consent' => "  We may text you.\n" ) );
	assert_same( 'We may text you.', FTVS_Followup::consent_text() );
}

/* ---------------------------------------------------------------- remind() */

function test_followup_remind_is_off_by_default() {
	$err = FTVS_Followup::remind( array( 'email' => 'a@example.org' ) );
	assert_wp_error( $err, 'ftvs_off' );
	assert_same( array( 'status' => 404 ), $err->get_error_data() );
	ftvs_t_settings( array( 'remind' => 1 ) );
	assert_wp_error( FTVS_Followup::remind( array( 'email' => 'a@example.org' ) ), 'ftvs_off', 'switched on, but no webhook' );
	ftvs_t_settings( array( 'remind_webhook' => FTVS_T_HOOK ) );
	assert_wp_error( FTVS_Followup::remind( array( 'email' => 'a@example.org' ) ), 'ftvs_off', 'a webhook, but switched off' );
	assert_count( 0, ftvs_t_requests() );
}

function test_followup_remind_needs_an_email_or_a_mobile_number() {
	ftvs_t_followup_on();
	foreach ( array( array(), array( 'email' => '' ), array( 'email' => 'not-an-email' ), array( 'phone' => '555-1234' ), array( 'phone' => '12345' ) ) as $in ) {
		$err = FTVS_Followup::remind( $in );
		assert_wp_error( $err, 'ftvs_contact', wp_json_encode( $in ) );
		assert_same( array( 'status' => 400 ), $err->get_error_data() );
	}
	assert_wp_error( FTVS_Followup::remind( 'not an array' ), 'ftvs_contact' );
	assert_count( 0, ftvs_t_requests() );
}

function test_followup_remind_a_phone_number_needs_texting_consent() {
	ftvs_t_followup_on();
	$err = FTVS_Followup::remind( array( 'phone' => '(555) 123-4567' ) );
	assert_wp_error( $err, 'ftvs_consent' );
	assert_same( array( 'status' => 400 ), $err->get_error_data() );
	assert_wp_error( FTVS_Followup::remind( array( 'email' => 'a@example.org', 'phone' => '5551234567' ) ), 'ftvs_consent', 'even next to an email' );
	assert_count( 0, ftvs_t_requests() );
}

function test_followup_remind_with_an_email_is_passed_on() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_json( array( 'ok' => true ) ) );
	assert_true( FTVS_Followup::remind( array( 'email' => ' Visitor@Example.ORG ' ) ) );
	$sent = ftvs_t_sent();
	assert_count( 1, $sent );
	assert_subset(
		array(
			'source'       => 'faith-tv-website',
			'kind'         => 'series',
			'email'        => 'Visitor@Example.ORG',
			'phone'        => '',
			'sms_consent'  => false,
			'consent_text' => '',
			'video_id'     => '',
			'video_title'  => '',
			'page'         => '',
			'site'         => home_url( '/' ),
		),
		$sent[0]
	);
	assert_true( abs( strtotime( $sent[0]['created_at'] ) - time() ) < 5 );
	$request = ftvs_t_requests( FTVS_T_HOOK )[0];
	assert_same( 'POST', $request['args']['method'] );
	assert_same( 'application/json', $request['args']['headers']['Content-Type'] );
	assert_same( 0, FTVS_Followup::pending() );
}

function test_followup_remind_with_a_phone_records_the_consent_that_was_shown() {
	ftvs_t_followup_on( array( 'remind_consent' => 'You agree to texts from us.' ) );
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_json( array( 'ok' => true ) ) );
	assert_true( FTVS_Followup::remind( array( 'phone' => '+1 (555) 123-4567', 'sms' => '1' ) ) );
	$sent = ftvs_t_sent()[0];
	assert_same( '+15551234567', $sent['phone'], 'digits and a leading + only' );
	assert_true( $sent['sms_consent'] );
	assert_same( 'You agree to texts from us.', $sent['consent_text'], 'the exact words the visitor agreed to' );
}

function test_followup_remind_cleans_what_it_forwards() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_json( array( 'ok' => true ) ) );
	FTVS_Followup::remind(
		array(
			'email' => 'a@example.org',
			'kind'  => 'live',
			'video' => 'demo video/1!<x>',
			'title' => str_repeat( 'T', 300 ),
			'page'  => 'javascript:alert(1)',
		)
	);
	FTVS_Followup::remind(
		array(
			'email' => 'b@example.org',
			'kind'  => 'anything else',
			'video' => str_repeat( 'v', 300 ),
			'page'  => 'https://example.org/live/?utm=1',
		)
	);
	$sent = ftvs_t_sent();
	assert_same( 'live', $sent[0]['kind'] );
	assert_same( 'demovideo1x', $sent[0]['video_id'] );
	assert_same( 160, strlen( $sent[0]['video_title'] ) );
	assert_same( '', $sent[0]['page'] );
	assert_same( 'series', $sent[1]['kind'], 'only "live" is kept; everything else is a series reminder' );
	assert_same( 128, strlen( $sent[1]['video_id'] ) );
	assert_same( 'https://example.org/live/?utm=1', $sent[1]['page'] );
}

function test_followup_remind_honeypot_fills_are_dropped_quietly() {
	ftvs_t_followup_on();
	assert_true( FTVS_Followup::remind( array( 'email' => 'bot@example.org', 'hp' => 'http://spam.example' ) ), 'the bot is told it worked' );
	assert_count( 0, ftvs_t_requests() );
	assert_same( 0, FTVS_Followup::pending() );
}

/* ---------------------------------------------------------------- when delivery fails */

function test_followup_remind_keeps_a_sign_up_that_could_not_be_delivered() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_text( 'busy', 503 ) );
	assert_true( FTVS_Followup::remind( array( 'email' => 'a@example.org' ) ), 'the visitor still sees "thank you"' );
	assert_same( 1, FTVS_Followup::pending() );
	assert_true( (bool) wp_next_scheduled( FTVS_Followup::CRON ), 'an hourly retry is scheduled' );
	assert_true( wp_next_scheduled( FTVS_Followup::CRON ) >= time() + HOUR_IN_SECONDS - 5 );
}

function test_followup_remind_is_kept_when_the_webhook_cannot_be_reached_or_is_not_https() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_neterr() );
	FTVS_Followup::remind( array( 'email' => 'a@example.org' ) );
	assert_same( 1, FTVS_Followup::pending() );

	ftvs_t_followup_on( array( 'remind_webhook' => 'http://insecure.example.test/hook' ) );
	FTVS_Followup::remind( array( 'email' => 'b@example.org' ) );
	assert_same( 1, FTVS_Followup::pending(), 'an http:// webhook is never called; the sign-up waits' );
	assert_count( 0, ftvs_t_requests( 'insecure.example.test' ) );
}

function test_followup_retry_delivers_what_was_kept() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_sequence( array( ftvs_t_text( 'busy', 503 ), ftvs_t_json( array( 'ok' => true ) ) ) ) );
	FTVS_Followup::remind( array( 'email' => 'a@example.org' ) );
	assert_same( 1, FTVS_Followup::pending() );
	FTVS_Followup::retry();
	assert_same( 0, FTVS_Followup::pending(), 'delivered on the retry' );
	assert_count( 2, ftvs_t_sent() );
	assert_same( ftvs_t_sent()[0]['email'], ftvs_t_sent()[1]['email'] );
}

function test_followup_retry_keeps_trying_but_drops_anything_older_than_a_day() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_text( 'still busy', 503 ) );
	update_option(
		FTVS_Followup::RETRY,
		array(
			array(
				'email'      => 'fresh@example.org',
				'created_at' => gmdate( 'c', time() - HOUR_IN_SECONDS ),
			),
			array(
				'email'      => 'stale@example.org',
				'created_at' => gmdate( 'c', time() - 2 * DAY_IN_SECONDS ),
			),
		),
		false
	);
	FTVS_Followup::retry();
	assert_same( 1, FTVS_Followup::pending(), 'the fresh one waits for the next hour' );
	assert_count( 1, ftvs_t_sent(), 'the stale one is dropped without being sent: nobody keeps people\'s details for days' );
	assert_same( 'fresh@example.org', ftvs_t_sent()[0]['email'] );
}

function test_followup_retry_with_nothing_kept_or_a_damaged_queue() {
	ftvs_t_followup_on();
	FTVS_Followup::retry();
	assert_count( 0, ftvs_t_requests() );
	update_option( FTVS_Followup::RETRY, 'damaged', false );
	assert_same( 0, FTVS_Followup::pending() );
	FTVS_Followup::retry();
	assert_same( 0, FTVS_Followup::pending() );
}

function test_followup_the_queue_keeps_only_the_latest_hundred() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_text( 'busy', 503 ) );
	$old = array();
	for ( $i = 0; $i < 105; $i++ ) {
		$old[] = array(
			'email'      => 'old' . $i . '@example.org',
			'created_at' => gmdate( 'c' ),
		);
	}
	update_option( FTVS_Followup::RETRY, $old, false );
	FTVS_Followup::remind( array( 'email' => 'newest@example.org' ) );
	assert_same( 100, FTVS_Followup::pending() );
	$queue = get_option( FTVS_Followup::RETRY );
	assert_same( 'newest@example.org', end( $queue )['email'] );
	assert_same( 'old6@example.org', $queue[0]['email'], 'the oldest were dropped' );
}

/* ---------------------------------------------------------------- new videos */

function test_followup_new_videos_are_sent_to_the_new_video_webhook() {
	ftvs_t_faithstream( array( 'new_webhook' => 'https://hooks.example.test/new' ) );
	ftvs_t_route( 'https://hooks.example.test/new', ftvs_t_json( array( 'ok' => true ) ) );
	FTVS_Followup::new_videos( ftvs_t_videos( 'n', 1, 25 ) );
	$requests = ftvs_t_requests( 'hooks.example.test/new' );
	assert_count( 1, $requests );
	$sent = json_decode( $requests[0]['args']['body'], true );
	assert_same( 'new_videos', $sent['event'] );
	assert_count( 20, $sent['videos'], 'at most twenty in one notice' );
	assert_same( 'n-1', $sent['videos'][0]['id'] );
	assert_same( array( 'id', 'title', 'speaker', 'image', 'added', 'url' ), array_keys( $sent['videos'][0] ) );
	assert_same( home_url( '/' ), $sent['site'] );
}

function test_followup_new_videos_do_nothing_without_a_webhook() {
	ftvs_t_faithstream();
	FTVS_Followup::new_videos( ftvs_t_videos( 'n', 1, 2 ) );
	assert_count( 0, ftvs_t_requests() );
}

function test_followup_new_videos_are_not_retried() {
	ftvs_t_followup_on( array( 'new_webhook' => 'https://hooks.example.test/new' ) );
	ftvs_t_route( 'https://hooks.example.test/new', ftvs_t_text( 'down', 500 ) );
	FTVS_Followup::new_videos( ftvs_t_videos( 'n', 1, 2 ) );
	assert_same( 0, FTVS_Followup::pending(), 'a missed notice is not queued: it would be stale by the time it arrived' );
}

function test_followup_remind_does_not_cut_a_title_in_the_middle_of_a_letter() {
	ftvs_t_followup_on();
	ftvs_t_route( FTVS_T_HOOK, ftvs_t_json( array( 'ok' => true ) ) );
	$original = 'a' . str_repeat( "\xc3\xa1", 100 ); // 201 bytes; byte 160 falls inside a letter
	FTVS_Followup::remind(
		array(
			'email' => 'a@example.org',
			'title' => $original,
		)
	);
	$title = ftvs_t_sent()[0]['video_title'];
	assert_true( '' !== $title && 0 === strpos( $original, $title ), 'the title sent is the start of the real title, not a title with a damaged last letter' );
}
