<?php
/**
 * FTVS_Updater: signed releases. The tests sign manifests with a throwaway Ed25519 key they
 * make themselves and swap it in for the real public key with the ftvs_update_public_key
 * filter, so the real signing key is never needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FTVS_T_MANIFEST_URL = 'https://updates.example.test/latest.json';

function ftvs_t_need_sodium() {
	if ( ! function_exists( 'sodium_crypto_sign_keypair' ) || ! function_exists( 'sodium_crypto_sign_detached' ) ) {
		ftvs_skip( 'the sodium extension is not available' );
	}
}

/** A fresh key pair, trusted for the rest of the test. @return string The secret key. */
function ftvs_t_trusted_key() {
	ftvs_t_need_sodium();
	$pair   = sodium_crypto_sign_keypair();
	$public = base64_encode( sodium_crypto_sign_publickey( $pair ) );
	ftvs_t_add_filter(
		'ftvs_update_public_key',
		function () use ( $public ) {
			return $public;
		}
	);
	return sodium_crypto_sign_secretkey( $pair );
}

function ftvs_t_release_fields( $extra = array() ) {
	return array_merge(
		array(
			'version'      => '9.9.9',
			'package'      => 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v9.9.9/faith-tv-series.zip',
			'sha256'       => str_repeat( 'a', 64 ),
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'tested'       => '7.1',
			'notes'        => 'What changed.',
			'published'    => '2026-09-29T21:20:27Z',
			'rollout'      => 100,
		),
		$extra
	);
}

/** @return array { 0: manifest JSON as published, 1: payload string, 2: base64 signature } */
function ftvs_t_manifest( $secret, $fields = null ) {
	$payload = wp_json_encode( null === $fields ? ftvs_t_release_fields() : $fields );
	$sig     = base64_encode( sodium_crypto_sign_detached( $payload, $secret ) );
	return array(
		wp_json_encode(
			array(
				'payload' => $payload,
				'sig'     => $sig,
			)
		),
		$payload,
		$sig,
	);
}

function ftvs_t_serve_manifest( $body, $code = 200 ) {
	delete_site_transient( FTVS_Updater::CACHE );
	ftvs_t_add_filter(
		'ftvs_update_manifest',
		function () {
			return FTVS_T_MANIFEST_URL;
		}
	);
	ftvs_t_route( FTVS_T_MANIFEST_URL, is_wp_error( $body ) ? $body : ftvs_t_text( $body, $code ) );
}

/* ---------------------------------------------------------------- verify */

function test_updater_verify_accepts_a_release_signed_with_the_trusted_key() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'sha256' => strtoupper( str_repeat( 'ab', 32 ) ) ) ) );
	$release      = FTVS_Updater::verify( $body );
	assert_not_error( $release );
	assert_same( '9.9.9', $release['version'] );
	assert_same( 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v9.9.9/faith-tv-series.zip', $release['package'] );
	assert_same( str_repeat( 'ab', 32 ), $release['sha256'], 'lower-cased for comparison' );
	assert_same( 'What changed.', $release['notes'] );
	assert_same( 'https://github.com/cwlelliott-stack/faith-tv-series/releases/tag/v9.9.9', $release['url'] );
	assert_same( '2026-09-29T21:20:27Z', $release['published'] );
	assert_same( '6.0', $release['requires'] );
	assert_same( '7.4', $release['requires_php'] );
	assert_same( '7.1', $release['tested'] );
	assert_false( $release['held'], 'rollout 100 reaches every site' );
}

function test_updater_verify_fills_in_optional_fields() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest(
		$secret,
		array(
			'version' => '1.4',
			'package' => 'https://example.test/x.zip',
			'sha256'  => 'abc',
		)
	);
	$release = FTVS_Updater::verify( $body );
	assert_not_error( $release );
	assert_same( '', $release['notes'] );
	assert_same( '', $release['published'] );
	assert_same( '6.0', $release['requires'] );
	assert_same( '7.4', $release['requires_php'] );
	assert_same( get_bloginfo( 'version' ), $release['tested'] );
	assert_false( $release['held'], 'no rollout given: everyone' );
}

function test_updater_verify_rejects_a_tampered_payload() {
	$secret                = ftvs_t_trusted_key();
	list( , $payload, $sig ) = ftvs_t_manifest( $secret );

	$evil = str_replace( '9.9.9', '9.9.10', $payload );
	assert_not_same( $payload, $evil );
	$err = FTVS_Updater::verify( wp_json_encode( array( 'payload' => $evil, 'sig' => $sig ) ) );
	assert_wp_error( $err, 'ftvs_update' );
	assert_contains( 'signature did not match', $err->get_error_message() );

	$evil = str_replace( 'github.com', 'evil.example.test', $payload );
	assert_not_same( $payload, $evil );
	assert_wp_error( FTVS_Updater::verify( wp_json_encode( array( 'payload' => $evil, 'sig' => $sig ) ) ), 'ftvs_update', 'a different download address' );

	$evil = str_replace( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), $payload );
	assert_not_same( $payload, $evil );
	assert_wp_error( FTVS_Updater::verify( wp_json_encode( array( 'payload' => $evil, 'sig' => $sig ) ) ), 'ftvs_update', 'a different checksum' );

	assert_wp_error( FTVS_Updater::verify( wp_json_encode( array( 'payload' => $payload . ' ', 'sig' => $sig ) ) ), 'ftvs_update', 'even one added space' );
}

function test_updater_verify_rejects_a_tampered_signature() {
	$secret                = ftvs_t_trusted_key();
	list( , $payload, $sig ) = ftvs_t_manifest( $secret );
	$raw                     = base64_decode( $sig, true );
	$raw[0]                  = chr( ord( $raw[0] ) ^ 1 );
	assert_wp_error( FTVS_Updater::verify( wp_json_encode( array( 'payload' => $payload, 'sig' => base64_encode( $raw ) ) ) ), 'ftvs_update' );
	assert_wp_error( FTVS_Updater::verify( wp_json_encode( array( 'payload' => $payload, 'sig' => base64_encode( substr( $raw, 0, 32 ) ) ) ) ), 'ftvs_update', 'a short signature' );
}

function test_updater_verify_rejects_a_release_signed_with_another_key() {
	ftvs_t_trusted_key();
	$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
	list( $body ) = ftvs_t_manifest( $other );
	assert_wp_error( FTVS_Updater::verify( $body ), 'ftvs_update' );
}

function test_updater_verify_only_trusts_the_built_in_key_by_default() {
	ftvs_t_need_sodium();
	$mine = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() ); // no filter: the real public key is in force
	list( $body ) = ftvs_t_manifest( $mine );
	assert_wp_error( FTVS_Updater::verify( $body ), 'ftvs_update', 'a release signed by anyone but FaithStream is refused' );
	assert_same( 32, strlen( base64_decode( FTVS_Updater::PUBLIC_KEY, true ) ), 'the built-in key is a well-formed Ed25519 public key' );
}

function test_updater_verify_rejects_unreadable_manifests() {
	ftvs_t_trusted_key();
	foreach ( array(
		''                                               => 'empty',
		'not json'                                       => 'not json',
		'[]'                                             => 'empty list',
		'{"payload":"{}"}'                               => 'no signature',
		'{"sig":"AAAA"}'                                 => 'no payload',
		'{"payload":["not","a","string"],"sig":"AAAA"}'  => 'payload not a string',
		'{"payload":"","sig":"AAAA"}'                    => 'empty payload',
		'"just a string"'                                => 'a string',
	) as $body => $why ) {
		assert_wp_error( FTVS_Updater::verify( (string) $body ), 'ftvs_update', $why );
	}
	assert_wp_error( FTVS_Updater::verify( '{"payload":"{}","sig":"!!!not base64!!!"}' ), 'ftvs_update', 'signature that is not base64' );
}

function test_updater_verify_refuses_when_the_public_key_is_unusable() {
	ftvs_t_need_sodium();
	$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
	list( $body ) = ftvs_t_manifest( $secret );
	ftvs_t_add_filter(
		'ftvs_update_public_key',
		function () {
			return '!!!not base64!!!';
		}
	);
	$err = FTVS_Updater::verify( $body );
	assert_wp_error( $err, 'ftvs_update' );
	assert_contains( 'cannot check update signatures', $err->get_error_message() );

	ftvs_t_add_filter( // runs after the first one, so this key is the one in force
		'ftvs_update_public_key',
		function () {
			return base64_encode( 'too short' );
		}
	);
	assert_wp_error( FTVS_Updater::verify( $body ), 'ftvs_update', 'a key of the wrong length must not throw' );
}

function test_updater_verify_rejects_incomplete_or_unsafe_release_details() {
	$secret = ftvs_t_trusted_key();
	$bad    = array(
		'no version'          => array( 'version' => '' ),
		'letters in version'  => array( 'version' => '1.2.x' ),
		'v prefix'            => array( 'version' => 'v1.2.0' ),
		'five-part version'   => array( 'version' => '1.2.3.4.5' ),
		'version with a tail' => array( 'version' => '1.2.0-beta' ),
		'no package'          => array( 'package' => '' ),
		'http package'        => array( 'package' => 'http://example.test/x.zip' ),
		'ftp package'         => array( 'package' => 'ftp://example.test/x.zip' ),
		'javascript package'  => array( 'package' => 'javascript:alert(1)' ),
		'no checksum'         => array( 'sha256' => '' ),
	);
	foreach ( $bad as $why => $change ) {
		list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( $change ) );
		$err          = FTVS_Updater::verify( $body );
		assert_wp_error( $err, 'ftvs_update', $why );
		assert_contains( 'incomplete', $err->get_error_message(), $why );
	}
	foreach ( array( '1', '1.2', '1.2.3', '1.2.3.4', '10.20.30' ) as $version ) {
		list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'version' => $version ) ) );
		assert_not_error( FTVS_Updater::verify( $body ), $version );
	}
}

/* ---------------------------------------------------------------- staged rollout */

function ftvs_t_site_bucket() {
	return (int) ( sprintf( '%u', crc32( home_url() ) ) % 100 );
}

function test_updater_rollout_zero_holds_the_release_back() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => 0 ) ) );
	$release      = FTVS_Updater::verify( $body );
	assert_not_error( $release );
	assert_true( $release['held'], 'a release held at 0% is offered to no site' );
}

function test_updater_rollout_one_hundred_reaches_every_site() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => 100 ) ) );
	assert_false( FTVS_Updater::verify( $body )['held'] );
}

function test_updater_rollout_reaches_a_site_when_the_percentage_passes_its_number() {
	$secret = ftvs_t_trusted_key();
	$bucket = ftvs_t_site_bucket();
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => $bucket ) ) );
	assert_true( FTVS_Updater::verify( $body )['held'], 'this site is number ' . $bucket . ': a ' . $bucket . '% rollout has not reached it yet' );
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => $bucket + 1 ) ) );
	assert_false( FTVS_Updater::verify( $body )['held'], 'one percent more does' );
}

function test_updater_rollout_is_clamped_to_zero_to_one_hundred() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => 250 ) ) );
	assert_false( FTVS_Updater::verify( $body )['held'] );
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => -20 ) ) );
	assert_true( FTVS_Updater::verify( $body )['held'] );
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => '50' ) ) );
	assert_same( ftvs_t_site_bucket() >= 50, FTVS_Updater::verify( $body )['held'], 'a numeric string works' );
}

/* ---------------------------------------------------------------- latest(): fetching and caching */

function test_updater_latest_fetches_verifies_and_caches() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret );
	ftvs_t_serve_manifest( $body );

	$release = FTVS_Updater::latest();
	assert_not_error( $release );
	assert_same( '9.9.9', $release['version'] );
	assert_count( 1, ftvs_t_requests( FTVS_T_MANIFEST_URL ) );
	assert_contains( 'FaithTVSeries/' . FTVS_VERSION, ftvs_t_requests()[0]['args']['headers']['User-Agent'] );

	FTVS_Updater::latest();
	assert_count( 1, ftvs_t_requests( FTVS_T_MANIFEST_URL ), 'answered from the six-hour cache' );
	FTVS_Updater::latest( true );
	assert_count( 2, ftvs_t_requests( FTVS_T_MANIFEST_URL ), '"Check for updates now" skips the cache' );
}

function test_updater_latest_manifest_url_default_and_filter() {
	assert_same( 'https://github.com/cwlelliott-stack/faith-tv-series/releases/latest/download/latest.json', FTVS_Updater::manifest_url() );
	ftvs_t_add_filter(
		'ftvs_update_manifest',
		function () {
			return 'https://test.example/x.json';
		}
	);
	assert_same( 'https://test.example/x.json', FTVS_Updater::manifest_url() );
}

function test_updater_latest_no_release_yet() {
	ftvs_t_serve_manifest( 'Not Found', 404 );
	$err = FTVS_Updater::latest();
	assert_wp_error( $err, 'ftvs_update' );
	assert_contains( 'No signed release found yet', $err->get_error_message() );
	FTVS_Updater::latest();
	assert_count( 1, ftvs_t_requests( FTVS_T_MANIFEST_URL ), 'a failed check is remembered for an hour, so pages do not keep asking' );
}

function test_updater_latest_server_errors_and_transport_errors() {
	ftvs_t_serve_manifest( 'oops', 503 );
	$err = FTVS_Updater::latest();
	assert_wp_error( $err, 'ftvs_update' );
	assert_contains( '503', $err->get_error_message() );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_serve_manifest( ftvs_t_neterr( 'Could not resolve host' ) );
	$err = FTVS_Updater::latest();
	assert_wp_error( $err, 'ftvs_update' );
	assert_contains( 'Could not resolve host', $err->get_error_message() );
}

function test_updater_latest_refuses_a_manifest_with_a_bad_signature() {
	$secret = ftvs_t_trusted_key();
	list( , $payload, $sig ) = ftvs_t_manifest( $secret );
	ftvs_t_serve_manifest( wp_json_encode( array( 'payload' => str_replace( '9.9.9', '9.9.8', $payload ), 'sig' => $sig ) ) );
	$err = FTVS_Updater::latest();
	assert_wp_error( $err, 'ftvs_update' );
	assert_contains( 'signature', $err->get_error_message() );
	$cached = get_site_transient( FTVS_Updater::CACHE );
	assert_true( is_array( $cached ) && isset( $cached['error'] ) && ! isset( $cached['version'] ), 'a refused release is remembered as an error, never as a release' );
}

/* ---------------------------------------------------------------- what WordPress is told */

function test_updater_check_offers_a_signed_release_that_is_not_held_back() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret );
	ftvs_t_serve_manifest( $body );
	$update = FTVS_Updater::check( false, array(), plugin_basename( FTVS_FILE ) );
	assert_same(
		array(
			'slug'         => 'faith-tv-series',
			'version'      => '9.9.9',
			'url'          => 'https://github.com/cwlelliott-stack/faith-tv-series',
			'package'      => 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v9.9.9/faith-tv-series.zip',
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'tested'       => '7.1',
		),
		$update
	);
}

function test_updater_check_says_nothing_about_other_plugins_or_held_or_failed_releases() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret );
	ftvs_t_serve_manifest( $body );
	assert_false( FTVS_Updater::check( false, array(), 'akismet/akismet.php' ), 'not our plugin: the answer is passed through' );
	assert_same( 'kept', FTVS_Updater::check( 'kept', array(), 'akismet/akismet.php' ) );

	list( $held ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'rollout' => 0 ) ) );
	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_serve_manifest( $held );
	assert_false( FTVS_Updater::check( false, array(), plugin_basename( FTVS_FILE ) ), 'held back at 0%' );

	$GLOBALS['ftvs_t_routes'] = array();
	ftvs_t_serve_manifest( 'gone', 404 );
	assert_false( FTVS_Updater::check( false, array(), plugin_basename( FTVS_FILE ) ), 'no release: nothing to offer' );
}

function test_updater_check_copes_with_a_release_cached_by_an_older_plugin_version() {
	// After a manual upload of a newer plugin, the six-hour cache still holds the shape the old
	// code wrote (no "held", "requires", "requires_php", "tested" or "sha256"). It counts as a miss,
	// so the manifest is asked for again (here: not published yet).
	ftvs_t_serve_manifest( '', 404 );
	set_site_transient(
		FTVS_Updater::CACHE,
		array(
			'version'   => '1.2.1',
			'package'   => 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v1.2.1/faith-tv-series.zip',
			'notes'     => 'Old cache entry.',
			'url'       => 'https://github.com/cwlelliott-stack/faith-tv-series/releases/tag/v1.2.1',
			'published' => '2026-09-29T21:20:27Z',
		),
		HOUR_IN_SECONDS
	);
	$update = FTVS_Updater::check( false, array(), plugin_basename( FTVS_FILE ) );
	assert_true( false === $update || is_array( $update ), 'an update check must not fall over on it' );
}

function test_updater_details_window() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret );
	ftvs_t_serve_manifest( $body );
	$args   = (object) array( 'slug' => 'faith-tv-series' );
	$result = FTVS_Updater::details( false, 'plugin_information', $args );
	assert_same( 'Faith TV Series', $result->name );
	assert_same( '9.9.9', $result->version );
	assert_same( 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v9.9.9/faith-tv-series.zip', $result->download_link );
	assert_contains( 'What changed.', $result->sections['changelog'] );

	assert_false( FTVS_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'other' ) ), 'someone else\'s plugin' );
	assert_false( FTVS_Updater::details( false, 'query_plugins', $args ), 'another kind of request' );
}

function test_updater_details_window_escapes_release_notes() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'notes' => '<script>alert(1)</script> & more' ) ) );
	ftvs_t_serve_manifest( $body );
	$result = FTVS_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'faith-tv-series' ) );
	assert_not_contains( '<script>', $result->sections['changelog'] );
	assert_contains( '&lt;script&gt;', $result->sections['changelog'] );
}

function test_updater_details_window_when_there_is_no_release() {
	ftvs_t_serve_manifest( 'nope', 404 );
	$result = FTVS_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'faith-tv-series' ) );
	assert_same( FTVS_VERSION, $result->version );
	assert_same( '', $result->download_link );
	assert_contains( 'No signed release found yet', $result->sections['changelog'] );
}

/* ---------------------------------------------------------------- downloading */

/** Answers the package address with $bytes, written where WordPress asked for the download to go. */
function ftvs_t_serve_package( $url, $bytes ) {
	ftvs_t_route(
		$url,
		function ( $u, $args ) use ( $bytes ) {
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $bytes ); // phpcs:ignore
			}
			return ftvs_t_text( '', 200 );
		}
	);
}

function test_updater_download_accepts_a_package_whose_checksum_matches() {
	$secret  = ftvs_t_trusted_key();
	$package = 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v9.9.9/faith-tv-series.zip';
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'sha256' => hash( 'sha256', 'the zip bytes' ) ) ) );
	ftvs_t_serve_manifest( $body );
	ftvs_t_serve_package( $package, 'the zip bytes' );

	$file = FTVS_Updater::download( false, $package, null );
	assert_not_error( $file );
	assert_true( is_string( $file ) && is_file( $file ), 'a downloaded file' );
	assert_same( 'the zip bytes', file_get_contents( $file ) ); // phpcs:ignore
	wp_delete_file( $file );
}

function test_updater_download_refuses_a_package_that_does_not_match_the_signed_checksum() {
	$secret  = ftvs_t_trusted_key();
	$package = 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v9.9.9/faith-tv-series.zip';
	list( $body ) = ftvs_t_manifest( $secret, ftvs_t_release_fields( array( 'sha256' => hash( 'sha256', 'the real zip' ) ) ) );
	ftvs_t_serve_manifest( $body );
	ftvs_t_serve_package( $package, 'a swapped zip' );

	$err = FTVS_Updater::download( false, $package, null );
	assert_wp_error( $err, 'ftvs_update' );
	assert_contains( 'did not match its signature', $err->get_error_message() );
}

function test_updater_download_leaves_other_packages_and_earlier_answers_alone() {
	$secret = ftvs_t_trusted_key();
	list( $body ) = ftvs_t_manifest( $secret );
	ftvs_t_serve_manifest( $body );
	assert_false( FTVS_Updater::download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null ), 'not our package: WordPress downloads it itself' );
	assert_same( '/tmp/already', FTVS_Updater::download( '/tmp/already', 'https://x.test/y.zip', null ), 'someone else already handled it' );
	assert_false( FTVS_Updater::download( false, array( 'not', 'a', 'string' ), null ) );
	assert_count( 0, ftvs_t_requests( 'akismet' ), 'nothing was downloaded' );
}

/* ---------------------------------------------------------------- housekeeping */

function test_updater_forget_clears_the_cached_release() {
	set_site_transient( FTVS_Updater::CACHE, array( 'version' => '1.0' ), HOUR_IN_SECONDS );
	FTVS_Updater::forget();
	assert_false( get_site_transient( FTVS_Updater::CACHE ) );
}

function test_updater_hooks_are_registered() {
	assert_true( false !== has_filter( 'update_plugins_github.com', array( 'FTVS_Updater', 'check' ) ) );
	assert_true( false !== has_filter( 'plugins_api', array( 'FTVS_Updater', 'details' ) ) );
	assert_true( false !== has_filter( 'upgrader_pre_download', array( 'FTVS_Updater', 'download' ) ) );
	assert_true( false !== has_action( 'upgrader_process_complete', array( 'FTVS_Updater', 'forget' ) ) );
}

function test_updater_plugin_header_points_wordpress_at_github() {
	$data = get_file_data( FTVS_FILE, array( 'update_uri' => 'Update URI' ) );
	assert_same( 'https://github.com/' . FTVS_Updater::REPO, $data['update_uri'], 'WordPress asks update_plugins_github.com, which this class answers' );
}

function test_updater_download_refuses_our_package_when_the_signed_manifest_cannot_be_read() {
	ftvs_t_trusted_key();
	$package = 'https://github.com/cwlelliott-stack/faith-tv-series/releases/download/v9.9.9/faith-tv-series.zip';
	ftvs_t_serve_manifest( 'not json at all' );
	ftvs_t_serve_package( $package, 'an unchecked zip' );
	delete_site_transient( FTVS_Updater::CACHE );
	$err = FTVS_Updater::download( false, $package, null );
	assert_wp_error( $err, 'ftvs_update', 'our release is never installed unchecked' );
}
