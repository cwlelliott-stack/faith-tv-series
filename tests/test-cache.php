<?php
/**
 * FTVS_Cache: fresh answers, stale-while-revalidate, the outage backup, "gone", and clear().
 * A fetcher class defined here stands in for the platform, so nothing needs HTTP.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plays the part of a platform client: hands out the answers queued for a tag, and counts calls. */
class FTVS_Test_Fetcher {

	public static $calls = array();
	public static $queue = array();
	private static $last = array();

	public static function reset() {
		self::$calls = array();
		self::$queue = array();
		self::$last  = array();
	}

	/** Queue the answers a tag will give, one per call; the last one repeats. */
	public static function answers( $tag, $answers ) {
		self::$queue[ $tag ] = $answers;
	}

	public static function count( $tag = null ) {
		return null === $tag ? count( self::$calls ) : count( array_keys( self::$calls, $tag, true ) );
	}

	public static function fetch( $tag ) {
		self::$calls[] = $tag;
		if ( ! empty( self::$queue[ $tag ] ) ) {
			self::$last[ $tag ] = array_shift( self::$queue[ $tag ] );
		}
		return isset( self::$last[ $tag ] ) ? self::$last[ $tag ] : array( 'default' => $tag );
	}
}

function ftvs_t_cache_job( $tag ) {
	return array( 'FTVS_Test_Fetcher', 'fetch', array( $tag ) );
}

/** A church of its own and a key nobody used before; returns the key. */
function ftvs_t_cache_setup( $tag = 'a' ) {
	FTVS_Test_Fetcher::reset();
	ftvs_t_gideo();
	update_option( 'ftvs_cleared_at', 0 );
	update_option( FTVS_Cache::QUEUE, array(), false );
	return 'ut_' . $tag . '_' . substr( md5( uniqid( '', true ) ), 0, 10);
}

function ftvs_t_cache_transient_name( $key ) {
	return ftvs_t_private( 'FTVS_Cache', 'transient', array( ftvs_t_cache_hash( $key ) ) );
}

/** Pretends the fresh copy expired (WP-Cron and time are not involved: the transient is just gone). */
function ftvs_t_cache_expire( $key ) {
	delete_transient( ftvs_t_cache_transient_name( $key ) );
}

function ftvs_t_cache_backup_option( $key ) {
	return 'ftvs_bk_' . ftvs_t_cache_hash( $key );
}

/** Changes when the saved copy says it was made. */
function ftvs_t_cache_backdate( $key, $seconds_ago ) {
	$name   = ftvs_t_cache_backup_option( $key );
	$backup = get_option( $name );
	assert_true( is_array( $backup ) && isset( $backup['t'] ), 'a backup should exist for ' . $key );
	$backup['t'] = time() - $seconds_ago;
	update_option( $name, $backup, false );
}

/* ---------------------------------------------------------------- fresh answers */

function test_cache_first_call_fetches_then_serves_from_cache() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );

	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ) );
	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'a fresh answer is reused' );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ) );
	assert_same( array( 'v' => 1 ), FTVS_Cache::peek( $key ), 'and kept as a backup' );
}

function test_cache_answers_stay_fresh_for_the_ttl() {
	if ( wp_using_ext_object_cache() ) {
		ftvs_skip( 'transient expiry rows are not in the options table when an object cache is in use' );
	}
	$key = ftvs_t_cache_setup();
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	$expires = (int) get_option( '_transient_timeout_' . ftvs_t_cache_transient_name( $key ) );
	assert_true( abs( $expires - ( time() + 300 ) ) <= 3, 'fresh for 300 seconds (expires in ' . ( $expires - time() ) . ')' );
}

function test_cache_keys_are_separate_per_church() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'church' => 'one' ), array( 'church' => 'two' ) ) );
	$one = FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	ftvs_t_gideo(); // a different church is connected
	$two = FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_same( 'one', $one['church'] );
	assert_same( 'two', $two['church'], 'the same key on another church is fetched, not shared' );
	assert_same( 2, FTVS_Test_Fetcher::count( 'a' ) );
}

function test_cache_success_is_recorded_in_the_health_record() {
	$key = ftvs_t_cache_setup();
	delete_option( FTVS_Cache::HEALTH );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	$health = FTVS_Cache::health();
	assert_true( abs( $health['last_ok'] - time() ) <= 3 );
	assert_same( 0, $health['fails'] );
	assert_same( FTVS_Catalog::identity(), $health['id'] );
}

/* ---------------------------------------------------------------- stale-while-revalidate */

function test_cache_after_expiry_the_backup_is_served_and_a_refresh_is_queued() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );

	ftvs_t_cache_expire( $key );
	$served = FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_same( array( 'v' => 1 ), $served, 'the visitor gets the saved copy right away' );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ), 'and does not wait for the platform' );

	$queue = get_option( FTVS_Cache::QUEUE );
	assert_has_key( $key, $queue, 'a refresh is queued for WP-Cron' );
	assert_same( 300, $queue[ $key ]['ttl'] );
	assert_same( ftvs_t_cache_job( 'a' ), $queue[ $key ]['job'] );
	assert_same( FTVS_Catalog::identity(), $queue[ $key ]['id'] );
	assert_true( (bool) wp_next_scheduled( FTVS_Cache::CRON ), 'the cron event that runs it is scheduled' );

	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'meanwhile everyone else gets the saved copy from a short cache' );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ) );
}

function test_cache_run_queue_fetches_what_went_stale() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	ftvs_t_cache_expire( $key );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );

	FTVS_Cache::run_queue();
	assert_same( 2, FTVS_Test_Fetcher::count( 'a' ), 'the queued job ran' );
	assert_same( array(), get_option( FTVS_Cache::QUEUE ), 'and the queue is empty again' );
	assert_same( array( 'v' => 2 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'now everyone sees the new answer' );
	assert_same( array( 'v' => 2 ), FTVS_Cache::peek( $key ) );
}

function test_cache_the_same_key_is_queued_only_once() {
	$key = ftvs_t_cache_setup();
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	ftvs_t_cache_expire( $key );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	ftvs_t_cache_expire( $key );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_count( 1, get_option( FTVS_Cache::QUEUE ) );
}

function test_cache_run_queue_skips_jobs_queued_for_another_church() {
	$key = ftvs_t_cache_setup();
	update_option(
		FTVS_Cache::QUEUE,
		array(
			$key            => array(
				'ttl' => 300,
				'job' => ftvs_t_cache_job( 'mine' ),
				'id'  => FTVS_Catalog::identity(),
			),
			$key . '_other' => array(
				'ttl' => 300,
				'job' => ftvs_t_cache_job( 'theirs' ),
				'id'  => 'some-other-church',
			),
		),
		false
	);
	FTVS_Cache::run_queue();
	assert_same( 1, FTVS_Test_Fetcher::count( 'mine' ) );
	assert_same( 0, FTVS_Test_Fetcher::count( 'theirs' ), 'a church that is no longer connected is not asked' );
	assert_same( array(), get_option( FTVS_Cache::QUEUE ) );
}

function test_cache_run_queue_with_nothing_queued() {
	ftvs_t_cache_setup();
	FTVS_Cache::run_queue();
	assert_same( 0, FTVS_Test_Fetcher::count() );
	update_option( FTVS_Cache::QUEUE, 'corrupted', false );
	FTVS_Cache::run_queue(); // must not throw or warn
	assert_same( 0, FTVS_Test_Fetcher::count() );
}

function test_cache_an_old_backup_is_not_served_stale() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	ftvs_t_cache_expire( $key );
	ftvs_t_cache_backdate( $key, 3 * HOUR_IN_SECONDS ); // WP-Cron has evidently not been running
	assert_same( array( 'v' => 2 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'too old to be a good stand-in: the visitor waits for a fresh copy' );
	assert_same( 2, FTVS_Test_Fetcher::count( 'a' ) );
}

function test_cache_a_backup_stays_usable_for_three_times_the_ttl_or_half_an_hour() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key, 3600, ftvs_t_cache_job( 'a' ) );
	ftvs_t_cache_expire( $key );
	ftvs_t_cache_backdate( $key, 2 * HOUR_IN_SECONDS ); // under 3 x 1 hour
	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key, 3600, ftvs_t_cache_job( 'a' ) ) );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ) );

	$key2 = ftvs_t_cache_setup( 'b' );
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key2, 60, ftvs_t_cache_job( 'a' ) );
	ftvs_t_cache_expire( $key2 );
	ftvs_t_cache_backdate( $key2, 20 * MINUTE_IN_SECONDS ); // a short ttl still allows half an hour
	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key2, 60, ftvs_t_cache_job( 'a' ) ) );
	ftvs_t_cache_expire( $key2 );
	ftvs_t_cache_backdate( $key2, 40 * MINUTE_IN_SECONDS );
	assert_same( array( 'v' => 2 ), FTVS_Cache::remember( $key2, 60, ftvs_t_cache_job( 'a' ) ), 'but not an hour' );
}

/* ---------------------------------------------------------------- removed on purpose */

function test_cache_gone_deletes_the_backup() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), new WP_Error( 'ftvs_gone', 'That is no longer on your channel.' ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_same( array( 'v' => 1 ), FTVS_Cache::peek( $key ) );

	$heard = array();
	ftvs_t_add_filter(
		'ftvs_gone',
		function ( $gone_key ) use ( &$heard ) {
			$heard[] = $gone_key;
		}
	);
	$fails = FTVS_Cache::health()['fails'];
	$out   = FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_wp_error( $out, 'ftvs_gone' );
	assert_same( null, FTVS_Cache::peek( $key ), 'the saved copy is deleted too, so the removed video stops showing' );
	assert_false( get_option( ftvs_t_cache_backup_option( $key ) ) );
	assert_same( array( $key ), $heard, 'the ftvs_gone action tells the rest of the plugin' );
	assert_same( $fails, FTVS_Cache::health()['fails'], 'a removed video is not a connection problem' );
}

function test_cache_gone_is_remembered_for_a_few_minutes() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( new WP_Error( 'ftvs_gone', 'Gone' ) ) );
	assert_wp_error( FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'ftvs_gone' );
	$again = FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_wp_error( $again, 'ftvs_gone', 'the answer is still "gone"' );
	assert_same( 'Gone', $again->get_error_message() );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ), 'and the platform is not asked again straight away' );
}

/* ---------------------------------------------------------------- the platform is down */

function test_cache_a_transport_error_serves_the_backup() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), new WP_Error( 'http_request_failed', 'Connection timed out' ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );

	$out = FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_same( array( 'v' => 1 ), $out, 'the site keeps showing the last list it got' );
	assert_same( array( 'v' => 1 ), FTVS_Cache::peek( $key ), 'and keeps the backup' );

	$health = FTVS_Cache::health();
	assert_same( 1, $health['fails'] );
	assert_same( 'Connection timed out', $health['error'] );
	assert_same( $key, $health['error_key'] );

	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'briefly cached, so a slow platform is not hammered' );
	assert_same( 2, FTVS_Test_Fetcher::count( 'a' ) );
}

function test_cache_after_a_timeout_the_platform_is_left_alone_for_a_few_minutes() {
	$key   = ftvs_t_cache_setup();
	$other = $key . '_other';
	FTVS_Test_Fetcher::answers( 'a', array( new WP_Error( 'http_request_failed', 'Connection refused' ) ) );
	assert_wp_error( FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'http_request_failed', 'no backup: the error is returned' );
	assert_true( (bool) get_transient( 'ftvs_down_' . md5( FTVS_Catalog::identity() ) ), 'the church is marked as not answering' );

	$out = FTVS_Cache::remember( $other, 300, ftvs_t_cache_job( 'b' ) );
	assert_wp_error( $out, 'ftvs_down', 'other requests do not even try' );
	assert_same( 0, FTVS_Test_Fetcher::count( 'b' ) );

	FTVS_Cache::clear();
	assert_false( get_transient( 'ftvs_down_' . md5( FTVS_Catalog::identity() ) ), 'a manual refresh lifts the pause' );
	assert_not_error( FTVS_Cache::remember( $other, 300, ftvs_t_cache_job( 'b' ) ) );
	assert_same( 1, FTVS_Test_Fetcher::count( 'b' ) );
}

function test_cache_while_down_a_key_with_a_backup_gets_the_backup() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	set_transient( 'ftvs_down_' . md5( FTVS_Catalog::identity() ), 1, 300 );
	assert_same( array( 'v' => 1 ), FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) ) );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ), 'no request was made' );
}

function test_cache_other_errors_are_returned_and_remembered_briefly() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( new WP_Error( 'ftvs_http', 'Faith Stream answered with status 500.' ) ) );
	$first = FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_wp_error( $first, 'ftvs_http' );
	$second = FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_wp_error( $second, 'ftvs_http', 'the same error, from the short cache' );
	assert_same( 'Faith Stream answered with status 500.', $second->get_error_message() );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ) );
	assert_false( get_transient( 'ftvs_down_' . md5( FTVS_Catalog::identity() ) ), 'a 500 is not "the platform is unreachable"' );
	assert_same( null, FTVS_Cache::peek( $key ), 'errors are never kept as a backup' );
}

function test_cache_health_counts_failures_and_recovers() {
	$key = ftvs_t_cache_setup();
	delete_option( FTVS_Cache::HEALTH );
	FTVS_Test_Fetcher::answers( 'a', array( new WP_Error( 'ftvs_http', 'first' ), new WP_Error( 'ftvs_http', 'second' ), array( 'ok' => true ) ) );
	FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	$health = FTVS_Cache::health();
	assert_same( 2, $health['fails'] );
	assert_same( 'second', $health['error'] );
	assert_true( $health['failing_at'] > 0 );
	FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	$health = FTVS_Cache::health();
	assert_same( 0, $health['fails'], 'one good answer ends the run of failures' );
	assert_same( 0, $health['failing_at'] );
}

function test_cache_health_defaults() {
	delete_option( FTVS_Cache::HEALTH );
	assert_same(
		array(
			'last_ok'    => 0,
			'last_error' => 0,
			'error'      => '',
			'error_key'  => '',
			'fails'      => 0,
			'failing_at' => 0,
			'id'         => '',
		),
		FTVS_Cache::health()
	);
	update_option( FTVS_Cache::HEALTH, 'garbage', false );
	assert_same( 0, FTVS_Cache::health()['fails'], 'a damaged record reads as empty' );
}

/* ---------------------------------------------------------------- refresh, lock, notifications */

function test_cache_a_second_visitor_does_not_fetch_while_the_first_is_fetching() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	set_transient( 'ftvs_lock_' . ftvs_t_cache_hash( $key ), 1, 60 );
	assert_same( array( 'v' => 1 ), FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) ), 'someone else is on it: the saved copy' );
	assert_same( 1, FTVS_Test_Fetcher::count( 'a' ) );

	// With no backup to fall back on, waiting is the only option.
	$key2 = ftvs_t_cache_setup( 'b' );
	set_transient( 'ftvs_lock_' . ftvs_t_cache_hash( $key2 ), 1, 60 );
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 'first ever' ) ) );
	assert_same( array( 'v' => 'first ever' ), FTVS_Cache::refresh( $key2, 300, ftvs_t_cache_job( 'a' ) ) );
	assert_false( get_transient( 'ftvs_lock_' . ftvs_t_cache_hash( $key2 ) ), 'the lock is released afterwards' );
}

function test_cache_changed_action_only_when_the_answer_changed() {
	$key   = ftvs_t_cache_setup();
	$heard = array();
	ftvs_t_add_filter(
		'ftvs_cache_changed',
		function ( $changed_key, $data, $old ) use ( &$heard ) {
			$heard[] = array( $changed_key, $data, $old );
		},
		10,
		3
	);
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_same( array( array( $key, array( 'v' => 1 ), null ) ), $heard, 'first fetch: no old copy' );
	FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_count( 1, $heard, 'the same answer again: nothing to report' );
	FTVS_Cache::refresh( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_count( 2, $heard );
	assert_same( array( $key, array( 'v' => 2 ), array( 'v' => 1 ) ), $heard[1], 'changed: new and old copy' );
}

/* ---------------------------------------------------------------- clear() */

function test_cache_clear_makes_the_next_call_fetch() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ) );
	ftvs_t_cache_backdate( $key, 30 ); // the saved copy is 30 seconds old when the church presses "refresh"

	$gen = (int) get_option( 'ftvs_cache_gen', 1 );
	FTVS_Cache::clear();
	assert_same( $gen + 1, (int) get_option( 'ftvs_cache_gen' ), 'every fresh answer is dropped by bumping the generation' );
	assert_true( abs( (int) get_option( 'ftvs_cleared_at' ) - time() ) <= 3 );

	assert_same( array( 'v' => 2 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'the next call fetches instead of serving the older backup' );
	assert_same( 2, FTVS_Test_Fetcher::count( 'a' ) );
	assert_same( array( 'v' => 2 ), FTVS_Cache::peek( $key ) );
}

function test_cache_clear_keeps_the_backup_as_the_outage_fallback() {
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), new WP_Error( 'http_request_failed', 'down' ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	ftvs_t_cache_backdate( $key, 30 );
	FTVS_Cache::clear();
	assert_same( array( 'v' => 1 ), FTVS_Cache::peek( $key ), 'still there after clear()' );
	assert_same( array( 'v' => 1 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'the fetch failed, so the backup is what the site shows' );
}

function test_cache_clear_same_second_as_the_backup_still_refetches() {
	// A copy saved in the very same second as clear() is not "after" it, so it should not be
	// served as a stand-in for the fresh fetch clear() asked for.
	$key = ftvs_t_cache_setup();
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 1 ), array( 'v' => 2 ) ) );
	FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) );
	FTVS_Cache::clear();
	ftvs_t_cache_backdate( $key, time() - (int) get_option( 'ftvs_cleared_at' ) ); // saved in the same second as clear()
	assert_same( array( 'v' => 2 ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ) );
}

/* ---------------------------------------------------------------- backups from older versions */

function test_cache_peek_reads_only_copies_saved_by_this_version() {
	$key = ftvs_t_cache_setup();
	assert_same( null, FTVS_Cache::peek( $key ) );
	update_option( ftvs_t_cache_backup_option( $key ), array( 'legacy' => 'copy from 1.2' ), false );
	assert_same( null, FTVS_Cache::peek( $key ), 'plain data saved by 1.2 and earlier has other shapes' );
	update_option(
		ftvs_t_cache_backup_option( $key ),
		array(
			'__ftvs' => 2,
			't'      => time(),
			'd'      => array( 'v' => 'new format' ),
		),
		false
	);
	assert_same( array( 'v' => 'new format' ), FTVS_Cache::peek( $key ) );
}

function test_cache_a_copy_from_an_older_version_is_never_shown() {
	$key = ftvs_t_cache_setup();
	update_option( ftvs_t_cache_backup_option( $key ), array( 'legacy' => 1 ), false ); // saved by 1.2: no marker
	FTVS_Test_Fetcher::answers( 'a', array( array( 'v' => 'fresh' ) ) );
	assert_same( array( 'v' => 'fresh' ), FTVS_Cache::remember( $key, 300, ftvs_t_cache_job( 'a' ) ), 'it is fetched again' );
	assert_same( array( 'v' => 'fresh' ), FTVS_Cache::peek( $key ), 'and the new answer replaces the old copy' );

	$key2 = ftvs_t_cache_setup( 'b' );
	update_option( ftvs_t_cache_backup_option( $key2 ), array( 'legacy' => 1 ), false );
	FTVS_Test_Fetcher::answers( 'a', array( new WP_Error( 'http_request_failed', 'down' ) ) );
	$got = FTVS_Cache::remember( $key2, 300, ftvs_t_cache_job( 'a' ) );
	assert_true( is_wp_error( $got ), 'not even when the platform is down: its shapes may break the page' );
}

function test_cache_an_update_starts_a_new_generation_and_clears_page_caches_once() {
	ftvs_t_cache_setup();
	$gen = (int) get_option( 'ftvs_cache_gen', 1 );
	update_option( 'ftvs_version', '1.0.0' ); // any other version
	wp_unschedule_hook( FTVS_Purge::CRON );
	FTVS_Settings::maybe_upgrade();
	assert_same( FTVS_VERSION, get_option( 'ftvs_version' ) );
	assert_same( $gen + 1, (int) get_option( 'ftvs_cache_gen' ), 'answers the old version cached are fetched again' );
	assert_true( false !== wp_next_scheduled( FTVS_Purge::CRON ), 'page caches with the old markup are cleared' );

	wp_unschedule_hook( FTVS_Purge::CRON );
	FTVS_Settings::maybe_upgrade();
	assert_same( $gen + 1, (int) get_option( 'ftvs_cache_gen' ), 'only once per version' );
	assert_false( wp_next_scheduled( FTVS_Purge::CRON ) );
}

function test_purge_runs_once_and_then_tells_hosts() {
	$runs  = 0;
	$heard = null;
	$count = function () use ( &$runs ) {
		$runs++;
	};
	$tell  = function ( $done ) use ( &$heard ) {
		$heard = $done;
	};
	add_action( FTVS_Purge::CRON, $count, 5 );
	add_action( 'ftvs_pages_purged', $tell );
	do_action( FTVS_Purge::CRON ); // what WP-Cron does
	remove_action( FTVS_Purge::CRON, $count, 5 );
	remove_action( 'ftvs_pages_purged', $tell );
	assert_same( 1, $runs, 'the purge must not trigger its own cron hook again (that looped until PHP ran out of memory)' );
	assert_true( is_array( $heard ), 'hosts hear about it once, with the caches that were cleared' );
	$last = get_option( 'ftvs_last_purge' );
	assert_true( is_array( $last ) && $last['t'] >= time() - 5, 'Health shows when it last happened' );
}
