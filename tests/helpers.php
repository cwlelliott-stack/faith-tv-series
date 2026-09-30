<?php
/**
 * Helpers for tests/run.php and the test-*.php files.
 *
 * The suite runs inside WordPress (WP-CLI eval-file) against a real database, so this file also
 * makes sure a run leaves nothing behind:
 *
 *   - No network. Every HTTP request is answered by a stub the test installed, or blocked (and
 *     the test fails, naming the address).
 *   - Settings are switched with a filter, never saved. Each test starts from the plugin's
 *     defaults ("no church connected"), whatever the site has saved.
 *   - The plugin's options, its transients and the cron list are snapshotted before the run and
 *     put back afterwards (also if PHP dies half-way).
 *
 * Keep this file PHP 7.4 compatible: CI lints everything on 7.4 to 8.4.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Assertion_Failed extends Exception {
}

class FTVS_Test_Skipped extends Exception {
}

/* ------------------------------------------------------------------ assertions */

function ftvs_t_where() {
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore
		if ( isset( $frame['file'], $frame['line'] ) && preg_match( '#/test-[a-z0-9-]+\.php$#', str_replace( '\\', '/', $frame['file'] ) ) ) {
			return basename( $frame['file'] ) . ':' . $frame['line'];
		}
	}
	return '';
}

function ftvs_t_export( $value ) {
	if ( $value instanceof WP_Error ) {
		return 'WP_Error(' . $value->get_error_code() . ': ' . $value->get_error_message() . ')';
	}
	if ( is_string( $value ) ) {
		$text = strlen( $value ) > 400 ? substr( $value, 0, 400 ) . '...(' . strlen( $value ) . ' bytes)' : $value;
		return var_export( $text, true ); // phpcs:ignore
	}
	$text = var_export( $value, true ); // phpcs:ignore
	$text = preg_replace( '/\s*\n\s*/', ' ', $text );
	return strlen( $text ) > 400 ? substr( $text, 0, 400 ) . '...' : $text;
}

function ftvs_t_fail( $message ) {
	$where = ftvs_t_where();
	throw new FTVS_Assertion_Failed( ( '' !== $where ? '[' . $where . '] ' : '' ) . $message );
}

function ftvs_skip( $reason ) {
	throw new FTVS_Test_Skipped( $reason );
}

function assert_true( $value, $message = '' ) {
	if ( true !== $value ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected true, got ' . ftvs_t_export( $value ) );
	}
}

function assert_false( $value, $message = '' ) {
	if ( false !== $value ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected false, got ' . ftvs_t_export( $value ) );
	}
}

function assert_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		ftvs_t_fail( ( '' !== $message ? $message . "\n" : '' ) . '  expected: ' . ftvs_t_export( $expected ) . "\n  actual:   " . ftvs_t_export( $actual ) );
	}
}

function assert_not_same( $unexpected, $actual, $message = '' ) {
	if ( $unexpected === $actual ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'did not expect ' . ftvs_t_export( $actual ) );
	}
}

function assert_near( $expected, $actual, $delta = 0.01, $message = '' ) {
	if ( ! is_numeric( $actual ) || abs( $expected - $actual ) > $delta ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected ' . $expected . ' (+/- ' . $delta . '), got ' . ftvs_t_export( $actual ) );
	}
}

function assert_contains( $needle, $haystack, $message = '' ) {
	if ( ! is_string( $haystack ) || false === strpos( $haystack, (string) $needle ) ) {
		ftvs_t_fail( ( '' !== $message ? $message . "\n" : '' ) . '  expected to contain: ' . ftvs_t_export( $needle ) . "\n  in: " . ftvs_t_export( $haystack ) );
	}
}

function assert_not_contains( $needle, $haystack, $message = '' ) {
	if ( is_string( $haystack ) && false !== strpos( $haystack, (string) $needle ) ) {
		ftvs_t_fail( ( '' !== $message ? $message . "\n" : '' ) . '  expected NOT to contain: ' . ftvs_t_export( $needle ) . "\n  in: " . ftvs_t_export( $haystack ) );
	}
}

function assert_matches( $regex, $string, $message = '' ) {
	if ( ! is_string( $string ) || 1 !== preg_match( $regex, $string ) ) {
		ftvs_t_fail( ( '' !== $message ? $message . "\n" : '' ) . '  expected to match ' . $regex . "\n  in: " . ftvs_t_export( $string ) );
	}
}

function assert_count( $expected, $value, $message = '' ) {
	if ( ! is_array( $value ) || count( $value ) !== $expected ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected ' . $expected . ' items, got ' . ( is_array( $value ) ? count( $value ) : ftvs_t_export( $value ) ) );
	}
}

function assert_has_key( $key, $value, $message = '' ) {
	if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected key ' . ftvs_t_export( $key ) . ' in ' . ftvs_t_export( is_array( $value ) ? array_keys( $value ) : $value ) );
	}
}

function assert_in_array( $needle, $haystack, $message = '' ) {
	if ( ! is_array( $haystack ) || ! in_array( $needle, $haystack, true ) ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected ' . ftvs_t_export( $needle ) . ' in ' . ftvs_t_export( $haystack ) );
	}
}

/** Every key of $expected is in $actual with the same value (extra keys in $actual are fine). */
function assert_subset( $expected, $actual, $message = '' ) {
	if ( ! is_array( $actual ) ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected an array, got ' . ftvs_t_export( $actual ) );
	}
	foreach ( $expected as $key => $value ) {
		if ( ! array_key_exists( $key, $actual ) || $actual[ $key ] !== $value ) {
			ftvs_t_fail( ( '' !== $message ? $message . "\n" : '' ) . '  key ' . ftvs_t_export( $key ) . ' expected ' . ftvs_t_export( $value ) . "\n  actual: " . ftvs_t_export( array_key_exists( $key, $actual ) ? $actual[ $key ] : '(missing)' ) );
		}
	}
}

function assert_wp_error( $value, $code = null, $message = '' ) {
	if ( ! ( $value instanceof WP_Error ) ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected a WP_Error' . ( null !== $code ? ' (' . $code . ')' : '' ) . ', got ' . ftvs_t_export( $value ) );
	}
	if ( null !== $code && $value->get_error_code() !== $code ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected error code ' . $code . ', got ' . ftvs_t_export( $value ) );
	}
}

function assert_not_error( $value, $message = '' ) {
	if ( $value instanceof WP_Error ) {
		ftvs_t_fail( ( '' !== $message ? $message . ' ' : '' ) . 'unexpected ' . ftvs_t_export( $value ) );
	}
}

/**
 * Marks a test as failing because of a bug in the plugin (not in the test). It is reported as
 * XFAIL and does not fail the run; when the plugin is fixed the test shows XPASS and the line
 * that calls this can be deleted. FTVS_STRICT=1 counts known bugs as failures.
 */
function ftvs_known_bug( $test, $where, $what ) {
	$GLOBALS['ftvs_t_known_bugs'][ strtolower( $test ) ] = array(
		'where' => $where,
		'what'  => $what,
	);
}

/* ------------------------------------------------------------------ per-test state */

$GLOBALS['ftvs_t_known_bugs']  = array();
$GLOBALS['ftvs_t_settings']    = null;
$GLOBALS['ftvs_t_tz']          = '';
$GLOBALS['ftvs_t_routes']      = array();
$GLOBALS['ftvs_t_http_log']    = array();
$GLOBALS['ftvs_t_http_blocked'] = array();
$GLOBALS['ftvs_t_hooks']       = array();
$GLOBALS['ftvs_t_hook_guards'] = array();
$GLOBALS['ftvs_t_php_notices'] = array();
$GLOBALS['ftvs_t_temp_users']  = array();

$GLOBALS['ftvs_t_cleanups'] = array();

/** Something to undo when the test ends, pass or fail (a temporary post, a static property...). */
function ftvs_t_cleanup( $callback ) {
	$GLOBALS['ftvs_t_cleanups'][] = $callback;
}

/** Put everything a test may have changed back to "plain defaults". Runs before and after each test. */
function ftvs_t_reset() {
	while ( $GLOBALS['ftvs_t_cleanups'] ) {
		$undo = array_pop( $GLOBALS['ftvs_t_cleanups'] );
		try {
			call_user_func( $undo );
		} catch ( Throwable $e ) {
			echo '        (cleanup failed: ' . $e->getMessage() . ")\n";
		}
	}
	foreach ( $GLOBALS['ftvs_t_hooks'] as $hook ) {
		remove_filter( $hook[0], $hook[1], $hook[2] );
	}
	$GLOBALS['ftvs_t_hooks'] = array();
	foreach ( $GLOBALS['ftvs_t_hook_guards'] as $tag => $saved ) {
		if ( null === $saved ) {
			unset( $GLOBALS['wp_filter'][ $tag ] );
		} else {
			$GLOBALS['wp_filter'][ $tag ] = $saved;
		}
	}
	$GLOBALS['ftvs_t_hook_guards']  = array();
	$GLOBALS['ftvs_t_routes']       = array();
	$GLOBALS['ftvs_t_http_log']     = array();
	$GLOBALS['ftvs_t_http_blocked'] = array();
	$GLOBALS['ftvs_t_tz']           = '';
	$_GET                           = array();
	$_POST                          = array();
	$_REQUEST                       = array();
	wp_set_current_user( 0 );
	ftvs_t_settings();
}

/** A filter/action that is removed again when the test ends. */
function ftvs_t_add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	add_filter( $tag, $callback, $priority, $args );
	$GLOBALS['ftvs_t_hooks'][] = array( $tag, $callback, $priority );
}

/**
 * For code under test that adds filters we cannot remove by name (closures inside the plugin):
 * remembers everything hooked to $tag now and puts it back when the test ends.
 */
function ftvs_t_guard_hook( $tag ) {
	if ( ! array_key_exists( $tag, $GLOBALS['ftvs_t_hook_guards'] ) ) {
		$GLOBALS['ftvs_t_hook_guards'][ $tag ] = isset( $GLOBALS['wp_filter'][ $tag ] ) ? clone $GLOBALS['wp_filter'][ $tag ] : null;
	}
}

/* ------------------------------------------------------------------ settings, time zone, users */

/**
 * What get_option( 'ftvs_settings' ) returns while a test has set its own settings. Nothing is
 * ever written. It hooks pre_option_ (so it also works on a site that has no ftvs_settings row
 * yet) and then runs the ordinary option_ftvs_settings filters over the value, so code that
 * uses that filter itself (the embed preview does) still works on top of it.
 */
function ftvs_t_settings_filter( $pre ) {
	if ( null === $GLOBALS['ftvs_t_settings'] ) {
		return $pre;
	}
	return apply_filters( 'option_' . FTVS_Settings::OPTION, $GLOBALS['ftvs_t_settings'], FTVS_Settings::OPTION );
}
add_filter( 'pre_option_' . FTVS_Settings::OPTION, 'ftvs_t_settings_filter', 10, 1 );

// Embed addresses are signed with a secret kept in an option (made on first use). Tests always
// use a fixed one so they never read, create or change the site's real secret.
add_filter(
	'pre_option_ftvs_embed_secret',
	function () {
		return 'secret-that-only-the-tests-use';
	},
	10,
	0
);

/**
 * The settings the plugin sees for the rest of the test: the plugin's defaults plus $overrides
 * (or the site's saved settings plus $overrides when $onto_saved is true).
 */
function ftvs_t_settings( $overrides = array(), $onto_saved = false ) {
	$GLOBALS['ftvs_t_settings'] = null; // read the base without our own override in the way
	$base                       = $onto_saved ? FTVS_Settings::all() : FTVS_Settings::defaults();
	$GLOBALS['ftvs_t_settings'] = array_merge( $base, $overrides );
}

/** The fake Faith Stream server the tests connect to (nothing answers there; requests are stubbed). */
const FTVS_T_FS = 'https://stream.example.test';

/** A Faith Stream church at a fake address, with a tenant nobody else uses (so caches never collide). */
function ftvs_t_faithstream( $extra = array() ) {
	$tenant = 'ut' . substr( md5( uniqid( '', true ) ), 0, 10 );
	ftvs_t_settings(
		array_merge(
			array(
				'source'    => 'faithstream',
				'fs_url'    => FTVS_T_FS,
				'fs_tenant' => $tenant,
			),
			$extra
		)
	);
	return $tenant;
}

// Ids the platform tests share.
const FTVS_T_CAT_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const FTVS_T_CAT_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const FTVS_T_CAT_C = 'cccccccccccccccccccccccccccccccc';

const FTVS_T_PL1     = 'PLrEnWoR732-BHrPp_Pm8_VleD68f9s14-';
const FTVS_T_PL2     = 'PLabcdefghijklmnopqrstuvwxyz012345';
const FTVS_T_UCID    = 'UC1234567890abcdefghij_-'; // "UC" + 22 characters
const FTVS_T_VIDEO_A = 'dQw4w9WgXcQ';
const FTVS_T_VIDEO_B = 'aBcDeFgHiJk';

/** Like ftvs_t_faithstream(), and the church's home answers with the real sample payload. */
function ftvs_t_faithstream_with_home( $extra = array() ) {
	$tenant = ftvs_t_faithstream( $extra );
	ftvs_t_route( '/api/public/home', ftvs_t_json( ftvs_t_sample_home() ) );
	return $tenant;
}

function ftvs_t_gideo( $extra = array() ) {
	$account = 'ut-' . substr( md5( uniqid( '', true ) ), 0, 10 );
	ftvs_t_settings(
		array_merge(
			array(
				'source'     => 'gideo',
				'account_id' => $account,
				'tv_url'     => 'https://tv.example.test',
			),
			$extra
		)
	);
	return $account;
}

function ftvs_t_demo( $extra = array() ) {
	ftvs_t_settings( array_merge( array( 'source' => 'demo' ), $extra ) );
}

add_filter(
	'pre_option_timezone_string',
	function ( $pre ) {
		return '' === $GLOBALS['ftvs_t_tz'] ? $pre : $GLOBALS['ftvs_t_tz'];
	}
);

/** Pretend the site's time zone is $name (for this test only). */
function ftvs_t_timezone( $name ) {
	$GLOBALS['ftvs_t_tz'] = $name;
}

/** Signs in as an administrator (the first one; a temporary one is made if the site has none). */
function ftvs_t_admin() {
	$ids = get_users(
		array(
			'role'    => 'administrator',
			'number'  => 1,
			'fields'  => 'ID',
			'orderby' => 'ID',
		)
	);
	if ( $ids ) {
		$id = (int) $ids[0];
	} else {
		$id = wp_insert_user(
			array(
				'user_login' => 'ftvs_test_admin_' . wp_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'administrator',
			)
		);
		if ( is_wp_error( $id ) ) {
			ftvs_t_fail( 'could not create a temporary administrator: ' . $id->get_error_message() );
		}
		$GLOBALS['ftvs_t_temp_users'][] = (int) $id;
	}
	wp_set_current_user( $id );
	return $id;
}

/* ------------------------------------------------------------------ HTTP stubs */

function ftvs_t_http_filter( $preempt, $args, $url ) {
	$GLOBALS['ftvs_t_http_log'][] = array(
		'url'  => $url,
		'args' => $args,
	);
	foreach ( $GLOBALS['ftvs_t_routes'] as $route ) {
		list( $needle, $response ) = $route;
		$hit                       = is_callable( $needle ) ? call_user_func( $needle, $url, $args ) : false !== strpos( $url, $needle );
		if ( $hit ) {
			return is_callable( $response ) ? call_user_func( $response, $url, $args ) : $response;
		}
	}
	$GLOBALS['ftvs_t_http_blocked'][] = $url;
	return new WP_Error( 'ftvs_test_no_network', 'Blocked by the test harness (no network in tests): ' . $url );
}
add_filter( 'pre_http_request', 'ftvs_t_http_filter', 1, 3 );

/**
 * Answers requests whose address contains $needle (or, if $needle is a function, for which it
 * returns true). $response is a response array, a WP_Error, or a function( $url, $args ) that
 * returns one. Routes are tried in the order they were added.
 */
function ftvs_t_route( $needle, $response ) {
	$GLOBALS['ftvs_t_routes'][] = array( $needle, $response );
}

/** A response function that hands out $responses one by one (the last one repeats). */
function ftvs_t_sequence( $responses ) {
	$i = 0;
	return function () use ( &$i, $responses ) {
		$response = $responses[ min( $i, count( $responses ) - 1 ) ];
		++$i;
		return $response;
	};
}

function ftvs_t_text( $body, $code = 200, $headers = array() ) {
	return array(
		'headers'       => $headers,
		'body'          => (string) $body,
		'response'      => array(
			'code'    => $code,
			'message' => get_status_header_desc( $code ),
		),
		'cookies'       => array(),
		'http_response' => null,
		'filename'      => null,
	);
}

function ftvs_t_json( $data, $code = 200 ) {
	return ftvs_t_text( wp_json_encode( $data ), $code, array( 'content-type' => 'application/json' ) );
}

/** What WordPress returns when the server cannot be reached at all. */
function ftvs_t_neterr( $message = 'Could not resolve host' ) {
	return new WP_Error( 'http_request_failed', $message );
}

/** Requests seen so far whose address contains $needle. */
function ftvs_t_requests( $needle = '' ) {
	return array_values(
		array_filter(
			$GLOBALS['ftvs_t_http_log'],
			function ( $r ) use ( $needle ) {
				return '' === $needle || false !== strpos( $r['url'], $needle );
			}
		)
	);
}

/* ------------------------------------------------------------------ fixtures, reflection */

function ftvs_t_repo_path( $relative ) {
	return dirname( __DIR__ ) . '/' . ltrim( $relative, '/' );
}

/** The real Faith Stream home payload saved in poc/faithstream-sample.json. */
function ftvs_t_sample_home() {
	static $data = null;
	if ( null === $data ) {
		$file = ftvs_t_repo_path( 'poc/faithstream-sample.json' );
		if ( ! is_readable( $file ) ) {
			ftvs_t_fail( 'missing fixture ' . $file . ' (the repository root must be next to tests/)' );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
		if ( ! is_array( $data ) || empty( $data['rows'] ) ) {
			ftvs_t_fail( 'poc/faithstream-sample.json is not a home payload' );
		}
	}
	return $data;
}

/** Calls a private static method. */
function ftvs_t_private( $class, $method, $args = array() ) {
	$m = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( null, $args );
}

/** Cache keys the plugin derives for a cache key (so a test can look at and clean up its own rows). */
function ftvs_t_cache_hash( $key ) {
	return ftvs_t_private( 'FTVS_Cache', 'hash', array( $key ) );
}

/* ------------------------------------------------------------------ shared builders for the platform tests */

/** Videos "$prefix-$from" to "$prefix-$to" in the shape of Faith Stream's video list entries. */
function ftvs_t_fs_videos( $prefix, $from, $to ) {
	$out = array();
	for ( $i = $from; $i <= $to; $i++ ) {
		$out[] = array(
			'id'            => 'id' . $i,
			'slug'          => $prefix . '-' . $i,
			'title'         => 'Message ' . $i,
			'thumbnail_url' => '/media/t/' . $i . '.jpg',
			'duration_s'    => 600 + $i,
			'published_at'  => gmdate( 'Y-m-d\TH:i:s\Z', 1700000000 - $i * 3600 ),
			'speaker'       => '',
		);
	}
	return $out;
}

/** What GET /api/public/categories/{slug} answers: the category, one page of videos, the total. */
function ftvs_t_fs_category_page( $slug, $videos, $total, $extra = array() ) {
	return array_merge(
		array(
			'category'       => array(
				'id'          => 'cat-' . $slug,
				'slug'        => $slug,
				'name'        => ucfirst( $slug ),
				'kind'        => 'category',
				'video_count' => $total,
			),
			'videos'         => $videos,
			'total'          => $total,
			'has_own_videos' => true,
			'children'       => array(),
		),
		$extra
	);
}

/** Videos in the shape the clients hand to the catalog. */
function ftvs_t_videos( $prefix, $from, $to ) {
	$out = array();
	for ( $i = $from; $i <= $to; $i++ ) {
		$out[] = array(
			'id'          => $prefix . '-' . $i,
			'parent'      => 'series',
			'title'       => 'Message ' . $i,
			'description' => '',
			'image'       => '',
			'poster'      => '',
			'length'      => 60,
			'added'       => '2026-09-0' . $i . 'T10:00:00Z',
			'live'        => false,
			'speaker'     => '',
			'scripture'   => '',
			'tags'        => array(),
		);
	}
	return $out;
}

/** Any published page of the site (a fresh WordPress has "Sample Page"). */
function ftvs_t_page() {
	$pages = get_posts(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => 1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);
	if ( ! $pages ) {
		ftvs_skip( 'the site has no published page to use as the Watch page' );
	}
	return $pages[0];
}

function ftvs_t_permalinks( $structure ) {
	ftvs_t_add_filter(
		'pre_option_permalink_structure',
		function () use ( $structure ) {
			return $structure;
		},
		10,
		0
	);
}

/** Play counts kept in memory for the test instead of in the ftvs_stats option. */
function ftvs_t_memory_stats() {
	$GLOBALS['ftvs_t_stats'] = array();
	ftvs_t_add_filter(
		'pre_option_' . FTVS_Stats::OPTION,
		function () {
			return $GLOBALS['ftvs_t_stats'];
		},
		10,
		0
	);
	ftvs_t_add_filter(
		'pre_update_option_' . FTVS_Stats::OPTION,
		function ( $value, $old ) {
			$GLOBALS['ftvs_t_stats'] = $value;
			return $old; // "unchanged": WordPress skips the database write
		},
		10,
		2
	);
}

/** A weekly service as the settings store it, starting at Unix time $t in the site's time zone. */
function ftvs_t_service_at( $t ) {
	return array(
		'day'  => (int) wp_date( 'w', $t ),
		'time' => wp_date( 'H:i', $t ),
	);
}

/** A live channel as Faith Stream's /api/public/live lists it. */
function ftvs_t_live_channel( $extra = array() ) {
	return array_merge(
		array(
			'slug'          => 'main',
			'name'          => 'Main Campus',
			'title'         => 'Sunday Service',
			'status'        => 'live',
			'thumbnail_url' => '/media/live.jpg',
			'hls_url'       => 'https://stream.mux.test/live.m3u8',
			'viewers'       => 37,
			'chat_enabled'  => true,
		),
		$extra
	);
}

/**
 * Ids of the cards in rendered section HTML, in page order (read from each card's data-item).
 * Series a webmaster built by hand on the test site (ids like _ms16) are left out unless
 * $with_manual is true, so the tests give the same answer on a site that has some.
 */
function ftvs_t_card_ids( $html, $with_manual = false ) {
	preg_match_all( '/data-item="([^"]*)"/', (string) $html, $found );
	$ids = array();
	foreach ( $found[1] as $attr ) {
		$item = json_decode( html_entity_decode( $attr, ENT_QUOTES ), true );
		$id   = is_array( $item ) && isset( $item['id'] ) ? $item['id'] : '?';
		if ( $with_manual || ! FTVS_Manual::owns( $id ) ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/* ------------------------------------------------------------------ leave the site as found */

function ftvs_t_option_rows() {
	global $wpdb;
	$where = array( "option_name = 'cron'" );
	foreach ( array( 'ftvs_', '_transient_ftvs_', '_transient_timeout_ftvs_', '_site_transient_ftvs_', '_site_transient_timeout_ftvs_' ) as $prefix ) {
		$where[] = $wpdb->prepare( 'option_name LIKE %s', $wpdb->esc_like( $prefix ) . '%' );
	}
	$rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE " . implode( ' OR ', $where ), ARRAY_A ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $row ) {
		$out[ $row['option_name'] ] = $row;
	}
	return $out;
}

function ftvs_t_snapshot() {
	$GLOBALS['ftvs_t_snapshot'] = ftvs_t_option_rows();
}

/**
 * Puts the plugin's options/transients and the cron list back the way ftvs_t_snapshot() saw them.
 *
 * @return array { changed: string[] option names touched, settings_changed: bool }
 */
function ftvs_t_restore_site() {
	global $wpdb;
	if ( ! isset( $GLOBALS['ftvs_t_snapshot'] ) || ! is_array( $GLOBALS['ftvs_t_snapshot'] ) ) {
		return array(
			'changed'          => array(),
			'settings_changed' => false,
		);
	}
	$before  = $GLOBALS['ftvs_t_snapshot'];
	$now     = ftvs_t_option_rows();
	$changed = array();
	foreach ( $now as $name => $row ) {
		if ( ! isset( $before[ $name ] ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore
			$changed[] = $name;
		} elseif ( $before[ $name ]['option_value'] !== $row['option_value'] ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $before[ $name ]['option_value'] ), array( 'option_name' => $name ) ); // phpcs:ignore
			$changed[] = $name;
		}
	}
	foreach ( $before as $name => $row ) {
		if ( ! isset( $now[ $name ] ) ) {
			$wpdb->replace( $wpdb->options, $row ); // phpcs:ignore
			$changed[] = $name;
		}
	}
	if ( $GLOBALS['ftvs_t_temp_users'] ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $GLOBALS['ftvs_t_temp_users'] as $id ) {
			wp_delete_user( $id );
		}
		$GLOBALS['ftvs_t_temp_users'] = array();
	}
	wp_cache_flush();
	$GLOBALS['ftvs_t_snapshot'] = null;
	return array(
		'changed'          => $changed,
		'settings_changed' => in_array( FTVS_Settings::OPTION, $changed, true ),
	);
}
